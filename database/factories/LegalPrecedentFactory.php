<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalPrecedent>
 */
class LegalPrecedentFactory extends Factory
{
    protected $model = LegalPrecedent::class;

    /**
     * Real rulings, verbatim, for the reason LegalThesisFactory gives: the
     * ABNT citation has a shape — court, número, órgão julgador, relator, data
     * de julgamento, DJe — and a faked one would not exercise the column that
     * the drafted pleading actually prints.
     *
     * @var list<array{name: string, type: string, description: string, citation: string, grounding: string}>
     */
    private const array PRECEDENTS = [
        [
            'name' => 'STJ — Súmula nº 393/STJ',
            'type' => 'sumula',
            'description' => 'SÚMULA 393/STJ: A exceção de pré-executividade é admissível na execução fiscal relativamente às matérias conhecíveis de ofício que não demandem dilação probatória.',
            'citation' => 'BRASIL. Superior Tribunal de Justiça. Súmula nº 393. Primeira Seção. Rel. Min. Luiz Fux, julgado em 23/09/2009, DJe 07/10/2009.',
            'grounding' => 'Fundamenta o cabimento da Exceção de Pré-Executividade sem a exigência de garantia do juízo (penhora/depósito), por se tratar de matéria de ordem pública cognoscível de ofício amparada em prova pré-constituída.',
        ],
        [
            'name' => 'STJ — Súmula nº 430/STJ',
            'type' => 'sumula',
            'description' => 'SÚMULA 430/STJ: O inadimplemento da obrigação tributária pela sociedade não gera, por si só, a responsabilidade solidária do sócio-administrador.',
            'citation' => 'BRASIL. Superior Tribunal de Justiça. Súmula nº 430. Primeira Seção. Rel. Min. Luiz Fux, julgado em 25/11/2009, DJe 15/12/2009.',
            'grounding' => 'Fundamenta a ilegitimidade passiva do sócio-administrador e a nulidade do redirecionamento da execução fiscal, pois o mero inadimplemento tributário ou a ausência de ato doloso/fraude não autoriza a responsabilidade pessoal dos gestores (art. 135 do CTN).',
        ],
        [
            'name' => 'STJ — Súmula nº 435/STJ',
            'type' => 'sumula',
            'description' => 'SÚMULA 435/STJ: Presume-se dissolvida irregularmente a empresa que deixar de funcionar no seu domicílio fiscal, sem comunicação aos órgãos competentes, legitimando o redirecionamento da execução fiscal para o sócio-gerente.',
            'citation' => 'BRASIL. Superior Tribunal de Justiça. Súmula nº 435. Primeira Seção. Rel. Min. Luiz Fux, julgado em 14/04/2010, DJe 13/05/2010.',
            'grounding' => 'Delimita a única hipótese em que o redirecionamento se admite, e serve à defesa quando a empresa permanece em atividade no domicílio fiscal declarado.',
        ],
        [
            'name' => 'STJ — Tema Repetitivo nº 981',
            'type' => 'repetitive_appeal',
            'description' => 'O redirecionamento da execução fiscal ao sócio que não constava da CDA e que dela se retirou antes da dissolução irregular é indevido, ainda que exercesse a gerência à época do fato gerador.',
            'citation' => 'BRASIL. Superior Tribunal de Justiça. REsp 1.377.019/SP. Primeira Seção. Rel. Min. Assusete Magalhães, julgado em 24/11/2021, DJe 19/12/2021.',
            'grounding' => 'Afasta o redirecionamento ao administrador que já havia deixado a sociedade quando da alegada dissolução irregular.',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $precedent = fake()->randomElement(self::PRECEDENTS);

        return [
            'account_id' => Account::factory(),
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            // Solto por padrão: um julgado encontrado antes da tese que vai
            // sustentar é a linha comum, e prender a factory a uma tese a
            // obrigaria a criar uma em toda chamada.
            'legal_thesis_id' => null,
            'name' => $precedent['name'],
            'type' => LegalPrecedentType::from($precedent['type']),
            'description' => $precedent['description'],
            'citation' => $precedent['citation'],
            'grounding' => $precedent['grounding'],
            'adherence' => fake()->optional(0.8)->randomFloat(2, 60, 100),
        ];
    }

    public function forLegalCase(LegalCase $legalCase): static
    {
        return $this->state(fn (): array => [
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    /**
     * Grounding a thesis — and living in the same pleading and account as it,
     * because the three columns are one fact and setting them apart would build
     * a row no use case could have written.
     */
    public function forThesis(LegalThesis $thesis): static
    {
        return $this->state(fn (): array => [
            'account_id' => $thesis->account_id,
            'legal_case_id' => $thesis->legal_case_id,
            'legal_thesis_id' => $thesis->id,
        ]);
    }

    /**
     * A ruling nobody scored — the null that means unmeasured.
     */
    public function unscored(): static
    {
        return $this->state(fn (): array => ['adherence' => null]);
    }

    public function adhering(float $percent): static
    {
        return $this->state(fn (): array => ['adherence' => $percent]);
    }

    public function nth(int $index): static
    {
        $precedent = self::PRECEDENTS[$index % count(self::PRECEDENTS)];

        return $this->state(fn (): array => [
            'name' => $precedent['name'],
            'type' => LegalPrecedentType::from($precedent['type']),
            'description' => $precedent['description'],
            'citation' => $precedent['citation'],
            'grounding' => $precedent['grounding'],
        ]);
    }
}
