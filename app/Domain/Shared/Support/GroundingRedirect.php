<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The page a Gemini grounding citation actually points at.
 *
 * Gemini does not hand back the address of what its search returned: every
 * grounding chunk carries a `vertexaisearch.cloud.google.com/grounding-api-redirect/…`
 * url, and the model itself only ever sees that. Measured on 2026-10-22 with the
 * doctrine research, and it decides a design:
 *
 * | where the address came from | what it was | resolves? |
 * |---|---|---|
 * | the model's sheet | `…:artigo.revista:1995;12030563` | **no** — "urn não encontrada" |
 * | the grounding chunk, followed once | `…:artigo.revista:1995;1000506067` | yes, the record |
 *
 * The model got the kind and the year right and invented the catalogue number,
 * which it cannot derive from anything it saw — all seven works of the first run
 * pointed nowhere. The redirect, followed one hop with no body read, is the one
 * address in the whole exchange that no model wrote.
 *
 * One hop and no further: the `Location` is the answer, and fetching it is the
 * caller's business, behind its own guard. A url that is not a Vertex redirect
 * comes back unchanged — some providers cite the page directly.
 */
final class GroundingRedirect
{
    private const HOST = 'vertexaisearch.cloud.google.com';

    private const TIMEOUT = 10;

    /**
     * The address behind a grounding url, or null if it could not be followed.
     */
    public static function resolve(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || strtolower($host) !== self::HOST) {
            return $url;
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        $location = trim($response->header('Location'));

        return $response->redirect() && $location !== '' ? $location : null;
    }
}
