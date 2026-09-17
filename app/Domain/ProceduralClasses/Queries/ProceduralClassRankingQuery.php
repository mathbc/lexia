<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Queries;

use App\Domain\ProceduralClasses\Actions\EmbedProceduralClasses;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Ordena as candidatas de uma área pela proximidade com o relato de fatos.
 *
 * O `enum` do schema continua cobrindo **todas** as candidatas — é ele que
 * impede o modelo de inventar uma classe, e isso não se negocia. O que este
 * ranking decide é outra coisa: quais delas chegam ao prompt descritas por
 * inteiro e quais chegam só pelo nome. Com as descrições enriquecidas, a lista
 * completa de penal passaria de 18 KB para perto de 30 KB numa janela de 16k
 * tokens; orçar a descrição onde ela importa é o que torna o enriquecimento
 * viável.
 *
 * A distância é calculada em PHP, e não com `whereVectorSimilarTo()`, por dois
 * motivos: o conjunto já está carregado (a Action precisa dos mesmos modelos
 * para resolver a resposta do agente) e uma segunda consulta poderia discordar
 * da primeira, que é exatamente a garantia que o desenho evita perder. São 120
 * vetores no pior caso — o custo é irrelevante ao lado de duas inferências.
 *
 * Degrada com elegância: sem Ollama, sem vetores ou com qualquer falha na
 * geração do embedding da consulta, devolve a coleção na ordem em que chegou —
 * específicas antes das genéricas, que é a ordenação do pivot e era o critério
 * anterior. Uma classificação um pouco pior é melhor do que uma exceção.
 */
final class ProceduralClassRankingQuery
{
    /**
     * @param  Collection<int, ProceduralClass>  $candidates
     * @return Collection<int, ProceduralClass> as mesmas instâncias, reordenadas
     */
    public function byRelevanceTo(Collection $candidates, string $facts): Collection
    {
        $query = $this->embedFacts($facts);

        if ($query === null) {
            return $candidates;
        }

        $ranked = $candidates->filter(
            static fn (ProceduralClass $class): bool => $class->embedding !== null
        );

        if ($ranked->isEmpty()) {
            return $candidates;
        }

        // As sem vetor vão para o fim preservando a ordem original, em vez de
        // sumirem: elas continuam sendo respostas válidas do `enum`.
        return $ranked
            ->sortBy(fn (ProceduralClass $class): float => $this->cosineDistance(
                $query,
                $class->embedding ?? [],
            ))
            ->concat($candidates->filter(
                static fn (ProceduralClass $class): bool => $class->embedding === null
            ))
            ->values();
    }

    /**
     * @return list<float>|null
     */
    private function embedFacts(string $facts): ?array
    {
        try {
            $response = Embeddings::for([EmbedProceduralClasses::queryFor($facts)])
                ->timeout(60)
                ->generate();

            return $response->first();
        } catch (Throwable) {
            // Silencioso de propósito: a classificação segue sem o ranking, e
            // quem quiser o diagnóstico roda o comando de embedding.
            return null;
        }
    }

    /**
     * Distância de cosseno — o mesmo operador `<=>` que o pgvector usaria.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineDistance(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return 1.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 1.0;
        }

        return 1.0 - ($dot / (sqrt($normA) * sqrt($normB)));
    }
}
