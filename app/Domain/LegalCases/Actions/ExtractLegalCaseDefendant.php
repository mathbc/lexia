<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\DefendantExtractionAgent;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\LegalCases\Data\DefendantData;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Read the facts of a matter and describe the other party out of them.
 *
 * A component on its own, and still one after being wired in: ClassifyLegalCase
 * appends it to the framing, so the preenchimento inteligente opens the wizard
 * with the defendant already suggested. It is appended rather than chained —
 * nothing here needs the area or the class, and the screen that wants only a
 * defendant calls this Action directly and pays one inference instead of three.
 *
 * The caller absorbs a failure here rather than propagating it: a suggestion
 * that did not arrive is not a reason to discard a framing that did.
 *
 * Returns `DefendantData` rather than a data object of its own, and that is the
 * point of the shape: the same object `UpdateLegalCaseDefendant::handle()`
 * already takes, so a suggestion the lawyer accepts is written by the code that
 * writes the form. `toArray()` publishes the twelve `defendant_*` keys of
 * `legal_cases`, which is also the JSON a screen would receive.
 *
 * `fromArray()` is what makes the agent's answer safe to store: it trims, drops
 * the empty strings, lowercases the e-mail and strips the masks a model will
 * happily emit — "11.222.333/0001-81" and "(47) 3322-1188" reach the columns as
 * digits, the single representation the rest of the system reads back. The UF
 * is resolved through `BrazilianState` — the same list the agent was
 * constrained to, which is why the resolution cannot miss.
 *
 * No `asController()` while no route points here, exactly as its siblings.
 */
final class ExtractLegalCaseDefendant
{
    use AsAction;

    public function handle(string $facts): DefendantData
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para analisar.');
        }

        $response = (new DefendantExtractionAgent(
            states: array_column(BrazilianState::cases(), 'value'),
        ))->prompt($facts);

        // The SDK returns the structured subclass for every agent declaring
        // `HasStructuredOutput`, so this narrowing never fires in practice —
        // it fires when the integration is broken, which is the same category
        // of failure the sibling Actions raise on an unknown slug or code.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente do réu não devolveu uma resposta estruturada.');
        }

        // Every key is in the schema as `required`, so the grammar guarantees
        // all twelve arrive — nulls included. `fromArray()` reads them by name,
        // which is why the schema spells them the way the table does.
        return DefendantData::fromArray($response->toArray());
    }
}
