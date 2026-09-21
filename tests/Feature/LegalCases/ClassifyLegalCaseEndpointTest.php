<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Actions\ExtractLegalCaseDefendant;
use App\Domain\LegalCases\Actions\ExtractLegalCaseRequirements;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\PracticeAreas\Data\PracticeAreaClassification;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Data\RequirementData;
use App\Domain\Requirements\Data\RequirementListData;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Todo teste que chega à inferência dubla as quatro: uma que ficasse de fora
 * sairia daqui direto para o modelo, e um teste da suíte padrão passaria a
 * depender dele.
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
            // A revisão forense **não** está aqui, e a ausência é contrato: a
            // pesquisa saiu desta rota porque não tem onde gravar o que acha
            // enquanto a peça não tem chave primária. Quem a roda hoje é
            // `ResearchLegalCaseForensicReview`, ao abrir a etapa 6.
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

        $this->fakeAction(ClassifyPracticeArea::class)->shouldNotReceive('handle');
        $this->fakeAction(SelectProceduralClass::class)->shouldNotReceive('handle');
        $this->fakeAction(ExtractLegalCaseDefendant::class)->shouldNotReceive('handle');
        $this->fakeAction(ExtractLegalCaseRequirements::class)->shouldNotReceive('handle');
        $this->fakeAction(ResearchLegalCaseTheses::class)->shouldNotReceive('handle');

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => ''])
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
     * A cadeia que sobrou: área, depois classe, depois pesquisa.
     *
     * O que se verifica aqui não é o resultado, é a **dependência** — e ela é o
     * que restou de afirmável depois que as quatro etapas básicas passaram a
     * correr dentro de um `Concurrency::run`. `globally()->ordered()` reprova
     * uma etapa que rode antes da anterior ter voltado, e as três que o
     * carregam são as três que não podem se reordenar: as classes candidatas
     * são as da área, e a pesquisa lê a peça que as outras descreveram.
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
     * Hoje quem a roda é `ResearchLegalCaseForensicReview`, ao abrir a etapa 6,
     * sobre uma peça que já tem chave primária para receber o resultado.
     * Religá-la aqui traria os dois problemas de volta de uma vez, e sem barulho
     * nenhum: a suíte padrão passaria a gastar cota do Gemini e a abrir o STJ de
     * verdade, ficando vermelha por um portal fora do ar em vez de por um bug.
     */
    #[Test]
    public function the_classification_never_researches_and_never_leaves_the_machine(): void
    {
        [, $owner] = $this->accountWithOwner();

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

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('practice_area.slug', 'civil')
            ->assertJsonMissingPath('research');
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
