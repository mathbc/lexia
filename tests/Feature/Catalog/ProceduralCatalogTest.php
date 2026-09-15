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
        $this->assertSame(1237, DB::table('practice_area_procedural_class')->count());
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
        // 59 classes, the order is what makes the select usable.
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
    public function the_hierarchy_of_a_class_is_kept_whole(): void
    {
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $path = $class->path;

        $this->assertSame('PROCESSO CÍVEL E DO TRABALHO', $path[0]);
        $this->assertSame($class->name, $path[count($path) - 1]);
        $this->assertSame(2, $class->root_code);
    }
}
