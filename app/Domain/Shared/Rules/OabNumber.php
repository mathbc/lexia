<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an OAB enrolment number, without the seccional.
 *
 * The OAB has no nationwide check digit: an enrolment is only unique together
 * with the UF that issued it, which is why the seccional is stored in its own
 * column and validated separately. All this rule can honestly assert is the
 * shape — up to six digits, optionally followed by the letter that marks a
 * supplementary registration.
 */
final class OabNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = strtoupper(trim((string) $value));

        if (preg_match('/^\d{1,6}[A-Z]?$/', $normalized) !== 1) {
            $fail('validation.oab_number')->translate();
        }
    }
}
