<?php

declare(strict_types=1);

namespace App\Domain\Requirements\Data;

/**
 * The whole list a pleading asks for, as one value.
 *
 * The requests are saved together and never one at a time — the screen holds a
 * list and the step submits it whole — so the list, and not the row, is the
 * argument the saving Action takes.
 *
 * Blank requests are dropped on the way in, mirroring `writtenRequirements()`
 * on the screen: a field the lawyer opened and abandoned is not a request, and
 * refusing the save over it would be worse than ignoring it.
 *
 * The order is the order they were posted in, which is the order they were
 * written and the order they will be numbered — "requer: 1. …; 2. …". There is
 * no position column to carry it; the insertion order and `oldest()` do.
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
        $rows = $validated['requirements'] ?? [];

        $requirements = array_map(
            static fn (array $row): RequirementData => RequirementData::fromArray($row),
            is_array($rows) ? array_values($rows) : [],
        );

        return new self(array_values(array_filter(
            $requirements,
            static fn (RequirementData $requirement): bool => $requirement->isWritten(),
        )));
    }
}
