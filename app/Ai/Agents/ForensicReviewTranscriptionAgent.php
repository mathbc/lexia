<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\LegalCases\Data\LegalResearchData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Turns the research sheet into the shape the forensic review is saved in.
 *
 * The second half of the pair. It receives what LegalThesisResearchAgent wrote
 * after searching the official portals, and does one thing: copies it into the
 * nested structure. It does not search, does not judge, does not complete and
 * does not improve — every fact in its answer was already in its input.
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
 * It is the same trade `app/Ai/README.md` already made for the practice area
 * and the procedural class, stated the same way: one more inference buys the
 * guarantee. What is bought here is bigger than a slug — three enums, the
 * ceiling on theses, and above all the **structural link** between a thesis and
 * its precedents, which is the entire reason the schema is nested.
 *
 * ## Copying, not choosing
 *
 * `Temperature(0.1)`, the setting the defendant extractor uses, and for the
 * identical reason: this is transcription. The instructions therefore spend
 * their length on refusal — do not add a precedent, do not repair a citation,
 * do not promote "Não localizado/confirmado em fonte oficial." into a finding.
 *
 * The failure this pairing has, and it is worth naming because it is the
 * inverse of the usual one: what the sheet did not write, this agent cannot
 * invent — and cannot recover either. An omission upstream is silent here. That
 * is why the sheet is labelled rather than prose, and why `null` is a legal
 * answer to every optional field instead of a thing to be filled in.
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
 * is a schema, and Ollama imposes one as a grammar. It is the halfway point of
 * the pair in every sense: the sheet crosses the network, the structure is
 * imposed at home.
 *
 * Reached through ResearchLegalCaseTheses, right after the researcher.
 */
