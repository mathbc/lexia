<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\LegalThemes\Enums\JudgingBody;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalTheme>
 */
class LegalThemeFactory extends Factory
{
    protected $model = LegalTheme::class;

    /**
     * Real Temas Repetitivos, read off the STJ file, for the reason the other
     * catalogue-shaped factories give: a seeded theme that reads like the
     * product exercises the columns the way the catalogue fills them — a
     * question in the STJ's own register, a thesis that runs to several
     * clauses, and one theme still `Afetado`, with no thesis at all.
     *
     * The catalogue itself is loaded by `lexia:import-legal-themes`, never by
     * a seeder or a migration; this is for the tests that need a theme and do
     * not care which import produced it.
     *
     * @var list<array{number: int, status: string, judging_body: string, question: string, settled_thesis: string|null}>
     */
    private const array THEMES = [
        [
            'number' => 952,
            'status' => 'Trânsito em Julgado',
            'judging_body' => 'second_section',
            'question' => 'Discute-se a validade da cláusula contratual de plano de saúde que prevê o aumento da mensalidade conforme a mudança de faixa etária do usuário.',
            'settled_thesis' => 'O reajuste de mensalidade de plano de saúde individual ou familiar fundado na mudança de faixa etária do beneficiário é válido desde que (i) haja previsão contratual, (ii) sejam observadas as normas expedidas pelos órgãos governamentais reguladores e (iii) não sejam aplicados percentuais desarrazoados ou aleatórios que, concretamente e sem base atuarial idônea, onerem excessivamente o consumidor ou discriminem o idoso.',
        ],
        [
            'number' => 1076,
            'status' => 'Acórdão Publicado - RE Pendente',
            'judging_body' => 'special_court',
            'question' => 'Definição do alcance da norma inserta no § 8º do artigo 85 do Código de Processo Civil nas causas em que o valor da causa ou o proveito econômico da demanda forem elevados.',
            'settled_thesis' => 'A fixação dos honorários por apreciação equitativa não é permitida quando os valores da condenação, da causa ou o proveito econômico da demanda forem elevados.',
        ],
        [
            'number' => 1200,
            'status' => 'Acórdão Publicado - RE Pendente',
            'judging_body' => 'second_section',
            'question' => 'Definir o termo inicial do prazo prescricional da petição de herança proposta por filho cujo reconhecimento da paternidade tenha ocorrido após a morte.',
            'settled_thesis' => 'O prazo prescricional para propor ação de petição de herança conta-se da abertura da sucessão, cuja fluência não é impedida, suspensa ou interrompida pelo ajuizamento de ação de reconhecimento de filiação, independentemente do seu trânsito em julgado.',
        ],
        [
            'number' => 1474,
            'status' => 'Afetado',
            'judging_body' => 'second_section',
            'question' => 'Definir se, nos contratos bancários, para a cobrança de juros com capitalização diária, basta a previsão contratual da periodicidade diária ou se é indispensável a informação expressa da taxa diária de juros aplicada.',
            'settled_thesis' => null,
        ],
    ];

    private static int $next = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = self::$next++;
        $theme = self::THEMES[$index % count(self::THEMES)];

        return [
            'sequential_number' => 900000 + $index,
            'type' => LegalThemeType::Theme,
            // O número se repete a cada volta pela lista, e (type, number) é
            // único: cada volta seguinte soma dez mil.
            'number' => $theme['number'] + 10000 * intdiv($index, count(self::THEMES)),
            'status' => $theme['status'],
            'judging_body' => JudgingBody::from($theme['judging_body']),
            'question' => $theme['question'],
            'settled_thesis' => $theme['settled_thesis'],
            'subjects' => [],
        ];
    }

    /**
     * Cancelled, which the retrieval never offers.
     */
    public function cancelled(): static
    {
        return $this->state(['status' => 'Cancelado']);
    }

    /**
     * With a vector, so the retrieval can reach it.
     *
     * @param  list<float>  $vector
     */
    public function embedded(array $vector): static
    {
        return $this->state([
            'embedding' => $vector,
            'embedding_hash' => hash('sha256', json_encode($vector, JSON_THROW_ON_ERROR)),
            'embedded_at' => now(),
        ]);
    }
}
