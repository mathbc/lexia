<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The documents that instruct a pleading, at the stage they exist today: a row
 * describing a file nobody has stored yet. There are no routes and no upload,
 * so these exercise the model and the schema directly.
 */
final class ManageDocumentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_document_is_stamped_with_the_actors_account(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();

        $document = Document::query()->create([
            'legal_case_id' => $case->id,
            'name' => 'procuracao-ad-judicia.pdf',
            'description' => 'Procuração assinada pelo cliente.',
            'extension' => 'pdf',
            'size' => 182_400,
        ]);

        // Never taken from the request: BelongsToAccount fills it in.
        $this->assertSame($account->id, $document->account_id);
        $this->assertSame($case->id, $document->legalCase->id);
        $this->assertSame(182_400, $document->size);
    }

    #[Test]
    public function a_pleading_reads_back_only_its_own_documents(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        $other = LegalCase::factory()->forAccount($account)->create();

        Document::factory()->forLegalCase($case)->count(3)->create();
        Document::factory()->forLegalCase($other)->count(2)->create();

        $this->assertCount(3, $case->documents);
        $this->assertCount(2, $other->documents);
    }

    #[Test]
    public function a_document_is_only_visible_inside_its_own_tenant(): void
    {
        [$mine, $owner] = $this->accountWithOwner();
        Document::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($mine)->create())
            ->count(2)
            ->create();

        [$theirs] = $this->accountWithOwner();
        Document::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($theirs)->create())
            ->count(3)
            ->create();

        app(TenantContext::class)->clear();
        $this->actingAsUser($owner);

        $this->assertSame(2, Document::query()->count());
        $this->assertSame(5, Document::acrossAllAccounts()->count());
    }

    #[Test]
    public function erasing_a_pleading_erases_the_documents_it_instructed(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $this->actingAsUser($owner);

        $case = LegalCase::factory()->forAccount($account)->create();
        Document::factory()->forLegalCase($case)->count(2)->create();

        // Straight to the table, past SoftDeletes: what is under test is the
        // cascade the foreign key declares, and a soft delete never reaches it.
        DB::table('legal_cases')->where('id', $case->id)->delete();

        $this->assertSame(0, DB::table('documents')->count());
    }
}
