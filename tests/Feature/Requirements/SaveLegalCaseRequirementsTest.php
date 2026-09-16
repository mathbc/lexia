<?php

declare(strict_types=1);

namespace Tests\Feature\Requirements;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Requirements\Models\Requirement;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fourth step: the numbered list a pleading closes with, saved whole.
 *
 * The reconciliation is the part worth pinning down. The form mints its own
 * uuids for rows it has just opened, and they are v4 exactly like the server's
 * — so the saving Action may never treat a posted id as proof of anything. It
 * looks the id up among the rows the pleading actually owns; everything else
 * becomes a new row. These tests are what hold that line.
 */
final class SaveLegalCaseRequirementsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_requests_of_a_pleading_are_written_in_one_go(): void
    {
        [$account, $owner, $legalCase] = $this->pleading();

        $this->actingAs($owner)
            ->put("/pecas/{$legalCase->id}/pedidos", ['requirements' => [
                ['id' => null, 'description' => 'A concessão da Gratuidade da Justiça;', 'amount' => ''],
                ['id' => null, 'description' => 'A citação do Réu;', 'amount' => ''],
            ]])
            ->assertSessionHasNoErrors();

        $descriptions = $legalCase->requirements()->pluck('description')->all();

        // A ordem é a de inserção, que é a ordem em que foram escritos e a
        // ordem em que serão numerados.
        $this->assertSame([
            'A concessão da Gratuidade da Justiça;',
            'A citação do Réu;',
        ], $descriptions);
    }

    #[Test]
    public function an_amount_arrives_masked_and_is_stored_as_a_decimal(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'A condenação ao pagamento de danos morais;', 'amount' => '50.000,00'],
        ]);

        // Decimal e não float: é dinheiro que entra numa sentença.
        $this->assertSame('50000.00', $legalCase->requirements()->sole()->amount);
    }

    #[Test]
    public function a_request_with_no_amount_is_accepted(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'A citação do Réu;', 'amount' => ''],
        ]);

        // A maioria dos pedidos não vale nada em reais, e um zero seria lido
        // como pedido de zero.
        $this->assertNull($legalCase->requirements()->sole()->amount);
    }

    #[Test]
    public function a_request_with_no_text_is_not_saved(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'A citação do Réu;', 'amount' => ''],
            // A linha que o advogado abriu e não escreveu: não vira pedido, e
            // também não trava o salvamento.
            ['id' => null, 'description' => '   ', 'amount' => ''],
        ]);

        $this->assertSame(1, $legalCase->requirements()->count());
    }

    #[Test]
    public function an_existing_request_is_updated_rather_than_duplicated(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'A citação do Réu;', 'amount' => ''],
        ]);

        $saved = $legalCase->requirements()->sole();

        $this->save($owner, $legalCase, [
            ['id' => $saved->id, 'description' => 'A citação do Réu, sob pena de revelia;', 'amount' => ''],
        ]);

        $this->assertSame(1, $legalCase->requirements()->count());
        $this->assertSame(
            'A citação do Réu, sob pena de revelia;',
            $legalCase->requirements()->sole()->description,
        );
        $this->assertSame($saved->id, $legalCase->requirements()->sole()->id);
    }

    #[Test]
    public function a_request_the_lawyer_removed_is_deleted(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'Primeiro;', 'amount' => ''],
            ['id' => null, 'description' => 'Segundo;', 'amount' => ''],
        ]);

        $keep = $legalCase->requirements()->where('description', 'Primeiro;')->sole();

        $this->save($owner, $legalCase, [
            ['id' => $keep->id, 'description' => 'Primeiro;', 'amount' => ''],
        ]);

        $this->assertSame(['Primeiro;'], $legalCase->requirements()->pluck('description')->all());
    }

    #[Test]
    public function an_empty_list_clears_every_request(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'Primeiro;', 'amount' => ''],
        ]);

        $this->save($owner, $legalCase, []);

        $this->assertSame(0, $legalCase->requirements()->count());
    }

    /**
     * The one that matters: a client uuid is shaped exactly like a server one,
     * so an id that does not belong to this pleading must never be followed.
     */
    #[Test]
    public function an_id_from_another_account_creates_a_new_request_instead_of_touching_it(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        [$otherAccount] = $this->accountWithOwner();
        $foreign = Requirement::factory()
            ->forLegalCase(LegalCase::factory()->forAccount($otherAccount)->create())
            ->create(['description' => 'Pedido de outra conta;']);

        $this->save($owner, $legalCase, [
            ['id' => $foreign->id, 'description' => 'Tentativa de sequestro de linha;', 'amount' => ''],
        ]);

        // A linha alheia não foi tocada…
        $this->assertSame(
            'Pedido de outra conta;',
            Requirement::acrossAllAccounts()->findOrFail($foreign->id)->description,
        );

        // …e o pedido postado virou linha nova desta peça, com id próprio.
        $created = $legalCase->requirements()->sole();
        $this->assertSame('Tentativa de sequestro de linha;', $created->description);
        $this->assertNotSame($foreign->id, $created->id);
    }

    #[Test]
    public function an_id_from_another_pleading_of_the_same_account_creates_a_new_request(): void
    {
        [$account, $owner, $legalCase] = $this->pleading();

        $sibling = LegalCase::factory()->forAccount($account)->create();
        $theirs = Requirement::factory()->forLegalCase($sibling)->create(['description' => 'Da outra peça;']);

        $this->save($owner, $legalCase, [
            ['id' => $theirs->id, 'description' => 'Desta peça;', 'amount' => ''],
        ]);

        $this->assertSame('Da outra peça;', $theirs->refresh()->description);
        $this->assertNotSame($theirs->id, $legalCase->requirements()->sole()->id);
    }

    #[Test]
    public function a_request_that_was_deleted_is_not_resurrected_by_its_old_id(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'Com valor;', 'amount' => '1.000,00'],
        ]);

        $removed = $legalCase->requirements()->sole();

        $this->save($owner, $legalCase, []);

        // Repostar o id de um pedido apagado cria linha nova: ressuscitar um
        // valor que alguém removeu é pior do que um id duplicado.
        $this->save($owner, $legalCase, [
            ['id' => $removed->id, 'description' => 'Com valor;', 'amount' => ''],
        ]);

        $current = $legalCase->requirements()->sole();

        $this->assertNotSame($removed->id, $current->id);
        $this->assertNull($current->amount);
    }

    #[Test]
    public function saving_the_requests_advances_the_pleading_to_the_documents_step(): void
    {
        [, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, []);

        $this->assertSame(LegalCaseStep::Documents, $legalCase->refresh()->current_step);
    }

    #[Test]
    public function the_requests_are_stamped_with_the_account_of_the_pleading(): void
    {
        [$account, $owner, $legalCase] = $this->pleading();

        $this->save($owner, $legalCase, [
            ['id' => null, 'description' => 'A citação do Réu;', 'amount' => ''],
        ]);

        $this->assertSame($account->id, $legalCase->requirements()->sole()->account_id);
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function save(User $owner, LegalCase $legalCase, array $requirements): void
    {
        $this->actingAs($owner)
            ->put("/pecas/{$legalCase->id}/pedidos", ['requirements' => $requirements])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array{0: Account, 1: User, 2: LegalCase}
     */
    private function pleading(): array
    {
        [$account, $owner] = $this->accountWithOwner();

        return [$account, $owner, LegalCase::factory()->forAccount($account)->create()];
    }
}
