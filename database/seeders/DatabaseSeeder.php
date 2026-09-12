<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\Models\Account;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data: one law firm with a full role spread, one solo
 * practitioner, and a LexIA staff account.
 *
 * Every user shares the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $firm = Account::factory()->lawFirm()->create([
            'name' => 'Almeida & Souza Advogados',
            'legal_name' => 'Almeida & Souza Sociedade de Advogados Ltda.',
            'email' => 'contato@almeidasouza.adv.br',
        ]);

        User::factory()->forAccount($firm)->accountAdmin()->create([
            'name' => 'Renata Almeida',
            'email' => 'admin@lexia.test',
        ]);

        User::factory()->forAccount($firm)->admin()->create([
            'name' => 'Carlos Souza',
            'email' => 'gestor@lexia.test',
        ]);

        User::factory()->forAccount($firm)->count(6)->create();

        User::factory()->forAccount($firm)->disabled()->create([
            'name' => 'Ex-colaborador',
            'email' => 'desabilitado@lexia.test',
        ]);

        $solo = Account::factory()->create([
            'name' => 'Mariana Duarte',
            'email' => 'mariana@duarte.adv.br',
            'oab_number' => '184320',
            'oab_state' => 'SP',
        ]);

        User::factory()->forAccount($solo)->accountAdmin()->create([
            'name' => 'Mariana Duarte',
            'email' => 'mariana@lexia.test',
            'type' => UserType::Lawyer,
        ]);

        // LexIA staff: crosses the tenant boundary, so it lives on its own
        // account rather than inside a customer's.
        $platform = Account::factory()->create([
            'name' => 'LexIA',
            'email' => 'suporte@lexia.com.br',
        ]);

        User::factory()->forAccount($platform)->platformAdmin()->create([
            'name' => 'Suporte LexIA',
            'email' => 'suporte@lexia.test',
        ]);

        $this->command?->info('Seed pronto. Senha de todos os usuários: password');
    }
}
