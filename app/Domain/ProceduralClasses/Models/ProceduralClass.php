<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Models;

use App\Domain\PracticeAreas\Models\PracticeArea;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A CNJ procedural class: how a case is docketed when it is filed.
 *
 * Reference data, like PracticeArea. `code` is the CNJ's own identifier — the
 * number that travels in DataJud and PJe integrations and appears on the case
 * record — and it is the key that survives a resync, not the uuid.
 *
 * @property string $id
 * @property int $code
 * @property string $name
 * @property string $slug
 * @property int $root_code
 * @property list<string> $path
 * @property string|null $abbreviation
 * @property string|null $nature
 * @property string|null $legal_norm
 * @property string|null $legal_article
 * @property string|null $active_party
 * @property string|null $passive_party
 * @property bool $has_own_numbering
 * @property bool $is_filing_class
 * @property bool $is_cross_cutting
 * @property list<string> $jurisdictions
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, PracticeArea> $practiceAreas
 */
class ProceduralClass extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => 'integer',
            'root_code' => 'integer',
            'path' => 'array',
            'jurisdictions' => 'array',
            'has_own_numbering' => 'boolean',
            'is_filing_class' => 'boolean',
            'is_cross_cutting' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<PracticeArea, $this>
     */
    public function practiceAreas(): BelongsToMany
    {
        return $this->belongsToMany(PracticeArea::class)->orderBy('position');
    }

    /**
     * The statute the class rests on, spelled out the way a pleading cites it.
     */
    public function legalBasis(): ?string
    {
        if ($this->legal_norm === null) {
            return null;
        }

        return $this->legal_article === null
            ? $this->legal_norm
            : "{$this->legal_norm}, art. {$this->legal_article}";
    }

    /**
     * Whether the class may be filed in a given CNJ competence.
     */
    public function appliesIn(string $jurisdiction): bool
    {
        return in_array($jurisdiction, $this->jurisdictions, true);
    }
}
