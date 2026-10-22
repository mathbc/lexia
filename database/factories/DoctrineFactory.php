<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Doctrines\Models\Doctrine;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctrine>
 */
class DoctrineFactory extends Factory
{
    protected $model = Doctrine::class;

    /**
     * Real LexML library records, read off the portal on 2026-10-22, for the
     * reason CourtDecisionFactory gives: the URN, the catalogue's author heading
     * ("Tupinambá, Carolina") and the composed publication line all have shapes
     * the portal imposes, and a faked one would not exercise the columns. Their
     * `source_url` opens. `relevance` is the only field written here, as it is
     * the only one a model writes.
     *
     * @var list<array{kind: string, author: string, title: string, published_in: string, summary: ?string, subject: ?string, publication: ?string, relevance: string, urn: string}>
     */
    private const array DOCTRINES = [
        [
            'kind' => 'Artigo de revista',
            'author' => 'Tupinambá, Carolina',
            'title' => 'A responsabilidade do empregador em decorrência de assaltos e atos criminosos contra empregados',
            'published_in' => '2015',
            'summary' => 'Sumário: Os danos contidos no drama de ser vítima de assalto -- A natureza jurídica do ato criminoso ou o seu significado para o direito do trabalho -- Os novos paradigmas da responsabilidade civil.',
            'subject' => null,
            'publication' => 'Revista Magister de direito do trabalho. Porto Alegre, Magister, 2011. v. 11, n. 64, p. 108–130, jan./fev., 2015.',
            'relevance' => 'Trata da responsabilidade civil por assalto sofrido durante a prestação do serviço, questão central do relato.',
            'urn' => 'urn:lex:br:rede.virtual.bibliotecas:artigo.revista:2015;2001031582',
        ],
        [
            'kind' => 'Livro',
            'author' => 'Almeida, Carlos Alberto Neves',
            'title' => 'Do contrato de transporte aéreo e da responsabilidade civil do transportador aéreo',
            'published_in' => '2010',
            'summary' => null,
            'subject' => null,
            'publication' => 'Coimbra, Almedina, 2010.',
            'relevance' => 'Obra específica sobre a responsabilidade civil do transportador, instituto que o relato levanta.',
            'urn' => 'urn:lex:br:rede.virtual.bibliotecas:livro:2010;000884424',
        ],
        [
            'kind' => 'Livro',
            'author' => 'Theodoro Júnior, Humberto, 1938',
            'title' => 'Responsabilidade civil',
            'published_in' => '1996, 1994, 1993, 1989',
            'summary' => null,
            'subject' => null,
            'publication' => 'Rio de Janeiro, Aide, 1993.',
            'relevance' => 'Manual geral sobre responsabilidade civil, o ramo em que a questão do caso se insere.',
            'urn' => 'urn:lex:br:rede.virtual.bibliotecas:livro:1993;000183964',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $doctrine = fake()->randomElement(self::DOCTRINES);

        return [
            'account_id' => Account::factory(),
            // A peça nasce na mesma conta da obra, pelo motivo que
            // CourtDecisionFactory registra.
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            ...self::columns($doctrine),
        ];
    }

    public function forLegalCase(LegalCase $legalCase): static
    {
        return $this->state(fn (): array => [
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    /**
     * For a test that needs two rows it can tell apart by title.
     */
    public function nth(int $index): static
    {
        $doctrine = self::DOCTRINES[$index % count(self::DOCTRINES)];

        return $this->state(fn (): array => [...self::columns($doctrine), 'position' => $index]);
    }

    /**
     * One catalogue record as the columns hold it, with `source_url` composed
     * from the URN — on this portal the address *is* the name.
     *
     * @param  array{kind: string, author: string, title: string, published_in: string, summary: ?string, subject: ?string, publication: ?string, relevance: string, urn: string}  $doctrine
     * @return array<string, mixed>
     */
    private static function columns(array $doctrine): array
    {
        return [
            ...$doctrine,
            'source_url' => 'https://www.lexml.gov.br/urn/'.$doctrine['urn'],
        ];
    }
}
