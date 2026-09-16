<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Documents\Models\Document;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @var list<string> */
    private const array TITLES = [
        'contrato-de-locacao',
        'procuracao-ad-judicia',
        'comprovante-de-residencia',
        'laudo-pericial',
        'notificacao-extrajudicial',
        'extrato-bancario',
        'boletim-de-ocorrencia',
        'contrato-social',
    ];

    /** @var list<string> */
    private const array EXTENSIONS = ['pdf', 'png', 'jpg', 'docx'];

    /**
     * The pleading is created inside the document's own account rather than
     * through a bare LegalCase::factory(), which would put it in a second one
     * and produce a row no use case could ever have written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = fake()->randomElement(self::EXTENSIONS);

        return [
            'account_id' => Account::factory(),
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            'name' => fake()->randomElement(self::TITLES).'.'.$extension,
            'description' => fake()->optional()->sentence(),
            'extension' => $extension,
            'size' => fake()->numberBetween(20_000, 8_000_000),
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
}
