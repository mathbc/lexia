<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Models;

use App\Domain\LegalThemes\Enums\JudgingBody;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use App\Domain\Shared\Casts\AsVector;
use Carbon\CarbonImmutable;
use Database\Factories\LegalThemeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A qualified precedent of the STJ: a Tema Repetitivo, or one of the
 * instruments filed beside it (see LegalThemeType).
 *
 * Reference data, like ProceduralClass: shared by every account, written only
 * by ImportLegalThemes. `sequential_number` is the STJ's id and the key that
 * survives a re-import; the uuid is ours. `type` + `number` is how a lawyer
 * names it — "Tema 1016".
 *
 * @property string $id
 * @property int $sequential_number
 * @property LegalThemeType $type
 * @property int $number
 * @property CarbonImmutable|null $first_assigned_on
 * @property CarbonImmutable|null $judged_on
 * @property CarbonImmutable|null $judgment_published_on
 * @property string $status
 * @property string|null $additional_information
 * @property string $question
 * @property string|null $settled_thesis
 * @property string|null $nugepnac_notes
 * @property string|null $judgment_scope
 * @property string|null $previous_understanding
 * @property string|null $legislative_reference
 * @property int|null $referenced_sumula
 * @property int|null $originated_sumula
 * @property bool|null $public_hearing
 * @property CarbonImmutable|null $public_hearing_on
 * @property JudgingBody|null $judging_body
 * @property list<array{code: int, name: string}> $subjects
 * @property list<float>|null $embedding
 * @property string|null $embedding_hash
 * @property CarbonImmutable|null $embedded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, GeneralRepercussion> $generalRepercussions
 */
#[UseFactory(LegalThemeFactory::class)]
class LegalTheme extends Model
{
    /** @use HasFactory<LegalThemeFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequential_number' => 'integer',
            'type' => LegalThemeType::class,
            'number' => 'integer',
            'first_assigned_on' => 'immutable_date',
            'judged_on' => 'immutable_date',
            'judgment_published_on' => 'immutable_date',
            'referenced_sumula' => 'integer',
            'originated_sumula' => 'integer',
            'public_hearing' => 'boolean',
            'public_hearing_on' => 'immutable_date',
            'judging_body' => JudgingBody::class,
            'subjects' => 'array',
            'embedding' => AsVector::class,
            'embedded_at' => 'immutable_datetime',
        ];
    }

    /**
     * The key a prompt names this precedent by: `theme-1016`, `puil-5`.
     *
     * Never the uuid, for the reason the procedural classes go by their CNJ
     * code: the uuid is ours, differs between databases and means nothing to a
     * model. Nor the bare number, which is not unique — Tema 5 and PUIL 5 are
     * different precedents. Type and number are, by the unique index, and they
     * are how the heading beside the key reads, so the model has nothing to
     * translate.
     */
    public function reference(): string
    {
        return "{$this->type->value}-{$this->number}";
    }

    /**
     * How a lawyer names it: "Tema Repetitivo 1016".
     */
    public function heading(): string
    {
        return "{$this->type->label()} {$this->number}";
    }

    /**
     * The STF general repercussions the STJ lists beside this precedent.
     *
     * @return HasMany<GeneralRepercussion, $this>
     */
    public function generalRepercussions(): HasMany
    {
        return $this->hasMany(GeneralRepercussion::class)->orderBy('number');
    }
}
