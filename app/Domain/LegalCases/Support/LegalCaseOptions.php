<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\Customers\Models\Customer;
use App\Domain\JudicialSystems\Models\JudicialSystem;
use App\Domain\PracticeAreas\Models\PracticeArea;

/**
 * The select lists the pleading screens need: the account's clients, the
 * practice areas and the judicial systems.
 *
 * Shared so the filter panel and the form cannot drift on how a client is
 * named or how the areas are ordered.
 */
final class LegalCaseOptions
{
    /**
     * The account is named explicitly for the same reason the index queries do
     * it: for platform staff the AccountScope is deliberately open, and a
     * select silently listing another tenant's clients is the worst kind of
     * leak — it looks like it works.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function customers(string $accountId): array
    {
        return Customer::acrossAllAccounts()
            ->where('account_id', $accountId)
            ->orderBy('name')
            ->get()
            ->map(static fn (Customer $customer): array => [
                'value' => $customer->id,
                'label' => $customer->displayName(),
            ])
            ->all();
    }

    /**
     * Ordered by `position`, which is curated rather than alphabetical: the
     * areas a firm actually uses come first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function practiceAreas(): array
    {
        return PracticeArea::query()
            ->orderBy('position')
            ->get()
            ->map(static fn (PracticeArea $area): array => [
                // The slug, never the uuid: it is generated on load and
                // differs between databases.
                'value' => $area->slug,
                'label' => $area->label,
            ])
            ->all();
    }

    /**
     * Ordered by `position`, which is reach: the systems most courts use come
     * first.
     *
     * The uuid travels as the value, unlike the area's slug: a system only
     * ever goes from the select to the form's payload and back, never into a
     * URL or a fixture. `courts` is the hint under the select — which state
     * courts file through it — composed here so the Portuguese stays on this
     * side.
     *
     * @return list<array{value: string, label: string, courts: string}>
     */
    public static function judicialSystems(): array
    {
        return JudicialSystem::query()
            ->with('courts')
            ->orderBy('position')
            ->get()
            ->map(static fn (JudicialSystem $system): array => [
                'value' => $system->id,
                'label' => $system->name,
                'courts' => $system->servedCourts(),
            ])
            ->all();
    }

    /**
     * Draft or finished, for the listing's situation filter.
     *
     * Published from here rather than written into the React file so the
     * Portuguese stays on this side, as it does for every other label.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function statuses(): array
    {
        return [
            ['value' => 'draft', 'label' => 'Rascunho'],
            ['value' => 'final', 'label' => 'Finalizada'],
        ];
    }
}
