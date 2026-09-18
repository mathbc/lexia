<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\PracticeAreas\Data\PracticeAreaClassification;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A casca HTTP do enquadramento: `POST /pecas/classificar`.
 *
 * Os dois agentes são substituídos por dublês, e é o ponto. O que se verifica
 * aqui é a rota — autorização, validação, a forma da resposta e o que acontece
 * quando a inferência falha —, não a qualidade da classificação; essa vive em
 * `tests/Agents`, exige o Ollama de pé e custa segundos por caso.
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
            ->assertJsonPath('procedural_class_justification', 'O pedido é indenizatório e não há rito próprio.');
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

        $this->actingAs($owner)
            ->postJson('/pecas/classificar', ['facts' => 'O vizinho derrubou o muro.'])
            ->assertOk()
            ->assertJsonPath('procedural_class', null)
            ->assertJsonPath('procedural_class_justification', null);
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
