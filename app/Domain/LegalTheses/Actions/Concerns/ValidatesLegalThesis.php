<?php

declare(strict_types=1);

namespace App\Domain\LegalTheses\Actions\Concerns;

use App\Domain\LegalTheses\Enums\LegalBasisType;
use App\Domain\LegalTheses\Enums\LegalThesisType;
use Illuminate\Validation\Rule;

/**
 * Validation shared by registering a thesis by hand and correcting it.
 *
 * Stricter than SaveLegalCaseForensicReview on purpose. That one saves a step
 * partially and receives what the research found, where a missing kind is a real
 * state; this one receives what a lawyer typed into a modal, and a lawyer writing
 * an argument knows whether it is a preliminary or the merits — the drafting
 * agent reads the kind to decide how the section opens. So the heading, the kind
 * and the argument are required, and a basis without a reference is refused
 * instead of silently dropped: a row the lawyer filled half of is a mistake worth
 * pointing at, where the step's save drops blank rows the research never wrote.
 *
 * The two limits on a basis are the step's own, so a thesis written here is
 * always one the "Concluir" accepts back.
 */
trait ValidatesLegalThesis
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(LegalThesisType::class)],
            'description' => ['required', 'string'],
            'impact' => ['nullable', 'string'],
            'legal_bases' => ['nullable', 'array', 'max:20'],
            'legal_bases.*.type' => ['nullable', Rule::enum(LegalBasisType::class)],
            'legal_bases.*.reference' => ['required', 'string', 'max:255'],
            'legal_bases.*.source' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'name' => 'título',
            'type' => 'tipo da tese',
            'description' => 'argumento',
            'impact' => 'o que o cliente ganha',
            'legal_bases' => 'fundamentação',
            'legal_bases.*.type' => 'tipo do fundamento',
            'legal_bases.*.reference' => 'referência',
            'legal_bases.*.source' => 'fonte',
        ];
    }
}
