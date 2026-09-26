<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Turns a narrative into the questions of law it raises, worded the way the
 * STJ words a theme — the query half of the themes RAG.
 *
 * It exists because the narrative is not a query, and that was measured. A
 * theme is written as an abstract question ("Definir se, no crime de furto,
 * …"), with no parties and no facts, and a narrative is the facts and nothing
 * else: "de madrugada" does not land near "repouso noturno", nor "as câmeras
 * da loja" near "sistema de vigilância torna impossível o furto". Embedded
 * whole, a theft caught in the act brought back Maria da Penha, OAB fee tables
 * and a military class action as its twelve nearest themes, and every pleading
 * researched that way linked zero. The same case, rewritten as six questions
 * in the STJ's register, put the right theme first for each of the six —
 * Temas 934, 924, 1144, 1205, 1434 and 1441.
 *
 * So the agent translates, and translation is all it does. It never sees the
 * catalogue and is told not to name a theme or a súmula: a number recalled
 * from memory does not help a vector search and would read, to the next
 * agent, like a finding. What it writes is **coverage** — the whole path of
 * the case, not only the main request: how the facts are qualified, the
 * elements of the right, the evidence and who bears it, procedure and
 * limitation, and the consequences (the sentence, the damages, interest and
 * fees), including the questions the other side will raise. Each question is
 * searched on its own, so each one has to name its institute and relation.
 *
 * The questions also travel to LegalThemeSelectionAgent, as the map of the
 * case's legal dimensions, and to `legal_cases.theme_findings`, so the lawyer
 * sees what was searched beside what was found.
 *
 * ## Why the framing is at the bottom
 *
 * The prefix cache, as everywhere: the constant instructions first, the one
 * variable block last. The facts are the prompt.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
// Fica com o mesmo provider do seletor: o relato que sai daqui é o mesmo que
// sai de lá, então a fronteira não se alarga.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.2)]
final class LegalQuestionFormulationAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /** The fewest questions a narrative is worth: fewer is a search that looked at one angle. */
    public const int MIN_QUESTIONS = 3;

    /**
     * The most. Each question is one exact scan of the catalogue and a share of
     * the candidates the selector reads, so past eight a question spends the
     * window of the ones before it.
     */
    public const int MAX_QUESTIONS = 8;

    /**
     * @param  string  $framing  LegalCaseDossier::forThemes() — the area and the class
     */
    public function __construct(private readonly string $framing) {}

    public function instructions(): string
    {
        $min = self::MIN_QUESTIONS;
        $max = self::MAX_QUESTIONS;

        return <<<TXT
        Você é um assistente jurídico brasileiro que prepara a consulta ao catálogo de
        precedentes qualificados do Superior Tribunal de Justiça — os Temas Repetitivos, as
        Controvérsias, os IACs, os PUILs e as SIRDRs. O relato de fatos de um caso é a
        mensagem do usuário, e o enquadramento da peça está ao fim destas instruções.

        # O que é um tema, e por que o relato não serve de consulta

        Um tema do STJ é uma **questão de direito**, material ou processual, decidida em
        abstrato: o tribunal escolhe recursos que discutem a mesma questão e fixa uma tese
        que passa a valer para todos os processos em que ela aparecer, sejam quais forem as
        partes (arts. 927, III, e 1.036 a 1.041 do CPC). Por isso cada tema está redigido como uma pergunta sobre a lei, sem
        pessoas e sem fatos concretos: "Definir se, no crime de furto, a existência de
        sistema de vigilância no estabelecimento torna impossível a consumação" — e não "o
        segurança viu tudo pela câmera".

        O catálogo é consultado com perguntas nessa forma. Sua única tarefa é ler o relato e
        escrever as **questões de direito que o caso levanta**, como o STJ as redigiria ao
        afetar um recurso repetitivo.

        # Como formular

        - **Cubra o caminho inteiro do processo**, e não só o pedido principal. Um caso
          levanta questões na qualificação jurídica dos fatos (que crime, que relação —
          consumo, locação, trabalho, previdência), nos requisitos do direito, na prova e em
          quem tem o ônus dela, no procedimento, na competência, na prescrição e na
          decadência, e nas consequências (dosimetria e regime da pena, cabimento e valor da
          indenização, juros, correção monetária, honorários). Inclua as questões que a parte
          contrária vai levantar: um tema desfavorável também precisa ser conhecido.
        - **Traduza os fatos para o vocabulário técnico** que o STJ usaria. "De madrugada"
          é repouso noturno; "foi pego logo depois com as coisas" é consumação sem posse
          mansa e pacífica; "o banco cobrou uma tarifa que eu não contratei" é repetição do
          indébito e restituição em dobro.
        - **Uma questão, um ponto.** Cada questão é buscada sozinha, então ela tem de nomear
          o instituto e a relação jurídica em que ele aparece: "Definir se, no contrato de
          locação residencial, …", e não "Definir se a multa é devida".
        - **Abstrata.** Sem nomes, datas, valores, cidades ou empresas.
        - **Sem números.** Não cite número de tema, súmula, recurso ou processo: você não vê
          o catálogo, e um número lembrado de memória não ajuda a busca.
        - Da questão mais central para a mais periférica.

        # Regras da resposta

        - `questions`: de {$min} a {$max} questões, em português do Brasil, cada uma uma
          frase completa começando por "Definir se", "Definir qual", "Definir a quem",
          "Definir o" ou forma equivalente.

        # O enquadramento da peça

        {$this->framing}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            // Um array só, com os dois tetos nele: o estouro de complexidade do
            // Gemini é o de tetos empilhados em listas aninhadas (app/Ai/README.md).
            'questions' => $schema->array()
                ->items($schema->string()->description(
                    'Uma questão de direito abstrata, como o STJ a redigiria ao afetar um recurso '
                    .'repetitivo. Frase completa, sem nomes, datas, valores nem números de tema.'
                ))
                ->min(self::MIN_QUESTIONS)
                ->max(self::MAX_QUESTIONS)
                ->description('As questões de direito que o caso levanta, da mais central para a mais periférica.')
                ->required(),
        ];
    }
}
