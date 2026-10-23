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
 * another model in the panel leaves their pick alone. The same holds for the
 * key: one given here only fills a row that has none, and never replaces a key
 * typed in the panel.
 */
final class RegisterDefaultModels
{
    use AsAction;

    public const string TEXT = 'gemini-3.7-flash';

    public const string EMBEDDING = 'gemini-embedding-001';

    /**
     * @return array{text: AiModel, embedding: EmbeddingModel}
     */
    public function handle(?string $geminiKey = null): array
    {
        $text = AiModel::query()->firstOrCreate(['provider' => AiProvider::Gemini, 'name' => self::TEXT]);
        self::fillKey($text, $geminiKey);

        if (! AiModel::hasDefault()) {
            MakeAiModelDefault::make()->handle($text);
        }

        $embedding = EmbeddingModel::query()->firstOrCreate(['provider' => AiProvider::Gemini, 'name' => self::EMBEDDING]);
        self::fillKey($embedding, $geminiKey);

        if (! EmbeddingModel::hasDefault()) {
            MakeEmbeddingModelDefault::make()->handle($embedding);
        }

        return ['text' => $text, 'embedding' => $embedding];
    }

    /**
     * Whether a run would leave either model without a key — missing rows
     * included, since they would be created without one.
     */
    public static function lacksKey(): bool
    {
        return ! self::keyed(AiModel::class, self::TEXT) || ! self::keyed(EmbeddingModel::class, self::EMBEDDING);
    }

    /**
     * `whereNotNull` reads the ciphertext, which is null exactly when the key is.
     *
     * @param  class-string<AiModel|EmbeddingModel>  $class
     */
    private static function keyed(string $class, string $name): bool
    {
        return $class::query()
            ->where(['provider' => AiProvider::Gemini, 'name' => $name])
            ->whereNotNull('api_key')
            ->exists();
    }

    private static function fillKey(AiModel|EmbeddingModel $model, ?string $key): void
    {
        if ($key !== null && $key !== '' && ! $model->hasApiKey()) {
            $model->forceFill(['api_key' => $key])->save();
        }
    }
}
