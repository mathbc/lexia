<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\ProceduralClasses\Queries\DescribedCandidateBudget;
use App\Domain\ProceduralClasses\Queries\ProceduralClassCandidatesQuery;
use App\Domain\ProceduralClasses\Queries\ProceduralClassRankingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

/**
 * O ranqueamento vetorial que decide quais candidatas chegam descritas.
 *
 * Fora do grupo `agents` de propósito: o que é verificado aqui é a matemática e
 * a degradação, não a qualidade de um modelo. Os vetores são fabricados, então
 * roda no `php artisan test` normal e sem Ollama de pé.
 */
final class ProceduralClassRankingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_orders_candidates_by_distance_to_the_facts(): void
    {
        // Três vetores unitários em eixos separados: o primeiro é idêntico à
        // consulta, o segundo ortogonal, o terceiro oposto. A ordem esperada
        // não depende de nenhuma propriedade do modelo de embeddings.
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area)->take(3)->values();

        $candidates[0]->forceFill(['embedding' => $this->axis(0, -1.0)])->save();
        $candidates[1]->forceFill(['embedding' => $this->axis(1)])->save();
        $candidates[2]->forceFill(['embedding' => $this->axis(0)])->save();

        Embeddings::fake([[$this->axis(0)]]);

        $ranked = (new ProceduralClassRankingQuery)->byRelevanceTo($candidates, 'os fatos');

        $this->assertSame(
            [$candidates[2]->code, $candidates[1]->code, $candidates[0]->code],
            $ranked->pluck('code')->all(),
        );
    }

    #[Test]
    public function classes_without_a_vector_go_last_but_are_never_dropped(): void
    {
        // Elas continuam no `enum`, então sumir da lista seria remover uma
        // resposta válida — o oposto do que o orçamento de prompt deve fazer.
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area)->take(3)->values();

        $candidates[0]->forceFill(['embedding' => $this->axis(1)])->save();
        $candidates[2]->forceFill(['embedding' => $this->axis(0)])->save();

        Embeddings::fake([[$this->axis(0)]]);

        $ranked = (new ProceduralClassRankingQuery)->byRelevanceTo($candidates, 'os fatos');

        $this->assertCount(3, $ranked);
        $this->assertSame($candidates[1]->code, $ranked->last()?->code);
    }

    #[Test]
    public function it_falls_back_to_the_given_order_when_nothing_is_embedded(): void
    {
        // Banco recém-migrado, ou Ollama fora do ar: a classificação segue com
        // a ordem do pivot — específicas antes das genéricas —, que era o
        // critério anterior ao vetor.
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        Embeddings::fake([[$this->axis(0)]]);

        $ranked = (new ProceduralClassRankingQuery)->byRelevanceTo($candidates, 'os fatos');

        $this->assertSame($candidates->pluck('code')->all(), $ranked->pluck('code')->all());
    }

    #[Test]
    public function a_failure_to_embed_the_facts_never_breaks_the_classification(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        $candidates->first()?->forceFill(['embedding' => $this->axis(0)])->save();

        Embeddings::fake(fn () => throw new RuntimeException('Ollama fora do ar'));

        $ranked = (new ProceduralClassRankingQuery)->byRelevanceTo($candidates, 'os fatos');

        $this->assertSame($candidates->pluck('code')->all(), $ranked->pluck('code')->all());
    }

    /**
     * A garantia de que o enriquecimento depende: o prompt tem de caber.
     */
    #[Test]
    #[TestWith(['penal'])]
    #[TestWith(['familia-sucessoes'])]
    #[TestWith(['infancia-juventude'])]
    #[TestWith(['civil'])]
    #[TestWith(['consumidor'])]
    public function the_described_candidates_stay_within_the_prompt_budget(string $slug): void
    {
        $area = PracticeArea::query()->where('slug', $slug)->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        $budget = DescribedCandidateBudget::fromConfig();
        $described = $budget->describedCount($candidates);

        $spent = $candidates->values()->take($described)->sum($budget->costOf(...));

        // O teto é aplicado por classe inteira, então a última descrita pode
        // ultrapassá-lo — o que não pode é ultrapassar por muito.
        $this->assertLessThan(
            (int) config('ai.retrieval.description_budget') * 1.2,
            $spent,
            "A lista descrita de {$slug} estourou o orçamento do prompt.",
        );

        $this->assertGreaterThanOrEqual((int) config('ai.retrieval.minimum_described'), $described);
        $this->assertLessThanOrEqual($candidates->count(), $described);
    }

    #[Test]
    public function the_floor_wins_over_the_budget_when_descriptions_are_long(): void
    {
        // Orçamento zerado: sem o piso, nenhuma classe chegaria descrita e o
        // agente escolheria entre 30 nomes soltos.
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        $this->assertSame(4, (new DescribedCandidateBudget(budget: 0, minimum: 4))->describedCount($candidates));
    }

    #[Test]
    public function a_generous_budget_describes_every_candidate(): void
    {
        $area = PracticeArea::query()->where('slug', 'civil')->sole();
        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        $this->assertSame(
            $candidates->count(),
            (new DescribedCandidateBudget(budget: PHP_INT_MAX, minimum: 0))->describedCount($candidates),
        );
    }

    #[Test]
    public function the_vector_survives_a_round_trip_through_postgres(): void
    {
        // pgvector guarda `float4`, então o valor volta arredondado — o que
        // precisa sobreviver é a dimensão e a ordem, não o bit a bit.
        $class = ProceduralClass::query()->where('code', 7)->sole();

        $class->forceFill(['embedding' => $this->axis(3)])->save();

        $stored = ProceduralClass::query()->where('code', 7)->sole()->embedding;

        $this->assertIsArray($stored);
        $this->assertCount(768, $stored);
        $this->assertSame(1.0, $stored[3]);
        $this->assertSame(0.0, $stored[0]);
    }

    /**
     * Vetor unitário no eixo pedido, na dimensão do nomic-embed-text.
     *
     * @return list<float>
     */
    private function axis(int $index, float $magnitude = 1.0): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$index] = $magnitude;

        return $vector;
    }
}
