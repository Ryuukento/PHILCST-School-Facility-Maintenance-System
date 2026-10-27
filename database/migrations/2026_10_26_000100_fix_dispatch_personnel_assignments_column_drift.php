<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a schema drift discovered 2026-10-26 while debugging a "Server
 * error" on Create Dispatch: dispatch_personnel_assignments was originally
 * created (by an earlier revision of
 * 2026_10_04_000100_add_multi_personnel_dispatch_support, before that
 * migration settled on its current column name) with a `personnel_id`
 * column. That migration is guarded with `Schema::hasTable(...)`, so once
 * the table already existed and the migration got recorded as run in this
 * environment, no later edit to the migration file could ever re-create it
 * with the column name the rest of the app (DispatchPersonnelAssignment
 * model, DispatchService, DispatchAuthorizationService) has used ever
 * since: `personnel_user_id`. Every dispatch creation/approval touching
 * this table has been failing with "Unknown column 'personnel_user_id'"
 * as a result -- confirmed via storage/logs/laravel.log and
 * `DESCRIBE dispatch_personnel_assignments` on the live DB: columns were
 * id, dispatch_id, personnel_id, assigned_by, assigned_at, created_at,
 * updated_at -- no personnel_user_id, and no foreign keys at all, unlike
 * what the current migration's Schema::create() block defines.
 *
 * The table has 0 rows (confirmed), so this is a pure schema fix, not a
 * data migration. MariaDB 10.4 (this project's DB server) doesn't support
 * the native RENAME COLUMN syntax Laravel's renameColumn() would
 * otherwise emit, and doctrine/dbal isn't installed (the fallback path
 * renameColumn() needs on older MariaDB), so this uses a raw CHANGE
 * COLUMN statement instead, which every MySQL/MariaDB version supports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dispatch_personnel_assignments')) {
            return;
        }

        if (Schema::hasColumn('dispatch_personnel_assignments', 'personnel_id')
            && !Schema::hasColumn('dispatch_personnel_assignments', 'personnel_user_id')) {
            DB::statement(
                'ALTER TABLE dispatch_personnel_assignments '
                . 'CHANGE COLUMN personnel_id personnel_user_id INT UNSIGNED NOT NULL'
            );
        }

        if (!Schema::hasColumn('dispatch_personnel_assignments', 'personnel_user_id')) {
            return;
        }

        // Backfill the foreign keys the current create-table migration
        // defines but this table never actually got, since it was never
        // actually created by that migration's Schema::create() block.
        // $table->foreign(...) only *queues* the command -- the ALTER TABLE
        // itself runs when Schema::table()'s closure returns, so the
        // try/catch has to wrap the Schema::table() call, not the line
        // inside the closure, or a real duplicate-constraint error (e.g. on
        // a second run of this migration) won't actually be caught.
        // personnel_user_id is NOT NULL, so it gets cascade (not
        // nullOnDelete, which MySQL/MariaDB reject on a non-nullable
        // column with errno 150) -- see the matching fix in
        // 2026_10_04_000100_add_multi_personnel_dispatch_support.
        $this->addForeignKeyIfMissing('dispatch_id', 'dispatches', 'id', false);
        $this->addForeignKeyIfMissing('personnel_user_id', 'users', 'user_id', false);
        $this->addForeignKeyIfMissing('assigned_by', 'users', 'user_id', true);
    }

    private function addForeignKeyIfMissing(string $column, string $refTable, string $refColumn, bool $nullOnDelete): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'dispatch_personnel_assignments'
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1",
            [$column]
        );

        if ($exists) {
            return;
        }

        try {
            Schema::table('dispatch_personnel_assignments', function (Blueprint $table) use ($column, $refTable, $refColumn, $nullOnDelete): void {
                $fk = $table->foreign($column)->references($refColumn)->on($refTable);
                $nullOnDelete ? $fk->nullOnDelete() : $fk->onDelete('cascade');
            });
        } catch (\Exception $e) {
            \Log::warning("dispatch_personnel_assignments.{$column} FK: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('dispatch_personnel_assignments')) {
            return;
        }

        foreach (['dispatch_id', 'personnel_user_id', 'assigned_by'] as $column) {
            try {
                Schema::table('dispatch_personnel_assignments', function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                });
            } catch (\Exception $e) {
                // Foreign key might not exist.
            }
        }

        if (Schema::hasColumn('dispatch_personnel_assignments', 'personnel_user_id')) {
            DB::statement(
                'ALTER TABLE dispatch_personnel_assignments '
                . 'CHANGE COLUMN personnel_user_id personnel_id INT UNSIGNED NOT NULL'
            );
        }
    }
};
