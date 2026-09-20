<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Data\PleadingDraftData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\LegalPleadingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of the drafted document.
 *
 * The other children of LegalCase hold a piece the lawyer assembled — a request,
 * a thesis, a ruling. This one holds the prose that assembles them: the petição
 * inicial as it will be filed, from the endereçamento down to the signature.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create — and by exactly one pleading. The account key is
 * redundant with the case's own, deliberately, exactly as on LegalThesis: it is
 * what lets the global scope narrow the table without a join on every read.
 *
 * **Rows are never updated and never deleted.** Editing the draft writes a new
 * row with the next `version`, so the text the agent produced stays beside the
 * text the lawyer settled on. That is why there is no `SoftDeletes` here while
 * every sibling has it — see the migration, where the reasoning lives.
 *
 * `content` holds no letterhead and no signature block from the model's hand:
 * the firm's name, address and OAB are data the screen draws around this text,
 * and the closing is composed by PleadingSignature. What the agent writes is the
 * body, and what this column stores is that body plus the composed closing — one
 * string, because the screen offers one textarea.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string $content
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 */
#[UseFactory(LegalPleadingFactory::class)]
class LegalPleading extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<LegalPleadingFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }

    /**
     * The gaps the drafting agent left for the lawyer to fill.
     *
     * A reading and not a column, and deliberately computed from the text rather
     * than stored beside it: the lawyer edits `content` freely, so a stored list
     * would start lying the moment a placeholder was filled in. Reading it back
     * means the count on screen is always about the text on screen.
     *
     * What counts as a gap is PleadingDraftData's to say, and this delegates
     * rather than repeating the pattern — two copies of it would disagree the
     * first time one was tightened.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return PleadingDraftData::gapsIn($this->content);
    }
}
