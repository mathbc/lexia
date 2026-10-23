<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Domain\AiModels\Models\AiModel;
use App\Domain\AiModels\Support\DefaultModels;

/**
 * The default text model, for an agent that searches the web.
 *
 * The three researchers search and read pages through provider tools, and the
 * Ollama gateway throws on the first one it sees — a researcher pointed at the
 * daemon would not research worse, it would not research at all. So when the
 * default cannot search, this refuses before the prompt is built, with a
 * message that names the fix, instead of letting the gateway fail on a tool.
 */
trait UsesDefaultWebSearchModel
{
    use UsesDefaultAiModel;

    protected function defaultModel(): AiModel
    {
        return app(DefaultModels::class)->webSearchText();
    }
}
