<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalTheses\Data\LegalThesisData;
use Illuminate\Support\Str;

/**
 * The whole forensic review of a pleading, as one value.
 *
 * Both lists travel together and are saved together, because the link between
 * them is an id: a precedent quotes the thesis it sustains, and that quote can
 * name a thesis created in the very same submission. Splitting the value in two
 * would split the save in two, and there would be nowhere to hold the map from
 * the id a browser minted to the id the database kept. See
 * SaveLegalCaseForensicReview.
 *
 * Blank rows are dropped on the way in — a heading the lawyer opened and
 * abandoned is not a thesis — mirroring what the requirements step already does
 * with `writtenRequirements()`. Both empty lists are meaningful: that is how a
 * lawyer clears the step.
 *
 * `fromAgent()` exists now, and it does what this docblock used to predict it
 * would have to. The agent's schema is **nested** — each thesis carrying its own
 * precedents — because a model cannot mint correlating uuids reliably: one
 * repeated uuid silently reassigns a ruling to the wrong argument, and a
 * dangling one points at nothing. Nesting makes the link structural, so the
 * grammar cannot emit a broken reference in the first place. Flattening it back
 * into these two lists is this class's job, and the correlation uuid is minted
 * here in PHP, playing exactly the part `crypto.randomUUID()` plays in the
 * browser.
 *
 * Nothing else had to change for that, which was the prediction worth keeping:
 * the correlation key is a hint, and anything may mint one.
 *
 * Note what `fromAgent()` deliberately does **not** do: it neither checks that a
 * citation came from an official portal nor drops the ones that did not. That
 * belongs to LegalResearchData, which runs first and hands this method theses it
 * has already cleaned. Keeping the two apart is what lets the guard report what
 * it removed — a list this value has nowhere to carry, because it is the shape
 * the *form* posts too.
 */
final readonly class ForensicReviewData
{
    /**
     * @param  list<LegalThesisData>  $theses
     * @param  list<LegalPrecedentData>  $precedents
     */
    public function __construct(
        public array $theses,
        public array $precedents,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            theses: self::theses($validated['theses'] ?? null),
            precedents: self::precedents($validated['precedents'] ?? null),
        );
    }

    /**
     * The research agents' answer, flattened.
     *
     * Takes the nested shape — theses, each carrying its own precedents — and
     * produces the two parallel lists SaveLegalCaseForensicReview knows how to
     * write. The uuid minted per thesis is the correlation key that the
     * precedents quote, and it is thrown away the moment the save resolves it
     * against the rows it actually wrote: `HasUuids` mints the real one, and the
     * map in that Action is what connects them.
     *
     * A thesis with no name is dropped, exactly as a blank row from the form is,
     * and **its precedents go with it** — a ruling whose thesis does not exist
     * would arrive with a correlation key nothing resolves, which is the
     * dangling reference the nesting was chosen to prevent. That the save would
     * land it in `null` anyway is not a reason to send it: a precedent grounding
     * nothing, created by a machine, is a row a lawyer has to notice and delete.
     *
     * @param  list<array<string, mixed>>  $theses  already cleaned by LegalResearchData
     */
    public static function fromAgent(array $theses): self
    {
        /** @var list<LegalThesisData> $written */
        $written = [];

        /** @var list<LegalPrecedentData> $precedents */
        $precedents = [];

        foreach ($theses as $row) {
            $thesis = LegalThesisData::fromArray($row);

            if (! $thesis->isWritten()) {
                continue;
            }

            $id = (string) Str::uuid();

            $written[] = new LegalThesisData(
                id: $id,
                name: $thesis->name,
                type: $thesis->type,
                description: $thesis->description,
                impact: $thesis->impact,
                legalBases: $thesis->legalBases,
            );

            foreach (self::precedentsOf($row, $id) as $precedent) {
                $precedents[] = $precedent;
            }
        }

        return new self(theses: $written, precedents: $precedents);
    }

    /**
     * The rulings nested under one thesis, each pointing back at it.
     *
     * `thesisId` is the correlation key and not a foreign key — the same hint
     * the browser posts. LegalPrecedentData carries it precisely so that
     * SaveLegalCaseForensicReview can refuse to trust it, which is why that
     * class's `toArray()` takes the resolved id as an argument.
     *
     * @param  array<string, mixed>  $row
     * @return list<LegalPrecedentData>
     */
    private static function precedentsOf(array $row, string $thesisId): array
    {
        $rows = is_array($row['precedents'] ?? null) ? array_values($row['precedents']) : [];

        $precedents = array_map(
            static function (mixed $precedent) use ($thesisId): LegalPrecedentData {
                $data = LegalPrecedentData::fromArray(is_array($precedent) ? $precedent : []);

                return new LegalPrecedentData(
                    id: null,
                    thesisId: $thesisId,
                    name: $data->name,
                    type: $data->type,
                    description: $data->description,
                    citation: $data->citation,
                    grounding: $data->grounding,
                    adherence: $data->adherence,
                );
            },
            $rows,
        );

        return array_values(array_filter(
            $precedents,
            static fn (LegalPrecedentData $precedent): bool => $precedent->isWritten(),
        ));
    }

    /**
     * @return list<LegalThesisData>
     */
    private static function theses(mixed $rows): array
    {
        $theses = array_map(
            static fn (mixed $row): LegalThesisData => LegalThesisData::fromArray(
                is_array($row) ? $row : [],
            ),
            is_array($rows) ? array_values($rows) : [],
        );

        return array_values(array_filter(
            $theses,
            static fn (LegalThesisData $thesis): bool => $thesis->isWritten(),
        ));
    }

    /**
     * @return list<LegalPrecedentData>
     */
    private static function precedents(mixed $rows): array
    {
        $precedents = array_map(
            static fn (mixed $row): LegalPrecedentData => LegalPrecedentData::fromArray(
                is_array($row) ? $row : [],
            ),
            is_array($rows) ? array_values($rows) : [],
        );

        return array_values(array_filter(
            $precedents,
            static fn (LegalPrecedentData $precedent): bool => $precedent->isWritten(),
        ));
    }
}
