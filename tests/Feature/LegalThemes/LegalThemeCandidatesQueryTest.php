<?php

declare(strict_types=1);

namespace Tests\Feature\LegalThemes;

use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\LegalThemes\Queries\LegalThemeCandidatesQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A recuperação do RAG sobre os temas do STJ.
 *
 * Os vetores são de eixo, feitos à mão, pelo motivo de ProceduralClassRankingTest:
 * o que se verifica é a ordem que o Postgres devolve e o que ele descarta, e
 * não a qualidade do nomic. O fake do SDK devolve um vetor por questão, na
 * ordem em que elas foram mandadas.
 */
final class LegalThemeCandidatesQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.default_for_embeddings' => 'ollama']);
    }

    #[Test]
    public function it_returns_the_closest_themes_first_and_stops_at_the_limit(): void
    {
        $far = LegalTheme::factory()->embedded($this->axis(1))->create();
        $closest = LegalTheme::factory()->embedded($this->axis(0))->create();
        $near = LegalTheme::factory()->embedded($this->mix(0, 1))->create();

        Embeddings::fake([[$this->axis(0)]]);

        $found = (new LegalThemeCandidatesQuery)->for(['Definir se o reajuste por faixa etária é válido.'], 2);

        $this->assertSame([$closest->id, $near->id], $found->pluck('id')->all());
        $this->assertNotContains($far->id, $found->pluck('id')->all());
    }

    /**
     * O primeiro de cada questão entra antes do segundo de qualquer uma: é o
     * que impede duas questões vizinhas de tomarem as vagas da terceira. O tema
     * que duas questões trazem entra uma vez, na primeira posição em que
     * aparece.
     */
    #[Test]
    public function the_questions_take_turns_so_each_brings_its_nearest_theme(): void
    {
        $theft = LegalTheme::factory()->embedded($this->axis(0))->create();
        $theftNeighbour = LegalTheme::factory()->embedded($this->mix(0, 2))->create();
        $search = LegalTheme::factory()->embedded($this->axis(1))->create();
        $searchNeighbour = LegalTheme::factory()->embedded($this->mix(1, 3))->create();

        // Duas questões sobre o furto e uma sobre a busca pessoal.
        Embeddings::fake([[$this->axis(0), $this->axis(0), $this->axis(1)]]);

        $found = (new LegalThemeCandidatesQuery)->for(['Consumação do furto.', 'Crime impossível no furto.', 'Fundada suspeita na busca pessoal.'], 3);

        $this->assertSame([$theft->id, $search->id, $theftNeighbour->id], $found->pluck('id')->all());
        $this->assertNotContains($searchNeighbour->id, $found->pluck('id')->all());
    }

    /**
     * Um tema cancelado não diz mais nada a uma peça nova, e um sem vetor não
     * tem como ser comparado — nenhum dos dois é candidato.
     */
    #[Test]
    public function cancelled_and_unembedded_themes_are_never_offered(): void
    {
        $kept = LegalTheme::factory()->embedded($this->axis(1))->create();
        LegalTheme::factory()->cancelled()->embedded($this->axis(0))->create();
        LegalTheme::factory()->create();

        Embeddings::fake([[$this->axis(0)]]);

        $found = (new LegalThemeCandidatesQuery)->for(['Questão.'], 10);

        $this->assertSame([$kept->id], $found->pluck('id')->all());
    }

    /**
     * O que o prompt lê chega carregado; o vetor, que ninguém lê depois da
     * ordenação, não.
     */
    #[Test]
    public function it_loads_the_repercussions_and_leaves_the_vector_behind(): void
    {
        $theme = LegalTheme::factory()->embedded($this->axis(0))->create();
        $theme->generalRepercussions()->create(['number' => 69, 'description' => 'Inclusão do ICMS na base de cálculo do PIS e da COFINS.']);

        Embeddings::fake([[$this->axis(0)]]);

        $found = (new LegalThemeCandidatesQuery)->for(['Questão.'], 10)->sole();

        $this->assertTrue($found->relationLoaded('generalRepercussions'));
        $this->assertSame([69], $found->generalRepercussions->pluck('number')->all());
        $this->assertArrayNotHasKey('embedding', $found->getAttributes());
    }

    /**
     * Cada questão vai sozinha atrás do prefixo do nomic — o outro lado do
     * `search_document:` com que os temas foram gravados —, e as questões vão
     * numa chamada só.
     */
    #[Test]
    public function each_question_is_a_query_behind_the_task_prefix(): void
    {
        LegalTheme::factory()->embedded($this->axis(0))->create();

        Embeddings::fake([[$this->axis(0), $this->axis(0)]]);

        (new LegalThemeCandidatesQuery)->for(['  Definir se o reajuste é válido.  ', 'Definir se cabe restituição em dobro.'], 10);

        Embeddings::assertGenerated(static fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === [
            'search_query: Definir se o reajuste é válido.',
            'search_query: Definir se cabe restituição em dobro.',
        ]);
    }

    /**
     * Sem vetor da consulta não há ordem que signifique alguma coisa. A falha
     * sobe — e sobe também quando o provedor devolve menos vetores do que
     * questões, que deixaria uma delas sem busca em silêncio.
     */
    #[Test]
    public function an_embedding_outage_is_not_swallowed(): void
    {
        LegalTheme::factory()->embedded($this->axis(0))->create();

        Embeddings::fake(static fn (): never => throw new RuntimeException('Ollama fora do ar'));

        $this->expectException(RuntimeException::class);

        (new LegalThemeCandidatesQuery)->for(['Questão.'], 10);
    }

    #[Test]
    public function a_missing_vector_is_not_swallowed(): void
    {
        LegalTheme::factory()->embedded($this->axis(0))->create();

        Embeddings::fake([[$this->axis(0)]]);

        $this->expectException(RuntimeException::class);

        (new LegalThemeCandidatesQuery)->for(['Primeira.', 'Segunda.'], 10);
    }

    #[Test]
    public function no_question_is_no_query(): void
    {
        Embeddings::fake();

        try {
            (new LegalThemeCandidatesQuery)->for([], 10);
            $this->fail('Sem questão não deveria haver consulta.');
        } catch (RuntimeException) {
            Embeddings::assertNothingGenerated();
        }
    }

    /**
     * @return list<float>
     */
    private function axis(int $index): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$index] = 1.0;

        return $vector;
    }

    /**
     * Metade de um eixo, metade de outro: a 45° dos dois.
     *
     * @return list<float>
     */
    private function mix(int $first, int $second): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$first] = 1.0;
        $vector[$second] = 1.0;

        return $vector;
    }
}
