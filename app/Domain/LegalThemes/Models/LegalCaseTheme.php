<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Models;

use App\Domain\LegalCases\Models\LegalCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The link between a pleading and an STJ theme it leans on.
 *
 * A custom pivot and not the bare table for one mechanical reason and one of
 * meaning. The mechanical one: the table has a uuid primary key, and only a
 * pivot *class* goes through `save()` on `attach()` — which is where
 * `HasUuids` mints it. The bare pivot would insert a row with no id.
 *
 * The one of meaning is `reason`: why this theme applies to these facts, as
 * `LegalThemeSelectionAgent` wrote it. The row is a finding about the pleading,
 * not a copy of the catalogue — see the migration.
 *
 * Not `BelongsToAccount`, unlike the other children of `LegalCase`. The rows
 * are only ever reached through `LegalCase::themes()`, which the account scope
 * already filters, and a global scope on a pivot class is not applied to that
 * query anyway — it would read as a guard it is not. `account_id` is written
 * explicitly by `SaveLegalCaseThemes`.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string $legal_theme_id
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read LegalCase $legalCase
 * @property-read LegalTheme $legalTheme
 */
class LegalCaseTheme extends Pivot
{
    use HasUuids;

    protected $table = 'legal_case_themes';

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    /**
     * @return BelongsTo<LegalTheme, $this>
     */
    public function legalTheme(): BelongsTo
    {
        return $this->belongsTo(LegalTheme::class);
    }
}
