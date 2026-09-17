<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Models;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\Shared\Casts\AsVector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
 * @property string|null $description
 * @property list<string> $typical_subjects
 * @property int $root_code
 * @property list<string> $path
 * @property string|null $abbreviation
 * @property string|null $nature
 * @property string|null $legal_norm
 * @property string|null $legal_article
 * @property list<array{norm: string, article: string, note: string}>|null $legal_bases
 * @property string|null $active_party
 * @property string|null $passive_party
 * @property bool $has_own_numbering
 * @property bool $is_filing_class
 * @property bool $is_cross_cutting
 * @property list<string> $jurisdictions
 * @property list<float>|null $embedding
 * @property string|null $embedding_hash
 * @property CarbonImmutable|null $embedded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, PracticeArea> $practiceAreas
 * @property-read Pivot|null $pivot the `scope` of the link, set only when the
 *     row was loaded through PracticeArea::proceduralClasses()
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
            'typical_subjects' => 'array',
            'legal_bases' => 'array',
            'path' => 'array',
            'jurisdictions' => 'array',
            'embedding' => AsVector::class,
            'embedded_at' => 'immutable_datetime',
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
     * The statutes a pleading under this class actually invokes, each spelled
     * out the way it is cited — "CPC, art. 319 (requisitos da petição inicial)".
     *
     * Plural, unlike legalBasis(): that one answers where the *class* is
     * defined, which is the CNJ's own single reference, while these are the
     * fundamentação the peça carries. Falls back to the CNJ reference so the
     * caller never has to branch on an unenriched row.
     *
     * @return list<string>
     */
    public function citedLegalBases(): array
    {
        $bases = $this->legal_bases ?? [];

        if ($bases === []) {
            $basis = $this->legalBasis();

            return $basis === null ? [] : [$basis];
        }

        return array_map(
            static fn (array $base): string => trim(sprintf(
                '%s, art. %s (%s)',
                $base['norm'],
                $base['article'],
                $base['note'],
            )),
            $bases,
        );
    }

    /**
     * Whether the class may be filed in a given CNJ competence.
     */
    public function appliesIn(string $jurisdiction): bool
    {
        return in_array($jurisdiction, $this->jurisdictions, true);
    }
}
