<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Queries;

use App\Domain\LegalThemes\Actions\EmbedLegalThemes;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * Os temas do STJ mais próximos das questões de direito do caso — o lado de
 * recuperação do RAG.
 *
 * É top-k de verdade, e a diferença para `ProceduralClassRankingQuery` é de
 * natureza, não de gosto. Lá o `enum` cobre todas as candidatas e o vetor só
 * decide onde gastar a janela; aqui o catálogo tem 2,4 mil linhas, a pergunta
 * é "quais se aplicam" e não "qual destes", e não há lista fechada a proteger.
 * O que não vem nesta consulta não chega ao agente, por desenho.
 *
 * **A consulta não é o relato**, e isso foi medido. Um tema é uma questão de
 * direito abstrata, e o relato são fatos concretos: embutido inteiro, o relato
 * de um furto em flagrante trouxe Maria da Penha e tabela de honorários da OAB
 * entre os doze mais próximos, e nenhum dos sete temas de furto que o catálogo
 * tem. Quem consulta são as questões que `LegalQuestionFormulationAgent`
 * escreve no registro do STJ — cada uma vira um vetor e uma busca, e cada uma
 * pôs o tema certo em primeiro lugar no mesmo caso.
 *
 * As listas são **intercaladas por posição**, e não fundidas por pontuação: o
 * primeiro de cada questão entra antes do segundo de qualquer uma. É o que
 * garante que toda questão chegue ao agente com o seu tema mais próximo — uma
 * fusão por soma deixaria três questões vizinhas (as três sobre furto)
 * empurrarem para fora a única sobre a busca pessoal. Um tema que duas questões
 * trazem entra uma vez, na primeira posição em que aparece.
 *
 * A distância é calculada no Postgres (`<=>`, cosseno), e não em PHP: hidratar
 * 2,4 mil vetores de 768 floats para ficar com vinte seria pagar a tabela
 * inteira pelo topo dela. O scan é exato, sem índice ANN — ver a migration do
 * catálogo —, e uma questão a mais é um scan a mais, de milissegundos.
 *
 * Os vetores vão **calculados**, como array. Uma string faria o framework
 * embuti-la por conta própria, sem o prefixo `search_query:` do nomic, e o
 * vetor cairia fora do espaço em que os documentos foram gravados.
 *
 * **Não degrada em silêncio**, ao contrário do ranking das classes. Lá a falha
 * do embedding devolve a ordem do pivot e a classificação segue um pouco pior;
 * aqui não há ordem de reserva que signifique alguma coisa. A exceção sobe, a
 * metade dos temas falha, e a tela oferece o botão.
 */
final class LegalThemeCandidatesQuery
{
    /**
     * Situações em que o tema não diz mais nada a uma peça nova.
     *
     * Medidas no catálogo: 531 das 2.364 linhas, com as duas grafias de
     * "cancelado" que o STJ usa. Um tema afetado, pendente ou sobrestado fica —
     * ele pode suspender o processo, e é exatamente o que o advogado precisa
     * saber. Uma grafia nova de cancelamento escaparia deste filtro e chegaria
     * ao agente com a situação escrita ao lado, que é o erro barato.
     */
    public const array SUPERSEDED = ['Cancelada', 'Cancelado', 'Prejudicada'];

    /**
     * O que o prompt e a tela leem — e nunca o `embedding`, que são 768 floats
     * por linha sem nenhum leitor depois da ordenação.
     */
    private const array COLUMNS = [
        'id',
        'type',
        'number',
        'status',
        'judging_body',
        'question',
        'settled_thesis',
        'judgment_scope',
    ];

    /**
     * @param  list<string>  $questions  LegalQuestionFormulationAgent's, most central first
     * @return Collection<int, LegalTheme> intercalados por posição, na ordem das questões
     */
    public function for(array $questions, int $limit): Collection
    {
        $ids = $this->interleave(
            array_map($this->nearest(...), $this->embed($questions), array_fill(0, count($questions), $limit)),
            $limit,
        );

        $themes = LegalTheme::query()
            ->select(self::COLUMNS)
            ->whereKey($ids)
            ->with('generalRepercussions')
            ->get()
            ->keyBy('id');

        return new Collection(array_values(array_filter(array_map(
            static fn (string $id): ?LegalTheme => $themes->get($id),
            $ids,
        ))));
    }

    /**
     * Os ids dos temas mais próximos de um vetor, do mais perto ao mais longe.
     *
     * @param  list<float>  $vector
     * @return list<string>
     */
    private function nearest(array $vector, int $limit): array
    {
        /** @var list<string> */
        return LegalTheme::query()
            ->whereNotNull('embedding')
            ->whereNotIn('status', self::SUPERSEDED)
            ->orderByVectorDistance('embedding', $vector)
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * O primeiro de cada lista, depois o segundo de cada uma, até o teto.
     *
     * @param  list<list<string>>  $rankings
     * @return list<string>
     */
    private function interleave(array $rankings, int $limit): array
    {
        $ids = [];
        $depth = max([0, ...array_map('count', $rankings)]);

        for ($position = 0; $position < $depth && count($ids) < $limit; $position++) {
            foreach ($rankings as $ranking) {
                $id = $ranking[$position] ?? null;

                if ($id !== null && count($ids) < $limit) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Um vetor por questão, numa chamada só ao provedor.
     *
     * @param  list<string>  $questions
     * @return list<list<float>>
     */
    private function embed(array $questions): array
    {
        if ($questions === []) {
            throw new RuntimeException('Não há questões de direito para consultar o catálogo de temas.');
        }

        $vectors = Embeddings::for(array_map(EmbedLegalThemes::queryFor(...), $questions))
            ->timeout(60)
            ->generate()
            ->embeddings;

        if (count($vectors) !== count($questions) || in_array([], $vectors, true)) {
            throw new RuntimeException('As questões de direito não puderam ser vetorizadas para a busca de temas.');
        }

        return array_map(array_values(...), array_values($vectors));
    }
}
