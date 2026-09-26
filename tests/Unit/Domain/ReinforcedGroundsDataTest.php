<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Data\ReinforcedGroundsData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * As três leituras que decidem se o DO DIREITO reescrito substitui o original.
 *
 * Sem banco e sem inferência, pelo motivo de PleadingDraftDataTest: um modelo só
 * perde um marcador ou lembra uma súmula quando lhe dá vontade, e uma guarda que
 * tem de valer sempre não se verifica numa rodada que falha de vez em quando.
 *
 * Diferente da irmã, aqui a guarda **recusa**: há sempre a seção que o agente de
 * minuta escreveu para onde voltar.
 */
final class ReinforcedGroundsDataTest extends TestCase
{
    private const string ORIGINAL = "Da Responsabilidade Civil\n\nO Réu deve indenizar, nos termos do art. 186 do Código Civil.\n\nNesse sentido:\n\n[[JULGADO 1]]";

    private const string SOURCES = "## As teses da revisão forense\n\n- Tese: Da Responsabilidade Civil\n- Fundamentos a citar:\n  - Súmula 393 do STJ (Súmula — STJ)\n\n- Condenação ao pagamento (valor pedido: R$ 18.400,00)";

    #[Test]
    public function a_rewrite_that_keeps_the_markers_and_cites_only_the_sources_is_accepted(): void
    {
        $reinforced = $this->reinforced("Da Responsabilidade Civil\n\nO art. 186 do Código Civil exige conduta, dano e nexo, e a Súmula nº 393 do STJ confirma o cabimento. O prejuízo foi de R$ 18.400,00.\n\nNesse sentido:\n\n[[JULGADO 1]]");

        $this->assertTrue($reinforced->isAcceptable());
        $this->assertNull($reinforced->refusal());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusals(): array
    {
        return [
            'a resposta vazia' => [
                '   ',
                'vazia',
            ],
            // O julgado que o advogado escolheu nunca seria citado.
            'o marcador perdido' => [
                "Da Responsabilidade Civil\n\nO Réu deve indenizar.",
                'marcador',
            ],
            // O mesmo julgado citado duas vezes.
            'o marcador repetido' => [
                "[[JULGADO 1]]\n\nNo mesmo sentido:\n\n[[JULGADO 1]]",
                'marcador',
            ],
            'o marcador inventado' => [
                "[[JULGADO 1]]\n\n[[JULGADO 2]]",
                'marcador',
            ],
            // O marcador que deixa de estar sozinho na linha não seria expandido.
            'o marcador no meio da frase' => [
                'Conforme [[JULGADO 1]], o dano é devido.',
                'marcador',
            ],
            'a súmula lembrada de memória' => [
                "O Réu responde, conforme a Súmula 145 do STJ.\n\n[[JULGADO 1]]",
                'Súmula 145',
            ],
            'o tema lembrado de memória' => [
                "O Réu responde, conforme o Tema nº 1.061 do STJ.\n\n[[JULGADO 1]]",
                'Tema nº 1.061',
            ],
            'a cifra que ninguém escreveu' => [
                "O prejuízo, totalizando R$ 30.200,00, deve ser reparado.\n\n[[JULGADO 1]]",
                '30200.00',
            ],
        ];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_rewrite_that_loses_a_ruling_or_invents_a_citation_is_refused(string $content, string $reason): void
    {
        $reinforced = $this->reinforced($content);

        $this->assertFalse($reinforced->isAcceptable());
        $this->assertStringContainsString($reason, (string) $reinforced->refusal());
    }

    /**
     * O que a redação já citou não precisa de nova licença: uma súmula que só
     * está no corpo original continua podendo estar no reescrito.
     */
    #[Test]
    public function what_the_original_section_cites_needs_no_other_source(): void
    {
        $original = "O Réu responde, conforme a Súmula 130 do STJ.\n\n[[JULGADO 1]]";

        $reinforced = ReinforcedGroundsData::fromAgent(
            ['content' => "Nos termos da súmula 130 do STJ, o Réu responde.\n\n[[JULGADO 1]]"],
            $original,
            '',
        );

        $this->assertTrue($reinforced->isAcceptable());
    }

    private function reinforced(string $content): ReinforcedGroundsData
    {
        return ReinforcedGroundsData::fromAgent(['content' => $content], self::ORIGINAL, self::SOURCES);
    }
}
