<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one row of a model catalogue the platform runs on.
 *
 * The partial unique index in the migration is what guarantees there is never a
 * second; this is the move that keeps it true while the default changes hands.
 *
 * @mixin Model
 */
trait HasPlatformDefault
{
    /**
     * The current default, or null on a database `lexia:install` never ran on.
     */
    public static function platformDefault(): ?static
    {
        return static::query()->where('default', true)->first();
    }

    public static function hasDefault(): bool
    {
        return static::query()->where('default', true)->exists();
    }

    /**
     * Hands the default to this row.
     *
     * Every row is locked before anything is written. Locking only the current
     * default is not enough under READ COMMITTED: a second admin promoting
     * another row at the same moment would wait on that lock, then not see this
     * row's new flag and set its own beside it — which the index refuses with a
     * 500. Waiting on the whole table, which holds a handful of rows, makes the
     * second one read the first one's result.
     */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            static::query()->lockForUpdate()->pluck($this->getKeyName());

            // The old default is cleared first: the index is checked per
            // statement, so for an instant there is none, never two.
            static::query()
                ->where('default', true)
                ->whereKeyNot($this->getKey())
                ->update(['default' => false]);

            $this->forceFill(['default' => true])->save();
        });
    }

    /**
     * Default first, then by provider and name, as the listing shows them.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInCatalogOrder(Builder $query): void
    {
        $query->orderByDesc('default')->orderBy('provider')->orderBy('name');
    }
}
