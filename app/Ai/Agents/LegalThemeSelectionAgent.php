<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Ranks, among the STJ themes retrieved for the case, the ones that bear on it
 * — the generation half of the themes RAG.
 *
 * `LegalQuestionFormulationAgent` writes the questions of law the narrative
 * raises, `LegalThemeCandidatesQuery` retrieves the themes nearest each
 * question, and this agent reads them and returns the ones that weigh on the
 * case, from the most to the least relevant, each with a reason. Similarity
 * finds themes about the same institutes; only a reading tells whether the
 * STJ's question is one this case will face, and where it sits.
 *
 * **It ranks; it does not filter.** It used to — "an empty list is a legitimate
 * answer and a common one" — and under that prompt every pleading came back
 * with zero themes, including a theft caught in the act at night, with the
 * store's cameras rolling, for which the catalogue has half a dozen. Two
 * things changed together. The prompt now says what a theme *is*: an abstract
 * question of law that reaches every case where it arises, on the merits, the
 * evidence, the procedure or the consequences — not a description of a case
 * that has to match this one. And the schema has a floor: at least
 * LegalCaseThemeListData::MIN_THEMES, because the lawyer unticks what does not
 * serve, and a theme they discard costs one click where a theme they never saw
 * costs the argument. LegalCaseThemeListData::fromAgent() holds the floor
 * again if a provider does not.
 *
 * The answer is chosen, never written. Each candidate travels under its
 * `reference` — `theme-1016`, `puil-5` — and the schema's `enum` is built from
 * those keys, so a theme that was not retrieved is not something the model can
 * emit: the same guarantee the procedural class selection gets from the CNJ
 * code, enforced by `response_json_schema` in the cloud and by the grammar
 * under the commented provider. The uuid never enters the prompt. The only
 * text the agent composes is `reason`, one or two sentences tying the theme to
 * a fact of the narrative, which the screen shows beside the theme.
 *
 * ## Why the variable blocks are at the bottom
 *
 * The prefix cache, as in ProceduralClassSelectionAgent: everything constant
 * comes first and the three variable blocks — the framing, the questions and
 * the candidates — come last, together, where they cost only themselves.
 *
 * It runs **beside** the thesis research, as the second task of the
 * `Concurrency::run` in ResearchLegalCaseForensicReview, and it depends on
 * nothing that research finds.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.1)]
