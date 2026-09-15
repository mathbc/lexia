<?php

declare(strict_types=1);

namespace Database\Factories\Support;

/**
 * CPFs and CNPJs with correct check digits.
 *
 * Faker runs under the en_US locale here, which has no provider for either,
 * and seeded data has to survive the Cpf and Cnpj validation rules.
 */
final class BrazilianDocuments
{
    /** @var list<int> */
    private const array CPF_FIRST_WEIGHTS = [10, 9, 8, 7, 6, 5, 4, 3, 2];

    /** @var list<int> */
    private const array CPF_SECOND_WEIGHTS = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];

    /** @var list<int> */
    private const array CNPJ_FIRST_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** @var list<int> */
    private const array CNPJ_SECOND_WEIGHTS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    public static function cpf(): string
    {
        $digits = array_map(static fn (): int => random_int(0, 9), range(1, 9));

        return self::withCheckDigits($digits, self::CPF_FIRST_WEIGHTS, self::CPF_SECOND_WEIGHTS);
    }

    public static function cnpj(): string
    {
        // The branch suffix is always 0001 for a head office, which is what a
        // seeded company would have.
        $digits = [...array_map(static fn (): int => random_int(0, 9), range(1, 8)), 0, 0, 0, 1];

        return self::withCheckDigits($digits, self::CNPJ_FIRST_WEIGHTS, self::CNPJ_SECOND_WEIGHTS);
    }

    /**
     * @param  list<int>  $digits
     * @param  list<int>  $firstWeights
     * @param  list<int>  $secondWeights
     */
    private static function withCheckDigits(array $digits, array $firstWeights, array $secondWeights): string
    {
        foreach ([$firstWeights, $secondWeights] as $weights) {
            $sum = 0;

            foreach ($weights as $index => $weight) {
                $sum += $digits[$index] * $weight;
            }

            $remainder = $sum % 11;
            $digits[] = $remainder < 2 ? 0 : 11 - $remainder;
        }

        return implode('', $digits);
    }
}
