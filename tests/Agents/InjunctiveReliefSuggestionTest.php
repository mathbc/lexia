<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\LegalCases\Actions\SuggestInjunctiveRelief;
use App\Domain\LegalCases\Enums\InjunctiveReliefKind;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The urgent-relief agent against the real text provider, no fakes.
 *
 * RefreshDatabase only for the catalogue: the area and the class are rows the
 * catalogue migration loads, and the class travels with its description and
 * legal bases. Nothing is written.
 *
 * Three narratives, one per answer that matters, and the assertions on *which*
 * answer are firm on purpose. Unlike choosing a procedural class, these three
 * are not close calls: a negativation days before a mortgage is signed is the
 * textbook anticipatory relief, a loan between neighbours with no sign of
 * insolvency is the textbook refusal, and a defendant selling everything after
 * the damage is the textbook precautionary one. An agent that misses any of
 * them is wrong, not varying.
 *
 * What is held loosely is the wording. What is held firmly besides the answer
 * is what the prompt forbids: no name from the narrative in the text the
 * lawyer is offered, no súmula or tema cited from memory, and no figure the
 * narrative does not write — the daily fine is a bracket.
 *
 * Grouped out of the default run for the same reason as its siblings: it
 * spends inference.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class InjunctiveReliefSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int, string, bool, InjunctiveReliefKind|null}>
     */
    public static function narratives(): array
    {
        return [
            'a negativação às vésperas do financiamento' => [
                'consumidor',
                7,
                <<<'TXT'
                Meu nome é Carla Mendes. Meu nome foi negativado pela operadora Linha Certa por uma linha que eu
                nunca contratei, no valor de R$ 1.340,00. Já liguei quatro vezes e mandei dois e-mails com cópia do
                meu RG, e eles só dizem que vão apurar. Tenho os protocolos e o print da consulta no Serasa. Na
                semana que vem tenho a assinatura do financiamento do apartamento e o banco já avisou que com o
                nome sujo não sai. Perco o imóvel e o sinal de R$ 15.000,00 que já paguei se isso não for
                resolvido em dias.
                TXT,
                true,
                InjunctiveReliefKind::Anticipatory,
            ],
            'o empréstimo entre vizinhos' => [
                'civil',
                7,
                <<<'TXT'
                Emprestei R$ 3.000,00 para o meu vizinho, o Sr. Anselmo, em janeiro, para ele consertar o carro.
                Ele prometeu devolver em três meses e não devolveu. Ele continua morando ao lado, trabalha
                normalmente e diz que vai pagar quando puder. Tenho as mensagens de WhatsApp em que ele admite a
                dívida. Quero receber o que emprestei.
                TXT,
                false,
                null,
            ],
            'o réu que vende tudo depois do dano' => [
                'civil',
                7,
                <<<'TXT'
                O caminhão da transportadora do Sr. Valdir Rocha destruiu a fachada da minha loja em agosto. O
                orçamento do conserto ficou em R$ 80.000,00 e ele se recusou a pagar. Desde então ele colocou à
                venda os dois caminhões que restam da empresa e o galpão, que estão anunciados num site de
                classificados — tenho os prints dos anúncios —, e os funcionários dizem que ele vai fechar tudo e
                se mudar para o Paraguai no mês que vem. Se ele vender tudo, não vou ter de quem cobrar.
                TXT,
                true,
                InjunctiveReliefKind::Precautionary,
            ],
        ];
    }

    #[Test]
    #[DataProvider('narratives')]
    public function it_weighs_urgency_the_way_the_courts_do(
        string $areaSlug,
        int $classCode,
        string $facts,
        bool $recommended,
        ?InjunctiveReliefKind $kind,
    ): void {
        $suggestion = SuggestInjunctiveRelief::run(
            PracticeArea::query()->where('slug', $areaSlug)->sole(),
            ProceduralClass::query()->where('code', $classCode)->sole(),
            $facts,
        );

        // The point of this test is also to look at the answer, and PHPUnit
        // swallows stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $suggestion->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);

        $this->assertSame($recommended, $suggestion->recommended);
        $this->assertNotSame('', $suggestion->justification);

        if (! $recommended) {
            $this->assertNull($suggestion->description);

            return;
        }

        $this->assertSame($kind, $suggestion->kind);

        $description = (string) $suggestion->description;

        $this->assertStringStartsWith('Medida pretendida:', $description);
        $this->assertStringContainsString('Perigo de dano:', $description);

        // O que o prompt proíbe, e o que a peça pagaria caro se passasse.
        foreach (['Carla', 'Mendes', 'Valdir', 'Rocha', 'Linha Certa'] as $name) {
            $this->assertStringNotContainsString($name, $description);
        }

        $this->assertDoesNotMatchRegularExpression('/s[úu]mula|\btema\b/iu', $description);
        $this->assertSame([], $suggestion->unsupportedAmounts);
    }
}
