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
 * Chooses the electronic system a pleading is filed through, when the state
 * court of the forum runs more than one.
 *
 * The second link of the addressing chain, and the order is imposed for the
 * reason that separates the area from the class: the candidates do not exist
 * until the first link has said which state the forum is in. They arrive
 * **injected** — the rows of the map for that court, with their status — and
 * again as the `enum()` of the schema, so a system the court does not run is
 * not something the model can emit. The candidates travel by **slug**: the
 * uuid is generated when the map loads and differs between databases.
 *
 * It is only asked when there is a choice. A court with one system in the map
 * is answered by SelectJudicialSystem straight from the table, which is most
 * of them; today the question exists in São Paulo, Rio Grande do Norte,
 * Roraima and Amapá. That is also the honest limit of what it knows: the map
 * says both systems are in use, and which comarca has already moved is a fact
 * the model may or may not have — the instructions make it say which.
 *
 * `Temperature(0.1)`, the setting of the agents that choose from a list. The
 * provider pair, the timeout and the variables-at-the-end order are the
 * siblings', for the siblings' reasons.
 *
 * Reached through SelectJudicialSystem, which SuggestCourtAddressing calls
 * after the addressing agent has placed the forum.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.1)]
final class JudicialSystemSelectionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  string  $forum  the forum already placed, as "Juízo: …; Tribunal: TJSP; Comarca: …"
     * @param  list<array{slug: string, line: string}>  $candidates  the systems the court runs, as the map records them
     * @param  string  $areaLabel  the area already decided
     * @param  string|null  $classLine  the class already decided, or null
     * @param  list<string>  $parties  the parties, reduced to what decides competence
     */
    public function __construct(
        private readonly string $forum,
        private readonly array $candidates,
        private readonly string $areaLabel,
        private readonly ?string $classLine,
        private readonly array $parties,
    ) {}

    public function instructions(): string
    {
        return <<<TXT
        Você é um assistente jurídico brasileiro que conhece o processo eletrônico dos
        tribunais de justiça. O foro competente da petição inicial já foi decidido e vem
        declarado ao fim destas instruções, junto dos sistemas eletrônicos que o tribunal
        daquele estado usa segundo o mapa do escritório. Sua única tarefa é dizer **por qual
        desses sistemas** a petição deve ser protocolada.

        Você não decide o foro, não escreve o endereçamento e não avalia o mérito.

        # O que o mapa diz

        Cada sistema candidato vem com a situação que o mapa registra para aquele tribunal:

        - **Em uso**: o tribunal recebe peças por ele.
        - **Em transição**: o tribunal está migrando para ele; parte das comarcas já o usa, e
          as outras continuam no sistema anterior.
        - **Em implantação**: o tribunal começou a adotá-lo; a maioria das comarcas ainda não
          o usa.
        - **Em coexistência com sistemas anteriores**: ele convive com o antigo, e a comarca
          ou a competência decide qual deles recebe a peça.

        # A decisão

        1. Prefira o sistema **em uso** ao que está em implantação ou em transição, a menos
           que você saiba que a comarca do foro já migrou.
        2. Quando dois sistemas estão em uso ao mesmo tempo, decida pela comarca e pela
           competência do juízo, que é o que os tribunais usam para dividir a migração. Se
           você não souber qual deles a comarca usa, escolha o que o tribunal adotou como
           destino da migração e **diga na justificativa que não sabe**.
        3. Você só pode escolher entre os candidatos listados. Não recomende sistema que o
           mapa não traga.

        # A resposta

        - `system`: o identificador do sistema escolhido, exatamente como aparece entre
          crases na lista de candidatos.
        - `justification`: uma ou duas frases para o advogado que vai conferir: por que este
          sistema, e o que ele deve confirmar no portal do tribunal antes de protocolar.

        Português do Brasil. Nunca use nomes próprios das partes.

        # A peça

        A área de atuação deste caso é **{$this->areaLabel}**.

        {$this->classDeclaration()}

        As partes:

        {$this->bulleted($this->parties)}

        # O foro decidido

        {$this->forum}

        # Os sistemas candidatos

        {$this->bulleted(array_column($this->candidates, 'line'))}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'system' => $schema->string()
                ->description('O identificador do sistema escolhido, entre os candidatos.')
                ->enum(array_column($this->candidates, 'slug'))
                ->required(),

            'justification' => $schema->string()
                ->description('Uma ou duas frases: por que este sistema e o que confirmar antes de protocolar.')
                ->required(),
        ];
    }

    private function classDeclaration(): string
    {
        return $this->classLine === null
            ? 'A classe processual não foi decidida.'
            : "A classe processual é a seguinte:\n\n{$this->classLine}";
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulleted(array $lines): string
    {
        return implode("\n", array_map(static fn (string $line): string => "- {$line}", $lines));
    }
}
