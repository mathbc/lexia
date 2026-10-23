<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Actions\Concerns\ValidatesModelCatalog;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Corrects a text model — a typo in the id, a newer version of the same one.
 *
 * Editing the default changes what every agent runs on from the next prompt,
 * and that is the point: there is nothing stored that the old name made.
 */
final class UpdateAiModel
{
    use AsAction;
    use ValidatesModelCatalog;

    public function handle(AiModel $model, AiProvider $provider, string $name): AiModel
    {
        $model->update(['provider' => $provider, 'name' => $name]);

        return $model;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('aiModel'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        /** @var AiModel $model */
        $model = $request->route('aiModel');

        return $this->catalogRules('ai_models', $request, $model);
    }

    public function asController(AiModel $aiModel, ActionRequest $request): RedirectResponse
    {
        $this->handle(
            $aiModel,
            AiProvider::from($request->string('provider')->toString()),
            $request->string('name')->toString(),
        );

        Inertia::flash('success', "Modelo {$aiModel->name} atualizado.");

        return to_route('ai-models.index');
    }
}
