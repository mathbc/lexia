<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\JudicialSystems\Enums\AdoptionStatus;
use App\Domain\JudicialSystems\Models\JudicialSystem;
use App\Domain\JudicialSystems\Models\JudicialSystemCourt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The judicial systems map arrives by migration, so these are assertions about
 * the migration itself — a truncated load would otherwise only surface as a
 * select missing an option.
 *
 * The numbers are those of the map of 29/09/2026. Editing the map is expected
 * to change this test with it.
 */
final class JudicialSystemCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_migration_loads_the_whole_map(): void
    {
        $this->assertSame(5, JudicialSystem::query()->count());
        $this->assertSame(31, JudicialSystemCourt::query()->count());

        $this->assertSame(
            ['eproc', 'PJe', 'e-SAJ', 'Projudi', 'Tucujuris'],
            JudicialSystem::query()->orderBy('position')->pluck('name')->all(),
        );
    }

    #[Test]
    public function every_state_court_is_mapped(): void
    {
        $mapped = JudicialSystemCourt::query()
            ->get()
            ->map(static fn (JudicialSystemCourt $court): string => $court->state->value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $states = array_map(
            static fn (BrazilianState $state): string => $state->value,
            BrazilianState::cases(),
        );
        sort($states);

        $this->assertSame($states, $mapped);
        $this->assertCount(27, $mapped);
    }

    #[Test]
    public function one_system_serves_many_courts(): void
    {
        $this->assertSame(14, $this->system('eproc')->courts()->count());
        $this->assertSame(11, $this->system('pje')->courts()->count());
        $this->assertSame(3, $this->system('esaj')->courts()->count());
        $this->assertSame(2, $this->system('projudi')->courts()->count());
        $this->assertSame(1, $this->system('tucujuris')->courts()->count());
    }

    #[Test]
    public function a_court_may_run_two_systems(): void
    {
        // Na ordem do select, que é a do alcance.
        $this->assertSame(['eproc', 'e-SAJ'], $this->systemsOf(BrazilianState::SP));
        $this->assertSame(['PJe', 'e-SAJ'], $this->systemsOf(BrazilianState::RN));
        $this->assertSame(['PJe', 'Projudi'], $this->systemsOf(BrazilianState::RR));
        $this->assertSame(['eproc', 'Tucujuris'], $this->systemsOf(BrazilianState::AP));

        // O Amapá usa o próprio sistema e está implantando o eproc.
        $this->assertSame(AdoptionStatus::Active, $this->adoption(BrazilianState::AP, 'tucujuris')->status);
        $this->assertSame(AdoptionStatus::Implementation, $this->adoption(BrazilianState::AP, 'eproc')->status);
    }

    #[Test]
    public function the_federal_district_court_is_the_tjdft(): void
    {
        $court = JudicialSystemCourt::query()->where('state', 'DF')->sole();

        $this->assertSame('TJDFT', $court->court);
        $this->assertSame('pje', $court->judicialSystem->slug);
    }

    #[Test]
    public function the_status_of_each_adoption_is_kept(): void
    {
        $this->assertSame(AdoptionStatus::Transition, $this->adoption(BrazilianState::BA, 'eproc')->status);
        $this->assertSame(AdoptionStatus::Transition, $this->adoption(BrazilianState::MG, 'eproc')->status);
        $this->assertSame(AdoptionStatus::Implementation, $this->adoption(BrazilianState::PR, 'eproc')->status);
        $this->assertSame(AdoptionStatus::Coexistence, $this->adoption(BrazilianState::RJ, 'eproc')->status);
        $this->assertSame(AdoptionStatus::Active, $this->adoption(BrazilianState::RS, 'eproc')->status);
    }

    #[Test]
    public function the_hint_names_the_courts_and_only_the_status_worth_saying(): void
    {
        $this->assertSame('TJAM, TJRN, TJSP', $this->system('esaj')->servedCourts());

        $this->assertSame(
            'TJAC, TJAL, TJAP (em implantação), TJBA (em transição), TJES (em transição), '
            .'TJMG (em transição), TJMS (em transição), TJPR (em implantação), '
            .'TJRJ (em coexistência com sistemas anteriores), TJRS, TJSC, TJSE (em transição), TJSP, TJTO',
            $this->system('eproc')->servedCourts(),
        );
    }

    /**
     * Rodar a carga de novo é o protocolo de atualização do mapa — ver
     * database/data/README.md —, então ela não pode duplicar linha nem trocar
     * o id de um sistema que uma peça já cita.
     */
    #[Test]
    public function running_the_load_again_changes_nothing(): void
    {
        $ids = JudicialSystem::query()->pluck('id', 'slug')->all();

        // A classe anônima declara `up()`; a base `Migration` não, então a
        // instância é usada onde é carregada, como em FoldFactsStepMigrationTest.
        $migration = require database_path('migrations/2026_09_29_120001_seed_judicial_systems.php');
        $migration->up();

        $this->assertSame(5, JudicialSystem::query()->count());
        $this->assertSame(31, JudicialSystemCourt::query()->count());
        $this->assertEquals($ids, JudicialSystem::query()->pluck('id', 'slug')->all());
    }

    private function system(string $slug): JudicialSystem
    {
        return JudicialSystem::query()->where('slug', $slug)->sole();
    }

    /**
     * @return list<string>
     */
    private function systemsOf(BrazilianState $state): array
    {
        return JudicialSystem::query()
            ->whereHas('courts', fn ($query) => $query->where('state', $state->value))
            ->orderBy('position')
            ->pluck('name')
            ->all();
    }

    private function adoption(BrazilianState $state, string $slug): JudicialSystemCourt
    {
        return JudicialSystemCourt::query()
            ->where('state', $state->value)
            ->whereRelation('judicialSystem', 'slug', $slug)
            ->sole();
    }
}
