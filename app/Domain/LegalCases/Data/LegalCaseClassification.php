<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Data\RequirementListData;

/**
 * What was read out of a narrative of facts: the area, the class, the reason
 * for each, the other party, what is being asked of the court, and what the
 * pleading can argue.
 *
 * Lives in LegalCases, and not in either catalogue, because it is exactly the
 * foreign keys of `legal_cases` plus the sentences the lawyer reads before
 * accepting them. Neither catalogue should own a decision about the other.
 *
 * Flat on purpose. The five steps return their own small objects so each stays
 * callable alone, but composing them here would make every consumer write
 * `$classification->proceduralClass->proceduralClass->code`. This is the wire
 * shape, and it is the only one — `PracticeAreaClassification` deliberately has
 * no `toArray()` of its own.
 *
 * `DefendantData` and `RequirementListData` are the exception that proves the
 * rule: neither is unwrapped, because each is already the shape the Action that
 * saves that step takes — `UpdateLegalCaseDefendant` and
 * `SaveLegalCaseRequirements`. Flattening them here would mean writing their
 * fields a third time.
 *
 * `LegalResearchData` is the third of those exceptions, and the one that is not
 * a step of the form: it is the sixth step of the assistant — a revisão forense
 * — arriving with the framing, and it keeps its own shape because
 * `SaveLegalCaseForensicReview` is what will eventually take it. It carries more
 * than the two lists the screen draws: the question that was researched, the
 * portals that were opened, what stayed unresolved and which citations the guard
 * refused. None of that has a column, and the screen is the only reader it has.
 *
 * Four of the six answers are nullable, for unrelated reasons. The class is
 * null when the selection step finds nothing to choose from — no area in the
 * catalogue is in that position today. The other three are null when their step
 * failed, and in every case that is a different thing from an answer with
 * nothing in it: a narrative that describes no defendant arrives as twelve
 * nulls inside a `DefendantData`, one that asks for nothing arrives as an empty
 * list inside a `RequirementListData`, and a research run that confirmed nothing
 * arrives as a `LegalResearchData` with empty lists and a written `pending`.
 */
final readonly class LegalCaseClassification
{
    public function __construct(
        public PracticeArea $practiceArea,
        public string $practiceAreaJustification,
        public ?ProceduralClass $proceduralClass,
        public ?string $proceduralClassJustification,
        public ?DefendantData $defendant,
        public ?RequirementListData $requirements,
        public ?LegalResearchData $research,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'practice_area' => [
                // Both ids are here to be written to `legal_cases` and nothing
                // else. They are generated when the catalogue migration loads,
                // so they differ between databases: never match on them, and
                // never write one into a fixture or a seed. The slug and the
                // CNJ code are the identifiers that survive a resync.
                'id' => $this->practiceArea->id,
                'slug' => $this->practiceArea->slug,
                'label' => $this->practiceArea->label,
            ],
            'practice_area_justification' => $this->practiceAreaJustification,
            'procedural_class' => $this->proceduralClass === null ? null : [
                'id' => $this->proceduralClass->id,
                'code' => $this->proceduralClass->code,
                'name' => $this->proceduralClass->name,
            ],
            'procedural_class_justification' => $this->proceduralClassJustification,
            // As doze chaves `defendant_*`, exatamente como a tabela as
            // escreve, ou nulo. A tela preenche os campos que vierem e deixa
            // os outros em branco, que é como a etapa do réu sempre abriu.
            'defendant' => $this->defendant?->toArray(),
            // Os pedidos que o relato sustenta, cada um com a frase que vai
            // para a peça e o valor em decimal — a tela mascara o valor no
            // campo e cunha o id de cada linha, que ainda não existe.
            'requirements' => $this->requirements?->toArray(),
            // A revisão forense: a questão pesquisada, as teses com os seus
            // fundamentos, os precedentes já achatados numa lista à parte — cada
            // um apontando para a tese que sustenta —, as fontes oficiais
            // abertas, o que ficou pendente e as citações que a guarda recusou.
            'research' => $this->research?->toArray(),
        ];
    }
}
