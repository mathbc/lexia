<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Requirements\Data\RequirementListData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two dialects a request's amount arrives in, and the one it leaves in.
 *
 * No database and no inference: this is a pure function over strings, and it is
 * the place where a mistake would be invisible. A misread separator does not
 * throw and does not look wrong — it claims R$ 252,00 where the client wrote
 * R$ 25.200,00, on a document that goes to a judge.
 *
 * `fromArray()` reads the screen, where the field builds the number from the
 * right and every digit is a cent. `fromAgent()` reads a model, which was asked
 * for "25200.00" and may still answer in Brazilian notation — so the separator
 * is found rather than assumed. The two are tested together because what they
 * must agree on is the output.
 *
 * `fromAgent()` also takes the narrative, and refuses any figure that is not
 * written in it. That guard has its own tests at the bottom: it is the answer
 * to a model that composes a plausible number, which no prompt held reliably
 * and no reader can catch afterwards.
 */
final class RequirementListDataTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function amounts(): array
    {
        return [
            'o decimal que as instruções pedem' => ['25200.00', '25200.00'],
            'notação brasileira completa' => ['25.200,00', '25200.00'],
            'com o cifrão que o modelo escreveu junto' => ['R$ 1.500,50', '1500.50'],
            'sem casas decimais' => ['42000', '42000.00'],
            // "1.500" tem três dígitos depois do ponto: é milhar, não decimal.
            // A notação brasileira sempre agrupa de três em três, e é isso que
            // separa este caso do de baixo.
            'milhar sem centavos' => ['1.500', '1500.00'],
            'uma casa decimal só' => ['1,5', '1.50'],
            'centavos sem a unidade' => [',50', '0.50'],
            'zeros à esquerda' => ['007.00', '7.00'],
            // Pedido sem cifra é o caso comum, e as instruções mandam devolver
            // nulo — mas um modelo pequeno inventa um zero de vez em quando, e
            // R$ 0,00 num pedido seria lido como pedido de zero.
            'zero não é valor' => ['0', null],
            'zero com centavos também não' => ['0,00', null],
            'texto que não é número' => ['a apurar', null],
            'vazio' => ['', null],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function it_reads_the_amount_a_model_wrote_in_whichever_notation(
        string $written,
        ?string $expected,
    ): void {
        $list = RequirementListData::fromAgent(
            [
                'requirements' => [
                    ['description' => 'A condenação do Réu ao pagamento da quantia;', 'amount' => $written],
                ],
            ],
            // O relato repete a cifra nas duas notações, para que a guarda
            // nunca dispare: o que esta função mede é a leitura da notação, e
            // a guarda tem os seus próprios testes logo abaixo.
            "O prejuízo foi de {$written}, ou seja {$expected} reais.",
        );

        $this->assertCount(1, $list->requirements);
        $this->assertSame($expected, $list->requirements[0]->amount);
    }

    /**
     * A guarda: uma cifra que o relato não escreve não passa.
     *
     * É o erro que o prompt não segurou — o modelo devolveu R$ 5.000,00 e
     * R$ 12.000,00 para um caso que não nomeia nenhum dos dois, compondo o que
     * um pedido daquele tipo costuma custar. O pedido continua; o que cai é a
     * cifra, e o advogado preenche um campo vazio em vez de conferir um número
     * seguro de si.
     */
    #[Test]
    public function it_refuses_an_amount_the_narrative_does_not_contain(): void
    {
        $list = RequirementListData::fromAgent(
            [
                'requirements' => [
                    ['description' => 'A restituição do valor pago;', 'amount' => '25200.00'],
                    ['description' => 'A indenização por danos morais;', 'amount' => '5000.00'],
                ],
            ],
            'Paguei R$ 25.200,00 pelos armários e até hoje não instalaram nada.',
        );

        $this->assertSame('25200.00', $list->requirements[0]->amount);

        // A frase fica inteira: o que se perde é a cifra, não o pedido.
        $this->assertNull($list->requirements[1]->amount);
        $this->assertSame('A indenização por danos morais;', $list->requirements[1]->description);
    }

    /**
     * A guarda é generosa de propósito: qualquer número do texto autoriza,
     * seja ele prazo, data ou número de porta.
     *
     * Ela não decide se a cifra pertence àquele pedido — isso é juízo, e é do
     * advogado que revisa. Ela recusa o que veio do nada.
     */
    #[Test]
    public function any_figure_written_in_the_narrative_is_enough_to_authorise_one(): void
    {
        $list = RequirementListData::fromAgent(
            [
                'requirements' => [
                    ['description' => 'A condenação ao pagamento da multa contratual;', 'amount' => '45.00'],
                ],
            ],
            'A entrega estava prometida para 45 dias e não saiu.',
        );

        $this->assertSame('45.00', $list->requirements[0]->amount);
    }

    /**
     * O custo conhecido da guarda: dinheiro escrito por extenso não ancora
     * nada, e o pedido perde uma cifra a que tinha direito. A frase continua
     * dizendo quanto é, e é lá que o advogado a lê.
     */
    #[Test]
    public function money_the_narrative_spells_out_in_words_anchors_nothing(): void
    {
        $list = RequirementListData::fromAgent(
            [
                'requirements' => [
                    ['description' => 'A restituição de quinze mil reais;', 'amount' => '15000.00'],
                ],
            ],
            'Dei quinze mil reais de sinal e o negócio não saiu.',
        );

        $this->assertNull($list->requirements[0]->amount);
        $this->assertSame('A restituição de quinze mil reais;', $list->requirements[0]->description);
    }

    /**
     * A tela fala outra língua, e ela não mudou: o campo empurra a vírgula da
     * direita para a esquerda, então "50000" é R$ 500,00 e não R$ 50.000,00.
     *
     * É o mesmo texto que `fromAgent()` leria como quarenta e dois mil, e é
     * por isso que os dois construtores existem separados.
     */
    #[Test]
    public function it_still_reads_the_screen_as_a_mask_of_cents(): void
    {
        $list = RequirementListData::fromArray([
            'requirements' => [
                ['id' => null, 'description' => 'A condenação do Réu;', 'amount' => '50.000,00'],
                ['id' => null, 'description' => 'A restituição;', 'amount' => '50000'],
            ],
        ]);

        $this->assertSame('50000.00', $list->requirements[0]->amount);
        $this->assertSame('500.00', $list->requirements[1]->amount);
    }

    /**
     * A gramática obriga a chave `description` a existir, mas não a ter texto.
     * Um pedido sem frase não é pedido, venha de onde vier.
     */
    #[Test]
    public function it_drops_the_requests_that_have_no_sentence(): void
    {
        $list = RequirementListData::fromAgent([
            'requirements' => [
                ['description' => '   ', 'amount' => '100.00'],
                ['description' => 'A citação do Réu;', 'amount' => null],
                ['description' => '', 'amount' => null],
            ],
        ], 'O réu deve R$ 100,00 e não paga.');

        $this->assertCount(1, $list->requirements);
        $this->assertSame('A citação do Réu;', $list->requirements[0]->description);

        // Reindexada: a lista vai para o JSON, e buracos nas chaves a
        // transformariam num objeto do lado do navegador.
        $this->assertSame([0], array_keys($list->requirements));
    }

    /**
     * O relato que não pede nada, que é resposta legítima e não falha.
     */
    #[Test]
    public function an_answer_with_no_requests_is_an_empty_list(): void
    {
        $facts = 'Preciso saber como agir com as filmagens da câmera de segurança.';

        $this->assertSame([], RequirementListData::fromAgent(['requirements' => []], $facts)->requirements);
        $this->assertSame([], RequirementListData::fromAgent([], $facts)->toArray());
    }

    /**
     * O id não vem do agente: uma sugestão ainda não é linha, e a chave de uma
     * linha nova é cunhada pelo `HasUuids` — nunca colada de um payload.
     */
    #[Test]
    public function a_suggested_request_carries_no_id(): void
    {
        $list = RequirementListData::fromAgent([
            'requirements' => [
                ['id' => 'algo-que-o-modelo-inventou', 'description' => 'A citação do Réu;', 'amount' => null],
            ],
        ], 'O réu não foi encontrado para a notificação.');

        $this->assertNull($list->requirements[0]->id);
        $this->assertSame(
            [['description' => 'A citação do Réu;', 'amount' => null]],
            $list->toArray(),
        );
    }
}
