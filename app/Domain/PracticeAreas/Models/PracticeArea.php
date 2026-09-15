<?php

declare(strict_types=1);

namespace App\Domain\PracticeAreas\Models;

use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An area of legal practice: the first thing a lawyer picks when drafting.
 *
 * Reference data shared by every account, so no BelongsToAccount and no soft
 * deletes — rows arrive by migration and only change when the CNJ table is
 * resynced. The slug, not the id, is what survives that: see
 * database/data/README.md.
 *
 * @property string $id
 * @property string $slug
 * @property string $label
 * @property list<int> $cnj_subject_roots
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ProceduralClass> $proceduralClasses
 */
class PracticeArea extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cnj_subject_roots' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * The classes a matter in this area may be docketed under.
     *
     * Specific first, then the generic civil trunk ones: some areas offer 90
     * classes, and the ones that actually belong to the area have to come off
     * the top of the list. 'specific' sorts after 'generic', hence the desc.
     *
     * @return BelongsToMany<ProceduralClass, $this>
     */
    public function proceduralClasses(): BelongsToMany
    {
        return $this->belongsToMany(ProceduralClass::class)
            ->withPivot('scope')
            ->orderByPivot('scope', 'desc')
            ->orderBy('name');
    }
}
