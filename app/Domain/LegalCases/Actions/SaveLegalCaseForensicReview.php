<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\AdvancesLegalCaseStep;
use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Models\LegalThesis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves what the pleading argues and what sustains it — the sixth step.
 *
 * Two lists, written whole and reconciled as a diff, exactly as
 * SaveLegalCaseRequirements writes the fourth step: rows that stayed are
 * updated, rows that left are removed, rows that arrived are created. **The
 * posted id is a hint and never a key** — the form mints uuids with
 * `crypto.randomUUID()` and they are v4 just like the server's, so membership
 * among the rows this pleading actually owns is the only safe test.
 *
 * One Action and not two, and the reason is the whole of this class. A precedent
 * carries the id of the thesis it sustains, and that id can name a thesis
 * created in this very submission — whose real key does not exist until it is
 * written, because `blank()` discards the posted one and HasUuids mints its own.
 * So the theses are saved first and their saving yields a **map from the posted
 * id to the persisted one**; the precedent's foreign key is resolved through
 * that map and never taken from the payload. Two independent Actions would have
 * nowhere to keep the map, and two requests would need a contract for echoing
 * the new ids back that does not exist.
 *
 * The map is also the tenant guard, and it costs nothing extra. It holds only
 * the theses this save just wrote, so every other case lands in null for free:
 * a thesis from another account — which the database would accept happily, since
 * a foreign key checks existence and not ownership — a thesis from a sibling
 * pleading, and a thesis the lawyer removed in this same submission. The
 * precedent survives with nothing to ground, which is a visible state somebody
 * fixes, rather than a link to somewhere it should not reach.
 *
 * One line in `saveTheses()` earns its own sentence: the map keys on
 * `$thesis->id ?? $row->id`. Without the fallback, two brand-new theses both key
 * on the empty string, the second overwrites the first, and the first is deleted
 * by `whereNotIn` microseconds after being created.
 *
 * Note that the foreign key's `nullOnDelete` does almost none of this work.
 * LegalThesis is soft-deleted, so removing one only writes `deleted_at` and the
 * database never consults the constraint; the map is what unhooks the precedent.
 * The constraint is the net for a hard delete.
 */
final class SaveLegalCaseForensicReview
{
    use AdvancesLegalCaseStep;
    use AsAction;

