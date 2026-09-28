<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The assembly flow: a pleading saved one step at a time.
 *
 * What these pin down is less the writing of columns than the two rules that
 * are easy to get wrong and impossible to see — that the step is a high-water
 * mark which corrections do not rewind, and that the Policy, not the scope, is
 * what keeps a pleading inside its account once a route resolves it.
 */
final class SaveLegalCaseStepsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_first_step_creates_the_pleading_and_lands_on_the_edit_url(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();
        $area = PracticeArea::query()->where('slug', 'imobiliario')->sole();
        $class = $area->proceduralClasses()->where('is_filing_class', true)->firstOrFail();

        $response = $this->actingAs($owner)->post('/pecas', [
            'customer_id' => $customer->id,
            'practice_area' => 'imobiliario',
            'procedural_class_id' => $class->id,
            'court_addressing' => 'Ao Juízo da 3ª Vara Cível da Comarca de Florianópolis/SC',
            'injunctive_relief' => false,
        ]);

        $legalCase = LegalCase::query()->sole();

        $response->assertRedirect(
            '/pecas/'.$legalCase->id.'/editar?etapa=defendant'
        );

        $this->assertSame($customer->id, $legalCase->customer_id);
        // A área viaja como slug e é gravada como uuid — trocar um pelo outro
        // é o erro mais provável desta rota.
        $this->assertSame($area->id, $legalCase->practice_area_id);
        $this->assertSame($class->id, $legalCase->procedural_class_id);
        $this->assertSame(
            'Ao Juízo da 3ª Vara Cível da Comarca de Florianópolis/SC',
            $legalCase->court_addressing,
        );
    }

    #[Test]
    public function a_new_pleading_starts_as_a_draft_standing_on_the_defendant_step(): void
    {
        $legalCase = $this->openPleading();

        $this->assertTrue($legalCase->is_draft);
        $this->assertSame(LegalCaseStep::Defendant, $legalCase->current_step);
    }

    #[Test]
    public function the_pleading_keeps_the_account_it_was_created_in(): void
    {
        [$account] = $this->accountWithOwner();

        $legalCase = $this->openPleading($account);

        // Nunca vem do request: BelongsToAccount carimba.
        $this->assertSame($account->id, $legalCase->account_id);
    }

    #[Test]
    public function the_court_addressing_is_optional(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/pecas', $this->basics($account, addressing: null))
            ->assertSessionHasNoErrors();

        $this->assertNull(LegalCase::query()->sole()->court_addressing);
    }

    /**
     * O relato é da etapa 1, e a criação o grava.
     *
     * Quem vem do preenchimento inteligente escreveu os fatos antes de a peça
     * existir, e foram eles que produziram a área e a classe. Gravá-los na
     * criação é o que os faz sobreviver à navegação que ela provoca. A marca
     * d'água segue a regra de sempre: a primeira etapa feita, a peça está na
     * segunda.
     */
    #[Test]
    public function the_narrative_that_framed_the_case_is_saved_with_it(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/pecas', $this->basics($account, addressing: null) + [
                'facts' => '  O vizinho derrubou o muro e se recusa a reconstruí-lo.  ',
            ])
            ->assertSessionHasNoErrors();

        $legalCase = LegalCase::query()->sole();

        $this->assertSame(
            'O vizinho derrubou o muro e se recusa a reconstruí-lo.',
            $legalCase->facts,
        );
        $this->assertSame(LegalCaseStep::Defendant, $legalCase->current_step);
    }

    /**
     * E continua opcional: o caminho manual manda a caixa vazia, e a peça nasce
     * sem relato como sempre nasceu.
     */
    #[Test]
    public function a_pleading_opened_by_hand_is_born_without_a_narrative(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/pecas', $this->basics($account, addressing: null) + ['facts' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(LegalCase::query()->sole()->facts);
    }

    /**
     * A tutela também é da etapa 1: quem a marca já na criação não precisa
     * voltar para escrevê-la.
     */
    #[Test]
    public function the_first_step_saves_the_injunction_it_asks_for(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/pecas', [
                'injunctive_relief' => true,
                'injunctive_relief_description' => '  Risco de demolição iminente.  ',
            ] + $this->basics($account, addressing: null))
            ->assertSessionHasNoErrors();

        $legalCase = LegalCase::query()->sole();

        $this->assertTrue($legalCase->injunctive_relief);
        $this->assertSame('Risco de demolição iminente.', $legalCase->injunctive_relief_description);
    }

    /**
     * A tutela é uma decisão: a etapa não a supõe, e um payload sem ela é
     * recusado em vez de gravar um "não" que ninguém disse.
     */
    #[Test]
    public function the_first_step_refuses_a_payload_that_does_not_decide_the_injunction(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $payload = $this->basics($account, addressing: null);
        unset($payload['injunctive_relief']);

        $this->actingAs($owner)
            ->post('/pecas', $payload)
            ->assertSessionHasErrors('injunctive_relief');

        $this->assertSame(0, LegalCase::query()->count());
    }

    #[Test]
    public function a_class_that_does_not_belong_to_the_chosen_area_is_refused(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();

        $labour = PracticeArea::query()->where('slug', 'trabalhista')->sole();
        $stranger = $labour->proceduralClasses()->where('is_filing_class', true)->firstOrFail();

        $this->actingAs($owner)
            ->post('/pecas', [
                'customer_id' => $customer->id,
                // A classe é trabalhista, a área declarada é imobiliária: o par
                // vive no pivot e o banco não o impede.
                'practice_area' => 'imobiliario',
                'procedural_class_id' => $stranger->id,
                'injunctive_relief' => false,
            ])
            ->assertSessionHasErrors('procedural_class_id');

        $this->assertSame(0, LegalCase::query()->count());
    }

    #[Test]
    public function a_client_from_another_account_cannot_be_the_subject_of_a_pleading(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$other] = $this->accountWithOwner();
        $stranger = Customer::factory()->forAccount($other)->create();

        $area = PracticeArea::query()->where('slug', 'imobiliario')->sole();
        $class = $area->proceduralClasses()->where('is_filing_class', true)->firstOrFail();

        $this->actingAs($owner)
            ->post('/pecas', [
                'customer_id' => $stranger->id,
                'practice_area' => 'imobiliario',
                'procedural_class_id' => $class->id,
                'injunctive_relief' => false,
            ])
            ->assertSessionHasErrors('customer_id');
    }

    #[Test]
    public function the_defendant_step_accepts_a_name_and_nothing_else(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)
            ->put("/pecas/{$legalCase->id}/reu", ['defendant_name' => 'Construtora Atlântico Ltda.'])
            ->assertSessionHasNoErrors();

        $legalCase->refresh();

        $this->assertSame('Construtora Atlântico Ltda.', $legalCase->defendant_name);
        $this->assertNull($legalCase->defendant_postal_code);
        $this->assertSame(LegalCaseStep::Requirements, $legalCase->current_step);
    }

    #[Test]
    public function the_defendant_document_postal_code_and_phone_are_stored_as_digits(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)
            ->put("/pecas/{$legalCase->id}/reu", [
                'defendant_name' => 'Joana Pereira',
                'defendant_document' => '123.456.789-09',
                'defendant_phone' => '(48) 99999-8888',
                'defendant_postal_code' => '88010-100',
                'defendant_email' => 'Joana@Exemplo.COM',
                'defendant_state' => 'SC',
            ])
            ->assertSessionHasNoErrors();

        $legalCase->refresh();

        $this->assertSame('12345678909', $legalCase->defendant_document);
        $this->assertSame('48999998888', $legalCase->defendant_phone);
        $this->assertSame('88010100', $legalCase->defendant_postal_code);
        $this->assertSame('joana@exemplo.com', $legalCase->defendant_email);
        $this->assertSame(BrazilianState::SC, $legalCase->defendant_state);
    }

    /**
     * Voltar à etapa 1 é também onde o relato é terminado: o re-salvar grava os
     * fatos e a tutela, e não só o enquadramento.
     */
    #[Test]
    public function re_saving_the_first_step_writes_the_narrative_and_the_injunction(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)
            ->put("/pecas/{$legalCase->id}/dados-basicos", [
                'facts' => 'O imóvel foi ocupado em março.',
                'injunctive_relief' => true,
                'injunctive_relief_description' => 'Risco de demolição iminente.',
            ] + $this->basics($account, addressing: null))
            ->assertSessionHasNoErrors();

        $legalCase->refresh();

        $this->assertSame('O imóvel foi ocupado em março.', $legalCase->facts);
        $this->assertTrue($legalCase->injunctive_relief);
        $this->assertSame('Risco de demolição iminente.', $legalCase->injunctive_relief_description);
    }

    #[Test]
    public function unchecking_the_relief_clears_its_description(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);
        $basics = $this->basics($account, addressing: null);

        $this->actingAs($owner)->put("/pecas/{$legalCase->id}/dados-basicos", [
            'facts' => 'O imóvel foi ocupado em março.',
            'injunctive_relief' => true,
            'injunctive_relief_description' => 'Risco de demolição iminente.',
        ] + $basics);

        $this->assertSame('Risco de demolição iminente.', $legalCase->refresh()->injunctive_relief_description);

        // A descrição não sobrevive ao desmarcar: texto guardado sob um pedido
        // que não existe é dado que ninguém consegue interpretar depois.
        $this->actingAs($owner)->put("/pecas/{$legalCase->id}/dados-basicos", [
            'facts' => 'O imóvel foi ocupado em março.',
            'injunctive_relief' => false,
            'injunctive_relief_description' => 'Risco de demolição iminente.',
        ] + $basics);

        $legalCase->refresh();

        $this->assertFalse($legalCase->injunctive_relief);
        $this->assertNull($legalCase->injunctive_relief_description);
    }

    /**
     * A sugestão da IA é gravada ao lado da decisão, como a tela a recebeu: é
     * o que mantém o selo "Sugestão da IA" depois de um reload.
     */
    #[Test]
    public function the_first_step_keeps_the_suggestion_it_was_given(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/pecas', [
                'injunctive_relief' => true,
                'injunctive_relief_description' => 'Medida pretendida: que se determine a suspensão da obra.',
                'injunctive_relief_suggestion' => $this->suggestion(),
            ] + $this->basics($account, addressing: null))
            ->assertSessionHasNoErrors();

        $legalCase = LegalCase::query()->sole();

        // `assertEquals`, e não `assertSame`: o jsonb do Postgres reordena as
        // chaves ao gravar, e a ordem delas não é contrato de ninguém.
        $this->assertEquals($this->suggestion(), $legalCase->injunctive_relief_suggestion);

        // E volta para a tela na mesma forma, pela projeção do formulário.
        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('legalCase.injunctive_relief_suggestion.kind', 'anticipatory')
                ->where('legalCase.injunctive_relief_suggestion.kind_label', 'Antecipada')
                ->where('legalCase.injunctive_relief_suggestion.recommended', true));
    }

    /**
     * Desmarcar apaga a descrição, mas não o registro do que a IA disse: "a IA
     * recomendou e o advogado recusou" é informação, e é exatamente essa.
     */
    #[Test]
    public function unchecking_the_relief_keeps_what_the_ai_said(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)->put("/pecas/{$legalCase->id}/dados-basicos", [
            'injunctive_relief' => false,
            'injunctive_relief_description' => 'Medida pretendida: que se determine a suspensão da obra.',
            'injunctive_relief_suggestion' => $this->suggestion(),
        ] + $this->basics($account, addressing: null))->assertSessionHasNoErrors();

        $legalCase->refresh();

        $this->assertFalse($legalCase->injunctive_relief);
        $this->assertNull($legalCase->injunctive_relief_description);
        $this->assertTrue($legalCase->injunctive_relief_suggestion['recommended']);
    }

    /**
     * Sem sugestão é o estado de uma peça montada à mão, e é nulo — não um
     * envelope vazio que a tela leria como uma consulta que aconteceu.
     */
    #[Test]
    public function a_pleading_built_by_hand_has_no_suggestion(): void
    {
        $legalCase = $this->openPleading();

        $this->assertNull($legalCase->injunctive_relief_suggestion);
    }

    #[Test]
    public function a_malformed_suggestion_is_refused(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->post('/pecas', [
                'injunctive_relief_suggestion' => [
                    'recommended' => 'talvez',
                    'kind' => 'provisional',
                    'evidence' => array_fill(0, 6, 'Documento'),
                ],
            ] + $this->basics($account, addressing: null))
            ->assertSessionHasErrors([
                'injunctive_relief_suggestion.recommended',
                'injunctive_relief_suggestion.kind',
                'injunctive_relief_suggestion.evidence',
                'injunctive_relief_suggestion.suggested_at',
            ]);

        $this->assertSame(0, LegalCase::query()->count());
    }

    #[Test]
    public function each_step_advances_the_pleading_to_the_next_one(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)->put("/pecas/{$legalCase->id}/reu", ['defendant_name' => 'Réu']);
        $this->assertSame(LegalCaseStep::Requirements, $legalCase->refresh()->current_step);

        $this->actingAs($owner)->put("/pecas/{$legalCase->id}/pedidos", ['requirements' => []]);
        $this->assertSame(LegalCaseStep::Documents, $legalCase->refresh()->current_step);
    }

    #[Test]
    public function the_documents_step_only_moves_the_pleading_forward(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)
            ->patch("/pecas/{$legalCase->id}/etapa", ['step' => 'review'])
            ->assertRedirect("/pecas/{$legalCase->id}/editar?etapa=review");

        $this->assertSame(LegalCaseStep::Review, $legalCase->refresh()->current_step);
    }

    #[Test]
    public function going_back_to_an_earlier_step_does_not_regress_the_furthest_reached(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $legalCase = $this->openPleading($account);

        $this->actingAs($owner)->patch("/pecas/{$legalCase->id}/etapa", ['step' => 'review']);
        $this->assertSame(LegalCaseStep::Review, $legalCase->refresh()->current_step);

        // Voltar à etapa 1 para corrigir o endereçamento é correção, não
        // retrocesso: as etapas já preenchidas continuam abertas.
        $this->actingAs($owner)
            ->put("/pecas/{$legalCase->id}/dados-basicos", $this->basics($account, addressing: 'Ao Juízo da 1ª Vara'))
            ->assertSessionHasNoErrors();

        $legalCase->refresh();

        $this->assertSame(LegalCaseStep::Review, $legalCase->current_step);
        $this->assertSame('Ao Juízo da 1ª Vara', $legalCase->court_addressing);
    }

    #[Test]
    public function a_lawyer_cannot_write_another_accounts_pleading(): void
    {
        [$account] = $this->accountWithOwner();
        $legalCase = LegalCase::factory()->forAccount($account)->create();

        [, $stranger] = $this->accountWithOwner();

        $this->assertEveryWriteRouteIsForbiddenTo($stranger, $legalCase);
    }

    #[Test]
    public function platform_staff_cannot_write_another_accounts_pleading(): void
    {
        [$account] = $this->accountWithOwner();
        $legalCase = LegalCase::factory()->forAccount($account)->create();

        $this->assertEveryWriteRouteIsForbiddenTo(
            User::factory()->platformAdmin()->create(),
            $legalCase,
        );
    }

    /**
     * 403 on every write route, and 403 rather than 404 is the whole point.
     *
     * Route-model binding resolves the pleading before BindTenantContext runs,
     * so the scope is not what refuses it — LegalCasePolicy is. That is the
     * trap CLAUDE.md names, and it is why the Policy had to gain `update`
     * before any of these routes existed.
     *
     * The context is cleared between requests because the singleton outlives a
     * request here, unlike in production where each one starts with a fresh
     * container. Without this the second call inherits the first call's tenant,
     * the scope hides the row, and a 404 would pass for a defence that is not
     * actually being exercised.
     */
    private function assertEveryWriteRouteIsForbiddenTo(User $actor, LegalCase $legalCase): void
    {
        foreach ($this->writeRoutes($legalCase->id) as [$method, $url]) {
            app(TenantContext::class)->clear();

            $this->actingAs($actor)->{$method}($url, [])->assertForbidden();
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function writeRoutes(string $id): array
    {
        return [
            ['put', "/pecas/{$id}/dados-basicos"],
            ['put', "/pecas/{$id}/reu"],
            ['put', "/pecas/{$id}/pedidos"],
            ['patch', "/pecas/{$id}/etapa"],
        ];
    }

    /**
     * The envelope the screen received from the urgent-relief agent, as it
     * posts it back.
     *
     * @return array<string, mixed>
     */
    private function suggestion(): array
    {
        return [
            'recommended' => true,
            'kind' => 'anticipatory',
            'kind_label' => 'Antecipada',
            'description' => 'Medida pretendida: que se determine a suspensão da obra.',
            'justification' => 'A obra avança sobre o terreno do Autor.',
            'evidence' => ['Fotos da obra'],
            'unsupported_amounts' => [],
            'suggested_at' => '2026-09-27T12:00:00-03:00',
        ];
    }

    /**
     * A valid step-1 payload for an account, client included.
     *
     * @return array<string, mixed>
     */
    private function basics(Account $account, ?string $addressing): array
    {
        $customer = Customer::factory()->forAccount($account)->create();
        $area = PracticeArea::query()->where('slug', 'imobiliario')->sole();
        $class = $area->proceduralClasses()->where('is_filing_class', true)->firstOrFail();

        return [
            'customer_id' => $customer->id,
            'practice_area' => 'imobiliario',
            'procedural_class_id' => $class->id,
            'court_addressing' => $addressing,
            'injunctive_relief' => false,
        ];
    }

    /**
     * A pleading opened through the real route, as the form does.
     */
    private function openPleading(?Account $account = null): LegalCase
    {
        if ($account === null) {
            [$account, $owner] = $this->accountWithOwner();
        } else {
            $owner = User::factory()->forAccount($account)->accountAdmin()->create();
        }

        $this->actingAs($owner)->post('/pecas', $this->basics($account, addressing: null));

        return LegalCase::query()->latest()->firstOrFail();
    }

    /**
     * O `?etapa` só pode estreitar: ele nunca abre uma etapa que a peça não
     * alcançou.
     *
     * Era um buraco com duas pontas. `?etapa=review` numa peça parada na etapa
     * 2 abria a revisão forense de um caso sem pedidos — e, agora
     * que abrir aquela etapa dispara a pesquisa de teses, gastaria uma
     * inferência de nuvem sobre um dossiê que não diz nada.
     *
     * A marca d'água continua sendo outra coisa: pedir uma etapa que a peça já
     * alcançou abre exatamente ela, que é o que faz o "Continuar" funcionar.
     */
    #[Test]
    public function the_step_in_the_url_cannot_go_past_the_high_water_mark(): void
    {
        [$account, $owner] = $this->accountWithOwner();

        $legalCase = LegalCase::factory()->forAccount($account)->create([
            'current_step' => LegalCaseStep::Defendant,
        ]);

        // Além da marca: aterra na marca, e não na etapa pedida.
        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar?etapa=review")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('initialStep', LegalCaseStep::Defendant->value));

        // Aquém da marca: abre onde se pediu, que é o caso do "Continuar" e o
        // da correção de uma etapa anterior.
        $this->actingAs($owner)
            ->get("/pecas/{$legalCase->id}/editar?etapa=basics")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('initialStep', LegalCaseStep::Basics->value));
    }

    /**
     * Numa peça que ainda não existe não há etapa alcançada nenhuma, então o
     * `?etapa` não significa coisa alguma — e é o que sustenta a trava da
     * trilha no assistente: até o "Continuar" da etapa 1 gravar a peça, não há
     * para onde ir.
     */
    #[Test]
    public function a_pleading_that_does_not_exist_yet_always_opens_at_the_first_step(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->get('/pecas/nova?etapa=review')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('initialStep', LegalCaseStep::Basics->value)
                ->where('legalCase', null));
    }
}
