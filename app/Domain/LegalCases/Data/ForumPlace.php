<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Data;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Enums\ForumSource;
use Illuminate\Support\Str;

/**
 * The city and state of the competent forum, and why either may be missing.
 *
 * The addressing agent says **where the city comes from**, and this is what
 * goes and gets it. Two sources are records — the client's registered
 * domicile, the defendant's saved address — and are copied as they are, the
 * model's own spelling ignored. The third is the narrative, and there the
 * model's city is accepted only when the narrative writes it: the same guard
 * PleadingDraftData runs on money, applied to a place. A city the facts do not
 * name is dropped with its state, and the warning says so, because a comarca
 * recalled from nowhere has exactly the shape of a real one.
 *
 * A place with a state and no city is still worth having. The addressing
 * leaves `[CIDADE]` for the lawyer, and the state alone is enough to find the
 * court's judicial system.
 */
final readonly class ForumPlace
{
    public function __construct(
        public ?string $city,
        public ?BrazilianState $state,
        public ?string $warning = null,
    ) {}

    /**
     * @param  ForumPlace  $plaintiff  the client's registered domicile
     * @param  ForumPlace  $defendant  the defendant's address, as far as it is known
     */
    public static function resolve(
        ?ForumSource $source,
        ?string $city,
        ?BrazilianState $state,
        self $plaintiff,
        self $defendant,
        string $facts,
    ): self {
        return match ($source) {
            ForumSource::PlaintiffAddress => $plaintiff,
            ForumSource::DefendantAddress => $defendant->city === null
                ? new self(null, $defendant->state, 'O endereço do réu não traz a cidade: complete a comarca do endereçamento.')
                : $defendant,
            ForumSource::Facts => self::fromFacts(self::text($city), $state, $facts),
            null => new self(null, null),
        };
    }

    /**
     * Whether a narrative writes a city, ignoring accents, case and the
     * spacing a dictated text gets wrong — "sao jose" names São José.
     */
    public static function writtenIn(string $city, string $facts): bool
    {
        $needle = self::normalise($city);

        return $needle !== ''
            && preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/', self::normalise($facts)) === 1;
    }

    private static function fromFacts(?string $city, ?BrazilianState $state, string $facts): self
    {
        if ($city === null || self::writtenIn($city, $facts)) {
            return new self($city, $state);
        }

        return new self(null, null, "A IA apontou {$city}, que o relato não menciona: confira a comarca do endereçamento.");
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }

    private static function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
