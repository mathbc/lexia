<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\PracticeAreas\Data\PracticeAreaClassification;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;

/**
 * O enquadramento de um caso: a área, e a classe escolhida dentro dela.
 *
 * Existe porque as duas etapas viraram **uma unidade de trabalho**. A classe só
 * pode ser escolhida entre as vinculadas à área, então as duas correm em série
 * dentro da mesma task de `ClassifyLegalCase` — e uma task devolve um valor, não
 * dois. Este objeto é esse valor.
 *
 * Não é payload, e por isso não tem `toArray()`: quem publica a forma de fio é
 * `LegalCaseClassification`, que continua sendo o único dono das chaves que a
 * tela lê. Este objeto nasce dentro do `handle()` e morre lá, depois de ser
 * desempacotado nos dois campos que sempre existiram.
 *
 * A área não é nula e a classe é, que é a assimetria do enquadramento: sem área
 * não há o que devolver — a rota responde 503 —, e sem classe o assistente abre
 * a lista da área para o advogado escolher. Ver `ClassifyLegalCase`.
 *
 * Os dois atravessam `serialize()` para voltar do processo filho, e isso é
 * contrato e não acidente: ambas as metades carregam o model de catálogo, e o
 * roundtrip preserva o `pivot` de onde `SelectProceduralClass` lê o `scope`.
 */
final readonly class LegalCaseFraming
{
    public function __construct(
        public PracticeAreaClassification $area,
        public ?ProceduralClassSelection $class,
    ) {}
}
