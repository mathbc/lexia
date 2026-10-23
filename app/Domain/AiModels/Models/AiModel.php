<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Models;

use App\Domain\AiModels\Concerns\HasPlatformDefault;
use App\Domain\AiModels\Concerns\HoldsApiKey;
use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Policies\ModelCatalogPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A text model the agents can run on.
 *
 * Reference data shared by every account and kept by LexIA staff. The row
 * flagged `default` is the one every agent prompts — DefaultModels resolves it,
 * and the agents read it through UsesDefaultAiModel instead of naming a
 * provider of their own.
 *
 * @property string $id
 * @property AiProvider $provider
 * @property string $name
 * @property string|null $api_key
 * @property bool $default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(AiModelFactory::class)]
#[UsePolicy(ModelCatalogPolicy::class)]
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    use HasPlatformDefault;
    use HasUuids;
    use HoldsApiKey;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['api_key'];

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
            'api_key' => 'encrypted',
            'default' => 'boolean',
        ];
    }

    /**
     * "gemini-3.7-flash (Google Gemini)", for messages that name it.
     */
    public function displayName(): string
    {
        return "{$this->name} ({$this->provider->label()})";
    }
}
