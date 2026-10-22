<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\Doctrines\Data\DoctrineListData;
use App\Domain\Doctrines\Models\Doctrine;
use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the doctrine a pleading leans on — the third tab of the fifth step.
 *
 * SaveLegalCaseCourtDecisions for scholarship: the list is written whole and
 * reconciled as a diff, the posted id is a hint looked up among the rows this
 * pleading owns and never a key, and a work the lawyer unticked becomes a
 * deletion when `FinalizeLegalCase` posts the list without it. No id map,
 * because a doctrine is nothing's parent.
 *
 * No `asController()`: the two callers are ResearchLegalCaseForensicReview,
 * which writes what the LexML confirmed, and FinalizeLegalCase, which writes
 * what survived the lawyer's reading and validates with the `rules()` below.
 */
final class SaveLegalCaseDoctrines
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, DoctrineListData $data): LegalCase
    {
        return DB::transaction(function () use ($legalCase, $data): LegalCase {
            // As linhas que esta peça de fato possui: um id vindo de fora
            // simplesmente não está aqui e vira linha nova.
            $existing = $legalCase->doctrines()->get()->keyBy('id');

            /** @var list<string> $kept */
            $kept = [];

            foreach ($data->doctrines as $position => $doctrine) {
                $row = $existing->get((string) $doctrine->id) ?? $this->blank($legalCase);

                // A posição é o índice na lista, como nos temas: a ordem do
                // agente na pesquisa, e a da tela no "Concluir".
                $row->fill([...$doctrine->toArray(), 'position' => $position])->save();

                $kept[] = $row->id;
            }

            $legalCase->doctrines()->whereNotIn('id', $kept)->delete();

            $this->advanceTo($legalCase, LegalCaseStep::Review);

            return $legalCase->refresh();
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * The empty list is valid — it is how a lawyer unticks everything, and how a
     * search that confirmed nothing comes back. The portal and author guards are
     * not rules, for the sibling's reason: DoctrineListData drops a bad row
     * instead of refusing the whole conclusion over it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doctrines' => ['present', 'array'],
            'doctrines.*.id' => ['nullable', 'uuid'],
            'doctrines.*.kind' => ['nullable', 'string'],
            'doctrines.*.author' => ['nullable', 'string'],
            'doctrines.*.title' => ['nullable', 'string'],
            'doctrines.*.published_in' => ['nullable', 'string'],
            'doctrines.*.summary' => ['nullable', 'string'],
            'doctrines.*.subject' => ['nullable', 'string'],
            'doctrines.*.publication' => ['nullable', 'string'],
            'doctrines.*.relevance' => ['nullable', 'string'],
            'doctrines.*.source_url' => ['nullable', 'string'],
            'doctrines.*.urn' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'doctrines' => 'doutrinas',
            'doctrines.*.kind' => 'tipo da obra',
            'doctrines.*.author' => 'autor',
            'doctrines.*.title' => 'título da obra',
            'doctrines.*.published_in' => 'ano de publicação',
            'doctrines.*.summary' => 'sumário',
            'doctrines.*.subject' => 'assuntos',
            'doctrines.*.publication' => 'publicação',
            'doctrines.*.relevance' => 'relação com o caso',
            'doctrines.*.source_url' => 'fonte',
            'doctrines.*.urn' => 'URN',
        ];
    }

    /**
     * A row that does not exist yet, already told where it lives.
     *
     * The account comes from the pleading and not from the actor, as on
     * SaveLegalCaseCourtDecisions: the research writes from a child process,
     * where there is no actor at all.
     */
    private function blank(LegalCase $legalCase): Doctrine
    {
        return new Doctrine([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }
}
