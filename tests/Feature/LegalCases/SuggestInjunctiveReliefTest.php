<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Ai\Agents\InjunctiveReliefSuggestionAgent;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * O "Consultar IA" da tutela: `POST /pecas/tutela-de-urgencia/sugerir`.
 *
 * O agente é substituído pelo dublê do SDK, e o que se fixa é a fiação — o que
 * chega às instruções, o que volta no JSON, e o que acontece quando o agente
 * cai. O juízo sobre a urgência é de `tests/Agents/InjunctiveReliefSuggestionTest`.
 */
final class SuggestInjunctiveReliefTest extends TestCase
{
    use RefreshDatabase;

    private const string FACTS = 'O banco negativou meu nome por uma dívida que eu já paguei, '
        .'e o financiamento da casa é assinado semana que vem.';

    #[Test]
    public function the_suggestion_comes_back_composed_for_the_form(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$area, $class] = $this->pair();

        InjunctiveReliefSuggestionAgent::fake([[
            'recommended' => true,
            'kind' => 'anticipatory',
            'measure' => 'que se determine à Ré a exclusão do nome do Autor dos cadastros de proteção ao crédito',
            'legal_basis' => 'art. 300 do CPC',
            'probability' => 'A dívida já foi paga.',
            'danger' => 'O financiamento é assinado na semana que vem.',
            'reversibility' => 'A inscrição pode ser refeita.',
            'evidence' => ['Comprovante de pagamento'],
            'justification' => 'A negativação ameaça o financiamento marcado.',
        ]]);

        $this->actingAs($owner)
            ->postJson('/pecas/tutela-de-urgencia/sugerir', [
                'facts' => self::FACTS,
                'practice_area' => $area->slug,
                'procedural_class_id' => $class->id,
            ])
            ->assertOk()
            ->assertJsonPath('recommended', true)
            ->assertJsonPath('kind', 'anticipatory')
            ->assertJsonPath('kind_label', 'Antecipada')
            ->assertJsonPath('evidence', ['Comprovante de pagamento'])
            ->assertJsonPath('unsupported_amounts', [])
            ->assertJsonPath(
                'description',
                implode(PHP_EOL.PHP_EOL, [
                    'Medida pretendida: que se determine à Ré a exclusão do nome do Autor dos cadastros de proteção ao crédito.',
                    'Espécie: tutela de urgência antecipada.',
                    'Fundamento legal: art. 300 do CPC.',
                    'Probabilidade do direito: A dívida já foi paga.',
                    'Perigo de dano: O financiamento é assinado na semana que vem.',
                    'Reversibilidade: A inscrição pode ser refeita.',
                ]),
            );

        // Os fatos são o prompt; a área e a classe — com o código e o nome —
        // fecham as instruções, e o guia de tutela vai inteiro no meio.
        InjunctiveReliefSuggestionAgent::assertPrompted(static function (AgentPrompt $prompt) use ($area, $class): bool {
            $instructions = (string) $prompt->agent->instructions();

            return $prompt->prompt === self::FACTS
                && str_contains($instructions, "A área de atuação deste caso é **{$area->label}**.")
                && str_contains($instructions, "[{$class->code}] {$class->name}")
                && str_contains($instructions, '# Tutela de urgência — base de análise');
        });
    }

    /**
     * "Não" é resposta, e chega como tal: a tela desmarca a caixa e mostra por
     * quê, com o botão de consultar de novo.
     */
    #[Test]
    public function a_refusal_comes_back_as_an_answer(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$area, $class] = $this->pair();

        InjunctiveReliefSuggestionAgent::fake([[
            'recommended' => false,
            'kind' => null,
            'measure' => null,
            'legal_basis' => null,
            'probability' => null,
            'danger' => null,
            'reversibility' => null,
            'evidence' => [],
            'justification' => 'O dano já se consumou e nada na demora o agrava.',
        ]]);

        $this->actingAs($owner)
            ->postJson('/pecas/tutela-de-urgencia/sugerir', [
                'facts' => 'O vizinho derrubou o muro no ano passado.',
                'practice_area' => $area->slug,
                'procedural_class_id' => $class->id,
            ])
            ->assertOk()
            ->assertJsonPath('recommended', false)
            ->assertJsonPath('description', null)
            ->assertJsonPath('justification', 'O dano já se consumou e nada na demora o agrava.');
    }

    /**
     * Inferência é cara: sem fatos, ou com uma classe que a área não aceita, o
     * validador recusa antes de o agente acordar — a mesma regra de par que a
     * etapa 1 aplica ao gravar.
     */
    #[Test]
    public function an_incomplete_form_is_refused_before_any_inference(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$area] = $this->pair();
        $foreign = PracticeArea::query()->where('slug', 'penal')->sole()
            ->proceduralClasses()->where('is_filing_class', true)
            ->whereNotIn('procedural_classes.id', $area->proceduralClasses()->pluck('procedural_classes.id'))
            ->firstOrFail();

        InjunctiveReliefSuggestionAgent::fake([])->preventStrayPrompts();

        $this->actingAs($owner)
            ->postJson('/pecas/tutela-de-urgencia/sugerir', [
                'facts' => '',
                'practice_area' => $area->slug,
                'procedural_class_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['facts', 'procedural_class_id']);

        InjunctiveReliefSuggestionAgent::assertNeverPrompted();
    }

    #[Test]
    public function an_agent_that_fails_answers_with_a_message_the_screen_can_show(): void
    {
        [, $owner] = $this->accountWithOwner();
        [$area, $class] = $this->pair();

        InjunctiveReliefSuggestionAgent::fake(static fn (): never => throw new RuntimeException('Connection refused'));

        $this->actingAs($owner)
            ->postJson('/pecas/tutela-de-urgencia/sugerir', [
                'facts' => self::FACTS,
                'practice_area' => $area->slug,
                'procedural_class_id' => $class->id,
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Não foi possível analisar a urgência agora. Tente novamente em instantes.');
    }

    #[Test]
    public function a_guest_is_sent_to_the_login(): void
    {
        $this->post('/pecas/tutela-de-urgencia/sugerir', ['facts' => 'Qualquer coisa.'])
            ->assertRedirect('/login');
    }

    /**
     * @return array{0: PracticeArea, 1: ProceduralClass}
     */
    private function pair(): array
    {
        $area = PracticeArea::query()->where('slug', 'consumidor')->sole();

        return [$area, $area->proceduralClasses()->where('is_filing_class', true)->firstOrFail()];
    }
}