// Trocar as duas linhas de lugar manda a inferência para o Gemini — o bloco
// `gemini` do config/ai.php já está ativo, então a troca mais um `config:clear`
// bastam.
// #[Provider('gemini')]
#[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.1)]
final class ForensicReviewTranscriptionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  list<string>  $thesisTypes  the backing values LegalThesisType accepts
     * @param  list<string>  $basisTypes  the backing values LegalBasisType accepts
     * @param  list<string>  $precedentTypes  the backing values LegalPrecedentType accepts
     */
    public function __construct(
        private readonly array $thesisTypes,
        private readonly array $basisTypes,
        private readonly array $precedentTypes,
    ) {}

    public function instructions(): string
    {
        return <<<'TXT'
        Você recebe a ficha de uma pesquisa jurídica já feita e a transcreve para
        a estrutura pedida. Sua única tarefa é copiar.

        Você não pesquisa, não confere, não corrige, não completa e não melhora.
        Não acrescenta uma tese, um fundamento ou um precedente que a ficha não
        traga. Não conserta uma citação incompleta. Não deduz o tipo de um
        instrumento que a ficha não classificou.

        # Como transcrever

        1. Cada seção `## TESE n` da ficha vira um item de `theses`, na mesma
           ordem.
        2. `Nome`, `Tipo`, `Descrição` e `Impacto` viram `name`, `type`,
           `description` e `impact`.
        3. Cada item de `### Fundamentos` vira um item de `legal_bases` **dentro
           daquela tese**: `Tipo` vira `type`, `Referência` vira `reference`,
           `Sigla` vira `source`, `URL` vira `source_url`.
        4. Cada item de `### Precedentes` vira um item de `precedents` **dentro
           daquela tese**: `Nome` vira `name`, `Tipo` vira `type`, `Ementa` vira
           `description`, `Citação` vira `citation`, `Aderência` vira
           `adherence`, `Fundamentação` vira `grounding`, `URL` vira
           `source_url`.
        5. `## QUESTÃO` vira `legal_question`, `## FONTES` vira
           `sources_consulted` e `## PENDENTE` vira `pending`.

        Um precedente pertence à tese sob cuja seção ele está escrito, e a
        nenhuma outra. Se a ficha listar um precedente fora de qualquer tese,
        ignore-o e registre o fato em `pending`.

        # O que vira nulo

        - A palavra `nulo` da ficha vira o nulo do JSON, e nunca a string
          "nulo", "", "não informado" ou "-".
        - A frase "Não localizado/confirmado em fonte oficial." **não é um
          valor**: onde ela aparecer, o campo é nulo. Ela nunca vira o nome de
          uma tese, o texto de uma ementa nem uma referência.
        - Um rótulo ausente na ficha vira nulo. Não invente o valor que faltou.

        `adherence` é só o número, sem o símbolo de porcentagem: "95", não "95%".
        Se a ficha escrever um intervalo ou um texto, devolva nulo.

        # Quando a ficha não traz tese

        Devolva `theses` como lista vazia e copie o que houver em `## PENDENTE`.
        Uma ficha sem tese é uma pesquisa que não confirmou nada, e essa é uma
        resposta legítima que você não deve preencher.

        Se a ficha vier vazia, ilegível ou em outro formato, devolva `theses`
        vazia e diga isso em `pending`. Não tente responder a pesquisa você
        mesmo: você não pesquisou nada.
        TXT;
    }

    /**
     * The answer, nested: every precedent lives inside the thesis it sustains.
     *
     * Nesting is not a formatting choice. A flat pair of lists would need the
     * model to mint an id per thesis and quote it from each precedent, and a
     * model cannot do that reliably — one repeated uuid silently reassigns a
     * ruling to the wrong argument, and a dangling one points at nothing. Here
     * the link is structural, so the grammar itself cannot emit a broken
     * reference. ForensicReviewData::fromAgent() flattens it afterwards and
     * mints the correlation ids in PHP, which is exactly the part
     * `crypto.randomUUID()` plays for the browser.
     *
     * The `required()` and `nullable()` pairing throughout is the contract the
     * twelve defendant fields use: the key must be there, and `null` is a legal
     * answer to it. Here it is what keeps "the sheet did not say" from looking
     * like "the key is missing".
     *
     * **Only one list carries a `max()`, and that is a Gemini limit rather than
     * a preference.** `max()` becomes `maxItems`, and this provider counts it
     * against a complexity budget for `response_json_schema`: with a cap on
     * `theses` *and* a cap on either list nested inside a thesis, the request
     * comes back 400 INVALID_ARGUMENT before a token is generated. Measured by
     * bisection — a cap on the outer list alone passes, caps on the inner lists
     * alone pass, no caps at all passes, and the same two caps pass in a small
     * toy schema, so the budget is the sum and not the shape. The ceiling that
     * matters most stays in the grammar; the other two are asked for in the
     * researcher's prompt and cut to size by LegalResearchData.
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

            'theses' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()
                        ->description('O rótulo Nome da ficha, copiado.')
                        ->required(),

                    'type' => $schema->string()
                        ->description('O rótulo Tipo da ficha. Nulo se ausente ou irreconhecível.')
                        ->enum([...$this->thesisTypes, null])
                        ->nullable()
                        ->required(),

                    'description' => $schema->string()
                        ->description('O rótulo Descrição da ficha, copiado sem reescrever.')
                        ->required(),

                    'impact' => $schema->string()
                        ->description('O rótulo Impacto da ficha. Nulo quando a ficha escreve `nulo`.')
                        ->nullable()
                        ->required(),

                    'legal_bases' => $schema->array()
                        ->items($schema->object([
                            'type' => $schema->string()
                                ->description('O rótulo Tipo do fundamento. Nulo se ausente.')
                                ->enum([...$this->basisTypes, null])
                                ->nullable()
                                ->required(),

                            'reference' => $schema->string()
                                ->description('O rótulo Referência, copiado inteiro e pronto para ler.')
                                ->required(),

                            'source' => $schema->string()
                                ->description('O rótulo Sigla. Nulo quando a ficha não traz.')
                                ->nullable()
                                ->required(),

                            'source_url' => $schema->string()
                                ->description(
                                    'O rótulo URL do fundamento, copiado exatamente. Nulo quando a '
                                    .'ficha não traz um endereço.'
                                )
                                ->nullable()
                                ->required(),
                        ]))
                        ->description('Os itens de ### Fundamentos desta tese, na ordem da ficha.')
                        ->required(),

                    'precedents' => $schema->array()
                        ->items($schema->object([
                            'name' => $schema->string()
                                ->description('O rótulo Nome do precedente, copiado.')
                                ->required(),

                            'type' => $schema->string()
                                ->description('O rótulo Tipo do precedente. Nulo se ausente.')
                                ->enum([...$this->precedentTypes, null])
                                ->nullable()
                                ->required(),

                            'description' => $schema->string()
                                ->description('O rótulo Ementa, copiado sem resumir.')
                                ->required(),

                            'citation' => $schema->string()
                                ->description('O rótulo Citação, copiado como está. Nulo se ausente.')
                                ->nullable()
                                ->required(),

                            'grounding' => $schema->string()
                                ->description('O rótulo Fundamentação, copiado. Nulo se ausente.')
                                ->nullable()
                                ->required(),

                            'adherence' => $schema->string()
                                ->description(
                                    'O rótulo Aderência, só o número ("95"). Nulo quando a ficha '
                                    .'escreve `nulo`, um intervalo ou um texto.'
                                )
                                ->nullable()
                                ->required(),

                            'source_url' => $schema->string()
                                ->description(
                                    'O rótulo URL do precedente, copiado exatamente. Nulo quando a '
                                    .'ficha não traz um endereço.'
                                )
                                ->nullable()
                                ->required(),
                        ]))
                        ->description('Os itens de ### Precedentes desta tese, na ordem da ficha.')
                        ->required(),
                ]))
                ->max(LegalResearchData::MAX_THESES)
                ->description(
                    'Uma entrada por seção ## TESE da ficha, na mesma ordem. Lista vazia '
                    .'quando a ficha não traz nenhuma.'
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
}
