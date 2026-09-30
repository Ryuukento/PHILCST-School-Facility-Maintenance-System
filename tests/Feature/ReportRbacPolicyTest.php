<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * RBAC POLICY UPDATE — locks in the current create/assign/status-update
 * permissions for maintenance reports:
 *   - super_admin (Administrator): reviews, assigns, monitors, manages the
 *     workflow, but cannot submit a new report.
 *   - maintenance_admin (Head Maintenance): can submit reports, assign
 *     personnel, monitor all reports, and update status when necessary.
 *   - maintenance_staff (Maintenance Staff): can submit reports and monitor
 *     its own/assigned reports, cannot assign personnel, and can only update
 *     the status of reports assigned to it.
 */
class ReportRbacPolicyTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_rbac_policy_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    public function test_super_admin_cannot_create_a_report(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Broken Projector',
                'description' => 'Projector will not power on.',
                'location' => 'Room 305',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    public function test_head_maintenance_can_create_a_report(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Broken Projector',
                'description' => 'Projector will not power on.',
                'location' => 'Room 305',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    public function test_maintenance_staff_can_create_a_report(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Faucet',
                'description' => 'Water leaking under the sink.',
                'location' => 'Room 204',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    public function test_head_maintenance_can_assign_personnel(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $techId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['created_by' => $headId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $techId])
            ->assertOk();

        $this->assertSame($techId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
    }

    public function test_maintenance_staff_cannot_assign_personnel(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['created_by' => $staffId, 'assigned_to' => $staffId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $otherStaffId])
            ->assertStatus(403);

        $this->assertNull(DB::table('maintenance_reports')->where('report_id', $reportId)->value('completed_date'));
        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_maintenance_staff_can_update_status_of_a_report_assigned_to_them(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['created_by' => $staffId, 'assigned_to' => $staffId, 'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg', // 2026-09-27: completing requires proof
        ]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])
            ->assertOk();

        $this->assertSame('completed', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_maintenance_staff_cannot_update_status_of_a_report_not_assigned_to_them(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['created_by' => $ownerId, 'assigned_to' => $ownerId, 'status' => 'in_progress']);

        $this
            ->actingAsSessionUser($otherStaffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])
            ->assertStatus(403);

        $this->assertSame('in_progress', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    // ---------------------------------------------------------------
    // 2026-09-27 — rules the detail page enforced, now enforced by the API
    // ---------------------------------------------------------------

    public function test_completing_requires_a_completion_proof_image(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['assigned_to' => $staffId, 'status' => 'in_progress']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please upload a completion proof image before marking this report as completed.');

        $this->assertSame('in_progress', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_assigned_status_requires_someone_assigned(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $reportId = $this->seedReport(['status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned'])
            ->assertStatus(422);

        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_head_cannot_create_a_report_that_is_already_finished(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Already Fixed',
                'description' => 'Skipping the workflow.',
                'location' => 'Room 210',
                'status' => 'completed',
            ])
            ->assertStatus(201);

        $this->assertSame('submitted', DB::table('maintenance_reports')->where('created_by', $headId)->value('status'));
    }

    public function test_head_cannot_create_an_in_progress_report_without_an_assignee(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Nobody On It',
                'description' => 'In progress with nobody assigned.',
                'location' => 'Room 211',
                'status' => 'in_progress',
            ])
            ->assertStatus(201);

        $this->assertSame('submitted', DB::table('maintenance_reports')->where('created_by', $headId)->value('status'));
    }

    public function test_only_a_submitted_report_can_be_deleted(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $startedId = $this->seedReport(['created_by' => $staffId, 'assigned_to' => $staffId, 'status' => 'in_progress']);
        $freshId = $this->seedReport(['created_by' => $staffId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->deleteJson("/api/reports/{$startedId}")
            ->assertStatus(422);
        $this->assertTrue(DB::table('maintenance_reports')->where('report_id', $startedId)->exists());

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->deleteJson("/api/reports/{$freshId}")
            ->assertOk();
        $this->assertFalse(DB::table('maintenance_reports')->where('report_id', $freshId)->exists());
    }

    /**
     * 2026-09-30 — the Administrator's (super_admin) Cancel-only status
     * capability was retired at the Capstone Panel's request (the
     * Administrator now has no status-change action on a report at all;
     * Head Maintenance runs the work end to end). This test used to be
     * named test_administrator_can_cancel_but_not_work_a_report and
     * asserted the opposite of its last block below; it now pins the new
     * behavior instead. Head Maintenance's own, separate 'cancelled' option
     * is untouched and covered elsewhere (e.g. UserManagementAuthorizationTest).
     */
    public function test_administrator_cannot_change_status_at_all(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport(['status' => 'submitted']);

        foreach (['assigned', 'in_progress', 'cancelled'] as $status) {
            $this
                ->actingAsSessionUser($adminId, 'super_admin')
                ->patchJson("/api/reports/{$reportId}", ['status' => $status, 'assigned_to' => $staffId])
                ->assertStatus(403);
        }
        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
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
        Schema::create('notifications', function ($table): void {
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
