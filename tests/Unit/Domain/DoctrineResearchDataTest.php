<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Doctrines\Data\DoctrineData;
use App\Domain\LegalCases\Data\DoctrineResearchData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The doctrine match and the five, without a network and without a model.
 *
 * The deterministic twin of `tests/Agents/DoctrineResearchTest`, and the file to
 * trust when that one is red because the LexML or the quota is. What is pinned
 * is the rule the first live run forced: the **provider's** records are what get
 * read, and the agent's works only count when a record holds them — the agent
 * invents the catalogue number of every address it writes.
 *
 * Extends PHPUnit's TestCase directly: nothing here touches the container.
 */
final class DoctrineResearchDataTest extends TestCase
{
    private const string LIBRARY = 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:';

    #[Test]
    public function the_sources_are_the_library_records_the_provider_returned(): void
    {
        $research = DoctrineResearchData::fromAgent(['doctrines' => []], [
            self::LIBRARY.'livro:2010;000884424',
            self::LIBRARY.'revista:1975;000424538',
            'https://www.lexml.gov.br/busca/search?keyword=transporte&f1-tipoDocumento=Doutrina',
            'https://blog.example.com/transportador',
            self::LIBRARY.'livro:2010;000884424',
        ]);

        $this->assertSame([self::LIBRARY.'livro:2010;000884424'], $research->sources);
    }

    #[Test]
    public function the_candidates_are_the_named_works_once_each_and_capped(): void
    {
        $rows = [
            ['title' => 'Programa de responsabilidade civil', 'author' => 'Cavalieri Filho, Sergio'],
            ['title' => 'Programa de Responsabilidade Civil', 'author' => 'Sergio Cavalieri Filho'],
            ['title' => '', 'author' => 'Sem título'],
            ['title' => 'Não localizado/confirmado em fonte oficial.', 'author' => null],
        ];

        foreach (range(1, 10) as $n) {
            $rows[] = ['title' => "Obra {$n}", 'author' => 'Autor'];
        }

        $research = DoctrineResearchData::fromAgent(['doctrines' => $rows]);

        $this->assertCount(DoctrineResearchData::CANDIDATES, $research->doctrines);
        $this->assertSame('Programa de responsabilidade civil', $research->doctrines[0]->title);
        $this->assertSame('Obra 1', $research->doctrines[1]->title);
    }

    /**
     * The case the redesign exists for, as the first live run returned it: the
     * agent named the right work at an address that does not exist, and the
     * provider's citation pointed at the real record.
     */
    #[Test]
    public function a_named_work_is_confirmed_by_the_record_the_provider_returned(): void
    {
        $real = self::LIBRARY.'artigo.revista:1995;1000506067';

        $research = DoctrineResearchData::fromAgent(['doctrines' => [[
            'title' => 'A responsabilidade no transporte terrestre de passageiro',
            'author' => 'Sergio Cavalieri Filho',
            'source_url' => self::LIBRARY.'artigo.revista:1995;12030563',
            'relevance' => 'Trata da responsabilidade do transportador por assalto.',
        ]]], [$real])->readRecords($this->catalogue([
            $real => ['A responsabilidade no transporte terrestre de passageiro: à luz do Código do consumidor', 'Cavalieri Filho, Sergio, 1949'],
        ]));

        $this->assertCount(1, $research->doctrines);
        $this->assertSame($real, $research->doctrines[0]->sourceUrl);
        $this->assertSame('Cavalieri Filho, Sergio, 1949', $research->doctrines[0]->author);
        $this->assertSame('Trata da responsabilidade do transportador por assalto.', $research->doctrines[0]->relevance);
        $this->assertSame([], $research->unverifiedCitations);
    }

