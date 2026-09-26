<?php

declare(strict_types=1);

namespace App\Domain\LegalThemes\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Where the STJ publishes its themes file, and how it is fetched.
 *
 * The open-data portal (CKAN) serves the resource as a plain `text/csv` of
 * about 2.5 MB. It is streamed to a temporary file rather than held in memory,
 * and the caller owns — and deletes — that file.
 */
final class StjLegalThemesFeed
{
    public const string URL = 'https://dadosabertos.web.stj.jus.br/dataset/4238da2f-c07b-4c1a-b345-4402accacdcf/resource/df29da13-7d6b-41ba-ad96-cd1a5bbd191c/download/temas.csv';

    private const int TIMEOUT = 60;

    /**
     * @return string the path of the downloaded file
     */
    public function download(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stj-temas-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário do download.');
        }

        try {
            Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LexIA/1.0)'])
                ->sink($path)
                ->get(self::URL)
                ->throw();
        } catch (Throwable $e) {
            // Uma resposta de erro também é gravada no sink; ela não pode
            // sobrar no disco nem chegar ao leitor.
            unlink($path);

            throw $e;
        }

        return $path;
    }
}
