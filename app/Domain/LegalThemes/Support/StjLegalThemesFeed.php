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
 *
 * The portal goes down for days at a time — the TLS handshake completes and
 * then nothing comes back —, so a copy of the same file lives on Google Drive.
 * The mirror is a snapshot that ages, which is why it is the second source and
 * never the first: it only answers while the portal does not.
 */
final class StjLegalThemesFeed
{
    public const string URL = 'https://dadosabertos.web.stj.jus.br/dataset/4238da2f-c07b-4c1a-b345-4402accacdcf/resource/df29da13-7d6b-41ba-ad96-cd1a5bbd191c/download/temas.csv';

    /**
     * The direct download of the Drive file, not its `/view` page.
     */
    public const string MIRROR_URL = 'https://drive.usercontent.google.com/download?id=1wVp3ThSrwL01yTwncF-cHLFDGwuSd3l4&export=download';

    /**
     * Tried in this order, until one delivers.
     */
    public const array SOURCES = [self::URL, self::MIRROR_URL];

    private const int TIMEOUT = 60;

    /**
     * @return string the path of the downloaded file
     */
    public function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stj-temas-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário do download.');
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LexIA/1.0)'])
                ->sink($path)
                ->get($url)
                ->throw();

            // Drive answers 200 with a page when the file stops being public;
            // the reader would refuse it by the header without saying why.
            if (str_contains($response->header('Content-Type'), 'text/html')) {
                throw new RuntimeException('a resposta é uma página HTML, e não o CSV.');
            }
        } catch (Throwable $e) {
            // Uma resposta de erro também é gravada no sink; ela não pode
            // sobrar no disco nem chegar ao leitor.
            unlink($path);

            throw $e;
        }

        return $path;
    }
}
