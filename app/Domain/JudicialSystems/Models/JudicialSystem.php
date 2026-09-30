<?php

declare(strict_types=1);

namespace App\Domain\JudicialSystems\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An electronic court system — eproc, PJe, e-SAJ — through which a pleading
 * is filed.
 *
 * Reference data shared by every account, like PracticeArea: rows arrive by
 * migration and only change when the map in database/data is edited. The slug,
 * not the id, is what survives that edit.
 *
 * A system is its own row, and not a column on each court, because one system
 * serves many courts: the eproc runs in fourteen of them. The courts hang off
 * it as `JudicialSystemCourt`, which is also where a court that runs two
 * systems at once — São Paulo, Rio Grande do Norte — shows up twice.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, JudicialSystemCourt> $courts
 */
class JudicialSystem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * The state courts that file through this system, by acronym.
     *
     * @return HasMany<JudicialSystemCourt, $this>
     */
    public function courts(): HasMany
    {
        return $this->hasMany(JudicialSystemCourt::class)->orderBy('court');
    }

    /**
     * The courts as one line — "TJAC, TJAP (em implantação), TJBA (em
     * transição)" — for the hint under the select.
     *
     * Only a court that is not simply using the system says so: "em uso" after
     * every other acronym would bury the three that are moving.
     */
    public function servedCourts(): string
    {
        return $this->courts
            ->map(static fn (JudicialSystemCourt $court): string => $court->statusNote() === null
                ? $court->court
                : "{$court->court} ({$court->statusNote()})")
            ->implode(', ');
    }

    /**
     * Where the lawyer opens the system to file: one address per court, since
     * each court runs its own instance.
     *
     * The state goes along because it is how the screen picks the one link
     * that matters — the forum's — out of the fourteen the eproc has. A court
     * with no known address is left out rather than sent empty: a button that
     * goes nowhere is worse than no button.
     *
     * @return list<array{court: string, state: string, url: string, note: string|null}>
     */
    public function accessLinks(): array
    {
        return $this->courts
            ->filter(static fn (JudicialSystemCourt $court): bool => $court->url !== null)
            ->map(static fn (JudicialSystemCourt $court): array => [
                'court' => $court->court,
                'state' => $court->state->value,
                'url' => (string) $court->url,
                'note' => $court->statusNote(),
            ])
            ->values()
            ->all();
    }
}
