<?php

declare(strict_types=1);

namespace App\Domain\ProceduralClasses\Actions;

use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Gera e grava o vetor de cada classe processual que ainda não o tem.
 *
 * O texto embutido é o mesmo que o advogado leria — nome, descrição, matérias
 * típicas e a fundamentação —, porque é contra ele que o relato de fatos vai
 * ser comparado.
 *
 * O prefixo `search_document:` não é enfeite, e é do `nomic-embed-text`: o
 * modelo foi treinado com prefixos de tarefa, e documento e consulta caem em
 * regiões diferentes do espaço quando cada um usa o seu. Sem eles a
 * similaridade entre um relato leigo e uma definição jurídica fica
 * visivelmente pior.
 *
 * Ele segue `ai.default_for_embeddings` em vez de ser fixo porque esse par é o
 * que pode divergir do texto dos agentes: já divergiu uma vez, quando o texto
 * esteve no Gemini, e hoje os dois voltaram a ser Ollama. Se um dia a
 * vetorização sair daqui, o prefixo tem de sair junto — um provedor de nuvem
 * expressa a mesma ideia por `taskType`, que o SDK não deixa passar daqui, e
 * receberia o prefixo como texto literal no começo de cada documento: ruído,
 * não instrução. Que o prefixo entre no texto e o texto
 * no hash é o que faz essa mudança se cobrar sozinha, regenerando os vetores
 * que deixaram de bater.
 *
 * O hash é o que torna isto barato de repetir: uma classe cujo texto não mudou
 * não é reembutida. A migration de recarga do catálogo zera os hashes
 * justamente para forçar a próxima passada.
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
     * Ollama aguenta lotes bem maiores, mas um lote gigante é uma requisição
     * longa e tudo-ou-nada; 64 mantém o custo de uma falha pequeno.
     */
    private const int BATCH = 64;

    /**
     * @param  Collection<int, ProceduralClass>  $classes
     * @return int quantas classes foram (re)embutidas
     */
    public function handle(Collection $classes): int
    {
        $stale = $classes
            ->map(fn (ProceduralClass $class): array => [
                'class' => $class,
                'text' => $text = $this->documentFor($class),
                'hash' => hash('sha256', $text),
            ])
            ->filter(fn (array $row): bool => $row['class']->embedding === null
                || $row['class']->embedding_hash !== $row['hash'])
            ->values();

        if ($stale->isEmpty()) {
            return 0;
        }

        foreach ($stale->chunk(self::BATCH) as $batch) {
            $this->embedBatch($batch->values());
        }

        return $stale->count();
    }

    /**
     * @param  Collection<int, array{class: ProceduralClass, text: string, hash: string}>  $batch
     */
    private function embedBatch(Collection $batch): void
    {
        $response = Embeddings::for($batch->pluck('text')->all())
            ->timeout(180)
            ->generate();

        foreach ($batch as $index => $row) {
            $row['class']->forceFill([
                'embedding' => $response->embeddings[$index],
                'embedding_hash' => $row['hash'],
                'embedded_at' => now(),
            ])->save();
        }
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

        return self::taskPrefix('search_document: ').implode(' ', $parts);
    }

    /**
     * O outro lado do par: o relato de fatos, como consulta.
     */
    public static function queryFor(string $facts): string
    {
        return self::taskPrefix('search_query: ').trim($facts);
    }

    /**
     * O prefixo de tarefa, quando o provedor de embeddings for um que os leia.
     *
     * Só o Ollama, hoje, porque só o `nomic-embed-text` foi treinado com eles.
     * Qualquer outro recebe a string vazia: um provedor que não conhece o
     * prefixo não o ignora, ele o embute.
     */
    private static function taskPrefix(string $prefix): string
    {
        return config('ai.default_for_embeddings') === 'ollama' ? $prefix : '';
    }
}
