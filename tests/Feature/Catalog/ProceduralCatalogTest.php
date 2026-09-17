<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CNJ catalogue arrives by migration, so these counts are assertions about
 * the migration itself — a truncated load would otherwise only surface as an
 * empty select in front of a user.
 *
 * The numbers come from the TPU version of 12/09/2026. A resync that changes
 * them is expected to change this test with it.
 */
final class ProceduralCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_migration_loads_the_whole_catalogue(): void
    {
        $this->assertSame(24, PracticeArea::query()->count());
        $this->assertSame(615, ProceduralClass::query()->count());
        $this->assertSame(1756, DB::table('practice_area_procedural_class')->count());
    }

    #[Test]
    public function one_class_serves_many_areas(): void
    {
        // "Procedimento Comum Cível" is filed in fifteen areas and is stored
        // once. This is the whole reason the link lives in a pivot: a
        // practice_area_id column here would mean fifteen copies of the row
        // and fifteen rows claiming CNJ code 7.
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $this->assertSame('Procedimento Comum Cível', $class->name);
        $this->assertSame(15, $class->practiceAreas()->count());
        $this->assertContains('imobiliario', $class->practiceAreas->pluck('slug')->all());

        $this->assertSame(1, ProceduralClass::query()->where('code', 7)->count());
    }

    #[Test]
    public function an_areas_own_classes_come_before_the_borrowed_ones(): void
    {
        $area = PracticeArea::query()->where('slug', 'imobiliario')->sole();

        $scopes = $area->proceduralClasses
            ->pluck('pivot.scope')
            ->unique()
            ->values()
            ->all();

        // Usucapião and Despejo before Procedimento Comum Cível: in a list of
        // 94 classes, the order is what makes the select usable.
        $this->assertSame(['specific', 'generic'], $scopes);
    }

    #[Test]
    public function filing_classes_are_told_apart_from_cross_cutting_ones(): void
    {
        $this->assertSame(326, ProceduralClass::query()->where('is_filing_class', true)->count());
        $this->assertSame(182, ProceduralClass::query()->where('is_cross_cutting', true)->count());

        // Usucapião opens a case; an appeal is filed inside one that exists.
        $this->assertTrue(ProceduralClass::query()->where('code', 49)->sole()->is_filing_class);
        $this->assertTrue(ProceduralClass::query()->where('code', 198)->sole()->is_cross_cutting);
        $this->assertFalse(ProceduralClass::query()->where('code', 198)->sole()->is_filing_class);
    }

    #[Test]
    public function a_class_carries_the_statute_it_rests_on(): void
    {
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $this->assertSame('CPC 2015, art. 318', $class->legalBasis());
        $this->assertTrue($class->appliesIn('just_es_1grau'));
        $this->assertFalse($class->appliesIn('just_trab_1grau'));

        // The CNJ leaves the statute blank on plenty of classes, and a missing
        // one has to read as absent rather than as a stray comma.
        $this->assertNull(
            ProceduralClass::query()->whereNull('legal_norm')->firstOrFail()->legalBasis(),
        );
    }

    #[Test]
    public function every_class_carries_the_editorial_reading_the_picker_shows(): void
    {
        // Nossa redação, não a do CNJ: uma ressincronização que reescrevesse
        // as 615 linhas a partir do web service apagaria isso em silêncio, e o
        // card do seletor voltaria a ser nome e código.
        $this->assertSame(0, ProceduralClass::query()->whereNull('description')->count());
        $this->assertSame(0, ProceduralClass::query()->whereRaw('jsonb_array_length(typical_subjects) = 0')->count());

        $class = ProceduralClass::query()->where('code', 94)->sole();

        $this->assertStringContainsString('locação', mb_strtolower((string) $class->description));

        // "Fiador" não aparece em nome nenhum do catálogo: é pela matéria que
        // a busca do seletor chega nesta classe.
        $this->assertContains('Responsabilidade do Fiador', $class->typical_subjects);
    }

    #[Test]
    public function every_filing_class_carries_the_articles_a_pleading_cites(): void
    {
        // `legal_bases` é editorial como `description`, e é o que o agente lê
        // para desempatar duas classes parecidas. Uma classe de ajuizamento sem
        // fundamentação chegaria ao prompt sem o dado que mais separa.
        $this->assertSame(
            0,
            ProceduralClass::query()
                ->where('is_filing_class', true)
                ->whereRaw('jsonb_array_length(coalesce(legal_bases, \'[]\'::jsonb)) = 0')
                ->count(),
        );

        $class = ProceduralClass::query()->where('code', 7)->sole();

        $this->assertSame([
            'CPC, art. 318 (cabimento do procedimento comum)',
            'CPC, art. 319 (requisitos da petição inicial)',
            'CPC, art. 300 (tutela de urgência)',
            'CC, art. 186 e 927 (ato ilícito e dever de indenizar)',
        ], $class->citedLegalBases());
    }

    #[Test]
    public function the_two_kinds_of_embargos_are_told_apart_by_their_deadline(): void
    {
        // O par que motivou o enriquecimento. As duas classes se chamam quase
        // igual e se separam pelo dispositivo: sem garantia e 15 dias da
        // citação no CPC, contra 30 dias depois de garantido o juízo na LEF.
        $cpc = ProceduralClass::query()->where('code', 172)->sole();
        $lef = ProceduralClass::query()->where('code', 1118)->sole();

        $this->assertStringContainsString('15 dias', (string) $cpc->description);
        $this->assertStringContainsString('independentemente de penhora', (string) $cpc->description);

        $this->assertStringContainsString('30 dias', (string) $lef->description);
        $this->assertStringContainsString('garantido o juízo', (string) $lef->description);

        $this->assertStringContainsString('CPC, art. 915', implode(' ', $cpc->citedLegalBases()));
        $this->assertStringContainsString('Lei 6.830/1980, art. 16', implode(' ', $lef->citedLegalBases()));
    }

    #[Test]
    public function a_class_without_curated_bases_falls_back_to_the_cnj_reference(): void
    {
        // A carga dá a toda linha com `legal_norm` ao menos a citação do CNJ,
        // então este caminho não aparece no catálogo de hoje — mas aparece numa
        // linha recém-inserida por uma ressincronização que ainda não passou
        // pelo enriquecimento, e aí o seletor não pode ficar sem fundamentação.
        $class = new ProceduralClass([
            'legal_norm' => 'CPC 2015',
            'legal_article' => '318',
            'legal_bases' => [],
        ]);

        $this->assertSame(['CPC 2015, art. 318'], $class->citedLegalBases());

        // Sem norma nenhuma, lista vazia — e não uma string solta com vírgula.
        $this->assertSame([], (new ProceduralClass)->citedLegalBases());
    }

    #[Test]
    public function the_civil_trunk_reaches_every_civil_area(): void
    {
        // The bug this locks down: the trunk of PROCESSO CÍVEL E DO TRABALHO —
        // cumprimento de sentença, embargos à execução, embargos de declaração —
        // had been linked to Direito do Trabalho alone, so a civil matter could
        // not be docketed under any of them. They belong to every civil area,
        // which is what `generic` means.
        $trunk = [
            156 => 'Cumprimento de sentença',
            172 => 'Embargos à Execução',
            1689 => 'Embargos de Declaração Cível',
            1208 => 'Agravo Interno Cível',
            261 => 'Carta Precatória Cível',
            12119 => 'Incidente de Desconsideração de Personalidade Jurídica',
        ];

        foreach ($trunk as $code => $name) {
            $class = ProceduralClass::query()->where('code', $code)->sole();
            $slugs = $class->practiceAreas->pluck('slug')->all();

            $this->assertSame($name, $class->name);

            foreach (['civil', 'familia-sucessoes', 'imobiliario', 'empresarial', 'tributario'] as $area) {
                $this->assertContains($area, $slugs, "{$name} não alcança {$area}.");
            }
        }
    }

    #[Test]
    public function a_class_bound_to_a_subject_stays_in_its_own_areas(): void
    {
        // The other half of the same rule: being in the civil trunk does not
        // make a class universal. Execução Fiscal is tax and public-finance
        // work, and the CNJ's own glossary puts the Maria da Penha protective
        // measure in família and penal — neither belongs in Direito Marítimo.
        $pinned = [
            1116 => ['administrativo', 'tributario'],
            1117 => ['imobiliario'],
            15309 => ['familia-sucessoes', 'penal'],
        ];

        foreach ($pinned as $code => $expected) {
            $slugs = ProceduralClass::query()->where('code', $code)->sole()
                ->practiceAreas->pluck('slug')->sort()->values()->all();

            sort($expected);

            $this->assertSame($expected, $slugs);
        }
    }

    #[Test]
    public function no_class_is_left_without_an_area(): void
    {
        // A class no area offers is a class no one can pick: it exists in the
        // catalogue and is unreachable from every screen that leads to it.
        $orphans = ProceduralClass::query()->doesntHave('practiceAreas')->pluck('name')->all();

        $this->assertSame([], $orphans);
    }

    #[Test]
    public function the_hierarchy_of_a_class_is_kept_whole(): void
    {
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $path = $class->path;

        $this->assertSame('PROCESSO CÍVEL E DO TRABALHO', $path[0]);
        $this->assertSame($class->name, $path[count($path) - 1]);
        $this->assertSame(2, $class->root_code);
    }
}
