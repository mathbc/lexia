<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalCases\Enums\FactsScreeningVerdict;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What the screening agent read in a narrative, and whether the smart fill may
 * go on with it.
 *
 * ## The agent answers questions, this decides
 *
 * The agent is asked six yes-or-no questions about the text — does it talk to
 * the system, does it read, is it a legal matter, does it say what happened,
 * what the client wants, and when — and never for a verdict. The verdict is
 * derived here, in a fixed order, so the line between "vague" and "admissible"
 * is a line in PHP that a test can hold, and not a judgement the model redraws
 * on every call.
 *
 * The order is the order of what the lawyer has to do about it. A text that
 * instructs the system is refused as that first, whatever else it is, because a
 * well-written injection is still an injection; noise comes before "not legal",
 * because a text that does not read cannot be said to be about anything; and
 * vague comes last, because it is the only refusal that is about a real case.
 *
 * The timeline is **never** a reason to refuse. A client often does not
 * remember the dates, and the drafting agent writes the gap as `[data]`. It is
 * only listed among what is missing once the narrative is refused as vague for
 * something else.
 *
 * ## The message is composed here, too
 *
 * It is the argument of the letterhead applied to an error: the headline comes
 * from the verdict's label, the list of what is missing from the signals, and
 * the agent only contributes one sentence about this particular text. That
 * sentence is **dropped** for a manipulation — it would be the model describing
 * the injected text back to whoever wrote it — and capped for the rest.
 */
final readonly class FactsScreeningData
{
    /** Uma ou duas frases, que é o que o agente foi pedido; o resto seria o modelo discorrendo. */
    public const int MAX_REASON = 300;

    public function __construct(
        public FactsScreeningVerdict $verdict,
        public bool $instructsTheSystem,
        public bool $intelligible,
        public bool $legalMatter,
        public bool $describesEvents,
        public bool $statesClaim,
        public bool $hasTimeline,
        public ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $response  the structured answer of FactsScreeningAgent
     */
    public static function fromAgent(array $response): self
    {
        $instructs = self::truthy($response['instructs_the_system'] ?? false);
        $intelligible = self::truthy($response['intelligible'] ?? false);
        $legal = self::truthy($response['legal_matter'] ?? false);
        $events = self::truthy($response['describes_events'] ?? false);
        $claim = self::truthy($response['states_claim'] ?? false);

        return new self(
            verdict: self::verdict($instructs, $intelligible, $legal, $events && $claim),
            instructsTheSystem: $instructs,
            intelligible: $intelligible,
            legalMatter: $legal,
            describesEvents: $events,
            statesClaim: $claim,
            hasTimeline: self::truthy($response['has_timeline'] ?? false),
            reason: self::text($response['reason'] ?? null),
        );
    }

    public function admissible(): bool
    {
        return $this->verdict === FactsScreeningVerdict::Admissible;
    }

    /**
     * The sentence the smart-fill screen shows under the facts.
     */
    public function message(): string
    {
        if ($this->admissible()) {
            return $this->verdict->label();
        }

        return implode(' ', array_filter([
            $this->verdict->label(),
            $this->missing(),
            $this->verdict === FactsScreeningVerdict::Manipulation ? null : $this->reason,
            'Revise o relato ou use o preenchimento manual.',
        ]));
    }

    /**
     * Uma chave que falta é um "não": a gramática obriga as sete, e um provider
     * que não a impusesse não ganharia o benefício da dúvida justo na porta.
     */
    private static function verdict(bool $instructs, bool $intelligible, bool $legal, bool $complete): FactsScreeningVerdict
    {
        return match (true) {
            $instructs => FactsScreeningVerdict::Manipulation,
            ! $intelligible => FactsScreeningVerdict::Unintelligible,
            ! $legal => FactsScreeningVerdict::NotLegal,
            ! $complete => FactsScreeningVerdict::Vague,
            default => FactsScreeningVerdict::Admissible,
        };
    }

    /**
     * What a vague narrative should say and does not, in the order a lawyer
     * would ask the client for it.
     */
    private function missing(): ?string
    {
        if ($this->verdict !== FactsScreeningVerdict::Vague) {
            return null;
        }

        $missing = array_keys(array_filter([
            'o que aconteceu' => ! $this->describesEvents,
            'o que o cliente quer obter' => ! $this->statesClaim,
            'quando os fatos ocorreram' => ! $this->hasTimeline,
        ]));

        return 'Falta dizer '.Arr::join($missing, ', ', ' e ').'.';
    }

    /** Um provider pode mandar o booleano como "true", e é o que se lê aqui. */
    private static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) === true;
    }

    /**
     * A razão aparada, cortada no teto e fechada com ponto — a frase seguinte
     * da mensagem não pode colar nela.
     */
    private static function text(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        if ($trimmed === '') {
            return null;
        }

        $limited = Str::limit($trimmed, self::MAX_REASON, '…', preserveWords: true);

        return preg_match('/[.!?…]$/u', $limited) === 1 ? $limited : $limited.'.';
    }
}
