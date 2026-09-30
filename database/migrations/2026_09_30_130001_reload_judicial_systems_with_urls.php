<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reloads the judicial systems map with the address of each court's instance.
 *
 * The update protocol database/data/README.md describes: edit the JSON and add
 * a migration that runs the same routine. It is that routine, with `url`
 * written beside the status — and so, from here on, **the** load: the next
 * update of the map copies this file, not the first one, which would rebuild
 * the links without their addresses.
 *
 * Through the query builder and not the models, for the reason the original
 * load gives: a migration has to keep working when their casts change under it.
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
        // Nothing to go back to: the links before this load were the same rows
        // without an address, and rolling back the schema migration drops the
        // column, which is the real rollback.
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
                    'url' => isset($system['url']) ? (string) $system['url'] : null,
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
