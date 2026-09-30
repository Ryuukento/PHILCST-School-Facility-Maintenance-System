<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 1 — Dispatch Inventory Automation.
 *
 * Adds ONLY the one new column this feature needs ("Save Approval Date" per
 * the business requirement): a nullable `approved_at` timestamp on
 * `dispatches`, set by DispatchService::approveDispatch() at the moment an
 * Administrator approves a dispatch and inventory is automatically released.
 *
 * Deliberately does NOT touch the `dispatches.status` enum. See
 * TASK1_DISPATCH_INVENTORY_AUTOMATION.md ("Implementation Details" ->
 * "Status mapping decision") for the full reasoning — in short: this app's
 * test suite (tests/Feature/*) runs against an in-memory SQLite connection,
 * which has no native ENUM type and cannot safely ALTER an emulated one
 * without a full table rebuild. The feature instead reuses the *existing*
 * 'released' status value (already the terminal, stock-left-the-building
 * state in this schema) as the automatic post-approval state, so no enum
 * migration is required at all — additive, portable, zero UI risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dispatches') && !Schema::hasColumn('dispatches', 'approved_at')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dispatches') && Schema::hasColumn('dispatches', 'approved_at')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $table->dropColumn('approved_at');
            });
        }
    }
};
