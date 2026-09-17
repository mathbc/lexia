<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Queries;

use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Support\Collection;

/**
 * Decide quais candidatas o prompt pode descrever por inteiro.
 *
 * Percorre a lista já ordenada por relevância e gasta o orçamento até acabar.
 * As que sobram chegam ao agente só com nome e código — continuam no `enum`,
 * então isto nunca remove uma resposta possível, só a explicação dela.
 *
 * Por tamanho, e não por contagem, porque é o tamanho que é o limite de
 * verdade: com as descrições enriquecidas, 14 das 24 áreas cabem inteiras e não
 * perdem nada, enquanto Família (54 candidatas, 33 KB) precisaria ser cortada
 * de qualquer jeito. Um teto por contagem cortaria as duas do mesmo modo.
 *
 * Classe própria, e não um método privado da Action, porque é aqui que mora a
 * garantia de que o prompt cabe na janela — e uma garantia dessas merece ser
 * verificada diretamente.
 */
final class DescribedCandidateBudget
{
    public function __construct(
        private readonly int $budget,
        private readonly int $minimum,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            budget: (int) config('ai.retrieval.description_budget', 16000),
            minimum: (int) config('ai.retrieval.minimum_described', 10),
        );
    }

    /**
     * Quantas das candidatas, na ordem em que chegam, cabem descritas.
     *
     * @param  Collection<int, ProceduralClass>  $ranked
     */
    public function describedCount(Collection $ranked): int
    {
        $spent = 0;
        $described = 0;

        foreach ($ranked->values() as $position => $class) {
            // O piso protege contra descrições anormalmente longas nas
            // primeiras posições esvaziarem a lista inteira.
            if ($position >= $this->minimum && $spent >= $this->budget) {
                break;
            }

            $spent += $this->costOf($class);
            $described++;
        }

        return $described;
    }

    /**
     * Quantos caracteres a descrição completa de uma classe custa ao prompt.
     */
    public function costOf(ProceduralClass $class): int
    {
        return mb_strlen((string) $class->description)
            + mb_strlen(implode(', ', $class->typical_subjects))
            + mb_strlen(implode('; ', $class->citedLegalBases()));
    }
}
