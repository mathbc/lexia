<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\RequirementExtractionAgent;
use App\Domain\Requirements\Data\RequirementListData;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Read the facts of a matter and write out what they ask the court for.
 *
 * A component on its own, and still one after being wired in: ClassifyLegalCase
 * appends it to the framing, so the preenchimento inteligente opens the wizard
 * with the requests already drafted. Appended rather than chained — nothing
 * here needs the area or the class — and the screen that wants only the
 * requests calls this Action directly and pays one inference instead of four.
 *
 * The caller absorbs a failure here rather than propagating it: a suggestion
 * that did not arrive is not a reason to discard a framing that did.
 *
 * Returns `RequirementListData` rather than a data object of its own, and that
 * is the point of the shape: the same object `SaveLegalCaseRequirements::handle()`
 * already takes, so a suggestion the lawyer accepts is written by the code that
 * writes the form. `toArray()` publishes the rows a screen receives.
 *
 * `fromAgent()` is what makes the answer safe to keep, which is why the facts
 * are handed to it along with the answer. It drops the requests with no
 * sentence — the grammar can force the key but not the content —, reads the
 * money in whichever notation the model wrote it, and refuses a figure the
 * narrative does not contain. That last one is the reason this Action passes
 * `$facts` twice: money is the one thing here that cannot be allowed to be
 * approximately right, and it is the one rule the prompt could not be made to
 * keep on its own.
 *
 * **The empty list is a legitimate answer, and is not the same as a failure.**
 * A narrative that only tells a story asks for nothing; that comes back as zero
 * requests, while an inference that fell over comes back as `null` from the
 * caller. The step opens blank either way, which is how it has always opened —
 * the difference matters to the log, not to the screen.
 *
 * No `asController()` while no route points here, exactly as its siblings.
 */
final class ExtractLegalCaseRequirements
{
    use AsAction;

    public function handle(string $facts): RequirementListData
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para analisar.');
        }

        $response = (new RequirementExtractionAgent)->prompt($facts);

        // The SDK returns the structured subclass for every agent declaring
        // `HasStructuredOutput`, so this narrowing never fires in practice —
        // it fires when the integration is broken, which is the same category
        // of failure the sibling Actions raise on an unknown slug or code.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de pedidos não devolveu uma resposta estruturada.');
        }

        // O relato viaja junto da resposta porque é ele que autoriza cada
        // cifra: `fromAgent()` recusa um valor que não esteja escrito nos
        // fatos. Ver o porquê lá — é a única regra desta cadeia que um prompt
        // não conseguiu segurar.
        return RequirementListData::fromAgent($response->toArray(), $facts);
    }
}
