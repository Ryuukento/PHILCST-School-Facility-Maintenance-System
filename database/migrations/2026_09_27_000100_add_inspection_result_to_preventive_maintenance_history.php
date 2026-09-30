<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inspection result for a Preventive Maintenance completion.
     *
     *   - condition_result: 'working' | 'needs_repair'. What the Head/Staff
     *     found when they inspected the equipment for this cycle.
     *   - maintenance_report_id: the repair report raised from a
     *     'needs_repair' result, so the PM history and the normal report
     *     workflow point at each other.
     *
     * Both are NULLABLE: every completion recorded before this change keeps
     * its data and simply has no recorded result ("Not recorded" in the UI).
     * No foreign key constraint — a report is never hard-deleted through the
     * app, and the link is informational; an index covers the lookups.
     */
    public function up(): void
    {
        if (!Schema::hasTable('preventive_maintenance_history')) {
            return;
        }

        Schema::table('preventive_maintenance_history', function (Blueprint $table): void {
            if (!Schema::hasColumn('preventive_maintenance_history', 'condition_result')) {
                $table->string('condition_result', 20)->nullable()->after('action_taken');
            }
            if (!Schema::hasColumn('preventive_maintenance_history', 'maintenance_report_id')) {
                $table->unsignedInteger('maintenance_report_id')->nullable()->after('condition_result');
                $table->index('maintenance_report_id', 'pm_history_maintenance_report_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('preventive_maintenance_history')) {
            return;
        }

        Schema::table('preventive_maintenance_history', function (Blueprint $table): void {
            if (Schema::hasColumn('preventive_maintenance_history', 'maintenance_report_id')) {
                $table->dropIndex('pm_history_maintenance_report_idx');
                $table->dropColumn('maintenance_report_id');
            }
            if (Schema::hasColumn('preventive_maintenance_history', 'condition_result')) {
                $table->dropColumn('condition_result');
            }
        });
    }
};
