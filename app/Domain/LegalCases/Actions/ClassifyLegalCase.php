<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Read the facts of a matter and frame it: practice area and procedural class.
 *
 * Two inferences in sequence, and the order is forced rather than chosen. The
 * classes a case may be filed under are the ones linked to its area, so the
 * list the second agent chooses from cannot exist until the first has answered.
 * Each step constrains its own answer with an `enum`, which Ollama turns into a
 * grammar — the guarantee that neither an invented area nor an invented class
 * is something the model is able to emit.
 *
 * Both steps share one model on purpose — the second call then finds it already
 * loaded.
 *
 * ## A casca HTTP
 *
 * `POST /pecas/classificar` devolve `LegalCaseClassification::toArray()` como
 * JSON, e não uma resposta do Inertia: quem chama é a tela de preenchimento
 * inteligente, que precisa do enquadramento em mãos para carregá-lo até o
 * assistente — a navegação vem depois, e é do browser.
 *
 * O preço continua sendo a latência: são duas chamadas a `Timeout(180)`, e o
 * navegador espera pelas duas. É uma dívida conhecida e o lugar dela é aqui —
 * quando a espera passar a ser uma fila, é este `asController()` que devolve um
 * identificador em vez do resultado, e o `handle()` não muda uma linha.
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

        return new LegalCaseClassification(
            practiceArea: $area->practiceArea,
            practiceAreaJustification: $area->justification,
            proceduralClass: $class?->proceduralClass,
            proceduralClassJustification: $class?->justification,
        );
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
