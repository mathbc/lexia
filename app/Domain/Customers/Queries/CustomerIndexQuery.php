<?php

declare(strict_types=1);

namespace App\Domain\Customers\Queries;

use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The clients listing: search, filters, sorting and pagination.
 *
 * The account is filtered explicitly rather than left to AccountScope. For
 * platform staff the scope is deliberately open, and without this the listing
 * would show every tenant's clients at once — the same reason UserIndexQuery
 * names the column by hand.
 */
final class CustomerIndexQuery
{
    private const int PER_PAGE = 15;

    /** @var list<string> */
    private const array SORTABLE = ['name', 'type', 'city', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(string $accountId, array $filters): LengthAwarePaginator
    {
        return Customer::acrossAllAccounts()
            ->where('account_id', $accountId)
            ->when($filters['search'] ?? null, $this->search(...))
            ->when($filters['type'] ?? null, $this->byType(...))
            ->orderBy(
                $this->sortColumn($filters['sort'] ?? null),
                $this->sortDirection($filters['direction'] ?? null),
            )
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function search(Builder $query, string $term): void
    {
        // The documents are stored as digits, so a term typed with a mask
        // ("123.456") only matches once its punctuation is stripped.
        $digits = (string) preg_replace('/\D/', '', $term);

        $query->where(function (Builder $query) use ($term, $digits): void {
            foreach (['name', 'legal_name', 'email'] as $column) {
                $query->orWhere($column, 'ilike', "%{$term}%");
            }

            if ($digits !== '') {
                $query->orWhere('cpf', 'like', "%{$digits}%")
                    ->orWhere('cnpj', 'like', "%{$digits}%");
            }
        });
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function byType(Builder $query, string $type): void
    {
        $query->where('type', CustomerType::from($type));
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
