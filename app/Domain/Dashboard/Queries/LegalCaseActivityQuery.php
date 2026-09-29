<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Dashboard\Queries\Concerns\ConstrainsToAccount;
use App\Domain\LegalCases\Models\LegalCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The monthly chart: pleadings opened in each month of a year, split by where
 * they stand today.
 *
 * The split is the current draft flag, not a finishing date — no column records
 * when a pleading was finalised — so a bar reads "opened in March, of which N
 * are finished by now".
 *
 * `created_at` is written in APP_TIMEZONE, so the month Postgres extracts is
 * already the local one; a pleading opened at 23h on the last day of a month
 * stays in that month.
 */
final class LegalCaseActivityQuery
{
    use ConstrainsToAccount;

    /**
     * The years that have at least one pleading, newest first — the only ones
     * the year filter offers.
     *
     * @return list<int>
     */
    public function years(?string $accountId): array
    {
        return $this->withinAccount(LegalCase::acrossAllAccounts(), $accountId)
            ->whereNotNull('created_at')
            ->toBase()
            ->selectRaw('distinct extract(year from created_at)::int as year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(static fn (mixed $year): int => (int) $year)
            ->values()
            ->all();
    }

    /**
     * Twelve entries, one per month, the empty ones included: the chart draws
     * a zero, not a gap.
     *
     * @return list<array{month: int, finalized: int, drafts: int}>
     */
    public function monthly(?string $accountId, int $year, ?string $userId): array
    {
        $start = CarbonImmutable::create($year, 1, 1);

        $rows = $this->withinAccount(LegalCase::acrossAllAccounts(), $accountId)
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            // A range rather than whereYear(): extract() on the column would
            // keep the database from using an index on it.
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addYear())
            ->toBase()
            ->selectRaw('extract(month from created_at)::int as month')
            ->selectRaw('count(*) filter (where not is_draft) as finalized')
            ->selectRaw('count(*) filter (where is_draft) as drafts')
            ->groupBy('month')
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->month);

        return array_map(static fn (int $month): array => [
            'month' => $month,
            'finalized' => (int) ($rows->get($month)->finalized ?? 0),
            'drafts' => (int) ($rows->get($month)->drafts ?? 0),
        ], range(1, 12));
    }
}
