<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Queries;

use App\Domain\LegalThemes\Actions\EmbedLegalThemes;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * Os temas do STJ mais próximos do relato — o lado de recuperação do RAG.
 *
 * É top-k de verdade, e a diferença para `ProceduralClassRankingQuery` é de
 * natureza, não de gosto. Lá o `enum` cobre todas as candidatas e o vetor só
 * decide onde gastar a janela; aqui o catálogo tem 2,4 mil linhas, a pergunta
 * é "quais se aplicam" e não "qual destes", e não há lista fechada a proteger.
 * O que não vem nesta consulta não chega ao agente, por desenho.
 *
 * Por isso a distância é calculada no Postgres (`<=>`, cosseno), e não em PHP:
 * hidratar 2,4 mil vetores de 768 floats para ficar com doze seria pagar a
 * tabela inteira pelo topo dela. O scan é exato, sem índice ANN — ver a
 * migration do catálogo.
 *
 * O vetor da consulta vai **calculado**, como array. Uma string faria o
 * framework embuti-la por conta própria, sem o prefixo `search_query:` do
 * nomic, e o vetor cairia fora do espaço em que os documentos foram gravados.
 *
 * **Não degrada em silêncio**, ao contrário do ranking das classes. Lá a falha
 * do embedding devolve a ordem do pivot e a classificação segue um pouco pior;
 * aqui não há ordem de reserva que signifique alguma coisa, e uma lista vazia
 * seria gravada como "nenhum tema se aplica" — uma afirmação falsa sobre a
 * peça. A exceção sobe, a metade dos temas falha, e a tela oferece o botão.
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
     * @return Collection<int, LegalTheme> do mais próximo ao mais distante
     */
    public function for(string $facts, ?string $area, int $limit): Collection
    {
        return LegalTheme::query()
            ->select(self::COLUMNS)
            ->whereNotNull('embedding')
            ->whereNotIn('status', self::SUPERSEDED)
            ->orderByVectorDistance('embedding', $this->embed($facts, $area))
            ->limit($limit)
            ->with('generalRepercussions')
            ->get();
    }

    /**
     * @return list<float>
     */
    private function embed(string $facts, ?string $area): array
    {
        $vector = Embeddings::for([EmbedLegalThemes::queryFor($facts, $area)])
            ->timeout(60)
            ->generate()
            ->first();

        if ($vector === []) {
            throw new RuntimeException('O relato não pôde ser vetorizado para a busca de temas.');
        }

        return array_values($vector);
    }
}
