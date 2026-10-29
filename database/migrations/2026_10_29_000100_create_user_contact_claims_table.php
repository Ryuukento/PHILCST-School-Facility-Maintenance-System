<?php

use App\Support\UserContactPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_contact_claims')) {
            throw new RuntimeException('Contact claim table already exists. Inspect any interrupted migration before retrying.');
        }
        if (!Schema::hasColumns('users', ['user_id', 'phone', 'email', 'verified_email'])) {
            throw new RuntimeException('Required user contact columns are missing.');
        }
        // Fail before DDL if the current source data cannot be reconciled.
        UserContactPolicy::backfillClaims(DB::table('users')->get(['user_id', 'phone', 'email', 'verified_email']));
        Schema::create('user_contact_claims', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
            $table->bigIncrements('id');
            $table->string('contact_type', 8);
            $table->string('normalized_value', 255);
            $table->unsignedInteger('user_id');
            $table->unique(['contact_type', 'normalized_value'], 'user_contact_claims_value_unique');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
        // MariaDB DDL auto-commits. If this fails, claims roll back and the
        // migration is NOT recorded; application writes remain fail-closed.
        // Deploy only in a separately approved, quiescent migration window.
        DB::transaction(function (): void {
            $users = DB::table('users')->orderBy('user_id')->lockForUpdate()->get(['user_id', 'phone', 'email', 'verified_email']);
            foreach (array_chunk(UserContactPolicy::backfillClaims($users), 200) as $chunk) DB::table('user_contact_claims')->insert($chunk);
        });
    }

    public function down(): void
    {
        // Roll back application code with this migration: users are untouched,
        // but contact writes deliberately stop when the protection is absent.
        Schema::dropIfExists('user_contact_claims');
    }
};
