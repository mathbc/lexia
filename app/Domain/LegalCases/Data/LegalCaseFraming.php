<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\PracticeAreas\Data\PracticeAreaClassification;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;

/**
 * O enquadramento de um caso: a área, a classe escolhida dentro dela, e se o
 * caso pede tutela de urgência.
 *
 * Existe porque as três etapas viraram **uma unidade de trabalho**. A classe só
 * pode ser escolhida entre as vinculadas à área, e a tutela só pode ser julgada
 * sabendo a classe — uma possessória de força nova, um despejo ou uma ação de
 * alimentos trazem liminar própria —, então as três correm em série dentro da
 * mesma task de `ClassifyLegalCase`. E uma task devolve um valor, não três.
 * Este objeto é esse valor.
 *
 * Não é payload, e por isso não tem `toArray()`: quem publica a forma de fio é
 * `LegalCaseClassification`, que continua sendo o único dono das chaves que a
 * tela lê. Este objeto nasce dentro do `handle()` e morre lá, depois de ser
 * desempacotado nos campos que ela publica.
 *
 * A área não é nula e as outras duas são, que é a assimetria do enquadramento:
 * sem área não há o que devolver — a rota responde 503 —, sem classe o
 * assistente abre a lista da área para o advogado escolher, e sem sugestão de
 * tutela a etapa 1 oferece o "Consultar IA". Ver `ClassifyLegalCase`.
 *
 * Os três atravessam `serialize()` para voltar do processo filho, e isso é
 * contrato e não acidente: as duas primeiras metades carregam o model de
 * catálogo, e o roundtrip preserva o `pivot` de onde `SelectProceduralClass` lê
 * o `scope`. A sugestão de tutela só carrega escalares e um enum.
 */
final readonly class LegalCaseFraming
{
    public function __construct(
        public PracticeAreaClassification $area,
        public ?ProceduralClassSelection $class,
        public ?InjunctiveReliefSuggestionData $injunctiveRelief = null,
    ) {}
}
