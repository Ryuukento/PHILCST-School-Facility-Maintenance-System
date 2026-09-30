<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 13 — Dispatch Release Assignment Workflow.
 *
 * Release Personnel are now chosen by the Head Maintenance *during dispatch
 * creation* (and may be reassigned until the dispatch is released), rather
 * than being picked by the Administrator inside the approve dialog. That
 * needs three new columns:
 *
 *   release_assigned_to  — the Maintenance Staff member who is the ONLY user
 *                          permitted to release this dispatch's inventory.
 *   release_assigned_by  — which Head Maintenance user made/changed the
 *                          assignment (audit trail requirement §7).
 *   release_assigned_at  — when the current assignment was made.
 *
 * All three are nullable and additive:
 *   - every dispatch that exists today keeps working unchanged (they simply
 *     have no assignment, which the release guard treats as "not releasable
 *     by anyone", matching the fact that they are already status='released');
 *   - DispatchService::createDispatch()'s internal callers (notably
 *     RepairService::fulfillReplacement()) may keep omitting the assignment.
 *
 * `released_by` is deliberately NOT reused for this: it means "who actually
 * performed the release" (TASK 3) and must stay null until the release
 * genuinely happens, otherwise the Task 3 audit trail becomes a forecast
 * rather than a record.
 *
 * No enum change is required — `dispatches.status` already contains
 * 'approved' (see 2026_05_15_000400_create_dispatches_and_items), it was
 * merely unreachable while approval and release were a single atomic step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dispatches')) {
            return;
        }

        Schema::table('dispatches', function (Blueprint $table): void {
            if (!Schema::hasColumn('dispatches', 'release_assigned_to')) {
                $table->unsignedInteger('release_assigned_to')->nullable()->after('released_by');
            }
            if (!Schema::hasColumn('dispatches', 'release_assigned_by')) {
                $table->unsignedInteger('release_assigned_by')->nullable()->after('release_assigned_to');
            }
            if (!Schema::hasColumn('dispatches', 'release_assigned_at')) {
                $table->timestamp('release_assigned_at')->nullable()->after('release_assigned_by');
            }
        });

        // Foreign keys mirror the existing released_by/approved_by columns in
        // the base dispatches migration: nullOnDelete() so deactivating or
        // removing a user never deletes dispatch history, it only orphans the
        // assignment (which the release guard then correctly refuses).
        Schema::table('dispatches', function (Blueprint $table): void {
            $table->foreign('release_assigned_to')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('release_assigned_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('dispatches')) {
            return;
        }

        Schema::table('dispatches', function (Blueprint $table): void {
            $table->dropForeign(['release_assigned_to']);
            $table->dropForeign(['release_assigned_by']);
        });

        Schema::table('dispatches', function (Blueprint $table): void {
            foreach (['release_assigned_to', 'release_assigned_by', 'release_assigned_at'] as $column) {
                if (Schema::hasColumn('dispatches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
