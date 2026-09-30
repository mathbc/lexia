<?php

declare(strict_types=1);

namespace App\Domain\JudicialSystems\Data;

use App\Domain\JudicialSystems\Enums\AdoptionStatus;
use App\Domain\JudicialSystems\Models\JudicialSystem;
use App\Domain\JudicialSystems\Models\JudicialSystemCourt;

/**
 * The electronic system a pleading is filed through, and how it was reached.
 *
 * Two ways, and `source` says which. Most state courts run one system in the
 * map, and the answer is read off it (`map`) with no inference at all. Four run
 * two — São Paulo, Rio Grande do Norte, Roraima, Amapá — and there the
 * JudicialSystemSelectionAgent picks one (`ai`), with the justification the
 * lawyer reads before trusting it.
 *
 * The court's status travels along because it is the caveat: a system "em
 * transição" is the one the court is moving to, and the comarca may not have
 * moved yet.
 */
final readonly class JudicialSystemSelection
{
    public const string FROM_MAP = 'map';

    public const string FROM_AGENT = 'ai';

    /**
     * @param  'map'|'ai'  $source
     */
    public function __construct(
        public JudicialSystem $system,
        public string $court,
        public AdoptionStatus $status,
        public string $source,
        public ?string $justification,
    ) {}

    public static function fromMap(JudicialSystemCourt $court): self
    {
        return new self($court->judicialSystem, $court->court, $court->status, self::FROM_MAP, null);
    }

    public static function fromAgent(JudicialSystemCourt $court, string $justification): self
    {
        return new self($court->judicialSystem, $court->court, $court->status, self::FROM_AGENT, $justification);
    }

    /**
     * The uuid is here to be written to `legal_cases.judicial_system_id` and
     * nothing else; the slug is what survives a resync of the map.
     *
     * @return array{id: string, slug: string, name: string, court: string, status: string, status_label: string, source: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->system->id,
            'slug' => $this->system->slug,
            'name' => $this->system->name,
            'court' => $this->court,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source,
        ];
    }
}
