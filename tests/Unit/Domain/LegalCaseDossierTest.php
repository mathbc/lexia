<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Documents\Models\Document;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalPrecedents\Models\LegalPrecedent;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use Carbon\CarbonImmutable;
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
     * O dossiê de redação: o mais largo dos três, e o único que qualifica.
     *
     * A narrativa só precisa saber como chamar as partes; a petição **abre**
     * qualificando-as, então o endereço inteiro vai. O que continua fora é o
     * relato, que é o prompt — a mesma regra dos dois irmãos.
     */
    #[Test]
    public function the_drafting_dossier_qualifies_both_parties(): void
    {
        $dossier = LegalCaseDossier::forDrafting($this->fullPleading());

        $this->assertStringContainsString('- Nome: Joaquim Vizinho', $dossier);
        // Mascarado: vai copiado para um parágrafo que um juiz lê.
        $this->assertStringContainsString('- Documento: 115.863.259-22', $dossier);
        $this->assertStringContainsString(
            '- Endereço: Rua das Acácias, 118, Centro, Itajaí/SC, CEP 88301-000',
            $dossier,
        );

        // O réu é descrito e não cadastrado: o que se sabe dele entra, e as
        // linhas que faltam somem em vez de virarem "não informado".
        $this->assertStringContainsString('- Nome: Construtora Muro Ltda', $dossier);
        $this->assertStringContainsString('- Endereço: Avenida Brasil, 900, Balneário Camboriú/SC', $dossier);

        $this->assertStringNotContainsString('derrubou o muro', $dossier);
    }

    /**
     * A qualificação vai quando existe, e some quando não existe.
     *
     * Estado civil e profissão eram, até o cadastro passar a pedi-los, o
     * exemplo canônico do que o agente marca entre colchetes em vez de chutar.
     * A regra não mudou — mudou a chance: `written()` continua descartando a
     * linha vazia, e é essa ausência que manda escrever `[estado civil]`.
     *
     * A idade entrou junto, e a data de nascimento continua fora: o dossiê
     * declara "47 anos" porque é o que a qualificação declara, e nunca
     * "02/04/1979", porque a qualificação padrão não declara aniversário e um
     * campo no dossiê é um convite a escrevê-lo. A subtração é feita aqui, e
     * não no prompt, que é a mesma regra da cifra vista de outro ângulo.
     *
     * O relógio é fixado porque a asserção é sobre um número que envelhece: sem
     * isso este teste passa a vida inteira e fica vermelho no aniversário da
     * cliente inventada.
     */
    #[Test]
    public function the_drafting_dossier_qualifies_the_plaintiff_only_as_far_as_the_registration_does(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20'));

        $qualified = LegalCaseDossier::forDrafting($this->fullPleading(customerAttributes: [
            'marital_status' => MaritalStatus::Married,
            'occupation' => 'Marceneiro',
            'birth_date' => '1979-04-02',
        ]));

        $this->assertStringContainsString('- Estado civil: Casado(a)', $qualified);
        $this->assertStringContainsString('- Profissão: Marceneiro', $qualified);
        $this->assertStringContainsString('- Idade: 47 anos', $qualified);
        $this->assertStringNotContainsString('1979', $qualified);

        // O cliente que ninguém qualificou: as três linhas somem inteiras, em
        // vez de chegarem como "não informado" — que é o que ensina um modelo
        // pequeno a escrever "não informado" dentro da petição.
        $unqualified = LegalCaseDossier::forDrafting($this->fullPleading());

        $this->assertStringNotContainsString('Estado civil', $unqualified);
        $this->assertStringNotContainsString('Profissão', $unqualified);
        $this->assertStringNotContainsString('Idade', $unqualified);
    }

    /**
     * Uma data de nascimento no futuro não qualifica ninguém com idade negativa.
     *
     * `diffInYears()` é negativo para uma data que ainda não chegou, e o corte
     * em zero de `age()` é o que transforma o erro de digitação em ausência —
     * que o agente já sabe tratar, porque é o mesmo caso do cadastro que nunca
     * perguntou a idade.
     */
    #[Test]
    public function the_drafting_dossier_drops_an_age_it_cannot_compute(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20'));

        $dossier = LegalCaseDossier::forDrafting($this->fullPleading(customerAttributes: [
            'birth_date' => '2079-04-02',
        ]));

        $this->assertStringNotContainsString('Idade', $dossier);
        $this->assertStringNotContainsString('2079', $dossier);
    }

    /**
     * As teses entram; os precedentes, não.
     *
     * É uma decisão do produto e não um esquecimento: a seção de jurisprudência
     * é trabalho de outro momento, e um agente que recebesse julgados escreveria
     * uma seção que ninguém pediu. A instrução negativa do prompt se apoia nesta
     * ausência — é ela que torna a instrução verificável.
     */
    #[Test]
    public function the_drafting_dossier_carries_the_theses_and_not_the_precedents(): void
    {
        $dossier = LegalCaseDossier::forDrafting($this->fullPleading());

        $this->assertStringContainsString('- Tese: Da Responsabilidade Civil do Construtor', $dossier);
        $this->assertStringContainsString('- Fundamentos a citar: Art. 186 do CC; Art. 927 do CC', $dossier);

        $this->assertStringNotContainsString('Súmula 479', $dossier);
        $this->assertStringNotContainsString('Apelação Cível', $dossier);
    }

    /**
     * A seção dos documentos some sozinha enquanto nada persiste um anexo.
     *
     * Hoje a relação chega sempre vazia, e `section()` diz isso em palavras em
     * vez de abrir um título vazio. No dia em que os uploads forem gravados a
     * seção passa a existir sem que esta classe ou o agente mudem.
     */
    #[Test]
    public function the_documents_section_appears_only_when_there_are_documents(): void
    {
        $without = LegalCaseDossier::forDrafting($this->fullPleading(documents: []));
        $this->assertStringContainsString(
            '## Os documentos que instruem a peça'.PHP_EOL.PHP_EOL.'Nada registrado nesta peça até aqui.',
            $without,
        );

        $with = LegalCaseDossier::forDrafting($this->fullPleading(documents: [
            new Document(['name' => 'Boletim de Ocorrência', 'description' => 'BO nº 123456']),
        ]));
        $this->assertStringContainsString('- Boletim de Ocorrência — BO nº 123456', $with);
    }

    /**
     * Uma peça inteira, ainda em memória.
     *
     * @param  list<Document>|null  $documents
     * @param  array<string, mixed>  $customerAttributes
     */
    private function fullPleading(?array $documents = null, array $customerAttributes = []): LegalCase
    {
        $pleading = $this->pleading(
            new ProceduralClass(['code' => 7, 'name' => 'Procedimento Comum Cível']),
        );

        $pleading->setRelation('customer', new Customer([
            'name' => 'Joaquim Vizinho',
            'type' => CustomerType::Individual,
            'cpf' => '11586325922',
            'postal_code' => '88301000',
            'street' => 'Rua das Acácias',
            'number' => '118',
            'district' => 'Centro',
            'city' => 'Itajaí',
            'state' => BrazilianState::SC,
            ...$customerAttributes,
        ]));

        $pleading->fill([
            'defendant_name' => 'Construtora Muro Ltda',
            'defendant_street' => 'Avenida Brasil',
            'defendant_number' => '900',
            'defendant_city' => 'Balneário Camboriú',
            'defendant_state' => BrazilianState::SC,
        ]);

        $pleading->setRelation('theses', new Collection([
            new LegalThesis([
                'name' => 'Da Responsabilidade Civil do Construtor',
                'description' => 'A conduta culposa gera o dever de indenizar.',
                'impact' => 'Assegura a reparação integral.',
                'legal_bases' => [
                    ['type' => 'article', 'reference' => 'Art. 186 do CC', 'source' => 'CC'],
                    ['type' => 'article', 'reference' => 'Art. 927 do CC', 'source' => 'CC'],
                ],
            ]),
        ]));

        // Penduradas de propósito, e o teste acima garante que não viajam: se um
        // dia `forDrafting()` passar a lê-las, é aqui que se descobre.
        $pleading->setRelation('precedents', new Collection([
            new LegalPrecedent([
                'name' => 'STJ — Súmula 479',
                'description' => 'Apelação Cível sobre responsabilidade objetiva.',
            ]),
        ]));

        $pleading->setRelation('documents', new Collection($documents ?? []));

        return $pleading;
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
