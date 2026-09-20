<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\LegalPleadings\Models\LegalPleading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalPleading>
 */
class LegalPleadingFactory extends Factory
{
    protected $model = LegalPleading::class;

    /**
     * A drafted document, shortened but shaped like one.
     *
     * Verbatim rather than `fake()->paragraphs()`, for the reason the sibling
     * factories give: a screen whose whole job is showing what a petição inicial
     * looks like is not tested by lorem ipsum. The bracketed gaps are here on
     * purpose — they are what `placeholders()` reads, and a draft with no gap at
     * all is the uncommon case, not the common one.
     */
    private const string CONTENT = <<<'TXT'
    EXCELENTÍSSIMO SENHOR DOUTOR JUIZ DE DIREITO DA VARA CÍVEL DA COMARCA DE [CIDADE/UF]

    [Nome do Autor], brasileiro, [estado civil], [profissão], portador do CPF nº
    [CPF], residente e domiciliado em [Endereço Completo], por intermédio de seu
    advogado infra-assinado, vem, respeitosamente, à presença de Vossa Excelência,
    propor a presente AÇÃO DE INDENIZAÇÃO POR DANOS MATERIAIS E MORAIS em face de
    [Nome do Réu], pelos fatos e fundamentos a seguir expostos.

    I – DOS FATOS

    Os fatos narrados pelo Autor dão conta de que a conduta da parte contrária lhe
    causou prejuízo material e abalo de ordem moral, na forma adiante demonstrada.

    II – DO DIREITO

    A responsabilidade civil do Réu é subjetiva, fundamentada nos arts. 186 e 927 do
    Código Civil.

    III – DOS PEDIDOS E REQUERIMENTOS

    Ante o exposto, requer:

    1. A citação do Réu para, querendo, contestar a ação no prazo legal, sob pena de
    revelia;
    2. A procedência total dos pedidos.

    Protesta provar o alegado por todos os meios de prova em direito admitidos.

    Dá-se à causa o valor de [valor da causa].

    Nestes termos, pede deferimento.
    TXT;

    /**
     * The pleading is created inside the draft's own account rather than through
     * a bare LegalCase::factory(), which would put it in a second one and produce
     * a row no use case could ever have written.
     *
     * `version` starts at 1 because that is what the first draft is; a factory
     * that counted rows would be guessing about a table it did not write.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'legal_case_id' => fn (array $attributes): string => LegalCase::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->id,
            'content' => self::CONTENT,
            'version' => 1,
        ];
    }

    /**
     * Attach to a pleading that already exists; the account comes with it.
     */
    public function forLegalCase(LegalCase $legalCase): static
    {
        return $this->state(fn (): array => [
            'account_id' => $legalCase->account_id,
            'legal_case_id' => $legalCase->id,
        ]);
    }

    /**
     * A given version, for a test about the numbering rather than the text.
     */
    public function version(int $version): static
    {
        return $this->state(fn (): array => [
            'version' => $version,
        ]);
    }

    /**
     * A draft with the given text, for a test about what the lawyer wrote.
     */
    public function withContent(string $content): static
    {
        return $this->state(fn (): array => [
            'content' => $content,
        ]);
    }
}
