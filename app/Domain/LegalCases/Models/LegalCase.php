<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Models;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Enums\CustomerType;
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
 * The `defendant_*` columns describe the other party inline. They are all
 * nullable because a defendant is described rather than registered: what the
 * lawyer knows at drafting time is often a name and little else, and
 * `defendant_notes` is where the rest of that partial knowledge goes.
 *
 * `facts` is the first column the lawyer writes rather than selects: the
 * narrative the pleading is built on, held as plain text and nullable like the
 * rest, because the form fills it one step at a time.
 *
 * @property string $id
 * @property string $account_id
 * @property string $customer_id
 * @property string $practice_area_id
 * @property string $procedural_class_id
 * @property string|null $defendant_name
 * @property string|null $defendant_document
 * @property string|null $defendant_email
 * @property string|null $defendant_phone
 * @property string|null $defendant_postal_code
 * @property string|null $defendant_street
 * @property string|null $defendant_number
 * @property string|null $defendant_complement
 * @property string|null $defendant_district
 * @property string|null $defendant_city
 * @property BrazilianState|null $defendant_state
 * @property string|null $defendant_notes
 * @property string|null $facts
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'defendant_state' => BrazilianState::class,
        ];
    }

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

    /**
     * Which document the defendant was identified by, if any.
     *
     * The column holds digits only and does not say which kind it is; the
     * length does, because a CPF has eleven and a CNPJ fourteen. Nothing is
     * validated here — this only reads back what was stored.
     */
    public function defendantDocumentType(): ?CustomerType
    {
        return match (strlen((string) $this->defendant_document)) {
            11 => CustomerType::Individual,
            14 => CustomerType::Company,
            default => null,
        };
    }
}
