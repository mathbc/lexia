<?php

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The users listing: search, filters, sorting and pagination.
 *
 * The listing belongs to one account, which is the one in the URL — not the
 * one in TenantContext. For platform staff the scope is deliberately open, so
 * an explicit account_id filter is the only thing keeping the tab showing the
 * tenant the page is about.
 */
final class UserIndexQuery
{
    private const int PER_PAGE = 15;

    /** @var list<string> */
    private const array SORTABLE = ['name', 'email', 'role', 'type', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(Account $account, array $filters): LengthAwarePaginator
    {
        return User::acrossAllAccounts()
            ->where('account_id', $account->id)
            ->when($filters['search'] ?? null, $this->search(...))
            ->when($filters['role'] ?? null, $this->byRole(...))
            ->when($filters['type'] ?? null, $this->byType(...))
            // Passing the value explicitly: when() would hand the callback the
            // boolean condition, turning a "disabled" filter into "enabled".
            ->when(
                $this->hasStatusFilter($filters),
                fn (Builder $query) => $this->byStatus($query, $filters['enabled']),
            )
            ->orderBy(
                $this->sortColumn($filters['sort'] ?? null),
                $this->sortDirection($filters['direction'] ?? null),
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Builder<User>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('oab_number', 'ilike', "%{$term}%");
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function byRole(Builder $query, string $role): void
    {
        $query->where('role', UserRole::from($role));
    }

    /**
     * @param  Builder<User>  $query
     */
    private function byType(Builder $query, string $type): void
    {
        $query->where('type', UserType::from($type));
    }

    /**
     * @param  Builder<User>  $query
     */
    private function byStatus(Builder $query, mixed $value): void
    {
        $query->where('enabled', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasStatusFilter(array $filters): bool
    {
        return filled($filters['enabled'] ?? null);
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
