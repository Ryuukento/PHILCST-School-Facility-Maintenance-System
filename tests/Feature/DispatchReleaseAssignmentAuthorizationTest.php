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
 * TASK 13 — Dispatch Release Assignment Workflow: AUTHORIZATION.
 *
 * Every test here drives a real HTTP request, because the requirement is that
 * these rules hold SERVER-SIDE — "client-side filtering alone is NOT
 * sufficient". Each one therefore sends exactly the request a malicious or
 * out-of-date client would send, bypassing whatever the UI would have allowed,
 * and asserts the server refuses it and that no state changed.
 *
 * Two properties are under test:
 *
 *   1. RELEASE PERSONNEL ELIGIBILITY. Department-based restriction (TASK 9)
 *      on who may be assigned as Release Personnel was REMOVED per product
 *      decision — see DispatchAuthorizationService's class doc comment.
 *      `dispatches.department_id` is a reporting/attribution tag only; it is
 *      not a property of the items in the dispatch and never restricted who
 *      may hand off stock. Any active Maintenance Staff member is now a valid
 *      candidate for either role, regardless of department. What still MUST
 *      fail: an inactive user, a non-Maintenance-Staff user, or (for
 *      reassignment) a dispatch that is already released/cancelled.
 *
 *   2. ASSIGNEE-ONLY RELEASE. "Server must validate: Authenticated user ==
 *      release_assigned_to. Role alone is NOT enough." Holding the
 *      maintenance_staff role must not be sufficient to release somebody
 *      else's dispatch.
 */
class DispatchReleaseAssignmentAuthorizationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const DEPT_COMPUTER = 1;
    private const DEPT_ELECTRICAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_release_assignment_authz_testing');
        $this->createTestSchema();
        $this->roomId = $this->seedRoom();
        $this->forceLocalTestUrl();
    }

    /** TASK 60 — room_id is now the required location field on every create. */
    private int $roomId;

    // -----------------------------------------------------------------
    // 1. Release Personnel eligibility — assignment
    // -----------------------------------------------------------------

    public function test_head_can_assign_staff_from_their_own_department_at_creation(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);
        $this->assertSame($staffId, (int) DB::table('dispatches')->where('id', $response->json('data.dispatch_id'))->value('release_assigned_to'));
    }

    /**
     * Department-based restriction was REMOVED (see
     * DispatchAuthorizationService's class doc comment): a Computer
     * Department Head assigning Electrical staff must now SUCCEED — the
     * target is a genuinely active Maintenance Staff member, and department
     * is a reporting tag, not an eligibility filter.
     */
    public function test_head_can_assign_staff_from_another_department_at_creation(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $foreignStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

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

    public function test_head_can_assign_staff_from_another_department_when_reassigning(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $ownStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $foreignStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 1, [
            'department_id' => self::DEPT_COMPUTER,
            'requested_by' => $headId,
            'release_assigned_to' => $ownStaffId,
            'release_assigned_by' => $headId,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/assign-personnel", [
                'release_assigned_to' => $foreignStaffId,
            ]);

        $response->assertOk();
        $this->assertSame($foreignStaffId, (int) DB::table('dispatches')->where('id', $dispatchId)->value('release_assigned_to'));
    }

    /**
     * Reassignment authorization (TASK 13) checks role (must be
     * maintenance_admin) and dispatch status (must be pending/approved) —
     * it never checked "did this Head create this dispatch", so there is no
     * ownership gate here to preserve. The department-matching check that
     * used to ALSO gate this (a Head could only touch dispatches tagged with
     * their own department) was removed along with the rest of the
     * department-based restriction (see DispatchAuthorizationService's class
     * doc comment) — any Head Maintenance may reassign the release personnel
     * of any still-pending-or-approved dispatch.
     */
    public function test_head_can_reassign_a_dispatch_belonging_to_another_department(): void
    {
        $foreignHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_ELECTRICAL]);
        $foreignStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $computerStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 1, [
            'department_id' => self::DEPT_COMPUTER,
            'release_assigned_to' => $computerStaffId,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($foreignHeadId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/assign-personnel", [
                'release_assigned_to' => $foreignStaffId,
            ]);

        $response->assertOk();
        $this->assertSame($foreignStaffId, (int) DB::table('dispatches')->where('id', $dispatchId)->value('release_assigned_to'));
    }

    /**
     * TASK 57 — Head Maintenance (maintenance_admin) is now an eligible
     * Release Personnel choice; only Administrator (super_admin) remains
     * excluded. Was named
     * `test_release_personnel_cannot_be_an_administrator_or_another_head`
     * before this task; split in two so each half states one fact.
     */
    public function test_release_personnel_can_be_another_head_but_not_an_administrator(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $otherHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        // Another Head is now a valid choice.
        $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $otherHeadId,
            ])
            ->assertStatus(201);

        // An Administrator is still refused.
        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $adminId,
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('Head Maintenance or Maintenance Staff', (string) $response->json('message'));
    }

    public function test_release_personnel_cannot_be_an_inactive_user(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $inactiveStaffId = $this->seedUser([
            'role' => 'maintenance_staff',
            'department_id' => self::DEPT_COMPUTER,
            'status' => 'inactive',
        ]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $inactiveStaffId,
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('inactive', (string) $response->json('message'));
    }

    /**
     * TASK 57 — the personnel picker's candidate list is every active Head
     * Maintenance OR Maintenance Staff member, regardless of department —
     * department-based scoping of this endpoint was REMOVED (see
     * DispatchAuthorizationService's class doc comment). An Administrator
     * (super_admin) is still never offered.
     */
    public function test_release_personnel_endpoint_returns_all_active_heads_and_staff_regardless_of_department(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $ownStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER, 'full_name' => 'Computer Staff']);
        $foreignStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL, 'full_name' => 'Electrical Staff']);
        $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER, 'full_name' => 'Retired Staff', 'status' => 'inactive']);
        $anotherHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER, 'full_name' => 'Another Head']);
        $this->seedUser(['role' => 'super_admin', 'full_name' => 'An Administrator']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->getJson('/api/dispatches/support/release-personnel');

        $response->assertOk();
        $ids = collect($response->json('data.users'))->pluck('user_id')->all();

        sort($ids);
        // The querying Head themself (headId) is also an active Head Maintenance
        // account and is therefore correctly included in their own candidate list.
        $expected = [$headId, $ownStaffId, $foreignStaffId, $anotherHeadId];
        sort($expected);
        $this->assertSame($expected, $ids, 'Every active Head Maintenance or Maintenance Staff member must be offered, regardless of department; inactive users and Administrators must not.');
    }

    /**
     * A client-supplied department_id query parameter has no effect — the
     * endpoint takes no department parameter at all now that the
     * department-based restriction has been removed. Same result with or
     * without it.
     */
    public function test_release_personnel_endpoint_ignores_a_client_supplied_department(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $ownStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $foreignStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->getJson('/api/dispatches/support/release-personnel?department_id=' . self::DEPT_ELECTRICAL);

        $response->assertOk();
        $ids = collect($response->json('data.users'))->pluck('user_id')->all();

        sort($ids);
        // TASK 57 — the querying Head is themself an active Head Maintenance
        // account and is therefore correctly included in their own candidate
        // list, alongside both Maintenance Staff regardless of department.
        $expected = [$headId, $ownStaffId, $foreignStaffId];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    // -----------------------------------------------------------------
    // 2. Assignee-only release
    // -----------------------------------------------------------------

    public function test_the_assigned_staff_member_can_release(): void
    {
        [$adminId, $staffId, $dispatchId, $itemId] = $this->seedApprovedDispatch();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    /**
     * THE core security rule of TASK 13. This user has the correct role, is
     * active, and is in the same department — and must still be refused,
     * because they are not the assignee.
     */
    public function test_a_different_staff_member_cannot_release_someone_elses_dispatch(): void
    {
        [$adminId, $staffId, $dispatchId, $itemId] = $this->seedApprovedDispatch();
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($otherStaffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", []);

        $response->assertStatus(403);
        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'), 'A refused release must move no stock.');
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    public function test_a_maintenance_staff_member_cannot_release_an_unassigned_dispatch(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        // A legacy row: approved under the old workflow, never assigned.
        $dispatchId = $this->seedDispatch($itemId, 2, ['status' => 'approved', 'approved_by' => $adminId]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", []);

        $response->assertStatus(403);
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    // -----------------------------------------------------------------
    // 3. Role separation
    // -----------------------------------------------------------------

    public function test_head_maintenance_cannot_approve_a_dispatch(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $headId])
            ->assertStatus(403);

        $this->assertSame('pending', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
    }

    public function test_administrator_cannot_assign_release_personnel(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/assign-personnel", ['release_assigned_to' => $otherStaffId])
            ->assertStatus(403);

        $this->assertSame($staffId, (int) DB::table('dispatches')->where('id', $dispatchId)->value('release_assigned_to'));
    }

    /**
     * TASK 41 — REPLACES `test_administrator_cannot_create_a_dispatch`.
     *
     * This test previously asserted that an Administrator receives 403 from
     * POST /api/dispatches and that no dispatch row appears. That was a correct
     * statement of the TASK 13 business rule, under which only Head Maintenance
     * could create a dispatch. The business rule has since been changed by the
     * system owner: an Administrator may now create dispatches, and because
     * the Administrator IS the approval authority, their own dispatch has no
     * approval step to wait for.
     *
     * The test is not deleted — it is inverted, so the file still pins down
     * exactly what an Administrator's create request does. The assertions below
     * are the mirror image of the ones they replace: same request, opposite
     * expected outcome, plus the two facts that make the new behaviour safe
     * (it is 'approved' WITHOUT a forged approver, and nothing was deducted).
     */
    public function test_administrator_can_create_a_dispatch_that_needs_no_approval(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.approval_required'));

        $dispatch = DB::table('dispatches')->where('id', $response->json('data.dispatch_id'))->first();

        // It skips 'pending' entirely and is immediately releasable.
        $this->assertSame('approved', $dispatch->status);
        // TASK 57 — no approval STEP occurred, but the creating Administrator
        // is now recorded as the approver so the UI never shows a blank
        // "Approved By" for a legitimate, approval-exempt dispatch.
        $this->assertSame($adminId, (int) $dispatch->approved_by);
        $this->assertNotNull($dispatch->approved_at);
        // The named release personnel is still recorded, exactly as for a
        // Head-created dispatch.
        $this->assertSame($staffId, (int) $dispatch->release_assigned_to);

        // Creating still does not touch stock — that remains release-only.
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    // -----------------------------------------------------------------
    // 4. Reassignment window
    // -----------------------------------------------------------------

    public function test_head_may_reassign_while_pending_and_while_approved(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'department_id' => self::DEPT_COMPUTER,
            'release_assigned_to' => $staffA,
            'release_assigned_by' => $headId,
        ]);

        // While pending.
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/assign-personnel", ['release_assigned_to' => $staffB])
            ->assertOk();
        $this->assertSame($staffB, (int) DB::table('dispatches')->where('id', $dispatchId)->value('release_assigned_to'));

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        // While approved but not released.
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/assign-personnel", ['release_assigned_to' => $staffA])
            ->assertOk();
        $this->assertSame($staffA, (int) DB::table('dispatches')->where('id', $dispatchId)->value('release_assigned_to'));
    }

    public function test_assignment_becomes_read_only_once_released(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'department_id' => self::DEPT_COMPUTER,
            'release_assigned_to' => $staffA,
            'release_assigned_by' => $headId,
        ]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();
        $this->actingAsSessionUserWithFlatKeys($staffA, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/assign-personnel", ['release_assigned_to' => $staffB])
            ->assertStatus(403);

        $this->assertSame($staffA, (int) DB::table('dispatches')->where('id', $dispatchId)->value('release_assigned_to'));
    }

    // -----------------------------------------------------------------
    // 5. Staff visibility scoping
    // -----------------------------------------------------------------

    public function test_maintenance_staff_only_see_dispatches_assigned_to_them(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 30, 'reserved_quantity' => 0]);
        $mine = $this->seedDispatch($itemId, 1, ['release_assigned_to' => $staffId]);
        $this->seedDispatch($itemId, 1, ['release_assigned_to' => $otherStaffId]);
        $this->seedDispatch($itemId, 1);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->getJson('/api/dispatches');

        $response->assertOk();
        // index() returns the paginator itself under `data`, so the rows live
        // at data.data.
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$mine], $ids);
    }

    public function test_maintenance_staff_cannot_read_a_dispatch_not_assigned_to_them(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 1, ['release_assigned_to' => $otherStaffId]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->getJson("/api/dispatches/{$dispatchId}")
            ->assertStatus(403);
    }

    public function test_head_and_administrator_still_see_every_dispatch(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 30, 'reserved_quantity' => 0]);
        $this->seedDispatch($itemId, 1, ['release_assigned_to' => $staffId]);
        $this->seedDispatch($itemId, 1, ['release_assigned_to' => $otherStaffId]);

        foreach ([[$headId, 'maintenance_admin'], [$adminId, 'super_admin']] as [$userId, $role]) {
            $response = $this
                ->actingAsSessionUserWithFlatKeys($userId, $role)
                ->getJson('/api/dispatches');

            $response->assertOk();
            $this->assertCount(2, $response->json('data.data'), "Monitoring must not be narrowed for {$role}.");
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0:int,1:int,2:int,3:int} [adminId, assignedStaffId, dispatchId, itemId]
     */
    private function seedApprovedDispatch(): array
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        return [$adminId, $staffId, $dispatchId, $itemId];
    }

    private function seedDispatch(int $itemId, int $quantity, array $overrides = []): int
    {
        $dispatchId = DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid('', true),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        DB::table('dispatch_items')->insert([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dispatchId;
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createRoomsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createMaintenanceReportsTable();

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
        });

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }
}
