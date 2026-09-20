<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O dossiê de pesquisa montado sobre a peça que ainda não existe.
 *
 * Este é o contrato que sustenta a quinta etapa de `ClassifyLegalCase`: aquela
 * Action não tem uma peça salva para entregar à pesquisa, monta uma em memória
 * com três relações penduradas à mão, e tudo depende de `forResearch()` só
 * tocar o modelo que recebe. Se um dia ele passar a ler uma coluna, uma chave
 * primária ou uma relação a mais, é aqui que o enquadramento assistido quebra —
 * e quebra em produção, porque nada mais na suíte padrão chega a essa Action
 * com uma peça não salva.
 *
 * Sem `RefreshDatabase` de propósito: a peça é construída em memória e nada é
 * gravado nem lido. Um teste que precisasse do banco estaria testando outra
 * coisa.
 */
final class LegalCaseDossierTest extends TestCase
{
    #[Test]
    public function it_writes_the_research_dossier_of_a_pleading_that_was_never_saved(): void
    {
        $dossier = LegalCaseDossier::forResearch($this->pleading(
            new ProceduralClass(['code' => 1118, 'name' => 'Embargos à Execução Fiscal']),
        ));

        $this->assertStringContainsString('- Área de atuação: Direito Tributário', $dossier);
        $this->assertStringContainsString('- Classe processual: [1118] Embargos à Execução Fiscal', $dossier);

        // A cifra é agrupada à brasileira e nunca passa por float — é dinheiro
        // que termina numa sentença.
        $this->assertStringContainsString('(valor pedido: R$ 4.300,00)', $dossier);

        // O cliente e o réu não entram na pesquisa, que é a única inferência do
        // projeto que sai da máquina. Nem o relato: ele é o prompt, não o
        // sistema.
        $this->assertStringNotContainsString('Joaquim', $dossier);
        $this->assertStringNotContainsString('O réu', $dossier);
        $this->assertStringNotContainsString('derrubou o muro', $dossier);
    }

    /**
     * A etapa que escolhe a classe pode cair sozinha, e a pesquisa continua.
     *
     * A linha simplesmente some. O agente perde a classe processual e mantém a
     * área, que é o que decide em que ramo do direito procurar — e é por isso
     * que derrubar a etapa inteira aqui seria trocar uma resposta parcial por
     * nenhuma.
     */
    #[Test]
    public function a_pleading_with_no_class_chosen_drops_the_line_instead_of_raising(): void
    {
        $dossier = LegalCaseDossier::forResearch($this->pleading(null));

        $this->assertStringContainsString('- Área de atuação: Direito Tributário', $dossier);
        $this->assertStringNotContainsString('Classe processual', $dossier);
        $this->assertStringContainsString('A exclusão do Autor do polo passivo;', $dossier);
    }

    /**
     * A peça como `ClassifyLegalCase::pleading()` a monta: sem chave primária,
     * sem nada gravado, e com as três relações que `forResearch()` consulta.
     */
    private function pleading(?ProceduralClass $class): LegalCase
    {
        $pleading = new LegalCase(['facts' => 'O vizinho derrubou o muro.']);

        $pleading->setRelation('practiceArea', new PracticeArea([
            'slug' => 'tributario',
            'label' => 'Direito Tributário',
        ]));
        $pleading->setRelation('proceduralClass', $class);
        $pleading->setRelation('requirements', new Collection([
            new Requirement(['description' => 'A exclusão do Autor do polo passivo;', 'amount' => null]),
            new Requirement(['description' => 'Danos materiais;', 'amount' => '4300.00']),
        ]));

        $this->assertFalse($pleading->exists);

        return $pleading;
    }
}
