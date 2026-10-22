<?php

declare(strict_types=1);

namespace App\Domain\Doctrines\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Doctrines\Policies\DoctrinePolicy;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Shared\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Database\Factories\DoctrineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One work of legal scholarship a pleading may lean on.
 *
 * The third tab of the fifth step, "Doutrinas". A book, a journal article or a
 * chapter, as the Rede Virtual de Bibliotecas catalogues it in the LexML — the
 * **reference**, not the text. Doctrine is complementary grounding: it explains
 * how a norm is read and reinforces a thesis, and it is neither the law nor a
 * ruling. What a pleading may say about it is who wrote what, and where; never
 * what the author holds, since nobody here has read the work.
 *
 * Hence the columns. Everything but `relevance` is the record, read from the
 * `/urn/` page by DoctrineRecordReader — see the migration for the mapping —, and
 * `relevance` is the research agent's note on why the *subject* of the work
 * meets the case, labelled as the AI's on the screen.
 *
 * Owned by exactly one account and one pleading, with the redundant
 * `account_id` every child of LegalCase carries for the global scope.
 *
 * Written in exactly one place: SaveLegalCaseDoctrines, which reconciles the
 * whole list as a diff. Two callers reach it — ResearchLegalCaseForensicReview,
 * with what the LexML confirmed, and FinalizeLegalCase, with what survived the
 * lawyer's reading. No route binds a row, hence no `update` or `delete` on the
 * policy: unticking a work is the list being posted without it.
 *
 * @property string $id
 * @property string $account_id
 * @property string $legal_case_id
 * @property string|null $kind
 * @property string $author
 * @property string $title
 * @property string|null $published_in
 * @property string|null $summary
 * @property string|null $subject
 * @property string|null $publication
 * @property string|null $relevance
 * @property string $source_url
 * @property string|null $urn
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read LegalCase $legalCase
 */
#[UsePolicy(DoctrinePolicy::class)]
#[UseFactory(DoctrineFactory::class)]
class Doctrine extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<DoctrineFactory> */
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
            'position' => 'integer',
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
