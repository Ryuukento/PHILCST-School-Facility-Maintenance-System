<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 81 Part 1 — Username Standardization (Remove Email as Login Identifier).
 *
 * Documents/creates the `users.username` column that AuthController::login()
 * now authenticates against exclusively. The real production database
 * already has this column (varchar(100), nullable, unique — confirmed via
 * `SHOW CREATE TABLE users`), added out-of-band at some point before this
 * migration existed (no prior migration file defines it, so `migrations`
 * never recorded it). This migration is guarded with Schema::hasColumn() so
 * it is a safe no-op against that already-correct production schema, while
 * bringing any fresh install / CI environment that runs `migrate:fresh` up
 * to the same real schema (mirrors the guarded-add pattern already used by
 * 2026_06_15_000000_normalize_user_roles.php's `previous_role` column).
 *
 * Nullable + unique matches `email`'s existing constraint shape exactly:
 * multiple NULL usernames are allowed (self-registered users created before
 * this task, or via any future flow that doesn't set one immediately), but
 * two users can never share the same non-null username.
 *
 * TASK 66 — this migration previously carried `->after('employee_id')`. That
 * positional hint was written against the *live* users table, where
 * `employee_id` exists only because it was added out-of-band; no migration in
 * this repository has ever created it. On a fresh database the whole chain
 * therefore aborted here with:
 *
 *     SQLSTATE[42S22]: Column not found: 1054 Unknown column 'employee_id' in 'users'
 *
 * Because `username` is the sole login identifier (AuthController::login()),
 * a fresh install was left with no way for anyone to authenticate at all.
 *
 * The hint is purely cosmetic — it controls physical column order and nothing
 * else — so it has been dropped rather than satisfied. Editing this file in
 * place is safe here specifically because it is the one migration in the repo
 * that the live `migrations` ledger has never recorded as run: no existing
 * database has executed the old body, so there is no version skew to create.
 * Fresh-install parity for `employee_id` itself is handled separately by
 * 2026_09_08_000100_add_out_of_band_user_and_report_columns.php.
 */
class AddUsernameToUsersTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 100)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('username');
            });
        }
    }
}
