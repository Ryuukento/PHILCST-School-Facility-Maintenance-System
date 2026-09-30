<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 66 — DATABASE MIGRATION REPRODUCIBILITY.
 *
 * WHY THIS MIGRATION EXISTS
 * -------------------------
 * Task 65 found that `php artisan migrate` against an empty database could not
 * rebuild this project's schema. Task 66's dependency audit established that
 * the failure was not one bug but a class of them: a number of columns exist in
 * the live database that NO migration in this repository has ever created. They
 * were applied out-of-band (direct SQL / phpMyAdmin), so the live `migrations`
 * ledger has no record of them and a fresh install simply never gets them.
 *
 * Evidence that they are out-of-band rather than merely old: every one of them
 * is absent from the union of all migration files, past and present. (Three
 * ledger rows — add_archive_fields_to_items_table, create_purchase_requests_table
 * and create_purchase_orders_table — DO point at migrations whose files were
 * later deleted from the repo. The columns/tables those three created are
 * deliberately NOT recreated here; see "DELIBERATELY NOT INCLUDED" below.)
 *
 * WHY A NEW MIGRATION RATHER THAN EDITING AN OLD ONE
 * --------------------------------------------------
 * The live database has already run every other migration in this repo. Editing
 * an executed migration would leave the live ledger claiming a version of that
 * file which no longer exists, and would never re-run to actually apply the
 * change. A new, additive, guarded migration is the only mechanism that is
 * simultaneously a no-op on the live database (every column below already
 * exists there, so every guard short-circuits) and corrective on a fresh one.
 *
 * This mirrors the guarded-add pattern already used by
 * 2026_06_15_000000_normalize_user_roles.php (`previous_role`) and
 * 2026_08_15_000100_add_username_to_users_table.php (`username`).
 *
 * COLUMN-BY-COLUMN JUSTIFICATION
 * ------------------------------
 * Definitions below are transcribed from the live database's
 * information_schema, so a fresh install reproduces it rather than approximates
 * it. Marked [REQUIRED] where application code actively reads or writes the
 * column — those are the ones whose absence is a functional break on a fresh
 * install, not merely cosmetic drift.
 *
 *  users.designation           varchar(100) NULL      [REQUIRED]
 *      Validated and inserted by UserController::store().
 *  users.force_profile_update  tinyint(1) NOT NULL 0  [REQUIRED]
 *      Written by UserController::store()/resetPassword(), read by
 *      UserController to decide whether to force the first-login profile setup.
 *  maintenance_reports.completion_proof_image  varchar(500) NULL  [REQUIRED]
 *      In MaintenanceReport::$fillable; written by ReportController when a
 *      completion proof is uploaded.
 *  users.employee_id           varchar(20) NULL UNIQUE
 *      No backend code reads or writes it (the "auto-generated Employee ID"
 *      copy in public/frontend/pages/users.php is vestigial), but it is part of
 *      the live schema and is what the historical `->after('employee_id')` hint
 *      in the username migration was written against. Reproduced for parity.
 *  users.created_by            int unsigned NULL
 *      Live-schema parity only. Note this is NOT the same as the heavily-used
 *      `created_by` on maintenance_reports / repair_requests /
 *      preventive_maintenance_tasks, which their own migrations do create.
 *  maintenance_reports.completion_proof_uploaded_at  timestamp NULL
 *      Live-schema parity only; no current code path writes it.
 *
 * users.email is additionally relaxed to NULL. UserController::store()
 * explicitly persists `null` when an administrator leaves email blank (email
 * stopped being the login identifier in Task 81 Part 1 — `username` is), so the
 * NOT NULL that 0001_01_01_000000_create_users_table.php declares would reject
 * a supported, exercised flow on a fresh install. The live column is already
 * NULL-able, so this is a no-op there.
 *
 * DELIBERATELY NOT INCLUDED
 * -------------------------
 * The live database also carries `items.archived_at/archived_by/archive_reason/
 * restored_at` and the `purchase_requests` / `purchase_orders` tables. Those
 * came from the three deleted migrations named above, and no current
 * application code references any of them (the `archived_at` that RepairService
 * does use belongs to `repair_requests`, which its own migration creates).
 * Recreating retired schema would be scope creep in the wrong direction, so
 * they are recorded as a known, intentional live-vs-fresh difference in the
 * Task 66 report instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id', 20)->nullable()->unique();
            }

            if (!Schema::hasColumn('users', 'designation')) {
                $table->string('designation', 100)->nullable();
            }

            if (!Schema::hasColumn('users', 'created_by')) {
                $table->unsignedInteger('created_by')->nullable();
            }

            if (!Schema::hasColumn('users', 'force_profile_update')) {
                $table->boolean('force_profile_update')->default(false);
            }
        });

        Schema::table('maintenance_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('maintenance_reports', 'completion_proof_image')) {
                $table->string('completion_proof_image', 500)->nullable();
            }

            if (!Schema::hasColumn('maintenance_reports', 'completion_proof_uploaded_at')) {
                $table->timestamp('completion_proof_uploaded_at')->nullable();
            }
        });

        // Relax users.email to NULL only when it is still NOT NULL, so this is
        // a genuine no-op against the live database.
        if ($this->emailIsNotNullable()) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally NOT reversing the users.email nullability change. Rows
        // legitimately created with a null email (administrator-created users
        // with no email address) would make a NOT NULL restore fail, and losing
        // that data to satisfy a rollback would be strictly worse than leaving
        // the column more permissive than the original migration declared.

        Schema::table('maintenance_reports', function (Blueprint $table) {
            foreach (['completion_proof_uploaded_at', 'completion_proof_image'] as $column) {
                if (Schema::hasColumn('maintenance_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['force_profile_update', 'created_by', 'designation'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('users', 'employee_id')) {
                $table->dropUnique('users_employee_id_unique');
                $table->dropColumn('employee_id');
            }
        });
    }

    private function emailIsNotNullable(): bool
    {
        if (!Schema::hasColumn('users', 'email')) {
            return false;
        }

        $connection = Schema::getConnection();

        // information_schema is MySQL/MariaDB-only. Other drivers (the SQLite
        // used by most of the suite) get the portable Schema fallback, which
        // cannot report nullability — treating it as "already nullable" is the
        // safe answer there because SQLite would not enforce the change anyway.
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $row = $connection->selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'email']
        );

        return $row !== null && strtoupper((string) $row->IS_NULLABLE) === 'NO';
    }
};
