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
 * TASK 20 — Notify Only the Assigned Personnel When a Report Is Assigned.
 *
 * Locks in that ReportController::update()'s 'assigned' status transition
 * notifies exactly one user (the newly assigned_to value) and nobody else —
 * never a department- or admin-wide broadcast, unlike the unrelated "New
 * Report Submitted" flow (notifyAdminsOfNewReport()), which stays untouched
 * and is separately re-verified here.
 */
class ReportAssignmentNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_assignment_notification_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    public function test_assigning_a_report_notifies_only_the_assigned_user(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']); // Head Maintenance assigns (2026-09-27: not the Administrator)
        $mariahId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Mariah']);
        $euclideId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Euclide']);
        $otherHeadId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Other Head']);
        $reportId = $this->seedReport(['status' => 'submitted', 'title' => 'Broken Window', 'location' => 'Room 210']);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $mariahId])
            ->assertOk();

        $this->assertSame(1, DB::table('notifications')->where('user_id', $mariahId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $euclideId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $otherHeadId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $adminId)->count());

        $notification = DB::table('notifications')->where('user_id', $mariahId)->first();
        $this->assertStringContainsString('Assigned', $notification->title);
        $this->assertStringContainsString((string) $reportId, $notification->message);
        $this->assertSame('report', $notification->entity_type);
        $this->assertSame($reportId, (int) $notification->entity_id);
        $this->assertSame(0, (int) $notification->is_read);
    }

    public function test_reassigning_a_report_notifies_only_the_new_assignee(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']); // Head Maintenance assigns (2026-09-27: not the Administrator)
        $mariahId = $this->seedUser(['role' => 'maintenance_staff']);
        $euclideId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['status' => 'assigned', 'assigned_to' => $mariahId]);

        // First assignment already happened outside this request (seeded directly),
        // so Mariah has zero notifications going into the reassignment below.
        $this->assertSame(0, DB::table('notifications')->where('user_id', $mariahId)->count());

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $euclideId])
            ->assertOk();

        $this->assertSame(1, DB::table('notifications')->where('user_id', $euclideId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $mariahId)->count(), 'Previous assignee must receive nothing.');
    }

    public function test_resubmitting_the_same_assignee_does_not_duplicate_the_notification(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']); // Head Maintenance assigns (2026-09-27: not the Administrator)
        $mariahId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['status' => 'submitted']);

        $client = $this->actingAsSessionUser($adminId, 'maintenance_admin');

        $client->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $mariahId])->assertOk();
        // Idempotent resubmission of the same assignee/status must not re-notify.
        $client->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $mariahId])->assertOk();

        $this->assertSame(1, DB::table('notifications')->where('user_id', $mariahId)->count());
    }

    public function test_new_report_submission_still_notifies_all_admins_unaffected_by_assignment_scoping(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Pipe',
                'description' => 'Water leaking near the entrance.',
                'location' => 'Room 105',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $headId)->count());
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
