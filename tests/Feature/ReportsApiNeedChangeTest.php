<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class ReportsApiNeedChangeTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('reports_api_need_change_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    public function test_reports_list_exposes_need_change_fields_after_approval(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Air Filter', 'quantity' => 10]);
        $reportId = $this->seedReport([
            'created_by' => $approverId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 4,
            'need_change_status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true])
            ->assertOk();

        $response = $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $rows = collect($response->json('data.reports'));
        $row = $rows->firstWhere('report_id', $reportId);

        $this->assertNotNull($row, 'The approved report was not present in the list response.');
        $this->assertSame($itemId, (int) $row['need_change_item_id']);
        $this->assertSame(4, (int) $row['need_change_quantity']);
        $this->assertSame('deducted', $row['need_change_status']);
        $this->assertSame($approverId, (int) $row['need_change_approved_by']);
        $this->assertNotNull($row['need_change_approved_at']);
        $this->assertNotNull($row['need_change_deducted_at']);
    }

    public function test_report_detail_endpoint_matches_list_endpoint_need_change_values(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Door Hinge', 'quantity' => 8]);
        $reportId = $this->seedReport([
            'created_by' => $approverId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 2,
            'need_change_status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true])
            ->assertOk();

        $listRow = collect(
            $this->actingAsSessionUser($approverId, 'super_admin')->getJson('/api/reports')->json('data.reports')
        )->firstWhere('report_id', $reportId);

        $detail = $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->getJson("/api/reports/{$reportId}")
            ->json('data.report');

        // Scalar Need Change fields must agree exactly between the two endpoints.
        $this->assertSame((int) $listRow['need_change_item_id'], (int) $detail['need_change_item_id']);
        $this->assertSame((int) $listRow['need_change_quantity'], (int) $detail['need_change_quantity']);
        $this->assertSame($listRow['need_change_status'], $detail['need_change_status']);
        $this->assertSame((int) $listRow['need_change_approved_by'], (int) $detail['need_change_approved_by']);

        // Timestamps: index() returns Eloquent-cast ISO8601 strings, show() returns
        // raw DB datetime strings — compare parsed instants rather than raw strings.
        $this->assertTrue(
            Carbon::parse($listRow['need_change_approved_at'])->equalTo(Carbon::parse($detail['need_change_approved_at']))
        );
        $this->assertTrue(
            Carbon::parse($listRow['need_change_deducted_at'])->equalTo(Carbon::parse($detail['need_change_deducted_at']))
        );
    }

    public function test_existing_filters_return_same_rows_regardless_of_need_change_fields(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $deptId = $this->seedDepartment();
        $itemId = $this->seedItem(['name' => 'Whiteboard', 'quantity' => 5]);

        $matching = $this->seedReport([
            'created_by' => $adminId,
            'department_id' => $deptId,
            'status' => 'submitted',
            'priority' => 'high',
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 1,
            'need_change_status' => 'pending',
            'created_at' => '2026-06-10 10:00:00',
        ]);
        $this->seedReport([
            'created_by' => $adminId,
            'department_id' => $deptId,
            'status' => 'closed',
            'priority' => 'low',
            'need_change_item_id' => null,
            'created_at' => '2026-06-11 10:00:00',
        ]);
        $otherDeptReport = $this->seedReport([
            'created_by' => $adminId,
            'department_id' => $this->seedDepartment(),
            'status' => 'submitted',
            'priority' => 'high',
            'created_at' => '2026-06-10 10:00:00',
        ]);

        $client = $this->actingAsSessionUser($adminId, 'maintenance_admin');

        // status filter
        $byStatus = $client->getJson('/api/reports?status=submitted')->json('data.reports');
        $this->assertSame(2, count($byStatus));

        // priority filter
        $byPriority = $client->getJson('/api/reports?priority=high')->json('data.reports');
        $this->assertSame(2, count($byPriority));

        // department filter
        $byDept = $client->getJson("/api/reports?department_id={$deptId}")->json('data.reports');
        $this->assertSame(2, count($byDept));
        $this->assertFalse(collect($byDept)->contains('report_id', $otherDeptReport));

        // date range filter
        $byDateRange = $client->getJson('/api/reports?date_from=2026-06-10&date_to=2026-06-10')->json('data.reports');
        $this->assertSame(2, count($byDateRange));

        // combined status + priority + department, isolating the single matching report
        $combined = $client
            ->getJson("/api/reports?status=submitted&priority=high&department_id={$deptId}")
            ->json('data.reports');
        $this->assertSame(1, count($combined));
        $this->assertSame($matching, (int) $combined[0]['report_id']);
    }

    /**
     * TASK 9 — Role + Department Based Authorization. Supersedes the earlier
     * Sprint 1 / Feature 2 auto-scope: Head Maintenance (maintenance_admin)
     * now sees reports from ALL departments in the default listing (no
     * status_group, no explicit department_id query param), the same as
     * super_admin/maintenance_staff. Modification remains department-gated
     * (see ReportDepartmentAuthorizationTest) — this test only covers
     * viewing.
     */
    public function test_head_maintenance_with_department_sees_all_departments_by_default(): void
    {
        $ownDeptId = $this->seedDepartment();
        $otherDeptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);

        $ownReport = $this->seedReport([
            'created_by' => $adminId,
            'department_id' => $ownDeptId,
        ]);
        $otherDeptReport = $this->seedReport([
            'created_by' => $this->seedUser(),
            'department_id' => $otherDeptId,
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $reportIds = collect($response->json('data.reports'))->pluck('report_id')->map(fn ($id) => (int) $id);

        $this->assertTrue($reportIds->contains($ownReport));
        $this->assertTrue($reportIds->contains($otherDeptReport));
    }

    /**
     * TASK 9 — a Head Maintenance user can explicitly filter/search by any
     * department_id, including a department that is not their own, since
     * viewing is unrestricted across departments.
     */
    public function test_head_maintenance_can_filter_by_a_department_that_is_not_their_own(): void
    {
        $ownDeptId = $this->seedDepartment();
        $otherDeptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);

        $ownReport = $this->seedReport([
            'created_by' => $adminId,
            'department_id' => $ownDeptId,
        ]);
        $otherDeptReport = $this->seedReport([
            'created_by' => $this->seedUser(),
            'department_id' => $otherDeptId,
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->getJson("/api/reports?department_id={$otherDeptId}");

        $response->assertOk();
        $reportIds = collect($response->json('data.reports'))->pluck('report_id')->map(fn ($id) => (int) $id);

        $this->assertTrue($reportIds->contains($otherDeptReport));
        $this->assertFalse($reportIds->contains($ownReport));
    }

    /**
     * Sprint 1 / Feature 2 — Super Admin must remain unaffected by the new
     * department-scoping rule: they still see reports across every
     * department by default.
     */
    public function test_super_admin_still_sees_all_departments_by_default(): void
    {
        $deptA = $this->seedDepartment();
        $deptB = $this->seedDepartment();
        $superAdminId = $this->seedUser(['role' => 'super_admin']);

        $reportA = $this->seedReport(['created_by' => $superAdminId, 'department_id' => $deptA]);
        $reportB = $this->seedReport(['created_by' => $superAdminId, 'department_id' => $deptB]);

        $response = $this
            ->actingAsSessionUser($superAdminId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $reportIds = collect($response->json('data.reports'))->pluck('report_id')->map(fn ($id) => (int) $id);

        $this->assertTrue($reportIds->contains($reportA));
        $this->assertTrue($reportIds->contains($reportB));
    }

    public function test_assigned_to_me_filter_is_unaffected_by_need_change_fields(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Padlock', 'quantity' => 5]);

        $assignedToMe = $this->seedReport([
            'created_by' => $otherStaffId,
            'assigned_to' => $staffId,
            'need_change_item_id' => $itemId,
            'need_change_status' => 'pending',
        ]);
        $this->seedReport([
            'created_by' => $otherStaffId,
            'assigned_to' => $otherStaffId,
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/reports?status_group=assigned_to_me');

        $rows = $response->json('data.reports');
        $this->assertSame(1, count($rows));
        $this->assertSame($assignedToMe, (int) $rows[0]['report_id']);
    }

    public function test_role_visibility_unchanged_regular_user_sees_only_own_or_assigned_reports(): void
    {
        $regularUserId = $this->seedUser(['role' => 'department_admin']);
        $otherUserId = $this->seedUser(['role' => 'department_admin']);
        $itemId = $this->seedItem(['name' => 'Keyboard', 'quantity' => 5]);

        $ownReport = $this->seedReport([
            'created_by' => $regularUserId,
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_approved_by' => $otherUserId,
            'need_change_approved_at' => now(),
            'need_change_deducted_at' => now(),
        ]);
        $assignedReport = $this->seedReport([
            'created_by' => $otherUserId,
            'assigned_to' => $regularUserId,
        ]);
        $foreignReport = $this->seedReport([
            'created_by' => $otherUserId,
            'assigned_to' => $otherUserId,
        ]);

        $response = $this
            ->actingAsSessionUser($regularUserId, 'department_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $reportIds = collect($response->json('data.reports'))->pluck('report_id')->map(fn ($id) => (int) $id);

        $this->assertTrue($reportIds->contains($ownReport));
        $this->assertTrue($reportIds->contains($assignedReport));
        $this->assertFalse($reportIds->contains($foreignReport));
    }

    public function test_role_visibility_unchanged_privileged_roles_see_all_reports_including_deducted(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $someoneElseId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Router', 'quantity' => 5]);

        $foreignDeductedReport = $this->seedReport([
            'created_by' => $someoneElseId,
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_approved_by' => $adminId,
            'need_change_approved_at' => now(),
            'need_change_deducted_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $reportIds = collect($response->json('data.reports'))->pluck('report_id')->map(fn ($id) => (int) $id);
        $this->assertTrue($reportIds->contains($foreignDeductedReport));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
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

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    /**
     * Not moved into the shared BuildsSharedTestSchema trait: this file's
     * default 'need_change_status' (null) differs from
     * NeedChangeApprovalTest's default ('pending'), and several call sites
     * here rely on this file's own default rather than always overriding
     * it. Consolidating the two would silently change one file's seeded
     * default, which risks changing test-observable behavior.
     */
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
