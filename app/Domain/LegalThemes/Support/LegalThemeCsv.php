<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Support;

use App\Domain\LegalThemes\Data\LegalThemeRecord;
use RuntimeException;
use UnexpectedValueException;

/**
 * Reads the STJ themes file into one LegalThemeRecord per precedent.
 *
 * The file is RFC 4180: quoted cells span lines and a quote inside one is
 * doubled (`""para que…""`). Hence `escape: ''` on every read — the default
 * backslash escape is not what the STJ writes, and relying on it is deprecated
 * since PHP 8.4.
 *
 * Everything is read and converted before anything is returned, so a bad cell
 * anywhere fails the import before the first write.
 */
final class LegalThemeCsv
{
    /**
     * @return list<LegalThemeRecord>
     */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Arquivo de temas ilegível: {$path}.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Arquivo de temas ilegível: {$path}.");
        }

        try {
            $groups = $this->groups($handle);
        } finally {
            fclose($handle);
        }

        return array_map($this->record(...), array_keys($groups), array_values($groups));
    }

    /**
     * The rows keyed by `sequencialPrecedente`, in the order they first appear
     * — an int key, once PHP is done with a numeric string.
     *
     * @param  resource  $handle
     * @return array<int|string, non-empty-list<array<string, string>>>
     */
    private function groups($handle): array
    {
        $columns = $this->header($handle);
        $groups = [];
        $index = 0;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $index++;

            if ($values === [null]) {
                continue;
            }

            if (count($values) !== count($columns)) {
                throw new UnexpectedValueException(sprintf(
                    'Registro %d do arquivo de temas tem %d colunas; o cabeçalho tem %d.',
                    $index,
                    count($values),
                    count($columns),
                ));
            }

            $row = array_combine($columns, array_map(strval(...), $values));
            $groups[trim($row['sequencialPrecedente'])][] = $row;
        }

        return $groups;
    }

    /**
     * The header, checked against the columns LegalThemeRecord reads.
     *
     * By name and not by position: an extra column or a reordering is
     * harmless, a renamed one is not.
     *
     * @param  resource  $handle
     * @return list<string>
     */
    private function header($handle): array
    {
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            throw new UnexpectedValueException('O arquivo de temas está vazio.');
        }

        // A BOM would glue itself to the first column name.
        $columns = array_map(
            static fn (?string $name): string => trim(preg_replace('/^\x{FEFF}/u', '', (string) $name) ?? ''),
            $header,
        );

        $missing = array_diff(LegalThemeRecord::COLUMNS, $columns);

        if ($missing !== []) {
            throw new UnexpectedValueException(
                'O arquivo de temas não tem as colunas: '.implode(', ', $missing).'.',
            );
        }

        return $columns;
    }

    /**
     * @param  non-empty-list<array<string, string>>  $rows
     */
    private function record(string|int $sequentialNumber, array $rows): LegalThemeRecord
    {
        try {
            return LegalThemeRecord::fromRows($rows);
        } catch (UnexpectedValueException $e) {
            throw new UnexpectedValueException(
                "Precedente {$sequentialNumber}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
