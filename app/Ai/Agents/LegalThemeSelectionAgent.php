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
 * Picks, among the STJ themes closest to the facts, the ones that apply.
 *
 * The generation half of the themes RAG. `LegalThemeCandidatesQuery` does the
 * retrieval — the top-k of the catalogue by cosine distance to the narrative —
 * and this agent reads those candidates and keeps the ones whose question
 * submitted to judgement *is* the question the facts raise. Similarity finds
 * themes about the same subject; only a reading tells whether the STJ decided
 * this controversy or a neighbouring one, and that reading is the job.
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
 * An empty list is a legitimate answer and a common one: most narratives touch
 * no qualified precedent at all. The prompt says so explicitly, because a
 * selection agent handed twelve candidates reads the list as a quota.
 *
 * ## Why the candidates are at the bottom
 *
 * The prefix cache, as in ProceduralClassSelectionAgent: everything constant
 * comes first and the two variable blocks — the framing and the candidates —
 * come last, together, where they cost only themselves.
 *
 * It runs **beside** the thesis research, as the second task of the
 * `Concurrency::run` in ResearchLegalCaseForensicReview, and it depends on
 * nothing that research finds. It reads the facts, the area and the class —
 * the same inputs — and answers a different question.
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
     * @param  list<array{reference: string, heading: string, status: string, judging_body: string|null, question: string, settled_thesis: string|null, judgment_scope: string|null, repercussions: list<string>}>  $candidates
     */
    public function __construct(
        private readonly string $framing,
        private readonly array $candidates,
    ) {}

    public function instructions(): string
    {
        $max = LegalCaseThemeListData::MAX_THEMES;

        return <<<TXT
        Você é um assistente jurídico brasileiro especializado em precedentes qualificados
        do Superior Tribunal de Justiça. Ao fim destas instruções estão o enquadramento de
        uma peça (área de atuação e classe processual) e uma lista de temas do STJ que uma
        busca por similaridade trouxe como os mais próximos do relato de fatos. O relato é
        a mensagem do usuário.

        Sua única tarefa é dizer quais desses temas **se aplicam** ao caso — e por quê.

        # Como decidir

        - Um tema se aplica quando a **questão submetida a julgamento** é a controvérsia
          que os fatos levantam: a mesma pergunta jurídica, sobre o mesmo tipo de relação.
          Assunto parecido não basta. Um tema sobre juros em contrato bancário não se
          aplica a um relato de cobrança de aluguel só porque os dois falam de juros.
        - A busca que montou a lista é por similaridade, e similaridade erra: a lista
          sempre traz temas vizinhos que não se aplicam. Estar na lista não é indício de
          nada.
        - Tema com **tese firmada** vincula os juízes e tribunais (art. 927, III, do CPC):
          é o precedente que a peça invoca ou precisa enfrentar.
        - Tema **afetado ou pendente**, ainda sem tese, também importa: a afetação pode
          suspender os processos sobre a mesma questão (art. 1.037, II, do CPC). Se ele se
          aplica, selecione-o, e diga na razão que a tese ainda não foi firmada.
        - A **repercussão geral** listada num tema diz que o STF tem a mesma questão em
          pauta. Use-a como contexto do tema, e não como um tema a mais.
        - Não selecione um tema pelo que ele *poderia* significar se o relato dissesse mais.
          Decida pelo que o relato diz.

        # Regras da resposta

        - `themes`: os temas que se aplicam, no máximo {$max}, do mais para o menos
          relevante. **Lista vazia é resposta legítima e frequente** — a maioria dos casos
          não toca precedente qualificado nenhum. Não complete a lista para ter o que
          devolver.
        - `reference`: a chave exata do tema, como aparece entre colchetes na lista.
        - `reason`: uma ou duas frases em português do Brasil ligando a questão do tema a um
          fato concreto do relato. Escreva para o advogado que vai conferir: diga qual fato
          levanta a questão, e não o que o tema significa. Não invente fatos que o relato não
          traz, não cite número de processo, súmula ou artigo que o tema não escreva, e não
          dê conselho jurídico.

        # O enquadramento da peça

        {$this->framing}

        # Os temas candidatos

        A lista vem da mais próxima do relato para a mais distante, mas a ordem é só de
        leitura: o tema certo pode estar em qualquer posição, e nenhum pode estar.

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
                            .'concreto do relato. Frases completas, não palavras soltas.'
                        )
                        ->required(),
                ]))
                ->max(LegalCaseThemeListData::MAX_THEMES)
                ->description(
                    'Os temas que se aplicam ao caso, do mais para o menos relevante. Lista '
                    .'vazia quando nenhum se aplica.'
                )
                ->required(),
        ];
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
