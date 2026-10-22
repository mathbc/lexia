<?php

declare(strict_types=1);

namespace App\Domain\Doctrines\Data;

use App\Domain\Doctrines\Support\DoctrineSources;

/**
 * The whole doctrine of a pleading, as one value.
 *
 * CourtDecisionListData for scholarship: the works are saved together and never
 * one at a time — the research writes them whole, the "Concluir" submits them
 * whole —, so the list is what SaveLegalCaseDoctrines takes.
 *
 * The two filters run again on the way in, for the sibling's reason: on the way
 * out of the research the refusals are reported, and on the way in from a
 * browser there is nobody to report to. A work that cannot be attributed, or
 * that does not trace back to a library record, is simply not written.
 */
final readonly class DoctrineListData
{
    /**
     * @param  list<DoctrineData>  $doctrines
     */
    public function __construct(public array $doctrines) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $rows = $validated['doctrines'] ?? null;

        if (! is_array($rows)) {
            return new self([]);
        }

        return self::of(array_map(
            DoctrineData::fromArray(...),
            array_values(array_filter($rows, 'is_array')),
        ));
    }

    /**
     * @param  list<DoctrineData>  $doctrines
     */
    public static function of(array $doctrines): self
    {
        return new self(array_values(array_filter($doctrines, self::isCitable(...))));
    }

    /**
     * Whether a work can be cited: attributable, and traceable to its record.
     */
    private static function isCitable(DoctrineData $doctrine): bool
    {
        return $doctrine->isAttributable() && DoctrineSources::isRecord($doctrine->sourceUrl);
    }
}
