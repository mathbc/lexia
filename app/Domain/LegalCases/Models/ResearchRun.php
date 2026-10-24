<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Models;

use App\Domain\LegalCases\Enums\InferenceRunStatus;
use App\Domain\LegalCases\Enums\ResearchSubject;
use App\Domain\LegalCases\Models\Concerns\TracksInferenceRun;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One research of a saved pleading, on one subject, in the queue.
 *
 * Queued by RunLegalCaseForensicReview (the fifth step's tabs) or
 * RunLegalCaseJurisprudence (the sixth step), answered by the same Actions
 * running as jobs. It says only whether the subject is being researched and
 * whether the last attempt failed; whether it was ever researched is still the
 * marker column on the pleading. See the migration.
 *
 * No policy of its own: nothing addresses a run by URL. It reaches the screen
 * through the pleading's props, behind the pleading's policy.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property ResearchSubject $subject
 * @property InferenceRunStatus $status
 * @property string|null $failure
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read LegalCase $legalCase
 */
class ResearchRun extends Model
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
            'subject' => ResearchSubject::class,
            'status' => InferenceRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function timeout(): int
    {
        return $this->subject->timeout();
    }

    /**
     * Queues the subjects asked for, minus the ones already on their way.
     *
     * The pleading's row is locked first, so two claims for the same pleading —
     * a double click, the step open in two browser tabs — take turns, and the
     * second sees the runs the first created. Within the lock, a run in flight
     * that is no longer believed (never picked up, or killed with its worker)
     * is marked failed and replaced; one that is still believed keeps its
     * subject, and nothing new is queued for it. The partial unique index is
     * the net under all of this, not the mechanism.
     *
     * The caller dispatches **after** this returns: the queue connection does
     * not wait for commits, and a worker handed the ids inside the transaction
     * could look for rows it cannot see yet.
     *
     * @param  list<ResearchSubject>  $subjects
     * @return list<self> the runs created, in the order asked
     */
    public static function claim(LegalCase $legalCase, array $subjects): array
    {
        return DB::transaction(static function () use ($legalCase, $subjects): array {
            LegalCase::acrossAllAccounts()->whereKey($legalCase->id)->lockForUpdate()->first();

            $inFlight = self::acrossAllAccounts()
                ->where('legal_case_id', $legalCase->id)
                ->whereIn('status', [InferenceRunStatus::Queued->value, InferenceRunStatus::Running->value])
                ->get()
                ->reject(static fn (self $run): bool => $run->expire())
                ->map(static fn (self $run): ResearchSubject => $run->subject)
                ->all();

            $created = [];

            foreach ($subjects as $subject) {
                if (in_array($subject, $inFlight, true)) {
                    continue;
                }

                $created[] = self::query()->create([
                    'account_id' => $legalCase->account_id,
                    'legal_case_id' => $legalCase->id,
                    'subject' => $subject,
                    'status' => InferenceRunStatus::Queued,
                ]);
            }

            return $created;
        });
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class);
    }
}
