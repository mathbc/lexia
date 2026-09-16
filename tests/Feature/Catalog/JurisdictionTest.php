<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\ProceduralClasses\Enums\Jurisdiction;
use App\Domain\ProceduralClasses\Enums\JurisdictionDegree;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class JurisdictionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The enum is a curated map over data the CNJ publishes. If a resync adds
     * a competence, this is what says so out loud instead of letting the class
     * picker quietly drop a filter.
     */
    #[Test]
    public function the_enum_covers_every_competence_the_catalogue_uses(): void
    {
        $used = ProceduralClass::query()
            ->pluck('jurisdictions')
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $known = array_map(
            static fn (Jurisdiction $case): string => $case->value,
            Jurisdiction::cases(),
        );
        sort($known);

        $this->assertSame($known, $used);
        $this->assertCount(29, $known);
    }

    #[Test]
    public function every_competence_lands_in_exactly_one_branch_and_one_degree(): void
    {
        $branches = [];
        $degrees = [];

        foreach (Jurisdiction::cases() as $case) {
            $branches[$case->branch()->value][] = $case->value;
            $degrees[$case->degree()->value][] = $case->value;
        }

        // Every bucket is used, and the two partitions each account for all 29.
        $this->assertCount(count(JusticeBranch::cases()), $branches);
        $this->assertCount(count(JurisdictionDegree::cases()), $degrees);
        $this->assertCount(29, array_merge(...array_values($branches)));
        $this->assertCount(29, array_merge(...array_values($degrees)));
    }

    #[Test]
    public function the_two_state_military_competences_are_not_confused(): void
    {
        // A military court inside the ordinary state justice...
        $this->assertSame(JusticeBranch::Military, Jurisdiction::StateFirstMilitary->branch());
        $this->assertSame(JurisdictionDegree::First, Jurisdiction::StateFirstMilitary->degree());

        // ...and the state military justice proper. Different rows in the TPU.
        $this->assertSame('just_mil_est_1grau', Jurisdiction::MilitaryStateFirst->value);
        $this->assertNotSame(Jurisdiction::StateFirstMilitary, Jurisdiction::MilitaryStateFirst);
    }

    #[Test]
    public function a_competence_projects_the_tag_the_picker_filters_on(): void
    {
        $this->assertSame([
            'value' => 'just_trab_tst',
            'label' => 'Tribunal Superior do Trabalho',
            'short_label' => 'TST',
            'branch' => 'labor',
            'degree' => 'superior',
        ], Jurisdiction::LaborSuperior->toTag());
    }
}
