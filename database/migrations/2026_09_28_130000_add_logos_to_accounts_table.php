<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account's logos: one for the light theme, one for the dark.
 *
 * The columns hold a path on the private disk, not the image. Base64 in the
 * row was the alternative, and it loses on every count that matters here: it
 * is a third larger than the file, it rides along on every query that loads an
 * account — and the account is loaded on every request, for the tenant
 * boundary —, it reaches the browser inside a JSON prop that no cache can keep
 * between pages, and the PDF and DOCX exporters want a file to read anyway. A
 * file behind a URL of its own is fetched once and then comes from the cache.
 *
 * The files live under `accounts/{id}/` on the `local` disk, so everything an
 * account stores sits in one folder named after it. Private rather than
 * public: a public URL skips the AccountPolicy, and ShowAccountLogo is what
 * keeps the tenant boundary on the image too.
 *
 * Both nullable, and independent: a logo is optional, and the dark one does
 * not require the light one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'logo_dark_path']);
        });
    }
};
