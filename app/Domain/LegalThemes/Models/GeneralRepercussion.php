<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An STF general-repercussion tema, as the STJ file links it to a precedent.
 *
 * A child row and not a catalogue entry: it records that *this* precedent is
 * tied to STF tema `number`, and the description is copied from the file.
 * The whole set of a precedent is rewritten on every import, so these rows
 * carry no identity worth keeping between runs.
 *
 * @property string $id
 * @property string $legal_theme_id
 * @property int $number
 * @property string $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read LegalTheme $legalTheme
 */
class GeneralRepercussion extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LegalTheme, $this>
     */
    public function legalTheme(): BelongsTo
    {
        return $this->belongsTo(LegalTheme::class);
    }
}
