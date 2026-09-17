<?php

declare(strict_types=1);

namespace App\Domain\Shared\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Hidrata uma coluna `vector` do pgvector como lista de floats.
 *
 * O framework 12 já traz `$table->vector()`, `whereVectorSimilarTo()` e
 * `ensureVectorExtensionExists()`, mas **não** o cast `AsVector` que a
 * documentação do SDK mostra — daí este. O driver devolve a coluna como o
 * texto `[0.1,0.2,…]`, que sem tratamento chegaria ao código como string.
 *
 * Na escrita, json_encode é exatamente a representação que o Postgres aceita
 * para `vector`, e é a mesma que `whereVectorDistanceLessThan()` usa no bind.
 *
 * @implements CastsAttributes<list<float>, mixed>
 */
final class AsVector implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        // O driver devolve a coluna como o texto `[0.1,0.2,…]`; um vetor que já
        // veio hidratado (um forceFill logo antes do save, por exemplo) chega
        // como array e passa direto.
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_map(floatval(...), $decoded));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("O atributo {$key} espera uma lista de floats.");
        }

        return (string) json_encode($value, JSON_THROW_ON_ERROR);
    }
}
