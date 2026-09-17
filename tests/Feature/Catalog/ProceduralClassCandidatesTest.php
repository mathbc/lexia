<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\ProceduralClasses\Queries\ProceduralClassCandidatesQuery;
use App\Rag\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the class agent is allowed to answer, checked without an agent.
 *
 * The candidate list is the enum, and the enum is the only hard guarantee in
 * the pipeline — so the properties it has to have are worth asserting where no
 * inference is involved and the whole thing runs in milliseconds.
 */
final class ProceduralClassCandidatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_area_offers_something_to_choose_from(): void
    {
        $query = new ProceduralClassCandidatesQuery;

        // An empty candidate list means an empty `enum`, which is an invalid
        // grammar — the Action returns null rather than ask the model an
        // unanswerable question. No area is in that position, and this is what
        // says so after a CNJ resync.
        foreach (PracticeArea::query()->get() as $area) {
            $this->assertNotEmpty(
                $query->forArea($area)->all(),
                "A área {$area->slug} não oferece nenhuma classe processual.",
            );
        }
    }

    #[Test]
    public function the_procedural_area_falls_back_to_its_pre_processual_classes(): void
    {
        // `processual-geral` is the one area whose classes are all non-filing:
        // two pre-processual procedures. The filter would empty it, so the
        // query hands over the whole list instead.
        $area = PracticeArea::query()->where('slug', 'processual-geral')->sole();

        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        $this->assertCount(2, $candidates);
        $this->assertSame([false, false], $candidates->pluck('is_filing_class')->all());
    }

    #[Test]
    public function an_area_offers_only_filing_classes_own_ones_first(): void
    {
        $area = PracticeArea::query()->where('slug', 'familia-sucessoes')->sole();

        $candidates = (new ProceduralClassCandidatesQuery)->forArea($area);

        // 54 of the area's 109 classes open a case; the rest are appeals,
        // incidents and cumprimento de sentença, which a narrative with no
        // lawsuit yet can never be.
        $this->assertCount(54, $candidates);

        foreach ($candidates as $candidate) {
            $this->assertTrue($candidate->is_filing_class);
        }

        // The prompt renders `specific` classes in full and the borrowed trunk
        // by name alone, so the order is not cosmetic: the area's own classes
        // have to arrive first.
        $this->assertSame('specific', $candidates->first()?->pivot?->getAttribute('scope'));
    }

    #[Test]
    public function the_knowledge_document_only_cites_codes_that_exist(): void
    {
        $knowledge = (new KnowledgeBase)->get('procedural-classes');

        preg_match_all('/\[(\d+)\]/', $knowledge, $matches);

        $cited = array_values(array_unique(array_map(intval(...), $matches[1])));

        $this->assertNotEmpty($cited);

        // The guide teaches by code, and a CNJ resync can retire one. A code
        // that no longer exists is advice pointing at nothing — and the model
        // cannot answer it either, since the enum is built from the catalogue.
        $known = ProceduralClass::query()->whereIn('code', $cited)->pluck('code')->all();

        $this->assertSame(
            [],
            array_values(array_diff($cited, $known)),
            'A base de conhecimento cita códigos CNJ que não existem no catálogo.',
        );
    }
}
