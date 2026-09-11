<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use App\Domain\Shared\Rules\Concerns\ChecksModulo11;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a CPF, ignoring any punctuation.
 */
final class Cpf implements ValidationRule
{
    use ChecksModulo11;

    private const int LENGTH = 11;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen((string) $digits) !== self::LENGTH || $this->isRepeated((string) $digits)) {
            $fail('validation.cpf')->translate();

            return;
        }

        if (! $this->hasValidCheckDigits((string) $digits)) {
            $fail('validation.cpf')->translate();
        }
    }

    private function hasValidCheckDigits(string $digits): bool
    {
        $numbers = $this->digitsOf($digits);

        $first = $this->checkDigit($numbers, range(10, 2));
        $second = $this->checkDigit($numbers, range(11, 2));

        return $numbers[9] === $first && $numbers[10] === $second;
    }
}