final class LegalThemeSelectionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  string  $framing  LegalCaseDossier::forThemes() — the area and the class
     * @param  list<string>  $questions  LegalQuestionFormulationAgent's, most central first
     * @param  list<array{reference: string, heading: string, status: string, judging_body: string|null, question: string, settled_thesis: string|null, judgment_scope: string|null, repercussions: list<string>}>  $candidates
     */
    public function __construct(
        private readonly string $framing,
        private readonly array $questions,
        private readonly array $candidates,
    ) {}

    public function instructions(): string
    {
        $min = $this->floor();
        $max = LegalCaseThemeListData::MAX_THEMES;

        return <<<TXT
        Você é um assistente jurídico brasileiro especializado em precedentes qualificados
        do Superior Tribunal de Justiça. Ao fim destas instruções estão o enquadramento de
        uma peça, as questões de direito que o caso levanta e os temas do STJ que uma busca
        trouxe para essas questões. O relato de fatos é a mensagem do usuário.

        Sua tarefa é **ordenar por relevância os temas que pesam sobre o caso** e dizer, de
        cada um, como ele toca os fatos. O advogado lê a sua lista e desmarca o que não
        servir: um tema que ele descarta custa um clique, e um tema que ele nunca viu pode
        custar o argumento.

        # O que é um tema do STJ

        - Um tema é uma **questão de direito**, material ou processual, que o STJ decide em
          abstrato para todos os processos em que ela surgir, sejam quais forem as partes.
          Ele não descreve um caso: descreve uma pergunta sobre a lei ("Definir se…"), e a
          **tese firmada** é a resposta. Um tema de furto vale para todo furto em que a
          mesma pergunta apareça.
        - **Tema Repetitivo** (arts. 1.036 a 1.041 do CPC) é o julgado em recurso especial
          repetitivo. A tese firmada vincula juízes e tribunais (art. 927, III) desde a
          publicação do acórdão, sem esperar o trânsito em julgado: a decisão que deixa de
          seguir a tese invocada pela parte sem distinguir o caso não se considera
          fundamentada (art. 489, § 1º, VI), o pedido contrário a ela pode ser julgado
          improcedente de plano (art. 332, II), e o pedido amparado nela pode ter tutela da
          evidência (art. 311, II).
        - Enquanto não há tese — tema **afetado** ou **em julgamento** — a afetação pode
          suspender os processos que discutem a mesma questão (art. 1.037, II). Saber disso
          muda o que o advogado pede.
        - **Controvérsia** é a questão que o STJ cadastrou como candidata a repetitivo antes
          de afetá-la. Ela não tem tese; o que ela faz é suspender processos na origem.
          "Vinculada a Tema" quer dizer que ela virou um tema e segue por ele.
        - **IAC** (art. 947 do CPC) é a questão de grande repercussão social decidida sem
          repetição em massa; o acórdão também vincula (art. 947, § 3º). **PUIL** uniformiza
          a interpretação de lei federal nos Juizados Especiais, e é lá que ele pesa.
          **SIRDR** é a suspensão, em todo o país, dos processos sobre a questão de um IRDR
          (art. 982, § 3º); a tese vem do IRDR.
        - A **situação** diz o que o tema faz hoje: "Afetado" e "Em Julgamento" ainda não
          têm tese; "Acórdão Publicado", "Acórdão Publicado - RE Pendente", "Mérito Julgado"
          e "Trânsito em Julgado" têm, e ela vincula; "Revisado" teve a tese reescrita, e a
          que vale é a que está na ficha.
        - O **órgão julgador** é sinal da matéria, e não filtro: a Terceira Seção julga
          matéria penal, a Segunda direito privado, a Primeira direito público — tributário,
          administrativo, previdenciário —, e um tema da Corte Especial (processo civil,
          honorários, prescrição) serve a qualquer área. O STJ não julga matéria trabalhista
          nem eleitoral; numa peça dessas, os temas que pesam são os de processo e os de
          direito comum que o caso toca.
        - A **repercussão geral** listada num tema diz que o STF tem a mesma questão em
          pauta. Use-a como contexto do tema, e não como um tema a mais.

        # Quando um tema pesa sobre o caso

        Quando a questão dele é uma das questões de direito que o processo vai enfrentar — e
        um processo enfrenta muitas além do pedido principal:

        - a qualificação jurídica dos fatos: que crime, consumado ou tentado, que relação
          jurídica (consumo, locação, trabalho, previdência);
        - os requisitos do direito, as causas de aumento e as excludentes;
        - a prova, quem tem o ônus dela, e a licitude da prova e da abordagem;
        - o procedimento, a competência, a prescrição e a decadência;
        - as consequências: a dosimetria e o regime da pena, o cabimento e o valor da
          indenização, juros, correção monetária, honorários.

        A pertinência se mede pela questão jurídica, pelo dispositivo e pela **premissa de
        fato que o tema tomou como determinante**. Se o relato traz a mesma questão sobre
        uma premissa diferente, o tema ainda pesa — é o caso de distinguir —, e a razão diz
        qual premissa difere. O tema **desfavorável** pesa tanto quanto o favorável: o
        advogado precisa conhecê-lo para distinguir o caso ou pedir a superação, e o juiz
        pode usá-lo para julgar de plano. O tema cuja pergunta depende de um fato que o
        relato não diz também pode entrar, abaixo dos que o relato decide, com a razão
        dizendo que fato decidiria.

        # Como ordenar

        1. Primeiro, os temas cuja questão é a controvérsia central do caso ou a
           qualificação jurídica dos fatos.
        2. Depois, os que o caso vai enfrentar no caminho: prova, procedimento,
           consequências.
        3. Por último, os que tratam do mesmo instituto numa situação vizinha — o advogado
           precisa saber que existem.

        Dentro de cada faixa, o tema com tese firmada vem antes do afetado, porque já
        vincula. Uma Controvérsia com a mesma questão de um Tema da lista é o mesmo assunto
        numa fase anterior: fique com o Tema. A posição do tema na lista de candidatos não é
        indício de relevância — ela é a ordem da busca.

        # Regras da resposta

        - `themes`: de {$min} a {$max} temas, do mais para o menos relevante. **Nunca
          devolva menos de {$min}.** Se nenhum tema pesa diretamente sobre o caso, devolva os
          {$min} que mais se aproximam das questões dele, na ordem acima, e diga na razão que
          a relação é indireta.
        - `reference`: a chave exata do tema, como aparece entre colchetes na lista.
        - `reason`: uma ou duas frases em português do Brasil ligando a questão do tema a um
          fato concreto do relato — qual fato levanta a questão e o que o tema faz por ela
          (sustenta o pedido, favorece a parte contrária, pode suspender o processo). Se a
          relação é indireta, diga que é. Escreva para o advogado que vai conferir. Não
          invente fatos que o relato não traz, não cite número de processo, súmula ou artigo
          que o tema não escreva, e não dê conselho jurídico.

        # O enquadramento da peça

        {$this->framing}

        # As questões de direito do caso

        Formuladas a partir do relato, da mais central para a mais periférica. Foram elas,
        e não o relato, que a busca usou.

        {$this->questionList()}

        # Os temas candidatos

        Para cada questão acima a busca trouxe os temas mais próximos, e a lista intercala
        as questões: o mais próximo de cada uma primeiro, depois o segundo de cada uma.

        {$this->candidateList()}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            // Um `maxItems` só, no array externo: tetos empilhados estouram o
            // orçamento de complexidade do schema no Gemini (ver app/Ai/README.md).
            'themes' => $schema->array()
                ->items($schema->object([
                    'reference' => $schema->string()
                        ->enum(array_column($this->candidates, 'reference'))
                        ->description('A chave exata de um dos temas candidatos, como aparece entre colchetes.')
                        ->required(),
                    'reason' => $schema->string()
                        ->description(
                            'Uma ou duas frases em português ligando a questão do tema a um fato '
                            .'concreto do relato, e dizendo se a relação é indireta. Frases completas, '
                            .'não palavras soltas.'
                        )
                        ->required(),
                ]))
                ->min($this->floor())
                ->max(LegalCaseThemeListData::MAX_THEMES)
                ->description(
                    'Os temas que pesam sobre o caso, do mais para o menos relevante. Nunca menos '
                    .'que o mínimo: se nenhum pesa diretamente, os mais próximos das questões do caso.'
                )
                ->required(),
        ];
    }

    /**
     * The floor the answer must reach: the policy's, or every candidate when
     * the retrieval found fewer — a `minItems` above the `enum`'s size would be
     * a schema no answer satisfies.
     */
    private function floor(): int
    {
        return min(LegalCaseThemeListData::MIN_THEMES, count($this->candidates));
    }

    private function questionList(): string
    {
        return implode(PHP_EOL, array_map(
            static fn (int $index, string $question): string => ($index + 1).". {$question}",
            array_keys($this->questions),
            $this->questions,
        ));
    }

    private function candidateList(): string
    {
        return implode(PHP_EOL.PHP_EOL, array_map(
            fn (array $candidate): string => $this->entry($candidate),
            $this->candidates,
        ));
    }

    /**
     * @param  array{reference: string, heading: string, status: string, judging_body: string|null, question: string, settled_thesis: string|null, judgment_scope: string|null, repercussions: list<string>}  $candidate
     */
    private function entry(array $candidate): string
    {
        $lines = [
            "- [{$candidate['reference']}] {$candidate['heading']} — ".$this->standing($candidate),
            "  Questão submetida a julgamento: {$candidate['question']}",
            '  Tese firmada: '.($candidate['settled_thesis'] ?? 'ainda não firmada.'),
        ];

        if ($candidate['judgment_scope'] !== null) {
            $lines[] = "  Delimitação do julgado: {$candidate['judgment_scope']}";
        }

        foreach ($candidate['repercussions'] as $repercussion) {
            $lines[] = "  Repercussão geral no STF: {$repercussion}";
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array{status: string, judging_body: string|null}  $candidate
     */
    private function standing(array $candidate): string
    {
        return $candidate['judging_body'] === null
            ? "situação: {$candidate['status']}"
            : "{$candidate['judging_body']}, situação: {$candidate['status']}";
    }
}
