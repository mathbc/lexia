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
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::cases(),
        );
    }
}
