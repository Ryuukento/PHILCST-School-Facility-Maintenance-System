<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEED CHANGE / REPLACEMENT TRACKING — DISPOSAL ARCHIVE.
 *
 * Raised by the Capstone Panel's IT Expert: Replacement Tracking's "For
 * Disposal" status (ReplacementTrackingController::deriveTrackingStatus())
 * was only ever a COMPUTED label — derived on every request from
 * need_change_status === 'deducted' plus the report being completed/closed.
 * Nothing was ever actually recorded when the old, replaced item was really
 * disposed of: no timestamp, no accountable person, no note. So there was no
 * true archive/history of disposal, only a live re-derivation that could
 * silently change if the underlying report was ever edited later.
 *
 * These three columns give disposal a real, one-time-stamped fact — mirrors
 * the existing need_change_approved_by/need_change_approved_at pattern
 * already on this table (2026_04_07_000700_add_need_change_fields_...).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('maintenance_reports', 'need_change_disposed_by')) {
                $table->unsignedInteger('need_change_disposed_by')->nullable()->after('need_change_deducted_at');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_disposed_at')) {
                $table->timestamp('need_change_disposed_at')->nullable()->after('need_change_disposed_by');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_disposal_notes')) {
                $table->string('need_change_disposal_notes', 255)->nullable()->after('need_change_disposed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $dropColumns = [];
            foreach (['need_change_disposed_by', 'need_change_disposed_at', 'need_change_disposal_notes'] as $column) {
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
