<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

/**
 * Gera e grava o vetor de cada model cujo texto ainda não o tem.
 *
 * Serve a todo corpus vetorizado do projeto — o catálogo de classes e os temas
 * do STJ —, e o contrato é de colunas: `embedding` (`vector(768)`, com o cast
 * AsVector), `embedding_hash` e `embedded_at`. Quem chama decide só o texto
 * que representa cada linha.
 *
 * O hash é o que torna isto barato de repetir: uma linha cujo texto não mudou
 * não é reembutida. Ele cobre o texto exato, prefixo incluído, e não o modelo
 * — trocar o modelo de embeddings pede o `--fresh` do comando.
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
 */
final class DocumentEmbedder
{
    /**
     * Ollama aguenta lotes bem maiores, mas um lote gigante é uma requisição
     * longa e tudo-ou-nada; 64 mantém o custo de uma falha pequeno.
     */
    private const int BATCH = 64;

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  Closure(TModel): string  $documentFor
     * @return int quantos models foram (re)embutidos
     */
    public function embed(Collection $models, Closure $documentFor): int
    {
        $stale = $models
            ->map(fn (Model $model): array => [
                'model' => $model,
                'text' => $text = $documentFor($model),
                'hash' => hash('sha256', $text),
            ])
            ->filter(fn (array $row): bool => $row['model']->getAttribute('embedding') === null
                || $row['model']->getAttribute('embedding_hash') !== $row['hash'])
            ->values();

        foreach ($stale->chunk(self::BATCH) as $batch) {
            $this->embedBatch($batch->values());
        }

        return $stale->count();
    }

    /**
     * O texto de um documento, com o prefixo de tarefa do lado do corpus.
     */
    public static function document(string $text): string
    {
        return self::taskPrefix('search_document: ').$text;
    }

    /**
     * O outro lado do par: o texto de uma consulta.
     */
    public static function query(string $text): string
    {
        return self::taskPrefix('search_query: ').$text;
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, array{model: TModel, text: string, hash: string}>  $batch
     */
    private function embedBatch(Collection $batch): void
    {
        $response = Embeddings::for($batch->pluck('text')->all())
            ->timeout(180)
            ->generate();

        foreach ($batch as $index => $row) {
            $row['model']->forceFill([
                'embedding' => $response->embeddings[$index],
                'embedding_hash' => $row['hash'],
                'embedded_at' => now(),
            ])->save();
        }
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
