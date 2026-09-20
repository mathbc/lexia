<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client of an account: the person or company whose matters it handles.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create, so a client can never land in the wrong
 * tenant by omission.
 *
 * @property string $id
 * @property string $account_id
 * @property string $name
 * @property string|null $legal_name
 * @property CustomerType $type
 * @property string|null $cpf
 * @property string|null $cnpj
 * @property MaritalStatus|null $marital_status
 * @property string|null $occupation
 * @property CarbonImmutable|null $birth_date
 * @property string $email
 * @property string $phone
 * @property string $postal_code
 * @property string $street
 * @property string $number
 * @property string|null $complement
 * @property string $district
 * @property string $city
 * @property BrazilianState $state
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 */
#[UsePolicy(CustomerPolicy::class)]
#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<CustomerFactory> */
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
            'type' => CustomerType::class,
            'marital_status' => MaritalStatus::class,
            'birth_date' => 'date',
            'state' => BrazilianState::class,
        ];
    }

    /**
     * The document that legally identifies this client.
     */
    public function identifier(): ?string
    {
        return $this->type->requiresCnpj() ? $this->cnpj : $this->cpf;
    }

    /**
     * What to show as the client's name: a company trades under its corporate
     * name, a person under their own.
     */
    public function displayName(): string
    {
        return $this->legal_name ?? $this->name;
    }
}
