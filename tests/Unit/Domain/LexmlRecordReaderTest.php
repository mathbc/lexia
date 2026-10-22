<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CourtDecisions\Support\LexmlRecordReader;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ruling's record, read from the page the LexML serves.
 *
 * Until the fetch and the label reading moved to LexmlPage — shared with the
 * doctrine reader — nothing in the standard suite read a real record: only the
 * live agent test did. The fixture is the portal's HTML for `REsp 143513 / SP`,
 * saved on 2026-10-22 from its `/urn/` address.
 */
final class LexmlRecordReaderTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/lexml/';

    private const string RULING = 'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332';

    #[Test]
    public function it_reads_the_labelled_fields_of_a_ruling(): void
    {
        Http::fake([self::RULING => Http::response($this->fixture('court-decision.html'))]);

        $decision = (new LexmlRecordReader)->read(self::RULING);

        $this->assertNotNull($decision);
        $this->assertSame('REsp 143513 / SP', $decision->title);
        $this->assertSame('Brasil', $decision->locality);
        $this->assertSame('Superior Tribunal de Justiça. 6ª Turma', $decision->authority);
        $this->assertStringStartsWith('PROCESSUAL CIVIL. EMBARGOS À EXECUÇÃO. PENHORA.', $decision->summary);
        $this->assertSame('1998-04-28', $decision->decidedAt?->toDateString());
        $this->assertSame('urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332', $decision->urn);
        $this->assertSame(self::RULING, $decision->sourceUrl);
    }

    #[Test]
    public function the_challenge_page_is_not_a_record(): void
    {
        Http::fake([self::RULING => Http::response($this->fixture('challenge.html'))]);

        $this->assertNull((new LexmlRecordReader)->read(self::RULING));
    }

    #[Test]
    public function the_search_screen_is_never_fetched(): void
    {
        Http::fake();

        $this->assertNull((new LexmlRecordReader)->read('https://www.lexml.gov.br/busca/search?keyword=embargos'));

        Http::assertNothingSent();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }
}
