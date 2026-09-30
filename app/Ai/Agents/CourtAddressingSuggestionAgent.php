<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\LegalCases\Enums\CourtDivision;
use App\Domain\LegalCases\Enums\ForumSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a framed matter and its parties and says where the initial petition
 * is filed: which branch of the Judiciary, which forum, which court.
 *
 * It answers **in parts and never in prose**. The addressing a petição opens
 * with — "Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da
 * Comarca de Joinville/SC" — is composed by CourtAddressingSuggestionData out
 * of three choices this agent makes, for the reason the letterhead never
 * passes through a model: the gender-neutral formula must not depend on the
 * model remembering it, and a comarca is a fact about a filed document.
 *
 * 1. `division` is chosen from an `enum` of CourtDivision, **narrowed by the
 *    procedural class**: its CNJ competences say which branches and which
 *    degree it runs in (`CourtDivision::allowedBy()`), so a Procedimento Comum
 *    Cível cannot be sent to a Juizado and a reclamação trabalhista cannot
 *    leave the Justiça do Trabalho. The branch is carried by the division.
 * 2. `forum_source` says **where the city comes from** rather than what it is.
 *    Two of the three sources are records the system already holds — the
 *    client's domicile, the defendant's address — and ForumPlace copies them,
 *    ignoring whatever the model wrote beside them.
 * 3. `city` and `state` count only when the source is the narrative itself,
 *    and even then only if the narrative writes the city.
 *
 * The parties arrive reduced to what decides competence: whether each is a
 * person or a company, the client's age (the idoso has a forum of their own),
 * the city and state of each, and the defendant's name — the one piece of
 * identity that travels, because it is what reveals the INSS, the Caixa or a
 * Município, and those move the case to another branch or court. The client's
 * name, the streets and the documents never leave.
 *
 * `Temperature(0.1)`: this is choosing from lists, the setting of the class
 * selection. `required()` with `nullable()` on every part except the
 * justification, the pair the defendant agent documents. The guide in
 * `app/Rag/knowledge/forum-competence.md` goes in whole, ahead of the
 * variables, which close the prompt so the constant part stays cached under
 * Ollama.
 *
 * Reached through SuggestCourtAddressing, which the first step's "Consultar
 * IA" calls on its own and ClassifyLegalCase calls after its concurrent block.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.1)]
