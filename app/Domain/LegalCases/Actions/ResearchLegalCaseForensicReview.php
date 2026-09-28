<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Data\LegalThemeResearchData;
use App\Domain\LegalCases\Enums\ForensicReviewTab;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Researches what a saved pleading can lean on, and writes it down — both tabs
 * of the fifth step.
 *
 * Two halves, run in parallel: the **theses** (ResearchLegalCaseTheses, the
 * portal research and its transcriber) and the **themes**
 * (ResearchLegalCaseThemes, the RAG over the STJ catalogue). They read the
 * same pleading and depend on nothing the other finds, so they are two tasks
 * of one `Concurrency::run` and the wait is the longer of the two — which is
 * the thesis research, by minutes.
 *
 * ## Why this exists rather than living in ClassifyLegalCase
 *
 * The research used to be the fifth step of the smart fill, and two things sent
 * it here.
 *
 * The first is time. The thesis research leaves the machine and opens the
 * official portals before answering, so it is by some margin the slowest thing
 * we run. Sitting inside the classification it cancelled out the concurrent
 * block entirely, and the lawyer waited for it before seeing a single field.
 *
 * The second decides it. A pleading that has not been saved has no primary key,
 * so there was nowhere to put what the research found — the theses came back in
 * the JSON, travelled through `sessionStorage`, and died with the tab.
 *
 * Here both are answered by the same fact: the pleading exists. This is also why
 * the wizard makes step 1 save before any other step can be opened.
 *
 * ## Exactly once per half, and the lawyer asks for the rest
 *
 * The screen fires this when step 5 opens and sends, in `tabs`, **only the
 * halves that were never researched**: `research_findings` null for the theses,
 * `theme_findings` null for the themes. A pleading researched before the themes
 * tab existed asks for the themes alone; one where both landed asks for nothing.
 * "Pesquisar novamente" sends the one tab it sits on.
 *
 * The guard is those columns and **not** the lists, which is the trap worth
 * naming: a run that confirms nothing is a legitimate, expensive answer that
 * writes zero rows, so a trigger keyed on an empty list would fire on every
 * visit. And firing again is not merely wasteful — both saves reconcile by
 * diff, so a silent re-run would quietly replace what the lawyer had already
 * curated. That is also why the markers are two and not one: keyed on a shared
 * column, retrying the half that failed would re-run the half that landed.
 *
 * ## What the parallelism costs, and where it lives
 *
 * The rules ClassifyLegalCase documents, unchanged. The `try/catch` lives
 * **inside** each task (`attempt()`): it is what logs the real stack trace,
 * from the child that saw it, and under `sync` an escaped exception would
 * leave `Concurrency::run` and take the other half with it. What no closure
 * can catch — the child killed by its clock, a fatal error — belongs to
 * IsolatedProcessDriver, which settles that task as the same null. Either way
 * a theme selection that fell does not take the theses with it, nor the
 * reverse, and each half has `concurrency.timeout` of its own.
 *
 * The closures are static and capture two strings, the pleading's id and
 * account: a child process has no session and no authenticated user, so it
 * reloads the pleading under `TenantContext::actingAs()`, which is explicit
 * where a scope silently left open would not be. And what comes back crosses
 * `serialize()`: both results are readonly DTOs of strings and ids, no model.
 *
 * ## The two effects, and why only one of them is a transaction
 *
 * Writing a half's rows and marking it are one statement about the pleading —
 * these are the theses, and this is when we looked — so they share a
 * transaction, and both halves that came back are written in the same one. The
 * inference is outside it, ahead of it, because a transaction held open across
 * a network call to another continent is a lock nobody meant to take.
 *
 * A half that fails writes nothing and marks nothing, and does not stop the
 * other half from being written. It comes back to the screen as an error keyed
 * by the tab — a redirect with `withErrors()`, and not a 500 — so the tab can
 * say that it failed, offer the button, and stop the step from asking again on
 * its own. The one refusal that is still a 500 is the missing narrative: it is
 * checked before anything is spent, and it fails both halves for the same
 * reason.
 */
final class ResearchLegalCaseForensicReview
{
    use AsAction;

    /**
     * @param  list<ForensicReviewTab>|null  $tabs  the halves to research; null is both
     * @return list<ForensicReviewTab> the halves that did not come back
     */
    public function handle(LegalCase $legalCase, ?array $tabs = null): array
    {
        $this->refuseUnresearchable($legalCase);

        $tabs ??= ForensicReviewTab::cases();

        // Fora da transação de propósito: são inferências, uma delas na nuvem e
        // abrindo páginas, e nada disso deve segurar uma linha travada.
        $found = $this->research($legalCase, $tabs);

        DB::transaction(function () use ($legalCase, $found): void {
            $this->writeTheses($legalCase, $found[ForensicReviewTab::Theses->value] ?? null);
            $this->writeThemes($legalCase, $found[ForensicReviewTab::Themes->value] ?? null);
        });

        return array_values(array_filter(
            $tabs,
            static fn (ForensicReviewTab $tab): bool => ($found[$tab->value] ?? null) === null,
        ));
    }

