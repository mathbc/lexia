<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The accounts listing: search, filters, sorting and pagination.
 *
 * Only platform staff ever reach this screen, and for them VisibleAccountScope
 * is already open; for anyone else it would collapse the result to their own
 * row, which is the safe failure mode.
 */
final class AccountIndexQuery
{
    private const int PER_PAGE = 15;

    /** @var list<string> */
    private const array SORTABLE = ['name', 'type', 'city', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Account>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Account::query()
            ->withCount('users')
            ->when($filters['search'] ?? null, $this->search(...))
            ->when($filters['type'] ?? null, $this->byType(...))
            // Passing the value explicitly: when() would hand the callback the
            // boolean condition, turning an "inactive" filter into "active".
            ->when(
                filled($filters['active'] ?? null),
                fn (Builder $query) => $this->byStatus($query, $filters['active']),
            )
            ->orderBy(
                $this->sortColumn($filters['sort'] ?? null),
                $this->sortDirection($filters['direction'] ?? null),
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Builder<Account>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term): void {
            foreach (['name', 'legal_name', 'email', 'federal_id', 'oab_number'] as $column) {
                $query->orWhere($column, 'ilike', "%{$term}%");
            }
        });
    }

    /**
     * @param  Builder<Account>  $query
     */
    private function byType(Builder $query, string $type): void
    {
        $query->where('type', AccountType::from($type));
    }

    /**
     * @param  Builder<Account>  $query
     */
    private function byStatus(Builder $query, mixed $value): void
    {
        $query->where('active', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    private function sortColumn(?string $sort): string
    {
        return in_array($sort, self::SORTABLE, true) ? $sort : 'name';
    }

    private function sortDirection(?string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }
}
