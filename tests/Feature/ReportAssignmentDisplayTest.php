<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 46 (Unified Status & Assignment Workflow).
 *
 * Pins down the `maintenance_reports.assigned_to` -> `assigned_name` contract
 * that ReportController::index() has exposed since before this task (via its
 * pre-existing `LEFT JOIN users AS assignee` — see
 * app/Http/Controllers/Api/ReportController.php), which reports.php's new
 * "Assigned To" list column now reads directly. No backend behavior changed;
 * this test only protects the field the new frontend column depends on from
 * silently regressing (e.g. a future rename/removal of `assigned_name`).
 */
class ReportAssignmentDisplayTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_assignment_display_testing');
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createMaintenanceReportsTable();

        $this->forceLocalTestUrl();
    }

    public function test_index_exposes_assigned_name_for_an_assigned_report(): void
    {
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Facilities',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'department_id');

        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $assigneeId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Alice Assignee']);

        $reportId = DB::table('maintenance_reports')->insertGetId([
            'title' => 'Leaking Faucet',
            'description' => 'Faucet in Room 202 is leaking.',
            'location' => 'Room 202',
            'priority' => 'medium',
            'status' => 'assigned',
            'created_by' => $viewerId,
            'assigned_to' => $assigneeId,
            'department_id' => $deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'report_id');

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $row = collect($response->json('data.reports'))->firstWhere('report_id', $reportId);

        $this->assertNotNull($row, 'The seeded report was not present in the list response.');
        $this->assertSame('Alice Assignee', $row['assigned_name']);
    }

    public function test_index_returns_null_assigned_name_when_report_is_unassigned(): void
    {
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        $reportId = DB::table('maintenance_reports')->insertGetId([
            'title' => 'Broken Window',
            'description' => 'Window latch is broken.',
            'location' => 'Room 105',
            'priority' => 'low',
            'status' => 'submitted',
            'created_by' => $viewerId,
            'assigned_to' => null,
            'department_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'report_id');

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $row = collect($response->json('data.reports'))->firstWhere('report_id', $reportId);

        $this->assertNotNull($row, 'The seeded report was not present in the list response.');
        $this->assertNull($row['assigned_name']);
    }
}
