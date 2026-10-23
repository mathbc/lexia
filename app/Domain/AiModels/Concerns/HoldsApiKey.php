<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Concerns;

use App\Domain\AiModels\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * The provider's API key, kept on the model row.
 *
 * Encrypted at rest by the `encrypted` cast and hidden from serialization, both
 * declared by the model. It never travels back to the browser: the screen gets
 * whether there is one and its last characters, and a blank field on the form
 * means "keep the one stored".
 *
 * @mixin Model
 */
trait HoldsApiKey
{
    public function hasApiKey(): bool
    {
        return $this->api_key !== null && $this->api_key !== '';
    }

    /**
     * "••••9kZA", enough for staff to tell which key is stored.
     */
    public function apiKeyHint(): ?string
    {
        return $this->hasApiKey() ? '••••'.mb_substr((string) $this->api_key, -4) : null;
    }

    /**
     * The key a correction leaves on the row: the one typed, or else the stored
     * one while the provider stays the same. A key belongs to its provider, so
     * switching providers without typing a new one drops it.
     */
    public function apiKeyAfter(AiProvider $provider, ?string $typed): ?string
    {
        if ($typed !== null && $typed !== '') {
            return $typed;
        }

        return $provider === $this->provider ? $this->api_key : null;
    }

    /**
     * Whether a correction to this provider must bring a key of its own:
     * one is required and none would survive the change.
     */
    public function needsApiKeyFor(AiProvider $provider): bool
    {
        return $provider->requiresApiKey() && blank($this->apiKeyAfter($provider, null));
    }
}
