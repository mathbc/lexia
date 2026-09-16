<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Policies\LegalCasePolicy;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\LegalCaseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pleading an account drafts for one of its clients.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create, as it does for Customer.
 *
 * The two catalogue keys are what the CNJ taxonomy needs: the area the lawyer
 * picked and the class the matter is docketed under. Nothing here guarantees
 * the class belongs to the area; that pairing lives in the pivot and is the
 * Action's to validate.
 *
 * @property string $id
 * @property string $account_id
 * @property string $customer_id
 * @property string $practice_area_id
 * @property string $procedural_class_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read Customer $customer
 * @property-read PracticeArea $practiceArea
 * @property-read ProceduralClass $proceduralClass
 */
#[UsePolicy(LegalCasePolicy::class)]
#[UseFactory(LegalCaseFactory::class)]
class LegalCase extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<LegalCaseFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<PracticeArea, $this>
     */
    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }

    /**
     * @return BelongsTo<ProceduralClass, $this>
     */
    public function proceduralClass(): BelongsTo
    {
        return $this->belongsTo(ProceduralClass::class);
    }
}
