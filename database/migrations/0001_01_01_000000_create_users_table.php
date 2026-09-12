<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Every user belongs to exactly one account. Restrict rather than
            // cascade: deleting a tenant with live users should be a deliberate
            // act, not a side effect.
            $table->foreignUuid('account_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            $table->string('oab_number', 20)->nullable();
            $table->char('oab_state', 2)->nullable();
            $table->date('birth_date')->nullable();

            // The role is the only source of authority: `platform_admin` is a
            // UserRole case, not a second flag that could disagree with it.
            $table->string('role', 20);
            $table->string('type', 20);

            $table->boolean('enabled')->default(true);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'role']);
            $table->index(['account_id', 'enabled']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            // Stock Laravel declares this as a bigint foreignId. With UUID user
            // keys that mismatch silently breaks every authenticated session.
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
