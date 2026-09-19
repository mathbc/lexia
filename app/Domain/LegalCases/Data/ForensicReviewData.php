<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalTheses\Data\LegalThesisData;

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
 * There is no `fromAgent()` yet, and that is deliberate rather than pending.
 * `DefendantData` has none because its agent's schema spells the keys as the
 * columns; `RequirementListData` has one only because money arrives in two
 * dialects and needed a guard no prompt could hold. Neither trigger has fired
 * here, and writing the constructor before the agent exists would be guessing at
 * a schema nobody has written.
 *
 * What that constructor will have to do, written down here so whoever builds the
 * agent does not rediscover it: a model cannot mint correlating uuids reliably,
 * so the agent's schema should be **nested** — each thesis carrying its own
 * precedents — which makes the link structural and leaves the grammar unable to
 * emit a dangling reference. `fromAgent()` then flattens that into these two
 * lists, minting one correlation uuid per thesis, playing exactly the part
 * `crypto.randomUUID()` plays in the browser. Nothing below needs to change for
 * that: the correlation key is a hint, and anything may mint one.
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
