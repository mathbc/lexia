<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Models\Customer;
use App\Domain\JudicialSystems\Data\JudicialSystemSelection;
use App\Domain\JudicialSystems\Models\JudicialSystemCourt;
use App\Domain\LegalCases\Actions\ExtractLegalCaseDefendant;
use App\Domain\LegalCases\Actions\ExtractLegalCaseRequirements;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Actions\ScreenLegalCaseFacts;
use App\Domain\LegalCases\Actions\SuggestCourtAddressing;
use App\Domain\LegalCases\Actions\SuggestInjunctiveRelief;
use App\Domain\LegalCases\Data\CourtAddressingSuggestionData;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\FactsScreeningData;
use App\Domain\LegalCases\Data\ForumPlace;
use App\Domain\LegalCases\Data\InjunctiveReliefSuggestionData;
use App\Domain\LegalCases\Enums\CourtDivision;
use App\Domain\LegalCases\Enums\FactsScreeningVerdict;
use App\Domain\LegalCases\Enums\ForumSource;
use App\Domain\LegalCases\Enums\InjunctiveReliefKind;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\PracticeAreas\Data\PracticeAreaClassification;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Data\RequirementData;
use App\Domain\Requirements\Data\RequirementListData;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A casca HTTP do enquadramento: `POST /pecas/classificar`.
 *
 * As cinco etapas são substituídas por dublês, e é o ponto. O que se verifica
 * aqui é a rota — autorização, validação, a forma da resposta e o que acontece
 * quando a inferência falha —, não a qualidade da classificação; essa vive em
 * `tests/Agents`, exige o Ollama de pé e custa segundos por caso.
 *
 * Todo teste que chega à inferência dubla as cinco — a tutela de urgência é a
 * quinta, terceiro elo do enquadramento: uma que ficasse de fora sairia daqui
 * direto para o modelo, e um teste da suíte padrão passaria a depender dele.
 *
 * E dubla também a triagem, que vem antes das cinco: `admit()` a faz deixar o
 * relato passar. Os testes que falam dela — a recusa, a queda, o piso — estão
 * logo depois do relato vazio, porque são a mesma pergunta um degrau acima.
 *
 * O endereçamento é a sexta, e a exceção a essa regra: ela só roda com o
 * cliente no pedido, então os testes que postam só os fatos não a alcançam. Os
 * que postam o cliente a dublam, e estão juntos no fim do arquivo — e são
 * também os que gravam o rascunho, porque a peça não existe sem cliente.
 *
 * `ResearchLegalCaseTheses` não está entre elas, e não por esquecimento: ela
 * saiu desta rota. Ela aparece aqui uma vez só, em
 * `the_classification_never_researches_and_never_leaves_the_machine`, e com
 * `shouldNotReceive` — é a única das cinco antigas que sairia da máquina, então
 * religá-la sem querer gastaria cota do Gemini e abriria os portais oficiais de
 * verdade a cada rodada da suíte.
 */
