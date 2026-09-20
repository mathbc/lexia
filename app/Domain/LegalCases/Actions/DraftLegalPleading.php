<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\PleadingDraftingAgent;
use App\Domain\LegalCases\Data\PleadingDraftData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalPleadings\Actions\StoreLegalPleadingVersion;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingSignature;
use App\Domain\Requirements\Models\Requirement;
use App\Domain\Users\Models\User;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Write the petição inicial for a pleading, and store it as its next version.
 *
 * Takes a `LegalCase` and not a string, like RefineLegalCaseFacts and unlike the
 * four Actions that only read a narrative: what is being written is a document
 * *of a pleading*, and every part of the pleading changes it — the class decides
 * what the action is called, the parties decide the opening paragraph, the
 * theses decide the DO DIREITO section, and the requests are copied out
 * verbatim and numbered.
 *
 * `$author` is the lawyer who signs. Optional rather than required, because the
 * signature is the only thing here that needs one and a missing author is a
 * legitimate state: the block comes out with `[Nome do advogado]` and `[OAB]`,
 * which is the same answer the agent gives for everything else it does not know.
 * A queued run has no actor at all, and this is what lets one exist.
 *
 * Two things happen after the agent answers, and neither is the agent's to do:
 *
 * 1. **The signature is appended**, composed by PleadingSignature. The reason it
 *    is not written by the model is in that class.
 * 2. **The row is written by StoreLegalPleadingVersion**, which is also what the
 *    lawyer's own edits go through — so the version numbering has exactly one
 *    implementation whether the text came from a model or from a keyboard.
 *
 * No `asController()`: nothing routes here directly. FinalizeLegalCase calls it
 * when the sixth step is concluded, and GenerateLegalPleading calls it when that
 * attempt failed and the lawyer asks again.
 */
final class DraftLegalPleading
{
    use AsAction;

    public function handle(LegalCase $legalCase, ?User $author = null): LegalPleading
    {
        $narrative = trim((string) $legalCase->facts);

        if ($narrative === '') {
            throw new RuntimeException('Não há fatos para redigir a minuta.');
        }

        // O dossiê de redação lê seis relações; quem chegou por route-model
        // binding não tem nenhuma carregada e um teste que montou a peça à mão
        // tem todas. `loadMissing` é o que faz os dois custarem o mesmo.
        $legalCase->loadMissing([
            'account',
            'customer',
            'practiceArea',
            'proceduralClass',
            'requirements',
            'theses',
            'documents',
        ]);

        $response = (new PleadingDraftingAgent(
            dossier: LegalCaseDossier::forDrafting($legalCase),
        ))->prompt($narrative);

        // O SDK devolve a subclasse estruturada para todo agente que declara
        // `HasStructuredOutput`, então este estreitamento nunca dispara na
        // prática — ele dispara quando a integração quebrou.
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de minuta não devolveu uma resposta estruturada.');
        }

        // O relato e os pedidos viajam juntos porque são eles que autorizam cada
        // cifra. Diferente do agente de fatos, aqui a fonte não é só a narrativa:
        // um valor que o advogado já escreveu num pedido é tão legítimo quanto um
        // que o cliente contou — ver PleadingDraftData.
        $draft = PleadingDraftData::fromAgent(
            $response->toArray(),
            $this->sources($legalCase, $narrative),
        );

        // A gramática garante a chave, nunca as páginas. Uma minuta vazia é a
        // única resposta que não pode ser gravada: viraria uma versão em branco
        // por cima do trabalho de quem esperou minutos por ela.
        if (! $draft->isWritten()) {
            throw new RuntimeException('O agente devolveu uma minuta vazia.');
        }

        $stored = StoreLegalPleadingVersion::run(
            $legalCase,
            $draft->content.PHP_EOL.PHP_EOL.PleadingSignature::for($legalCase, $author),
        );

        // Null ali quer dizer uma coisa só: o texto saiu idêntico ao da última
        // versão, e não há o que gravar. É improvável — a assinatura carrega a
        // data — mas não impossível, e a resposta certa é a linha que já existe,
        // porque foi ela que a gravação decidiu manter. Uma peça sem versão
        // nenhuma não chega aqui: o conteúdo não é vazio, então a linha nasceu.
        return $stored ?? $legalCase->pleadings()->firstOrFail();
    }

    /**
     * Everything that is allowed to put a figure in the document.
     *
     * The narrative, plus the amounts the lawyer themselves wrote into the
     * requests — those are in the `amount` column in decimal notation, and the
     * guard parses both sides through one reader, so "50000.00" here matches
     * "R$ 50.000,00" there.
     */
    private function sources(LegalCase $legalCase, string $narrative): string
    {
        $claimed = $legalCase->requirements
            ->map(static fn (Requirement $requirement): string => $requirement->amount === null
                ? ''
                : 'R$ '.$requirement->amount)
            ->filter()
            ->implode(' ');

        return $narrative.' '.$claimed;
    }
}
