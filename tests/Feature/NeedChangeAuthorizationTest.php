<?php

namespace Tests\Feature;

use App\Services\NeedChangeService;
use App\Services\ReportAuthorizationService;
use App\Services\RoleNormalizerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 49 — Need Change Authorization Hardening.
 *
 * TASK 48 audited activity_logs #1220 (action APPROVE_NEED_CHANGE, actor role
 * maintenance_staff) and concluded there was no live bypass — but it recorded
 * weakness W-2: the entire "only an Administrator may approve a Need Change"
 * rule existed as one inline role comparison inside ReportController::update(),
 * with nothing behind it and, crucially, NO negative test. That gate could have
 * been deleted and all 712 existing tests would still have passed.
 *
 * This file is that missing coverage. It is deliberately written so that each
 * denial is asserted through the PUBLIC HTTP surface, and separately against
 * the service, so neither enforcement layer can be removed silently:
 *
 *   - Remove the controller gate  -> the service still throws, the controller
 *                                    maps it to the same 403 + message, and the
 *                                    HTTP tests below still pass; but
 *                                    test_need_change_service_itself_refuses_*
 *                                    is what proves that second layer exists.
 *   - Remove the service gate     -> the direct-service tests fail immediately.
 *
 * Every denial test also asserts the FULL absence of downstream effects, since
 * "returned 403" and "changed nothing" are two different claims.
 */
class NeedChangeAuthorizationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const DEPARTMENT_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('need_change_authorization_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------------
    // A — Maintenance Staff cannot approve a Need Change.
    // ---------------------------------------------------------------------

    /**
     * THE test this task exists to add.
     *
     * The staff user is deliberately constructed to PASS the generic report
     * gate — same department as the report AND named as its assignee, which is
     * exactly what ReportAuthorizationService::canModifyReport() requires of a
     * maintenance_staff user. Without that setup the request would be refused
     * by canModifyReport() before the Need Change rule was ever consulted, and
     * the test would prove nothing about Need Change authorization at all.
     *
     * So this asserts the narrow claim: a Maintenance Staff member who is fully
     * entitled to modify this report is still refused permission to approve its
     * Need Change request.
     */
    public function test_maintenance_staff_assigned_to_the_report_still_cannot_approve_need_change(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Aircon Compressor', 'quantity' => 20, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'assigned_to' => $staffId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 3,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => ReportAuthorizationService::NEED_CHANGE_APPROVE_DENIED_MESSAGE,
        ]);

        $this->assertNeedChangeUntouched($reportId, $itemId, 20);
        $this->assertNoApprovalSideEffects($reportId);
    }

    /**
     * The generic report gate must keep refusing first for a staff member with
     * no relationship to the report — this is the pre-existing TASK 9 rule and
     * this task must not have widened it. Asserting the message proves the
     * refusal came from canModifyReport(), not from the Need Change rule.
     */
    public function test_unrelated_maintenance_staff_is_refused_by_the_report_gate_before_the_need_change_rule(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Door Closer', 'quantity' => 12, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'assigned_to' => null,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 2,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'You are not authorized to modify this report',
        ]);

        $this->assertNeedChangeUntouched($reportId, $itemId, 12);
        $this->assertNoApprovalSideEffects($reportId);
    }

    /**
     * Rejection carries the same restriction as approval and always has. The
     * rejection branch writes need_change_status directly in the controller
     * rather than through a service, so the controller check is its only
     * enforcement point — which is precisely why it needs explicit coverage.
     */
    public function test_maintenance_staff_assigned_to_the_report_cannot_reject_need_change(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Ballast', 'quantity' => 9, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'assigned_to' => $staffId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 1,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", ['reject_need_change' => true]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => ReportAuthorizationService::NEED_CHANGE_REJECT_DENIED_MESSAGE,
        ]);

        $this->assertSame(
            'pending',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('need_change_status')
        );
    }

    /**
     * Head Maintenance raises Need Change requests; approving them is somebody
     * else's decision. This is the existing rule, asserted here so that a future
     * change to canApproveNeedChange() cannot quietly widen it.
     */
    public function test_head_maintenance_cannot_approve_need_change(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Circuit Breaker', 'quantity' => 15, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 4,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => ReportAuthorizationService::NEED_CHANGE_APPROVE_DENIED_MESSAGE,
        ]);

        $this->assertNeedChangeUntouched($reportId, $itemId, 15);
        $this->assertNoApprovalSideEffects($reportId);
    }

    // ---------------------------------------------------------------------
    // The W-2 fix itself — the service refuses on its own account.
    // ---------------------------------------------------------------------

    /**
     * TASK 48 weakness W-2, closed. NeedChangeService::approve() used to accept
     * a bare approver id and perform no authorization whatsoever, trusting that
     * its single caller had already checked. This calls the service DIRECTLY,
     * bypassing the controller entirely, and asserts it refuses by itself.
     *
     * If someone deletes the controller gate, this test still passes and the
     * system is still safe. If someone deletes THIS gate, this test fails.
     */
    public function test_need_change_service_itself_refuses_a_maintenance_staff_actor(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Direct Call Item', 'quantity' => 30, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'assigned_to' => $staffId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 5,
            'need_change_status' => 'pending',
        ]);

        $report = \App\Models\MaintenanceReport::query()->findOrFail($reportId);

        try {
            app(NeedChangeService::class)->approve($report, [
                'user_id' => $staffId,
                'role' => 'maintenance_staff',
                'department_id' => self::DEPARTMENT_ID,
            ]);
            $this->fail('NeedChangeService::approve() must refuse a maintenance_staff actor.');
        } catch (AuthorizationException $e) {
            $this->assertSame(ReportAuthorizationService::NEED_CHANGE_APPROVE_DENIED_MESSAGE, $e->getMessage());
        }

        $this->assertNeedChangeUntouched($reportId, $itemId, 30);
        $this->assertNoApprovalSideEffects($reportId);
    }

    /**
     * The refusal happens BEFORE DB::transaction() opens, so an unauthorized
     * attempt never acquires the report row lock and never reaches the
     * inventory ledger. Asserted by the total absence of any row anywhere
     * above, plus the report's updated_at being untouched here.
     */
    public function test_refused_service_call_does_not_touch_the_report_row_at_all(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Untouched Item', 'quantity' => 8, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'assigned_to' => $staffId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 2,
            'need_change_status' => 'pending',
        ]);

        $before = DB::table('maintenance_reports')->where('report_id', $reportId)->first();

        $report = \App\Models\MaintenanceReport::query()->findOrFail($reportId);

        $this->expectException(AuthorizationException::class);

        try {
            app(NeedChangeService::class)->approve($report, [
                'user_id' => $staffId,
                'role' => 'maintenance_staff',
            ]);
        } finally {
            $after = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
            $this->assertEquals($before, $after, 'A refused approval must leave the report row byte-identical.');
        }
    }

    // ---------------------------------------------------------------------
    // B / E — the authorized role still works, unchanged.
    // ---------------------------------------------------------------------

    /**
     * Preservation test. Everything the legitimate approval flow did before
     * this task must still happen, with the same actor recorded in the same
     * places — the deduction, the ledger row, the approval stamps, the activity
     * log, and the "Replacement Approved" notification.
     */
    public function test_administrator_can_still_approve_need_change_with_all_effects_intact(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin']);
        $creatorId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Replacement Keyboard', 'quantity' => 20, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'created_by' => $creatorId,
            'department_id' => self::DEPARTMENT_ID,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 5,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Need Change approved and inventory deducted successfully',
        ]);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('deducted', $report->need_change_status);
        $this->assertSame($approverId, (int) $report->need_change_approved_by);
        $this->assertNotNull($report->need_change_approved_at);
        $this->assertNotNull($report->need_change_deducted_at);

        // Inventory moved through the ledger, exactly once.
        $this->assertSame(15, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $transactions = DB::table('inventory_transactions')->where('report_id', $reportId)->get();
        $this->assertCount(1, $transactions);
        $this->assertSame('deploy', $transactions[0]->transaction_type);
        $this->assertSame(5, (int) $transactions[0]->quantity);
        $this->assertSame($approverId, (int) $transactions[0]->performed_by);

        // Activity log written, attributed to the approver.
        $log = DB::table('activity_logs')->where('action', 'APPROVE_NEED_CHANGE')->first();
        $this->assertNotNull($log, 'The approval must still be audited.');
        $this->assertSame($approverId, (int) $log->user_id);
        $this->assertSame('super_admin', $log->user_role);
        $this->assertSame($reportId, (int) $log->entity_id);

        // TASK 18 notification still reaches the report's creator.
        $this->assertSame(
            1,
            DB::table('notifications')
                ->where('user_id', $creatorId)
                ->where('title', 'Replacement Approved — Report #' . $reportId)
                ->count()
        );
    }

    /**
     * The approver id stamped on the report is now DERIVED from the authorized
     * session rather than passed alongside it, so the identity that satisfied
     * authorization and the identity recorded as approver cannot diverge. That
     * divergence is the exact shape of the activity_logs #1220 discrepancy.
     */
    public function test_recorded_approver_is_the_authorized_session_user(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPARTMENT_ID]);
        $itemId = $this->seedItem(['name' => 'Attribution Item', 'quantity' => 10, 'reorder_level' => 0]);
        $reportId = $this->seedReport([
            'department_id' => self::DEPARTMENT_ID,
            'assigned_to' => $staffId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 1,
            'need_change_status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true])
            ->assertOk();

        $approvedBy = (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('need_change_approved_by');
        $performedBy = (int) DB::table('inventory_transactions')->where('report_id', $reportId)->value('performed_by');
        $loggedBy = (int) DB::table('activity_logs')->where('action', 'APPROVE_NEED_CHANGE')->value('user_id');

        $this->assertSame($approverId, $approvedBy);
        $this->assertSame($approverId, $performedBy);
        $this->assertSame($approverId, $loggedBy);
        $this->assertNotSame($staffId, $approvedBy);
    }

    // ---------------------------------------------------------------------
    // Step 6 — role normalization, asserted against the one authoritative rule.
    // ---------------------------------------------------------------------

    /**
     * Pins the role vocabulary the rule is evaluated against. The aliases are
     * the real ones from RoleNormalizerService::ALIASES; the point is that no
     * alias, and no absent/blank/cased variant, resolves to super_admin.
     *
     * @dataProvider needChangeApprovalRoleProvider
     */
    public function test_only_super_admin_satisfies_the_need_change_approval_rule(?string $role, bool $expected): void
    {
        $service = app(ReportAuthorizationService::class);
        $authUser = ['user_id' => 42];
        if ($role !== null) {
            $authUser['role'] = $role;
        }

        $this->assertSame($expected, $service->canApproveNeedChange($authUser));
        $this->assertSame($expected, $service->canRejectNeedChange($authUser));
    }

    public static function needChangeApprovalRoleProvider(): array
    {
        return [
            'administrator'                   => ['super_admin', true],
            'head maintenance'                => ['maintenance_admin', false],
            'head maintenance legacy alias'   => ['admin_maintenance', false],
            'maintenance staff'               => ['maintenance_staff', false],
            'maintenance staff legacy alias'  => ['maintenance_personnel', false],
            'eelab staff legacy alias'        => ['eelab_staff', false],
            'blank role'                      => ['', false],
            'whitespace role'                 => ['   ', false],
            'absent role key'                 => [null, false],
            'unknown role'                    => ['department_admin', false],
        ];
    }

    /**
     * Guards the substitution made in this task: canApproveNeedChange() uses
     * RoleNormalizerService::normalize() where the retired inline check used
     * normalizeWithStaffDefault(). Those two differ on exactly one input — the
     * empty string — and this asserts that neither of the two results is
     * super_admin, which is what makes the substitution behaviour-preserving.
     */
    public function test_role_normalizer_behaviour_relied_on_by_the_rule_is_unchanged(): void
    {
        $this->assertSame('maintenance_staff', RoleNormalizerService::normalize('eelab_staff'));
        $this->assertSame('maintenance_staff', RoleNormalizerService::normalize('maintenance_personnel'));
        $this->assertSame('maintenance_admin', RoleNormalizerService::normalize('admin_maintenance'));
        $this->assertSame('super_admin', RoleNormalizerService::normalize('super_admin'));

        $this->assertSame('', RoleNormalizerService::normalize(''));
        $this->assertSame('maintenance_staff', RoleNormalizerService::normalizeWithStaffDefault(''));
        $this->assertNotSame('super_admin', RoleNormalizerService::normalize(''));
        $this->assertNotSame('super_admin', RoleNormalizerService::normalizeWithStaffDefault(''));
    }

    /**
     * An authorized role with no usable user_id must not be able to record an
     * approval attributed to user 0.
     */
    public function test_authorized_role_without_a_user_id_is_refused(): void
    {
        $service = app(ReportAuthorizationService::class);

        $this->assertSame(9, $service->assertCanApproveNeedChange(['user_id' => 9, 'role' => 'super_admin']));

        $this->expectException(AuthorizationException::class);
        $service->assertCanApproveNeedChange(['role' => 'super_admin']);
    }

    // ---------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------

    private function assertNeedChangeUntouched(int $reportId, int $itemId, int $expectedQuantity): void
    {
        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();

        $this->assertSame('pending', $report->need_change_status, 'need_change_status must not change.');
        $this->assertNull($report->need_change_approved_by, 'need_change_approved_by must not be set.');
        $this->assertNull($report->need_change_approved_at, 'need_change_approved_at must not be set.');
        $this->assertNull($report->need_change_deducted_at, 'need_change_deducted_at must not be set.');

        $this->assertSame(
            $expectedQuantity,
            (int) DB::table('items')->where('id', $itemId)->value('quantity'),
            'Stock must not move on a refused approval.'
        );
    }

    private function assertNoApprovalSideEffects(int $reportId): void
    {
        $this->assertSame(
            0,
            DB::table('inventory_transactions')->where('report_id', $reportId)->count(),
            'No inventory transaction may be created by a refused approval.'
        );
        $this->assertSame(
            0,
            DB::table('activity_logs')->where('action', 'APPROVE_NEED_CHANGE')->count(),
            'No success activity log may be written for a refused approval.'
        );
        $this->assertSame(
            0,
            DB::table('activity_logs')->where('action', 'INVENTORY_TRANSACTION')->count(),
            'A refused approval must not reach the inventory ledger observer.'
        );
        $this->assertSame(
            0,
            DB::table('notifications')->count(),
            'No approval notification may be sent for a refused approval.'
        );
        $this->assertSame(
            0,
            DB::table('dispatches')->count(),
            'A refused Need Change approval must not create or modify a Dispatch.'
        );
        $this->assertSame(0, DB::table('dispatch_items')->count());
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

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
            'title' => 'Broken Aircon',
            'description' => 'Unit is not cooling.',
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
            'need_change_status' => 'pending',
            'need_change_approved_by' => null,
            'need_change_approved_at' => null,
            'need_change_deducted_at' => null,
            'completion_proof_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }
}
