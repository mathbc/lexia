<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One page of the LexML, fetched and read as the portal prints it.
 *
 * The part two readers share: LexmlRecordReader, which reads a ruling, and
 * DoctrineRecordReader, which reads a work of scholarship. Both pages are the
 * same template — a Bootstrap list whose every row is a `<strong>` label beside
 * a `text-left` cell —, served from the same `/urn/` path, behind the same
 * anti-bot filter. What differs is which labels matter and what they become,
 * and that stays in each reader.
 *
 * Only ever handed a `/urn/` address: the callers check it first, because the
 * search screen under `/busca/` is closed to clients (robots.txt and the
 * Senado's challenge — CourtDecisionSources has the table).
 */
final class LexmlPage
{
    /**
     * Short on purpose.
     *
     * Several records are read per research run, in series, inside a request a
     * browser is already waiting on — and the expensive part of that wait is
     * the two inferences ahead of this. A record that does not answer in ten
     * seconds is dropped, which costs one row; waiting on each of six would
     * cost the step.
     */
    private const TIMEOUT = 10;

    /**
     * The page, or null for anything that is not a page.
     *
     * A browser's User-Agent, because the portal sits behind a filter that
     * answers a bare client with a challenge; on `/urn/` it does not fire, and
     * asking politely is what keeps it that way. Every failure — DNS, timeout,
     * 404, a 200 carrying the challenge instead of the record — folds into
     * null, because every caller does the same thing with all of them.
     */
    public static function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LexIA/1.0)'])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        // A página de desafio responde 200 e não é um registro. Reconhecê-la
        // pelo título é mais barato e mais estável que pelo corpo, que é
        // JavaScript.
        return str_contains($html, 'Verificação de segurança') ? null : $html;
    }

    /**
     * Every labelled row of the page, in order, as `[label, text]` pairs.
     *
     * A label can repeat — an aggregated record lists one Tipo per edition, a
     * co-written work one Autor per author —, which is why this is a list and
     * not a map. A label whose `<strong>` carries markup (the "Classificação"
     * row links to Wikipedia) does not match, and is not wanted by anybody.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function rows(string $html): array
    {
        $pattern = '#<strong>\s*([^<]+?)\s*</strong>\s*</div>\s*'
            .'<div[^>]*class="[^"]*text-left[^"]*"[^>]*>(.*?)</div>#su';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(
            static fn (array $match): array => [self::text($match[1]), self::text($match[2])],
            $matches,
        );
    }

    /**
     * The first occurrence of each label, mapped to the key the caller names.
     *
     * The first one wins even when it is empty, and empty values are dropped
     * afterwards — so a label the page omits, or prints blank, is simply
     * absent from the result.
     *
     * @param  array<string, string>  $labels  page label => key
     * @return array<string, string>
     */
    public static function fields(string $html, array $labels): array
    {
        $fields = [];

        foreach (self::rows($html) as [$label, $value]) {
            $key = $labels[$label] ?? null;

            if ($key !== null && ! isset($fields[$key])) {
                $fields[$key] = $value;
            }
        }

        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }

    /**
     * One cell as plain text.
     *
     * The portal writes `&#150;` for the en dash its ementas are full of, so
     * decoding runs before the tags come out. `menos [x]` is the collapse link
     * the Assuntos row carries, and it is markup pretending to be content.
     */
    public static function text(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags(preg_replace('#<br\s*/?>#i', ' ', $decoded) ?? $decoded);
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;

        return trim(str_replace(['menos [x]', 'mais [+]'], '', $collapsed));
    }
}