    public function handle(LegalCase $legalCase, ForensicReviewData $data): LegalCase
    {
        return DB::transaction(function () use ($legalCase, $data): LegalCase {
            // As teses primeiro: a linha precisa existir antes que um precedente
            // possa apontar para ela.
            $theses = $this->saveTheses($legalCase, $data);

            $this->savePrecedents($legalCase, $data, $theses);

            // Review é a última etapa e `next()` satura nela; nomeá-la diz a
            // mesma coisa sem o enigma.
            $this->advanceTo($legalCase, LegalCaseStep::Review);

            return $legalCase->refresh();
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, e não a tese: nem a tese nem o precedente têm rota própria, e
        // quem pode escrever a peça pode escrever o que ela argumenta.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Both empty lists are valid — it is how a lawyer clears the step — and so
     * is a precedent grounding no thesis, which is how a ruling found before the
     * argument it will sustain arrives.
     *
     * `precedents.*.legal_thesis_id` carries no `exists` rule, and not by
     * omission: a thesis created in this same submission does not exist yet, and
     * the rule would happily answer "that row exists" about another account's
     * thesis. Existence is not the question — membership is, and `handle()`
     * answers it with the map.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'theses' => ['present', 'array'],
            'theses.*.id' => ['nullable', 'uuid'],
            'theses.*.name' => ['nullable', 'string'],
            'theses.*.type' => ['nullable', Rule::enum(LegalThesisType::class)],
            'theses.*.description' => ['nullable', 'string'],
            'theses.*.impact' => ['nullable', 'string'],
            'theses.*.legal_bases' => ['nullable', 'array'],
            'theses.*.legal_bases.*.type' => ['nullable', Rule::enum(LegalBasisType::class)],
            'theses.*.legal_bases.*.reference' => ['nullable', 'string', 'max:255'],
            'theses.*.legal_bases.*.source' => ['nullable', 'string', 'max:60'],

            'precedents' => ['present', 'array'],
            'precedents.*.id' => ['nullable', 'uuid'],
            'precedents.*.legal_thesis_id' => ['nullable', 'uuid'],
            'precedents.*.name' => ['nullable', 'string'],
            'precedents.*.type' => ['nullable', Rule::enum(LegalPrecedentType::class)],
            'precedents.*.description' => ['nullable', 'string'],
            'precedents.*.citation' => ['nullable', 'string'],
            'precedents.*.grounding' => ['nullable', 'string'],
            'precedents.*.adherence' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'theses' => 'teses',
            'theses.*.name' => 'tese',
            'theses.*.type' => 'tipo da tese',
            'theses.*.description' => 'descrição da tese',
            'theses.*.impact' => 'impacto da tese',
            'theses.*.legal_bases' => 'fundamentos',
            'theses.*.legal_bases.*.type' => 'tipo do fundamento',
            'theses.*.legal_bases.*.reference' => 'fundamento',
            'theses.*.legal_bases.*.source' => 'origem do fundamento',
            'precedents' => 'precedentes',
            'precedents.*.name' => 'precedente',
            'precedents.*.type' => 'tipo do precedente',
            'precedents.*.description' => 'ementa do precedente',
            'precedents.*.citation' => 'citação',
            'precedents.*.grounding' => 'fundamentação',
            'precedents.*.adherence' => 'aderência',
        ];
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, ForensicReviewData::fromArray($request->validated()));

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Review->value,
        ]);
    }

    /**
     * The theses, reconciled, and the map their saving produced.
     *
     * @return array<string, string> posted id => persisted id
     */
    private function saveTheses(LegalCase $legalCase, ForensicReviewData $data): array
    {
        // As linhas que esta peça de fato possui — a relação já filtra por
        // legal_case_id e o AccountScope a estreita à conta. Um id vindo de fora
        // simplesmente não está aqui.
        $existing = $legalCase->theses()->get()->keyBy('id');

        /** @var array<string, string> $saved */
        $saved = [];

        foreach ($data->theses as $thesis) {
            $row = $existing->get((string) $thesis->id) ?? $this->blankThesis($legalCase);

            // toArray() não devolve `id`: a chave de uma linha nova é cunhada
            // pelo HasUuids, nunca colada do payload.
            $row->fill($thesis->toArray())->save();

            // A chave é o palpite que veio, porque é por ele que um precedente
            // aponta. Sem palpite a linha é inalcançável e mapeia para si mesma,
            // que é o que impede duas teses novas de colidirem na string vazia.
            $saved[$thesis->id ?? $row->id] = $row->id;
        }

        $legalCase->theses()->whereNotIn('id', array_values($saved))->delete();

        return $saved;
    }

    /**
     * @param  array<string, string>  $theses  posted id => persisted id
     */
    private function savePrecedents(LegalCase $legalCase, ForensicReviewData $data, array $theses): void
    {
        $existing = $legalCase->precedents()->get()->keyBy('id');

        /** @var list<string> $kept */
        $kept = [];

        foreach ($data->precedents as $precedent) {
            $row = $existing->get((string) $precedent->id) ?? $this->blankPrecedent($legalCase);

            // A tese sai do mapa e de nenhum outro lugar. A de outra conta não
            // está nele; a que o advogado removeu nesta mesma submissão também
            // não. As duas aterram em null.
            $row->fill($precedent->toArray($theses[(string) $precedent->thesisId] ?? null))->save();

            $kept[] = $row->id;
        }

        $legalCase->precedents()->whereNotIn('id', $kept)->delete();
    }

    /**
     * A row that does not exist yet, already told where it lives.
     *
     * The account comes from the pleading and not from the actor: it is the
     * pleading that says which tenant the argument belongs to.
     */
    private function blankThesis(LegalCase $legalCase): LegalThesis
    {
        return new LegalThesis([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    private function blankPrecedent(LegalCase $legalCase): LegalPrecedent
    {
        return new LegalPrecedent([
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }
}
