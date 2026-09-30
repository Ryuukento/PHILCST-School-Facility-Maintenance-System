<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 3 — Dispatch Personnel Audit Trail.
 *
 * Adds ONLY the one new column this feature needs: a nullable `requested_by`
 * FK on `dispatches`, recording who actually created the dispatch request.
 *
 * Before this migration, `dispatches` had no requester column of its own —
 * "Requested By" could only ever be *inferred*, and only for dispatches
 * linked to a Maintenance Report, via `report_id -> maintenance_reports
 * .created_by -> users` (see the "HEAD DASHBOARD BUG FIX" comment in
 * DispatchController::index()). Dispatches created directly (no report link)
 * had no requester at all, and every dispatch's `released_by` was — until
 * this task — simply copied from `approved_by`, conflating "who authorized
 * this" with "who requested/released it" (see
 * TASK3_DISPATCH_AUDIT_TRAIL.md §1). This column lets DispatchService record
 * the true requester for every dispatch, and lets `released_by` default to
 * that requester instead of the approving Administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dispatches') && !Schema::hasColumn('dispatches', 'requested_by')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $table->unsignedInteger('requested_by')->nullable()->after('department_id');
                $table->foreign('requested_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dispatches') && Schema::hasColumn('dispatches', 'requested_by')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $table->dropForeign(['requested_by']);
                $table->dropColumn('requested_by');
            });
        }
    }
};
