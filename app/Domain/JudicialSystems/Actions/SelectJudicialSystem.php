<?php

declare(strict_types=1);

namespace App\Domain\JudicialSystems\Actions;

use App\Ai\Agents\JudicialSystemSelectionAgent;
use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\JudicialSystems\Data\JudicialSystemSelection;
use App\Domain\JudicialSystems\Enums\AdoptionStatus;
use App\Domain\JudicialSystems\Models\JudicialSystemCourt;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Find the electronic system a pleading is filed through, once the state of
 * its forum is known.
 *
 * The map answers most of the time and the agent only when it cannot. The
 * candidates are the rows of `judicial_system_courts` for the forum's state:
 * one in 23 of the 27 states, and then the answer is that row, with no
 * inference. Two in the other four, and JudicialSystemSelectionAgent chooses
 * between them — the same arrangement as the forensic review's reinforcement,
 * which is not called when the pleading has no thesis to reinforce.
 *
 * The map covers the state courts only, so the caller asks for the Justiça
 * Estadual alone; a federal or labour forum never reaches here.
 *
 * A failed choice **throws**. The caller decides what a missing system costs,
 * and for the addressing it costs the select, never the addressing.
 */
final class SelectJudicialSystem
{
    use AsAction;

    /**
     * @param  string  $forum  the forum already placed, as the agent reads it
     * @param  list<string>  $parties  the parties, reduced to what decides competence
     */
    public function handle(
        BrazilianState $state,
        string $forum,
        string $areaLabel,
        ?string $classLine,
        array $parties,
        string $facts,
    ): ?JudicialSystemSelection {
        $courts = self::candidates($state);

        if ($courts->count() < 2) {
            $court = $courts->first();

            return $court === null ? null : JudicialSystemSelection::fromMap($court);
        }

        $response = (new JudicialSystemSelectionAgent(
            forum: $forum,
            candidates: $courts->map(static fn (JudicialSystemCourt $court): array => [
                'slug' => $court->judicialSystem->slug,
                'line' => self::line($court),
            ])->values()->all(),
            areaLabel: $areaLabel,
            classLine: $classLine,
            parties: $parties,
        ))->prompt($facts);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('O agente de sistema judicial não devolveu uma resposta estruturada.');
        }

        $answer = $response->toArray();
        $chosen = $courts->first(static fn (JudicialSystemCourt $court): bool => $court->judicialSystem->slug === ($answer['system'] ?? null));

        return $chosen === null
            ? throw new RuntimeException('O agente de sistema judicial escolheu um sistema fora das candidatas.')
            : JudicialSystemSelection::fromAgent($chosen, trim((string) ($answer['justification'] ?? '')));
    }

    /**
     * The rows for one state, the one in use first and then in the map's own
     * order — which is how many courts run each system.
     *
     * @return Collection<int, JudicialSystemCourt>
     */
    private static function candidates(BrazilianState $state): Collection
    {
        return JudicialSystemCourt::query()
            ->with('judicialSystem')
            ->where('state', $state->value)
            ->get()
            ->sortBy(static fn (JudicialSystemCourt $court): array => [
                $court->status === AdoptionStatus::Active ? 0 : 1,
                $court->judicialSystem->position,
            ])
            ->values();
    }

    /** "`eproc` — eproc (TJSP, em uso)". */
    private static function line(JudicialSystemCourt $court): string
    {
        $status = mb_strtolower($court->status->label());

        return "`{$court->judicialSystem->slug}` — {$court->judicialSystem->name} ({$court->court}, {$status})";
    }
}
