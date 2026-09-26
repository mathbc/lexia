<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LegalThemes\Data\LegalCaseThemeData;
use App\Domain\LegalThemes\Data\LegalCaseThemeListData;
use App\Domain\LegalThemes\Enums\LegalThemeType;
use App\Domain\LegalThemes\Models\LegalTheme;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two doors into the list of themes a pleading leans on — the agent's and
 * the browser's — and what each refuses without asking anyone.
 */
final class LegalCaseThemeListDataTest extends TestCase
{
    private const string UUID_A = '11111111-1111-4111-8111-111111111111';

    private const string UUID_B = '22222222-2222-4222-8222-222222222222';

    #[Test]
    public function the_agents_references_resolve_to_the_themes_it_was_shown(): void
    {
        $list = LegalCaseThemeListData::fromAgent([
            ['reference' => 'theme-952', 'reason' => '  O relato discute o reajuste.  '],
            ['reference' => 'puil-5', 'reason' => ''],
        ], $this->candidates());

        $this->assertEquals([
            new LegalCaseThemeData(self::UUID_A, 'O relato discute o reajuste.'),
            new LegalCaseThemeData(self::UUID_B, null),
        ], $list->themes);
    }

    /**
     * O `enum` já torna isto impossível de emitir; a lista não confia numa
     * garantia que mora noutro processo.
     */
    #[Test]
    public function an_unknown_or_repeated_reference_is_dropped(): void
    {
        $list = LegalCaseThemeListData::fromAgent([
            ['reference' => 'theme-952', 'reason' => 'Primeira.'],
            ['reference' => 'theme-9999', 'reason' => 'Inventada.'],
            ['reference' => 'theme-952', 'reason' => 'Repetida.'],
            'não é um objeto',
        ], $this->candidates());

        $this->assertEquals([new LegalCaseThemeData(self::UUID_A, 'Primeira.')], $list->themes);
    }

    #[Test]
    public function the_ceiling_holds_whatever_the_agent_returns(): void
    {
        $candidates = new Collection(array_map(
            fn (int $number): LegalTheme => $this->theme(sprintf('00000000-0000-4000-8000-%012d', $number), LegalThemeType::Theme, $number),
            range(1, 8),
        ));

        $list = LegalCaseThemeListData::fromAgent(
            array_map(static fn (int $number): array => ['reference' => "theme-{$number}", 'reason' => null], range(1, 8)),
            $candidates,
        );

        $this->assertCount(LegalCaseThemeListData::MAX_THEMES, $list->themes);
    }

    #[Test]
    public function the_posted_rows_become_the_list_the_lawyer_kept(): void
    {
        $list = LegalCaseThemeListData::fromArray(['themes' => [
            ['legal_theme_id' => self::UUID_A, 'reason' => 'Mantido.'],
            ['legal_theme_id' => '', 'reason' => 'Sem tema.'],
            ['legal_theme_id' => self::UUID_B, 'reason' => '   '],
        ]]);

        $this->assertEquals([
            new LegalCaseThemeData(self::UUID_A, 'Mantido.'),
            new LegalCaseThemeData(self::UUID_B, null),
        ], $list->themes);

        $this->assertSame([], LegalCaseThemeListData::fromArray([])->themes);
    }

    #[Test]
    public function the_sync_payload_carries_the_account_and_the_reason(): void
    {
        $list = new LegalCaseThemeListData([new LegalCaseThemeData(self::UUID_A, 'Mantido.')]);

        $this->assertSame(
            [self::UUID_A => ['account_id' => 'conta', 'reason' => 'Mantido.']],
            $list->toSync('conta'),
        );
    }

    /**
     * @return Collection<int, LegalTheme>
     */
    private function candidates(): Collection
    {
        return new Collection([
            $this->theme(self::UUID_A, LegalThemeType::Theme, 952),
            $this->theme(self::UUID_B, LegalThemeType::Puil, 5),
        ]);
    }

    private function theme(string $id, LegalThemeType $type, int $number): LegalTheme
    {
        return (new LegalTheme)->forceFill(['id' => $id, 'type' => $type, 'number' => $number]);
    }
}
