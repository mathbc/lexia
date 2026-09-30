<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Ai\Agents\CourtAddressingSuggestionAgent;
use App\Ai\Agents\JudicialSystemSelectionAgent;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Models\Customer;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * O "Consultar IA" do endereçamento: `POST /pecas/enderecamento/sugerir`.
 *
 * Os dois agentes são substituídos pelo dublê do SDK, e o que se fixa é a
 * fiação: de onde a cidade vem, quando o segundo agente é chamado, o que sai
 * da máquina e o que acontece quando um deles cai. A frase é composta em PHP,
 * então é aqui que a forma neutra — "Excelentíssimo(a) Senhor(a) Juiz(a)" — fica
 * fixada. O juízo sobre a competência é de
 * `tests/Agents/CourtAddressingSuggestionTest`.
 */
final class SuggestCourtAddressingTest extends TestCase
{
    use RefreshDatabase;

    private const string FACTS = 'Comprei uma geladeira pela internet que chegou quebrada, e a loja '
        .'se recusa a trocar. Já reclamei três vezes.';

    #[Test]
    public function the_city_is_copied_from_the_clients_record_and_the_system_from_the_map(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC, ['name' => 'Maria Aparecida Souza', 'birth_date' => now()->subYears(67)]);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer([
            'division' => 'civil',
            'forum_source' => 'plaintiff_address',
            // Ignorados: a fonte é o cadastro, e a cidade sai de lá.
            'city' => 'Florianópolis',
            'state' => 'RS',
            'legal_basis' => 'CDC, art. 101, I',
        ])]);
        JudicialSystemSelectionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class, [
                'defendant_name' => 'Loja Exemplo Ltda',
                'defendant_document' => '11.222.333/0001-44',
                'defendant_city' => 'São Paulo',
                'defendant_state' => 'SP',
            ]))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Joinville/SC')
            ->assertJsonPath('division', 'civil')
            ->assertJsonPath('branch', 'state')
            ->assertJsonPath('forum_source', 'plaintiff_address')
            ->assertJsonPath('city', 'Joinville')
            ->assertJsonPath('state', 'SC')
            ->assertJsonPath('legal_basis', 'CDC, art. 101, I')
            ->assertJsonPath('judicial_system.slug', 'eproc')
            ->assertJsonPath('judicial_system.court', 'TJSC')
            ->assertJsonPath('judicial_system.source', 'map')
            ->assertJsonPath('warnings', []);

        // Um tribunal com um sistema só é resposta do mapa, sem inferência.
        JudicialSystemSelectionAgent::assertNeverPrompted();

        CourtAddressingSuggestionAgent::assertPrompted(static function (AgentPrompt $prompt) use ($area): bool {
            $instructions = (string) $prompt->agent->instructions();

            return $prompt->prompt === self::FACTS
                && str_contains($instructions, '# Competência e endereçamento — base de análise')
                && str_contains($instructions, "A área de atuação deste caso é **{$area->label}**.")
                && str_contains($instructions, 'Competências da classe: Justiça Estadual — 1º grau')
                // O que decide a competência viaja — o tipo, a idade, a cidade
                // e o nome do réu pessoa jurídica —; o nome do cliente não.
                && str_contains($instructions, 'Autor (o cliente): pessoa física, 67 anos; domicílio em Joinville/SC.')
                && str_contains($instructions, 'Réu: pessoa jurídica, Loja Exemplo Ltda; endereço em São Paulo/SP.')
                && ! str_contains($instructions, 'Maria Aparecida')
                // A classe do procedimento comum não vai ao juizado: a lista
                // do fim oferece a vara, e não o JEC que o guia descreve.
                && str_contains($instructions, '- `civil` — Vara Cível (Justiça Estadual)')
                && ! str_contains($instructions, '- `small_claims` — Juizado Especial Cível (Justiça Estadual)');
        });
    }

    /**
     * O nome de um particular não decide competência nenhuma, e fica em casa.
     */
    #[Test]
    public function the_name_of_a_defendant_who_is_a_person_never_leaves(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer(['division' => 'civil', 'forum_source' => 'defendant_address'])]);

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class, [
                'defendant_name' => 'João Pereira',
                'defendant_document' => '123.456.789-00',
                'defendant_city' => 'Porto Alegre',
                'defendant_state' => 'RS',
            ]))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Porto Alegre/RS')
            ->assertJsonPath('judicial_system.slug', 'eproc');

        CourtAddressingSuggestionAgent::assertPrompted(static function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'Réu: pessoa física; endereço em Porto Alegre/RS.')
                && ! str_contains($instructions, 'João Pereira');
        });
    }

    #[Test]
    public function a_court_that_runs_two_systems_asks_the_second_agent(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Campinas', BrazilianState::SP);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer(['division' => 'civil', 'forum_source' => 'plaintiff_address'])]);
        JudicialSystemSelectionAgent::fake([[
            'system' => 'esaj',
            'justification' => 'A comarca de Campinas ainda recebe peças pelo e-SAJ.',
        ]]);

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Campinas/SP')
            ->assertJsonPath('judicial_system.slug', 'esaj')
            ->assertJsonPath('judicial_system.source', 'ai')
            ->assertJsonPath('judicial_system_justification', 'A comarca de Campinas ainda recebe peças pelo e-SAJ.')
            ->assertJsonPath('warnings', []);

        // Os candidatos são os do tribunal do foro, e só eles.
        JudicialSystemSelectionAgent::assertPrompted(static function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return $prompt->prompt === self::FACTS
                && str_contains($instructions, 'estado do foro: São Paulo; cidade do foro: Campinas.')
                && str_contains($instructions, '`esaj` — e-SAJ (TJSP, em uso)')
                && str_contains($instructions, '`eproc` — eproc (TJSP, em uso)')
                && ! str_contains($instructions, '`pje`');
        });
    }

    /**
     * O sistema custa o select, nunca o endereçamento.
     */
    #[Test]
    public function a_failed_system_choice_costs_only_the_select(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Campinas', BrazilianState::SP);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer(['division' => 'civil', 'forum_source' => 'plaintiff_address'])]);
        JudicialSystemSelectionAgent::fake(static fn (): never => throw new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Campinas/SP')
            ->assertJsonPath('judicial_system', null)
            ->assertJsonPath('warnings', ['Não foi possível apontar o sistema judicial do tribunal de SP: escolha-o à mão.']);
    }

    #[Test]
    public function a_court_still_moving_to_its_system_says_so(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Salvador', BrazilianState::BA);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer(['division' => 'civil', 'forum_source' => 'plaintiff_address'])]);
        JudicialSystemSelectionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertOk()
            ->assertJsonPath('judicial_system.slug', 'eproc')
            ->assertJsonPath('judicial_system.status', 'transition')
            ->assertJsonPath('warnings', ['O mapa registra o eproc no TJBA como “em transição”: confira no portal do tribunal se a comarca já o usa.']);
    }

    /**
     * O mapa é dos tribunais de justiça: um foro federal sai com a frase dele e
     * o sistema em branco, sem perguntar a ninguém.
     */
    #[Test]
    public function a_federal_forum_leaves_the_system_to_the_lawyer(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(436);

        CourtAddressingSuggestionAgent::fake([$this->answer(['division' => 'federal_small_claims', 'forum_source' => 'plaintiff_address'])]);
        JudicialSystemSelectionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) Federal do Juizado Especial Federal da Subseção Judiciária de Joinville/SC')
            ->assertJsonPath('branch', 'federal')
            ->assertJsonPath('judicial_system', null)
            ->assertJsonPath('warnings', ['O mapa de sistemas cobre só os tribunais de justiça estaduais: escolha à mão o sistema da Justiça Federal.']);

        JudicialSystemSelectionAgent::assertNeverPrompted();
    }

    #[Test]
    public function a_city_the_narrative_writes_is_accepted(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(7);
        $facts = 'O apartamento que aluguei fica em Balneário Camboriú e o dono não devolve a caução.';

        CourtAddressingSuggestionAgent::fake([$this->answer([
            'division' => 'civil',
            'forum_source' => 'facts',
            'city' => 'Balneário Camboriú',
            'state' => 'SC',
        ])]);

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', [...$this->payload($customer, $area, $class), 'facts' => $facts])
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Balneário Camboriú/SC')
            ->assertJsonPath('forum_source', 'facts')
            ->assertJsonPath('judicial_system.slug', 'eproc')
            ->assertJsonPath('warnings', []);
    }

    /**
     * A cidade que o relato não escreve não entra, e o advogado é avisado — a
     * guarda de cifra, aplicada a lugar.
     */
    #[Test]
    public function a_city_the_narrative_does_not_write_becomes_a_gap(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer([
            'division' => 'civil',
            'forum_source' => 'facts',
            'city' => 'Curitiba',
            'state' => 'PR',
        ])]);
        JudicialSystemSelectionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de [CIDADE/UF]')
            ->assertJsonPath('city', null)
            ->assertJsonPath('state', null)
            ->assertJsonPath('judicial_system', null)
            ->assertJsonPath('warnings', [
                'A IA apontou Curitiba, que o relato não menciona: confira a comarca do endereçamento.',
                'Sem a UF do foro não há como apontar o sistema judicial: escolha-o à mão.',
            ]);
    }

    #[Test]
    public function a_defendant_with_no_city_on_record_leaves_the_comarca_to_the_lawyer(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([$this->answer(['division' => 'civil', 'forum_source' => 'defendant_address'])]);

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class, ['defendant_state' => 'RS']))
            ->assertOk()
            ->assertJsonPath('court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de [CIDADE]/RS')
            // A UF basta para achar o sistema do tribunal.
            ->assertJsonPath('judicial_system.court', 'TJRS')
            ->assertJsonPath('warnings', ['O endereço do réu não traz a cidade: complete a comarca do endereçamento.']);
    }

    /**
     * Uma classe que só tramita em tribunal não tem juízo de primeiro grau a
     * endereçar, e a resposta diz isso sem gastar inferência.
     */
    #[Test]
    public function a_class_that_runs_only_in_a_tribunal_asks_no_one(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(47);

        CourtAddressingSuggestionAgent::fake([])->preventStrayPrompts();
        JudicialSystemSelectionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertOk()
            ->assertJsonPath('court_addressing', null)
            ->assertJsonPath('division', null)
            ->assertJsonPath('judicial_system', null);

        CourtAddressingSuggestionAgent::assertNeverPrompted();
    }

    /**
     * O cliente é um id vindo do pedido, e para a equipe LexIA o escopo está
     * aberto: a conta é conferida antes de o agente acordar.
     */
    #[Test]
    public function a_client_from_another_account_is_refused_before_any_inference(): void
    {
        [, $owner] = $this->accountWithOwner();
        $foreign = $this->customer(Account::factory()->create(), 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($foreign, $area, $class, ['defendant_state' => 'XX']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'defendant_state']);

        CourtAddressingSuggestionAgent::assertNeverPrompted();
    }

    #[Test]
    public function an_agent_that_fails_answers_with_a_message_the_screen_can_show(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = $this->customer($account, 'Joinville', BrazilianState::SC);
        [$area, $class] = $this->pair(7);

        CourtAddressingSuggestionAgent::fake(static fn (): never => throw new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/enderecamento/sugerir', $this->payload($customer, $area, $class))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Não foi possível sugerir o endereçamento agora. Tente novamente em instantes.');
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        $this->post('/pecas/enderecamento/sugerir', ['facts' => 'Qualquer coisa.'])
            ->assertRedirect('/login');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(Account $account, string $city, BrazilianState $state, array $attributes = []): Customer
    {
        return Customer::factory()->forAccount($account)->create([
            'city' => $city,
            'state' => $state,
            'birth_date' => null,
            ...$attributes,
        ]);
    }

    /**
     * The consumer area and one of its filing classes, by CNJ code.
     *
     * @return array{0: PracticeArea, 1: ProceduralClass}
     */
    private function pair(int $code): array
    {
        $area = PracticeArea::query()->where('slug', 'consumidor')->sole();

        return [$area, $area->proceduralClasses()->where('code', $code)->sole()];
    }

    /**
     * @param  array<string, mixed>  $defendant
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, PracticeArea $area, ProceduralClass $class, array $defendant = []): array
    {
        return [
            'facts' => self::FACTS,
            'practice_area' => $area->slug,
            'procedural_class_id' => $class->id,
            'customer_id' => $customer->id,
            ...$defendant,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answer(array $overrides): array
    {
        return [
            'division' => null,
            'forum_source' => null,
            'city' => null,
            'state' => null,
            'legal_basis' => 'CPC, art. 46',
            'justification' => 'O foro segue a regra aplicável ao caso.',
            ...$overrides,
        ];
    }
}
