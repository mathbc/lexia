<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Models;

use App\Domain\LegalCases\Actions\RunAssistedFill;
use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Models\Concerns\TracksInferenceRun;
use App\Domain\LegalCases\Policies\AssistedFillPolicy;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One smart fill, between the click and the pleading it saved.
 *
 * The request that asks for it creates this row and queues RunAssistedFill;
 * the screen of the new pleading polls it, and the worker writes the outcome
 * here — see the migration for what each column holds, and TracksInferenceRun
 * for how the states move.
 *
 * It belongs to the user who asked, not only to the account: it carries the
 * narrative they typed, and AssistedFillPolicy shows it to nobody else.
 *
 * @property string $id
 * @property string $account_id
 * @property string $user_id
 * @property string|null $customer_id
 * @property string|null $legal_case_id
 * @property string|null $facts
 * @property InferenceRunStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $failure
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UsePolicy(AssistedFillPolicy::class)]
class AssistedFill extends Model
{
    use BelongsToAccount;
    use HasUuids;
    use TracksInferenceRun;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InferenceRunStatus::class,
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function timeout(): int
    {
        return RunAssistedFill::TIMEOUT;
    }
}
