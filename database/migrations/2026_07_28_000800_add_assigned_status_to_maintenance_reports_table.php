<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 2 / Feature 2 — Maintenance Report Status Transition Enforcement.
     *
     * The `maintenance_reports.status` column was created (2026_03_27_000400)
     * as ENUM('submitted','in_progress','completed','closed','cancelled') and
     * was never altered to include 'assigned' — yet ReportController@update,
     * the legacy ReportService, and the maintenance report detail UI have all
     * treated 'assigned' as a valid, actively-used status for years (see
     * status_group=recent_assignments, $allowedStatusOptions in
     * maintenance-report-detail.php, and status_group=assigned_to_me). On a
     * strict MySQL/MariaDB connection this mismatch means persisting
     * status='assigned' can be rejected or silently truncated.
     *
     * This migration only widens the existing enum to match what the
     * application already assumes; it does not change any other table.
     */
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE maintenance_reports MODIFY status "
                . "ENUM('submitted','assigned','in_progress','completed','closed','cancelled') "
                . "NOT NULL DEFAULT 'submitted'"
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE maintenance_reports MODIFY status "
                . "ENUM('submitted','in_progress','completed','closed','cancelled') "
                . "NOT NULL DEFAULT 'submitted'"
            );
        }
    }
};
