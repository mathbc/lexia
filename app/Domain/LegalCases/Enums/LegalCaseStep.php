<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Where the drafting of a pleading stands.
 *
 * The six steps of the assembly form, in the order they are filled: who the
 * pleading is for, who it is against, what happened, what is asked, what
 * instructs it, and the final read-through.
 *
 * Stored on `legal_cases.current_step` as a high-water mark — the furthest step
 * reached, never the last one edited. That is what lets the listing say where a
 * draft stopped and the form reopen there, and it is why going back to fix the
 * client does not lock the steps already written. `furthest()` is the rule.
 *
 * The order is the declaration order and nothing else: `position()` reads it
 * from `cases()` rather than from a number written twice, so inserting a step
 * is a one-line change here and nowhere else.
 */
enum LegalCaseStep: string implements HasLabel
{
    use ProvidesOptions;

    case Basics = 'basics';
    case Defendant = 'defendant';
    case Facts = 'facts';
    case Requirements = 'requirements';
    case Documents = 'documents';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Basics => 'Dados básicos',
            self::Defendant => 'Dados do réu',
            self::Facts => 'Fatos e tutela',
            self::Requirements => 'Pedidos e requerimentos',
            self::Documents => 'Documentos',
            self::Review => 'Revisão forense',
        };
    }

    /**
     * How far along the form this step sits, counting from zero.
     *
     * Zero-based to match the index the timeline component works in, and read
     * from `cases()` so the declaration order above is the only place the
     * sequence is written down.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * The step that follows this one.
     *
     * The last step returns itself: there is nothing after the final review,
     * and saturating is what keeps `furthest()` from having to guard the end of
     * the list.
     */
    public function next(): self
    {
        return self::cases()[$this->position() + 1] ?? $this;
    }

    /**
     * The more advanced of two steps.
     *
     * This is the whole of the high-water rule: a pleading that reached the
     * requests and comes back to have its client corrected is still a pleading
     * that reached the requests.
     */
    public function furthest(self $other): self
    {
        return $other->position() > $this->position() ? $other : $this;
    }
}
