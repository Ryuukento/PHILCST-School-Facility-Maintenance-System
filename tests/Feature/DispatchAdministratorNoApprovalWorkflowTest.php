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
 * TASK 41 — Administrator Create Dispatch WITHOUT Approval.
 *
 * Two dispatch workflows now coexist, and this file exists to prove that
 * BOTH of them behave exactly as specified — the new one works, and the old
 * one was not weakened to make it work.
 *
 *   WORKFLOW A — Head Maintenance (UNCHANGED)
 *     Create -> pick Release Personnel -> PENDING -> Administrator approves
 *     -> APPROVED -> assigned personnel releases -> InventoryTransaction ->
 *     stock deduction -> Deployment Tracking.
 *
 *   WORKFLOW B — Administrator (NEW)
 *     Create -> pick Release Personnel -> NO APPROVAL STEP -> immediately
 *     releasable -> assigned personnel releases -> InventoryTransaction ->
 *     stock deduction -> Deployment Tracking.
 *
 * Three properties are load-bearing and are asserted repeatedly rather than
 * assumed:
 *
 *   1. INVENTORY IS ONLY DEDUCTED AT RELEASE. Creating a dispatch — by ANY
 *      role, with or without approval — must not move a single unit of stock.
 *      Workflow B removes an approval step, not the release step.
 *
 *   2. THE BYPASS IS ROLE-BOUND TO super_admin AND SESSION-DERIVED. It is
 *      computed from the authenticated session by
 *      DispatchAuthorizationService::creationRequiresApproval(), never read
 *      from the request body, so no crafted payload can claim it.
 *
 *   3. SELF-APPROVAL IS RECORDED, NOT FABRICATED. An Administrator-created
 *      dispatch is written directly to the existing 'approved' state (no new
 *      status, no migration). TASK 57 (superseding this file's original NULL
 *      -approved_by design): approved_by / approved_at ARE now set, to the
 *      creating Administrator and the creation timestamp, so the UI never
 *      shows a blank "Approved By" for a legitimate, approval-exempt
 *      dispatch. "Approval was not required" and "an approval happened at
 *      approve() time" stay distinguishable via approval_required in the
 *      activity log and the fact this dispatch never passed through
 *      approveDispatch() — not via a NULL approved_by anymore.
 *
 * Every test drives a real HTTP request, because these rules must hold
 * SERVER-SIDE. The frontend gate on the Create Dispatch button is a
 * convenience; these are the checks that actually decide.
 */
class DispatchAdministratorNoApprovalWorkflowTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const DEPT_COMPUTER = 1;
    private const DEPT_ELECTRICAL = 2;

    /** TASK 60 — room_id is now the required location field on every create. */
    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_admin_no_approval_testing');
        $this->createTestSchema();
        $this->roomId = $this->seedRoom();
        $this->forceLocalTestUrl();
    }

    // =================================================================
    // ADMINISTRATOR WORKFLOW (scenarios 1-11)
    // =================================================================

    /**
     * SCENARIO 1 — Administrator can access Create Dispatch.
     *
     * The Create Dispatch page cannot function without its Release Personnel
     * selector, and both the page and that selector's endpoint are gated by
     * the same role list. Reaching the endpoint with a 200 proves the route
     * gate genuinely opened for super_admin, rather than the button merely
     * being drawn on a page whose API would still refuse.
     */
    public function test_scenario_01_administrator_can_reach_the_create_dispatch_support_endpoint(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER, 'full_name' => 'Staff One']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->getJson('/api/dispatches/support/release-personnel');

        $response->assertOk();
        // An Administrator has no department of their own. Before this task
        // that meant the selector was scoped to "staff with a NULL department"
        // and always came back empty, which is what made the feature
        // impossible. It must now actually return the staff.
        $this->assertNotEmpty($response->json('data.users'));
    }

    /** SCENARIO 2 — Administrator can create a Dispatch. */
    public function test_scenario_02_administrator_can_create_a_dispatch(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('dispatches')->count());
    }

    /** SCENARIO 3 — Administrator can assign Release Personnel at creation. */
    public function test_scenario_03_administrator_assigns_release_personnel_at_creation(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem();

        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId);
        $dispatch = $this->dispatch($dispatchId);

        $this->assertSame($staffId, (int) $dispatch->release_assigned_to);
        // The Administrator is recorded as the assigner — this is a real,
        // attributable action, unlike the approval that never happened.
        $this->assertSame($adminId, (int) $dispatch->release_assigned_by);
        $this->assertNotNull($dispatch->release_assigned_at);
    }

    /**
     * SCENARIO 4 — Administrator-created Dispatch does not require approval.
     *
     * The dispatch never enters 'pending', so there is no separate approval
     * step to go through. TASK 57: approved_by / approved_at ARE nonetheless
     * populated — with the creating Administrator and the creation time — so
     * the record self-describes as approved rather than showing a blank
     * "Approved By" for a perfectly legitimate dispatch.
     */
    public function test_scenario_04_administrator_created_dispatch_requires_no_approval(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.approval_required'));
        $this->assertSame('approved', $response->json('data.status'));

        $dispatch = $this->dispatch((int) $response->json('data.dispatch_id'));
        $this->assertSame('approved', $dispatch->status);
        $this->assertSame($adminId, (int) $dispatch->approved_by, 'The creating Administrator is recorded as the approver.');
        $this->assertNotNull($dispatch->approved_at);
    }

    /**
     * SCENARIO 5 — Administrator-created Dispatch is immediately eligible for
     * release, with no approve call in between.
     */
    public function test_scenario_05_administrator_created_dispatch_is_immediately_releasable(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem();
        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId);

        // Note there is NO /approve request anywhere in this test.
        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame('released', $this->dispatch($dispatchId)->status);
    }

    /**
     * SCENARIO 6 — Creating the Dispatch does NOT deduct inventory.
     *
     * This is the non-negotiable rule of the whole task. Removing the
     * approval step must not pull the stock movement forward to creation
     * time. Stock is untouched and the ledger is completely empty.
     */
    public function test_scenario_06_creating_as_administrator_does_not_deduct_inventory(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem(10);

        $this->createAsAdmin($adminId, $staffId, $itemId, 2);

        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(0, (int) DB::table('items')->where('id', $itemId)->value('reserved_quantity'));
        $this->assertSame(0, DB::table('inventory_transactions')->count(), 'Creation must write no inventory ledger row at all.');
    }

    /**
     * SCENARIO 7 — Only the ASSIGNED personnel may release it. The dispatch
     * skipping approval must not make it releasable by just anybody holding
     * the maintenance_staff role.
     */
    public function test_scenario_07_only_the_assigned_personnel_may_release_an_administrator_dispatch(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem();
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId);

        $this->actingAsSessionUserWithFlatKeys($otherStaffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertStatus(403);

        $this->assertSame('approved', $this->dispatch($dispatchId)->status);
        $this->assertSame(0, DB::table('inventory_transactions')->count());

        // ...and the genuinely assigned person still can.
        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();
    }

    /**
     * SCENARIO 8 — Release creates the NORMAL InventoryTransaction, written by
     * the existing observer-backed path. No second inventory mutation route
     * was introduced for this workflow.
     */
    public function test_scenario_08_release_creates_the_normal_inventory_transaction(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem(10);
        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId, 3);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $transactions = DB::table('inventory_transactions')->get();
        $this->assertCount(1, $transactions);

        $tx = $transactions->first();
        $this->assertSame('deploy', $tx->transaction_type);
        $this->assertSame($itemId, (int) $tx->item_id);
        $this->assertSame(3, (int) $tx->quantity);
        $this->assertSame($dispatchId, (int) $tx->dispatch_id);
        // The ledger records the staff member who physically released it,
        // never the Administrator who created it.
        $this->assertSame($staffId, (int) $tx->performed_by);
    }

    /** SCENARIO 9 — Release causes the normal stock deduction. */
    public function test_scenario_09_release_deducts_stock_normally(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem(10);
        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId, 3);

        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame(7, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    /**
     * SCENARIO 10 — A released Administrator Dispatch appears in Deployment
     * Tracking exactly like any other released dispatch.
     *
     * Deployment Tracking filters on `d.status = 'released'` and never looks
     * at who created the dispatch or whether it was approved, so nothing had
     * to change there — this test pins that down so a future change to the
     * tracking query cannot silently drop these rows.
     */
    public function test_scenario_10_released_administrator_dispatch_appears_in_deployment_tracking(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem(10);
        $roomId = $this->seedRoom(['name' => 'Lab 101']);
        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId, 2, ['room_id' => $roomId]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $rows = collect($response->json('data.rows'))
            ->where('deployment_source', 'dispatch')
            ->where('dispatch_id', $dispatchId);

        $this->assertCount(1, $rows, 'An Administrator-created dispatch must be tracked like any other.');
        $this->assertSame('Lab 101', $rows->first()['room_name']);
        $this->assertSame(2, (int) $rows->first()['dispatched_qty']);
    }

    /**
     * SCENARIO 11 — No approval notification is generated for an
     * Administrator-created Dispatch, and no duplicate is generated either.
     *
     * There is no approval to request, so nothing may tell anyone the
     * dispatch is "awaiting approval". The assignee DOES still get told there
     * is work waiting for them — otherwise they would never learn of it,
     * since that message is normally sent at the approval step this workflow
     * skips. It is the identical message, sent exactly once.
     */
    public function test_scenario_11_administrator_created_dispatch_sends_no_approval_notification(): void
    {
        [$adminId, $staffId, $itemId] = $this->seedAdminStaffAndItem();

        $dispatchId = $this->createAsAdmin($adminId, $staffId, $itemId);

        $notifications = DB::table('notifications')->get();

        foreach ($notifications as $notification) {
            $this->assertStringNotContainsStringIgnoringCase(
                'approval',
                $notification->title . ' ' . $notification->message,
                'Nothing may claim this dispatch is awaiting approval.'
            );
        }

        $readyForRelease = $notifications
            ->where('user_id', $staffId)
            ->where('title', 'Dispatch Ready For Release');

        $this->assertCount(1, $readyForRelease, 'The assignee is told exactly once that work is waiting.');
        $this->assertSame('dispatch', $readyForRelease->first()->entity_type);
        $this->assertSame($dispatchId, (int) $readyForRelease->first()->entity_id);

        // ...and releasing it does not re-send that same message.
        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertCount(
            1,
            DB::table('notifications')->where('user_id', $staffId)->where('title', 'Dispatch Ready For Release')->get(),
            'No duplicate ready-for-release notification.'
        );
    }

    // =================================================================
    // HEAD MAINTENANCE WORKFLOW — MUST BE UNCHANGED (scenarios 12-15)
    // =================================================================

    /** SCENARIO 12 — Head Maintenance can still create a Dispatch. */
    public function test_scenario_12_head_maintenance_can_still_create_a_dispatch(): void
    {
        [$headId, $staffId, $itemId] = $this->seedHeadStaffAndItem();

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('dispatches')->count());
    }

    /**
     * SCENARIO 13 — A Head Maintenance-created Dispatch STILL requires
     * Administrator approval. The new bypass is not contagious.
     */
    public function test_scenario_13_head_created_dispatch_still_requires_approval(): void
    {
        [$headId, $staffId, $itemId] = $this->seedHeadStaffAndItem();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.approval_required'));
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame('pending', $this->dispatch((int) $response->json('data.dispatch_id'))->status);
    }

    /**
     * SCENARIO 14 — A Head Maintenance-created Dispatch cannot be released
     * before approval, and the failed attempt moves no stock.
     */
    public function test_scenario_14_head_created_dispatch_cannot_be_released_before_approval(): void
    {
        [$headId, $staffId, $itemId] = $this->seedHeadStaffAndItem(10);

        $dispatchId = (int) $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ])->json('data.dispatch_id');

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertStatus(400);

        $this->assertSame('pending', $this->dispatch($dispatchId)->status);
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_transactions')->count());

        // The full Workflow A path still completes normally afterwards.
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", [])
            ->assertOk();

        $approved = $this->dispatch($dispatchId);
        $this->assertSame('approved', $approved->status);
        // A REAL approval, unlike Workflow B, records who performed it.
        $this->assertSame($adminId, (int) $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();
        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    /**
     * SCENARIO 15 — Release Personnel eligibility rules remain intact, EXCEPT
     * department matching, which was REMOVED per product decision (see
     * DispatchAuthorizationService's class doc comment): dispatches'
     * department_id is a reporting/attribution tag, not a property of the
     * items being dispatched, so a Head may now name any active Maintenance
     * Staff member regardless of department. The Administrator's TASK 41
     * exception never becomes a "general hole" because there is no department
     * rule left for it to be an exception to.
     */
    public function test_scenario_15_release_personnel_selection_is_not_department_restricted(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $foreignStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $foreignStaffId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            $foreignStaffId,
            (int) DB::table('dispatches')->where('id', $response->json('data.dispatch_id'))->value('release_assigned_to')
        );
    }

    // =================================================================
    // MAINTENANCE STAFF — MUST GAIN NOTHING (scenarios 16-17)
    // =================================================================

    /** SCENARIO 16 — Maintenance Staff gain no Create Dispatch permission. */
    public function test_scenario_16_maintenance_staff_cannot_create_a_dispatch(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $otherStaffId,
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('dispatches')->count());

        // The selector that feeds the create form is closed to them too, so
        // the UI and the API agree rather than one permitting what the other
        // refuses.
        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->getJson('/api/dispatches/support/release-personnel')
            ->assertStatus(403);
    }

    /**
     * SCENARIO 17 — Maintenance Staff cannot bypass approval through this
     * change. They are stopped before any dispatch exists, so there is no
     * approval-free dispatch for them to obtain by any route.
     */
    public function test_scenario_17_maintenance_staff_cannot_obtain_an_approval_free_dispatch(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10]);

        // Every shape of "please skip approval" a staff member could send.
        foreach ([
            ['approval_required' => false],
            ['status' => 'approved'],
            ['approved_by' => $staffId],
            ['role' => 'super_admin'],
        ] as $extra) {
            $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
                ->postJson('/api/dispatches', array_merge([
                    'items' => [['item_id' => $itemId, 'quantity' => 1]],
                    'room_id' => $this->roomId,
                    'release_assigned_to' => $otherStaffId,
                ], $extra))
                ->assertStatus(403);
        }

        $this->assertSame(0, DB::table('dispatches')->count());
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    // =================================================================
    // SECURITY (scenarios 18-20)
    // =================================================================

    /**
     * SCENARIO 18 — A manually crafted request cannot impersonate an
     * Administrator.
     *
     * The role used for every decision comes from the server-side session.
     * A body that claims a role, or claims to already be approved, changes
     * nothing: the Head's dispatch is still created 'pending' with no
     * approver, exactly as if the extra keys were absent.
     */
    public function test_scenario_18_a_crafted_body_cannot_impersonate_an_administrator(): void
    {
        [$headId, $staffId, $itemId] = $this->seedHeadStaffAndItem();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
                // All of these are attacker-supplied and must be ignored.
                'role' => 'super_admin',
                'user_role' => 'super_admin',
                'approval_required' => false,
                'status' => 'approved',
                'approved_by' => $headId,
                'approved_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.approval_required'));

        $dispatch = $this->dispatch((int) $response->json('data.dispatch_id'));
        $this->assertSame('pending', $dispatch->status);
        $this->assertNull($dispatch->approved_by);
        $this->assertNull($dispatch->approved_at);
    }

    /**
     * SCENARIO 19 — A non-Administrator cannot trigger the no-approval path.
     *
     * Stated as a property rather than a single case: of every role that can
     * reach the create endpoint at all, ONLY super_admin gets a dispatch that
     * skips approval. This is the test that would fail if the rule were ever
     * loosened to, say, "anyone who is not maintenance_staff".
     */
    public function test_scenario_19_only_super_admin_triggers_the_no_approval_path(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 50]);

        $cases = [
            // [role, department_id, expected http status, expected dispatch status]
            ['super_admin', null, 201, 'approved'],
            ['maintenance_admin', self::DEPT_COMPUTER, 201, 'pending'],
            ['maintenance_staff', self::DEPT_COMPUTER, 403, null],
        ];

        $adminActorId = null;

        foreach ($cases as [$role, $departmentId, $expectedHttp, $expectedStatus]) {
            $actorId = $this->seedUser(['role' => $role, 'department_id' => $departmentId]);
            if ($role === 'super_admin') {
                $adminActorId = $actorId;
            }

            $response = $this
                ->actingAsSessionUserWithFlatKeys($actorId, $role)
                ->postJson('/api/dispatches', [
                    'items' => [['item_id' => $itemId, 'quantity' => 1]],
                    'room_id' => $this->roomId,
                    'release_assigned_to' => $staffId,
                ]);

            $response->assertStatus($expectedHttp);

            if ($expectedStatus !== null) {
                $this->assertSame(
                    $expectedStatus,
                    $this->dispatch((int) $response->json('data.dispatch_id'))->status,
                    "Role {$role} produced the wrong created status."
                );
            }
        }

        // Exactly one approval-free dispatch exists, and it is the
        // Administrator's — self-recorded as its own approver (TASK 57).
        $this->assertSame(
            1,
            DB::table('dispatches')->where('status', 'approved')->where('approved_by', $adminActorId)->count()
        );
    }

    /**
     * SCENARIO 20 — Invalid or inactive Release Personnel are rejected, for
     * the Administrator too.
     *
     * Department matching no longer applies to anyone (see
     * DispatchAuthorizationService's class doc comment), so there is nothing
     * left for the Administrator to be exempt from — but every other guard on
     * who may be named as release personnel (active status, eligible role,
     * user must exist) still applies to them.
     *
     * TASK 57 — Head Maintenance (maintenance_admin) is REMOVED from this
     * "bad assignee" list: it is now an eligible role (see SCENARIO 20c).
     * Only Administrator (super_admin) remains ineligible alongside inactive
     * users.
     */
    public function test_scenario_20_invalid_or_inactive_release_personnel_are_rejected_for_administrators(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10]);

        $inactiveStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER, 'status' => 'inactive']);
        $otherAdminId = $this->seedUser(['role' => 'super_admin']);

        foreach ([$inactiveStaffId, $otherAdminId] as $badAssigneeId) {
            $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
                ->postJson('/api/dispatches', [
                    'items' => [['item_id' => $itemId, 'quantity' => 1]],
                    'room_id' => $this->roomId,
                    'release_assigned_to' => $badAssigneeId,
                ])
                ->assertStatus(400);
        }

        // A user id that does not exist at all is refused by validation.
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => 999999,
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('dispatches')->count());
    }

    /**
     * SCENARIO 20c — TASK 57. Head Maintenance (maintenance_admin) is now a
     * valid Release Personnel choice, for both dispatch-creating roles, and a
     * Head assigned this way can actually perform the release — mirroring
     * every guarantee SCENARIO 5/7 already prove for Maintenance Staff.
     * Administrator (super_admin) is proven to remain permanently excluded,
     * even when it is the Administrator naming another Administrator.
     */
    public function test_scenario_20c_head_maintenance_is_an_eligible_release_personnel_but_administrator_is_not(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $otherAdminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10]);

        // A Head may be named as release personnel on an Administrator's dispatch...
        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $headId,
            ]);
        $response->assertStatus(201);
        $dispatchId = (int) $response->json('data.dispatch_id');

        // ...and can actually release it.
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();
        $this->assertSame('released', $this->dispatch($dispatchId)->status);

        // An Administrator naming ANOTHER Administrator is still refused.
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $otherAdminId,
            ])
            ->assertStatus(400);
    }

    /**
     * SCENARIO 20b — the Administrator's chosen destination department (TASK
     * 41's `department_id`) has never constrained who may be named as release
     * personnel since the department-based restriction was REMOVED (see
     * DispatchAuthorizationService's class doc comment): it is a reporting tag
     * only, feeding AnalyticsReportController's department-usage comparison,
     * not an eligibility filter. Electrical staff is accepted even though the
     * chosen destination is Computer.
     */
    public function test_scenario_20b_administrator_personnel_selection_ignores_the_chosen_destination_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $computerStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $electricalStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10]);

        // Destination is the Computer department; Electrical staff is still accepted.
        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'department_id' => self::DEPT_COMPUTER,
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $electricalStaffId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            $electricalStaffId,
            (int) DB::table('dispatches')->where('id', $response->json('data.dispatch_id'))->value('release_assigned_to')
        );

        // The same-department staff member is, of course, still accepted too.
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'department_id' => self::DEPT_COMPUTER,
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $computerStaffId,
            ])
            ->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{0:int,1:int,2:int} [adminId, staffId, itemId] */
    private function seedAdminStaffAndItem(int $quantity = 10): array
    {
        return [
            $this->seedUser(['role' => 'super_admin', 'full_name' => 'The Administrator']),
            $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER, 'full_name' => 'Assigned Staff']),
            $this->seedItem(['name' => 'Mouse', 'quantity' => $quantity, 'reserved_quantity' => 0]),
        ];
    }

    /** @return array{0:int,1:int,2:int} [headId, staffId, itemId] */
    private function seedHeadStaffAndItem(int $quantity = 10): array
    {
        return [
            $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]),
            $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]),
            $this->seedItem(['name' => 'Mouse', 'quantity' => $quantity, 'reserved_quantity' => 0]),
        ];
    }

    private function createAsAdmin(int $adminId, int $staffId, int $itemId, int $quantity = 2, array $extra = []): int
    {
        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', array_merge([
                'items' => [['item_id' => $itemId, 'quantity' => $quantity]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('data.dispatch_id');
    }

    private function dispatch(int $dispatchId): object
    {
        return DB::table('dispatches')->where('id', $dispatchId)->first();
    }

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Test Room',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();

        // asset_code is now built into BuildsSharedTestSchema::createItemsTable()
        // itself (added for the Per Room Report / deployed-items fix), so the
        // additive Schema::table() patch that used to live here would now
        // collide with a duplicate column definition.

        $this->createActivityLogsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();

        // BuildsSharedTestSchema::createInventoryTransactionsTable() predates
        // the dispatch_id FK added by
        // 2026_07_28_002000_add_report_id_relationships_for_maintenance_report_centralization,
        // so patch it in the same way the other dispatch tests do.
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
        });

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

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

        // Minimal rooms table — the create endpoint validates room_id against
        // it, and the Deployment Tracking query LEFT JOINs it for r.name.
        Schema::create('rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        // Deployment Tracking joins these for the source-OR columns. No rows
        // are seeded; the joins are LEFT joins, so the columns come back null.
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('or_number')->unique();
            $table->date('receipt_date');
            $table->string('supplier_name');
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('received_by');
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('proof_image')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('inventory_room_id')->nullable();
            $table->integer('quantity_received');
            $table->string('unit', 20)->default('pc');
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();

        // The create endpoint validates department_id with
        // `exists:departments,department_id`, so the two departments these
        // tests refer to must really exist.
        DB::table('departments')->insert([
            ['department_id' => self::DEPT_COMPUTER, 'name' => 'Computer Department', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['department_id' => self::DEPT_ELECTRICAL, 'name' => 'Electrical Department', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
