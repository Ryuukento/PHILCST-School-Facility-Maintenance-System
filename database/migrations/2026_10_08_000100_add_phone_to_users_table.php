<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator Need Change SMS alert — adds an optional mobile number to
 * `users` so SmsService has somewhere to read a recipient's phone number
 * from. Nullable and additive only:
 *
 *   - Every existing user row gets `phone = NULL` on this migration; no
 *     existing login, profile read, or report flow is affected.
 *   - SmsService::send() already no-ops (logs and returns false) whenever a
 *     recipient's phone is empty/null, so an Administrator who has not yet
 *     filled in a phone number simply never receives the SMS leg of the new
 *     Need Change notification — the in-system and email legs are
 *     unaffected either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
