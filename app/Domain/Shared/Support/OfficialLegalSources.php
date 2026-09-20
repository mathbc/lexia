<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

/**
 * The portals a legal citation is allowed to have been confirmed against.
 *
 * One list, read twice and for opposite purposes: the research agent's
 * instructions print it so the model knows where to look, and
 * LegalResearchData checks every url the model answers with against it. Two
 * copies would drift, and the drift would be silent in the worst direction —
 * a prompt naming a portal the guard rejects throws away good findings, and a
 * guard accepting a portal the prompt never named is a hole nobody opened on
 * purpose.
 *
 * **The guard is not redundant with the provider, and that is the whole reason
 * this class exists.** `WebSearch::allow()` and `WebFetch::allow()` describe
 * exactly this restriction, and on Anthropic they reach the wire as
 * `allowed_domains`. On Gemini — which is the provider this agent runs on —
 * `GeminiProvider::webSearchToolOptions()` returns `[]`, so the allowlist is
 * dropped before the request is built, with no escape hatch: that provider does
 * not read the tool's `providerOptions()` either, and request-level provider
 * options land in `generationConfig`, which cannot reach `tools`. The calls are
 * still made, because they document the intent and start working the day the
 * provider changes. Until then, this class is the only thing standing between
 * a Jusbrasil summary and a citation in a pleading.
 *
 * Membership is by registrable domain and not by host, so `scon.stj.jus.br` and
 * `processo.stj.jus.br` both pass under `stj.jus.br` without being listed. The
 * subdomain test is written with the leading dot on purpose: plain
 * `str_ends_with($host, 'planalto.gov.br')` would also admit
 * `planalto.gov.br.attacker.com`'s cousin `naoeoplanalto.gov.br`.
 *
 * What is deliberately *not* here: a blanket `gov.br` or `jus.br`. They would
 * admit every municipal portal and every court in the country, which is most of
 * what the prompt spends its lines refusing. State and municipal law is the one
 * case this list cannot anticipate — when it comes up, the entity's own portal
 * is added here by hand, which is a visible edit somebody reviews.
 */
final class OfficialLegalSources
{
    /**
     * @var list<string>
     */
    public const DOMAINS = [
        // Legislação federal, Constituição, códigos e o diário onde uma norma
        // nasce, é alterada ou é revogada.
        'planalto.gov.br',
        'in.gov.br',
        'senado.leg.br',
        'camara.leg.br',

        // Súmulas, Jurisprudência em Teses, SCON, temas repetitivos e IACs.
        'stj.jus.br',

        // Súmulas, súmulas vinculantes e repercussão geral.
        'stf.jus.br',

        // Resoluções e a TPU, que é de onde vêm as classes processuais.
        'cnj.jus.br',
    ];

    /**
     * Whether a url was served by one of the portals above.
     *
     * Anything that is not a parseable absolute url with a host is false, which
     * folds three different failures into the same answer on purpose: the model
     * that answered "Não localizado/confirmado em fonte oficial." in the url
     * field, the one that wrote a bare "STJ", and the one that invented
     * `https://jusbrasil.com.br/...` are all citations nobody can check.
     */
    public static function covers(?string $url): bool
    {
        $host = is_string($url) ? parse_url(trim($url), PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach (self::DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The list as the prompt prints it, one domain per line.
     */
    public static function asPromptList(): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $domain): string => '- '.$domain,
            self::DOMAINS,
        ));
    }
}
