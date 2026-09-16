<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Documents\Policies\DocumentPolicy;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file that instructs a pleading: the contract, the power of attorney, the
 * receipt, the report.
 *
 * Owned by exactly one account — BelongsToAccount both scopes every query and
 * stamps `account_id` on create — and by exactly one pleading. The account key
 * is redundant with the pleading's own, and deliberately so: it is what lets
 * the global scope narrow the table without a join on every read.
 *
 * `name` is the original filename, extension included, and `extension` repeats
 * that suffix so a listing never has to take the string apart.
 *
 * Nothing here points at stored bytes yet. The form holds the files in the
 * browser until the Action that saves the pleading exists; the disk and the
 * path arrive with it.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string $name
 * @property string|null $description
 * @property string $extension
 * @property int $size
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 */
#[UsePolicy(DocumentPolicy::class)]
#[UseFactory(DocumentFactory::class)]
class Document extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<DocumentFactory> */
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
            'size' => 'integer',
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
