<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPRINT 4 — Maintenance Workflow Migration.
     *
     * Adds the one column needed to let a newly-created damage_reports row
     * originate its own linked maintenance_reports row, per
     * SPRINT_4_WORKFLOW_MIGRATION.md and the "gradual migration ... without
     * breaking existing functionality" mandate: new Damage Reports going
     * forward create a paired Maintenance Report and stamp its report_id
     * back here, so that the report_id already introduced on
     * repair_requests and dispatches in Sprint 3 has something real to
     * propagate from at creation time.
     *
     * This migration is PURELY ADDITIVE:
     *   - No table is dropped, renamed, or merged. damage_reports remains a
     *     fully independent, fully functional table (Sprint 2's Option C
     *     row-merge has NOT been performed — that is still a distinct,
     *     larger, not-yet-authorized future step).
     *   - No existing column on damage_reports is altered or removed.
     *   - No data is backfilled — every existing damage_reports row (0,
     *     confirmed live at the time this migration was written) gets
     *     report_id = NULL, same as every other nullable relationship
     *     column added in Sprints 1 and 3.
     *
     * report_id is UNIQUE because this sprint's service-layer change
     * creates exactly one maintenance_reports row per damage_reports row
     * (1:1), the same reasoning already applied to repair_requests.report_id
     * in Sprint 3.
     *
     * A foreign key constraint IS added, consistent with Sprint 3's
     * reasoning: the column is 100% NULL on every existing row today, so
     * there is no data that could violate it.
     */
    public function up(): void
    {
        if (!Schema::hasTable('damage_reports') || !Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('damage_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('damage_reports', 'report_id')) {
                $table->unsignedInteger('report_id')->nullable()->unique()->after('damage_report_code');
            }
        });

        Schema::table('damage_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('damage_reports', 'report_id')) {
                return;
            }
            $indexes = collect(Schema::getIndexes('damage_reports'))->pluck('name')->all();
            if (!in_array('damage_reports_report_id_foreign', $indexes, true)) {
                $table->foreign('report_id')->references('report_id')->on('maintenance_reports')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('damage_reports') || !Schema::hasColumn('damage_reports', 'report_id')) {
            return;
        }

        Schema::table('damage_reports', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('damage_reports'))->pluck('name')->all();
            if (in_array('damage_reports_report_id_foreign', $indexes, true)) {
                $table->dropForeign(['report_id']);
            }
        });

        Schema::table('damage_reports', function (Blueprint $table): void {
            if (Schema::hasColumn('damage_reports', 'report_id')) {
                $table->dropColumn('report_id');
            }
        });
    }
};
