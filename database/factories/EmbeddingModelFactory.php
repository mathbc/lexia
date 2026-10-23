<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\EmbeddingModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbeddingModel>
 */
class EmbeddingModelFactory extends Factory
{
    protected $model = EmbeddingModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AiProvider::Gemini,
            'name' => 'gemini-embedding-'.fake()->unique()->numerify('00#'),
            'default' => false,
        ];
    }

    public function ollama(string $name = 'nomic-embed-text'): static
    {
        return $this->state(['provider' => AiProvider::Ollama, 'name' => $name]);
    }

    public function asDefault(): static
    {
        return $this->state(['default' => true]);
    }
}
