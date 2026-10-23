<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    protected $model = AiModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AiProvider::Gemini,
            'name' => 'gemini-'.fake()->unique()->numerify('#.#').'-flash',
            'api_key' => 'AIza'.fake()->regexify('[A-Za-z0-9_-]{35}'),
            'default' => false,
        ];
    }

    public function ollama(string $name = 'gpt-oss:20b'): static
    {
        return $this->state(['provider' => AiProvider::Ollama, 'name' => $name, 'api_key' => null]);
    }

    public function asDefault(): static
    {
        return $this->state(['default' => true]);
    }

    public function withoutApiKey(): static
    {
        return $this->state(['api_key' => null]);
    }
}
