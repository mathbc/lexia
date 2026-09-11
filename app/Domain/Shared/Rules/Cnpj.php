<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use App\Domain\Shared\Rules\Concerns\ChecksModulo11;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a CNPJ, ignoring any punctuation.
 */
final class Cnpj implements ValidationRule
{
    use ChecksModulo11;

    private const int LENGTH = 14;

    /** @var list<int> */
    private const array FIRST_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** @var list<int> */
    private const array SECOND_WEIGHTS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen((string) $digits) !== self::LENGTH || $this->isRepeated((string) $digits)) {
            $fail('validation.cnpj')->translate();

            return;
        }

        if (! $this->hasValidCheckDigits((string) $digits)) {
            $fail('validation.cnpj')->translate();
        }
    }

    private function hasValidCheckDigits(string $digits): bool
    {
        $numbers = $this->digitsOf($digits);

        $first = $this->checkDigit($numbers, self::FIRST_WEIGHTS);
        $second = $this->checkDigit($numbers, self::SECOND_WEIGHTS);

        return $numbers[12] === $first && $numbers[13] === $second;
    }
}
