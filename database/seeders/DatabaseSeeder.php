<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deliberately empty.
 *
 * Nothing the application needs comes from a seeder. The platform account and
 * the catalogues arrive by migration, and the first administrator and the AI
 * models by `php artisan lexia:install` — Laravel Cloud does not run
 * `db:seed`, and a seeder built on factories would need the faker that a
 * production install does not ship. The class stays so that `db:seed` and
 * `migrate --seed` still have something to call.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Nada a semear: rode `php artisan lexia:install`.');
    }
}
