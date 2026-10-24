<?php

declare(strict_types=1);

namespace App\Domain\AgentsExecutionLogs\Models;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent that fell, flattened for whoever has to find out why.
 *
 * Written only by `RecordAgentFailure`, which reads the exception chain into
 * these columns; see the migration for what each one holds. Append-only: there
 * is no `updated_at`, because a failure that happened does not change.
 *
 * The account is set explicitly by the writer and may be null. Most failures
 * happen in a queue worker or in a child process, where there is no
 * authenticated user for `BelongsToAccount` to fill it from — and the smart
 * fill's concurrent block runs before any account is in reach.
 *
 * @property string $id
 * @property string|null $account_id
 * @property string|null $legal_case_id
 * @property string $agent
 * @property string $operation
 * @property int|null $http_status
 * @property string|null $message
 * @property string $error
 * @property string|null $model
 * @property CarbonImmutable $created_at
 */
class AgentsExecutionLog extends Model
{
    use BelongsToAccount;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
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
