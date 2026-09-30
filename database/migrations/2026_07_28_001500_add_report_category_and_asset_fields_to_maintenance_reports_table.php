<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPRINT 1 — Maintenance Report Central Entity Migration.
     *
     * Per FINAL_BUSINESS_ARCHITECTURE_ALIGNMENT.md, maintenance_reports is
     * being established as the system's single primary business entity,
     * matching the confirmed business process ("users always create a
     * Maintenance Report; everything else originates from it") and the
     * approved Capstone title, "Centralized School Facility Maintenance
     * Report Management System."
     *
     * This migration is PURELY ADDITIVE and is foundation-only:
     *   - No table is dropped, renamed, or merged.
     *   - No existing column is altered or removed.
     *   - No data is copied, moved, or backfilled.
     *   - damage_reports, repair_requests, and dispatches are untouched.
     *   - No controller, service, or route reads or writes these columns yet
     *     (that wiring is explicit, deliberately out-of-scope future-sprint
     *     work — see SPRINT_1_MAINTENANCE_REPORT_CENTRALIZATION.md §8).
     *
     * Three new, nullable-safe columns are added to maintenance_reports:
     *
     *   - report_category    ENUM('general','repair_replacement') NOT NULL
     *                         DEFAULT 'general'. Every existing row becomes
     *                         unambiguously 'general' — safe, because
     *                         damage_reports and repair_requests both had
     *                         0 rows at the time this migration was written,
     *                         so no maintenance_reports row has ever actually
     *                         been part of a repair/replacement flow.
     *   - item_id             nullable unsigned int. The future "affected
     *                         asset / item reference" for a
     *                         repair_replacement-category report.
     *   - source_dispatch_id  nullable unsigned big int. Future traceability
     *                         back to a prior Dispatch that deployed the
     *                         now-affected item (mirrors
     *                         damage_reports.source_dispatch_id).
     *
     * Foreign key constraints for item_id/source_dispatch_id are
     * deliberately NOT added in this sprint — matching the same caution
     * already established in this codebase by
     * 2026_04_07_000700_add_need_change_fields_to_maintenance_reports_table.php
     * ("Huwag munang maglagay ng foreign key constraint... hanggang sure na
     * ang data at structure"). No workflow writes to these columns yet, so
     * there is nothing to validate a constraint against; add the
     * constraints in the sprint that starts writing to them.
     *
     * Deliberately NOT added in this sprint (reasoning in
     * SPRINT_1_MAINTENANCE_REPORT_CENTRALIZATION.md §3):
     *   - severity_level — maintenance_reports.priority already covers this
     *     (near-identical enum: low/medium/high/urgent/critical vs
     *     damage_reports' low/medium/high/critical). A second field would
     *     be an unnecessary duplicate.
     *   - room_id — maintenance_reports.location (free text) and
     *     department_id already carry general location context; a
     *     structured room_id FK is deferred until a future sprint confirms
     *     it is actually needed by the Dispatch/repair linkage.
     *   - replacement_item_id / replacement_quantity /
     *     replacement_transaction_id / replaced_by / replaced_at —
     *     maintenance_reports already has a structurally near-identical
     *     quintet (need_change_item_id/need_change_quantity/
     *     need_change_status/need_change_approved_by/
     *     need_change_approved_at/need_change_deducted_at). Whether the
     *     future repair/replacement workflow reuses those columns or needs
     *     its own is a workflow-design decision explicitly out of scope for
     *     this schema-only sprint ("No Replacement changes yet").
     */
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('maintenance_reports', 'report_category')) {
                $table->enum('report_category', ['general', 'repair_replacement'])
                    ->default('general')
                    ->after('description');
            }
            if (!Schema::hasColumn('maintenance_reports', 'item_id')) {
                $table->unsignedInteger('item_id')->nullable()->after('report_category');
            }
            if (!Schema::hasColumn('maintenance_reports', 'source_dispatch_id')) {
                $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
            }
        });

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('maintenance_reports', 'report_category')) {
                return;
            }

            $indexes = collect(Schema::getIndexes('maintenance_reports'))->pluck('name')->all();
            if (!in_array('maintenance_reports_report_category_index', $indexes, true)) {
                $table->index('report_category', 'maintenance_reports_report_category_index');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('maintenance_reports'))->pluck('name')->all();
            if (in_array('maintenance_reports_report_category_index', $indexes, true)) {
                $table->dropIndex('maintenance_reports_report_category_index');
            }
        });

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $dropColumns = [];
            foreach (['report_category', 'item_id', 'source_dispatch_id'] as $column) {
                if (Schema::hasColumn('maintenance_reports', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