    /**
     * The halves that were asked for, each one a task of its own.
     *
     * @param  list<ForensicReviewTab>  $tabs
     * @return array<string, LegalResearchData|LegalThemeResearchData|null>
     */
    private function research(LegalCase $legalCase, array $tabs): array
    {
        $id = $legalCase->id;
        $accountId = $legalCase->account_id;

        $tasks = [
            ForensicReviewTab::Theses->value => static fn (): ?LegalResearchData => self::attempt(
                $accountId,
                static fn (): LegalResearchData => ResearchLegalCaseTheses::run(self::reload($id)),
            ),
            ForensicReviewTab::Themes->value => static fn (): ?LegalThemeResearchData => self::attempt(
                $accountId,
                static fn (): LegalThemeResearchData => ResearchLegalCaseThemes::run(self::reload($id)),
            ),
        ];

        /** @var array<string, LegalResearchData|LegalThemeResearchData|null> */
        return Concurrency::run(Arr::only($tasks, array_map(
            static fn (ForensicReviewTab $tab): string => $tab->value,
            $tabs,
        )));
    }

    /**
     * A half that may fail without taking the other one with it.
     *
     * The `report()` is what separates this from swallowing the error: the
     * cause reaches the log from inside the process that saw it, with the real
     * stack trace. The null tells the caller this tab has nothing to write.
     *
     * Static because it is called from inside a serialized closure, which has
     * no instance to return to.
     *
     * @template TFound
     *
     * @param  Closure(): TFound  $research
     * @return TFound|null
     */
    private static function attempt(string $accountId, Closure $research): mixed
    {
        try {
            return app(TenantContext::class)->actingAs($accountId, $research);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The pleading again, inside the task.
     *
     * A child process starts with no tenant, and `actingAs()` above gives it
     * this pleading's account before the query runs, so the scope applies here
     * exactly as it did in the request.
     */
    private static function reload(string $id): LegalCase
    {
        return LegalCase::query()->findOrFail($id);
    }

    private function writeTheses(LegalCase $legalCase, mixed $research): void
    {
        if (! $research instanceof LegalResearchData) {
            return;
        }

        // A Action irmã faz o trabalho inteiro das duas tabelas — o mapa de
        // ids, o diff, o `advanceTo(Review)` — e é a mesma que o "Concluir"
        // chama. Duplicá-la aqui seria manter duas gravações da mesma coisa.
        SaveLegalCaseForensicReview::run($legalCase, $research->review);

        $legalCase->update(['research_findings' => $research->findings()]);
    }

    private function writeThemes(LegalCase $legalCase, mixed $research): void
    {
        if (! $research instanceof LegalThemeResearchData) {
            return;
        }

        SaveLegalCaseThemes::run($legalCase, $research->themes);

        $legalCase->update(['theme_findings' => $research->findings()]);
    }

    /**
     * The one thing neither half can run without.
     *
     * The facts, and only the facts. The other half of what the dossiers send —
     * the area — needs no check here because `legal_cases.practice_area_id` is
     * NOT NULL: a pleading that exists has an area.
     *
     * The facts are nullable, though, and reachable while null: they belong to
     * step 1, but a lawyer may frame the case first and write the story later,
     * and every step after it opens regardless. Without them both inner
     * Actions would throw the same refusal, one of them after a round trip to
     * the cloud. Better here, before anything is spent.
     */
    private function refuseUnresearchable(LegalCase $legalCase): void
    {
        if (trim((string) $legalCase->facts) === '') {
            throw new RuntimeException('Não há fatos para pesquisar.');
        }
    }

    public function authorize(ActionRequest $request): bool
    {
        // A peça, como em toda etapa do assistente: quem pode escrevê-la pode
        // pedir a pesquisa do que ela argumenta.
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * Absent means both halves — a caller that predates the tabs gets what it
     * always got, plus the themes.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tabs' => ['sometimes', 'array', 'min:1'],
            'tabs.*' => ['distinct', Rule::enum(ForensicReviewTab::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'tabs' => 'abas',
            'tabs.*' => 'aba',
        ];
    }

    /**
     * Answers with a redirect and not with the payload, unlike the smart fill.
     *
     * The rows are already written by the time this returns, so the honest
     * answer is "go and read the step": Inertia re-renders the form with
     * `LegalCaseFormProps::draft()`, and the rows arrive with the ids the
     * database minted. A half that failed rides along as an error under its
     * tab's value, which is what fires the screen's `onError` — a 500 would
     * reach Inertia's own error dialog instead, and the tab would never know.
     */
    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $failed = $this->handle($legalCase, $this->tabsOf($request));

        $redirect = to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => LegalCaseStep::Review->value,
        ]);

        if ($failed === []) {
            return $redirect;
        }

        return $redirect->withErrors(array_combine(
            array_map(static fn (ForensicReviewTab $tab): string => $tab->value, $failed),
            array_map(static fn (ForensicReviewTab $tab): string => $tab->failure(), $failed),
        ));
    }

    /**
     * @return list<ForensicReviewTab>|null
     */
    private function tabsOf(ActionRequest $request): ?array
    {
        $tabs = $request->validated('tabs');

        return is_array($tabs)
            ? array_values(array_map(static fn (string $tab): ForensicReviewTab => ForensicReviewTab::from($tab), $tabs))
            : null;
    }
}
