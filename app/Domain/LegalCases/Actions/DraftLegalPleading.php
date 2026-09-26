<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Ai\Agents\PleadingDraftingAgent;
use App\Domain\CourtDecisions\Models\CourtDecision;
use App\Domain\LegalCases\Data\PleadingDraftData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalCases\Support\LegalCaseDossier;
use App\Domain\LegalPleadings\Actions\StoreLegalPleadingVersion;
use App\Domain\LegalPleadings\Models\LegalPleading;
use App\Domain\LegalPleadings\Support\PleadingJurisprudence;
use App\Domain\LegalPleadings\Support\PleadingSignature;
use App\Domain\Requirements\Models\Requirement;
use App\Domain\Users\Models\User;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

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
 * The rulings are the ones the seventh step kept, read here and not passed in:
 * FinalizeLegalCase deletes what the lawyer unticked before calling this, so the
 * relation *is* the list they chose, and the dossier and the quotation both
 * number that one list.
 *
 * `$author` is the lawyer who signs. Optional rather than required, because the
 * signature is the only thing here that needs one and a missing author is a
 * legitimate state: the block comes out with `[Nome do advogado]` and `[OAB]`,
 * which is the same answer the agent gives for everything else it does not know.
 * A queued run has no actor at all, and this is what lets one exist.
 *
 * Four things happen after the agent answers, and none is the drafting agent's
 * to do:
 *
 * 1. **The argument is reinforced**, by ReinforcePleadingGrounds: a second agent
 *    rewrites the body of DO DIREITO from the theses of the forensic review. It
 *    is the only one of the four that can fail on its own, and when it does the
 *    draft keeps the section the drafting agent wrote — reported, never fatal,
 *    because a weaker argument is a better answer than no pleading.
 * 2. **The rulings are quoted**, by PleadingJurisprudence: each `[[JULGADO n]]`
 *    the agent placed becomes the ementa and its reference, copied from the
 *    LexML record and indented — abridged to the passages the agent chose by
 *    number, with `[...]` where the court's text was cut. The reason the model
 *    places and chooses but never writes is in that class.
 * 3. **The signature is appended**, composed by PleadingSignature. The reason it
 *    is not written by the model is in that class.
 * 4. **The row is written by StoreLegalPleadingVersion**, which is also what the
 *    lawyer's own edits go through — so the version numbering has exactly one
 *    implementation whether the text came from a model or from a keyboard.
 *
 * No `asController()`: nothing routes here directly. FinalizeLegalCase calls it
 * when the last step is concluded, and GenerateLegalPleading calls it when that
 * attempt failed or the lawyer asks for the document again — in both cases the
 * result is the next version, never a rewrite of the current one.
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

        // O dossiê de redação, a citação e a assinatura leem oito relações; quem
        // chegou por route-model binding não tem nenhuma carregada e um teste que
        // montou a peça à mão tem todas. `loadMissing` é o que faz os dois
        // custarem o mesmo.
        $legalCase->loadMissing([
            'account',
            'customer',
            'practiceArea',
            'proceduralClass',
            'requirements',
            'theses',
            'courtDecisions',
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

        $content = $this->reinforced($legalCase, $draft->content);

        // A guarda de cifra acima leu só o que o modelo escreveu; a ementa entra
        // depois, porque não é dele — é cópia do registro, feita em PHP. Os
        // trechos que ele escolheu, por número, só abreviam a cópia.
        $stored = StoreLegalPleadingVersion::run(
            $legalCase,
            PleadingJurisprudence::expand($content, $legalCase->courtDecisions, $draft->excerpts)
                .PHP_EOL.PHP_EOL.PleadingSignature::for($legalCase, $author),
        );

        // Null ali quer dizer uma coisa só: o texto saiu idêntico ao da última
        // versão, e não há o que gravar. É improvável — a assinatura carrega a
        // data — mas não impossível, e a resposta certa é a linha que já existe,
        // porque foi ela que a gravação decidiu manter. Uma peça sem versão
        // nenhuma não chega aqui: o conteúdo não é vazio, então a linha nasceu.
        return $stored ?? $legalCase->pleadings()->firstOrFail();
    }

    /**
     * The draft with its DO DIREITO reinforced, or the draft as it came when the
     * reinforcement fails.
     *
     * The same shape as the drafting call in FinalizeLegalCase, one level down:
     * what can fail on its own is reported and does not take the rest with it.
     * The draft already passed the drafting agent's rules, so falling back to it
     * loses the second pass and nothing else.
     */
    private function reinforced(LegalCase $legalCase, string $draft): string
    {
        try {
            return ReinforcePleadingGrounds::run($legalCase, $draft);
        } catch (Throwable $e) {
            report($e);

            return $draft;
        }
    }

    /**
     * Everything that is allowed to put a figure in the document.
     *
     * The narrative, plus the amounts the lawyer themselves wrote into the
     * requests — those are in the `amount` column in decimal notation, and the
     * guard parses both sides through one reader, so "50000.00" here matches
     * "R$ 50.000,00" there.
     *
     * And the ementas of the rulings about to be quoted. A sentence that
     * introduces a ruling may repeat the figure it fixed — "que manteve a
     * indenização de R$ 8.000,00" — and that figure is the court's, written in
     * the record, not one the model composed.
     */
    private function sources(LegalCase $legalCase, string $narrative): string
    {
        $claimed = $legalCase->requirements
            ->map(static fn (Requirement $requirement): string => $requirement->amount === null
                ? ''
                : 'R$ '.$requirement->amount)
            ->filter()
            ->implode(' ');

        $quoted = $legalCase->courtDecisions
            ->map(static fn (CourtDecision $decision): string => PleadingJurisprudence::ementa($decision->summary))
            ->implode(' ');

        return $narrative.' '.$claimed.' '.$quoted;
    }
}
