<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules\Concerns;

/**
 * The modulo-11 check digit shared by CPF and CNPJ.
 *
 * Both documents differ only in length and in the weights applied, so the
 * arithmetic lives here once.
 */
trait ChecksModulo11
{
    /**
     * @param  list<int>  $digits
     * @param  list<int>  $weights
     */
    private function checkDigit(array $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += $digits[$index] * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }

    /**
     * @return list<int>
     */
    private function digitsOf(string $value): array
    {
        return array_map(intval(...), str_split((string) preg_replace('/\D/', '', $value)));
    }

    /**
     * "111.111.111-11" and friends satisfy the arithmetic but are never issued.
     */
    private function isRepeated(string $digits): bool
    {
        return preg_match('/^(\d)\1+$/', $digits) === 1;
    }
}
