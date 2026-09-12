<?php

declare(strict_types=1);

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LexIA's own account: the tenant that administers all the others.
 *
 * It belongs in a migration rather than a seeder because the platform admins
 * hang off it, so production needs the row just as much as a dev machine.
 *
 * Written through the query builder, not the model: a migration has to keep
 * working when the model's casts, scopes or defaults change under it. Only the
 * id, name and type carry meaning — contact details and address are
 * placeholders for LexIA staff to fill in on the account screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->insertOrIgnore([
            'id' => Account::PLATFORM_ID,
            'name' => 'LexIA',
            'legal_name' => null,
            'type' => AccountType::Platform->value,
            'federal_id' => null,
            'oab_number' => null,
            'oab_state' => null,
            'email' => 'suporte@lexia.com.br',
            'phone' => '00000000000',
            'postal_code' => '00000000',
            'street' => 'A definir',
            'number' => 'S/N',
            'complement' => null,
            'district' => 'A definir',
            'city' => 'A definir',
            'state' => 'SP',
            'active' => true,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The users foreign key restricts on delete, so this fails loudly
        // while platform admins still exist — which is the right outcome.
        DB::table('accounts')->where('id', Account::PLATFORM_ID)->delete();
    }
};
