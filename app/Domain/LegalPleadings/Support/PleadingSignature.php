<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;

/**
 * The closing block of a pleading: the place, the date and who signs.
 *
 *     Itajaí/SC, 20 de setembro de 2026.
 *
 *     Marcelino Pedro
 *     OAB/SC 438912
 *
 * Composed here and not by the drafting agent, for the reason that keeps the
 * letterhead out of the model too: a name, an enrolment number and a date are
 * **data**. A model given them would copy them correctly almost always, and the
 * exception is a document filed under a real lawyer's OAB. There is no upside to
 * trade against that, so the agent stops at "Nestes termos, pede deferimento."
 * and this is appended.
 *
 * No honorific. "Dr." and "Dra." are gendered, the schema records no gender, and
 * a wrong one on a signed document reads worse than none at all.
 *
 * Whatever is missing becomes a bracketed marker, exactly as the agent's gaps
 * do — a lawyer who has not filled in their OAB gets `[OAB]` to complete rather
 * than a signature block that quietly omits it.
 */
final class PleadingSignature
{
    /**
     * The months in Portuguese, written out rather than resolved through a
     * locale.
     *
     * Same reasoning as `LegalCaseDossier::money()` avoiding `number_format`:
     * the output of this class goes into a legal document, and a date that
     * changes shape because a locale was or was not installed on the machine is
     * not a dependency worth taking for twelve words.
     *
     * @var list<string>
     */
    private const array MONTHS = [
        'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    /**
     * The block, ready to be appended to the drafted body.
     *
     * The place is the firm's city and not the court's: it is where the document
     * is signed, which is what the line means. The date is today, because that is
     * when this version was written — a draft opened again next week keeps the
     * date it was drafted under, which is correct and is also editable, since all
     * of this lands in the same textarea as the rest.
     */
    public static function for(LegalCase $legalCase, ?User $author = null, ?CarbonImmutable $on = null): string
    {
        $account = self::accountOf($legalCase);

        return implode(PHP_EOL, [
            self::placeAndDate($account, $on ?? CarbonImmutable::now()),
            '',
            $author->name ?? '[Nome do advogado]',
            $author?->oabRegistration() ?? $account?->oabRegistration() ?? '[OAB]',
        ]);
    }

    /**
     * The account, read through the relation rather than the typed property.
     *
     * The same move `LegalCaseDossier::filedAs()` makes, and for the same
     * reason: on a saved pleading the account is always there and the docblock
     * is right, but this class is also asked about a LegalCase that exists only
     * in memory — an agent test builds one with `setRelation()`, and a
     * classification builds one that was never saved. There the property would
     * be null against its own declared type, so the relation is asked instead
     * and the missing account turns into a marker, like every other gap.
     */
    private static function accountOf(LegalCase $legalCase): ?Account
    {
        $account = $legalCase->getRelationValue('account');

        return $account instanceof Account ? $account : null;
    }

    private static function placeAndDate(?Account $account, CarbonImmutable $on): string
    {
        $city = self::city($account);
        $month = self::MONTHS[$on->month - 1];

        return "{$city}, {$on->day} de {$month} de {$on->year}.";
    }

    /**
     * "Itajaí/SC", or the marker when the account is not loaded.
     *
     * The city and the UF are both NOT NULL on `accounts`, so the only way to
     * reach the marker is a pleading whose account relation is absent — which
     * happens in the one place it should: a LegalCase built in memory for a test
     * or for a classification that has not been saved.
     */
    private static function city(?Account $account): string
    {
        if ($account === null) {
            return '[Cidade/UF]';
        }

        return "{$account->city}/{$account->state->value}";
    }
}
