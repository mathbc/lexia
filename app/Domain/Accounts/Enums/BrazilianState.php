<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

use App\Domain\Shared\Concerns\ProvidesOptions;
use App\Domain\Shared\Contracts\HasLabel;

/**
 * Brazilian federative units.
 *
 * Used both for postal addresses and for the OAB seccional, which is what
 * makes an enrolment number unique — "123456" alone identifies nobody.
 */
enum BrazilianState: string implements HasLabel
{
    use ProvidesOptions;

    case AC = 'AC';
    case AL = 'AL';
    case AP = 'AP';
    case AM = 'AM';
    case BA = 'BA';
    case CE = 'CE';
    case DF = 'DF';
    case ES = 'ES';
    case GO = 'GO';
    case MA = 'MA';
    case MT = 'MT';
    case MS = 'MS';
    case MG = 'MG';
    case PA = 'PA';
    case PB = 'PB';
    case PR = 'PR';
    case PE = 'PE';
    case PI = 'PI';
    case RJ = 'RJ';
    case RN = 'RN';
    case RS = 'RS';
    case RO = 'RO';
    case RR = 'RR';
    case SC = 'SC';
    case SP = 'SP';
    case SE = 'SE';
    case TO = 'TO';

    /**
     * A flat lookup table rather than a 27-arm match: this is data, and a
     * match here scores 28 on cyclomatic complexity while carrying no branch
     * risk at all.
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];

    public function label(): string
    {
        return self::NAMES[$this->value];
    }

    /**
     * Abbreviation plus name, e.g. "SP — São Paulo". Long dropdowns are easier
     * to scan when the UF a lawyer already knows leads the line.
     */
    public function labelWithAbbreviation(): string
    {
        return "{$this->value} — {$this->label()}";
    }
}
