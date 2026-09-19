<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\ForensicReviewData;
use App\Domain\LegalPrecedents\Data\LegalPrecedentData;
use App\Domain\LegalPrecedents\Enums\LegalPrecedentType;
use App\Domain\LegalTheses\Data\LegalBasisData;
use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The forensic review as a value, before any row exists.
 *
 * No database and no inference: what is tested here is the reading of a payload
 * and the shape of what goes into a column. Three things live in this file
 * because a mistake in any of them is silent.
 *
 * The **adherence** is read from whatever dialect it arrives in, the way money
 * is in RequirementListDataTest, and zero means unmeasured rather than
 * irrelevant. The **legal bases** go into a jsonb column, where a PHP enum
 * would serialise to something no migration promised. And `toArray()` on a
 * precedent **takes the foreign key as an argument**, which is the guard that
 * keeps a posted `legal_thesis_id` from reaching the database unresolved — a
 * test that reads oddly until you remember that a foreign key checks existence
 * and not ownership.
 */
final class ForensicReviewDataTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function adherences(): array
    {
        return [
            'o inteiro que as instruções pedem' => ['93', '93.00'],
            'com o símbolo que a tela desenha' => ['93%', '93.00'],
            'notação brasileira' => ['93,5', '93.50'],
            'notação decimal' => ['93.5', '93.50'],
            'número de verdade e não string' => [93, '93.00'],
            'float' => [93.5, '93.50'],
            // Grampeado em vez de recusado: perder o achado inteiro por causa
            // de um 120 entusiasmado seria a troca pior.
            'acima de cem' => ['120', '100.00'],
            'exatamente cem' => ['100', '100.00'],
            // Zero é ausência de medida, como "um pedido que não cobra é um
            // pedido sem cifra": um julgado com zero não teria entrado na lista.
            'zero' => ['0', null],
            'zero com casas' => ['0,00', null],
            'vazio' => ['', null],
            'ausente' => [null, null],
            'texto sem número' => ['alta', null],
            // Não reescalado de propósito: multiplicar por cem seria palpite, e
            // o mesmo palpite transformaria um 0,93% legítimo em 93%.
            'a escala de zero a um não é adivinhada' => ['0.93', '0.93'],
        ];
    }

    #[Test]
    #[DataProvider('adherences')]
    public function it_reads_the_adherence_in_whichever_dialect_it_arrives(mixed $written, ?string $expected): void
    {
        $precedent = LegalPrecedentData::fromArray([
            'name' => 'STJ — Súmula nº 393/STJ',
            'adherence' => $written,
        ]);

        $this->assertSame($expected, $precedent->adherence);
    }

    #[Test]
    public function a_precedent_writes_the_thesis_it_is_handed_and_not_the_one_it_carries(): void
    {
        $precedent = LegalPrecedentData::fromArray([
            'legal_thesis_id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'STJ — Súmula nº 430/STJ',
            'type' => 'sumula',
            'description' => 'O inadimplemento não gera responsabilidade solidária.',
        ]);

        // O palpite chega e fica guardado…
        $this->assertSame('11111111-1111-4111-8111-111111111111', $precedent->thesisId);

        // …mas o que vai para a linha é o que quem resolveu decidiu. A
        // assinatura é a guarda: não há como produzir a linha sem antes decidir.
        $row = $precedent->toArray('99999999-9999-4999-8999-999999999999');

        $this->assertSame('99999999-9999-4999-8999-999999999999', $row['legal_thesis_id']);
        $this->assertSame(LegalPrecedentType::Sumula, $row['type']);
        $this->assertArrayNotHasKey('id', $row);

        // E nada resolvido é nada gravado.
        $this->assertNull($precedent->toArray(null)['legal_thesis_id']);
    }

    #[Test]
    public function a_basis_goes_into_the_column_as_the_backing_value(): void
    {
        $basis = LegalBasisData::fromArray([
            'type' => 'sumula',
            'reference' => 'Súmula 393 do STJ',
            'source' => 'STJ',
        ]);

        $this->assertSame(LegalBasisType::Sumula, $basis->type);

        // O documento gravado é registro histórico, como a migration: guarda o
        // valor e nunca o objeto do enum.
        $this->assertSame(
            ['type' => 'sumula', 'reference' => 'Súmula 393 do STJ', 'source' => 'STJ'],
            $basis->toArray(),
        );
    }

    #[Test]
    public function an_unclassifiable_basis_keeps_its_citation(): void
    {
        $basis = LegalBasisData::fromArray([
            'type' => 'portaria',
            'reference' => 'Portaria PGFN nº 502/2016',
        ]);

        // Perder a fundamentação por causa de uma taxonomia seria a troca pior:
        // o tipo cai para null e a citação segue.
        $this->assertNull($basis->type);
        $this->assertSame('Portaria PGFN nº 502/2016', $basis->reference);
        $this->assertNull($basis->source);
        $this->assertTrue($basis->isWritten());
    }

    #[Test]
    public function blank_rows_are_dropped_at_every_level(): void
    {
        $review = ForensicReviewData::fromArray([
            'theses' => [
                ['name' => '', 'description' => 'Sem título.'],
                ['name' => '   ', 'description' => ''],
                ['name' => 'A tese de verdade', 'description' => 'Escrita.', 'legal_bases' => [
                    ['type' => 'article', 'reference' => 'Art. 803 do CPC', 'source' => 'CPC'],
                    ['type' => 'sumula', 'reference' => '', 'source' => 'STJ'],
                    ['type' => 'theme', 'reference' => '   '],
                ]],
            ],
            'precedents' => [
                ['name' => '', 'description' => 'Sem nome.'],
                ['name' => 'STJ — Súmula nº 393/STJ', 'description' => 'A exceção é admissível.'],
            ],
        ]);

        $this->assertCount(1, $review->theses);
        $this->assertCount(1, $review->precedents);

        // O fundamento em branco também não sobrevive — é um campo que alguém
        // abriu e abandonou, no nível de dentro.
        $this->assertCount(1, $review->theses[0]->legalBases);
        $this->assertSame('Art. 803 do CPC', $review->theses[0]->legalBases[0]->reference);
    }

    #[Test]
    public function both_empty_lists_are_a_legitimate_answer(): void
    {
        $review = ForensicReviewData::fromArray(['theses' => [], 'precedents' => []]);

        $this->assertSame([], $review->theses);
        $this->assertSame([], $review->precedents);

        // E um payload sem as chaves não explode: é o mesmo nada.
        $missing = ForensicReviewData::fromArray([]);

        $this->assertSame([], $missing->theses);
        $this->assertSame([], $missing->precedents);
    }

    #[Test]
    public function a_thesis_writes_its_bases_as_a_list_and_leaves_its_id_out(): void
    {
        $review = ForensicReviewData::fromArray([
            'theses' => [[
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => 'Do Cabimento da Exceção de Pré-Executividade',
                'type' => 'preliminary',
                'description' => 'Matéria de ordem pública.',
                'legal_bases' => [
                    ['type' => 'sumula', 'reference' => 'Súmula 393 do STJ', 'source' => 'STJ'],
                    ['type' => 'statute', 'reference' => 'Lei nº 6.830/80'],
                ],
            ]],
            'precedents' => [],
        ]);

        $thesis = $review->theses[0];
        $this->assertSame('11111111-1111-4111-8111-111111111111', $thesis->id);
        $this->assertSame(LegalThesisType::Preliminary, $thesis->type);

        $row = $thesis->toArray();

        $this->assertArrayNotHasKey('id', $row);
        $this->assertSame([
            ['type' => 'sumula', 'reference' => 'Súmula 393 do STJ', 'source' => 'STJ'],
            ['type' => 'statute', 'reference' => 'Lei nº 6.830/80', 'source' => null],
        ], $row['legal_bases']);
    }

    #[Test]
    public function an_unclassifiable_kind_does_not_lose_the_thesis(): void
    {
        $review = ForensicReviewData::fromArray([
            'theses' => [['name' => 'Uma tese', 'type' => 'merito_principal', 'description' => 'Escrita.']],
            'precedents' => [['name' => 'Um julgado', 'type' => 'acordao', 'description' => 'Ementa.']],
        ]);

        // A grafia do print não é a do enum, e uma tese não se perde por isso.
        $this->assertNull($review->theses[0]->type);
        $this->assertNull($review->precedents[0]->type);
        $this->assertSame('Uma tese', $review->theses[0]->name);
    }
}
