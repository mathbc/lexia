<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\CourtAddressingSuggestionAgent;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Models\Customer;
use App\Domain\JudicialSystems\Actions\SelectJudicialSystem;
use App\Domain\JudicialSystems\Data\JudicialSystemSelection;
use App\Domain\LegalCases\Actions\Concerns\ValidatesLegalCaseBasics;
use App\Domain\LegalCases\Data\CourtAddressingSuggestionData;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\ForumPlace;
use App\Domain\LegalCases\Enums\CourtDivision;
use App\Domain\LegalCases\Enums\ForumSource;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\ForumBrief;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Rag\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Say to whom a framed matter's petição is addressed and through which system
 * it is filed, and get the line the first step offers the lawyer for it.
 *
 * Two agents in series, and the order is imposed. CourtAddressingSuggestionAgent
 * places the forum — the branch, the court, where the city comes from —, and
 * only then does SelectJudicialSystem know which state court's systems to
 * choose between: the same dependency that separates the area from the class.
 * The second one is usually not an inference at all. The map answers for a
 * court that runs one system, and the agent is asked only where it runs two.
 *
 * Two callers, the same arrangement as SuggestInjunctiveRelief. The first
 * step's "Consultar IA" posts what the form holds — the facts, the CNJ pair,
 * the client and whatever of the defendant is on the page —, because the
 * pleading may not exist yet. And ClassifyLegalCase calls it after its
 * concurrent block, with the defendant it has just extracted: the smart fill
 * asks for everything a narrative can give before the wizard opens.
 *
 * What fails, and what it costs. The first agent failing is the whole answer
 * failing — there is no addressing without a forum —, and the route answers
 * 503. The second one failing costs the select and never the addressing: it is
 * reported, the system comes back empty, and the warning says to choose it by
 * hand. A class that only runs where the list of courts does not reach — the
 * electoral or military courts, a tribunal's original competence — asks no one
 * and says so.
 */
final class SuggestCourtAddressing
{
    use AsAction;
    use ValidatesLegalCaseBasics;

    public function __construct(
        private readonly KnowledgeBase $knowledge,
    ) {}

    public function handle(
        PracticeArea $area,
        ?ProceduralClass $class,
        string $facts,
        Customer $customer,
        DefendantData $defendant,
    ): CourtAddressingSuggestionData {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para analisar.');
        }

        $divisions = CourtDivision::allowedBy($class->jurisdictions ?? []);

        if ($divisions === []) {
            return CourtAddressingSuggestionData::outOfReach(
                'A classe processual não é proposta num juízo de primeiro grau da Justiça Estadual, '
                .'Federal ou do Trabalho, e o endereçamento dela não é sugerido: escreva-o à mão.',
            );
        }

        $classLine = ForumBrief::classLine($class);
        $parties = ForumBrief::parties($customer, $defendant);
        $answer = $this->ask($area, $classLine, $divisions, $parties, $facts);

        $division = CourtDivision::tryFrom(self::field($answer, 'division'));
        $source = ForumSource::tryFrom(self::field($answer, 'forum_source'));
        $place = ForumPlace::resolve(
            $source,
            self::field($answer, 'city'),
            BrazilianState::tryFrom(self::field($answer, 'state')),
            ForumBrief::plaintiffPlace($customer),
            ForumBrief::defendantPlace($defendant),
            $facts,
        );

        return CourtAddressingSuggestionData::compose(
            division: $division,
            source: $source,
            place: $place,
            legalBasis: self::field($answer, 'legal_basis'),
            justification: self::field($answer, 'justification'),
            system: self::system($division, $place, $area, $classLine, $parties, $facts),
        );
    }

    /**
     * @param  list<CourtDivision>  $divisions
     * @param  list<string>  $parties
     * @return array<string, mixed>
     */
    private function ask(PracticeArea $area, ?string $classLine, array $divisions, array $parties, string $facts): array
    {
        $response = (new CourtAddressingSuggestionAgent(
            areaLabel: $area->label,
            classLine: $classLine,
            divisions: $divisions,
            states: array_column(BrazilianState::cases(), 'value'),
            parties: $parties,
            knowledge: $this->knowledge->get('forum-competence'),
        ))->prompt($facts);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de endereçamento não devolveu uma resposta estruturada.');
        }

        return $response->toArray();
    }

    /**
     * The system of the forum's state court, when the map can know it.
     *
     * Only the Justiça Estadual, because the map only has the state courts,
     * and only with a state. A failure here is reported and costs the select:
     * CourtAddressingSuggestionData says to choose it by hand.
     *
     * @param  list<string>  $parties
     */
    private static function system(
        ?CourtDivision $division,
        ForumPlace $place,
        PracticeArea $area,
        ?string $classLine,
        array $parties,
        string $facts,
    ): ?JudicialSystemSelection {
        if ($division === null || $division->branch() !== JusticeBranch::State || $place->state === null) {
            return null;
        }

        $forum = "Juízo: {$division->label()} ({$division->branch()->label()}); estado do foro: "
            .$place->state->label().'; cidade do foro: '.($place->city ?? 'não informada').'.';

        try {
            return SelectJudicialSystem::run($place->state, $forum, $area->label, $classLine, $parties, $facts);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * One key of the agent's answer as a trimmed string, empty when the
     * provider sent null or something that is not text.
     *
     * @param  array<string, mixed>  $answer
     */
    private static function field(array $answer, string $key): string
    {
        $value = $answer[$key] ?? null;

        return is_string($value) ? trim($value) : '';
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
     * O par área × classe e o cliente pelas regras da própria etapa 1 — o
     * cliente conferido contra a conta do ator —, e do réu só o que decide a
     * competência, tudo opcional: a etapa 2 pode nem ter sido aberta.
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
                ['customer_id', 'practice_area', 'procedural_class_id'],
            ),
            'defendant_name' => ['nullable', 'string', 'max:255'],
            'defendant_document' => ['nullable', 'string', 'max:20'],
            'defendant_city' => ['nullable', 'string', 'max:120'],
            'defendant_state' => ['nullable', Rule::enum(BrazilianState::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            ...$this->basicsAttributes(),
            'defendant_name' => 'nome do réu',
            'defendant_document' => 'documento do réu',
            'defendant_city' => 'cidade do réu',
            'defendant_state' => 'UF do réu',
        ];
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        $area = $this->area($request->string('practice_area')->toString());

        try {
            $suggestion = $this->handle(
                $area ?? throw new RuntimeException('Área de atuação desconhecida.'),
                ProceduralClass::query()->find($request->string('procedural_class_id')->toString()),
                $request->string('facts')->toString(),
                Customer::query()->findOrFail($request->string('customer_id')->toString()),
                DefendantData::fromArray($request->validated()),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Não foi possível sugerir o endereçamento agora. Tente novamente em instantes.',
            ], 503);
        }

        return response()->json($suggestion->toArray());
    }
}
