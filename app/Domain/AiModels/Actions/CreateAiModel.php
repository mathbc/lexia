<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Actions\Concerns\ValidatesModelCatalog;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registers a text model.
 *
 * Registering does not switch: the agents keep running on the default until
 * someone promotes the new row. The exception is the first row of an empty
 * catalogue, which becomes the default — a platform with one model and no
 * default would refuse every prompt.
 */
final class CreateAiModel
{
    use AsAction;
    use ValidatesModelCatalog;

    public function handle(AiProvider $provider, string $name, ?string $apiKey = null): AiModel
    {
        return DB::transaction(function () use ($provider, $name, $apiKey): AiModel {
            $model = AiModel::query()->create(['provider' => $provider, 'name' => $name, 'api_key' => $apiKey]);

            if (! AiModel::hasDefault()) {
                MakeAiModelDefault::make()->handle($model);
            }

            return $model;
        });
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', AiModel::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return $this->catalogRules('ai_models', $request);
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $model = $this->handle(
            AiProvider::from($request->string('provider')->toString()),
            $request->string('name')->toString(),
            $this->typedApiKey($request),
        );

        Inertia::flash('success', $model->default
            ? "Modelo {$model->name} cadastrado e definido como padrão."
            : "Modelo {$model->name} cadastrado.");

        return to_route('ai-models.index');
    }
}
