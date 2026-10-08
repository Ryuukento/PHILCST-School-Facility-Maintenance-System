<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * "Performed by" (2026-10-08) — tracks who actually clicked Mark as
 * Completed on a report, via the new nullable maintenance_reports.completed_by
 * column (see 2026_10_08_000200_add_completed_by_to_maintenance_reports_table).
 *
 * WHY THIS EXISTS: `assigned_to` does not reliably say who performed the
 * completion. Head Maintenance (maintenance_admin/department_admin) can set
 * a report straight to 'completed' on a report that has no assignee at all,
 * or on one assigned to a different Staff member — in both cases
 * assigned_to does not reflect who actually completed it. This is the gap
 * the user flagged: the All Reports "Assigned To" column showed blank for
 * reports Head created/completed directly, and there was no way to see "who
 * performed this" from the report detail page.
 *
 * SCOPE (per explicit user decisions this same day):
 *   - New completed_by column (not derived from assigned_to), so the actor
 *     is recorded accurately even when it differs from the assignee.
 *   - All Reports "Assigned To" fallback (reports.php, frontend-only, not
 *     re-tested here) shows whoever MOST RECENTLY completed the report,
 *     Head or Staff alike — not restricted to Head.
 *   - The report detail page's "Performed By" row is shown only while
 *     status === 'completed' (maintenance-report-detail.php, frontend-only).
 *
 * This file pins the backend contract those two frontend reads depend on:
 * completed_by is stamped on the completed transition, surfaced as
 * completed_by_name by both the list (index) and detail (show) endpoints,
 * and is NOT touched by the later completed -> closed transition (so it
 * keeps meaning "who completed it", not "who closed it").
 */
class ReportCompletedByTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_completed_by_testing');
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createActivityLogsTable();

        $this->forceLocalTestUrl();
    }

    public function test_staff_completing_their_own_assigned_report_is_recorded_as_the_completer(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff Completer']);
        $reportId = $this->seedReport([
            'assigned_to' => $staffId,
            'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg',
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed']);

        $response->assertOk();

        $this->assertSame($staffId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('completed_by'));
    }

    public function test_head_completing_an_unassigned_report_is_recorded_as_the_completer_not_blank(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Maintenance']);
        $reportId = $this->seedReport([
            'assigned_to' => null,
            'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg',
        ]);

        $response = $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed']);

        $response->assertOk();

        $this->assertSame($headId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('completed_by'));
    }

    public function test_head_completing_a_report_assigned_to_someone_else_is_recorded_as_the_completer(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Maintenance']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Assigned Staff']);
        $reportId = $this->seedReport([
            'assigned_to' => $staffId,
            'status' => 'in_progress',
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg',
        ]);

        $response = $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed']);

        $response->assertOk();

        // The assignee is untouched (still Staff) ...
        $row = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame($staffId, (int) $row->assigned_to);
        // ... but completed_by correctly records Head, who actually performed it.
        $this->assertSame($headId, (int) $row->completed_by);
    }

    public function test_closing_a_completed_report_does_not_overwrite_the_original_completer(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff Completer']);
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Maintenance']);
        $reportId = $this->seedReport([
            'assigned_to' => $staffId,
            'status' => 'completed',
            'completed_by' => $staffId,
            'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'closed']);

        $response->assertOk();

        $this->assertSame($staffId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('completed_by'));
    }

    public function test_index_surfaces_completed_by_name_for_an_unassigned_completed_report(): void
    {
        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Maintenance']);
        $reportId = $this->seedReport([
            'created_by' => $headId,
            'assigned_to' => null,
            'status' => 'completed',
            'completed_by' => $headId,
        ]);

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $row = collect($response->json('data.reports'))->firstWhere('report_id', $reportId);

        $this->assertNotNull($row);
        $this->assertNull($row['assigned_name']);
        $this->assertSame('Head Maintenance', $row['completed_by_name']);
    }

    public function test_show_surfaces_completed_by_name_for_the_detail_page(): void
    {
        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff Completer']);
        $reportId = $this->seedReport([
            'assigned_to' => $staffId,
            'status' => 'completed',
            'completed_by' => $staffId,
        ]);

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson("/api/reports/{$reportId}");

        $response->assertOk();
        $this->assertSame($staffId, $response->json('data.report.completed_by'));
        $this->assertSame('Staff Completer', $response->json('data.report.completed_by_name'));
    }

    public function test_show_returns_null_completed_by_for_a_report_never_completed(): void
    {
        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $reportId = $this->seedReport(['status' => 'submitted']);

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson("/api/reports/{$reportId}");

        $response->assertOk();
        $this->assertNull($response->json('data.report.completed_by'));
        $this->assertNull($response->json('data.report.completed_by_name'));
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
            'completed_by' => null,
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
