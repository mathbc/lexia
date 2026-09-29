<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Models;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Models\Document;
use App\Domain\JudicialSystems\Models\JudicialSystem;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Policies\LegalCasePolicy;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalThemes\Models\LegalCaseTheme;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use App\Domain\Shared\Concerns\BelongsToAccount;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\LegalCaseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * `injunctive_relief` rides along with it, and is a flag rather than a guess
 * made from the description beside it: asking for an injunction is a decision,
 * and the text that justifies it is written, erased and rewritten while the
 * decision holds. False is the answer until the lawyer says otherwise.
 *
 * The urgent-relief agent may **suggest** that decision — the smart fill opens
 * the first step with the box ticked and the text written when it recommends
 * one —, but it is still the lawyer's save that writes the flag, and unticking
 * it still erases the text. `injunctive_relief_suggestion` is the provenance
 * kept beside them: what the agent said, and when, so the screen can go on
 * calling that text an AI suggestion after a reload.
 *
 * The requests it makes and the documents that instruct it are rows of their
 * own, unlike the defendant: there are many of each, they are written and
 * described separately, and they are added and removed one at a time.
 *
 * `court_addressing` is the line the document opens with — "Ao Juízo da 3ª Vara
 * Cível da Comarca de Florianópolis/SC" — and is text rather than a key into a
 * table of courts, because no such table exists here and the wording varies
 * with the branch and the local habit.
 *
 * The electronic system it will be filed through is the opposite case: eproc,
 * PJe, e-SAJ are a short closed list, so `judicial_system_id` is a key into
 * the catalogue and not text. Nullable, because the form lets it wait and the
 * rows written before it existed were left without one.
 *
 * `current_step` and `is_draft` are what make an unfinished pleading a first
 * class thing rather than an accident. The form saves one step at a time, so
 * the row exists long before it is complete: the step is a high-water mark —
 * the furthest point reached, which is what the listing reports and what the
 * form reopens on — and the flag says whether anyone has called it finished.
 * The two are deliberately independent: reaching the last step is not the same
 * as declaring the pleading done.
 *
 * @property string $id
 * @property string $account_id
 * @property string|null $user_id
 * @property string $customer_id
 * @property string $practice_area_id
 * @property string $procedural_class_id
 * @property string|null $judicial_system_id
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
 * @property string|null $court_addressing
 * @property string|null $facts
 * @property bool $injunctive_relief
 * @property string|null $injunctive_relief_description
 * @property array<string, mixed>|null $injunctive_relief_suggestion
 * @property LegalCaseStep $current_step
 * @property bool $is_draft
 * @property array<string, mixed>|null $research_findings
 * @property array<string, mixed>|null $court_decision_findings
 * @property array<string, mixed>|null $theme_findings
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Account $account
 * @property-read User|null $user
 * @property-read Customer $customer
 * @property-read PracticeArea $practiceArea
 * @property-read ProceduralClass $proceduralClass
 * @property-read JudicialSystem|null $judicialSystem
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, Requirement> $requirements
 * @property-read Collection<int, LegalThesis> $theses
 * @property-read Collection<int, LegalPrecedent> $precedents
 * @property-read Collection<int, CourtDecision> $courtDecisions
 * @property-read Collection<int, LegalTheme> $themes
 * @property-read Collection<int, LegalPleading> $pleadings
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
            'injunctive_relief' => 'boolean',
            'injunctive_relief_suggestion' => 'array',
            'current_step' => LegalCaseStep::class,
            'is_draft' => 'boolean',
            'research_findings' => 'array',
            'court_decision_findings' => 'array',
            'theme_findings' => 'array',
        ];
    }

    /**
     * Who opened the pleading. Null for the rows that predate the column —
     * see the migration that added it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return BelongsTo<JudicialSystem, $this>
     */
    public function judicialSystem(): BelongsTo
    {
        return $this->belongsTo(JudicialSystem::class);
    }

    /**
     * The files that instruct the pleading.
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * What the pleading asks the court for.
     *
     * Oldest first, because that is the order they were written in and the
     * order they will be numbered in — "requer: 1. …; 2. …". Nothing stronger
     * is promised: two requests written in the same second have no order
     * between them, and the column that would fix that arrives when the screen
     * offers reordering.
     *
     * @return HasMany<Requirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class)->oldest();
    }

    /**
     * The lines of argument the pleading will carry — the forensic review.
     *
     * Oldest first, like the requirements: insertion order is the order they
     * were written and the order the drafted document will make them, and there
     * is no position column until the screen offers reordering.
     *
     * @return HasMany<LegalThesis, $this>
     */
    public function theses(): HasMany
    {
        return $this->hasMany(LegalThesis::class)->oldest();
    }

    /**
     * The rulings found to sustain what it argues.
     *
     * Hung off the pleading and not only off the theses, because a ruling can be
     * found before the thesis it will sustain exists — `legal_thesis_id` is
     * nullable, and an unattached precedent still belongs to this pleading.
     *
     * @return HasMany<LegalPrecedent, $this>
     */
    public function precedents(): HasMany
    {
        return $this->hasMany(LegalPrecedent::class)->oldest();
    }

    /**
     * The rulings the courts have already handed down in cases like this one.
     *
     * Oldest first, like the theses and the requirements: insertion order is
     * the order the research returned them, which is the order the screen
     * numbers them in, and there is no position column until something offers
     * reordering.
     *
     * Note what it does *not* hang off: a thesis. That is the whole difference
     * from `precedents()` — a precedent is a finding about an argument, while a
     * court decision is the document itself, cited before anybody has decided
     * what it will be used for. See the CourtDecision model.
     *
     * @return HasMany<CourtDecision, $this>
     */
    public function courtDecisions(): HasMany
    {
        return $this->hasMany(CourtDecision::class)->oldest();
    }

    /**
     * The STJ themes the pleading leans on — the fifth step's second tab.
     *
     * The one relation here that crosses into the catalogue, which is why it is
     * a `BelongsToMany` and not a `HasMany`: the theme is reference data shared
     * by every account, and what belongs to this pleading is only the link and
     * the reason it was made. The link is a model of its own, `LegalCaseTheme`,
     * so that it mints its uuid on `sync()` like every other row.
     *
     * Ordered by the pivot's `position`, which is the selection's ranking: the
     * agent returns the themes from the most to the least relevant, and the
     * lawyer reads them — and keeps them — in that order. The insertion order
     * breaks ties, which only links written before the column existed have.
     *
     * @return BelongsToMany<LegalTheme, $this, LegalCaseTheme, 'pivot'>
     */
    public function themes(): BelongsToMany
    {
        return $this->belongsToMany(LegalTheme::class, 'legal_case_themes')
            ->using(LegalCaseTheme::class)
            ->withPivot(['id', 'account_id', 'reason', 'position'])
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderByPivot('created_at');
    }

    /**
     * The drafted document, newest version first.
     *
     * The one relation here that is not `->oldest()`, and the exception is the
     * point: the requests and the theses are read as a list in the order they
     * were written, while of the drafts the last one is what the tab opens on.
     * Editing never overwrites — a save writes the next version — so "newest
     * first" is what the screen asks for and `pleadings->first()` is the current
     * text.
     *
     * @return HasMany<LegalPleading, $this>
     */
    public function pleadings(): HasMany
    {
        return $this->hasMany(LegalPleading::class)->orderByDesc('version');
    }

    /**
     * The version a URL names, or the current one when it names none.
     *
     * The Minuta tab and both of its exports read through here, so the file a
     * lawyer downloads is always the version on screen. A named version that
     * does not exist is a 404 rather than the current text: versions are never
     * deleted, so a missing one is a mistyped URL, and answering it with another
     * version would print a document nobody asked for.
     */
    public function pleadingVersion(?int $version = null): ?LegalPleading
    {
        if ($version === null) {
            return $this->pleadings()->first();
        }

        return $this->pleadings()->where('version', $version)->firstOrFail();
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
