<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\Models\Account;
use App\Domain\Customers\Models\Customer;
use App\Domain\Users\Enums\UserType;
use App\Domain\Users\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development data: one law firm with a full role spread, one solo
 * practitioner, and a LexIA staff user in the platform account.
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

        // A carteira de clientes do escritório: pessoas e empresas.
        Customer::factory()->forAccount($firm)->count(8)->create();
        Customer::factory()->forAccount($firm)->company()->count(4)->create();

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

        Customer::factory()->forAccount($solo)->count(3)->create();

        // LexIA staff. The platform account itself is not seeded: it is created
        // by migration, because production needs it too.
        User::factory()->platformAdmin()->create([
            'name' => 'Suporte LexIA',
            'email' => 'suporte@lexia.test',
        ]);

        $this->command->info('Seed pronto. Senha de todos os usuários: password');
    }
}
