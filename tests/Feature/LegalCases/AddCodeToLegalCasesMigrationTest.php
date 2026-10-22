<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that gave every pleading a code, run against rows that predate
 * it.
 *
 * `RefreshDatabase` runs it on an empty table, where the backfill does nothing.
 * So the rows are written first, the column is taken away by `down()`, and
 * `up()` has to give it back to each of them.
 */
final class AddCodeToLegalCasesMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_existing_pleading_is_given_a_distinct_code(): void
    {
        [$account] = $this->accountWithOwner();

        $cases = LegalCase::factory()->forAccount($account)->count(3)->create();
        $cases->firstOrFail()->delete();

        $migration = require database_path('migrations/2026_10_22_130000_add_code_to_legal_cases_table.php');
        $migration->down();
        $migration->up();

        // O soft delete não dispensa a linha: restaurá-la sem código violaria
        // o NOT NULL.
        $codes = DB::table('legal_cases')->pluck('code');

        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->unique());

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{6}$/', $code);
        }
    }
}
