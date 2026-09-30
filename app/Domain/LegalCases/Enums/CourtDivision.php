<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\ProceduralClasses\Enums\Jurisdiction;
use App\Domain\ProceduralClasses\Enums\JurisdictionDegree;
use App\Domain\ProceduralClasses\Enums\JusticeBranch;
use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The first-degree court a pleading is addressed to — "Vara Cível",
 * "Juizado Especial Federal", "Vara do Trabalho".
 *
 * What the addressing agent chooses instead of writing the addressing itself.
 * The case carries the branch of the Judiciary with it, so one choice decides
 * both whose judge is addressed ("Juiz(a) de Direito", "Juiz(a) Federal",
 * "Juiz(a) do Trabalho") and how the seat is named (comarca, subseção, the
 * city of the Vara do Trabalho). CourtAddressingSuggestionData composes the
 * sentence out of these parts, so the gender-neutral form never depends on a
 * model remembering it.
 *
 * The list is generic on purpose. State judicial organisation varies — "Vara
 * de Família e Sucessões" in one state, "Vara de Órfãos e Sucessões" in the
 * next, "Vara Única" in a small comarca — and no table here could follow it.
 * The addressing is a suggestion the lawyer edits, and the specialised court
 * is the one worth suggesting.
 *
 * `allowedBy()` is where the procedural class narrows the choice: its CNJ
 * competences say which branches and which degree it runs in, so a
 * Procedimento Comum Cível never lands in a Juizado and an Ação Trabalhista
 * never leaves the Justiça do Trabalho. The agent's `enum` is built from it.
 */
enum CourtDivision: string implements HasLabel
{
    use ProvidesOptions;

    case Civil = 'civil';
    case Family = 'family';
    case PublicTreasury = 'public_treasury';
    case PublicRecords = 'public_records';
    case Business = 'business';
    case Bankruptcy = 'bankruptcy';
    case TaxEnforcement = 'tax_enforcement';
    case Childhood = 'childhood';
    case DomesticViolence = 'domestic_violence';
    case Criminal = 'criminal';
    case CriminalEnforcement = 'criminal_enforcement';
    case SmallClaims = 'small_claims';
    case TreasurySmallClaims = 'treasury_small_claims';
    case CriminalSmallClaims = 'criminal_small_claims';

    case Federal = 'federal';
    case FederalSmallClaims = 'federal_small_claims';

    case Labor = 'labor';

    /**
     * A flat lookup table for the same reason Jurisdiction keeps one: this is
     * data, and a seventeen-arm match per method would score seventeen on
     * cyclomatic complexity while carrying no branch risk at all.
     *
     * `article` is the preposition the sentence needs — "da Vara", "do
     * Juizado" — and `small_claims` is the degree, which is what separates a
     * Juizado from a Vara of the same branch.
     *
     * @var array<string, array{label: string, article: string, branch: JusticeBranch, small_claims: bool}>
     */
    private const array MAP = [
        'civil' => ['label' => 'Vara Cível', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'family' => ['label' => 'Vara de Família e Sucessões', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'public_treasury' => ['label' => 'Vara da Fazenda Pública', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'public_records' => ['label' => 'Vara de Registros Públicos', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'business' => ['label' => 'Vara Empresarial', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'bankruptcy' => ['label' => 'Vara de Falências e Recuperações Judiciais', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'tax_enforcement' => ['label' => 'Vara de Execuções Fiscais', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'childhood' => ['label' => 'Vara da Infância e da Juventude', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'domestic_violence' => ['label' => 'Juizado de Violência Doméstica e Familiar contra a Mulher', 'article' => 'do', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'criminal' => ['label' => 'Vara Criminal', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'criminal_enforcement' => ['label' => 'Vara de Execuções Penais', 'article' => 'da', 'branch' => JusticeBranch::State, 'small_claims' => false],
        'small_claims' => ['label' => 'Juizado Especial Cível', 'article' => 'do', 'branch' => JusticeBranch::State, 'small_claims' => true],
        'treasury_small_claims' => ['label' => 'Juizado Especial da Fazenda Pública', 'article' => 'do', 'branch' => JusticeBranch::State, 'small_claims' => true],
        'criminal_small_claims' => ['label' => 'Juizado Especial Criminal', 'article' => 'do', 'branch' => JusticeBranch::State, 'small_claims' => true],

        'federal' => ['label' => 'Vara Federal', 'article' => 'da', 'branch' => JusticeBranch::Federal, 'small_claims' => false],
        'federal_small_claims' => ['label' => 'Juizado Especial Federal', 'article' => 'do', 'branch' => JusticeBranch::Federal, 'small_claims' => true],

        'labor' => ['label' => 'Vara do Trabalho', 'article' => 'da', 'branch' => JusticeBranch::Labor, 'small_claims' => false],
    ];

    public function label(): string
    {
        return self::MAP[$this->value]['label'];
    }

    public function branch(): JusticeBranch
    {
        return self::MAP[$this->value]['branch'];
    }

    /** "da Vara Cível", "do Juizado Especial Cível". */
    public function withArticle(): string
    {
        return self::MAP[$this->value]['article'].' '.$this->label();
    }

    /**
     * Whose judge the pleading addresses, in the gender-neutral form: the
     * person on the bench is unknown until the case is distributed.
     */
    public function judge(): string
    {
        return match ($this->branch()) {
            JusticeBranch::Federal => 'Juiz(a) Federal',
            JusticeBranch::Labor => 'Juiz(a) do Trabalho',
            default => 'Juiz(a) de Direito',
        };
    }

    /**
     * The courts a class can be filed in, out of its CNJ competences.
     *
     * A competence counts when it is first degree or small claims — the two
     * places an initial petition is filed —; second degree, panels, superior
     * courts and councils name nothing here. A class with no competences on
     * record (33 of them) or no class at all opens the whole list, the same
     * fallback `processual-geral` gets in the class selection: an empty list
     * would be an empty `enum`, which is invalid grammar.
     *
     * A class that only runs in the electoral or military courts, or only
     * originally in a tribunal, gets an empty list, and that is an answer: the
     * caller says the addressing is out of reach instead of asking.
     *
     * @param  list<string>  $jurisdictions  the raw `procedural_classes.jurisdictions`
     * @return list<self>
     */
    public static function allowedBy(array $jurisdictions): array
    {
        if ($jurisdictions === []) {
            return self::cases();
        }

        $keys = array_filter(array_map(
            static fn (string $value): ?string => self::keyOf(Jurisdiction::tryFrom($value)),
            $jurisdictions,
        ));

        return array_values(array_filter(
            self::cases(),
            static fn (self $division): bool => in_array($division->key(), $keys, true),
        ));
    }

    private function key(): string
    {
        return $this->branch()->value.(self::MAP[$this->value]['small_claims'] ? ':small' : ':first');
    }

    private static function keyOf(?Jurisdiction $jurisdiction): ?string
    {
        if ($jurisdiction === null) {
            return null;
        }

        return match ($jurisdiction->degree()) {
            JurisdictionDegree::First => $jurisdiction->branch()->value.':first',
            JurisdictionDegree::SmallClaims => $jurisdiction->branch()->value.':small',
            default => null,
        };
    }
}
