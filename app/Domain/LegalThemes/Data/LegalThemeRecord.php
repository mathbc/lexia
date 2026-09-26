<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Data;

use App\Domain\LegalThemes\Enums\JudgingBody;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use UnexpectedValueException;

/**
 * One precedent of the STJ file, translated into `legal_themes` columns.
 *
 * The only place that knows the STJ's headers. A precedent may span several
 * rows — the file repeats it once per STF general repercussion, and only
 * `numeroRepercussaoGeralSTF`/`descricaoRepercussaoGeral` change — so the
 * scalar columns come from the first row and the repercussions from all of
 * them.
 *
 * Every conversion is strict in the same direction: a blank cell is null, and
 * a cell that is neither blank nor what the column holds throws. A precedent
 * imported with a guessed date or a guessed type would be wrong silently,
 * while a failed import names the cell.
 */
final readonly class LegalThemeRecord
{
    /**
     * The STJ's header, in the file's order. A missing one fails the import.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'sequencialPrecedente',
        'tipoPrecedente',
        'numeroPrecedente',
        'dataPrimeiraAfetacao',
        'dataJulgamento',
        'dataPublicacaoAcordao',
        'situacao',
        'informacoesComplementares',
        'questaoSubmetidaAJulgamento',
        'teseFirmada',
        'anotacoesNUGEPNAC',
        'delimitacaoJulgado',
        'entendimentoAnterior',
        'referenciaLegislativa',
        'referenciaSumular',
        'sumulaOriginada',
        'audienciaPublica',
        'dataAudienciaPublica',
        'orgaoJulgador',
        'Assuntos',
        'numeroRepercussaoGeralSTF',
        'descricaoRepercussaoGeral',
    ];

    /**
     * @param  array<string, mixed>  $attributes  `legal_themes` columns, before casts
     * @param  list<array{number: int, description: string}>  $repercussions
     */
    public function __construct(
        public int $sequentialNumber,
        public array $attributes,
        public array $repercussions,
    ) {}

    /**
     * @param  non-empty-list<array<string, string>>  $rows  every row sharing one `sequencialPrecedente`
     */
    public static function fromRows(array $rows): self
    {
        $row = $rows[0];
        $sequentialNumber = self::requiredInteger($row['sequencialPrecedente'], 'sequencialPrecedente');

        return new self(
            sequentialNumber: $sequentialNumber,
            attributes: [
                'sequential_number' => $sequentialNumber,
                'type' => LegalThemeType::fromSource($row['tipoPrecedente']),
                'number' => self::requiredInteger($row['numeroPrecedente'], 'numeroPrecedente'),
                'first_assigned_on' => self::date($row['dataPrimeiraAfetacao']),
                'judged_on' => self::date($row['dataJulgamento']),
                'judgment_published_on' => self::date($row['dataPublicacaoAcordao']),
                'status' => trim($row['situacao']),
                'additional_information' => self::text($row['informacoesComplementares']),
                'question' => trim($row['questaoSubmetidaAJulgamento']),
                'settled_thesis' => self::text($row['teseFirmada']),
                'nugepnac_notes' => self::text($row['anotacoesNUGEPNAC']),
                'judgment_scope' => self::text($row['delimitacaoJulgado']),
                'previous_understanding' => self::text($row['entendimentoAnterior']),
                'legislative_reference' => self::text($row['referenciaLegislativa']),
                'referenced_sumula' => self::integer($row['referenciaSumular']),
                'originated_sumula' => self::integer($row['sumulaOriginada']),
                'public_hearing' => self::flag($row['audienciaPublica']),
                'public_hearing_on' => self::date($row['dataAudienciaPublica']),
                'judging_body' => JudgingBody::fromSource($row['orgaoJulgador']),
                'subjects' => self::subjects($row['Assuntos']),
            ],
            repercussions: self::repercussions($rows),
        );
    }

    /**
     * The distinct STF repercussions across the precedent's rows.
     *
     * @param  non-empty-list<array<string, string>>  $rows
     * @return list<array{number: int, description: string}>
     */
    private static function repercussions(array $rows): array
    {
        $repercussions = [];

        foreach ($rows as $row) {
            $number = self::integer($row['numeroRepercussaoGeralSTF']);

            if ($number !== null) {
                $repercussions[$number] = [
                    'number' => $number,
                    'description' => trim($row['descricaoRepercussaoGeral']),
                ];
            }
        }

        return array_values($repercussions);
    }

    /**
     * "1156- DIREITO DO CONSUMIDOR, 7771- Contratos de Consumo" as
     * `[{code: 1156, name: "DIREITO DO CONSUMIDOR"}, …]`.
     *
     * A name ends where the next ", <digits>-" begins, not at every comma, so
     * a comma inside a name survives. The STJ's stray space before a comma
     * ("Litisconsórcio , 899- …") is trimmed away.
     *
     * @return list<array{code: int, name: string}>
     */
    public static function subjects(string $value): array
    {
        preg_match_all('/(\d+)-\s*(.+?)\s*(?=,\s*\d+-|$)/su', trim($value), $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => ['code' => (int) $match[1], 'name' => $match[2]],
            $matches,
        );
    }

    /**
     * `S`, `N` or blank — the file's three answers to "was there a public
     * hearing?".
     */
    public static function flag(string $value): ?bool
    {
        return match (trim($value)) {
            '' => null,
            'S' => true,
            'N' => false,
            default => throw new UnexpectedValueException("Valor S/N inválido: [{$value}]."),
        };
    }

    /**
     * An ISO date (`2018-06-27`, the file's format), checked for existence and
     * returned as written.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new UnexpectedValueException("Data inválida: [{$value}].");
        }

        return $value;
    }

    public static function integer(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value)) {
            throw new UnexpectedValueException("Número inválido: [{$value}].");
        }

        return (int) $value;
    }

    private static function requiredInteger(string $value, string $column): int
    {
        return self::integer($value)
            ?? throw new UnexpectedValueException("Coluna obrigatória vazia: [{$column}].");
    }

    private static function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
