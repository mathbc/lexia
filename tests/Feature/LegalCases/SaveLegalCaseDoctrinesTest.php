<?php

declare(strict_types=1);

namespace Tests\Feature\LegalCases;

use App\Domain\Doctrines\Data\DoctrineData;
use App\Domain\Doctrines\Data\DoctrineListData;
use App\Domain\Doctrines\Models\Doctrine;
use App\Domain\LegalCases\Actions\SaveLegalCaseDoctrines;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseFormProps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The doctrine of a pleading, written as a diff and read back by the fifth
 * step's third tab.
 *
 * No route points at the Action, so it is called directly; the route that
 * carries its payload — the "Concluir" — is pinned in FinalizeLegalCaseTest.
 */
final class SaveLegalCaseDoctrinesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_works_in_order_with_the_pleadings_account(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Documents]);

        SaveLegalCaseDoctrines::run($case, DoctrineListData::of([
            $this->work('Primeira obra', ';1'),
            $this->work('Segunda obra', ';2'),
        ]));

        $doctrines = $case->doctrines()->get();

        $this->assertSame(['Primeira obra', 'Segunda obra'], $doctrines->pluck('title')->all());
        $this->assertSame([0, 1], $doctrines->pluck('position')->all());
        $this->assertSame([$account->id], $doctrines->pluck('account_id')->unique()->values()->all());

        // As três abas são uma etapa só: gravar a doutrina levanta a mesma
        // marca d'água que gravar as teses.
        $this->assertSame(LegalCaseStep::Review, $case->refresh()->current_step);
    }

    /**
     * O diff: o que ficou é atualizado no lugar, o que saiu é apagado, e um id
     * que esta peça não possui — de outra peça, de outra conta, inventado — não
     * é encontrado e vira linha nova.
     */
    #[Test]
    public function saving_again_updates_what_stayed_and_removes_what_left(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);
        $kept = Doctrine::factory()->forLegalCase($case)->nth(0)->create();
        $left = Doctrine::factory()->forLegalCase($case)->nth(1)->create();
        $foreign = Doctrine::factory()->nth(2)->create();

        SaveLegalCaseDoctrines::run($case, DoctrineListData::fromArray(['doctrines' => [
            [...$kept->only(['id', 'kind', 'author', 'title', 'source_url', 'urn']), 'relevance' => 'Nota revista.'],
            $foreign->only(['id', 'kind', 'author', 'title', 'source_url', 'urn']),
        ]]));

        $rows = $case->doctrines()->get();

        $this->assertCount(2, $rows);
        $this->assertSame($kept->id, $rows[0]->id);
        $this->assertSame('Nota revista.', $rows[0]->relevance);
        $this->assertNotSame($foreign->id, $rows[1]->id);
        $this->assertSoftDeleted($left);
        $this->assertNotSoftDeleted($foreign);
    }

    #[Test]
    public function the_empty_list_removes_everything(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);
        $doctrine = Doctrine::factory()->forLegalCase($case)->create();

        SaveLegalCaseDoctrines::run($case, new DoctrineListData([]));

        $this->assertSame(0, $case->doctrines()->count());
        $this->assertSoftDeleted($doctrine);
    }

    /**
     * Do navegador não há a quem relatar a recusa: a obra sem autor e a que não
     * aponta para um registro da rede de bibliotecas simplesmente não entram.
     */
    #[Test]
    public function a_posted_work_that_cannot_be_cited_is_not_written(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);

        SaveLegalCaseDoctrines::run($case, DoctrineListData::fromArray(['doctrines' => [
            ['title' => 'Sem autor', 'author' => '', 'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:livro:2000;1'],
            ['title' => 'Periódico', 'author' => 'Editora', 'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:revista:2000;2'],
            ['title' => 'Fora do portal', 'author' => 'Alguém', 'source_url' => 'https://blog.example.com/obra'],
        ]]));

        $this->assertSame(0, $case->doctrines()->count());
    }

    /**
     * Uma peça reaberta uma semana depois hidrata pela mesma forma que a
     * gravação aceita, com os ids persistidos e na ordem do agente.
     */
    #[Test]
    public function the_form_reads_the_works_back_in_order(): void
    {
        [$account] = $this->accountWithOwner();
        $case = LegalCase::factory()->forAccount($account)->create(['current_step' => LegalCaseStep::Review]);
        $second = Doctrine::factory()->forLegalCase($case)->nth(1)->create();
        $first = Doctrine::factory()->forLegalCase($case)->nth(0)->create();

        $props = LegalCaseFormProps::draft($case->refresh());

        $this->assertSame([$first->id, $second->id], array_column($props['doctrines'], 'id'));
        $this->assertSame($first->author, $props['doctrines'][0]['author']);
        $this->assertNull($props['doctrine_research']);
    }

    private function work(string $title, string $suffix): DoctrineData
    {
        return DoctrineData::fromArray([
            'kind' => 'Livro',
            'author' => 'Almeida, Carlos Alberto Neves',
            'title' => $title,
            'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:livro:2010'.$suffix,
        ]);
    }
}
