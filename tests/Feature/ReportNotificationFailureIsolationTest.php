<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 52 — regression coverage for a real notification defect found while
 * auditing transaction/failure behavior: ReportController::notifyAdminsOfNewReport()
 * and ::notifyReportOwnerOfCompletion() write to the notifications table with
 * a raw DB::table('notifications')->insert() call, unlike every other
 * notification-producing flow in the app, which goes through
 * NotificationService::notify() — a method that deliberately swallows any
 * Throwable and only logs it (see app/Services/NotificationService.php), so a
 * notification failure never surfaces as a failure of the primary business
 * operation. These two ReportController methods had no such guard: an
 * exception thrown while notifying (e.g. a schema mismatch, a DB hiccup) would
 * propagate straight out of store()/update() as an uncaught 500 — even though
 * the report had ALREADY been created / the status change had ALREADY been
 * persisted (neither call site is wrapped in DB::transaction()), risking a
 * confused client retry/duplicate submission over what is, to every other
 * flow in this app, a non-critical side effect.
 *
 * Fixed by wrapping both methods' bodies in the same try/catch(Throwable) +
 * Log::error() pattern NotificationService::notify() already uses.
 *
 * This test proves the fix by making the notifications table itself
 * unavailable (never created in this file's schema) and asserting that
 * report creation and report completion both still succeed and persist,
 * despite the notification write necessarily failing under the hood.
 */
class ReportNotificationFailureIsolationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_notification_failure_isolation_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_report_creation_succeeds_even_when_the_notification_write_fails(): void
    {
        $reporterId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Faucet',
                'description' => 'Water leaking under the sink.',
                'location' => 'Room 204',
            ]);

        // Must succeed, not 500, even though notifications table doesn't exist.
        $response->assertStatus(201);
        $this->assertSame(
            1,
            DB::table('maintenance_reports')->where('title', 'Leaking Faucet')->count(),
            'The report must still be persisted even though notifying admins failed.'
        );
    }

    public function test_report_completion_succeeds_even_when_the_notification_write_fails(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport([
            'created_by' => $ownerId,
            'assigned_to' => $staffId,
            'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg', // 2026-09-27: completing requires proof
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed']);

        // Must succeed, not 500, even though notifying the owner failed.
        $response->assertOk();
        $this->assertSame('completed', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $this->seedUser(),
            'assigned_to' => null,
            'department_id' => null,
            'due_date' => null,
            'completed_date' => null,
            'need_change_item_id' => null,
            'need_change_quantity' => 1,
            'need_change_status' => null,
            'need_change_approved_by' => null,
            'need_change_approved_at' => null,
            'need_change_deducted_at' => null,
            'completion_proof_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        // Deliberately NOT creating a 'notifications' table — this is the
        // failure condition under test.
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        Schema::enableForeignKeyConstraints();
    }
}
