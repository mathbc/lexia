<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Support;

use App\Domain\Customers\Enums\CustomerType;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\ForumPlace;
use App\Domain\ProceduralClasses\Enums\Jurisdiction;
use App\Domain\ProceduralClasses\Models\ProceduralClass;

/**
 * What the two addressing agents read about a pleading, and nothing more.
 *
 * The sibling of LegalCaseDossier for a question the dossier does not answer:
 * the dossier describes a saved pleading to write it, and this describes the
 * parties of one that may not be saved yet, to place it. It is narrower on
 * purpose, because it goes to the cloud. Competence is decided by what each
 * party **is** and **where** it is, so that is what travels:
 *
 * - the client as a person or a company, with the age when it is a person —
 *   the idoso has a forum of their own —, and the city and state of the
 *   registered domicile. Never the name, the street or the document;
 * - the defendant as a person or a company when the document says which,
 *   with the city and state when they are on record, and the **name** unless
 *   the document says it is a person. The name is what reveals the INSS, the
 *   Caixa or a Município, and those move the case to another branch or court;
 *   a private individual's name decides nothing and stays home.
 */
final class ForumBrief
{
    /**
     * The class line with the competences it runs in, which is what narrows
     * the justice.
     */
    public static function classLine(?ProceduralClass $class): ?string
    {
        if ($class === null) {
            return null;
        }

        $competences = array_filter(array_map(
            static fn (string $value): ?string => Jurisdiction::tryFrom($value)?->label(),
            $class->jurisdictions,
        ));

        return $competences === []
            ? $class->promptLine()
            : $class->promptLine().' Competências da classe: '.implode('; ', $competences).'.';
    }

    /**
     * @return list<string>
     */
    public static function parties(Customer $customer, DefendantData $defendant): array
    {
        return [self::plaintiff($customer), self::defendant($defendant)];
    }

    /**
     * The client's registered domicile, where the addressing copies the city
     * from when the forum is the plaintiff's.
     */
    public static function plaintiffPlace(Customer $customer): ForumPlace
    {
        return new ForumPlace($customer->city, $customer->state);
    }

    /**
     * The defendant's saved address, as much of it as is known.
     */
    public static function defendantPlace(DefendantData $defendant): ForumPlace
    {
        return new ForumPlace($defendant->city, $defendant->state);
    }

    private static function plaintiff(Customer $customer): string
    {
        $who = $customer->type === CustomerType::Individual
            ? implode(', ', array_filter(['pessoa física', LegalCaseDossier::age($customer->birth_date)]))
            : 'pessoa jurídica';

        return "Autor (o cliente): {$who}; domicílio em {$customer->city}/{$customer->state->value}.";
    }

    private static function defendant(DefendantData $defendant): string
    {
        $type = match (strlen((string) $defendant->document)) {
            11 => CustomerType::Individual,
            14 => CustomerType::Company,
            default => null,
        };

        $who = array_filter([
            $type === null ? null : mb_strtolower($type->label()),
            $type === CustomerType::Individual ? null : $defendant->name,
        ]);

        return 'Réu: '.($who === [] ? 'não cadastrado — o relato pode descrevê-lo' : implode(', ', $who))
            .'; '.self::where($defendant).'.';
    }

    private static function where(DefendantData $defendant): string
    {
        $city = $defendant->city;
        $state = $defendant->state?->value;

        return match (true) {
            $city !== null && $state !== null => "endereço em {$city}/{$state}",
            $city !== null => "endereço em {$city}, sem a UF",
            $state !== null => "endereço em {$state}, sem a cidade",
            default => 'endereço não cadastrado',
        };
    }
}
