<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * The 29 competences the CNJ publishes in `procedural_classes.jurisdictions`.
 *
 * The raw values are opaque (`just_es_juizado_es_fp`, `just_tu_es_un`), so this
 * is where they gain a name and a place in the branch × degree grid the class
 * picker filters by. Two pairs read almost alike and are not the same thing:
 * `just_es_1grau_mil` is a military court inside the ordinary state justice,
 * while `just_mil_est_1grau` is the state military justice proper.
 *
 * 33 of the 615 classes carry an empty array — the CNJ simply did not say. They
 * are not an error to correct here; the picker decides whether a filter hides
 * them.
 */
enum Jurisdiction: string implements HasLabel
{
    use ProvidesOptions;

    case StateFirst = 'just_es_1grau';
    case StateSecond = 'just_es_2grau';
    case StateFirstMilitary = 'just_es_1grau_mil';
    case StateSecondMilitary = 'just_es_2grau_mil';
    case StateSmallClaims = 'just_es_juizado_es';
    case StateSmallClaimsTreasury = 'just_es_juizado_es_fp';
    case StatePanels = 'just_es_turmas';
    case StateUnification = 'just_tu_es_un';

    case FederalFirst = 'just_fed_1grau';
    case FederalSecond = 'just_fed_2grau';
    case FederalSmallClaims = 'just_fed_juizado_es';
    case FederalPanels = 'just_fed_turmas';
    case FederalRegionalUnification = 'just_fed_regional';
    case FederalNationalUnification = 'just_fed_nacional';

    case LaborFirst = 'just_trab_1grau';
    case LaborSecond = 'just_trab_2grau';
    case LaborSuperior = 'just_trab_tst';
    case LaborCouncil = 'just_trab_csjt';

    case ElectoralFirst = 'just_elei_1grau';
    case ElectoralSecond = 'just_elei_2grau';
    case ElectoralSuperior = 'just_elei_tse';

    case MilitaryStateFirst = 'just_mil_est_1grau';
    case MilitaryStateCourt = 'just_mil_est_tjm';
    case MilitaryUnionFirst = 'just_mil_uniao_1grau';
    case MilitaryUnionSuperior = 'just_mil_uniao_stm';

    case SupremeCourt = 'stf';
    case SuperiorCourt = 'stj';
    case NationalCouncil = 'cnj';
    case FederalCouncil = 'cjf';

