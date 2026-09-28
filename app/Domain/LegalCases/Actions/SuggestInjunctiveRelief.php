<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\InjunctiveReliefSuggestionAgent;
use App\Domain\LegalCases\Actions\Concerns\ValidatesLegalCaseBasics;
use App\Domain\LegalCases\Data\InjunctiveReliefSuggestionData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Rag\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Ask whether a framed matter should carry a *tutela de urgência*, and get the
 * text the first step offers the lawyer for it.
 *
 * Two callers, and the second is why this Action has a route when its sibling
 * extractions do not. ClassifyLegalCase calls it as the third link of the
 * framing chain — area, then class, then this —, because the smart fill asks
 * for everything a narrative can give before the wizard opens. And the first
 * step calls it on its own: the "Consultar IA" of a pleading built by hand, or
 * of one whose smart fill came back without a recommendation, and the "Gerar
 * novamente" of one that has a suggestion already. Those buttons live on a
 * screen where the pleading may not exist yet — a new one, in manual mode —, so
 * the route takes what the form holds, the facts and the CNJ pair, and not a
 * saved pleading. What comes back is JSON the screen applies to the form; the
 * save is still the step's own Continuar.
 *
 * The class is optional in `handle()` and required on the route. The chain may
 * reach here without one — the class selection is the step that can fail
 * alone —, and the answer is still worth having on the area and the facts. The
 * screen has no such excuse: the step cannot be saved without a class, so a
 * button that consulted without one would suggest for a pleading that does not
 * exist.
 *
 * Only the narrative, the area and the class travel. No party, no dossier: the
 * question is whether *this story* shows urgency, and the agent is told never
 * to name anyone.
 */
final class SuggestInjunctiveRelief
{
    use AsAction;
    use ValidatesLegalCaseBasics;

    public function __construct(
        private readonly KnowledgeBase $knowledge,
    ) {}

    public function handle(PracticeArea $area, ?ProceduralClass $class, string $facts): InjunctiveReliefSuggestionData
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para analisar.');
        }

        $response = (new InjunctiveReliefSuggestionAgent(
            areaLabel: $area->label,
            classLine: $class === null ? null : self::classLine($class),
            knowledge: $this->knowledge->get('injunctive-relief'),
        ))->prompt($facts);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de tutela de urgência não devolveu uma resposta estruturada.');
        }

        return InjunctiveReliefSuggestionData::fromAgent($response->toArray(), $facts);
    }

    /**
     * "[7] Procedimento Comum Cível — description. Base legal: …", the line the
     * class selection agent reads, because the operational description and the
     * statutes are what say whether the class carries a liminar of its own.
     */
    private static function classLine(ProceduralClass $class): string
    {
        $line = "[{$class->code}] {$class->name}";

        if ($class->description !== null && trim($class->description) !== '') {
            $line .= " — {$class->description}";
        }

        $bases = $class->citedLegalBases();

        return $bases === [] ? $line : $line.' Base legal: '.implode('; ', $bases).'.';
    }

    /**
     * Quem pode abrir uma peça pode pedir a sugestão para uma: é o mesmo gesto
     * do enquadramento, e a tela que chama pode estar montando uma peça nova.
     */
    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    /**
     * O par área × classe pelas regras da própria etapa 1, para que a sugestão
     * nunca seja pedida para um par que a etapa recusaria gravar.
     *
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return [
            'facts' => ['required', 'string'],
            ...Arr::only(
                $this->basicsRules(
                    $request->user()->account_id,
                    $request->string('practice_area')->toString(),
                ),
                ['practice_area', 'procedural_class_id'],
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->basicsAttributes();
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        $area = $this->area($request->string('practice_area')->toString());

        try {
            $suggestion = $this->handle(
                $area ?? throw new RuntimeException('Área de atuação desconhecida.'),
                ProceduralClass::query()->find($request->string('procedural_class_id')->toString()),
                $request->string('facts')->toString(),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Não foi possível analisar a urgência agora. Tente novamente em instantes.',
            ], 503);
        }

        return response()->json($suggestion->toArray());
    }
}