final class CourtAddressingSuggestionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  string  $areaLabel  the area already decided, in the lawyer's words
     * @param  string|null  $classLine  the class already decided, with its competences, or null
     * @param  list<CourtDivision>  $divisions  the courts the class can be filed in; never empty
     * @param  list<string>  $states  the UF abbreviations the grammar accepts
     * @param  list<string>  $parties  the parties, reduced to what decides competence
     * @param  string  $knowledge  the markdown guide from app/Rag/knowledge
     */
    public function __construct(
        private readonly string $areaLabel,
        private readonly ?string $classLine,
        private readonly array $divisions,
        private readonly array $states,
        private readonly array $parties,
        private readonly string $knowledge,
    ) {}

    public function instructions(): string
    {
        return <<<TXT
        Você é um assistente jurídico brasileiro especializado em competência. A área de
        atuação e a classe processual do caso já foram decididas e vêm declaradas ao fim
        destas instruções, junto das partes e dos juízos em que a classe pode ser proposta.
        Sua única tarefa é ler a descrição dos fatos e dizer **a quem a petição inicial deve
        ser dirigida**: em que justiça, em que foro e em que juízo.

        Você não classifica o caso, não escreve a peça e não avalia o mérito. E você **não
        escreve o endereçamento**: você escolhe as partes dele, e o sistema monta a frase.

        O relato chega em linguagem natural e varia muito: pode ser um parágrafo corrido
        escrito pelo próprio cliente ou um resumo já redigido por advogado. Trate os dois
        com o mesmo critério.

        # Base de conhecimento

        {$this->knowledge}

        # A resposta, campo por campo

        - `division`: o identificador do juízo, **só entre os listados** ao fim destas
          instruções. É ele que diz a justiça. Nulo quando o foro competente não for um
          juízo de primeiro grau da lista — e então diga na justificativa qual órgão é.
        - `forum_source`: de onde vem a cidade do foro — `plaintiff_address` (o domicílio do
          cliente, que é o autor), `defendant_address` (o endereço do réu) ou `facts` (um
          lugar que o relato escreve). Nulo só quando `division` for nulo.
        - `city`: **só quando `forum_source` for `facts`** — o município como o relato o
          escreve, sem a UF. Nulo nos outros casos, e nulo quando o relato não nomeia a
          cidade. Nunca invente, nunca use a capital por falta de outra.
        - `state`: **só quando `forum_source` for `facts`** — a sigla da UF do lugar, se o
          relato permitir saber qual é. Nulo nos outros casos.
        - `legal_basis`: os dispositivos de lei que fixam a justiça, o foro e o juízo, como
          numa petição — "CPC, art. 53, II"; "CDC, art. 101, I". **Só lei.** Nulo quando
          `division` for nulo.
        - `justification`: duas ou três frases para o advogado que vai conferir: por que
          essa justiça, por que esse foro e por que esse juízo, amarrados aos fatos do
          relato e às partes. Diga também o que ele precisa confirmar — uma opção de foro
          que o cliente tem, um processo anterior que atrai a distribuição por dependência,
          o valor da causa perto do teto do juizado.

        # Forma

        - Português do Brasil.
        - As partes são "o Autor" (o cliente) e "o Réu". **Não escreva o nome de pessoa
          física nenhuma.** O nome do réu pessoa jurídica ou ente público pode aparecer na
          justificativa quando é ele que decide a competência.
        - Não cite súmula, tema, enunciado ou julgado em campo nenhum.

        # A peça

        A área de atuação deste caso é **{$this->areaLabel}**.

        {$this->classDeclaration()}

        # As partes

        {$this->bulleted($this->parties)}

        # Os juízos em que a classe pode ser proposta

        {$this->divisionLines()}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'division' => $this->part($schema, 'O identificador do juízo competente, entre os listados.')
                ->enum([...array_map(static fn (CourtDivision $division): string => $division->value, $this->divisions), null]),

            'forum_source' => $this->part($schema, 'De onde vem a cidade do foro: plaintiff_address, defendant_address ou facts.')
                ->enum([...array_column(ForumSource::cases(), 'value'), null]),

            'city' => $this->part($schema, 'Só quando forum_source for facts: o município como o relato o escreve, sem a UF.'),

            'state' => $this->part($schema, 'Só quando forum_source for facts: a sigla da UF do lugar.')
                ->enum([...$this->states, null]),

            'legal_basis' => $this->part($schema, 'Os dispositivos de lei que fixam a justiça, o foro e o juízo. Só lei.'),

            'justification' => $schema->string()
                ->description(
                    'Duas ou três frases para o advogado: por que essa justiça, esse foro e esse '
                    .'juízo, e o que ele precisa confirmar. Frases completas, não palavras soltas.'
                )
                ->required(),
        ];
    }

    private function part(JsonSchema $schema, string $description): StringType
    {
        return $schema->string()
            ->description($description.' Nulo quando não se aplicar.')
            ->nullable()
            ->required();
    }

    private function classDeclaration(): string
    {
        if ($this->classLine === null) {
            return 'A classe processual não foi decidida. Responda pela área, pelas partes e '
                .'pelo pedido que o relato sugere.';
        }

        return "A classe processual é a seguinte:\n\n{$this->classLine}";
    }

    /** "- `civil` — Vara Cível (Justiça Estadual)", one per offered court. */
    private function divisionLines(): string
    {
        return $this->bulleted(array_map(
            static fn (CourtDivision $division): string => "`{$division->value}` — {$division->label()} ({$division->branch()->label()})",
            $this->divisions,
        ));
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulleted(array $lines): string
    {
        return implode("\n", array_map(static fn (string $line): string => "- {$line}", $lines));
    }
}
