<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Covers the Report Completion Notification feature added to close the
 * "communication loop" gap documented in SYSTEM_FLOW_REVIEW.md §7 / §3 and
 * CHANGELOG.md: a maintenance report transitioning to 'completed' or
 * 'closed' must notify the report owner exactly once, reusing the existing
 * raw notifications-table insert pattern (no new notification system).
 */
class ReportCompletionNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_completion_notification_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    public function test_completing_a_report_notifies_the_owner_exactly_once(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Owner Staff']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Assigned Tech']);
        $reportId = $this->seedReport([
            'created_by' => $ownerId,
            'assigned_to' => $staffId,
            'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg', // 2026-09-27: completing requires proof
            'location' => 'Building A - Room 101',
            'title' => 'Broken Aircon',
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", [
                'status' => 'completed',
                'completed_date' => '2026-07-22',
            ]);

        $response->assertOk();

        $notifications = DB::table('notifications')->where('user_id', $ownerId)->get();
        $this->assertCount(1, $notifications, 'Expected exactly one notification for the report owner.');

        $notification = $notifications->first();
        $this->assertStringContainsString((string) $reportId, $notification->title);
        $this->assertStringContainsString('Completed', $notification->title);
        $this->assertStringContainsString('Building A - Room 101', $notification->message);
        $this->assertStringContainsString('Assigned Tech', $notification->message);
        $this->assertStringContainsString('2026-07-22', $notification->message);
        $this->assertStringContainsString('Completed', $notification->message);
        $this->assertSame(0, (int) $notification->is_read);
    }

    public function test_closing_a_report_notifies_the_owner_exactly_once(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff']);
        // 2026-09-27: Head Maintenance closes; the Administrator only cancels.
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $reportId = $this->seedReport([
            'created_by' => $ownerId,
            'assigned_to' => null,
            'status' => 'completed',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'closed']);

        $response->assertOk();

        $notifications = DB::table('notifications')->where('user_id', $ownerId)->get();
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('Closed', $notifications->first()->title);
        $this->assertStringContainsString('Unassigned', $notifications->first()->message);
    }

    public function test_repeated_completion_update_does_not_duplicate_the_notification(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport([
            'created_by' => $ownerId,
            'assigned_to' => $staffId,
            'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg', // 2026-09-27: completing requires proof
        ]);

        $client = $this->actingAsSessionUser($staffId, 'maintenance_staff');

        $client->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])->assertOk();
        // Idempotent re-submission of the same terminal status must not double-notify.
        $client->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])->assertOk();

        $this->assertSame(1, DB::table('notifications')->where('user_id', $ownerId)->count());
    }

    public function test_non_terminal_status_transition_does_not_notify(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $reportId = $this->seedReport([
            'created_by' => $ownerId,
            'status' => 'submitted',
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $adminId])
            ->assertOk();

        $this->assertSame(0, DB::table('notifications')->where('user_id', $ownerId)->count());
    }

    public function test_new_report_submission_notification_flow_is_unaffected(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Faucet',
                'description' => 'Water leaking under the sink.',
                'location' => 'Room 204',
            ]);

        $response->assertStatus(201);

        // The existing new-report-submission notification to admins must still fire,
        // and must not be affected by the new completion-notification logic.
        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->count());
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
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
        $this->createNotificationsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
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
}
