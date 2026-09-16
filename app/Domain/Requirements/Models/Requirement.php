<?php

declare(strict_types=1);

namespace App\Domain\Requirements\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Requirements\Policies\RequirementPolicy;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\RequirementFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One of the things a pleading asks the court for: the court fee waiver, the
 * injunction, the summons, the damages, the attorney's fees.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create — and by exactly one pleading. The account key
 * is redundant with the pleading's own, and deliberately so: it is what lets
 * the global scope narrow the table without a join on every read.
 *
 * `description` is the request as it will be read out — a sentence, not a
 * code — and `amount` the money it claims, absent on the majority of requests
 * that claim none. It is cast as a decimal string rather than a float,
 * because it is money and reads back into a judgment.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string $description
 * @property string|null $amount
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 */
#[UsePolicy(RequirementPolicy::class)]
#[UseFactory(RequirementFactory::class)]
class Requirement extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<RequirementFactory> */
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
            'amount' => 'decimal:2',
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
