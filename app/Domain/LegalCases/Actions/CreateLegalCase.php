<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Actions\Concerns\ValidatesLegalCaseBasics;
use App\Domain\LegalCases\Data\LegalCaseBasicsData;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Opens a pleading, at the close of the form's first step.
 *
 * The row is born here and is finished nowhere: the assembly form saves one
 * step at a time, so what exists after this is a draft standing on the second
 * step, with a client, a heading and nothing else. That is the point of saving
 * this early — from here on the work survives closing the tab.
 *
 * `is_draft` is left to the column's default rather than set: every pleading is
 * a draft today, and writing it out here would suggest this Action had a say.
 *
 * O relato é o único campo de outra etapa que entra aqui, e é opcional. Ele vem
 * do preenchimento inteligente: lá o advogado escreve os fatos antes de existir
 * peça, e foram eles que produziram a área e a classe que o assistente já abre
 * escolhidas. Sem gravá-los neste mesmo passo o texto morreria na navegação que
 * a criação provoca, e a etapa de fatos abriria em branco depois de escrita.
 * `current_step` não se mexe por causa disso: a marca d'água diz até onde o
 * advogado andou, e ele continua devendo as etapas do meio.
 */
final class CreateLegalCase
{
    use AsAction;
    use ValidatesLegalCaseBasics;

    public function handle(string $accountId, LegalCaseBasicsData $data, ?string $facts = null): LegalCase
    {
        $legalCase = new LegalCase($data->toArray());

        // Explicit rather than left to BelongsToAccount: the account is an
        // argument of this use case, so it is set where it can be seen.
        $legalCase->account_id = $accountId;

        $legalCase->facts = $facts;

        // The first step is done, so the pleading now stands on the second.
        $legalCase->current_step = LegalCaseStep::Basics->next();

        $legalCase->save();

        return $legalCase;
    }

    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(ActionRequest $request): array
    {
        return $this->basicsRules(
            $request->user()->account_id,
            $request->string('practice_area')->toString(),
        ) + [
            // Nunca preenchido pelo caminho manual, onde a etapa 3 é quem o
            // salva; chega aqui só quando a peça vem do relato.
            'facts' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return $this->basicsAttributes() + ['facts' => 'fatos'];
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $legalCase = $this->handle(
            $request->user()->account_id,
            $this->basicsData($request->validated()),
            // ConvertEmptyStringsToNull já cuida do caminho manual, que manda
            // a caixa vazia; o trim é para o relato que só tem espaço.
            $request->string('facts')->trim()->value() ?: null,
        );

        return to_route('legal-cases.edit', [
            'legalCase' => $legalCase,
            'etapa' => $legalCase->current_step->value,
        ]);
    }
}