    /**
     * Two books called "Responsabilidade civil" are two works: the title alone
     * does not attach one author's note to another author's book.
     */
    #[Test]
    public function the_same_title_by_another_author_is_another_work(): void
    {
        $stoco = self::LIBRARY.'livro:2013;000984910';

        $research = DoctrineResearchData::fromAgent(['doctrines' => [[
            'title' => 'Responsabilidade civil',
            'author' => 'Theodoro Júnior, Humberto',
            'relevance' => 'Nota sobre o livro de Theodoro Júnior.',
        ]]], [$stoco])->readRecords($this->catalogue([
            $stoco => ['Responsabilidade civil', 'Stoco, Rui'],
        ]));

        $this->assertSame(['Responsabilidade civil'], $research->unverifiedCitations);

        // O livro do Stoco continua: a busca o trouxe pelos termos do caso. Só
        // não leva a nota que o agente escreveu sobre outra obra.
        $this->assertSame('Stoco, Rui', $research->doctrines[0]->author);
        $this->assertNull($research->doctrines[0]->relevance);
    }

    #[Test]
    public function the_named_works_come_first_and_the_unnamed_records_fill_up_to_five(): void
    {
        $grounded = array_map(static fn (int $n): string => self::LIBRARY."livro:2000;{$n}", range(1, 8));
        $catalogue = array_combine($grounded, array_map(
            static fn (int $n): array => ["Obra {$n}", "Autor{$n}, Fulano"],
            range(1, 8),
        ));

        $research = DoctrineResearchData::fromAgent(['doctrines' => [
            ['title' => 'Obra 6', 'author' => 'Autor6, Fulano', 'relevance' => 'A mais específica.'],
            ['title' => 'Obra 3', 'author' => 'Autor3, Fulano', 'relevance' => 'A segunda.'],
        ]], $grounded)->readRecords($this->catalogue($catalogue));

        $this->assertSame(
            ['Obra 6', 'Obra 3', 'Obra 1', 'Obra 2', 'Obra 4'],
            array_map(static fn (DoctrineData $doctrine): string => $doctrine->title, $research->doctrines),
        );
        $this->assertSame('A mais específica.', $research->doctrines[0]->relevance);
        $this->assertNull($research->doctrines[2]->relevance);
    }

    /**
     * The agent's own address is read too — it is sometimes right —, but only
     * a record that holds the named work confirms it.
     */
    #[Test]
    public function the_agents_own_address_counts_when_it_reads(): void
    {
        $address = self::LIBRARY.'livro:2010;000884424';

        $research = DoctrineResearchData::fromAgent(['doctrines' => [[
            'title' => 'Do contrato de transporte aéreo e da responsabilidade civil do transportador aéreo',
            'author' => 'Almeida, Carlos Alberto Neves',
            'source_url' => $address,
        ]]])->readRecords($this->catalogue([
            $address => ['Do contrato de transporte aéreo e da responsabilidade civil do transportador aéreo', 'Almeida, Carlos Alberto Neves'],
        ]));

        $this->assertSame([$address], array_map(
            static fn (DoctrineData $doctrine): ?string => $doctrine->sourceUrl,
            $research->doctrines,
        ));
    }

    /**
     * The third live run: the agent named a real article, and the search had
     * returned only the journal it was published in. The journal's index knows
     * the article's address; the article's own record is what gets shown.
     */
    #[Test]
    public function an_article_only_a_periodicals_index_knew_is_read_and_confirmed(): void
    {
        $periodical = self::LIBRARY.'revista:1946;000348832';
        $article = self::LIBRARY.'artigo.revista:2013;1000980597';
        $title = 'Responsabilidade civil no transporte de passageiros: "assalto a ônibus e excludentes"';

        $research = DoctrineResearchData::fromAgent(['doctrines' => [[
            'title' => $title,
            'author' => 'Veiga, Aloysio Corrêa da',
            'relevance' => 'Trata das excludentes no assalto a ônibus.',
        ]]], [$periodical]);

        $this->assertSame([], $research->sources);
        $this->assertSame([$periodical], $research->periodicals);

        $research = $research->readRecords(
            $this->catalogue([$article => [$title, 'Veiga, Aloysio Corrêa da']]),
            fn (string $url): array => $url === $periodical
                ? [$this->record($article, $title, 'Aloysio Corrêa da Veiga')]
                : [],
        );

        $this->assertSame([$article], array_map(
            static fn (DoctrineData $doctrine): ?string => $doctrine->sourceUrl,
            $research->doctrines,
        ));
        $this->assertSame('Trata das excludentes no assalto a ônibus.', $research->doctrines[0]->relevance);
        $this->assertSame([], $research->unverifiedCitations);
    }

