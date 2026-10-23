<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions\Concerns;

use App\Domain\AiModels\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;
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
 */
trait ValidatesModelCatalog
{
    /**
     * @return array<string, mixed>
     */
    protected function catalogRules(string $table, ActionRequest $request, ?Model $ignoring = null): array
    {
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'provider' => 'provedor',
            'name' => 'modelo',
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
        ];
    }
}
