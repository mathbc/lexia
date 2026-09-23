<?php

declare(strict_types=1);

namespace App\Domain\CourtDecisions\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\CourtDecisions\Policies\CourtDecisionPolicy;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\CourtDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One ruling the courts have already handed down in a case like this one.
 *
 * The seventh step's row — "Análise de Jurisprudência" — and the sibling of
 * LegalPrecedent, which is close enough that the difference has to be said out
 * loud. A precedent is what sustains a **thesis**: it hangs off one, carries an
 * adherence score and a sentence about what it does for these facts. A court
 * decision is the **document itself**, transcribed from the catalogue: what
 * that tribunal decided, with no claim attached about this pleading. Hence no
 * `legal_thesis_id`, no `adherence` and no `grounding` here — the reading is
 * the lawyer's to make, and the row is the finding-free half of the pair.
 *
 * The columns are the LexML record, named in English and mapped one to one:
 * Localidade, Autoridade, Título, Data, Ementa, Assuntos and the Nome Uniforme
 * become `locality`, `authority`, `title`, `decided_at`, `summary`, `subject`
 * and `urn`. Following the catalogue's own vocabulary is what makes a
 * transcription checkable against the page it came from.
 *
 * `source_url` is the `/urn/` page that was read, and it is the one column the
 * guard consults — see CourtDecisionSources. A decision without it cannot be
 * confirmed by anybody, and an unconfirmable ementa is exactly what the
 * research prompt exists to refuse.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create — and by exactly one pleading. The account key
 * is redundant with the pleading's own and deliberately so, for the reason
 * LegalThesis gives: it is what lets the global scope narrow the table without
 * a join on every read.
 *
 * Nothing writes these rows yet. The research pair
 * (ResearchLegalCaseCourtDecisions) answers with CourtDecisionData, and the
 * Action that persists it arrives with the screen — the same order
 * CreateLegalThesis was born in, and the same reason: a save with no caller is
 * a guess about a payload nobody has posted.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string $title
 * @property string|null $locality
 * @property string|null $authority
 * @property string $summary
 * @property string|null $subject
 * @property string $source_url
 * @property string|null $urn
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 */
#[UsePolicy(CourtDecisionPolicy::class)]
#[UseFactory(CourtDecisionFactory::class)]
class CourtDecision extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<CourtDecisionFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }
}
