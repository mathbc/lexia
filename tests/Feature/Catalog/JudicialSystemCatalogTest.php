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
 * The numbers are those of the map of 29/09/2026, and the addresses those of
 * 30/09/2026. Editing the map is expected to change this test with it.
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
     * The address is the court's own instance, so its host names the court:
     * `eproc1g.tjsc.jus.br`, `pjepg.tjro.jus.br`. Anything else would be a
     * mirror, a login redirect copied by mistake, or another court's system.
     */
    #[Test]
    public function every_address_is_the_courts_own(): void
    {
        $courts = JudicialSystemCourt::query()->whereNotNull('url')->get();

        $this->assertCount(27, $courts);

        foreach ($courts as $court) {
            $url = (string) $court->url;

            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME), $url);
            $this->assertStringEndsWith(
                mb_strtolower($court->court).'.jus.br',
                (string) parse_url($url, PHP_URL_HOST),
                $url,
            );
        }

        $this->assertSame('https://eproc1g.tjsc.jus.br/eproc/', $this->adoption(BrazilianState::SC, 'eproc')->url);
        $this->assertSame('https://eproc1.tjto.jus.br/eprocV2_prod_1grau/', $this->adoption(BrazilianState::TO, 'eproc')->url);
    }

    /**
     * Null is a link that takes no new petição inicial — the system switched
     * off, legacy, or not yet published —, not an address nobody looked up.
     * The README says what each court says.
     */
    #[Test]
    public function a_link_that_takes_no_new_filings_has_no_address(): void
    {
        $this->assertNull($this->adoption(BrazilianState::AM, 'esaj')->url);
        $this->assertNull($this->adoption(BrazilianState::RN, 'esaj')->url);
        $this->assertNull($this->adoption(BrazilianState::RR, 'pje')->url);
        $this->assertNull($this->adoption(BrazilianState::ES, 'eproc')->url);
    }

    #[Test]
    public function the_access_links_leave_out_a_court_with_no_address(): void
    {
        $links = collect($this->system('eproc')->accessLinks())->keyBy('state');

        // Catorze tribunais no mapa, treze com instância: o TJES ainda não tem.
        $this->assertCount(13, $links);
        $this->assertFalse($links->has('ES'));

        $this->assertSame([
            'court' => 'TJSC',
            'state' => 'SC',
            'url' => 'https://eproc1g.tjsc.jus.br/eproc/',
            'note' => null,
        ], $links['SC']);

        // Só o que não é "em uso" se diz, como na dica do select.
        $this->assertSame('em transição', $links['BA']['note']);
    }

    /**
     * Rodar a carga de novo é o protocolo de atualização do mapa — ver
     * database/data/README.md —, então ela não pode duplicar linha nem trocar
     * o id de um sistema que uma peça já cita.
     *
     * É a carga vigente que se roda, a que grava os endereços: a primeira os
     * apagaria, e é por isso que a próxima atualização copia esta.
     */
    #[Test]
    public function running_the_load_again_changes_nothing(): void
    {
        $ids = JudicialSystem::query()->pluck('id', 'slug')->all();

        // A classe anônima declara `up()`; a base `Migration` não, então a
        // instância é usada onde é carregada, como em FoldFactsStepMigrationTest.
        $migration = require database_path('migrations/2026_09_30_130001_reload_judicial_systems_with_urls.php');
        $migration->up();

        $this->assertSame(5, JudicialSystem::query()->count());
        $this->assertSame(31, JudicialSystemCourt::query()->count());
        $this->assertSame(27, JudicialSystemCourt::query()->whereNotNull('url')->count());
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
