<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalCases\Enums\LegalCaseStep;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The step enum, and the high-water rule it carries.
 */
final class LegalCaseStepTest extends TestCase
{
    #[Test]
    public function every_step_has_a_portuguese_label(): void
    {
        foreach (LegalCaseStep::cases() as $step) {
            $this->assertNotSame('', trim($step->label()));
            $this->assertNotSame($step->value, $step->label());
        }

        $this->assertSame('Dados do réu', LegalCaseStep::Defendant->label());
    }

    #[Test]
    public function the_positions_follow_the_order_the_form_is_filled_in(): void
    {
        $this->assertSame(0, LegalCaseStep::Basics->position());
        $this->assertSame(5, LegalCaseStep::Review->position());

        $this->assertTrue(
            LegalCaseStep::Facts->position() < LegalCaseStep::Requirements->position(),
        );
    }

    #[Test]
    public function each_step_is_followed_by_the_next_one(): void
    {
        $this->assertSame(LegalCaseStep::Defendant, LegalCaseStep::Basics->next());
        $this->assertSame(LegalCaseStep::Documents, LegalCaseStep::Requirements->next());
    }

    #[Test]
    public function the_last_step_has_no_step_after_it(): void
    {
        // Satura em vez de transbordar, que é o que dispensa `furthest()` de
        // guardar o fim da lista.
        $this->assertSame(
            LegalCaseStep::CourtDecisions,
            LegalCaseStep::CourtDecisions->next(),
        );

        // E a revisão forense deixou de ser o fim: é ela que abre a análise de
        // jurisprudência.
        $this->assertSame(LegalCaseStep::CourtDecisions, LegalCaseStep::Review->next());
    }

    #[Test]
    public function the_furthest_of_two_steps_is_the_later_one_whichever_way_it_is_asked(): void
    {
        $early = LegalCaseStep::Basics;
        $late = LegalCaseStep::Documents;

        $this->assertSame($late, $early->furthest($late));
        $this->assertSame($late, $late->furthest($early));
        $this->assertSame($late, $late->furthest($late));
    }

    #[Test]
    public function the_options_carry_every_step_as_value_and_label(): void
    {
        $options = LegalCaseStep::options();

        $this->assertCount(count(LegalCaseStep::cases()), $options);
        $this->assertSame(
            ['value' => 'basics', 'label' => 'Dados básicos'],
            $options[0],
        );
    }
}
