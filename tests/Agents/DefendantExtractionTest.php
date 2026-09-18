<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Actions\ExtractLegalCaseDefendant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The defendant agent against a real Ollama, no fakes.
 *
 * No RefreshDatabase, unlike its sibling: this agent reads nothing from the
 * catalogue and writes nothing back. What it takes is a narrative and what it
 * returns is a DefendantData, so the test is one inference and no schema.
 *
 * And, unlike its sibling, the assertions are firm. Pinning *which* procedural
 * class a model chooses would turn ordinary variation into a red build, because
 * choosing is a judgement; copying a CNPJ that is written in the narrative is
 * not. If the CNPJ below comes back wrong, the agent is wrong.
 *
 * The narrative is built around the failure that matters. The author's own CPF,
 * telephone, CEP and address are all in the text — usually the *first* ones in
 * the text — and none of them may reach a single field. Every assertion on an
 * exact value is therefore also an assertion that the other party's data did
 * not win.
 *
 * Grouped out of the default run for the same reason as the classification
 * test: it needs Ollama up and spends seconds on inference.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class DefendantExtractionTest extends TestCase
{
    #[Test]
    public function it_extracts_the_defendant_from_a_narrative_of_facts(): void
    {
        $facts = <<<'TXT'
        Doutor, meu nome é Ricardo Amaral, CPF 123.456.789-09, moro na Rua das Palmeiras, 77, apartamento 402,
        bairro Velha, em Blumenau/SC, CEP 89040-200, e meu celular é (47) 99988-7766.
        Em 03/02/2026 contratei a Móveis Bertoldi Indústria e Comércio Ltda, CNPJ 11.222.333/0001-81, para fabricar
        e instalar os armários planejados do meu apartamento, pelo valor de R$ 42.000,00 e com entrega prometida
        em 45 dias. Paguei 60% à vista, na assinatura do contrato.
        A empresa fica na Rua Itajaí, 1482, sala 3, bairro Bela Vista, em Gaspar/SC, CEP 89110-000. O telefone da
        loja é (47) 3322-1188 e o e-mail deles é contato@moveisbertoldi.com.br.
        Passados sete meses, nada foi instalado. Liguei dezenas de vezes e sempre fui atendido pelo gerente, o
        Sr. Juliano, que remarcava a entrega e nunca cumpria. Em julho mandei uma notificação extrajudicial e não
        obtive resposta. Quero o dinheiro de volta e uma indenização, porque desmontei a cozinha antiga contando
        com a instalação.
        TXT;

        // Os três relatos abaixo trazem cada vez menos sobre o réu, e é para
        // isso que eles servem: trocar o `$facts` acima por um deles é o jeito
        // de ver, no dump abaixo, se o agente devolve nulo onde não sabe em vez
        // de completar. As asserções de valor desta função descrevem o relato
        // completo acima — ao trocar, o que se lê é o dump.
        //
        // 1. Pessoa física, endereço parcial: sem documento, sem telefone, sem
        //    e-mail e sem CEP. `defendant_number` não existe no texto.
        //
        // $facts = <<<'TXT'
        // Meu vizinho de cima, o Sr. Hélio Brandão, do apartamento 802 do Edifício Solar das Águas, na Rua
        // Coronel Bento, bairro Água Verde, aqui em Curitiba/PR, começou uma reforma há quatro meses sem
        // autorização do condomínio. Quebrou parte da viga da varanda e agora apareceram rachaduras na parede
        // do meu banheiro. Já reclamei com o síndico duas vezes e ele diz que não pode fazer nada. Não tenho o
        // telefone nem o CPF dele.
        // TXT;
        //
        // 2. Empresa, só o nome comercial: nenhum endereço é o da ré — o da obra
        //    é o local do fato, e o condomínio é onde mora o autor.
        //
        // $facts = <<<'TXT'
        // Em frente ao condomínio onde eu moro, existe uma obra da construtora Marinho, eles iniciam as obras
        // todos os dias às 6 da manhã e terminam às 18h. Porém a obra continua aos finais de semana e não para
        // nem no domingo, causando barulho e poluição sonora. O condomínio não faz nada para resolver o
        // problema, e nem mesmo reconhece que é um problema. Além de dificultarem o trânsito por conta do
        // tráfego de caminhões, ergueram alguns carros de moradores e visitantes, sem autorização.
        // TXT;
        //
        // 3. Réu não identificado: todos os campos nulos, e tudo o que se sabe
        //    dele cabe em `defendant_notes`. A placa não é documento.
        //
        // $facts = <<<'TXT'
        // No dia 12/09/2026, por volta das 21h, eu estava em casa assistindo TV quando ouvi um barulho de batida
        // muito forte. Ao sair para verificar, notei que um homem havia batido no meu portão, quebrando o motor
        // elétrico e entortando a folha de alumínio. Tentei conversar, ele se recusou a se identificar, agiu de
        // forma agressiva e fugiu. Estava com sinais de embriaguez. Consegui anotar a placa, YTD123, de um
        // Chevrolet Onix prata.
        // TXT;

        $defendant = ExtractLegalCaseDefendant::run($facts);

        // The point of this test is to look at the answer, and PHPUnit swallows
        // stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $defendant->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);

        // The payload is the contract: the twelve `defendant_*` columns of
        // `legal_cases`, spelled as the table spells them. A screen reads these
        // keys and UpdateLegalCaseDefendant writes them, so a rename in the
        // agent's schema has to fail here rather than leave a column unfilled.
        $this->assertSame([
            'defendant_name',
            'defendant_document',
            'defendant_email',
            'defendant_phone',
            'defendant_postal_code',
            'defendant_street',
            'defendant_number',
            'defendant_complement',
            'defendant_district',
            'defendant_city',
            'defendant_state',
            'defendant_notes',
        ], array_keys($defendant->toArray()));

        // Masks are stripped on the way in, whatever the model emitted, because
        // the columns hold one representation.
        $this->assertMatchesRegularExpression('/^\d{14}$/', (string) $defendant->document);
        $this->assertMatchesRegularExpression('/^\d{10,11}$/', (string) $defendant->phone);
        $this->assertMatchesRegularExpression('/^\d{8}$/', (string) $defendant->postalCode);

        // Copied, not decided — and every one of these has a decoy earlier in
        // the narrative, belonging to the author.
        $this->assertSame('11222333000181', $defendant->document);
        $this->assertSame('4733221188', $defendant->phone);
        $this->assertSame('89110000', $defendant->postalCode);
        $this->assertSame('contato@moveisbertoldi.com.br', $defendant->email);
        $this->assertSame('Gaspar', $defendant->city);
        $this->assertSame(BrazilianState::SC, $defendant->state);

        // The corporate name varies in how much of it the model carries over,
        // so the assertion is on what cannot vary.
        $this->assertStringContainsString('Bertoldi', (string) $defendant->name);

        // The author is not the defendant, and this is the sentence that says
        // so: nothing of Ricardo Amaral belongs in any field.
        $this->assertStringNotContainsString('Ricardo', (string) $defendant->name);
        $this->assertStringNotContainsString('Palmeiras', (string) $defendant->street);
        $this->assertNotSame('Blumenau', $defendant->city);
    }
}
