<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions\Concerns;

use App\Domain\LegalCases\Data\LegalCaseBasicsData;
use App\Domain\PracticeAreas\Models\PracticeArea;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the creation of a pleading and the re-saving of its
 * first step.
 *
 * Two callers, one trait — the same arrangement, and the same reason, as
 * ValidatesCustomer. The defendant, the facts and the requests have one caller
 * each and keep their rules in their own Action.
 *
 * Two invariants live here that the database cannot hold:
 *
 * 1. **The class must belong to the area.** They are independent foreign keys
 *    and the valid pairs live in the pivot; checking it in the schema would
 *    mean a composite key into it. The migration says as much, and points at
 *    the Action.
 * 2. **The client must belong to the actor's account.** The scope would already
 *    narrow a read, but this is a write taking an id from the request, and for
 *    platform staff the scope is open.
 */
trait ValidatesLegalCaseBasics
{
    /**
     * Resolved once per request: `rules()` needs the area to check the pair,
     * and the Action needs its uuid to build the DTO.
     */
    private ?PracticeArea $resolvedArea = null;

    /**
     * @return array<string, mixed>
     */
    protected function basicsRules(string $accountId, ?string $areaSlug): array
    {
        return [
            'customer_id' => [
                'required',
                'uuid',
                Rule::exists('customers', 'id')
                    ->where('account_id', $accountId)
                    ->whereNull('deleted_at'),
            ],

            // O slug, nunca o uuid: é assim que LegalCaseOptions publica as
            // áreas, porque o uuid é gerado no load e difere entre bancos.
            'practice_area' => ['required', 'string', Rule::exists('practice_areas', 'slug')],

            // O par área × classe vive no pivot. Um slug desconhecido faz este
            // `where` comparar com null e não casar nada, que é a recusa certa
            // — e sem um ramo a mais para isso.
            'procedural_class_id' => [
                'required',
                'uuid',
                Rule::exists('practice_area_procedural_class', 'procedural_class_id')
                    ->where('practice_area_id', $this->area($areaSlug)?->id),
            ],

            'court_addressing' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function basicsAttributes(): array
    {
        return [
            'customer_id' => 'cliente',
            'practice_area' => 'área de atuação',
            'procedural_class_id' => 'classe processual',
            'court_addressing' => 'endereçamento',
        ];
    }

    /**
     * The validated payload as a value, with the area's slug traded for its id.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function basicsData(array $validated): LegalCaseBasicsData
    {
        $area = $this->area((string) $validated['practice_area']);

        return LegalCaseBasicsData::fromArray($validated, (string) $area?->id);
    }

    private function area(?string $slug): ?PracticeArea
    {
        return $this->resolvedArea ??= PracticeArea::query()
            ->where('slug', (string) $slug)
            ->first();
    }
}
