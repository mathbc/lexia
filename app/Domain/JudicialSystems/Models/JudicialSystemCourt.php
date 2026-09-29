<?php

declare(strict_types=1);

namespace App\Domain\JudicialSystems\Models;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\JudicialSystems\Enums\AdoptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One state court running one system: "o TJBA está em transição para o eproc".
 *
 * The court is its acronym and its state, not a row of its own — there is one
 * court of justice per state and nothing else here to say about it. The
 * acronym is stored rather than derived from the state because it cannot be:
 * the Distrito Federal's court is the TJDFT.
 *
 * Nothing points at these rows, so the migration rebuilds them wholesale on a
 * resync and the uuids do not survive it — unlike the systems'.
 *
 * @property string $id
 * @property string $judicial_system_id
 * @property BrazilianState $state
 * @property string $court
 * @property AdoptionStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read JudicialSystem $judicialSystem
 */
class JudicialSystemCourt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => BrazilianState::class,
            'status' => AdoptionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<JudicialSystem, $this>
     */
    public function judicialSystem(): BelongsTo
    {
        return $this->belongsTo(JudicialSystem::class);
    }
}
