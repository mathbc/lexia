<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requirement>
 */
class RequirementFactory extends Factory
{
    protected $model = Requirement::class;

    /**
     * The requests that close almost every pleading, in the order they are
     * usually written. Verbatim rather than generated: a `fake()->sentence()`
     * here would read as noise on a screen whose whole job is showing what a
     * well-written request looks like.
     *
     * @var list<string>
     */
    private const array DESCRIPTIONS = [
        'A concessão da Gratuidade da Justiça;',
        'A citação do Réu para, querendo, contestar a ação no prazo legal, sob pena de revelia;',
        'A produção de todas as provas em direito admitidas, em especial a documental, a testemunhal e a pericial;',
        'A inversão do ônus da prova, nos termos do art. 6º, VIII, do CDC;',
        'A procedência total dos pedidos;',
        'A condenação do Réu ao pagamento de custas e honorários advocatícios;',
    ];

    /**
     * The pleading is created inside the request's own account rather than
     * through a bare LegalCase::factory(), which would put it in a second one
     * and produce a row no use case could ever have written.
     *
     * Most requests claim no money — a fee waiver and a summons are worth
     * nothing in reais — so `amount` is absent far more often than present.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            'description' => fake()->randomElement(self::DESCRIPTIONS),
            'amount' => fake()->optional(0.3)->randomFloat(2, 1_000, 200_000),
        ];
    }

    /**
     * Attach to a pleading that already exists; the account comes with it.
     */
    public function forLegalCase(LegalCase $legalCase): static
    {
        return $this->state(fn (): array => [
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    /**
     * A request that claims money, for the cases that are about the figure.
     */
    public function claiming(float $amount): static
    {
        return $this->state(fn (): array => [
            'amount' => $amount,
        ]);
    }
}
