<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\CourtDecisions\Data\CourtDecisionListData;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the case law a pleading cites — the seventh step.
 *
 * The list is written whole and reconciled as a diff, exactly as
 * `SaveLegalCaseRequirements` writes the fourth step and
 * `SaveLegalCaseForensicReview` writes the sixth: rows that stayed are updated,
 * rows that left are removed, rows that arrived are created.
 *
 * **The posted id is a hint and never a key.** It is looked up among the rows
 * this pleading actually owns, and anything else — an id from another account,
 * an id the browser invented — simply is not found and becomes a new row. That
 * is what makes the reconciliation tenant-safe without a single check about
 * accounts in it.
 *
 * This is where a lawyer's unticking becomes a deletion. The research writes
 * every ruling it confirmed; the seventh step ticks them all and lets the
 * lawyer untick what does not serve, and the untick lives in the browser until
 * `FinalizeLegalCase` posts the list without it. Removing rather than flagging
 * is deliberate and is the sibling's decision too: a pleading cites the rulings
 * it cites, and a table of rows nobody kept would be a second state to filter
 * everywhere the first is read. The lawyer who changes their mind presses
 * "Pesquisar novamente".
 *
 * One thing it does **not** do, and the omission is the difference from the
 * sixth step: there is no map from posted id to persisted id here. A court
 * decision is nothing's parent — no row points at it — so a fresh one has
 * nobody waiting for its key.
 *
 * No `asController()`: no route points here. The two callers are
 * `ResearchLegalCaseJurisprudence`, which writes what the agents confirmed, and
 * `FinalizeLegalCase`, which writes what survived the lawyer's reading — and
 * the `rules()` below are what the second one validates with, delegated rather
 * than restated so the two cannot drift.
 */
final class SaveLegalCaseCourtDecisions
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, CourtDecisionListData $data): LegalCase
    {
        return DB::transaction(function () use ($legalCase, $data): LegalCase {
            // As linhas que esta peça de fato possui — a relação já filtra por
            // legal_case_id e o AccountScope a estreita à conta. Um id vindo de
            // fora simplesmente não está aqui.
            $existing = $legalCase->courtDecisions()->get()->keyBy('id');

            /** @var list<string> $kept */
            $kept = [];

            foreach ($data->decisions as $decision) {
                $row = $existing->get((string) $decision->id) ?? $this->blank($legalCase);

                // toArray() não devolve `id`: a chave de uma linha nova é
                // cunhada pelo HasUuids, nunca colada do payload.
                $row->fill($decision->toArray())->save();

                $kept[] = $row->id;
            }

            // Escopado à relação: só alcança os julgados desta peça.
            $legalCase->courtDecisions()->whereNotIn('id', $kept)->delete();

            // A última etapa do assistente: `next()` satura nela, e nomeá-la diz
            // a mesma coisa sem o enigma.
            $this->advanceTo($legalCase, LegalCaseStep::CourtDecisions);

            return $legalCase->refresh();
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, e não o julgado: a jurisprudência não tem rota própria, e quem
        // pode escrever a peça pode escrever o que ela cita. Ver
        // CourtDecisionPolicy.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * The empty list is valid — it is how a lawyer unticks everything, and how
     * a search that confirmed nothing comes back.
     *
     * Nothing here repeats the portal guard, and that is on purpose: a rule
     * would answer with a validation error on a field the lawyer never typed
     * into, refusing the whole conclusion of a pleading over one bad row that
     * the agent produced. `CourtDecisionListData` drops it instead, which is
     * the same call `RequirementListData` makes about a blank request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'court_decisions' => ['present', 'array'],
            'court_decisions.*.id' => ['nullable', 'uuid'],
            'court_decisions.*.title' => ['nullable', 'string'],
            'court_decisions.*.locality' => ['nullable', 'string'],
            'court_decisions.*.authority' => ['nullable', 'string'],
            'court_decisions.*.summary' => ['nullable', 'string'],
            'court_decisions.*.subject' => ['nullable', 'string'],
            'court_decisions.*.source_url' => ['nullable', 'string'],
            'court_decisions.*.urn' => ['nullable', 'string'],
            'court_decisions.*.decided_at' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'court_decisions' => 'jurisprudências',
            'court_decisions.*.title' => 'título do julgado',
            'court_decisions.*.locality' => 'localidade',
            'court_decisions.*.authority' => 'autoridade',
            'court_decisions.*.summary' => 'ementa',
            'court_decisions.*.subject' => 'assuntos',
            'court_decisions.*.source_url' => 'fonte',
            'court_decisions.*.urn' => 'URN',
            'court_decisions.*.decided_at' => 'data do julgamento',
        ];
    }

    /**
     * A row that does not exist yet, already told where it lives.
     *
     * The account comes from the pleading and not from the actor: it is the
     * pleading that says which tenant the citation belongs to.
     */
    private function blank(LegalCase $legalCase): CourtDecision
    {
        return new CourtDecision([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }
}
