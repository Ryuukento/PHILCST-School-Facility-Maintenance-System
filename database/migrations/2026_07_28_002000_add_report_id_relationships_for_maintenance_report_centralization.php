<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPRINT 3 — Maintenance Report Relationship Wiring.
     *
     * Implements ONLY the foreign-key directions approved in
     * SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §8:
     *
     *   - repair_requests.report_id           -> maintenance_reports.report_id (nullable, UNIQUE)
     *   - dispatches.report_id                -> maintenance_reports.report_id (nullable)
     *   - inventory_transactions.dispatch_id  -> dispatches.id                 (nullable)
     *
     * Rationale for each, per Sprint 2:
     *   - repair_requests.report_id is UNIQUE because Sprint 2 §4.2/§7 confirmed
     *     the existing 1:1 business rule (one report -> at most one repair
     *     request) should carry over unchanged from the legacy
     *     damage_report_id UNIQUE constraint it sits alongside.
     *   - dispatches.report_id is NOT unique — Sprint 2 §7 confirmed a report
     *     may have zero, one, or many dispatches over its lifecycle.
     *   - inventory_transactions.dispatch_id closes the traceability gap
     *     flagged in Sprint 2 §2 problem 3 / §5: today a dispatch's own
     *     transactions are only findable via a free-text reference_note
     *     string. This gives dispatch->transactions a real, queryable FK.
     *
     * This migration is PURELY ADDITIVE and backward-compatible, per this
     * sprint's explicit mandate:
     *   - damage_reports is untouched (table, columns, rows).
     *   - repair_requests.damage_report_id (legacy, UNIQUE) is untouched —
     *     report_id is a new, parallel, optional column, not a replacement.
     *   - dispatches.damage_report_id / dispatches.repair_request_id
     *     (legacy) are untouched — report_id is new and parallel.
     *   - No table is dropped, renamed, or merged.
     *   - No existing column is altered or removed.
     *   - No data is copied, moved, or backfilled — every existing row in
     *     repair_requests (0), dispatches (21), and inventory_transactions
     *     (25) receives these new columns as NULL.
     *
     * Foreign key constraints ARE added in this migration (unlike Sprint 1's
     * item_id / source_dispatch_id, which were deliberately left
     * unconstrained because nothing wrote to them yet). Sprint 2 explicitly
     * called for "proper foreign keys ... not reliance on reference_note"
     * for the dispatch/transaction link, and since these three columns are
     * brand new (100% NULL today across all existing rows), adding the
     * constraint now carries no risk of violating existing data.
     */
    public function up(): void
    {
        if (Schema::hasTable('repair_requests') && Schema::hasTable('maintenance_reports')) {
            Schema::table('repair_requests', function (Blueprint $table): void {
                if (!Schema::hasColumn('repair_requests', 'report_id')) {
                    $table->unsignedInteger('report_id')->nullable()->unique()->after('damage_report_id');
                }
            });

            Schema::table('repair_requests', function (Blueprint $table): void {
                if (!Schema::hasColumn('repair_requests', 'report_id')) {
                    return;
                }
                $indexes = collect(Schema::getIndexes('repair_requests'))->pluck('name')->all();
                if (!in_array('repair_requests_report_id_foreign', $indexes, true)) {
                    $table->foreign('report_id')->references('report_id')->on('maintenance_reports')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('dispatches') && Schema::hasTable('maintenance_reports')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                if (!Schema::hasColumn('dispatches', 'report_id')) {
                    $table->unsignedInteger('report_id')->nullable()->after('damage_report_id');
                }
            });

            Schema::table('dispatches', function (Blueprint $table): void {
                if (!Schema::hasColumn('dispatches', 'report_id')) {
                    return;
                }
                $indexes = collect(Schema::getIndexes('dispatches'))->pluck('name')->all();
                if (!in_array('dispatches_report_id_foreign', $indexes, true)) {
                    $table->foreign('report_id')->references('report_id')->on('maintenance_reports')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('inventory_transactions') && Schema::hasTable('dispatches')) {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                if (!Schema::hasColumn('inventory_transactions', 'dispatch_id')) {
                    $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
                }
            });

            Schema::table('inventory_transactions', function (Blueprint $table): void {
                if (!Schema::hasColumn('inventory_transactions', 'dispatch_id')) {
                    return;
                }
                $indexes = collect(Schema::getIndexes('inventory_transactions'))->pluck('name')->all();
                if (!in_array('inventory_transactions_dispatch_id_foreign', $indexes, true)) {
                    $table->foreign('dispatch_id')->references('id')->on('dispatches')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_transactions') && Schema::hasColumn('inventory_transactions', 'dispatch_id')) {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                $indexes = collect(Schema::getIndexes('inventory_transactions'))->pluck('name')->all();
                if (in_array('inventory_transactions_dispatch_id_foreign', $indexes, true)) {
                    $table->dropForeign(['dispatch_id']);
                }
            });
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                if (Schema::hasColumn('inventory_transactions', 'dispatch_id')) {
                    $table->dropColumn('dispatch_id');
                }
            });
        }

        if (Schema::hasTable('dispatches') && Schema::hasColumn('dispatches', 'report_id')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $indexes = collect(Schema::getIndexes('dispatches'))->pluck('name')->all();
                if (in_array('dispatches_report_id_foreign', $indexes, true)) {
                    $table->dropForeign(['report_id']);
                }
            });
            Schema::table('dispatches', function (Blueprint $table): void {
                if (Schema::hasColumn('dispatches', 'report_id')) {
                    $table->dropColumn('report_id');
                }
            });
        }

        if (Schema::hasTable('repair_requests') && Schema::hasColumn('repair_requests', 'report_id')) {
            Schema::table('repair_requests', function (Blueprint $table): void {
                $indexes = collect(Schema::getIndexes('repair_requests'))->pluck('name')->all();
                if (in_array('repair_requests_report_id_foreign', $indexes, true)) {
                    $table->dropForeign(['report_id']);
                }
            });
            Schema::table('repair_requests', function (Blueprint $table): void {
                if (Schema::hasColumn('repair_requests', 'report_id')) {
                    $table->dropColumn('report_id');
                }
            });
        }
    }
};
