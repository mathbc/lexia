<?php

declare(strict_types=1);

namespace App\Domain\LegalPrecedents\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalPrecedents\Policies\LegalPrecedentPolicy;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\LegalPrecedentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A ruling found to sustain what this pleading argues.
 *
 * `name` is how it is referred to — "STJ — Súmula nº 393/STJ" — and
 * `description` the text it consolidated, quoted. `citation` is the reference in
 * ABNT, which is what the drafted document prints, and `grounding` the sentence
 * that says what it does for *this* case.
 *
 * The row is the finding and not the ruling. Súmula 393 exists once in Brazilian
 * law and many times in this table, one per pleading that cited it, because what
 * is stored is how well it fits these facts and what it grounds in them — both
 * of which change from case to case. A shared catalogue of rulings would be a
 * different table, and this one would still exist.
 *
 * `adherence` is that fit, in percent, and is nullable in the way
 * `requirements.amount` is: it is a measurement somebody made, not a property of
 * the súmula. A ruling the lawyer typed in by hand carries none, and a zero
 * would claim it was measured and found irrelevant.
 *
 * `legal_thesis_id` is the thesis it sustains, nullable because a ruling can be
 * found before the thesis exists and remains a finding without one. It is filled
 * by SaveLegalCaseForensicReview through its id map and never from the payload
 * directly — see that class for why.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string|null $legal_thesis_id
 * @property string $name
 * @property LegalPrecedentType|null $type
 * @property string $description
 * @property string|null $citation
 * @property string|null $grounding
 * @property string|null $adherence
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 * @property-read LegalThesis|null $thesis
 */
#[UsePolicy(LegalPrecedentPolicy::class)]
#[UseFactory(LegalPrecedentFactory::class)]
class LegalPrecedent extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<LegalPrecedentFactory> */
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
            'type' => LegalPrecedentType::class,
            'adherence' => 'decimal:2',
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
     * The thesis this ruling sustains, if it sustains one.
     *
     * @return BelongsTo<LegalThesis, $this>
     */
    public function thesis(): BelongsTo
    {
        return $this->belongsTo(LegalThesis::class, 'legal_thesis_id');
    }
}
