<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Queries;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pleadings listing: search, filters, pagination and the card projection.
 *
 * The account is filtered explicitly rather than left to AccountScope. For
 * platform staff the scope is deliberately open, and without this the listing
 * would show every tenant's pleadings at once — the same reason
 * CustomerIndexQuery names the column by hand.
 */
final class LegalCaseIndexQuery
{
    /** Twelve, not fifteen: the cards sit in a two and three column grid. */
    private const int PER_PAGE = 12;

    /** @var list<string> */
    private const array SORTABLE = ['created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(string $accountId, array $filters): LengthAwarePaginator
    {
        return LegalCase::acrossAllAccounts()
            ->where('account_id', $accountId)
            // Without this the card grid asks the database three times a row.
            ->with(['customer', 'practiceArea', 'proceduralClass'])
            ->when($filters['search'] ?? null, $this->search(...))
            ->when($filters['customer'] ?? null, $this->byCustomer(...))
            ->when($filters['practice_area'] ?? null, $this->byPracticeArea(...))
            // Passing the value explicitly: when() would hand the callback the
            // boolean condition, turning "finalizada" into "rascunho".
            ->when(
                filled($filters['status'] ?? null),
                fn (Builder $query) => $this->byStatus($query, (string) $filters['status']),
            )
            ->orderBy(
                $this->sortColumn($filters['sort'] ?? null),
                $this->sortDirection($filters['direction'] ?? null),
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through($this->toCard(...));
    }

    /**
     * What a card shows. Projected here rather than serialising the models, so
     * the screen never receives a column it does not draw.
     *
     * @return array<string, mixed>
     */
    private function toCard(LegalCase $legalCase): array
    {
        return [
            'id' => $legalCase->id,
            'customer' => [
                'id' => $legalCase->customer->id,
                // A company trades under its corporate name, a person under
                // their own — Customer::displayName() is the rule.
                'display_name' => $legalCase->customer->displayName(),
            ],
            'practice_area' => [
                'slug' => $legalCase->practiceArea->slug,
                'label' => $legalCase->practiceArea->label,
            ],
            'procedural_class' => [
                'code' => $legalCase->proceduralClass->code,
                'name' => $legalCase->proceduralClass->name,
                'abbreviation' => $legalCase->proceduralClass->abbreviation,
            ],
            'current_step' => $legalCase->current_step->value,
            // O rótulo resolvido aqui, e não no React: o português do enum
            // mora de um lado só.
            'current_step_label' => $legalCase->current_step->label(),
            'is_draft' => $legalCase->is_draft,
            'created_at' => $legalCase->created_at?->toIso8601String(),
        ];
    }

    /**
     * A pleading has no name of its own, so the term is matched against what
     * identifies it on the card: the client and the procedural class.
     *
     * @param  Builder<LegalCase>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term): void {
            $query->whereHas('customer', fn (Builder $customer): Builder => $customer
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('legal_name', 'ilike', "%{$term}%"))
                ->orWhereHas('proceduralClass', fn (Builder $class): Builder => $this->matchClass($class, $term));
        });
    }

    /**
     * The CNJ code is what a lawyer quotes ("classe 7"), so a term typed as
     * digits searches it as well as the name.
     *
     * @param  Builder<ProceduralClass>  $query
     * @return Builder<ProceduralClass>
     */
    private function matchClass(Builder $query, string $term): Builder
    {
        $query->where('name', 'ilike', "%{$term}%");

        $digits = (string) preg_replace('/\D/', '', $term);

        return $digits === '' ? $query : $query->orWhere('code', (int) $digits);
    }

    /**
     * Draft or finished.
     *
     * A value the screen does not offer filters nothing, rather than filtering
     * the opposite of what was asked — which is what a bare boolean cast of an
     * unexpected string would do.
     *
     * @param  Builder<LegalCase>  $query
     */
    private function byStatus(Builder $query, string $status): void
    {
        if (! in_array($status, ['draft', 'final'], true)) {
            return;
        }

        $query->where('is_draft', $status === 'draft');
    }

    /**
     * @param  Builder<LegalCase>  $query
     */
    private function byCustomer(Builder $query, string $customerId): void
    {
        $query->where('customer_id', $customerId);
    }

    /**
     * Filtered by slug rather than uuid: the slug is the stable identity of an
     * area, and it keeps the query string readable.
     *
     * @param  Builder<LegalCase>  $query
     */
    private function byPracticeArea(Builder $query, string $slug): void
    {
        $query->whereHas('practiceArea', fn (Builder $area): Builder => $area->where('slug', $slug));
    }

    private function sortColumn(?string $sort): string
    {
        return in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
    }

    /** Newest first: a work list opens on what was drafted last. */
    private function sortDirection(?string $direction): string
    {
        return $direction === 'asc' ? 'asc' : 'desc';
    }
}
