<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Doctrines\Support\DoctrineRecordReader;
use App\Domain\Doctrines\Support\DoctrineSources;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The doctrine record, read from the page the LexML serves.
 *
 * The fixtures under `tests/Fixtures/lexml/` are the portal's own HTML, saved on
 * 2026-10-22 from `/urn/` addresses (which robots.txt allows): an article, a
 * book, and the Senado's challenge page. If the portal changes its template,
 * this is the file that says so without a model in the loop.
 */
final class DoctrineRecordReaderTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/lexml/';

    private const string ARTICLE = 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:artigo.revista:2015;2001031582';

    private const string BOOK = 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:livro:2010;000884424';

    #[Test]
    public function it_reads_an_article_with_the_periodical_and_the_pages(): void
    {
        Http::fake([self::ARTICLE => Http::response($this->fixture('doctrine-article.html'))]);

        $doctrine = (new DoctrineRecordReader)->read(self::ARTICLE);

        $this->assertNotNull($doctrine);
        $this->assertSame('Artigo de revista', $doctrine->kind);
        $this->assertSame('Tupinambá, Carolina', $doctrine->author);
        $this->assertSame(
            'A responsabilidade do empregador em decorrência de assaltos e atos criminosos contra empregados',
            $doctrine->title,
        );
        $this->assertSame('2015', $doctrine->publishedIn);
        $this->assertStringStartsWith('Sumário: Os danos contidos no drama de ser vítima de assalto', (string) $doctrine->summary);
        $this->assertSame(
            'Revista Magister de direito do trabalho. Porto Alegre, Magister, 2011. v. 11, n. 64, p. 108–130, jan./fev., 2015.',
            $doctrine->publication,
        );
        $this->assertSame(self::ARTICLE, $doctrine->sourceUrl);
        $this->assertSame('urn:lex:br:rede.virtual.bibliotecas:artigo.revista:2015;2001031582', $doctrine->urn);
        $this->assertNull($doctrine->relevance);
    }

    /**
     * A book's edition block names the book again and gives a bare year as the
     * reference: both are dropped, and what is left is the imprint.
     */
    #[Test]
    public function it_reads_a_book_with_its_publisher(): void
    {
        Http::fake([self::BOOK => Http::response($this->fixture('doctrine-book.html'))]);

        $doctrine = (new DoctrineRecordReader)->read(self::BOOK);

        $this->assertNotNull($doctrine);
        $this->assertSame('Livro', $doctrine->kind);
        $this->assertSame('Almeida, Carlos Alberto Neves', $doctrine->author);
        $this->assertSame('Coimbra, Almedina, 2010.', $doctrine->publication);
        $this->assertNull($doctrine->summary);
    }

    #[Test]
    public function the_challenge_page_is_not_a_record(): void
    {
        Http::fake([self::ARTICLE => Http::response($this->fixture('challenge.html'))]);

        $this->assertNull((new DoctrineRecordReader)->read(self::ARTICLE));
    }

    #[Test]
    public function a_record_that_does_not_answer_is_dropped(): void
    {
        Http::fake([self::ARTICLE => Http::response('', 404)]);

        $this->assertNull((new DoctrineRecordReader)->read(self::ARTICLE));
    }

    /**
     * The guard the tab rests on: a work nobody can attribute is not returned.
     */
    #[Test]
    public function a_work_without_an_author_is_dropped(): void
    {
        $html = (string) preg_replace(
            '#<strong>Autor</strong>.*?</div>\s*</div>\s*</div>#su',
            '',
            $this->fixture('doctrine-article.html'),
            1,
        );

        Http::fake([self::ARTICLE => Http::response($html)]);

        $this->assertNull((new DoctrineRecordReader)->read(self::ARTICLE));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notDoctrine(): array
    {
        return [
            'the search screen' => ['https://www.lexml.gov.br/busca/search?keyword=Assalto&f1-tipoDocumento=Doutrina'],
            'a periodical' => ['https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:revista:2011;000913314'],
            'a ruling' => ['https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332'],
            'another host' => ['https://www.jusbrasil.com.br/urn/urn:lex:br:rede.virtual.bibliotecas:livro:2010;000884424'],
            'the home page' => ['https://www.lexml.gov.br/'],
        ];
    }

    /**
     * Refused before any request: the address came from a model, and fetching
     * whatever it wrote is the hole the guard closes.
     */
    #[Test]
    #[DataProvider('notDoctrine')]
    public function an_address_that_is_not_a_library_record_is_never_fetched(string $url): void
    {
        Http::fake();

        $this->assertFalse(DoctrineSources::isRecord($url));
        $this->assertNull((new DoctrineRecordReader)->read($url));

        Http::assertNothingSent();
    }

    /**
     * A periodical's record lists every article it published, each linked to
     * the article's own record — which is how a work the search only found as
     * part of a journal is reached. The fixture is a cut of the real page.
     */
    #[Test]
    public function it_lists_the_articles_a_periodical_published(): void
    {
        $periodical = 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:revista:1946;000348832';

        Http::fake([$periodical => Http::response($this->fixture('periodical-index.html'))]);

        $articles = (new DoctrineRecordReader)->articlesIn($periodical);

        $this->assertCount(6, $articles);

        $assalto = $articles[4];

        $this->assertSame('Responsabilidade civil no transporte de passageiros: "assalto a ônibus e excludentes"', $assalto->title);
        $this->assertSame('Aloysio Corrêa da Veiga', $assalto->author);
        $this->assertSame(
            'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:artigo.revista:2013;1000980597',
            $assalto->sourceUrl,
        );
    }

    #[Test]
    public function only_a_periodical_has_an_index(): void
    {
        Http::fake();

        $this->assertSame([], (new DoctrineRecordReader)->articlesIn(self::BOOK));

        Http::assertNothingSent();
    }

    #[Test]
    public function it_asks_like_a_browser(): void
    {
        Http::fake([self::ARTICLE => Http::response($this->fixture('doctrine-article.html'))]);

        (new DoctrineRecordReader)->read(self::ARTICLE);

        Http::assertSent(static fn (Request $request): bool => str_contains(
            $request->header('User-Agent')[0] ?? '',
            'Mozilla/5.0',
        ));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }
}