final class ClassifyLegalCaseEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_answers_with_the_framing_in_json(): void
    {
        [, $owner] = $this->accountWithOwner();

        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: $area,
                justification: 'O réu é um particular e não há relação de consumo.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(
                proceduralClass: $class,
                justification: 'O pedido é indenizatório e não há rito próprio.',
            ));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn(new DefendantData(
                name: 'Joaquim Vizinho',
                document: null,
                email: null,
                phone: null,
                postalCode: null,
                street: 'Rua das Acácias',
                number: '118',
                complement: null,
                district: null,
                city: 'Joinville',
                state: BrazilianState::SC,
                notes: null,
            ));

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu à reconstrução do muro derrubado;',
                    amount: null,
                ),
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu ao pagamento de R$ 4.300,00 a título de danos materiais;',
                    amount: '4300.00',
                ),
            ]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: true));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro e se recusa a reconstruí-lo.'])
            ->assertOk()
            // O slug da área e o uuid da classe são as duas formas que o
            // assistente preenche: `?area=` na URL e `procedural_class_id` no
            // formulário. Trocar uma pela outra quebra a tela de destino sem
            // quebrar esta rota.
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonPath('practice_area.id', $area->id)
            ->assertJsonPath('procedural_class.id', $class->id)
            ->assertJsonPath('procedural_class.code', 7)
            ->assertJsonPath('practice_area_justification', 'O réu é um particular e não há relação de consumo.')
            ->assertJsonPath('procedural_class_justification', 'O pedido é indenizatório e não há rito próprio.')
            // As doze chaves `defendant_*` viajam com o nome da coluna, que é
            // o nome do campo do formulário: é isso que faz a sugestão cair na
            // etapa do réu sem tradução nenhuma no meio.
            ->assertJsonPath('defendant.defendant_name', 'Joaquim Vizinho')
            ->assertJsonPath('defendant.defendant_street', 'Rua das Acácias')
            // A UF sai como a sigla, e não como o objeto do enum.
            ->assertJsonPath('defendant.defendant_state', 'SC')
            // O que o relato não diz chega nulo e presente: uma chave ausente
            // deixaria o campo sem valor em vez de vazio.
            ->assertJsonPath('defendant.defendant_document', null)
            ->assertJsonPath('defendant.defendant_notes', null)
            // Os pedidos chegam na ordem em que serão numerados, e o valor sai
            // em decimal: a máscara é do campo que o desenha, não da rota.
            ->assertJsonCount(2, 'requirements')
            ->assertJsonPath(
                'requirements.0.description',
                'A condenação do Réu à reconstrução do muro derrubado;',
            )
            ->assertJsonPath('requirements.0.amount', null)
            ->assertJsonPath('requirements.1.amount', '4300.00')
            // A tutela chega com o texto já composto e a espécie nas duas
            // formas: o valor que volta ao salvar e o rótulo que a tela mostra.
            ->assertJsonPath('injunctive_relief.recommended', true)
            ->assertJsonPath('injunctive_relief.kind', 'anticipatory')
            ->assertJsonPath('injunctive_relief.kind_label', 'Antecipada')
            ->assertJsonPath(
                'injunctive_relief.description',
                'Medida pretendida: que se determine ao Réu a reconstrução do muro.',
            )
            // A revisão forense **não** está aqui, e a ausência é contrato: a
            // pesquisa saiu desta rota porque não tem onde gravar o que acha
            // enquanto a peça não tem chave primária. Quem a roda hoje é
            // `ResearchLegalCaseForensicReview`, ao abrir a etapa 5.
            ->assertJsonMissingPath('research');
    }

    /**
     * O réu e os pedidos são as duas respostas que podem faltar sozinhas.
     *
     * O advogado esperou minutos pelo enquadramento; devolver 503 porque uma
     * das extrações caiu cobraria as inferências que deram certo para entregar
     * a mesma tela de erro de quem não teve nenhuma. E a falha de uma não
     * arrasta a outra: são perguntas diferentes sobre o mesmo relato.
     */
    #[Test]
    public function an_extraction_that_could_not_be_read_does_not_cost_the_framing(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(
                proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
                justification: 'O pedido é indenizatório.',
            ));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu à reconstrução do muro derrubado;',
                    amount: null,
                ),
            ]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: false));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonPath('defendant', null)
            ->assertJsonCount(1, 'requirements');
    }

    /**
     * Uma área sem classe de ajuizamento não existe no catálogo de hoje, mas o
     * payload admite o caso — e a tela de destino depende de ele chegar nulo em
     * vez de faltar.
     */
    #[Test]
    public function a_framing_without_a_class_keeps_the_shape(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturnNull();

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn(new DefendantData(
                name: null,
                document: null,
                email: null,
                phone: null,
                postalCode: null,
                street: null,
                number: null,
                complement: null,
                district: null,
                city: null,
                state: null,
                notes: 'O relato não identifica o vizinho.',
            ));

        // A lista vazia é o relato que não pede nada, e não a inferência que
        // falhou: aquela é o nulo do teste acima.
        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: false));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('procedural_class', null)
            ->assertJsonPath('procedural_class_justification', null)
            ->assertJsonPath('requirements', []);
    }

    /**
     * Inferência é cara: um relato em branco é recusado pelo validador, antes
     * de qualquer agente ser acordado.
     */
    #[Test]
    public function an_empty_narrative_is_refused_before_any_inference(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->fakeAction(ScreenLegalCaseFacts::class)->shouldNotReceive('handle');
        $this->fakeAction(ClassifyPracticeArea::class)->shouldNotReceive('handle');
        $this->fakeAction(SelectProceduralClass::class)->shouldNotReceive('handle');
        $this->fakeAction(ExtractLegalCaseDefendant::class)->shouldNotReceive('handle');
        $this->fakeAction(ExtractLegalCaseRequirements::class)->shouldNotReceive('handle');
        $this->fakeAction(SuggestInjunctiveRelief::class)->shouldNotReceive('handle');
        $this->fakeAction(ResearchLegalCaseTheses::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('facts');
    }

    /**
     * A porta: um texto que não é relato de caso volta como erro de validação
     * dos fatos, e nada do que viria depois dele é acordado.
     *
     * É o 422, e não o 503, porque a recusa é uma resposta sobre a entrada — e
     * é o 422 que a tela desenha debaixo do campo. Sem peça gravada, mesmo com
     * o cliente no pedido: o rascunho só existe depois do enquadramento.
     *
     * O log registra quem foi recusado e por quê, e **não** o texto: o falso
     * positivo é o relato de um cliente de verdade.
     */
    #[Test]
    public function a_text_that_is_not_a_case_is_refused_before_the_block(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();
        $facts = 'Batatinha quando nasce se espalha a rama pelo chão.';
        $screening = $this->screening(legalMatter: false, reason: 'É a letra de uma cantiga infantil.');

        $log = Log::spy();

        $this->fakeAction(ScreenLegalCaseFacts::class)
            ->shouldReceive('handle')
            ->once()
            ->with($facts)
            ->andReturn($screening);

        foreach ([
            ClassifyPracticeArea::class,
            SelectProceduralClass::class,
            ExtractLegalCaseDefendant::class,
            ExtractLegalCaseRequirements::class,
            SuggestInjunctiveRelief::class,
            SuggestCourtAddressing::class,
        ] as $action) {
            $this->fakeAction($action)->shouldNotReceive('handle');
        }

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => $facts, 'customer_id' => $customer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['facts' => $screening->message()])
            ->assertJsonPath('message', $screening->message());

        $this->assertSame(0, LegalCase::acrossAllAccounts()->count());

        $log->shouldHaveReceived('notice')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Relato recusado pela triagem.'
                && $context === [
                    'user_id' => $owner->id,
                    'account_id' => $account->id,
                    'verdict' => 'not_legal',
                    'length' => mb_strlen($facts),
                ]);
    }

    /**
     * A triagem que cai não abre a porta: sem ela não há como saber se o texto
     * é um caso, e o agente da área, que viria logo depois, mora no mesmo
     * provider. É o 503 de sempre, e o bloco nem começa.
     */
    #[Test]
    public function a_screening_that_fails_answers_503_and_never_frames(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->fakeAction(ScreenLegalCaseFacts::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->fakeAction(ClassifyPracticeArea::class)->shouldNotReceive('handle');
        $this->fakeAction(ExtractLegalCaseDefendant::class)->shouldNotReceive('handle');
        $this->fakeAction(ExtractLegalCaseRequirements::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Não foi possível enquadrar o caso agora. Tente novamente em instantes.',
            );
    }

    /**
     * O piso não custa inferência: o que nem chega a ser frase é recusado pelo
     * validador, antes de a triagem ser perguntada.
     */
    #[Test]
    public function a_text_below_the_floor_is_refused_before_the_screening(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->fakeAction(ScreenLegalCaseFacts::class)->shouldNotReceive('handle');
        $this->fakeAction(ClassifyPracticeArea::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'teste teste'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('facts');
    }

    /**
     * Um agente fora do ar é condição de operação, não defeito: a tela precisa
     * de uma frase para mostrar, e de um status que não seja 200.
     *
     * A área é a única etapa obrigatória, e a queda dela é o 503 — mas o que
     * este teste passou a afirmar é mais estreito do que já foi. As duas
     * extrações são **irmãs** da área dentro do bloco concorrente, não etapas
     * seguintes: elas correm mesmo quando o enquadramento cai, e o trabalho
     * delas é descartado. É o desperdício que o paralelismo cobra, e está
     * escrito no docblock da Action.
     *
     * O que continua valendo, e é o que importa aqui: a pesquisa **não** é
     * acordada. Ela vive fora do bloco e é a etapa mais lenta e mais cara do
     * projeto, então o 503 sai antes de ela custar um minuto.
     */
    #[Test]
    public function an_agent_that_fails_answers_with_a_message_the_screen_can_show(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        // Pares da área, e não etapas depois dela: correm, e o que devolvem é
        // jogado fora junto com o resto.
        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([]));

        // A tutela, ao contrário, é elo da cadeia da área: sem área ela nem
        // começa, e não há inferência dela a jogar fora.
        $this->fakeAction(SuggestInjunctiveRelief::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Não foi possível enquadrar o caso agora. Tente novamente em instantes.',
            );
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        $this->post('/pecas/classificar', ['facts' => 'Qualquer coisa.'])
            ->assertRedirect('/login');
    }

    /**
     * A cadeia que sobrou: a triagem, e então área, depois classe, depois tutela.
     *
     * O que se verifica aqui não é o resultado, é a **dependência** — e ela é o
     * que restou de afirmável depois que as quatro etapas básicas passaram a
     * correr dentro de um `Concurrency::run`. `globally()->ordered()` reprova
     * uma etapa que rode antes da anterior ter voltado, e as três que o
     * carregam são as três que não podem se reordenar: as classes candidatas
     * são as da área, e a tutela lê a classe para saber se ela tem liminar
     * própria.
     *
     * As duas extrações entram com `once()` e **sem** `ordered()`, e a ausência
     * é a afirmação: a posição delas deixou de ser contrato. Elas são pares da
     * área, leem os mesmos fatos e não devem nada a ninguém — amarrá-las a uma
     * ordem aqui seria escrever em teste um detalhe que o desenho acabou de
     * abrir mão de garantir.
     *
     * O que este teste **não** prova é que o bloco é concorrente: ele roda no
     * driver `sync` que o `phpunit.xml` fixa, onde as três tasks correm em
     * série no mesmo processo para que os dublês valham. Quem exercita o driver
     * de verdade é `tests/Agents/LegalCaseClassificationTest`, que é opt-in e
     * gasta inferência.
     */
    #[Test]
    public function the_dependencies_between_the_steps_stay_in_order(): void
    {
        [, $owner] = $this->accountWithOwner();

        $area = PracticeArea::query()->where('slug', 'civil')->sole();

        // A porta abre a fila: nada do bloco roda antes de ela ter voltado.
        $this->fakeAction(ScreenLegalCaseFacts::class)
            ->shouldReceive('handle')
            ->once()
            ->globally()
            ->ordered()
            ->with('O vizinho derrubou o muro.')
            ->andReturn($this->screening());

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->once()
            ->globally()
            ->ordered()
            ->andReturn(new PracticeAreaClassification(
                practiceArea: $area,
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->once()
            ->globally()
            ->ordered()
            ->andReturn(new ProceduralClassSelection(
                proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
                justification: 'O pedido é indenizatório.',
            ));

        // A tutela lê a classe para saber se ela traz liminar própria, então
        // é o terceiro elo da cadeia, e recebe a área e a classe que as duas
        // primeiras decidiram — não as que ela mesma deduzisse do relato.
        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->once()
            ->globally()
            ->ordered()
            ->withArgs(static fn (PracticeArea $given, ?ProceduralClass $class, string $facts): bool => $given->is($area)
                && $class?->code === 7
                && $facts === 'O vizinho derrubou o muro.')
            ->andReturn($this->suggestion(recommended: false));

        // Sem `ordered()`: pares da área, e a posição delas não é contrato.
        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn(new RequirementListData([]));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk();
    }

    /**
     * A classe é a segunda da fila, e a queda dela não cancela as duas últimas.
     *
     * Nem o réu nem os pedidos dependem da classe — dependem dos mesmos fatos,
     * que continuam de pé. Devolver 503 aqui jogaria fora a área, que já custou
     * uma inferência, para entregar uma tela de erro; o assistente sabe abrir
     * sem classe e deixar o advogado escolher na lista da área.
     */
    #[Test]
    public function a_class_that_could_not_be_chosen_does_not_stop_the_queue(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        // A tutela roda mesmo sem classe: a área e os fatos bastam para uma
        // resposta, só sem o fundamento de um rito próprio.
        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(static fn (PracticeArea $area, ?ProceduralClass $class): bool => $class === null)
            ->andReturn($this->suggestion(recommended: false));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn(new DefendantData(
                name: 'Joaquim Vizinho',
                document: null,
                email: null,
                phone: null,
                postalCode: null,
                street: null,
                number: null,
                complement: null,
                district: null,
                city: null,
                state: null,
                notes: null,
            ));

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn(new RequirementListData([
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu à reconstrução do muro derrubado;',
                    amount: null,
                ),
            ]));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonPath('procedural_class', null)
            ->assertJsonPath('procedural_class_justification', null)
            ->assertJsonPath('defendant.defendant_name', 'Joaquim Vizinho')
            ->assertJsonCount(1, 'requirements');
    }

    /**
     * A tutela é a terceira da fila, e a queda dela não custa nada do resto.
     *
     * É a única etapa que julga em vez de ler, e a única que a tela sabe pedir
     * de novo sozinha: o nulo abre a etapa 1 com a caixa desmarcada e o
     * "Consultar IA" à mão, que é exatamente o que um advogado sem sugestão
     * nenhuma veria.
     */
    #[Test]
    public function an_injunction_that_could_not_be_weighed_does_not_cost_the_framing(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(
                proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
                justification: 'O pedido é indenizatório.',
            ));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([]));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('procedural_class.code', 7)
            ->assertJsonPath('defendant.defendant_name', 'Joaquim Vizinho')
            ->assertJsonPath('injunctive_relief', null);
    }

    /**
     * A pesquisa saiu desta rota, e a ausência é o contrato que este teste fixa.
     *
     * Ela já foi a quinta etapa daqui, e duas coisas a tiraram. A primeira é que
     * é a única inferência do projeto que sai da máquina e abre páginas antes de
     * responder: ficando aqui, anulava o bloco concorrente, porque encurtar as
     * quatro primeiras não encurta a quinta e o advogado esperava por ela antes
     * de ver o primeiro campo preenchido. A segunda é que uma peça não salva não
     * tem onde guardar o que a pesquisa acha — as teses voltavam no JSON,
     * atravessavam o `sessionStorage` e morriam com a aba.
     *
     * Hoje quem a roda é `ResearchLegalCaseForensicReview`, ao abrir a etapa 5,
     * sobre uma peça que já tem chave primária para receber o resultado.
     * Religá-la aqui traria os dois problemas de volta de uma vez, e sem barulho
     * nenhum: a suíte padrão passaria a gastar cota do Gemini e a abrir o STJ de
     * verdade, ficando vermelha por um portal fora do ar em vez de por um bug.
     */
    #[Test]
    public function the_classification_never_researches_and_never_leaves_the_machine(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(
                proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
                justification: 'O pedido é indenizatório.',
            ));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([]));

        // Sem dublê que a deixe passar: se alguém religar a quinta etapa, este
        // é o teste que fica vermelho, e não uma fatura do Gemini.
        $this->fakeAction(ResearchLegalCaseTheses::class)->shouldNotReceive('handle');

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: false));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonMissingPath('research');
    }

    /**
     * O endereçamento lê duas tasks do bloco — a classe e o réu extraído —, e
     * por isso roda depois dele, com o cliente que o pedido trouxe.
     */
    #[Test]
    public function the_addressing_runs_after_the_block_with_the_client_and_the_extracted_defendant(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();
        [$area, $class] = $this->fakeTheBlock();

        $this->fakeAction(SuggestCourtAddressing::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(static fn (PracticeArea $given, ?ProceduralClass $chosen, string $facts, Customer $client, DefendantData $defendant): bool => $given->is($area)
                && $chosen?->is($class) === true
                && $facts === 'O vizinho derrubou o muro.'
                && $client->is($customer)
                && $defendant->name === 'Joaquim Vizinho')
            ->andReturn(CourtAddressingSuggestionData::compose(
                CourtDivision::Civil,
                ForumSource::DefendantAddress,
                new ForumPlace('Joinville', BrazilianState::SC),
                'CPC, art. 46',
                'O réu mora em Joinville.',
                null,
            ));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.', 'customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonPath('court_addressing.court_addressing', 'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Joinville/SC')
            ->assertJsonPath('court_addressing.justification', 'O réu mora em Joinville.');
    }

    /**
     * O endereçamento que cai não leva o enquadramento junto: a chave volta
     * nula, e a etapa 1 oferece o "Consultar IA".
     */
    #[Test]
    public function a_failed_addressing_is_a_null_and_the_framing_survives(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();
        $this->fakeTheBlock();

        $this->fakeAction(SuggestCourtAddressing::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.', 'customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonPath('court_addressing', null);
    }

    /**
     * Sem cliente não há endereçamento, e também não há rascunho: a peça não
     * existe sem cliente. A chave volta nula, presente, e nada é gravado.
     */
    #[Test]
    public function without_a_client_the_addressing_never_runs(): void
    {
        [, $owner] = $this->accountWithOwner();
        $this->fakeTheBlock();

        $this->fakeAction(SuggestCourtAddressing::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('court_addressing', null)
            ->assertJsonPath('legal_case_id', null);

        $this->assertSame(0, LegalCase::acrossAllAccounts()->count());
    }

    /**
     * O enquadramento termina com a peça gravada como rascunho: a etapa 1, o
     * réu e os pedidos, do jeito que a tela os preenchia a partir da entrega.
     *
     * A marca d'água fica na etapa 1, e é a afirmação mais importante daqui:
     * quem preencheu foi a IA, e o advogado ainda confere cada etapa no
     * "Continuar" dela.
     */
    #[Test]
    public function with_a_client_the_framing_is_saved_as_a_draft(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();

        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $class = ProceduralClass::query()->where('code', 7)->sole();
        $court = JudicialSystemCourt::query()->where('court', 'TJSC')->firstOrFail();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(practiceArea: $area, justification: 'O réu é um particular.'));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(proceduralClass: $class, justification: 'O pedido é indenizatório.'));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu à reconstrução do muro derrubado;',
                    amount: null,
                ),
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu ao pagamento de R$ 4.300,00 a título de danos materiais;',
                    amount: '4300.00',
                ),
            ]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: true));

        $this->fakeAction(SuggestCourtAddressing::class)
            ->shouldReceive('handle')
            ->andReturn(CourtAddressingSuggestionData::compose(
                CourtDivision::Civil,
                ForumSource::DefendantAddress,
                new ForumPlace('Joinville', BrazilianState::SC),
                'CPC, art. 46',
                'O réu mora em Joinville.',
                JudicialSystemSelection::fromMap($court),
            ));

        $response = $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => "  O vizinho derrubou o muro.\n", 'customer_id' => $customer->id])
            ->assertOk()
            // O payload continua inteiro: é ele que a tela usa quando a peça
            // não pôde ser gravada.
            ->assertJsonPath('practice_area.slug', 'civil');

        $legalCase = LegalCase::acrossAllAccounts()->sole();

        $response->assertJsonPath('legal_case_id', $legalCase->id);

        $this->assertSame($account->id, $legalCase->account_id);
        $this->assertSame($owner->id, $legalCase->user_id);
        $this->assertSame($customer->id, $legalCase->customer_id);
        $this->assertSame($area->id, $legalCase->practice_area_id);
        $this->assertSame($class->id, $legalCase->procedural_class_id);
        $this->assertSame('O vizinho derrubou o muro.', $legalCase->facts);

        $this->assertTrue($legalCase->is_draft);
        $this->assertSame(LegalCaseStep::Basics, $legalCase->current_step);

        // A tutela recomendada abre marcada e escrita, com o envelope ao lado.
        $this->assertTrue($legalCase->injunctive_relief);
        $this->assertSame(
            'Medida pretendida: que se determine ao Réu a reconstrução do muro.',
            $legalCase->injunctive_relief_description,
        );
        $this->assertTrue($legalCase->injunctive_relief_suggestion['recommended'] ?? null);

        // O endereçamento e o sistema saem da sugestão; o envelope fica.
        $this->assertSame(
            'Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Joinville/SC',
            $legalCase->court_addressing,
        );
        $this->assertSame($court->judicial_system_id, $legalCase->judicial_system_id);
        $this->assertSame('O réu mora em Joinville.', $legalCase->court_addressing_suggestion['justification'] ?? null);

        $this->assertSame('Joaquim Vizinho', $legalCase->defendant_name);

        // Pela frase, e não pela posição: dois pedidos gravados no mesmo
        // segundo não têm ordem entre si — ver `LegalCase::requirements()`.
        $this->assertEqualsCanonicalizing([
            'A condenação do Réu à reconstrução do muro derrubado;' => null,
            'A condenação do Réu ao pagamento de R$ 4.300,00 a título de danos materiais;' => '4300.00',
        ], $legalCase->requirements()->pluck('amount', 'description')->all());

        // Sem o escopo de conta, que filtraria justamente o que se quer ver.
        $this->assertSame(
            [$account->id],
            Requirement::acrossAllAccounts()
                ->where('legal_case_id', $legalCase->id)
                ->pluck('account_id')
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * A tutela que a IA não recomenda não marca a caixa, mas o envelope é
     * gravado: "a IA não viu urgência" é o registro de uma resposta.
     */
    #[Test]
    public function an_injunction_not_recommended_is_saved_unchecked_with_its_envelope(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();
        $this->fakeTheBlock();

        $this->fakeAction(SuggestCourtAddressing::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.', 'customer_id' => $customer->id])
            ->assertOk();

        $legalCase = LegalCase::acrossAllAccounts()->sole();

        $this->assertFalse($legalCase->injunctive_relief);
        $this->assertNull($legalCase->injunctive_relief_description);
        $this->assertFalse($legalCase->injunctive_relief_suggestion['recommended'] ?? null);

        // O endereçamento que caiu é a etapa 1 com os dois campos em branco.
        $this->assertNull($legalCase->court_addressing);
        $this->assertNull($legalCase->judicial_system_id);
        $this->assertNull($legalCase->court_addressing_suggestion);
    }

    /**
     * Sem classe não há rascunho — a coluna é NOT NULL —, e o enquadramento
     * não se perde por isso: o payload volta inteiro e a tela segue pela
     * entrega, abrindo a lista da área.
     */
    #[Test]
    public function a_framing_without_a_class_is_not_saved_and_keeps_the_payload(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: false));

        $this->fakeAction(SuggestCourtAddressing::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.', 'customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonPath('procedural_class', null)
            ->assertJsonPath('defendant.defendant_name', 'Joaquim Vizinho')
            ->assertJsonPath('legal_case_id', null);

        $this->assertSame(0, LegalCase::acrossAllAccounts()->count());
    }

    /**
     * A gravação é uma transação, e a que falha não leva o enquadramento.
     *
     * Um telefone que não cabe na coluna derruba o insert; nem a etapa 1 nem
     * os pedidos ficam para trás como meia peça, e o payload volta inteiro para
     * a tela seguir pela entrega.
     */
    #[Test]
    public function a_draft_that_cannot_be_saved_does_not_cost_the_framing(): void
    {
        [$account, $owner] = $this->accountWithOwner();
        $customer = Customer::factory()->forAccount($account)->create();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(
                practiceArea: PracticeArea::query()->where('slug', 'civil')->sole(),
                justification: 'O réu é um particular.',
            ));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(
                proceduralClass: ProceduralClass::query()->where('code', 7)->sole(),
                justification: 'O pedido é indenizatório.',
            ));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn(new DefendantData(
                name: 'Joaquim Vizinho',
                document: null,
                email: null,
                // Dois telefones colados: 22 dígitos numa coluna de 20.
                phone: '4733334444047999998888',
                postalCode: null,
                street: null,
                number: null,
                complement: null,
                district: null,
                city: null,
                state: null,
                notes: null,
            ));

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([
                new RequirementData(
                    id: null,
                    description: 'A condenação do Réu à reconstrução do muro derrubado;',
                    amount: null,
                ),
            ]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: false));

        $this->fakeAction(SuggestCourtAddressing::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.', 'customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('procedural_class.code', 7)
            ->assertJsonCount(1, 'requirements')
            ->assertJsonPath('legal_case_id', null);

        $this->assertSame(0, LegalCase::acrossAllAccounts()->count());
        $this->assertSame(0, Requirement::acrossAllAccounts()->count());
    }

    /**
     * O cliente é um id vindo do pedido: o de outra conta é recusado antes de
     * qualquer inferência.
     */
    #[Test]
    public function a_client_from_another_account_is_refused(): void
    {
        [, $owner] = $this->accountWithOwner();
        $foreign = Customer::factory()->forAccount(Account::factory()->create())->create();

        $this->fakeAction(ScreenLegalCaseFacts::class)->shouldNotReceive('handle');
        $this->fakeAction(ClassifyPracticeArea::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.', 'customer_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    /**
     * As cinco etapas do bloco, dubladas com as respostas de um caso de
     * vizinhança, para os testes que falam do que vem depois dele.
     *
     * @return array{0: PracticeArea, 1: ProceduralClass}
     */
    private function fakeTheBlock(): array
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $this->admit();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andReturn(new PracticeAreaClassification(practiceArea: $area, justification: 'O réu é um particular.'));

        $this->fakeAction(SelectProceduralClass::class)
            ->shouldReceive('handle')
            ->andReturn(new ProceduralClassSelection(proceduralClass: $class, justification: 'O pedido é indenizatório.'));

        $this->fakeAction(ExtractLegalCaseDefendant::class)
            ->shouldReceive('handle')
            ->andReturn($this->defendant());

        $this->fakeAction(ExtractLegalCaseRequirements::class)
            ->shouldReceive('handle')
            ->andReturn(new RequirementListData([]));

        $this->fakeAction(SuggestInjunctiveRelief::class)
            ->shouldReceive('handle')
            ->andReturn($this->suggestion(recommended: false));

        return [$area, $class];
    }

    /**
     * O réu que um relato de vizinhança dá: um nome, e mais nada.
     *
     * Doze campos e onze nulos é a resposta legítima do agente, não uma falha —
     * um réu é descrito, não cadastrado.
     */
    private function defendant(): DefendantData
    {
        return new DefendantData(
            name: 'Joaquim Vizinho',
            document: null,
            email: null,
            phone: null,
            postalCode: null,
            street: null,
            number: null,
            complement: null,
            district: null,
            city: null,
            state: null,
            notes: null,
        );
    }

    /**
     * Uma sugestão de tutela como `SuggestInjunctiveRelief` a devolve.
     *
     * A recusa é a resposta mais frequente de verdade, e é a que os testes que
     * não falam de tutela recebem: ela não muda nada do resto do payload.
     */
    private function suggestion(bool $recommended): InjunctiveReliefSuggestionData
    {
        return new InjunctiveReliefSuggestionData(
            recommended: $recommended,
            kind: $recommended ? InjunctiveReliefKind::Anticipatory : null,
            description: $recommended ? 'Medida pretendida: que se determine ao Réu a reconstrução do muro.' : null,
            justification: $recommended
                ? 'O muro derrubado deixa a casa aberta, e a espera pela sentença é o risco.'
                : 'O dano já se consumou e o relato não mostra nada que a demora agrave.',
            evidence: [],
            unsupportedAmounts: [],
            suggestedAt: '2026-09-27T12:00:00-03:00',
        );
    }

    /**
     * A triagem deixando o relato passar, para os testes que falam do que vem
     * depois dela.
     */
    private function admit(): void
    {
        $this->fakeAction(ScreenLegalCaseFacts::class)
            ->shouldReceive('handle')
            ->andReturn($this->screening());
    }

    /**
     * Uma triagem como `FactsScreeningData::fromAgent()` a devolveria — por
     * ele mesmo, para que o veredito saia da derivação e não de um palpite do
     * teste.
     */
    private function screening(bool $legalMatter = true, ?string $reason = null): FactsScreeningData
    {
        $screening = FactsScreeningData::fromAgent([
            'instructs_the_system' => false,
            'intelligible' => true,
            'legal_matter' => $legalMatter,
            'describes_events' => true,
            'states_claim' => true,
            'has_timeline' => false,
            'reason' => $reason ?? 'Narra o muro derrubado pelo vizinho.',
        ]);

        $this->assertSame(
            $legalMatter ? FactsScreeningVerdict::Admissible : FactsScreeningVerdict::NotLegal,
            $screening->verdict,
        );

        return $screening;
    }

    /**
     * Um dublê para uma Action `final`.
     *
     * `AsFake::mock()` faz `Mockery::mock(static::class)`, que o PHP recusa numa
     * classe final — e marcá-las assim é decisão do projeto, não obstáculo a
     * contornar no código de produção. O que resta é o mock por proxy, que
     * embrulha uma instância real em vez de estendê-la, registrado direto sob a
     * chave que o ActionManager consulta: `mock()` devolve o que já estiver lá,
     * e o `run()` estático passa a cair nele.
     */
    private function fakeAction(string $action): MockInterface
    {
        $fake = Mockery::mock(app($action));

        app()->instance('LaravelActions:AsFake:'.$action, $fake);

        return $fake;
    }
}
