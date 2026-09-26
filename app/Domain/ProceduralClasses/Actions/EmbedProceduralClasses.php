<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Actions;

use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Shared\Support\DocumentEmbedder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Gera e grava o vetor de cada classe processual que ainda não o tem.
 *
 * O texto embutido é o mesmo que o advogado leria — nome, descrição, matérias
 * típicas e a fundamentação —, porque é contra ele que o relato de fatos vai
 * ser comparado. O laço de lotes, o hash e o prefixo de tarefa do
 * `nomic-embed-text` moram no DocumentEmbedder, que os temas do STJ também
 * usam; o docblock dele explica por que o prefixo existe.
 *
 * A migration de recarga do catálogo zera os hashes justamente para forçar a
 * próxima passada.
 *
 * Chamado de dois lugares, e é de propósito: o comando
 * `lexia:embed-procedural-classes` prepara o catálogo inteiro de uma vez, e
 * SelectProceduralClass chama para as candidatas da área antes de ranqueá-las —
 * o que faz um banco recém-migrado funcionar sem nenhum passo manual, pagando
 * a inferência só na primeira classificação daquela área.
 */
final class EmbedProceduralClasses
{
    use AsAction;

    /**
     * @param  Collection<int, ProceduralClass>  $classes
     * @return int quantas classes foram (re)embutidas
     */
    public function handle(Collection $classes): int
    {
        return (new DocumentEmbedder)->embed($classes, $this->documentFor(...));
    }

    /**
     * O documento que representa a classe no espaço vetorial.
     *
     * Vale para o hash tanto quanto para o vetor: qualquer mudança aqui
     * invalida o catálogo inteiro na próxima passada, que é o comportamento
     * desejado — o vetor antigo descreveria um texto que não existe mais.
     */
    public function documentFor(ProceduralClass $class): string
    {
        $parts = ["Classe processual: {$class->name}."];

        if ($class->description !== null) {
            $parts[] = $class->description;
        }

        if ($class->typical_subjects !== []) {
            $parts[] = 'Matérias típicas: '.implode(', ', $class->typical_subjects).'.';
        }

        $bases = $class->citedLegalBases();

        if ($bases !== []) {
            $parts[] = 'Base legal: '.implode('; ', $bases).'.';
        }

        return DocumentEmbedder::document(implode(' ', $parts));
    }

    /**
     * O outro lado do par: o relato de fatos, como consulta.
     */
    public static function queryFor(string $facts): string
    {
        return DocumentEmbedder::query(trim($facts));
    }
}