    /**
     * A flat lookup table rather than four 29-arm matches: this is data, and a
     * match here would score 30 on cyclomatic complexity while carrying no
     * branch risk at all. Same reasoning as BrazilianState::NAMES.
     *
     * `short` is what fits on a badge in the class card; `label` is the full
     * name, which the card carries as a title attribute.
     *
     * @var array<string, array{label: string, short: string, branch: JusticeBranch, degree: JurisdictionDegree}>
     */
    private const array MAP = [
        'just_es_1grau' => ['label' => 'Justiça Estadual — 1º grau', 'short' => 'Estadual 1º', 'branch' => JusticeBranch::State, 'degree' => JurisdictionDegree::First],
        'just_es_2grau' => ['label' => 'Justiça Estadual — 2º grau', 'short' => 'Estadual 2º', 'branch' => JusticeBranch::State, 'degree' => JurisdictionDegree::Second],
        'just_es_juizado_es' => ['label' => 'Justiça Estadual — Juizado Especial', 'short' => 'Juizado estadual', 'branch' => JusticeBranch::State, 'degree' => JurisdictionDegree::SmallClaims],
        'just_es_juizado_es_fp' => ['label' => 'Justiça Estadual — Juizado Especial da Fazenda Pública', 'short' => 'Juizado da Fazenda', 'branch' => JusticeBranch::State, 'degree' => JurisdictionDegree::SmallClaims],
        'just_es_turmas' => ['label' => 'Justiça Estadual — Turmas Recursais', 'short' => 'Turmas estaduais', 'branch' => JusticeBranch::State, 'degree' => JurisdictionDegree::Panels],
        'just_tu_es_un' => ['label' => 'Justiça Estadual — Turma de Uniformização', 'short' => 'Uniformização estadual', 'branch' => JusticeBranch::State, 'degree' => JurisdictionDegree::Panels],

        'just_fed_1grau' => ['label' => 'Justiça Federal — 1º grau', 'short' => 'Federal 1º', 'branch' => JusticeBranch::Federal, 'degree' => JurisdictionDegree::First],
        'just_fed_2grau' => ['label' => 'Justiça Federal — 2º grau', 'short' => 'Federal 2º', 'branch' => JusticeBranch::Federal, 'degree' => JurisdictionDegree::Second],
        'just_fed_juizado_es' => ['label' => 'Justiça Federal — Juizado Especial', 'short' => 'Juizado federal', 'branch' => JusticeBranch::Federal, 'degree' => JurisdictionDegree::SmallClaims],
        'just_fed_turmas' => ['label' => 'Justiça Federal — Turmas Recursais', 'short' => 'Turmas federais', 'branch' => JusticeBranch::Federal, 'degree' => JurisdictionDegree::Panels],
        'just_fed_regional' => ['label' => 'Justiça Federal — Turma Regional de Uniformização', 'short' => 'TRU', 'branch' => JusticeBranch::Federal, 'degree' => JurisdictionDegree::Panels],
        'just_fed_nacional' => ['label' => 'Justiça Federal — Turma Nacional de Uniformização', 'short' => 'TNU', 'branch' => JusticeBranch::Federal, 'degree' => JurisdictionDegree::Panels],

        'just_trab_1grau' => ['label' => 'Justiça do Trabalho — 1º grau', 'short' => 'Trabalho 1º', 'branch' => JusticeBranch::Labor, 'degree' => JurisdictionDegree::First],
        'just_trab_2grau' => ['label' => 'Justiça do Trabalho — 2º grau', 'short' => 'Trabalho 2º', 'branch' => JusticeBranch::Labor, 'degree' => JurisdictionDegree::Second],
        'just_trab_tst' => ['label' => 'Tribunal Superior do Trabalho', 'short' => 'TST', 'branch' => JusticeBranch::Labor, 'degree' => JurisdictionDegree::Superior],
        'just_trab_csjt' => ['label' => 'Conselho Superior da Justiça do Trabalho', 'short' => 'CSJT', 'branch' => JusticeBranch::Labor, 'degree' => JurisdictionDegree::Council],

        'just_elei_1grau' => ['label' => 'Justiça Eleitoral — 1º grau', 'short' => 'Eleitoral 1º', 'branch' => JusticeBranch::Electoral, 'degree' => JurisdictionDegree::First],
        'just_elei_2grau' => ['label' => 'Justiça Eleitoral — 2º grau', 'short' => 'Eleitoral 2º', 'branch' => JusticeBranch::Electoral, 'degree' => JurisdictionDegree::Second],
        'just_elei_tse' => ['label' => 'Tribunal Superior Eleitoral', 'short' => 'TSE', 'branch' => JusticeBranch::Electoral, 'degree' => JurisdictionDegree::Superior],

        'just_es_1grau_mil' => ['label' => 'Justiça Estadual — 1º grau militar', 'short' => 'Estadual 1º militar', 'branch' => JusticeBranch::Military, 'degree' => JurisdictionDegree::First],
        'just_es_2grau_mil' => ['label' => 'Justiça Estadual — 2º grau militar', 'short' => 'Estadual 2º militar', 'branch' => JusticeBranch::Military, 'degree' => JurisdictionDegree::Second],
        'just_mil_est_1grau' => ['label' => 'Justiça Militar Estadual — 1º grau', 'short' => 'Militar estadual 1º', 'branch' => JusticeBranch::Military, 'degree' => JurisdictionDegree::First],
        'just_mil_est_tjm' => ['label' => 'Tribunal de Justiça Militar', 'short' => 'TJM', 'branch' => JusticeBranch::Military, 'degree' => JurisdictionDegree::Second],
        'just_mil_uniao_1grau' => ['label' => 'Justiça Militar da União — 1º grau', 'short' => 'Militar União 1º', 'branch' => JusticeBranch::Military, 'degree' => JurisdictionDegree::First],
        'just_mil_uniao_stm' => ['label' => 'Superior Tribunal Militar', 'short' => 'STM', 'branch' => JusticeBranch::Military, 'degree' => JurisdictionDegree::Superior],

        'stf' => ['label' => 'Supremo Tribunal Federal', 'short' => 'STF', 'branch' => JusticeBranch::Superior, 'degree' => JurisdictionDegree::Superior],
        'stj' => ['label' => 'Superior Tribunal de Justiça', 'short' => 'STJ', 'branch' => JusticeBranch::Superior, 'degree' => JurisdictionDegree::Superior],
        'cnj' => ['label' => 'Conselho Nacional de Justiça', 'short' => 'CNJ', 'branch' => JusticeBranch::Superior, 'degree' => JurisdictionDegree::Council],
        'cjf' => ['label' => 'Conselho da Justiça Federal', 'short' => 'CJF', 'branch' => JusticeBranch::Superior, 'degree' => JurisdictionDegree::Council],
    ];

    public function label(): string
    {
        return self::MAP[$this->value]['label'];
    }

    /** What fits on a badge: "Estadual 1º", "TST", "STF". */
    public function shortLabel(): string
    {
        return self::MAP[$this->value]['short'];
    }

    public function branch(): JusticeBranch
    {
        return self::MAP[$this->value]['branch'];
    }

    public function degree(): JurisdictionDegree
    {
        return self::MAP[$this->value]['degree'];
    }

    /**
     * The competence as the class picker consumes it: a badge to draw and the
     * two keys it filters on.
     *
     * @return array{value: string, label: string, short_label: string, branch: string, degree: string}
     */
    public function toTag(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'short_label' => $this->shortLabel(),
            'branch' => $this->branch()->value,
            'degree' => $this->degree()->value,
        ];
    }
}
