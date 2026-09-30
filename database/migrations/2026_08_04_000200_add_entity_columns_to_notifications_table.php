<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 17 — Notification Deep Linking.
 *
 * Additive schema change only. The `notifications` table has always stored
 * free-text `title`/`message` with no link back to the record the
 * notification is actually about, even though every caller that creates a
 * notification already has that record in scope at the moment it calls
 * NotificationService::notify() (see App\Services\DispatchService,
 * RepairService, DamageReportService, ReportController, AuthController).
 *
 * `entity_type` + `entity_id` let a notification say "I am about dispatch
 * #42" / "repair_request #7" / "damage_report #3" / "report #101" /
 * "user #9" without duplicating any data — no columns, tables, or copies of
 * report/dispatch/etc. data are introduced. Both are nullable and existing
 * rows are left as NULL on purpose:
 *   - Old notifications keep working exactly as before (the frontend's
 *     existing Task 13.1 text-extraction fallback for dispatch/report
 *     notifications is untouched and still runs for any row where
 *     entity_type is NULL).
 *   - No backfill is attempted. Retroactively guessing an old notification's
 *     entity from its free-text title/message is exactly the kind of
 *     unreliable inference this task is trying to move away from, and it is
 *     unnecessary: the fallback already covers those rows.
 *
 * This mirrors a convention already half-present in this codebase: several
 * places (NotificationController, the legacy public/backend/models/
 * Notification.php model, ReportController, AuthController) already do a
 * defensive Schema::hasColumn('notifications', 'report_id') check for a
 * report_id column that was anticipated but never actually added. This
 * migration finally adds a (more general, multi-entity) column so that
 * long-anticipated linkage becomes real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            // e.g. 'report', 'dispatch', 'repair_request', 'damage_report', 'user'
            $table->string('entity_type', 40)->nullable()->after('message');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex(['entity_type', 'entity_id']);
            $table->dropColumn(['entity_type', 'entity_id']);
        });
    }
};
