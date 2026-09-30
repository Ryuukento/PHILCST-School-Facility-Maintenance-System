<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 9 — Role + Department Based Authorization.
 *
 * Locks in the canModifyReport() policy (App\Services\ReportAuthorizationService)
 * enforced by ReportController::update():
 *   - super_admin (Administrator): can modify every report, any department.
 *   - maintenance_admin (Head Maintenance): can view all departments, but
 *     may only modify reports where report.department_id == user.department_id.
 *   - maintenance_staff (Maintenance Staff): may only modify reports that
 *     are BOTH assigned to them AND in their own department.
 *
 * Unauthorized modification attempts must return HTTP 403, never rely on
 * the frontend alone.
 */
class ReportDepartmentAuthorizationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_department_authorization_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    /**
     * 2026-09-30 — the Administrator's Cancel-only status capability was
     * retired at the Capstone Panel's request; the Administrator now has no
     * status-change action on a report in any department. This test used to
     * be named test_administrator_can_cancel_a_report_in_any_department_but_not_assign_it
     * and asserted the cancel succeeded; it now pins that it is rejected too.
     */
    public function test_administrator_cannot_cancel_or_assign_a_report_in_any_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $deptA = $this->seedDepartment();
        $deptB = $this->seedDepartment();
        $reportId = $this->seedReport(['department_id' => $deptA, 'status' => 'submitted']);
        $staffInDeptB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptB]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", [
                'status' => 'assigned',
                'assigned_to' => $staffInDeptB,
            ])
            ->assertStatus(403);
        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'cancelled'])
            ->assertStatus(403);
        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_head_maintenance_can_modify_a_report_in_their_own_department(): void
    {
        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport(['department_id' => $deptId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertSame('in_progress', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_head_maintenance_cannot_modify_a_report_in_another_department(): void
    {
        $ownDeptId = $this->seedDepartment();
        $otherDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $otherDeptId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'in_progress'])
            ->assertStatus(403);

        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_head_maintenance_cannot_assign_personnel_for_another_department(): void
    {
        $ownDeptId = $this->seedDepartment();
        $otherDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $techId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $otherDeptId]);
        $reportId = $this->seedReport(['department_id' => $otherDeptId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $techId])
            ->assertStatus(403);

        $this->assertNull(DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
    }

    public function test_head_maintenance_cannot_edit_report_fields_for_another_department(): void
    {
        $ownDeptId = $this->seedDepartment();
        $otherDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $otherDeptId, 'title' => 'Original Title']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['title' => 'Hijacked Title'])
            ->assertStatus(403);

        $this->assertSame('Original Title', DB::table('maintenance_reports')->where('report_id', $reportId)->value('title'));
    }

    public function test_head_maintenance_can_still_view_a_report_from_another_department(): void
    {
        $ownDeptId = $this->seedDepartment();
        $otherDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $otherDeptId]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->getJson("/api/reports/{$reportId}")
            ->assertOk();
    }

    public function test_maintenance_staff_can_update_status_of_a_report_assigned_to_them_in_their_department(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $reportId = $this->seedReport(['department_id' => $deptId, 'assigned_to' => $staffId, 'status' => 'in_progress', 'completion_proof_image' => '/frontend/uploads/completion-proofs/test-proof.jpg', // 2026-09-27: completing requires proof
        ]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed', 'completion_proof_image' => null])
            ->assertOk();

        $this->assertSame('completed', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_maintenance_staff_cannot_update_a_report_assigned_to_them_but_outside_their_department(): void
    {
        $staffDeptId = $this->seedDepartment();
        $reportDeptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $staffDeptId]);
        // Report is assigned to this staff member, but its department was
        // later changed away from the staff member's own department.
        $reportId = $this->seedReport(['department_id' => $reportDeptId, 'assigned_to' => $staffId, 'status' => 'in_progress']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])
            ->assertStatus(403);

        $this->assertSame('in_progress', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_maintenance_staff_cannot_update_a_report_in_their_department_not_assigned_to_them(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $reportId = $this->seedReport(['department_id' => $deptId, 'assigned_to' => $otherStaffId, 'status' => 'in_progress']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'completed'])
            ->assertStatus(403);

        $this->assertSame('in_progress', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_maintenance_staff_can_create_reports_regardless_of_department_authorization(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Carpentry',
                'title' => 'Broken Window',
                'description' => 'Window latch is broken.',
                'location' => 'Room 210',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    public function test_no_department_can_modify_another_departments_report_via_status_update(): void
    {
        $deptA = $this->seedDepartment();
        $deptB = $this->seedDepartment();
        $headB = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptB]);
        $reportInA = $this->seedReport(['department_id' => $deptA, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headB, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportInA}", ['status' => 'cancelled'])
            ->assertStatus(403);
    }

    /**
     * TASK 55 — Security & Input Validation Hardening.
     *
     * DEFECT: ReportController::update()'s "CASE A — basic field edit" block
     * copied department_id straight from $request->input() into $changes
     * with zero validation — no exists() check at all — unlike store(),
     * which validates the same field with
     * Rule::exists('departments','department_id')->where('status','active').
     * maintenance_reports.department_id also has no DB-level foreign key
     * (see 2026_03_27_000400_create_maintenance_reports_table), so there was
     * no backstop at any layer.
     *
     * A maintenance_admin who currently shares the report's department
     * passes the canModifyReport() gate with no ownership requirement, then
     * Case A only requires $canEditAny (true for maintenance_admin) OR
     * ownership — so they could silently move the report to a department
     * that does not exist at all, taking it outside the modification
     * authority of anyone except super_admin (since canModifyReport() is
     * re-evaluated against the NEW department_id on every subsequent
     * request). Note: moving a report to a DIFFERENT but real/active
     * department is intentionally still allowed — see the "still allowed"
     * test below, which pins that store()'s own "TASK 19 — Target
     * Maintenance Department" design already lets any role freely pick the
     * handling department, so this is not itself a privilege boundary.
     */
    public function test_editing_a_report_to_a_nonexistent_department_id_is_rejected(): void
    {
        $ownDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId, 'title' => 'Broken Chair']);

        $nonexistentDepartmentId = 999999;

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['department_id' => $nonexistentDepartmentId])
            ->assertStatus(422);

        // The report must still belong to its original, real department —
        // not be silently orphaned into a department_id nothing points to.
        $this->assertSame(
            $ownDeptId,
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('department_id')
        );
    }

    /**
     * NOT a defect — pinned deliberately. store()'s own "TASK 19 — Target
     * Maintenance Department" rule lets ANY role freely pick which
     * department should handle a report at creation time (department_id
     * represents "who should handle this," not "who owns this"), with no
     * ownership restriction. Editing department_id to a different, real,
     * active department is therefore consistent with that existing design,
     * not a privilege escalation — only a nonexistent/inactive value (see
     * the tests above and below) was ever unvalidated. This test locks in
     * that the fix does not overreach into restricting a legitimate
     * re-target to any authorized editor.
     */
    public function test_editing_a_report_to_a_different_but_valid_department_is_still_allowed(): void
    {
        $ownDeptId = $this->seedDepartment();
        $anotherDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['department_id' => $anotherDeptId])
            ->assertOk();

        $this->assertSame(
            $anotherDeptId,
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('department_id')
        );
    }

    /**
     * An inactive department must be rejected too — mirrors store()'s
     * Rule::exists(...)->where('status', 'active'), which this test's
     * companion in store()'s own coverage (ReportCreateAssetSeverityImageTest
     * / general report-creation tests) does not otherwise duplicate for
     * update().
     */
    public function test_editing_a_report_into_an_inactive_department_is_rejected(): void
    {
        $ownDeptId = $this->seedDepartment();
        $inactiveDeptId = $this->seedDepartment(['status' => 'inactive']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['department_id' => $inactiveDeptId])
            ->assertStatus(422);

        $this->assertSame(
            $ownDeptId,
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('department_id')
        );
    }

    /**
     * Positive control: a real, active department_id must still be
     * accepted — the fix must not break the legitimate case.
     */
    public function test_editing_a_report_to_a_valid_active_department_id_still_succeeds(): void
    {
        $ownDeptId = $this->seedDepartment();
        $anotherActiveDeptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $reportId = $this->seedReport(['department_id' => $ownDeptId]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['department_id' => $anotherActiveDeptId])
            ->assertOk();

        $this->assertSame(
            $anotherActiveDeptId,
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('department_id')
        );
    }

    /**
     * DEFECT (same Case A block): 'priority' was copied from
     * $request->input() with no enum whitelist, unlike store()'s
     * 'in:low,medium,high,critical'. An arbitrary string would be persisted
     * and later silently render as the generic gray fallback color on the
     * Super Admin dashboard (DashboardController::colorForLabel(), see
     * DashboardControllerHelpersTest) instead of being rejected.
     */
    public function test_editing_a_report_to_an_invalid_priority_value_is_rejected(): void
    {
        $ownDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId, 'priority' => 'medium']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'not-a-real-priority'])
            ->assertStatus(422);

        $this->assertSame(
            'medium',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('priority')
        );
    }

    /**
     * DEFECT (Case B — status-change block, same class of bug as Case A's
     * department_id/priority gap above): 'assigned_to' was copied straight
     * from $request->input() with zero validation, unlike store()'s own
     * 'assigned_to' => ['nullable','integer','exists:users,user_id'] rule.
     * The real migration (2026_03_27_000400_create_maintenance_reports_table)
     * does carry a DB-level FK on assigned_to, but relying on that alone
     * (which this project's Task 55 audit explicitly disallows) means a
     * nonexistent id would surface as an uncaught QueryException — a raw
     * 500 — in production rather than a clean 422, and this isolated test
     * schema (BuildsSharedTestSchema, deliberately not a byte-for-byte copy
     * of every migration constraint) has no such FK at all, so pre-fix this
     * silently persisted the bogus id. Now validated at read time, mirroring
     * store()'s rule exactly.
     */
    public function test_editing_a_report_status_to_assigned_with_a_nonexistent_assigned_to_is_rejected(): void
    {
        $ownDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", [
                'status' => 'assigned',
                'assigned_to' => 999999,
            ])
            ->assertStatus(422);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('submitted', $report->status, 'A rejected assignment must not partially apply the status change either.');
        $this->assertNull($report->assigned_to);
    }

    /**
     * Positive control: a real, existing user id must still be accepted —
     * the fix must not break legitimate assignment.
     */
    public function test_editing_a_report_status_to_assigned_with_a_valid_assigned_to_still_succeeds(): void
    {
        $ownDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId, 'status' => 'submitted']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", [
                'status' => 'assigned',
                'assigned_to' => $staffId,
            ])
            ->assertOk();

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('assigned', $report->status);
        $this->assertSame($staffId, (int) $report->assigned_to);
    }

    /**
     * DEFECT (Case B, same block): 'due_date' had no 'date' format check at
     * all here, unlike store()'s 'due_date' => ['nullable','date']. Unlike
     * assigned_to, due_date has no DB-level FK equivalent to fall back on —
     * a malformed value could throw the same uncaught-exception risk under a
     * real DATE column, or (as this SQLite-backed test proves) simply be
     * persisted verbatim as garbage text with no error at all.
     */
    public function test_editing_a_report_with_an_invalid_due_date_is_rejected(): void
    {
        $ownDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId, 'status' => 'submitted', 'due_date' => null]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", [
                'status' => 'in_progress',
                'due_date' => 'not-a-real-date',
            ])
            ->assertStatus(422);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('submitted', $report->status);
        $this->assertNull($report->due_date);
    }

    /**
     * DEFECT (Case B, same block): 'completed_date' had no 'date' format
     * check either, and — unlike assigned_to/due_date — store() does not
     * even accept this field, so there was no prior validated rule to
     * compare against at all; it went straight from request input to the DB
     * with zero checks whenever a report was marked completed/closed.
     */
    public function test_editing_a_report_to_completed_with_an_invalid_completed_date_is_rejected(): void
    {
        $ownDeptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDeptId]);
        $reportId = $this->seedReport(['department_id' => $ownDeptId, 'status' => 'in_progress', 'completed_date' => null]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", [
                'status' => 'completed',
                'completed_date' => 'not-a-real-date',
            ])
            ->assertStatus(422);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('in_progress', $report->status);
        $this->assertNull($report->completed_date);
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

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
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
