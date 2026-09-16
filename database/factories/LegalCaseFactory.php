<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalCase>
 */
class LegalCaseFactory extends Factory
{
    protected $model = LegalCase::class;

    /**
     * The catalogue is not seeded here — it arrives by migration — so the area
     * and the class are drawn from the rows already in the database.
     *
     * The client is created inside the pleading's own account rather than
     * through a bare Customer::factory(), which would put it in a second one
     * and produce a row no use case could ever have written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $area = $this->randomArea();

        return [
            'account_id' => Account::factory(),
            'customer_id' => fn (array $attributes): string => Customer::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            'practice_area_id' => $area->id,
            'procedural_class_id' => $this->filingClassOf($area),
        ];
    }

    /**
     * Attach to an existing account, client included.
     */
    public function forAccount(Account $account): static
    {
        return $this->state(fn (): array => [
            'account_id' => $account->id,
            'customer_id' => Customer::factory()->forAccount($account),
        ]);
    }

    /**
     * Draft for a client that already exists; the account comes with them.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'account_id' => $customer->account_id,
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Pin the area, and draw a class that actually belongs to it.
     */
    public function inArea(PracticeArea $area): static
    {
        return $this->state(fn (): array => [
            'practice_area_id' => $area->id,
            'procedural_class_id' => $this->filingClassOf($area),
        ]);
    }

    /**
     * A draft stopped at a given step — the state every pleading is in today.
     */
    public function draft(LegalCaseStep $step = LegalCaseStep::Defendant): static
    {
        return $this->state(fn (): array => [
            'current_step' => $step,
            'is_draft' => true,
        ]);
    }

    /**
     * A pleading someone called finished.
     *
     * No Action produces one yet — the flag exists before the flow that flips
     * it — so this state is what lets a test exercise the other half of the
     * listing.
     */
    public function finalised(): static
    {
        return $this->state(fn (): array => [
            'current_step' => LegalCaseStep::Review,
            'is_draft' => false,
        ]);
    }

    /**
     * Only areas that can open a case: `processual-geral` carries nothing but
     * cross-cutting classes, and drawing it would leave nothing to pick.
     */
    private function randomArea(): PracticeArea
    {
        return PracticeArea::query()
            ->whereHas(
                'proceduralClasses',
                fn (Builder $query): Builder => $query->where('is_filing_class', true),
            )
            ->inRandomOrder()
            ->firstOrFail();
    }

    private function filingClassOf(PracticeArea $area): string
    {
        return $area->proceduralClasses()
            ->where('is_filing_class', true)
            ->inRandomOrder()
            ->firstOrFail()
            ->id;
    }
}
