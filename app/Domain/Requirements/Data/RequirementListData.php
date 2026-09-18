<?php

declare(strict_types=1);

namespace App\Domain\Requirements\Data;

/**
 * The whole list a pleading asks for, as one value.
 *
 * The requests are saved together and never one at a time — the screen holds a
 * list and the step submits it whole — so the list, and not the row, is the
 * argument the saving Action takes. It is also what the extraction agent
 * returns, which is the point of the shape: a suggestion the lawyer accepts is
 * written by the code that writes the form.
 *
 * Blank requests are dropped on the way in, wherever they came from, mirroring
 * `writtenRequirements()` on the screen: a field the lawyer opened and
 * abandoned is not a request, and refusing the save over it would be worse than
 * ignoring it. From a model, the same filter catches the empty string a
 * grammar cannot forbid.
 *
 * The order is the order they were posted in, which is the order they were
 * written and the order they will be numbered — "requer: 1. …; 2. …". There is
 * no position column to carry it; the insertion order and `oldest()` do.
 *
 * The empty list is meaningful at both ends: it is how a lawyer clears the
 * step, and it is how a narrative that asks for nothing comes back.
 */
final readonly class RequirementListData
{
    /**
     * @param  list<RequirementData>  $requirements
     */
    public function __construct(public array $requirements) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return self::of($validated['requirements'] ?? null, RequirementData::fromArray(...));
    }

    /**
     * The requirement extraction agent's answer, under the key its schema
     * declares, with the narrative it was read from.
     *
     * The narrative is here to be a **guard on the money**, and it is the one
     * rule in this class that a prompt could not be made to keep. Across runs
     * the model has claimed R$ 5.000,00 and R$ 12.000,00 for a case whose
     * narrative names neither — a figure it composed out of what a request of
     * that kind usually costs. Nothing downstream can tell that apart from a
     * figure the client wrote: it arrives well-formed, it reads plausibly in
     * the sentence beside it, and it would be filed as the value of a claim.
     *
     * So an amount survives only when the narrative contains it. What that
     * leaves behind is the visible failure instead of the invisible one: the
     * request keeps its sentence and loses its cifra, and the lawyer fills a
     * blank field rather than approving a confident wrong number.
     *
     * The sentence may still spell the invented figure out — this guards the
     * column, not the prose. That is the right half to guard: the field is what
     * is stored, summed and read back, while the sentence is text the lawyer
     * reads and rewrites. A blank field beside a sentence naming a figure is
     * also, usefully, the shape of a request that wants a second look.
     *
     * The check is deliberately generous — every number in the text counts,
     * whatever it was counting there. It is not trying to decide whether the
     * figure belongs to *that* request, which is judgement; it only refuses the
     * figures that came from nowhere. The known cost is a narrative that spells
     * its money out in words: "quinze mil reais" anchors nothing, so that
     * request loses an amount it was entitled to, and the sentence still says
     * it.
     *
     * @param  array<string, mixed>  $answer
     * @param  string  $facts  the narrative, as the agent received it
     */
    public static function fromAgent(array $answer, string $facts): self
    {
        $written = self::figuresIn($facts);

        return self::of(
            $answer['requirements'] ?? null,
            static fn (array $row): RequirementData => RequirementData::fromAgent($row)
                ->withoutAmountUnless($written),
        );
    }

    /**
     * The list as a screen receives it, which is also the JSON the
     * classification endpoint publishes.
     *
     * The amount goes out as the decimal string the column speaks, and not
     * masked: masking belongs to the field that draws it, and the browser
     * already owns that function.
     *
     * @return list<array{description: string, amount: string|null}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (RequirementData $requirement): array => [
                'description' => $requirement->description,
                'amount' => $requirement->amount,
            ],
            $this->requirements,
        );
    }

    /**
     * Every number the narrative writes, as decimal strings.
     *
     * Read with the same parser the agent's answers go through, so the two
     * sides of the comparison speak one notation: "R$ 25.200,00" in the text
     * and "25200.00" in the answer are the same figure, and both arrive here
     * as "25200.00".
     *
     * A date, a deadline and a house number all land in this set too. That is
     * the intended looseness — see `fromAgent()`.
     *
     * @return list<string>
     */
    private static function figuresIn(string $facts): array
    {
        // Cada token começa e termina em dígito: "R$ 25.200,00, doutor" traz
        // "25.200,00" e não "25.200,00,", que o leitor de valor entenderia
        // como outro número.
        preg_match_all('/\d+(?:[.,]\d+)*/', $facts, $matches);

        return array_values(array_unique(array_filter(array_map(
            static fn (string $figure): ?string => RequirementData::amountWrittenAs($figure),
            $matches[0],
        ))));
    }

    /**
     * @param  callable(array<string, mixed>): RequirementData  $read
     */
    private static function of(mixed $rows, callable $read): self
    {
        $requirements = array_map($read, is_array($rows) ? array_values($rows) : []);

        return new self(array_values(array_filter(
            $requirements,
            static fn (RequirementData $requirement): bool => $requirement->isWritten(),
        )));
    }
}
