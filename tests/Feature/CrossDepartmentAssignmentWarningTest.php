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
 * TASK 44 — Cross-Department Assignment Warning + Audit Trail.
 *
 * A maintenance report MAY be assigned to personnel from another department.
 * That stays true here: every test in this file that asserts a cross-department
 * assignment asserts that it SUCCEEDS. What Task 44 adds is (a) an explicit
 * confirmation step in the UI before such an assignment is submitted, and
 * (b) enough information in the existing activity log to reconstruct the event
 * afterwards.
 *
 * The critical invariant these tests lock in is the negative one: a department
 * mismatch between the report and the selected personnel must NEVER by itself
 * produce a 403 or a 422. Authorization remains exactly what
 * ReportAuthorizationService::canModifyReport() already decided — which is
 * about the ACTING user's department, not the assignee's — and is re-asserted
 * here unchanged.
 */
class CrossDepartmentAssignmentWarningTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const ASSIGNMENT_PAGE = __DIR__ . '/../../public/frontend/pages/maintenance-report-detail.php';

    private int $electricalId;
    private int $computerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('cross_department_assignment_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        $this->electricalId = $this->seedDepartment('Electrical');
        $this->computerId = $this->seedDepartment('Computer');
    }

    // ---------------------------------------------------------------
    // 1-2. Same-department assignment: unchanged, and not interrupted.
    // ---------------------------------------------------------------

    public function test_same_department_assignment_still_succeeds(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]); // Head of the report's department assigns (2026-09-27)
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->electricalId]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $staffId])
            ->assertOk();

        $this->assertSame($staffId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
    }

    public function test_same_department_assignment_is_not_flagged_and_needs_no_confirmation(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]); // Head of the report's department assigns (2026-09-27)
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->electricalId]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $staffId])
            ->assertOk();

        $meta = $this->assignmentMeta($reportId);
        $this->assertFalse($meta['cross_department'], 'A same-department assignment must not be flagged as cross-department.');

        // The confirmation dialog is opened only when the departments differ:
        // it lives behind isCrossDepartmentAssignment() in the submit handler,
        // so a matching pair reaches the request without any extra dialog.
        $page = $this->assignmentPageSource();
        $this->assertStringContainsString(
            'if (selectedPerson && isCrossDepartmentAssignment(currentReport, selectedPerson)) {',
            $page,
            'The confirmation must stay gated on an actual department mismatch.'
        );
    }

    // ---------------------------------------------------------------
    // 3-6. Cross-department: allowed, detected, and correctly described.
    // ---------------------------------------------------------------

    public function test_cross_department_assignment_is_allowed(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]); // Head of the report's department assigns (2026-09-27)
        $euclideId = $this->seedUser([
            'role' => 'maintenance_staff',
            'full_name' => 'Euclide',
            'department_id' => $this->computerId,
        ]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $euclideId])
            ->assertOk();

        $this->assertSame(
            $euclideId,
            (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'),
            'An Electrical report must remain assignable to Computer personnel.'
        );
    }

    public function test_cross_department_assignment_is_detected_and_flagged(): void
    {
        $reportId = $this->assignAcrossDepartments();

        $this->assertTrue($this->assignmentMeta($reportId)['cross_department']);
    }

    public function test_audit_record_stores_the_report_department(): void
    {
        $reportId = $this->assignAcrossDepartments();

        $this->assertSame($this->electricalId, $this->assignmentMeta($reportId)['report_department_id']);
    }

    public function test_audit_record_stores_the_personnel_department(): void
    {
        $reportId = $this->assignAcrossDepartments();

        $this->assertSame($this->computerId, $this->assignmentMeta($reportId)['assignee_department_id']);
    }

    // ---------------------------------------------------------------
    // 7. The warning the user actually reads.
    // ---------------------------------------------------------------

    public function test_warning_message_contains_the_required_content(): void
    {
        $page = $this->assignmentPageSource();

        $this->assertStringContainsString('Cross-Department Assignment', $page);
        $this->assertStringContainsString("'Report Department: '", $page);
        $this->assertStringContainsString("'Selected Personnel: '", $page);
        $this->assertStringContainsString("'Personnel Department: '", $page);
        $this->assertStringContainsString(
            'This report belongs to a different department from the selected personnel.',
            $page
        );
        $this->assertStringContainsString(
            'Cross-department assignments are allowed. Please confirm that this personnel is appropriate to handle this report.',
            $page
        );
        $this->assertStringContainsString("confirmText: 'Assign Anyway'", $page);
        $this->assertStringContainsString("cancelText: 'Cancel'", $page);

        // Rendered through the application's own shared modal component, not a
        // new dialog framework and not window.confirm().
        $this->assertStringContainsString('return showSystemConfirm({', $page);
        $this->assertStringNotContainsString('window.confirm(', $page);
    }

    // ---------------------------------------------------------------
    // 8. Cancel.
    // ---------------------------------------------------------------

    public function test_cancelling_the_warning_assigns_nothing(): void
    {
        // Cancel means the PATCH is never issued: the `return` sits inside the
        // submit handler ahead of the fetch(), so the server never sees the
        // assignment at all. Server-side, that is exactly the untouched report.
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);
        $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->computerId]);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertNull($report->assigned_to);
        $this->assertSame('submitted', $report->status);
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'ASSIGN_REPORT')->count());
        $this->assertSame(0, DB::table('notifications')->count());

        $handler = $this->statusUpdateSubmitHandlerSource();
        $this->assertMatchesRegularExpression(
            '/if \(!proceedWithAssignment\) \{.*?return;.*?\}/s',
            $handler,
            'Cancelling must return before the request is built.'
        );
        $this->assertLessThan(
            strpos($handler, 'const response = await fetch('),
            strpos($handler, 'const proceedWithAssignment = await confirmCrossDepartmentAssignment('),
            'The confirmation must be awaited BEFORE the assignment request is sent.'
        );
    }

    // ---------------------------------------------------------------
    // 9-10. Assign Anyway.
    // ---------------------------------------------------------------

    public function test_assign_anyway_produces_exactly_one_assignment(): void
    {
        $reportId = $this->assignAcrossDepartments();

        $this->assertSame(1, DB::table('activity_logs')->where('action', 'ASSIGN_REPORT')->where('entity_id', $reportId)->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_assign_anyway_produces_an_audit_record(): void
    {
        $reportId = $this->assignAcrossDepartments();

        $log = DB::table('activity_logs')->where('action', 'ASSIGN_REPORT')->where('entity_id', $reportId)->first();

        $this->assertNotNull($log, 'A cross-department assignment must be recorded in the existing activity log.');
        $this->assertSame('report', $log->module);
        $this->assertSame('report', $log->entity_type);
        $this->assertNotNull($log->user_id, 'The acting user must be recorded.');
        $this->assertNotNull($log->created_at, 'The time of the assignment must be recorded.');
        $this->assertStringContainsString('Cross-department assignment', (string) $log->details);
    }

    // ---------------------------------------------------------------
    // 11-13. Nothing else moved.
    // ---------------------------------------------------------------

    public function test_users_who_were_unauthorized_before_are_still_unauthorized(): void
    {
        // A maintenance_admin outside the report's department could not modify
        // it before Task 44 and still cannot. This 403 is about the ACTING
        // user, and is unrelated to the assignee's department.
        $outsiderId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->computerId]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->computerId]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $this
            ->actingAsSessionUser($outsiderId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $staffId])
            ->assertStatus(403);

        $this->assertNull(DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
    }

    public function test_department_mismatch_alone_never_causes_a_403_or_422(): void
    {
        // The acting admin IS in the report's department, so authorization
        // passes; only the assignee's department differs. That must succeed.
        $electricalAdminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]);
        $computerStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->computerId]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $response = $this
            ->actingAsSessionUser($electricalAdminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $computerStaffId]);

        $response->assertOk();
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotSame(422, $response->getStatusCode());
        $this->assertSame($computerStaffId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
    }

    public function test_existing_assignment_validation_is_unchanged(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]); // Head of the report's department assigns (2026-09-27)
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => 999999])
            ->assertStatus(422);

        $this->assertNull(DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
    }

    public function test_existing_assignment_notification_is_unchanged(): void
    {
        $reportId = $this->assignAcrossDepartments();

        $assigneeId = (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to');
        $notification = DB::table('notifications')->where('user_id', $assigneeId)->first();

        $this->assertNotNull($notification, 'The assigned personnel must still be notified across departments.');
        $this->assertStringContainsString('Assigned', $notification->title);
        $this->assertSame('report', $notification->entity_type);
        $this->assertSame($reportId, (int) $notification->entity_id);
    }

    // ---------------------------------------------------------------
    // 14. No duplicate assignment.
    // ---------------------------------------------------------------

    public function test_repeated_submissions_do_not_produce_a_duplicate_assignment(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]); // Head of the report's department assigns (2026-09-27)
        $euclideId = $this->seedUser([
            'role' => 'maintenance_staff',
            'full_name' => 'Euclide',
            'department_id' => $this->computerId,
        ]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $client = $this->actingAsSessionUser($adminId, 'maintenance_admin');
        $client->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $euclideId])->assertOk();
        $client->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $euclideId])->assertOk();

        $this->assertSame($euclideId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('assigned_to'));
        $this->assertSame(1, DB::table('notifications')->count(), 'Re-submitting the same assignee must not re-notify.');

        // Client side, a second submit cannot even be started while the first
        // one (including its open confirmation dialog) is still in flight.
        $this->assertStringContainsString('let isStatusUpdateInFlight = false;', $this->assignmentPageSource());

        $handler = $this->statusUpdateSubmitHandlerSource();
        $this->assertMatchesRegularExpression(
            '/if \(isStatusUpdateInFlight\) \{\s*return;\s*\}/',
            $handler,
            'A submit arriving while another is in flight must be dropped.'
        );
        $this->assertLessThan(
            strpos($handler, 'const proceedWithAssignment = await confirmCrossDepartmentAssignment('),
            strpos($handler, 'isStatusUpdateInFlight = true;'),
            'The in-flight guard must be raised before the dialog opens, not after.'
        );
        $this->assertLessThan(
            strpos($handler, 'const proceedWithAssignment = await confirmCrossDepartmentAssignment('),
            strpos($handler, 'btn.disabled = true;'),
            'The submit button must be disabled before the dialog opens, not after.'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Performs one Electrical-report -> Computer-personnel assignment. */
    private function assignAcrossDepartments(): int
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->electricalId]); // Head of the report's department assigns (2026-09-27)
        $euclideId = $this->seedUser([
            'role' => 'maintenance_staff',
            'full_name' => 'Euclide',
            'department_id' => $this->computerId,
        ]);
        $reportId = $this->seedReport(['department_id' => $this->electricalId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $euclideId])
            ->assertOk();

        return $reportId;
    }

    /** @return array{report_department_id: ?int, assignee_department_id: ?int, cross_department: bool} */
    private function assignmentMeta(int $reportId): array
    {
        $log = DB::table('activity_logs')
            ->where('action', 'ASSIGN_REPORT')
            ->where('entity_id', $reportId)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Expected an ASSIGN_REPORT activity log entry.');

        $meta = json_decode((string) $log->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('report_department_id', $meta);
        $this->assertArrayHasKey('assignee_department_id', $meta);
        $this->assertArrayHasKey('cross_department', $meta);

        return [
            'report_department_id' => $meta['report_department_id'] !== null ? (int) $meta['report_department_id'] : null,
            'assignee_department_id' => $meta['assignee_department_id'] !== null ? (int) $meta['assignee_department_id'] : null,
            'cross_department' => (bool) $meta['cross_department'],
        ];
    }

    private function assignmentPageSource(): string
    {
        $path = self::ASSIGNMENT_PAGE;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The status/assignment submit handler only. The page contains several
     * other fetch() calls (report loading, need-change approval), so ordering
     * assertions must be scoped to this handler to mean anything.
     */
    private function statusUpdateSubmitHandlerSource(): string
    {
        $page = $this->assignmentPageSource();
        $start = strpos($page, "document.getElementById('status-update-form').addEventListener('submit'");
        $this->assertNotFalse($start, 'The status/assignment submit handler must exist.');

        $end = strpos($page, "document.getElementById('approve-need-change-btn')", $start);
        $this->assertNotFalse($end, 'Expected the need-change handler to follow the submit handler.');

        return substr($page, $start, $end - $start);
    }

    private function seedDepartment(string $name): int
    {
        return DB::table('departments')->insertGetId([
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'department_id');
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
            'title' => 'Broken Socket',
            'description' => 'Wall socket sparking in the lab.',
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
