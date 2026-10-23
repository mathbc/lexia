<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Models;

use App\Domain\AiModels\Concerns\HasPlatformDefault;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Policies\ModelCatalogPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\EmbeddingModelFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that turns text into vectors, for the two vectorized catalogues.
 *
 * Unlike a text model, changing the default here is not free: every vector
 * already stored was made by the previous one and is not comparable with what
 * the new one produces. Each vector row records its model's key() for that
 * reason, the retrieval only compares rows made by the default, and promoting
 * another one queues EmbedCatalogs.
 *
 * @property string $id
 * @property AiProvider $provider
 * @property string $name
 * @property bool $default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(EmbeddingModelFactory::class)]
#[UsePolicy(ModelCatalogPolicy::class)]
class EmbeddingModel extends Model
{
    /** @use HasFactory<EmbeddingModelFactory> */
    use HasFactory;

    use HasPlatformDefault;
    use HasUuids;

    /**
     * The width of the `vector(768)` columns, asked of every model.
     *
     * Not a column: a model that cannot answer in 768 dimensions cannot fill
     * the catalogues without a migration, whatever the row says. Gemini
     * truncates to it through `outputDimensionality`; nomic speaks it natively.
     */
    public const int DIMENSIONS = 768;

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'default' => 'boolean',
        ];
    }

    /**
     * What a vector row stores in `embedding_model`: provider and name, and not
     * the id, so a row edited to name another model stops matching the vectors
     * the old name made.
     */
    public function key(): string
    {
        return "{$this->provider->value}:{$this->name}";
    }

    public function displayName(): string
    {
        return "{$this->name} ({$this->provider->label()})";
    }
}
