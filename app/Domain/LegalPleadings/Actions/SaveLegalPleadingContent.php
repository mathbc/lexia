<?php

declare(strict_types=1);

namespace App\Domain\LegalPleadings\Actions;

use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Save the lawyer's edits to the drafted document.
 *
 * **No agent runs here.** Editing a paragraph is not a reason to spend a
 * `Timeout(360)` and several minutes regenerating a document the lawyer was in
 * the middle of correcting — and it would throw their correction away to do it.
 * The agent writes once, when the sixth step is concluded; everything after that
 * is typing.
 *
 * What a save produces is a **new version** rather than an update in place, so
 * the text the agent wrote stays beside the text the lawyer settled on and a
 * paragraph deleted by accident is still somewhere. StoreLegalPleadingVersion is
 * where that numbering lives, along with the rule that identical text writes
 * nothing at all.
 *
 * Note there is no `pleading` in the route and none in the payload. A save is
 * always about the current text of a pleading, never about a version by id:
 * accepting one would be offering to write version 5 on top of version 3, which
 * is a branch, and a branch is a feature nobody has asked for. The URL names the
 * `LegalCase`, and the LegalCasePolicy is the defence — the same arrangement the
 * theses and the requests already use.
 */
final class SaveLegalPleadingContent
{
    use AsAction;

    /**
     * Null when the text is unchanged — see StoreLegalPleadingVersion.
     */
    public function handle(LegalCase $legalCase, string $content): ?LegalPleading
    {
        return StoreLegalPleadingVersion::run($legalCase, $content);
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('update', $request->route('legalCase'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return ['content' => 'conteúdo da minuta'];
    }

    public function asController(LegalCase $legalCase, ActionRequest $request): RedirectResponse
    {
        $this->handle($legalCase, $request->string('content')->toString());

        // O mesmo destino com ou sem gravação: um texto que não mudou deixa a
        // tela exatamente onde estava, que é o que ela já mostrava.
        return to_route('legal-cases.pleading', ['legalCase' => $legalCase]);
    }
}
