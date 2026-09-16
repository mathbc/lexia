<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Requirements\Data\RequirementListData;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves what the pleading asks the court for — the fourth step.
 *
 * The list is written whole, not row by row: the screen holds it as a list, the
 * step submits it as one, and the result is a diff — rows that stayed are
 * updated, rows that left are removed, rows that arrived are created.
 *
 * **The posted id is a hint and never a key.** The form mints uuids with
 * `crypto.randomUUID()` for rows it has just opened, and they are v4 exactly
 * like the server's — indistinguishable by shape, so any rule trying to tell
 * them apart is wrong by construction. Membership is the only safe test: an id
 * is looked up among the rows this pleading actually owns, and anything else —
 * a fresh client uuid, an id belonging to another account — simply is not found
 * and becomes a new row. That is what makes the reconciliation tenant-safe
 * without a single check about accounts in it.
 *
 * Two consequences worth knowing. Re-posting the id of a request already
 * removed creates a new row rather than resurrecting the old one, because the
 * lookup does not see soft-deleted rows — bringing back an amount somebody
 * deleted would be worse than a duplicate id. And the order is the order the
 * rows were posted in, which is the order they were written and the order they
 * will be numbered; there is still no position column, and none is invented
 * here.
 */
final class SaveLegalCaseRequirements
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, RequirementListData $data): LegalCase
    {
        return DB::transaction(function () use ($legalCase, $data): LegalCase {
            // As linhas que esta peça de fato possui — a relação já filtra por
            // legal_case_id e o AccountScope a estreita à conta. Um id vindo de
            // fora simplesmente não está aqui.
            $existing = $legalCase->requirements()->get()->keyBy('id');

            /** @var list<string> $kept */
            $kept = [];

            foreach ($data->requirements as $requirement) {
                $row = $existing->get((string) $requirement->id) ?? $this->blank($legalCase);

                // toArray() não devolve `id`: a chave de uma linha nova é
                // cunhada pelo HasUuids, nunca colada do payload.
                $row->fill($requirement->toArray())->save();

                $kept[] = $row->id;
            }

            // Escopado à relação: só alcança os pedidos desta peça.
            $legalCase->requirements()->whereNotIn('id', $kept)->delete();

            $this->advanceTo($legalCase, LegalCaseStep::Requirements->next());

            return $legalCase->refresh();
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, e não o pedido: os pedidos não têm rota própria, e quem pode
        // escrever a peça pode escrever o que ela pede. Ver RequirementPolicy.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * The empty list is valid — it is how a lawyer clears the step — and so is
     * a request with no amount, which is the majority: waiving court fees and
     * serving the defendant are worth nothing in reais.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requirements' => ['present', 'array'],
            'requirements.*.id' => ['nullable', 'uuid'],
            'requirements.*.description' => ['nullable', 'string'],
            'requirements.*.amount' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'requirements' => 'pedidos',
            'requirements.*.description' => 'pedido',
            'requirements.*.amount' => 'valor',
        ];
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, RequirementListData::fromArray($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Requirements->next()->value,
        ]);
    }

    /**
     * A row that does not exist yet, already told where it lives.
     *
     * The account comes from the pleading and not from the actor: it is the
     * pleading that says which tenant the request belongs to.
     */
    private function blank(LegalCase $legalCase): Requirement
    {
        return new Requirement([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }
}
