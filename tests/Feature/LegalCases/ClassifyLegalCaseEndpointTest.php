<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Actions\ExtractLegalCaseDefendant;
use App\Domain\LegalCases\Actions\ExtractLegalCaseRequirements;
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
 * Os quatro agentes são substituídos por dublês, e é o ponto. O que se verifica
 * aqui é a rota — autorização, validação, a forma da resposta e o que acontece
 * quando a inferência falha —, não a qualidade da classificação; essa vive em
 * `tests/Agents`, exige o Ollama de pé e custa segundos por caso.
 *
 * Todo teste que chega à inferência dubla os quatro: um que ficasse de fora
 * sairia daqui direto para o Ollama, e um teste da suíte padrão passaria a
 * depender dele estar de pé.
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
            ->assertJsonPath('requirements.1.amount', '4300.00');
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

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('facts');
    }

    /**
     * O Ollama fora do ar é condição de operação, não defeito: a tela precisa
     * de uma frase para mostrar, e de um status que não seja 200.
     */
    #[Test]
    public function an_agent_that_fails_answers_with_a_message_the_screen_can_show(): void
    {
        [, $owner] = $this->accountWithOwner();

        $this->fakeAction(ClassifyPracticeArea::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Connection refused'));

        // As duas extrações vêm depois do enquadramento, e por isso não chegam
        // a ser acordadas quando o primeiro agente cai.
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

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        $this->post('/pecas/classificar', ['facts' => 'Qualquer coisa.'])
            ->assertRedirect('/login');
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
