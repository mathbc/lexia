<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use App\Domain\AiModels\Enums\AiProvider;
use App\Domain\AiModels\Models\EmbeddingModel;
use App\Domain\AiModels\Support\DefaultModels;
use App\Domain\AiModels\Support\ProviderInstance;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

/**
 * Gera e grava o vetor de cada model cujo texto ainda não o tem — e é o único
 * lugar do projeto que pede um embedding ao provedor.
 *
 * Serve a todo corpus vetorizado do projeto — o catálogo de classes e os temas
 * do STJ —, e o contrato é de colunas: `embedding` (`vector(768)`, com o cast
 * AsVector), `embedding_hash`, `embedding_model` e `embedded_at`. Quem chama
 * decide só o texto que representa cada linha.
 *
 * O modelo é o padrão de `embedding_models`, e cada vetor guarda a `key()` dele
 * em `embedding_model`. Uma linha está em dia quando tem vetor, o hash do texto
 * bate e o modelo é o padrão; trocar o padrão torna velhas todas as outras, e é
 * isso que EmbedCatalogs refaz. O hash continua sendo o que torna isto barato de
 * repetir: uma linha cujo texto não mudou não é reembutida.
 *
 * A tarefa — documento ou consulta — chega ao provedor do jeito que ele a lê.
 * O `nomic-embed-text` do Ollama foi treinado com prefixos de tarefa
 * (`search_document:`, `search_query:`), e documento e consulta caem em regiões
 * diferentes do espaço quando cada um usa o seu; sem eles a similaridade entre
 * um relato leigo e uma definição jurídica fica visivelmente pior. O Gemini
 * expressa a mesma ideia por `taskType`, que vai nas opções do provedor, e
 * receberia o prefixo como texto literal no começo de cada documento: ruído,
 * não instrução. Que o prefixo entre no texto e o texto no hash é o que faz uma
 * troca de provedor se cobrar duas vezes — pelo modelo e pelo hash.
 */
final class DocumentEmbedder
{
    /**
     * Ollama aguenta lotes bem maiores, mas um lote gigante é uma requisição
     * longa e tudo-ou-nada; 64 mantém o custo de uma falha pequeno.
     */
    private const int BATCH = 64;

    private const string DOCUMENT_TASK = 'RETRIEVAL_DOCUMENT';

    private const string QUERY_TASK = 'RETRIEVAL_QUERY';

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  Closure(TModel): string  $documentFor
     * @return int quantos models foram (re)embutidos
     */
    public function embed(Collection $models, Closure $documentFor): int
    {
        $current = self::model()->key();

        $stale = $models
            ->map(fn (Model $model): array => [
                'model' => $model,
                'text' => $text = $documentFor($model),
                'hash' => hash('sha256', $text),
            ])
            ->filter(fn (array $row): bool => $row['model']->getAttribute('embedding') === null
                || $row['model']->getAttribute('embedding_hash') !== $row['hash']
                || $row['model']->getAttribute('embedding_model') !== $current)
            ->values();

        foreach ($stale->chunk(self::BATCH) as $batch) {
            $this->embedBatch($batch->values(), $current);
        }

        return $stale->count();
    }

    /**
     * Os vetores de consultas, já com o prefixo de `query()`, na ordem em que
     * chegaram — o lado da busca do par.
     *
     * @param  list<string>  $queries
     * @return list<list<float>>
     */
    public static function embedQueries(array $queries, int $timeout): array
    {
        return self::generate($queries, self::QUERY_TASK, $timeout);
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
     * O padrão vigente: a coluna `embedding_model` das linhas que ele gerar e o
     * filtro das buscas que compararem com elas.
     */
    public static function model(): EmbeddingModel
    {
        return app(DefaultModels::class)->embedding();
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, array{model: TModel, text: string, hash: string}>  $batch
     */
    private function embedBatch(Collection $batch, string $current): void
    {
        $vectors = self::generate($batch->pluck('text')->all(), self::DOCUMENT_TASK, 180);

        foreach ($batch as $index => $row) {
            $row['model']->forceFill([
                'embedding' => $vectors[$index],
                'embedding_hash' => $row['hash'],
                'embedding_model' => $current,
                'embedded_at' => now(),
            ])->save();
        }
    }

    /**
     * Um pedido ao provedor do modelo padrão, sempre na largura da coluna.
     *
     * As dimensões vão explícitas: sem elas o SDK cai nas do bloco do provedor
     * no config, e o padrão do Gemini é 3072. E o provedor vai pela instância
     * de ProviderInstance, que é quem leva a chave de API da linha do modelo.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    private static function generate(array $inputs, string $task, int $timeout): array
    {
        $model = self::model();

        $pending = Embeddings::for($inputs)
            ->dimensions(EmbeddingModel::DIMENSIONS)
            ->timeout($timeout);

        if ($model->provider === AiProvider::Gemini) {
            $pending->withProviderOptions(['taskType' => $task]);
        }

        return array_values($pending->generate(ProviderInstance::for($model), $model->name)->embeddings);
    }

    /**
     * O prefixo de tarefa, quando o provedor de embeddings for um que os leia.
     * Qualquer outro recebe a string vazia: um provedor que não conhece o
     * prefixo não o ignora, ele o embute.
     */
    private static function taskPrefix(string $prefix): string
    {
        return self::model()->provider->usesTaskPrefixes() ? $prefix : '';
    }
}
