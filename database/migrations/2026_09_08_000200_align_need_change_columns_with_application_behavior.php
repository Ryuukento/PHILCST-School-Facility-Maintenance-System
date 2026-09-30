<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 66 — need_change_status / need_change_quantity schema drift.
 *
 * THE DRIFT
 * ---------
 * 2026_04_07_000700_add_need_change_fields_to_maintenance_reports_table.php
 * declares:
 *
 *     need_change_status   ENUM('pending','approved','deducted','failed') NULL
 *     need_change_quantity INT UNSIGNED NOT NULL DEFAULT 1
 *
 * The live database has neither. It has VARCHAR(50) NULL and INT NULL. A fresh
 * install built from migrations therefore gets a *different and stricter*
 * schema than the database the application actually runs against — and both
 * differences are functional, not cosmetic.
 *
 * WHY THE ENUM IS WRONG — EVIDENCE, NOT PREFERENCE
 * ------------------------------------------------
 * Every value the application reads or writes was enumerated for this task:
 *
 *   WRITTEN by application code:
 *     'deducted'  NeedChangeService::approve()      (line ~148)
 *     'rejected'  ReportController::update()        (line ~697)
 *   BRANCHED ON by application code:
 *     'rejected'  NeedChangeService::approve() guard — a rejected request must
 *                 never be deducted (line ~75)
 *     'deducted'  ReportController immutability guard (line ~826),
 *                 ReplacementTrackingController::deriveTrackingStatus()
 *     'approved'  ReportController immutability guard (line ~824)
 *     'pending'   frontend default for NULL (maintenance-report-detail.php)
 *   PRESENT in live data (SELECT ... GROUP BY, 14 rows total):
 *     NULL x9, 'pending' x3, 'deducted' x2
 *
 * So the ENUM is wrong in both directions at once:
 *
 *   - 'rejected' IS written by the application but is NOT an ENUM member. On a
 *     fresh migrated database, rejecting a Need Change either raises
 *     SQLSTATE 01000 (Laravel's connection sets strict mode) or is silently
 *     coerced to '' (legacy non-strict connections). The reject workflow, and
 *     the NeedChangeService guard that depends on reading 'rejected' back, are
 *     therefore BROKEN on any database built from migrations today.
 *   - 'failed' IS an ENUM member but is never written or read by anything.
 *
 * That the member list has been wrong for the entire life of the column
 * without anyone noticing is itself the argument: the ENUM was never the
 * load-bearing constraint. The real enforcement is the PHP-side branching
 * above, which is where it stays.
 *
 * WHY VARCHAR(50) AND NOT A CORRECTED ENUM
 * ----------------------------------------
 * Considered and rejected: widening the ENUM to include 'rejected'. That would
 * mean rewriting a column on the live production table purely to satisfy a
 * migration file — converting VARCHAR(50) to ENUM in place, where any row
 * outside the member list becomes '' under a non-strict connection. It buys no
 * safety the application does not already enforce, and it re-arms the exact
 * trap that caused this bug: every new state would need another ALTER on a
 * production table, and forgetting one fails silently.
 *
 * VARCHAR(50) instead converges the migration on reality:
 *   - it is a genuine NO-OP against the live database (already VARCHAR(50)),
 *     so no production data is rewritten and no production column is retyped;
 *   - it makes the currently-broken 'rejected' workflow work on a fresh
 *     install without further schema churn;
 *   - it matches tests/Support/BuildsSharedTestSchema.php:117, which already
 *     declares string('need_change_status', 50) — so the suite, the migration
 *     and the live database finally agree on one representation.
 *
 * need_change_quantity NOT NULL -> NULL for the same class of reason:
 * ReportController validates it as ['sometimes','nullable','integer','min:1']
 * and writes the validated value straight through, so clearing the Edit Report
 * "Need Change" toggle persists NULL. Against the migration's NOT NULL that
 * write fails on a fresh install. Live is already NULL-able, so again a no-op.
 *
 * NO DATA IS MODIFIED. Widening a type and relaxing a NOT NULL preserve every
 * existing value; no row's need_change_status or need_change_quantity is read,
 * rewritten, or deleted by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        // ENUM -> VARCHAR(50) is a widening conversion: every current member
        // ('pending','approved','deducted','failed') and NULL survive verbatim.
        if ($this->columnTypeOf('need_change_status') !== 'varchar(50)') {
            Schema::getConnection()->statement(
                'ALTER TABLE maintenance_reports MODIFY need_change_status VARCHAR(50) NULL DEFAULT NULL'
            );
        }

        if ($this->columnIsNotNullable('need_change_quantity')) {
            Schema::getConnection()->statement(
                'ALTER TABLE maintenance_reports MODIFY need_change_quantity INT UNSIGNED NULL DEFAULT NULL'
            );
        }
    }

    public function down(): void
    {
        // Deliberately NOT restoring the ENUM or the NOT NULL.
        //
        // Restoring ENUM('pending','approved','deducted','failed') would
        // destroy data: any row holding 'rejected' — a value the application
        // legitimately writes — would be coerced to '' or abort the ALTER.
        // Restoring NOT NULL would fail outright against rows holding NULL.
        // A rollback must not be more destructive than the change it reverses,
        // so this migration is intentionally irreversible in schema terms.
    }

    private function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql'
            && Schema::hasTable('maintenance_reports');
    }

    private function columnTypeOf(string $column): ?string
    {
        $row = Schema::getConnection()->selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['maintenance_reports', $column]
        );

        return $row === null ? null : strtolower((string) $row->COLUMN_TYPE);
    }

    private function columnIsNotNullable(string $column): bool
    {
        $row = Schema::getConnection()->selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['maintenance_reports', $column]
        );

        return $row !== null && strtoupper((string) $row->IS_NULLABLE) === 'NO';
    }
};
