<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Faker's pt_BR locale has no usable provider for either of these:
     * secondaryAddress() returns a bare letter and citySuffix() returns
     * fragments like "do Sul", which is a state suffix, not a bairro.
     *
     * @var list<string>
     */
    private const array COMPLEMENTS = ['Apto 42', 'Sala 12', 'Conj. 501', 'Bloco B', '3º andar'];

    /** @var list<string> */
    private const array DISTRICTS = [
        'Centro', 'Bela Vista', 'Jardins', 'Moema', 'Pinheiros',
        'Savassi', 'Boa Viagem', 'Batel', 'Meireles', 'Asa Sul',
    ];

    /**
     * Defaults to an individual practitioner; use ->lawFirm() for the other.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'legal_name' => null,
            'type' => AccountType::Individual,
            'federal_id' => null,
            'oab_number' => (string) fake()->unique()->numberBetween(1000, 999999),
            'oab_state' => fake()->randomElement(BrazilianState::cases()),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('###########'),
            'postal_code' => fake()->numerify('########'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'complement' => fake()->optional()->randomElement(self::COMPLEMENTS),
            'district' => fake()->randomElement(self::DISTRICTS),
            'city' => fake()->city(),
            'state' => fake()->randomElement(BrazilianState::cases()),
            'active' => true,
            'enabled' => true,
        ];
    }

    public function lawFirm(): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' Sociedade de Advogados Ltda.',
            'type' => AccountType::LawFirm,
            'federal_id' => self::fakeCnpj(),
            'oab_number' => null,
            'oab_state' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    /**
     * A CNPJ with correct check digits, so seeded data survives the Cnpj rule.
     */
    private static function fakeCnpj(): string
    {
        $base = array_map(static fn (): int => random_int(0, 9), range(1, 8));
        $digits = [...$base, 0, 0, 0, 1];

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;

            foreach ($weights as $index => $weight) {
                $sum += $digits[$index] * $weight;
            }

            $remainder = $sum % 11;
            $digits[] = $remainder < 2 ? 0 : 11 - $remainder;
        }

        return implode('', $digits);
    }
}
