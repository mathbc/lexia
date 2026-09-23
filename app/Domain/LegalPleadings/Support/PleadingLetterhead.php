<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Models\User;

/**
 * The firm's letterhead, projected for the screen that draws it.
 *
 * The document's header is **chrome and not content**: it is drawn by React from
 * the account and the signed-in lawyer, and it is not part of the `content` the
 * textarea edits. Three things fall out of that, and each is the point:
 *
 * 1. No address and no OAB number ever passes through a language model.
 * 2. Correcting the firm's telephone corrects it on every draft at once, instead
 *    of on none of the ones already written.
 * 3. The lawyer cannot accidentally delete the letterhead while editing the body.
 *
 * The address columns go out **raw**, the way the database holds them, because
 * the masks already exist once — `formatPhone` and `formatPostalCode` in
 * `@/lib/format` — and writing PHP twins of them would be two implementations of
 * one rule, disagreeing eventually.
 *
 * Every field is nullable to the screen even where the column is not, because
 * the account relation can be absent and because `oab` genuinely has no value
 * for a lawyer who has not filled theirs in. The screen draws nothing for a
 * missing part rather than drawing a gap.
 */
final class PleadingLetterhead
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?Account $account, ?User $author): array
    {
        return [
            'firm' => $account?->displayName(),
            'lawyer' => $author?->name,
            // O advogado que assina vem antes da conta: numa conta `individual`
            // os dois costumam ser a mesma pessoa gravada duas vezes, e quem
            // assina a peça é quem está logado.
            'oab' => $author?->oabRegistration() ?? $account?->oabRegistration(),
            'email' => $account?->email,
            'phone' => $account?->phone,
            'address' => self::address($account),
        ];
    }

    /**
     * The three lines of the printed letterhead, with whatever is missing left out.
     *
     *     ESCRITÓRIO SILVA ADVOGADOS
     *     Maria Silva · OAB/SC 438912
     *     Rua X, 100, Centro, Itajaí/SC, CEP 88301-000 · (47) 99999-0000 · contato@…
     *
     * @return array{firm: string|null, signer: string|null, contact: string|null}
     */
    public static function lines(?Account $account, ?User $author): array
    {
        $letterhead = self::for($account, $author);

        return [
            'firm' => $letterhead['firm'] === null ? null : mb_strtoupper($letterhead['firm']),
            'signer' => self::joined([$letterhead['lawyer'], $letterhead['oab']]),
            'contact' => self::joined([
                self::addressLine($account),
                $account?->phone === null ? null : self::phone($account->phone),
                $letterhead['email'],
            ]),
        ];
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function joined(array $parts, string $glue = ' · '): ?string
    {
        $parts = array_filter($parts, static fn (?string $part): bool => trim((string) $part) !== '');

        return $parts === [] ? null : implode($glue, $parts);
    }

    /**
     * "Av. Paulista, 1000, Bela Vista, São Paulo/SP, CEP 01310-100" — the
     * `addressLine` of the screen.
     */
    private static function addressLine(?Account $account): ?string
    {
        if ($account === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $account->postal_code) ?? '';

        return self::joined([
            $account->street,
            $account->number,
            $account->complement,
            $account->district,
            self::joined([$account->city, $account->state->value], '/'),
            $digits === '' ? null : 'CEP '.(strlen($digits) === 8 ? substr($digits, 0, 5).'-'.substr($digits, 5) : $digits),
        ], ', ');
    }

    /** "(47) 3344-5566" or "(47) 99988-7766", as `formatPhone` writes them. */
    private static function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return match (strlen($digits)) {
            10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
            11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
            default => $value,
        };
    }

    /**
     * The seven address columns, unformatted.
     *
     * @return array<string, string|null>|null
     */
    private static function address(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'postal_code' => $account->postal_code,
            'street' => $account->street,
            'number' => $account->number,
            'complement' => $account->complement,
            'district' => $account->district,
            'city' => $account->city,
            'state' => $account->state->value,
        ];
    }
}
