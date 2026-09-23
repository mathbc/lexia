<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Ai\Agents\PleadingDraftingAgent;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Accounts\Models\Account;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Actions\DraftLegalPleading;
use App\Domain\LegalCases\Data\PleadingDraftData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalPleadings\Support\PleadingBlocks;
use App\Domain\LegalPleadings\Support\PleadingJurisprudence;
use App\Domain\LegalPleadings\Support\PleadingSignature;
use App\Domain\LegalTheses\Models\LegalThesis;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Models\Requirement;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The agent that writes the petição inicial.
 *
 * What is pinned follows the rule the sibling tests set: wording is judgement,
 * and pinning it would turn ordinary variation into a red build. What is pinned
 * is **copying** (the client's name, the CPF, the figures the requests claim),
 * **structure** (the sections that must exist and the order they come in), and
 * the inventions the instructions forbid outright.
 *
 * The forbidden inventions are three, and each is a different kind of failure:
 *
 * 1. **A qualification that was not given.** Marital status and occupation are
 *    obligatory in the opening paragraph and absent from `customers`, so they
 *    are the single most likely invention in this project — grammatically
 *    required, utterly ordinary, and wrong about a real person in a document
 *    filed under a lawyer's number. The right answer is a bracket.
 * 2. **A ruling the dossier does not carry.** A model that has read a thousand
 *    petições opens a "Jurisprudência:" block out of sheer form. The rulings the
 *    lawyer kept are in the dossier under markers, and the agent's whole part
 *    in them is placing each marker once, alone in a paragraph, inside DO
 *    DIREITO — the ementa itself is PHP's to write, so any case law in the
 *    agent's own prose was invented.
 * 3. **A total.** Five numbered requests with figures invite a closing line that
 *    adds them up, which is arithmetic rather than anything anyone claimed.
 *
 * The signature is asserted **absent** from the agent's answer, because it is
 * composed in PHP: an OAB number the model wrote is the one thing this document
 * cannot afford.
 *
 * Not measured against any model yet. Run it, read what comes out of `show()`,
 * and rewrite these notes with what the configured model actually does.
 */
#[Group('agents')]
final class PleadingDraftingTest extends TestCase
{
    #[Test]
    public function it_writes_a_pleading_marking_what_the_record_does_not_say(): void
    {
        $facts = <<<'TXT'
        Doutor, no dia 10 de setembro de 2026, por volta das 21h, eu tava em casa quando
        ouvi um estrondo muito forte. Era um carro que bateu no meu muro. O motorista, o
        Pedro Henrique, tava visivelmente bêbado, me xingou e depois fugiu numa moto que
        um amigo dele trouxe. O muro caiu, o portão entortou e o motor do portão queimou.
        Pior de tudo é que o meu cachorro tava no quintal e morreu na hora. A polícia veio
        e fez o boletim. As minhas vizinhas viram tudo. Os orçamentos que peguei pro
        conserto deram R$ 18.400,00.
        TXT;

        $draft = $this->draft($facts);

        $this->show($facts, $draft);

        // O que é cópia é pinado.
        $this->assertStringContainsStringIgnoringCase('Matheus Araújo', $draft->content);
        // Em caixa alta, que é como as instruções mandam nomear as partes.
        $this->assertStringContainsStringIgnoringCase('Pedro Henrique', $draft->content);
        $this->assertStringContainsString('18.400,00', $draft->content);

        // O CPF é copiado como uma peça o escreve, e não como a coluna o guarda.
        $this->assertStringContainsString('115.863.259-22', $draft->content);

        // A estrutura de uma inicial, e o fecho exato em que o agente para.
        $this->assertMatchesRegularExpression('/DOS FATOS/u', $draft->content);
        $this->assertMatchesRegularExpression('/DO DIREITO/u', $draft->content);
        $this->assertMatchesRegularExpression('/DOS PEDIDOS/u', $draft->content);
        $this->assertStringContainsString('Nestes termos, pede deferimento', $draft->content);

        // A lacuna é a resposta certa: este cliente não tem estado civil nem
        // profissão registrados, e a qualificação os exige.
        $this->assertNotEmpty($draft->placeholders);

        // Nenhuma cifra que o relato e os pedidos não escrevam.
        $this->assertSame([], $draft->unsupportedAmounts);

        $this->assertNoInventedQualification($draft);
        $this->assertNoCaseLaw($draft);
        $this->assertNoSignature($draft);
    }

    /**
     * Os pedidos são copiados, não reescritos, e nenhum é somado.
     *
     * É o caso em que a conta é mais tentadora: três cifras numeradas numa lista
     * e um "valor da causa" logo abaixo delas.
     */
    #[Test]
    public function it_numbers_the_requests_without_totalling_them(): void
    {
        $facts = <<<'TXT'
        A empresa deixou de pagar as mensalidades do contrato de manutenção desde março.
        São cinco parcelas de R$ 4.800,00 cada uma. Também tivemos que contratar outro
        prestador às pressas, o que custou R$ 6.200,00.
        TXT;

        $draft = $this->draft($facts, [
            new Requirement(['description' => 'A concessão da Gratuidade da Justiça;', 'amount' => null]),
            new Requirement(['description' => 'A condenação da Ré ao pagamento das mensalidades vencidas;', 'amount' => '24000.00']),
            new Requirement(['description' => 'A condenação da Ré ao ressarcimento da contratação emergencial;', 'amount' => '6200.00']),
        ], new LegalThesis([
            'name' => 'Do Inadimplemento Contratual e do Dever de Indenizar',
            'description' => 'O descumprimento da obrigação de pagar as mensalidades pactuadas constitui a Ré em mora e a obriga a responder pelas perdas e danos dela decorrentes.',
            'impact' => 'Alcança as parcelas vencidas e a despesa da contratação emergencial.',
            'legal_bases' => [
                ['type' => 'article', 'reference' => 'Art. 389 do Código Civil', 'source' => 'CC'],
                ['type' => 'article', 'reference' => 'Art. 395 do Código Civil', 'source' => 'CC'],
            ],
        ]));

        $this->show($facts, $draft);

        // A soma das duas cifras é R$ 30.200,00, e ela não pode estar em lugar
        // nenhum. A guarda é a asserção, e não a palavra: numa rodada o agente
        // escreveu "totalizando cinco parcelas" — contando parcelas, não somando
        // dinheiro — e proibir o conectivo no teste reprovaria português correto.
        // Quem sabe a diferença é `unsupportedAmounts`, que compara cifras.
        $this->assertSame([], $draft->unsupportedAmounts);
        $this->assertStringNotContainsString('30.200,00', $draft->content);

        $this->assertStringContainsString('Gratuidade da Justiça', $draft->content);
        $this->assertStringContainsString('24.000,00', $draft->content);

        $this->assertNoCaseLaw($draft);
    }

    /**
     * Os julgados que o advogado manteve entram pelo marcador, e só eles.
     *
     * Dois acórdãos do STJ sobre acidente de trânsito, que é o caso do relato. O
     * que se pina é o que é estrutura: cada marcador uma vez, sozinho na linha,
     * dentro de DO DIREITO — e, depois da expansão, cada ementa uma vez, como
     * citação recuada. Em que tese cada um entra, e com que frase, é juízo, e
     * fica para quem lê o `show()`.
     */
    #[Test]
    public function it_places_each_kept_ruling_once_inside_the_argument(): void
    {
        $facts = <<<'TXT'
        Doutor, no dia 10 de setembro de 2026, por volta das 21h, um carro bateu no meu
        muro. O motorista, o Pedro Henrique, tava visivelmente bêbado e fugiu. O carro
        era do pai dele. O muro caiu e o portão entortou. Os orçamentos que peguei pro
        conserto deram R$ 18.400,00.
        TXT;

        $decisions = new Collection([
            new CourtDecision([
                'title' => 'AgInt no REsp 2091428 / MA',
                'locality' => 'Brasil',
                'authority' => 'Superior Tribunal de Justiça. 3ª Turma',
                'summary' => 'PROCESSUAL CIVIL. AGRAVO INTERNO NO RECURSO ESPECIAL. AÇÃO DE INDENIZAÇÃO POR DANOS MORAIS. ACIDENTE DE TRÂNSITO. MORTE DE VÍTIMA. JUIZO DE ORIGEM QUE CONCLUIU PELA EXISTÊNCIA DE CONDUTA, DANO, NEXO DE CAUSALIDADE E CULPA. DIREÇÃO PERIGOSA. PROPRIETÁRIO DO VEÍCULO. RESPONSABILIDADE SOLIDÁRIA. JULGADOS DESTA CORTE. 1. Ação de indenização por danos morais em razão de acidente de trânsito que causou a morte da vítima. 2. A absolvição no juízo criminal, diante da relativa independência entre as instâncias cível e criminal, apenas vincula o juízo cível quando for reconhecida a inexistência do fato ou ficar demonstrado que o demandado não foi seu autor. Julgados desta Corte. 3. O proprietário do veículo responde solidariamente pelos danos decorrentes de acidente de trânsito causado por culpa do condutor. Julgados. 4. Agravo interno não provido. Decis?o Vistos e relatados estes autos em que são partes as acima indicadas, acordam os Ministros da TERCEIRA TURMA do Superior Tribunal de Justiça, por unanimidade, negar provimento ao recurso.',
                'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.3:acordao;resp:2023-11-13;2091428-2368856',
                'decided_at' => '2023-11-13',
            ]),
            new CourtDecision([
                'title' => 'REsp 1106086 / MA',
                'locality' => 'Brasil',
                'authority' => 'Superior Tribunal de Justiça. 1ª Turma',
                'summary' => 'ADMINISTRATIVO. PROCESSUAL CIVIL. RECURSO ESPECIAL. RESPONSABILIDADE CIVIL DO ESTADO. AÇÃO DE INDENIZAÇÃO. ACIDENTE DE TRÂNSITO CAUSADO POR SERVIDOR DA POLÍCIA MILITAR. DANOS MATERIAIS. AÇÃO AJUIZADA PELO PROPRIETÁRIO DO VEÍCULO LESIONADO. LEGITIMIDADE ATIVA DO CONDUTOR OU DO PROPRIETÁRIO. 1. Hipótese em que se alega ilegitimidade ativa do recorrido, por não ser o condutor do veículo lesionado no momento do acidente. 2. Na ação de indenização por danos materiais decorrentes de acidente de trânsito, é legitimada ativamente a pessoa que suportou o prejuízo com a reparação do dano. 3. Recurso especial não provido.',
                'source_url' => 'https://www.lexml.gov.br/urn/urn:lex:br:superior.tribunal.justica;turma.1:acordao;resp:2009-10-01;1106086',
                'decided_at' => '2009-10-01',
            ]),
        ]);

        $draft = $this->draft($facts, decisions: $decisions);
        $document = PleadingJurisprudence::expand($draft->content, $decisions);

        $this->show($facts, $draft, $document);

        // Um marcador que sobrou é um que o agente escreveu no meio de uma
        // frase, ou um que não existe: a expansão não o alcança.
        $this->assertStringNotContainsString('[[', $document);

        // Cada ementa uma vez, dentro de DO DIREITO. É a asserção que pega o
        // julgado esquecido (zero) e o repetido (dois), sem pinar a grafia do
        // marcador, que a expansão já tolera.
        $law = mb_strpos($document, 'DO DIREITO');
        $requests = mb_strpos($document, 'DOS PEDIDOS');
        $this->assertNotFalse($law);
        $this->assertNotFalse($requests);

        foreach ($decisions as $decision) {
            $ementa = PleadingJurisprudence::ementa($decision->summary);

            $this->assertSame(1, mb_substr_count($document, $ementa));
            $this->assertGreaterThan($law, mb_strpos($document, $ementa));
            $this->assertLessThan($requests, mb_strpos($document, $ementa));
        }

        $this->assertCount(4, array_filter(
            PleadingBlocks::of($document),
            static fn (array $block): bool => $block['citation'],
        ));

        // A prosa do agente não transcreve nem inventa: todo julgado da peça é
        // um dos dois, e nenhum é chamado do que um acórdão de turma não é.
        $this->assertStringNotContainsStringIgnoringCase('vinculante', $draft->content);
        $this->assertStringNotContainsStringIgnoringCase('súmula', $draft->content);
        $this->assertDoesNotMatchRegularExpression('/^\s*Jurisprudência:?\s*$/mu', $draft->content);
        $this->assertDoesNotMatchRegularExpression('/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/u', $draft->content);

        $this->assertSame([], $draft->unsupportedAmounts);
        $this->assertNoInventedQualification($draft);
        $this->assertNoSignature($draft);
    }

    /**
     * A qualificação não é inventada.
     *
     * Os estados civis e as profissões mais prováveis são nomeados um a um em
     * vez de procurados por regra: o que se quer saber é se o modelo preencheu a
     * lacuna com o palpite óbvio, e o palpite óbvio é curto e conhecido.
     */
    private function assertNoInventedQualification(PleadingDraftData $draft): void
    {
        foreach (['casado', 'casada', 'solteiro', 'solteira', 'divorciado', 'viúvo', 'comerciante', 'autônomo', 'empresário'] as $guess) {
            $this->assertStringNotContainsStringIgnoringCase(
                $guess,
                $draft->content,
                "A qualificação foi preenchida com um palpite: \"{$guess}\".",
            );
        }
    }

    /**
     * Nenhum julgado, e nenhuma seção que peça um.
     *
     * Para os casos em que o dossiê não traz julgado nenhum — a seção diz
     * "Nada registrado" —, então tudo o que aparecesse aqui teria sido
     * inventado, com número de processo verossímil e inexistente.
     */
    private function assertNoCaseLaw(PleadingDraftData $draft): void
    {
        $this->assertStringNotContainsStringIgnoringCase('jurisprudência', $draft->content);
        $this->assertStringNotContainsStringIgnoringCase('apelação cível', $draft->content);
        $this->assertStringNotContainsStringIgnoringCase('súmula', $draft->content);

        // Um número de processo do CNJ, que é a forma que uma citação inventada
        // costuma tomar.
        $this->assertDoesNotMatchRegularExpression(
            '/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/u',
            $draft->content,
            'A peça cita um número de processo que ninguém forneceu.',
        );
    }

    /**
     * O agente para antes de assinar.
     *
     * Cidade, data, nome e OAB são acrescentados por PleadingSignature, em PHP.
     * Um número de OAB escrito por um modelo é a coisa que este documento não
     * pode comprar.
     */
    private function assertNoSignature(PleadingDraftData $draft): void
    {
        $this->assertDoesNotMatchRegularExpression('/OAB\/[A-Z]{2}/u', $draft->content);
    }

    /**
     * A resposta do agente, sem gravar nada.
     *
     * Chama o agente pelo mesmo dossiê que a Action monta, mas não passa por
     * `DraftLegalPleading::run()`: aquela grava uma linha, e o que se lê aqui é
     * o que o modelo escreveu — antes da assinatura, que é o que
     * `assertNoSignature` consegue afirmar.
     *
     * @param  list<Requirement>|null  $requirements
     * @param  Collection<int, CourtDecision>|null  $decisions
     */
    private function draft(
        string $facts,
        ?array $requirements = null,
        ?LegalThesis $thesis = null,
        ?Collection $decisions = null,
    ): PleadingDraftData {
        $legalCase = $this->pleading($facts, $requirements, $thesis, $decisions);

        $response = (new PleadingDraftingAgent(
            dossier: LegalCaseDossier::forDrafting($legalCase),
        ))->prompt($facts);

        $this->assertInstanceOf(StructuredAgentResponse::class, $response);

        // As mesmas fontes que DraftLegalPleading::sources() junta: o relato, as
        // cifras dos pedidos e as ementas que a peça vai citar.
        $quoted = $legalCase->courtDecisions
            ->map(static fn (CourtDecision $decision): string => PleadingJurisprudence::ementa($decision->summary))
            ->implode(' ');

        return PleadingDraftData::fromAgent($response->toArray(), $facts.' R$ 24000.00 R$ 6200.00 R$ 18400.00 '.$quoted);
    }

    /**
     * Uma peça que existe só em memória.
     *
     * As sete relações são penduradas à mão, o que mantém este teste fora do
     * banco: o `loadMissing()` da Action encontra todas presentes e não consulta
     * nada. O estado civil e a profissão do cliente ficam **deliberadamente em
     * branco**: `customers` tem as colunas desde que o cadastro passou a pedi-las,
     * mas as duas são opcionais, e é o cliente não qualificado que este teste
     * mede — é dele que sai a lacuna entre colchetes.
     *
     * @param  list<Requirement>|null  $requirements
     * @param  Collection<int, CourtDecision>|null  $decisions
     */
    private function pleading(
        string $facts,
        ?array $requirements = null,
        ?LegalThesis $thesis = null,
        ?Collection $decisions = null,
    ): LegalCase {
        $legalCase = new LegalCase([
            'facts' => $facts,
            'court_addressing' => 'Ao Juízo de Direito da Vara Cível da Comarca de Balneário Camboriú/SC',
            'defendant_name' => 'Pedro Henrique',
            'defendant_city' => 'Balneário Camboriú',
            'defendant_state' => BrazilianState::SC,
        ]);

        $legalCase->setRelation('account', new Account([
            'name' => 'Pedro & Associados Advocacia',
            'city' => 'Balneário Camboriú',
            'state' => BrazilianState::SC,
        ]));

        $legalCase->setRelation('practiceArea', new PracticeArea([
            'slug' => 'civil',
            'label' => 'Direito Civil',
        ]));

        $legalCase->setRelation('proceduralClass', new ProceduralClass([
            'code' => 7,
            'name' => 'Procedimento Comum Cível',
        ]));

        $legalCase->setRelation('customer', new Customer([
            'name' => 'Matheus Araújo',
            'type' => CustomerType::Individual,
            'cpf' => '11586325922',
            'city' => 'Balneário Camboriú',
            'state' => BrazilianState::SC,
        ]));

        $legalCase->setRelation('requirements', new Collection($requirements ?? [
            new Requirement(['description' => 'A concessão da Gratuidade da Justiça;', 'amount' => null]),
            new Requirement(['description' => 'A condenação do Réu ao pagamento dos danos materiais e morais;', 'amount' => '18400.00']),
        ]));

        $legalCase->setRelation('theses', new Collection([
            $thesis ?? new LegalThesis([
                'name' => 'Da Responsabilidade Civil por Ato Ilícito',
                'description' => 'A conduta culposa do Réu — conduzir veículo sob efeito de álcool e colidir contra propriedade alheia — gera o dever de indenizar os danos materiais e morais dela decorrentes.',
                'impact' => 'Assegura a reparação integral do prejuízo.',
                'legal_bases' => [
                    ['type' => 'article', 'reference' => 'Art. 186 do Código Civil', 'source' => 'CC'],
                    ['type' => 'article', 'reference' => 'Art. 927 do Código Civil', 'source' => 'CC'],
                ],
            ]),
        ]));

        $legalCase->setRelation('precedents', new Collection);
        $legalCase->setRelation('courtDecisions', $decisions ?? new Collection);
        $legalCase->setRelation('documents', new Collection);

        return $legalCase;
    }

    /**
     * The point of an agent test is to read the answer, and PHPUnit swallows
     * stdout — STDERR is what actually reaches the terminal.
     */
    private function show(string $facts, PleadingDraftData $draft, ?string $document = null): void
    {
        fwrite(STDERR, PHP_EOL.json_encode([
            'relato' => $facts,
            'minuta' => $document ?? $draft->content,
            // Lidas do documento expandido quando há um, como a tela as lê da
            // versão gravada: antes da expansão o marcador parece lacuna.
            'lacunas' => $document === null ? $draft->placeholders : PleadingDraftData::gapsIn($document),
            'cifras_sem_fonte' => $draft->unsupportedAmounts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);
    }
}
