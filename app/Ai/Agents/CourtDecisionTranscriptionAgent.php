<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\LegalCases\Data\CourtDecisionResearchData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Turns the jurisprudence sheet into the shape a CourtDecision is saved in.
 *
 * The second half of the pair. It receives what CourtDecisionResearchAgent
 * wrote after searching the LexML, and does one thing: copies it into the flat
 * structure. It does not search, does not judge, does not complete and does not
 * improve — every fact in its answer was already in its input.
 *
 * ## Why this agent exists at all
 *
 * Because on Gemini a schema and a web search cannot be asked for in the same
 * request: passing `response_json_schema` silently switches the grounding off,
 * and the model answers from memory with every appearance of having searched.
 * The measurements are in LegalThesisResearchAgent's docblock. So the search
 * runs unstructured, where it demonstrably works, and the structure is imposed
 * here, where nothing needs the network.
 *
 * ## Flatter than its sibling, and that is the whole difference
 *
 * ForensicReviewTranscriptionAgent nests precedents inside theses, because a
 * model cannot mint correlation uuids reliably and the nesting makes the link
 * structural. Nothing here has that problem: a court decision belongs to the
 * pleading and to nothing else, so the answer is one list of flat objects and
 * the grammar has no relationship to protect. What remains of that lesson is
 * the single `max()` — see `schema()`.
 *
 * ## Copying, not choosing
 *
 * `Temperature(0.1)`, the setting the defendant extractor uses, and for the
 * identical reason: this is transcription. The instructions therefore spend
 * their length on refusal — do not summarise an ementa, do not repair a URL, do
 * not promote "Não localizado/confirmado em fonte oficial." into a finding.
 *
 * The failure this pairing has, and it is the inverse of the usual one: what
 * the sheet did not write, this agent cannot invent — and cannot recover
 * either. An omission upstream is silent here. That is why the sheet is
 * labelled rather than prose, and why `null` is a legal answer to every
 * optional field instead of a thing to be filled in.
 *
 * ## Deliberately without tools
 *
 * Adding any would reintroduce exactly the problem the pair exists to avoid.
 * The absence is load-bearing, not an omission.
 *
 * ## Local, unlike the half it transcribes
 *
 * The researcher has no choice about the cloud — it needs provider-side web
 * tools, and `OllamaGateway::mapTools()` throws on the first `ProviderTool` it
 * sees. This one has no tools at all, so the only thing it asks of a provider
 * is a schema, and Ollama imposes one as a grammar. The sheet crosses the
 * network; the structure is imposed at home.
 *
 * Reached through ResearchLegalCaseCourtDecisions, right after the researcher.
 */
