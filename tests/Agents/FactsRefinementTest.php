<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Actions\RefineLegalCaseFacts;
use App\Domain\LegalCases\Data\RefinedFactsData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The facts refinement agent against the real text provider, no fakes.
 *
 * No RefreshDatabase, like the two extraction tests: the pleading here is built
 * in memory — `new LegalCase(...)` with its four relations set by hand — so
 * nothing is written, nothing is read back and no catalogue is needed. That is
 * also the honest shape of the dependency: the agent reads a *dossier*, and
 * LegalCaseDossier only ever touches the model it is handed.
 *
 * Two cases, because the agent makes two decisions and the second one is the
 * interesting half:
 *
 * - **A commercial matter**, where the answer is register and nothing else. It
 *   carries an arithmetic trap — a monthly fee and a number of months — because
 *   a total nobody wrote is the invention this project has measured before.
 * - **An irreparable loss**, where the narrative is supposed to carry weight,
 *   and where the failure is the opposite one: a suffering nobody narrated.
 *
 * What is pinned follows the rule the sibling tests set. Wording is judgement
 * and pinning it would turn ordinary variation into a red build; what is pinned
 * is copying (the figures and the words the client chose), the decision the
 * agent declares in `impact_basis`, the person of the verb, and the inventions
 * the instructions forbid outright.
 *
 * ## What the configured model does and does not hold
 *
 * Measured while these assertions were written, same prompt, same two cases:
 *
 * - `qwen3.8:27b` holds everything the instructions ask for, including the two
 *   hardest rules — it never composes a total, and it carries the clause the
 *   client wrote in passing ("as coisas da minha mãe, que faleceu em janeiro")
 *   into the narrative, which is the fact the whole case rests on.
 * - `qwen2.5:7b`, which is what `OLLAMA_TEXT_MODEL` names today, holds the
 *   commercial case reliably once the prompt names the connectives rather than
 *   the operation ("não escreva totalizando, no total de, perfazendo"). On the
 *   longer narrative it still drops that embedded clause and still reaches for
 *   a total now and then.
 *
 * So the money question is not asserted on the harder case: it lives in code
 * instead, as `RefinedFactsData::unsupportedAmounts`, which is model
 * independent and has deterministic tests in `tests/Unit/Domain`. The answer is
 * dumped in full here, that report included, because reading it is the point.
 * Losing the embedded clause has no guard and cannot get one — it is an absence
 * — and it is the reason this agent's output is a draft for a lawyer to accept
 * rather than a field that fills itself.
 *
 * Grouped out of the default run for the same reason as its siblings: it needs
 * Ollama up and spends seconds on inference — two inferences here, one per case.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class FactsRefinementTest extends TestCase
{
    #[Test]
    public function it_formalises_a_commercial_dispute_without_dramatising_it(): void
    {
        $facts = <<<'TXT'
        Doutor, a gente faz a manutenção das empilhadeiras da Transportadora Lorenzi faz uns 3 anos.
        Em 2025 fechamos um contrato de manutenção mensal, R$ 4.800,00 por mês, com relatório de
        atendimento toda vez que a gente vai lá. De março a agosto de 2026 eu atendi todos os chamados
        e mandei todos os relatórios, mas eles pararam de pagar a partir de abril. São 5 meses em
        aberto. Mandei e-mail, mandei carta com AR, e o financeiro só empurra com a barriga, fala que
        o pagamento tá em processamento e nunca cai. Continuei atendendo porque tinha contrato, mas
        agora não dá mais.
        TXT;

        $legalCase = $this->pleading(
            area: new PracticeArea(['slug' => 'empresarial', 'label' => 'Direito Empresarial e Falimentar']),
            class: new ProceduralClass(['code' => 7, 'name' => 'Procedimento Comum Cível']),
            customer: new Customer([
                'name' => 'Meccanica Assistência Técnica',
                'legal_name' => 'Meccanica Assistência Técnica Ltda',
                'type' => CustomerType::Company,
                'cnpj' => '07665331000104',
                'city' => 'Joinville',
                'state' => 'SC',
            ]),
            attributes: [
                'defendant_name' => 'Transportadora Lorenzi Ltda',
                'defendant_document' => '19884270000162',
                'defendant_city' => 'Araquari',
                'defendant_state' => 'SC',
                'defendant_notes' => 'Contato mantido com o setor financeiro da Ré.',
                'court_addressing' => 'Ao Juízo de Direito da Vara Cível da Comarca de Araquari/SC',
                'facts' => $facts,
            ],
            requirements: [
                new Requirement([
                    'description' => 'A condenação da Ré ao pagamento das mensalidades vencidas e não pagas do contrato de manutenção;',
                    'amount' => null,
                ]),
            ],
        );

        $refined = RefineLegalCaseFacts::run($legalCase);

        $this->show($facts, $refined);

        // Uma cobrança entre empresas não é para ser escrita como tragédia, e é
        // esta a asserção que diz que o destaque não se aplica a todo processo.
        $this->assertNull($refined->impactBasis);

        // "5 meses a R$ 4.800,00" não vira R$ 24.000,00: o total é uma conta, e
        // uma conta produz um número que ninguém escreveu. A guarda é quem
        // responde por isso, e aqui ela tem de vir vazia.
        $this->assertSame([], $refined->unsupportedAmounts);

        $this->assertThirdPerson($refined);

        // O que é copiado é pinado: a mensalidade e o objeto do contrato estão
        // escritos no relato.
        $this->assertStringContainsString('4.800,00', $refined->facts);
        $this->assertStringContainsString('empilhadeira', mb_strtolower($refined->facts));

        // Os fatos são uma seção, e só ela: o pedido já existe na peça, a
        // fundamentação virá depois e o título é da tela.
        $this->assertStringNotContainsStringIgnoringCase('ante o exposto', $refined->facts);
        $this->assertDoesNotMatchRegularExpression('/^\s*DOS FATOS/mi', $refined->facts);
    }

    #[Test]
    public function it_gives_weight_to_a_loss_that_cannot_be_undone(): void
    {
        $facts = <<<'TXT'
        Doutora, em 08/05/2026 contratei a Mudanças Peroba pra levar minhas coisas de Florianópolis
        pra Itajaí e paguei R$ 3.200,00. Eram 42 caixas, chegaram 38. As 4 que sumiram eram justamente
        as que tinham as coisas da minha mãe, que faleceu em janeiro: os álbuns de foto da família
        inteira, as cartas que ela escreveu pro meu pai quando namoravam, a aliança de casamento dela
        e o vestido que ela usou no dia do casamento. Eu tinha etiquetado tudo e avisei o motorista na
        hora do carregamento que aquelas quatro caixas eram as mais importantes. Liguei no dia
        seguinte, falaram que iam procurar no galpão, e depois de duas semanas disseram que não
        acharam e me ofereceram R$ 400,00, que é o que o contrato deles paga por caixa extraviada.
        Não é questão de dinheiro, doutora. Não tem como comprar de novo.
        TXT;

        $legalCase = $this->pleading(
            area: new PracticeArea(['slug' => 'consumidor', 'label' => 'Direito do Consumidor']),
            class: new ProceduralClass(['code' => 436, 'name' => 'Procedimento do Juizado Especial Cível']),
            customer: new Customer([
                'name' => 'Marina Fontes Rebelo',
                'type' => CustomerType::Individual,
                'cpf' => '02938471055',
                'city' => 'Itajaí',
                'state' => 'SC',
            ]),
            attributes: [
                'defendant_name' => 'Mudanças Peroba Transportes Ltda',
                'defendant_document' => '31447902000148',
                'defendant_city' => 'Florianópolis',
                'defendant_state' => 'SC',
                'court_addressing' => 'Ao Juizado Especial Cível da Comarca de Itajaí/SC',
                'facts' => $facts,
            ],
            requirements: [
                new Requirement([
                    'description' => 'A condenação da Ré à restituição do valor pago pelo serviço de mudança;',
                    'amount' => '3200.00',
                ]),
                new Requirement([
                    'description' => 'A condenação da Ré à indenização pelos danos morais decorrentes do extravio definitivo dos bens da genitora da Autora, em valor a ser arbitrado por este Juízo;',
                    'amount' => null,
                ]),
            ],
        );

        $refined = RefineLegalCaseFacts::run($legalCase);

        $this->show($facts, $refined);

        // A perda de bens insubstituíveis é o caso em que o texto frio seria o
        // erro. O agente declara aqui o fato do relato que autorizou o peso.
        $this->assertNotNull($refined->impactBasis);

        // "Aumentar" é dizer o fato por inteiro, e isso ocupa mais linhas do
        // que o relato do cliente.
        $this->assertGreaterThan(mb_strlen($facts), mb_strlen($refined->facts));

        // E é o fato narrado que dá o peso, não o adjetivo: o que precisa estar
        // no texto são as coisas que a cliente escolheu contar, com a cifra que
        // ela mesma escreveu.
        $this->assertStringContainsString('aliança', mb_strtolower($refined->facts));
        $this->assertStringContainsString('cartas', mb_strtolower($refined->facts));
        $this->assertStringContainsString('3.200,00', $refined->facts);

        // O outro lado da mesma regra, e o motivo de este teste existir: dor se
        // narra, diagnóstico se prova. Se esta asserção ficar vermelha, o lugar
        // de olhar é o prompt, não o relato.
        $this->assertStringNotContainsStringIgnoringCase('depress', $refined->facts);
        $this->assertStringNotContainsStringIgnoringCase('indescritível', $refined->facts);

        // E o peso não pode custar a pessoa do verbo: um relato emocionado é
        // justamente onde o "eu" do cliente tende a sobreviver à reescrita.
        $this->assertThirdPerson($refined);
    }

    /**
     * A pleading that exists only in memory.
     *
     * The four relations are set rather than loaded, which is what keeps this
     * test off the database: `loadMissing()` in the Action finds all of them
     * present and queries nothing. It is also the smallest faithful stand-in —
     * the dossier reads relations and columns, and never a query of its own.
     *
     * @param  array<string, mixed>  $attributes  the `legal_cases` columns, defendant included
     * @param  list<Requirement>  $requirements
     */
    private function pleading(
        PracticeArea $area,
        ProceduralClass $class,
        Customer $customer,
        array $attributes,
        array $requirements,
    ): LegalCase {
        $legalCase = new LegalCase($attributes);

        $legalCase->setRelation('practiceArea', $area);
        $legalCase->setRelation('proceduralClass', $class);
        $legalCase->setRelation('customer', $customer);
        $legalCase->setRelation('requirements', new Collection($requirements));

        return $legalCase;
    }

    /**
     * The narrative talks *about* the client, never *as* them.
     *
     * The pronouns are the visible half of the rewrite the lawyer asked for: a
     * paragraph that still says "eu paguei" is the client's text with better
     * spelling, not the narrative a pleading opens with. The grammatical gender
     * is not pinned — a Ltda called "o Autor" is one word to fix, and the
     * prompt is what asks for "a Autora".
     */
    private function assertThirdPerson(RefinedFactsData $refined): void
    {
        $this->assertMatchesRegularExpression('/\bAutora?\b/u', $refined->facts);

        $this->assertDoesNotMatchRegularExpression(
            '/\b(eu|meu|minha|meus|minhas|nós|nosso|nossa)\b/iu',
            $refined->facts,
            'A narrativa reescrita continua na primeira pessoa do relato.',
        );
    }

    /**
     * The point of an agent test is to read the answer, and PHPUnit swallows
     * stdout — STDERR is what actually reaches the terminal.
     */
    private function show(string $facts, RefinedFactsData $refined): void
    {
        fwrite(STDERR, PHP_EOL.json_encode([
            'relato' => $facts,
            'resposta' => $refined->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);
    }
}
