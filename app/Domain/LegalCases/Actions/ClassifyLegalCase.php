<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\Requirements\Data\RequirementListData;
use Closure;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Read the facts of a matter: frame it, describe who is on the other side, and
 * write out what is being asked of the court.
 *
 * Four inferences, of which only the first two are a chain. The classes a case
 * may be filed under are the ones linked to its area, so the list the second
 * agent chooses from cannot exist until the first has answered — that order is
 * forced rather than chosen. Each of the two constrains its own answer with an
 * `enum`, which Ollama turns into a grammar: neither an invented area nor an
 * invented class is something the model is able to emit.
 *
 * The other two owe nothing to the framing and nothing to each other.
 * `ExtractLegalCaseDefendant` reads the same facts and answers who is being
 * sued; `ExtractLegalCaseRequirements` reads them and answers what the client
 * wants. Both are appended here rather than chained, and both stay callable on
 * their own for the screen that wants just one of them. What puts them in this
 * Action is the caller: the preenchimento inteligente asks for everything a
 * narrative can give before opening the wizard, and a round trip per step would
 * make the lawyer wait three times for one gesture.
 *
 * All four share one model on purpose — the later calls find it loaded.
 *
 * ## A casca HTTP
 *
 * `POST /pecas/classificar` devolve `LegalCaseClassification::toArray()` como
 * JSON, e não uma resposta do Inertia: quem chama é a tela de preenchimento
 * inteligente, que precisa do resultado em mãos para carregá-lo até o
 * assistente — a navegação vem depois, e é do browser.
 *
 * O preço continua sendo a latência, e ela cresce a cada agente acrescentado:
 * são quatro chamadas a `Timeout(180)`, e o navegador espera pelas quatro. É
 * uma dívida conhecida e o lugar dela é aqui — quando a espera passar a ser uma
 * fila, é este `asController()` que devolve um identificador em vez do
 * resultado, e o `handle()` não muda uma linha. As duas últimas, por não
 * dependerem de nada, são também as duas que um dia podem correr em paralelo.
 *
 * Um agente fora do ar é condição de operação, não defeito de código: daí o
 * 503 com uma frase que a tela consegue mostrar, e o `report()` para que a
 * causa real continue chegando ao log.
 */
final class ClassifyLegalCase
{
    use AsAction;

    public function handle(string $facts): LegalCaseClassification
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para classificar.');
        }

        $area = ClassifyPracticeArea::run($facts);

        // A wrong area makes the class wrong by construction, and this step has
        // no way to object. Both justifications travel separately so the lawyer
        // can see which of the two decisions to distrust.
        $class = SelectProceduralClass::run($area->practiceArea, $facts);

        // Os argumentos são avaliados na ordem em que estão escritos, e é ela
        // que decide quem roda primeiro: as duas extrações são independentes
        // entre si, mas ambas vêm depois do enquadramento — um `throw` lá
        // atrás dispensa as duas chamadas.
        return new LegalCaseClassification(
            practiceArea: $area->practiceArea,
            practiceAreaJustification: $area->justification,
            proceduralClass: $class?->proceduralClass,
            proceduralClassJustification: $class?->justification,
            defendant: $this->suggestion(
                static fn (): DefendantData => ExtractLegalCaseDefendant::run($facts),
            ),
            requirements: $this->suggestion(
                static fn (): RequirementListData => ExtractLegalCaseRequirements::run($facts),
            ),
        );
    }

    /**
     * Uma inferência que pode faltar sem levar as outras junto.
     *
     * O enquadramento é o que a tela foi buscar; o réu e os pedidos são o que
     * ela ganha de brinde. Deixar uma falha numa delas derrubar a resposta
     * inteira cobraria do advogado os minutos das inferências que já deram
     * certo, para devolver a mesma tela de erro que ele veria sem nenhuma.
     *
     * O `report()` é o que separa isto de engolir o erro: a causa continua
     * chegando ao log, e o nulo diz à tela que não há sugestão — e não que o
     * relato não descreve ninguém nem que ele não pede nada. Essas duas outras
     * coisas são doze nulos dentro de um `DefendantData` e uma lista vazia
     * dentro de um `RequirementListData`.
     *
     * @template TSuggestion
     *
     * @param  Closure(): TSuggestion  $read
     * @return TSuggestion|null
     */
    private function suggestion(Closure $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Quem pode abrir uma peça pode pedir o enquadramento de uma: é o mesmo
     * gesto, e a rota é a porta curta para o mesmo formulário.
     */
    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facts' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'facts' => 'fatos',
        ];
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        try {
            $classification = $this->handle($request->string('facts')->toString());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Não foi possível enquadrar o caso agora. Tente novamente em instantes.',
            ], 503);
        }

        return response()->json($classification->toArray());
    }
}
