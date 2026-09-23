<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPleadings;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Actions\DraftLegalPleading;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The Minuta tab: reading the current draft and saving edits to it.
 *
 * What is pinned is the one rule that makes this table a history rather than a
 * column: **a save never overwrites**. Editing writes the next version, so the
 * text the agent produced stays beside the text the lawyer settled on. The
 * corollary is pinned too — identical text writes nothing, because otherwise
 * opening a draft and pressing Salvar would fill the history with versions that
 * differ only in their timestamp.
 *
 * Saving involves no agent, and that is the point of the screen: correcting a
 * paragraph must not cost an inference. Regenerating does, and is pinned here to
 * the same rule as a save — it writes the next version and leaves the lawyer's
 * text in the table. The agent is mocked; it runs for real in tests/Agents.
 */
final class SaveLegalPleadingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function saving_writes_the_next_version_instead_of_overwriting(): void
    {
        [, $owner, $case] = $this->pleading();

        $first = LegalPleading::factory()
            ->forLegalCase($case)
            ->withContent('A redação do agente.')
            ->create();

        $this->actingAs($owner)
            ->put(route('legal-cases.pleading.save', $case), ['content' => 'A redação do advogado.'])
            ->assertRedirect(route('legal-cases.pleading', $case));

        $this->assertSame(2, $case->pleadings()->count());

        $latest = $case->pleadings()->first();
        $this->assertSame(2, $latest->version);
        $this->assertSame('A redação do advogado.', $latest->content);

        // A versão do agente continua inteira: é para isso que a tabela existe.
        $this->assertSame('A redação do agente.', $first->refresh()->content);
    }

    #[Test]
    public function saving_unchanged_text_writes_nothing(): void
    {
        [, $owner, $case] = $this->pleading();

        LegalPleading::factory()
            ->forLegalCase($case)
            ->withContent('Exatamente este texto.')
            ->create();

        $this->actingAs($owner)
            ->put(route('legal-cases.pleading.save', $case), ['content' => 'Exatamente este texto.'])
            ->assertRedirect(route('legal-cases.pleading', $case));

        $this->assertSame(1, $case->pleadings()->count());
    }

    /**
     * O `trim()` é o que faz "sem mudança" significar o que o advogado quer
     * dizer: uma quebra de linha a mais no fim da caixa não é uma versão nova.
     */
    #[Test]
    public function surrounding_whitespace_is_not_a_change(): void
    {
        [, $owner, $case] = $this->pleading();

        LegalPleading::factory()->forLegalCase($case)->withContent('O texto.')->create();

        $this->actingAs($owner)
            ->put(route('legal-cases.pleading.save', $case), ['content' => "  O texto.\n\n"]);

        $this->assertSame(1, $case->pleadings()->count());
    }

    #[Test]
    public function the_first_save_of_a_pleading_with_no_draft_is_version_one(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->actingAs($owner)
            ->put(route('legal-cases.pleading.save', $case), ['content' => 'Escrita à mão.']);

        $this->assertSame(1, $case->pleadings()->sole()->version);
    }

    #[Test]
    public function an_empty_draft_is_refused(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->actingAs($owner)
            ->put(route('legal-cases.pleading.save', $case), ['content' => ''])
            ->assertSessionHasErrors('content');

        $this->assertSame(0, $case->pleadings()->count());
    }

    #[Test]
    public function the_screen_shows_the_latest_version_and_the_letterhead(): void
    {
        [$account, $owner, $case] = $this->pleading();

        LegalPleading::factory()->forLegalCase($case)->version(1)->withContent('Antiga.')->create();
        LegalPleading::factory()->forLegalCase($case)->version(2)
            ->withContent('A atual, com [estado civil] em aberto.')
            ->create();

        $this->actingAs($owner)
            ->get(route('legal-cases.pleading', $case))
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/pleading')
                ->where('pleading.version', 2)
                ->where('pleading.content', 'A atual, com [estado civil] em aberto.')
                ->where('pleading.placeholders', ['[estado civil]'])
                ->where('letterhead.firm', $account->displayName())
                ->where('letterhead.lawyer', $owner->name)
                ->where('can.generate', true)
                ->where('can.export', true));
    }

    /**
     * A peça cuja redação falhou: a aba abre vazia e oferece o agente.
     *
     * A mesma porta que, com uma minuta na mão, vira "Gerar novamente" — ver
     * GenerateLegalPleading.
     */
    #[Test]
    public function a_pleading_with_no_draft_offers_to_generate_one(): void
    {
        [, $owner, $case] = $this->pleading();

        $this->actingAs($owner)
            ->get(route('legal-cases.pleading', $case))
            ->assertInertia(fn ($page) => $page
                ->component('legal-cases/pleading')
                ->where('pleading', null)
                ->where('can.generate', true)
                ->where('can.export', false));
    }

    /**
     * Gerar de novo é gravar a versão seguinte: o texto do advogado continua
     * na tabela como a versão anterior.
     */
    #[Test]
    public function generating_over_an_existing_draft_writes_the_next_version(): void
    {
        [, $owner, $case] = $this->pleading();

        $edited = LegalPleading::factory()->forLegalCase($case)->withContent('O que o advogado escreveu.')->create();

        $this->fakeDrafting()
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(fn (LegalCase $legalCase): LegalPleading => LegalPleading::factory()
                ->forLegalCase($legalCase)
                ->version(2)
                ->withContent('A redação nova do agente.')
                ->create());

        $this->actingAs($owner)
            ->post(route('legal-cases.pleading.generate', $case))
            ->assertRedirect(route('legal-cases.pleading', $case))
            ->assertSessionHas('success', 'Nova versão da minuta gerada.');

        $this->assertSame(2, $case->pleadings()->count());
        $this->assertSame('A redação nova do agente.', $case->pleadings()->first()->content);
        $this->assertSame('O que o advogado escreveu.', $edited->refresh()->content);
    }

    /** O provedor falhou: a versão atual fica onde estava, e a tela diz por quê. */
    #[Test]
    public function a_failed_regeneration_keeps_the_current_version(): void
    {
        [, $owner, $case] = $this->pleading();

        LegalPleading::factory()->forLegalCase($case)->withContent('A versão atual.')->create();

        $this->fakeDrafting()->shouldReceive('handle')->once()->andThrow(new RuntimeException('cota esgotada'));

        $this->actingAs($owner)
            ->post(route('legal-cases.pleading.generate', $case))
            ->assertSessionHas('error');

        $this->assertSame('A versão atual.', $case->pleadings()->sole()->content);
    }

    /**
     * Uma requisição por teste, e não duas, de propósito.
     *
     * O `TenantContext` é singleton e sobrevive de uma requisição para a outra
     * dentro do mesmo teste, o que o navegador nunca faz. A segunda requisição
     * encontraria o escopo já armado no route-model binding e devolveria 404 —
     * verde pelo motivo errado, escondendo que quem barra de verdade é a Policy.
     */
    #[Test]
    public function saving_the_draft_of_another_account_is_refused(): void
    {
        [$theirs, $owner] = $this->foreignPleading();

        // Route-model binding resolve antes do middleware de tenant: quem barra
        // é a Policy da peça, com 403 e não 404.
        $this->actingAs($owner)
            ->put(route('legal-cases.pleading.save', $theirs), ['content' => 'Invasão.'])
            ->assertForbidden();

        $this->assertSame(0, $theirs->pleadings()->count());
    }

    #[Test]
    public function reading_the_draft_of_another_account_is_refused(): void
    {
        [$theirs, $owner] = $this->foreignPleading();

        $this->actingAs($owner)
            ->get(route('legal-cases.pleading', $theirs))
            ->assertForbidden();
    }

    #[Test]
    public function generating_a_draft_for_another_account_is_refused(): void
    {
        [$theirs, $owner] = $this->foreignPleading();

        $this->actingAs($owner)
            ->post(route('legal-cases.pleading.generate', $theirs))
            ->assertForbidden();

        $this->assertSame(0, $theirs->pleadings()->count());
    }

    /**
     * O dublê da redação — mesmo arranjo de FinalizeLegalCaseTest: as Actions
     * são `final`, então o mock parte de uma instância já construída.
     */
    private function fakeDrafting(): MockInterface
    {
        $fake = Mockery::mock(app(DraftLegalPleading::class));

        app()->instance('LaravelActions:AsFake:'.DraftLegalPleading::class, $fake);

        return $fake;
    }

    /**
     * Uma peça de outra conta, e alguém de fora dela.
     *
     * @return array{0: LegalCase, 1: User}
     */
    private function foreignPleading(): array
    {
        [, $owner] = $this->accountWithOwner();

        [$otherAccount] = $this->accountWithOwner();

        return [LegalCase::factory()->forAccount($otherAccount)->create(), $owner];
    }

    /**
     * @return array{0: Account, 1: User, 2: LegalCase}
     */
    private function pleading(): array
    {
        [$account, $owner] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->finalised()->create();

        return [$account, $owner, $case];
    }
}
