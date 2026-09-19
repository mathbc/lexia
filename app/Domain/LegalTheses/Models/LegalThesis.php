<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use App\Domain\LegalTheses\Policies\LegalThesisPolicy;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\LegalThesisFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of argument the pleading will carry.
 *
 * "Do Cabimento da Exceção de Pré-Executividade sem Garantia do Juízo" is a
 * thesis; so is "Da Ilegitimidade Passiva do Sócio-Administrador". `name` is the
 * heading it gets in the drafted document, `description` what it argues, and
 * `impact` what winning it buys the client.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create — and by exactly one pleading. The account key
 * is redundant with the pleading's own, and deliberately so: it is what lets the
 * global scope narrow the table without a join on every read.
 *
 * The precedents that sustain it are rows of their own and point back here. The
 * relation is one-to-many rather than a pivot even though the same súmula
 * sustains theses in other pleadings: what is stored there is not the ruling but
 * the *finding* about this case, and two cases that cite Súmula 393 adhere to it
 * differently and for different reasons.
 *
 * `legal_bases` is the fundamentação as a typed list — the articles, statutes,
 * súmulas, ADCs and temas the thesis rests on. The contract of each item, which
 * `citedLegalBases()` depends on and nothing else enforces:
 *
 * - `reference` is the citation **complete and ready to read**, exactly as the
 *   chip shows it: "Súmula 393 do STJ", "Art. 135, III, do CTN".
 * - `source` is the authority it comes from, as a sigla — "STJ", "CTN",
 *   "CF/88" — and repeats what the reference already says, on purpose.
 *
 * The redundancy is what buys grouping and filtering by court later, and it
 * dodges a problem composition cannot solve: the preposition is gendered in
 * Portuguese — *do* CPC but *da* CF/88 — so building the chip from the parts
 * would need a table of the gender of every sigla in Brazilian law, and would
 * write bad Portuguese until that table was complete.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string $name
 * @property LegalThesisType|null $type
 * @property string $description
 * @property string|null $impact
 * @property list<array{type: string|null, reference: string, source: string|null}>|null $legal_bases
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 * @property-read Collection<int, LegalPrecedent> $precedents
 */
#[UsePolicy(LegalThesisPolicy::class)]
#[UseFactory(LegalThesisFactory::class)]
class LegalThesis extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<LegalThesisFactory> */
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
            'type' => LegalThesisType::class,
            'legal_bases' => 'array',
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
     * The rulings that sustain this thesis.
     *
     * Oldest first, like the requirements and for the same reason: insertion
     * order is the order they were found and the order they will be cited, and
     * there is no position column until the screen offers reordering.
     *
     * @return HasMany<LegalPrecedent, $this>
     */
    public function precedents(): HasMany
    {
        return $this->hasMany(LegalPrecedent::class)->oldest();
    }

    /**
     * The fundamentação as the chips read it, in the order it was written.
     *
     * A reading and not a column: storing "Súmula 393 do STJ" already assembled
     * would be the same string, but it would lose the `type` and `source` that
     * let a screen group the chips by instrument or by court.
     *
     * @return list<string>
     */
    public function citedLegalBases(): array
    {
        return array_column($this->legal_bases ?? [], 'reference');
    }
}
