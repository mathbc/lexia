<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Dashboard\Queries\LegalCaseActivityQuery;
use App\Domain\Users\Models\User;
use Illuminate\Support\Str;

/**
 * What the dashboard is looking at: the account, the user and the year, read
 * from the URL and never trusted from it.
 *
 * This is the tenant boundary of the screen. The AccountScope cannot be it —
 * for staff it is open — so every filter is checked here against what the
 * actor may see:
 *
 * - the account is fixed for a customer, whatever `?account=` says; staff may
 *   pick any account, the LexIA one included, and none means all of them;
 * - the user must belong to the account in view, so a user id from another
 *   tenant filters nothing;
 * - the year must be one that has pleadings, and falls back to the newest.
 *
 * Ids are checked for shape before they reach the database: a malformed value
 * compared with a uuid column is an SQL error in Postgres, not an empty result.
 */
final readonly class DashboardScope
{
    /**
     * @param  list<int>  $years
     * @param  array<string, string|int>  $applied
     */
    private function __construct(
        public ?string $accountId,
        public ?string $userId,
        public ?int $year,
        public array $years,
        private array $applied,
    ) {}

    /**
     * A customer of the platform: their own account, and nothing else.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function withinAccount(string $accountId, array $filters, LegalCaseActivityQuery $activity): self
    {
        return self::make($accountId, [], $filters, $activity);
    }

    /**
     * LexIA staff: the account comes from the filter.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function acrossAccounts(array $filters, LegalCaseActivityQuery $activity): self
    {
        $accountId = self::account($filters['account'] ?? null);

        return self::make($accountId, array_filter(['account' => $accountId]), $filters, $activity);
    }

    /**
     * The filters that actually took effect, to echo back to the screen: a
     * value the URL carried but this class refused is not an active filter.
     *
     * @return array<string, string|int>
     */
    public function filters(): array
    {
        return $this->applied;
    }

    /**
     * @param  array<string, string>  $applied
     * @param  array<string, mixed>  $filters
     */
    private static function make(?string $accountId, array $applied, array $filters, LegalCaseActivityQuery $activity): self
    {
        $years = $activity->years($accountId);
        $year = self::year($years, $filters['year'] ?? null);
        $userId = self::user($accountId, $filters['user'] ?? null);

        return new self(
            $accountId,
            $userId,
            $year ?? $years[0] ?? null,
            $years,
            array_filter($applied + ['year' => $year, 'user' => $userId]),
        );
    }

    /**
     * An account that exists and has not been deleted — the same set the
     * filter offers and the sums add up.
     */
    private static function account(mixed $requested): ?string
    {
        if (! self::isUuid($requested)) {
            return null;
        }

        return Account::query()->whereKey($requested)->exists() ? $requested : null;
    }

    private static function user(?string $accountId, mixed $requested): ?string
    {
        if ($accountId === null || ! self::isUuid($requested)) {
            return null;
        }

        $belongs = User::acrossAllAccounts()
            ->where('account_id', $accountId)
            ->whereKey($requested)
            ->exists();

        return $belongs ? $requested : null;
    }

    /**
     * @param  list<int>  $years
     */
    private static function year(array $years, mixed $requested): ?int
    {
        $year = filter_var($requested, FILTER_VALIDATE_INT);

        return in_array($year, $years, true) ? $year : null;
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }
}
