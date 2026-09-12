<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

/**
 * Default `options()` for a backed enum implementing HasLabel.
 */
trait ProvidesOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return self::optionsFrom(self::cases());
    }

    /**
     * The same projection over a hand-picked subset, for the cases that exist
     * in the enum but must not be offered in a form.
     *
     * @param  list<self>  $cases
     * @return list<array{value: string, label: string}>
     */
    public static function optionsFrom(array $cases): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
