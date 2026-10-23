<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions\Concerns;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;

/**
 * Validation shared by registering and correcting a model, in either catalogue.
 *
 * The name is the id the provider's API speaks and travels as is — into a URL
 * path on Gemini, into the request body on Ollama —, so it is held to the
 * characters those ids use: letters, digits, `.`, `_`, `:`, `/` and `-`. That
 * covers `gemini-3.7-flash`, `gpt-oss:20b` and `hf.co/user/model:tag`, and
 * refuses the space a pasted display name would bring.
 *
 * The API key is required where the provider needs one and none would survive
 * the save: always when registering a Gemini model, and when correcting one
 * only if the row has no key of its provider yet. A blank field on a
 * correction keeps the stored key — the form never receives it to send back.
 */
trait ValidatesModelCatalog
{
    /**
     * @return array<string, mixed>
     */
    protected function catalogRules(string $table, ActionRequest $request, AiModel|EmbeddingModel|null $ignoring = null): array
    {
        $provider = AiProvider::tryFrom($request->string('provider')->toString());

        return [
            'provider' => ['required', Rule::enum(AiProvider::class)],
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/^[A-Za-z0-9._:\/-]+$/',
                Rule::unique($table, 'name')
                    ->where('provider', $request->string('provider')->toString())
                    ->ignore($ignoring?->getKey()),
            ],
            'api_key' => [
                Rule::requiredIf(static fn (): bool => $provider !== null
                    && ($ignoring?->needsApiKeyFor($provider) ?? $provider->requiresApiKey())),
                'nullable',
                'string',
                'max:500',
                'regex:/^\S+$/',
            ],
        ];
    }

    /**
     * What was typed in the key field, with blank as "nothing typed".
     */
    protected function typedApiKey(ActionRequest $request): ?string
    {
        return $request->filled('api_key') ? $request->string('api_key')->trim()->toString() : null;
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'provider' => 'provedor',
            'name' => 'modelo',
            'api_key' => 'chave de API',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        return [
            'name.regex' => 'Informe o identificador do modelo na API, sem espaços — como gemini-3.7-flash ou gpt-oss:20b.',
            'name.unique' => 'Este modelo já está cadastrado para o provedor.',
            'api_key.required' => 'Informe a chave de API: o provedor não atende sem ela.',
            'api_key.regex' => 'A chave de API não tem espaços — confira se ela foi colada inteira e sozinha.',
        ];
    }
}