// Trocar as duas linhas de lugar manda a inferência para o Gemini — o bloco
// `gemini` do config/ai.php já está ativo, então a troca mais um `config:clear`
// bastam.
// #[Provider('gemini')]
#[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.1)]
final class CourtDecisionTranscriptionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
        Você recebe a ficha de uma pesquisa de jurisprudência já feita e a
        transcreve para a estrutura pedida. Sua única tarefa é copiar.

        Você não pesquisa, não confere, não corrige, não completa e não melhora.
        Não acrescenta um julgado que a ficha não traga. Não conserta um
        endereço incompleto. Não deduz o tribunal a partir do número do
        processo.

        # Como transcrever

        1. Cada seção `## JULGADO n` da ficha vira um item de `decisions`, na
           mesma ordem.
        2. Os rótulos viram os campos, um a um:
           `Título` vira `title`, `Autoridade` vira `authority`, `Assuntos`
           vira `subject`, `Nome Uniforme` vira `urn` e `URL` vira
           `source_url`.
        3. `## QUESTÃO` vira `legal_question`, `## FONTES` vira
           `sources_consulted` e `## PENDENTE` vira `pending`.

        `Relevância` **não tem campo** e não vai para lugar nenhum. É a frase
        que o pesquisador escreveu para si; ignore-a e não a coloque em nenhum
        outro campo.

        # O endereço é o que mais importa

        `source_url` é copiado caractere por caractere. Não complete um endereço
        truncado, não troque `http` por `https` e **não construa um endereço a
        partir do URN** — é por ele que o texto do julgado será buscado, e um
        endereço montado por você aponta para lugar nenhum. Se a ficha não traz
        URL, o campo é a string vazia.

        # O que vira nulo

        - A palavra `nulo` da ficha vira o nulo do JSON, e nunca a string
          "nulo", "", "não informado" ou "-".
        - A frase "Não localizado/confirmado em fonte oficial." **não é um
          valor**: onde ela aparecer, o campo é nulo. Ela nunca vira o título de
          um julgado.
        - Um rótulo ausente na ficha vira nulo. Não invente o valor que faltou.

        Você não escreve ementa. A ficha não traz uma, e não há campo para ela:
        o texto do julgado é lido da página depois, e inventá-lo aqui seria pôr
        numa petição um parágrafo que nenhum tribunal escreveu.

        # Quando a ficha não traz julgado

        Devolva `decisions` como lista vazia e copie o que houver em
        `## PENDENTE`. Uma ficha sem julgado é uma pesquisa que não confirmou
        nada, e essa é uma resposta legítima que você não deve preencher.

        Se a ficha vier vazia, ilegível ou em outro formato, devolva `decisions`
        vazia e diga isso em `pending`. Não tente responder a pesquisa você
        mesmo: você não pesquisou nada.
        TXT;
    }

    /**
     * The answer, flat: one object per ruling the sheet wrote.
     *
     * The `required()` and `nullable()` pairing throughout is the contract the
     * twelve defendant fields use: the key must be there, and `null` is a legal
     * answer to it. Here it is what keeps "the page did not print Assuntos"
     * from looking like "the key is missing".
     *
     * **There is no `summary` here, and the absence is the design.** The ementa
     * is read from the record by LexmlRecordReader, in PHP, because asking
     * Gemini to reproduce a published headnote verbatim returns
     * `finishReason: RECITATION` and an empty candidate — the table is in that
     * class. A field for it here would be an invitation to fill it from
     * memory, and a paraphrase printed in a pleading as the court's own words
     * is the failure this whole pair is built to prevent.
     *
     * `title` and `source_url` are the two that are not nullable. A ruling with
     * no título cannot be shown, and one with no url cannot be read at all —
     * `source_url` is what the reader is handed. Requiring them does not make
     * them true; it makes the sheet's omission arrive as an empty string rather
     * than as a missing key, and the guard downstream does the refusing.
     *
     * **Only one list carries a `max()`, and that is a Gemini limit rather than
     * a preference** — `maxItems` counts against a complexity budget that is
     * the sum and not the shape, so a second cap anywhere returns 400 before a
     * token is generated. Measured by bisection on the sibling schema. There is
     * only one list here, so the rule costs nothing today and is why it is
     * written down: it is the constraint that would bite the day somebody nests
     * something inside a decision.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'legal_question' => $schema->string()
                ->description('A questão jurídica pesquisada, copiada da seção QUESTÃO.')
                ->nullable()
                ->required(),

            'decisions' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()
                        ->description('O rótulo Título da ficha, copiado inteiro.')
                        ->required(),

                    'authority' => $this->field(
                        $schema,
                        'O rótulo Autoridade, com o órgão julgador: "Superior Tribunal de Justiça. 1ª Turma".',
                    ),

                    'subject' => $this->field($schema, 'O rótulo Assuntos, copiado como está.'),

                    'source_url' => $schema->string()
                        ->description(
                            'O rótulo URL, copiado exatamente como a ficha o escreveu. '
                            .'Nunca construído a partir do URN. String vazia se a ficha não traz.'
                        )
                        ->required(),

                    'urn' => $this->field(
                        $schema,
                        'O rótulo Nome Uniforme, o URN do documento, copiado exatamente.',
                    ),
                ]))
                ->max(CourtDecisionResearchData::MAX_DECISIONS)
                ->description(
                    'Uma entrada por seção ## JULGADO da ficha, na mesma ordem. Lista vazia '
                    .'quando a ficha não traz nenhum.'
                )
                ->required(),

            'sources_consulted' => $schema->array()
                ->items($schema->string())
                ->description('Os endereços da seção ## FONTES, um por item.')
                ->required(),

            'pending' => $schema->array()
                ->items($schema->string())
                ->description('As linhas da seção ## PENDENTE, uma por item.')
                ->required(),
        ];
    }

    /**
     * A field the grammar insists on and the sheet may leave empty.
     *
     * The idiom DefendantExtractionAgent factors out, for the same contract:
     * `required()` makes the key exist, `nullable()` makes `null` the legal
     * answer for what the page did not print.
     */
    private function field(JsonSchema $schema, string $description): StringType
    {
        return $schema->string()
            ->description($description.' Nulo se a ficha não trouxer.')
            ->nullable()
            ->required();
    }
}