    /**
     * An index entry is not a record: if the article's own page does not open,
     * the entry confirms nothing.
     */
    #[Test]
    public function an_index_entry_whose_record_does_not_open_confirms_nothing(): void
    {
        $periodical = self::LIBRARY.'revista:1946;000348832';
        $article = self::LIBRARY.'artigo.revista:2013;1000980597';

        $research = DoctrineResearchData::fromAgent(['doctrines' => [[
            'title' => 'Dosimetria do dano moral',
            'author' => 'Alexandre Agra Belmonte',
        ]]], [$periodical])->readRecords(
            static fn (): ?DoctrineData => null,
            fn (): array => [$this->record($article, 'Dosimetria do dano moral', 'Alexandre Agra Belmonte')],
        );

        $this->assertSame([], $research->doctrines);
        $this->assertSame(['Dosimetria do dano moral'], $research->unverifiedCitations);
    }

    #[Test]
    public function fewer_works_are_never_padded_and_the_reads_are_capped(): void
    {
        $grounded = array_map(static fn (int $n): string => self::LIBRARY."livro:2000;{$n}", range(1, 20));
        $read = 0;

        $research = DoctrineResearchData::fromAgent(['doctrines' => []], $grounded)->readRecords(
            function (string $url) use (&$read): ?DoctrineData {
                $read++;

                // Só um registro abre: o resto é "urn não encontrada".
                return str_ends_with($url, ';1') ? $this->record($url, 'Obra 1', 'Autor, Fulano') : null;
            },
        );

        $this->assertCount(1, $research->doctrines);
        $this->assertSame(DoctrineResearchData::MAX_READS, $read);
    }

    #[Test]
    public function the_findings_carry_the_terms_and_drop_the_placeholders(): void
    {
        $findings = DoctrineResearchData::fromAgent([
            'search_terms' => ['dano moral consumidor', 'nulo'],
            'doctrines' => [],
            'pending' => ['Nada.', 'O termo "dano moral consumidor" não retornou obra.'],
        ], [self::LIBRARY.'livro:2000;1'])->findings();

        $this->assertSame(['dano moral consumidor'], $findings['search_terms']);
        $this->assertSame([self::LIBRARY.'livro:2000;1'], $findings['sources']);
        $this->assertSame(['O termo "dano moral consumidor" não retornou obra.'], $findings['pending']);
        $this->assertSame([], $findings['unverified_citations']);
        $this->assertNotEmpty($findings['researched_at']);
    }

    #[Test]
    public function an_answer_shaped_like_nothing_is_an_empty_run(): void
    {
        $research = DoctrineResearchData::fromAgent(['doctrines' => 'texto solto', 'search_terms' => null]);

        $this->assertSame([], $research->doctrines);
        $this->assertSame([], $research->searchTerms);
        $this->assertSame([], $research->readRecords(static fn (): ?DoctrineData => null)->doctrines);
    }

    /**
     * A reader over a fixed catalogue: url => [title, author].
     *
     * @param  array<string, array{0: string, 1: string}>  $catalogue
     * @return callable(string): ?DoctrineData
     */
    private function catalogue(array $catalogue): callable
    {
        return fn (string $url): ?DoctrineData => isset($catalogue[$url])
            ? $this->record($url, ...$catalogue[$url])
            : null;
    }

    private function record(string $url, string $title, string $author): DoctrineData
    {
        return DoctrineData::fromArray([
            'kind' => 'Livro',
            'author' => $author,
            'title' => $title,
            'source_url' => $url,
        ]);
    }
}
