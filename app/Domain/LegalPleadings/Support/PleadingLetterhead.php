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
