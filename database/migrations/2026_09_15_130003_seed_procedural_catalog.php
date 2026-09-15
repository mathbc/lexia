<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the CNJ procedural catalogue: 24 practice areas, 615 procedural
 * classes and the 1,237 links between them.
 *
 * A migration and not a seeder, like the platform account: production needs
 * the catalogue as much as a dev machine does, because no pleading can be
 * drafted without it. Written through the query builder and not the models for
 * the same reason — a migration has to keep working when their casts, scopes
 * and defaults change under it.
 *
 * Idempotent, so resyncing with the CNJ is: replace the two JSON files, add a
 * migration that runs this same routine. See database/data/README.md.
 */
return new class extends Migration
{
    /**
     * Postgres caps a statement at 65535 bound parameters and the class rows
     * carry 18 columns each, so this leaves a wide margin.
     */
    private const int CHUNK = 500;

    public function up(): void
    {
        // Classes first: the links need their ids.
        $this->loadClasses();
        $this->loadAreas();
    }

    public function down(): void
    {
        // legal_cases restricts on delete, so this fails loudly while a
        // pleading still cites the catalogue — which is the right outcome.
        DB::table('practice_area_procedural_class')->delete();
        DB::table('procedural_classes')->delete();
        DB::table('practice_areas')->delete();
    }

    private function loadClasses(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $class): array => [
                'id' => (string) Str::uuid(),
                'code' => (int) $class['code'],
                'name' => (string) $class['name'],
                'slug' => (string) $class['slug'],
                'root_code' => (int) $class['root_code'],
                'path' => $this->toJson($class['path']),
                'abbreviation' => $this->nullableString($class['abbreviation']),
                'nature' => $this->nullableString($class['nature']),
                'legal_norm' => $this->nullableString($class['legal_norm']),
                'legal_article' => $this->nullableString($class['legal_article']),
                'active_party' => $this->nullableString($class['active_party']),
                'passive_party' => $this->nullableString($class['passive_party']),
                'has_own_numbering' => (bool) $class['has_own_numbering'],
                'is_filing_class' => (bool) $class['is_filing_class'],
                'is_cross_cutting' => (bool) $class['is_cross_cutting'],
                'jurisdictions' => $this->toJson($class['jurisdictions']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->read('procedural-classes.json'),
        );

        // Keyed by the CNJ code, never by the uuid: on a resync the row keeps
        // the id it already has, so the pleadings pointing at it still resolve.
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('procedural_classes')->upsert($chunk, ['code'], [
                'name', 'slug', 'root_code', 'path', 'abbreviation', 'nature',
                'legal_norm', 'legal_article', 'active_party', 'passive_party',
                'has_own_numbering', 'is_filing_class', 'is_cross_cutting',
                'jurisdictions', 'updated_at',
            ]);
        }
    }

    private function loadAreas(): void
    {
        $areas = $this->read('practice-areas.json');
        $now = now();

        $rows = array_map(
            fn (array $area): array => [
                'id' => (string) Str::uuid(),
                'slug' => (string) $area['slug'],
                'label' => (string) $area['label'],
                'cnj_subject_roots' => $this->toJson($area['cnj_subject_roots']),
                'position' => (int) $area['position'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $areas,
        );

        DB::table('practice_areas')->upsert($rows, ['slug'], [
            'label', 'cnj_subject_roots', 'position', 'updated_at',
        ]);

        $this->linkAreasToClasses($areas);
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function linkAreasToClasses(array $areas): void
    {
        // Read back rather than reused from the arrays above: an upsert leaves
        // pre-existing rows with the ids they already had.
        $areaIds = DB::table('practice_areas')->pluck('id', 'slug')->all();
        $classIds = DB::table('procedural_classes')->pluck('id', 'code')->all();

        $links = [];

        foreach ($areas as $area) {
            /** @var list<array<string, mixed>> $classes */
            $classes = $area['classes'];

            foreach ($classes as $class) {
                $links[] = [
                    'practice_area_id' => $areaIds[(string) $area['slug']],
                    'procedural_class_id' => $classIds[(int) $class['code']],
                    'scope' => (string) $class['scope'],
                ];
            }
        }

        // Rebuilt wholesale instead of upserted: a resync may drop a link, and
        // an upsert would leave the stale one in place.
        DB::table('practice_area_procedural_class')->delete();

        foreach (array_chunk($links, self::CHUNK) as $chunk) {
            DB::table('practice_area_procedural_class')->insert($chunk);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $file): array
    {
        $path = database_path("data/{$file}");
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Catálogo processual ausente: {$path}.");
        }

        /** @var list<array<string, mixed>> */
        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function toJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
};
