<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Performed by" — tracks who actually clicked Mark as Completed on a
     * report, which is NOT always the same person as assigned_to.
     *
     * Why a new column instead of reusing assigned_to:
     *   - Head Maintenance (maintenance_admin/department_admin) can set a
     *     report's status straight to 'completed' on a report that has no
     *     assignee at all (assigned_to stays NULL), or on a report assigned
     *     to a Staff member — in both cases assigned_to does not reflect
     *     who actually performed the completion action.
     *   - completed_date (existing column) records WHEN, not WHO.
     *
     * PURELY ADDITIVE: nullable, FK set-null-on-delete (same pattern as
     * assigned_to), no backfill for existing completed/closed reports (they
     * simply show no "Performed by" until acted on again). No existing
     * column is renamed, removed, or repurposed.
     */
    public function up(): void
    {
        if (Schema::hasColumn('maintenance_reports', 'completed_by')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->unsignedInteger('completed_by')->nullable()->after('completed_date');
            $table->index('completed_by', 'maintenance_reports_completed_by_index');
            $table->foreign('completed_by', 'maintenance_reports_completed_by_foreign')
                ->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('maintenance_reports', 'completed_by')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->dropForeign('maintenance_reports_completed_by_foreign');
            $table->dropIndex('maintenance_reports_completed_by_index');
            $table->dropColumn('completed_by');
        });
    }
};
