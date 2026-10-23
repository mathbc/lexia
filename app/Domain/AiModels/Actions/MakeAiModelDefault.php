<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Points every agent at another text model.
 *
 * Takes effect on the next request: DefaultModels is scoped, so nothing holds
 * on to the old one past the request or job it was read in. A model whose
 * provider cannot search the web is accepted — the other agents run fine on
 * it —, and the three research agents then refuse to run, naming the reason.
 */
final class MakeAiModelDefault
{
    use AsAction;

    public function handle(AiModel $model): void
    {
        $model->makeDefault();
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('makeDefault', $request->route('aiModel'));
    }

    public function asController(AiModel $aiModel): RedirectResponse
    {
        $this->handle($aiModel);

        Inertia::flash('success', "Os agentes passam a usar {$aiModel->displayName()}.");

        return to_route('ai-models.index');
    }
}
