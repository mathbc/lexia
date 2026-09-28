<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that retired the `facts` step, run against rows that still
 * carry it.
 *
 * `RefreshDatabase` runs it on an empty table, where it proves nothing: the
 * rows it exists for are the ones a production database already has. So they
 * are written here the only way they still can be — through the query builder,
 * since the enum cast no longer accepts the value — and the migration is run
 * again over them.
 */
final class FoldFactsStepMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_pleading_that_stood_on_the_facts_moves_on_to_the_requests(): void
    {
        [$account] = $this->accountWithOwner();

        $stranded = LegalCase::factory()->forAccount($account)->create();
        $deleted = LegalCase::factory()->forAccount($account)->create();
        $deleted->delete();
        $untouched = LegalCase::factory()->forAccount($account)->draft(LegalCaseStep::Review)->create();

        DB::table('legal_cases')
            ->whereIn('id', [$stranded->id, $deleted->id])
            ->update(['current_step' => 'facts']);

        $this->runMigration();

        $this->assertSame(LegalCaseStep::Requirements, $stranded->refresh()->current_step);
        // O soft delete não protege a linha: restaurá-la esbarraria no mesmo cast.
        $this->assertSame(
            LegalCaseStep::Requirements,
            LegalCase::withTrashed()->findOrFail($deleted->id)->current_step,
        );
        $this->assertSame(LegalCaseStep::Review, $untouched->refresh()->current_step);
    }

    /**
     * The anonymous class the file returns declares `up()`; the `Migration`
     * base it extends does not, so the instance is used where it is loaded.
     */
    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_26_150000_fold_facts_step_into_basics.php');

        $migration->up();
    }
}
