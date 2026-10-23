<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Models\EmbeddingModel;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The models a new installation starts on: Gemini for text and for vectors.
 *
 * Registered by `lexia:install` and not by a seeder or a migration — Laravel
 * Cloud does not run `db:seed`, and a migration would also impose the default
 * on every database it reaches.
 *
 * Idempotent, and it never takes a choice back: a model is only promoted on a
 * catalogue with no default, so running the install again after staff picked
 * another model in the panel leaves their pick alone.
 */
final class RegisterDefaultModels
{
    use AsAction;

    public const string TEXT = 'gemini-3.7-flash';

    public const string EMBEDDING = 'gemini-embedding-001';

    /**
     * @return array{text: AiModel, embedding: EmbeddingModel}
     */
    public function handle(): array
    {
        $text = AiModel::query()->firstOrCreate(['provider' => AiProvider::Gemini, 'name' => self::TEXT]);

        if (! AiModel::hasDefault()) {
            MakeAiModelDefault::make()->handle($text);
        }

        $embedding = EmbeddingModel::query()->firstOrCreate(['provider' => AiProvider::Gemini, 'name' => self::EMBEDDING]);

        if (! EmbeddingModel::hasDefault()) {
            MakeEmbeddingModelDefault::make()->handle($embedding);
        }

        return ['text' => $text, 'embedding' => $embedding];
    }
}
