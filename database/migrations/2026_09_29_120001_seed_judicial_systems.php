<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the judicial systems map: 5 systems and the 31 links to the 27 state
 * courts that file through them.
 *
 * A migration and not a seeder, like the procedural catalogue: production
 * needs the map as much as a dev machine does, since the first step of a
 * pleading offers it. Written through the query builder and not the models for
 * the same reason — a migration has to keep working when their casts change
 * under it.
 *
 * Idempotent, so updating the map is: edit database/data/judicial-systems.json
 * and add a migration that runs this same routine. See database/data/README.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $data = $this->read('judicial-systems.json');

        $this->loadSystems($data['systems']);
        $this->loadCourts($data['courts']);
    }

    public function down(): void
    {
        // legal_cases restricts on delete, so this fails loudly while a
        // pleading still cites a system — which is the right outcome.
        DB::table('judicial_system_courts')->delete();
        DB::table('judicial_systems')->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $systems
     */
    private function loadSystems(array $systems): void
    {
        $now = now();

        $rows = array_map(
            fn (array $system): array => [
                'id' => (string) Str::uuid(),
                'slug' => (string) $system['slug'],
                'name' => (string) $system['name'],
                'position' => (int) $system['position'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $systems,
        );

        // Keyed by the slug, never by the uuid: on a resync the row keeps the
        // id it already has, so the pleadings pointing at it still resolve.
        DB::table('judicial_systems')->upsert($rows, ['slug'], [
            'name', 'position', 'updated_at',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $courts
     */
    private function loadCourts(array $courts): void
    {
        // Read back rather than reused from the rows above: an upsert leaves
        // pre-existing rows with the ids they already had.
        $systemIds = DB::table('judicial_systems')->pluck('id', 'slug')->all();
        $now = now();

        $rows = [];

        foreach ($courts as $court) {
            /** @var list<array<string, mixed>> $systems */
            $systems = $court['systems'];

            foreach ($systems as $system) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'judicial_system_id' => $systemIds[(string) $system['system']],
                    'state' => (string) $court['state'],
                    'court' => (string) $court['court'],
                    'status' => (string) $system['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Rebuilt wholesale instead of upserted: a court that leaves a system
        // drops out of the file, and an upsert would leave the stale link.
        DB::table('judicial_system_courts')->delete();
        DB::table('judicial_system_courts')->insert($rows);
    }

    /**
     * @return array{systems: list<array<string, mixed>>, courts: list<array<string, mixed>>}
     */
    private function read(string $file): array
    {
        $path = database_path("data/{$file}");
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Mapa de sistemas judiciais ausente: {$path}.");
        }

        /** @var array{systems: list<array<string, mixed>>, courts: list<array<string, mixed>>} */
        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
};
