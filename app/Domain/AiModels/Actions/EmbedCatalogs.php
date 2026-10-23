<?php

declare(strict_types=1);

namespace App\Domain\AiModels\Actions;

use App\Domain\LegalThemes\Actions\EmbedLegalThemes;
use App\Domain\LegalThemes\Models\LegalTheme;
use App\Domain\ProceduralClasses\Actions\EmbedProceduralClasses;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Brings both vectorized catalogues up to the default embedding model.
 *
 * Queued when the default changes hands, because every vector stored until then
 * was made by the previous model: DocumentEmbedder counts a row whose
 * `embedding_model` is not the default's key as stale, so this re-embeds those
 * and only those — a second run, or a run on rows already current, sends
 * nothing.
 *
 * Until it finishes, the theme search finds no candidate made by the new model
 * and fails loudly, as it does with an empty catalogue; the class ranking heals
 * the candidates it needs on the spot. The two `lexia:` commands do the same
 * work by hand.
 */
final class EmbedCatalogs
{
    use AsAction;

    /**
     * Once: a failure is a provider or a quota, and the next change of default
     * or the commands are the retry.
     */
    public int $jobTries = 1;

    /**
     * Some three thousand texts in batches of 64 — minutes on a cloud
     * provider, more on the local daemon.
     */
    public int $jobTimeout = 1800;

    private const int THEME_CHUNK = 256;

    /**
     * @return int how many rows were (re)embedded
     */
    public function handle(): int
    {
        $embedded = EmbedProceduralClasses::run(ProceduralClass::query()->get());

        // In chunks, as the import does: the 2.4 thousand themes hydrated with
        // their vectors at once overflow a 128 MB memory_limit.
        LegalTheme::query()->chunkById(self::THEME_CHUNK, function (Collection $themes) use (&$embedded): void {
            $embedded += EmbedLegalThemes::run($themes);
        });

        return $embedded;
    }
}
