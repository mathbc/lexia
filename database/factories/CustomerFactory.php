<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /** @var list<string> */
    private const array COMPLEMENTS = ['Apto 42', 'Sala 12', 'Conj. 501', 'Bloco B', '3º andar'];

    /** @var list<string> */
    private const array DISTRICTS = [
        'Centro', 'Bela Vista', 'Jardins', 'Moema', 'Pinheiros',
        'Savassi', 'Boa Viagem', 'Batel', 'Meireles', 'Asa Sul',
    ];

    /**
     * Defaults to a natural person; use ->company() for the other.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'legal_name' => null,
            'type' => CustomerType::Individual,
            'cpf' => BrazilianDocuments::cpf(),
            'cnpj' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('###########'),
            'postal_code' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'complement' => fake()->optional()->randomElement(self::COMPLEMENTS),
            'district' => fake()->randomElement(self::DISTRICTS),
            'city' => fake()->city(),
            'state' => fake()->randomElement(BrazilianState::cases()),
        ];
    }

    public function company(): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->company(),
            // finish() and not concatenation: under the pt_BR locale faker
            // already hands back a name ending in "Ltda.", and appending a
            // second one reads like a bug in the seed data.
            'legal_name' => (string) str(fake()->company())->finish(' Ltda.'),
            'type' => CustomerType::Company,
            'cpf' => null,
            'cnpj' => BrazilianDocuments::cnpj(),
        ]);
    }

    /**
     * Attach to an existing account instead of creating a fresh one.
     */
    public function forAccount(Account $account): static
    {
        return $this->state(fn (): array => ['account_id' => $account->id]);
    }
}
