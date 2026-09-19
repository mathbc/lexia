<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalThesis>
 */
class LegalThesisFactory extends Factory
{
    protected $model = LegalThesis::class;

    /**
     * Real theses, verbatim, rather than `fake()->sentence()`.
     *
     * The same choice RequirementFactory makes: a test that reads
     * "Da Ilegitimidade Passiva do Sócio-Administrador" shows what the column
     * holds, and a screenshot of seeded data looks like the product instead of
     * like lorem ipsum. Each entry carries the fundamentação that actually goes
     * with it, so `legal_bases` is never a plausible-looking mismatch.
     *
     * @var list<array{name: string, type: string, description: string, impact: string, legal_bases: list<array{type: string, reference: string, source: string|null}>}>
     */
    private const array THESES = [
        [
            'name' => 'Do Cabimento da Exceção de Pré-Executividade sem Garantia do Juízo',
            'type' => 'preliminary',
            'description' => 'Demonstração de que a matéria arguida é de ordem pública (inexigibilidade da obrigação e ilegitimidade passiva) e independe de dilação probatória, sendo cabível sem necessidade de prévia garantia do juízo.',
            'impact' => 'Garante o conhecimento imediato da defesa sem bloqueio de bens da empresa.',
            'legal_bases' => [
                ['type' => 'sumula', 'reference' => 'Súmula 393 do STJ', 'source' => 'STJ'],
                ['type' => 'article', 'reference' => 'Art. 803 do CPC', 'source' => 'CPC'],
                ['type' => 'statute', 'reference' => 'Lei nº 6.830/80', 'source' => null],
            ],
        ],
        [
            'name' => 'Da Ilegitimidade Passiva do Sócio-Administrador e Nulidade do Redirecionamento',
            'type' => 'preliminary',
            'description' => 'O simples inadimplemento da obrigação tributária não enseja o redirecionamento da execução fiscal ao sócio-gerente sem a prova inequívoca de infração à lei, aos estatutos ou dissolução irregular comprovada.',
            'impact' => 'Afasta de plano a responsabilidade patrimonial pessoal do administrador.',
            'legal_bases' => [
                ['type' => 'article', 'reference' => 'Art. 135, III, do CTN', 'source' => 'CTN'],
                ['type' => 'sumula', 'reference' => 'Súmula 430 do STJ', 'source' => 'STJ'],
                ['type' => 'sumula', 'reference' => 'Súmula 435 do STJ', 'source' => 'STJ'],
                ['type' => 'theme', 'reference' => 'Tema 981 do STJ', 'source' => 'STJ'],
            ],
        ],
        [
            'name' => 'Da Inexigibilidade de ICMS sobre Transferência Interestadual entre Estabelecimentos da Mesma Titularidade',
            'type' => 'principal_merits',
            'description' => 'Não constitui fato gerador do ICMS o mero deslocamento de mercadoria de um para outro estabelecimento do mesmo contribuinte, por ausência de circulação jurídica ou transferência de titularidade econômica.',
            'impact' => 'Tese com efeito vinculante e eficácia erga omnes do STF e pacificada pelo STJ.',
            'legal_bases' => [
                ['type' => 'sumula', 'reference' => 'Súmula 166 do STJ', 'source' => 'STJ'],
                ['type' => 'adc', 'reference' => 'ADC 49 do STF (Tema 1099)', 'source' => 'STF'],
                ['type' => 'article', 'reference' => 'Art. 155, II, da CF/88', 'source' => 'CF/88'],
            ],
        ],
        [
            'name' => 'Da Aplicação Subsidiária da Denúncia Espontânea',
            'type' => 'subsidiary_merits',
            'description' => 'Caso superada a inexigibilidade, o recolhimento do tributo antes de qualquer procedimento fiscalizatório afasta a multa moratória, remanescendo devido apenas o principal acrescido de juros.',
            'impact' => 'Reduz o valor executado ao excluir a multa, ainda que vencida a tese principal.',
            'legal_bases' => [
                ['type' => 'article', 'reference' => 'Art. 138 do CTN', 'source' => 'CTN'],
                ['type' => 'sumula', 'reference' => 'Súmula 360 do STJ', 'source' => 'STJ'],
            ],
        ],
        [
            'name' => 'Da Condenação da Exequente em Honorários Advocatícios',
            'type' => 'ancillary_request',
            'description' => 'Acolhida a exceção de pré-executividade com extinção parcial ou total da execução, é devida a condenação da Fazenda em honorários pelo princípio da causalidade.',
            'impact' => 'Recupera o custo da defesa quando a execução é extinta.',
            'legal_bases' => [
                ['type' => 'sumula', 'reference' => 'Súmula 153 do STJ', 'source' => 'STJ'],
                ['type' => 'article', 'reference' => 'Art. 85, § 3º, do CPC', 'source' => 'CPC'],
            ],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $thesis = fake()->randomElement(self::THESES);

        return [
            'account_id' => Account::factory(),
            // O fecho recebe os atributos já sorteados para que a peça nasça na
            // mesma conta da tese: um `LegalCase::factory()` solto a poria numa
            // segunda conta e produziria uma linha que nenhum caso de uso
            // poderia ter escrito.
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            'name' => $thesis['name'],
            'type' => LegalThesisType::from($thesis['type']),
            'description' => $thesis['description'],
            'impact' => $thesis['impact'],
            'legal_bases' => $thesis['legal_bases'],
        ];
    }

    public function forLegalCase(LegalCase $legalCase): static
    {
        return $this->state(fn (): array => [
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    public function ofType(LegalThesisType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * A thesis nobody has grounded yet — the column is null, not `[]`.
     */
    public function ungrounded(): static
    {
        return $this->state(fn (): array => ['legal_bases' => null]);
    }

    /**
     * @param  list<array{type: string, reference: string, source: string|null}>  $bases
     */
    public function citing(array $bases): static
    {
        return $this->state(fn (): array => ['legal_bases' => $bases]);
    }

    /**
     * The exact thesis at that position in the table above, for a test that
     * needs two rows it can tell apart by name.
     */
    public function nth(int $index): static
    {
        $thesis = self::THESES[$index % count(self::THESES)];

        return $this->state(fn (): array => [
            'name' => $thesis['name'],
            'type' => LegalThesisType::from($thesis['type']),
            'description' => $thesis['description'],
            'impact' => $thesis['impact'],
            'legal_bases' => $thesis['legal_bases'],
        ]);
    }

    /**
     * Every basis type the enum knows, for a test about reading them back.
     */
    public function citingEveryType(): static
    {
        return $this->citing(array_map(
            static fn (LegalBasisType $type): array => [
                'type' => $type->value,
                'reference' => $type->label().' de teste',
                'source' => 'STJ',
            ],
            LegalBasisType::cases(),
        ));
    }
}
