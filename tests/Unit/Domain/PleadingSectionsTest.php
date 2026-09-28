<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalPleadings\Support\PleadingSections;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O recorte do DO DIREITO que o agente de embasamento reescreve.
 *
 * O que se fixa é a fronteira: o agente recebe o corpo e nunca o título — a
 * numeração romana não é dele —, os subtítulos das teses não encerram a seção,
 * e um documento sem a seção seguinte não é recortado, porque o corpo avançaria
 * sobre os pedidos e o fecho.
 */
final class PleadingSectionsTest extends TestCase
{
    private const string DRAFT = "EXCELENTÍSSIMO SENHOR DOUTOR JUIZ\n\nII – DOS FATOS\n\nO Réu colidiu contra o muro.\n\nIII – DO DIREITO\n\nDa Responsabilidade Civil\n\nO Réu deve indenizar.\n\n[[JULGADO 1]]\n\nIV – DOS PEDIDOS E REQUERIMENTOS\n\n1. A condenação do Réu.\n\nNestes termos, pede deferimento.";

    #[Test]
    public function it_cuts_the_body_of_do_direito_without_its_heading(): void
    {
        $this->assertSame(
            "Da Responsabilidade Civil\n\nO Réu deve indenizar.\n\n[[JULGADO 1]]",
            PleadingSections::grounds(self::DRAFT),
        );
    }

    #[Test]
    public function it_puts_a_new_body_back_under_the_original_heading(): void
    {
        $this->assertSame(
            "EXCELENTÍSSIMO SENHOR DOUTOR JUIZ\n\nII – DOS FATOS\n\nO Réu colidiu contra o muro.\n\nIII – DO DIREITO\n\nDa Responsabilidade Civil\n\nO art. 186 exige conduta, dano e nexo.\n\n[[JULGADO 1]]\n\nIV – DOS PEDIDOS E REQUERIMENTOS\n\n1. A condenação do Réu.\n\nNestes termos, pede deferimento.",
            PleadingSections::withGrounds(self::DRAFT, "\n  Da Responsabilidade Civil\n\nO art. 186 exige conduta, dano e nexo.\n\n[[JULGADO 1]]  \n"),
        );
    }

    /**
     * O hífen e o travessão que um modelo alterna com o meia-risca.
     */
    #[Test]
    public function it_accepts_the_dashes_a_model_alternates_between(): void
    {
        $this->assertSame('Corpo.', PleadingSections::grounds("II - DO DIREITO\n\nCorpo.\n\nIII — DOS PEDIDOS"));
    }

    /**
     * A tutela de urgência tem seção própria logo depois do DO DIREITO, e é
     * ela que encerra o corpo: o agente de embasamento nunca a vê, então não
     * tem como dobrá-la numa tese, e a devolução do corpo a deixa intacta.
     */
    #[Test]
    public function the_injunction_section_is_outside_the_body_of_do_direito(): void
    {
        $draft = "II – DOS FATOS\n\nFatos.\n\nIII – DO DIREITO\n\nDa Responsabilidade Civil\n\nO Réu deve indenizar.\n\n"
            ."IV – DA TUTELA DE URGÊNCIA\n\nO perigo de dano é concreto.\n\nV – DOS PEDIDOS E REQUERIMENTOS\n\n1. A tutela.";

        $this->assertSame("Da Responsabilidade Civil\n\nO Réu deve indenizar.", PleadingSections::grounds($draft));

        $this->assertStringContainsString(
            "O art. 927 obriga a reparar.\n\nIV – DA TUTELA DE URGÊNCIA\n\nO perigo de dano é concreto.",
            PleadingSections::withGrounds($draft, 'O art. 927 obriga a reparar.'),
        );
    }

    #[Test]
    public function a_document_without_the_section_or_without_a_section_after_it_is_left_alone(): void
    {
        $withoutGrounds = "I – DOS FATOS\n\nFatos.\n\nII – DOS PEDIDOS";
        $withoutNext = "I – DOS FATOS\n\nFatos.\n\nII – DO DIREITO\n\nCorpo.\n\nNestes termos, pede deferimento.";

        $this->assertNull(PleadingSections::grounds($withoutGrounds));
        $this->assertNull(PleadingSections::grounds($withoutNext));
        $this->assertSame($withoutNext, PleadingSections::withGrounds($withoutNext, 'Outro corpo.'));
    }
}
