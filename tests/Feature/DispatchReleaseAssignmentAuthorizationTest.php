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
 * Primary/Additional hierarchy removal — this file was fully rewritten. A
 * dispatch's Release Personnel is now a flat, equal roster stored in
 * dispatch_personnel_assignments; there is no longer a single
 * release_assigned_to "primary" person with a separate reassignment endpoint.
 * `POST {dispatch}/assign-personnel` no longer exists — it is replaced by
 * `POST {dispatch}/add-personnel` and `POST {dispatch}/remove-personnel`,
 * each adding/removing ONE roster member at a time.
 *
 * Every test here drives a real HTTP request, because the requirement is that
 * these rules hold SERVER-SIDE — "client-side filtering alone is NOT
 * sufficient". Each one therefore sends exactly the request a malicious or
 * out-of-date client would send, bypassing whatever the UI would have allowed,
 * and asserts the server refuses it and that no state changed.
 *
 * Three properties are under test:
 *
 *   1. RELEASE PERSONNEL ELIGIBILITY. Department-based restriction (TASK 9)
 *      on who may be assigned as Release Personnel was REMOVED per product
 *      decision — see DispatchAuthorizationService's class doc comment.
 *      `dispatches.department_id` is a reporting/attribution tag only; it is
 *      not a property of the items in the dispatch and never restricted who
 *      may hand off stock. Any active Head Maintenance or Maintenance Staff
 *      member is now a valid roster candidate, regardless of department. What
 *      still MUST fail: an inactive user, an Administrator, or (for add/
 *      remove) a dispatch that is already released/cancelled.
 *
 *   2. ROSTER-MEMBERSHIP-ONLY RELEASE. "Server must validate: Authenticated
 *      user is a MEMBER of the dispatch's personnel roster. Role alone is NOT
 *      enough, and — since the hierarchy removal — being the FIRST person
 *      added is not special either." Any roster member, first or not, may
 *      release; nobody outside the roster may.
 *
 *   3. VISIBILITY. Maintenance Staff see only dispatches whose roster they
 *      are on — including as a non-first member, which is the pre-existing
 *      bug this change fixes (an "additional" person could never see their
 *      own dispatch under the old release_assigned_to-only scoping).
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
    // 1. Release Personnel eligibility — creation
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
                'personnel_ids' => [$staffId],
            ]);

        $response->assertStatus(201);
        $dispatchId = (int) $response->json('data.dispatch_id');
        $this->assertTrue(
            DB::table('dispatch_personnel_assignments')
                ->where('dispatch_id', $dispatchId)
                ->where('personnel_user_id', $staffId)
                ->exists()
        );
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
                'personnel_ids' => [$foreignStaffId],
            ]);

        $response->assertStatus(201);
        $dispatchId = (int) $response->json('data.dispatch_id');
        $this->assertTrue(
            DB::table('dispatch_personnel_assignments')
                ->where('dispatch_id', $dispatchId)
                ->where('personnel_user_id', $foreignStaffId)
                ->exists()
        );
    }

    /**
     * A dispatch may name several Release Personnel at once, all equal — no
     * primary/additional split. Every one of them lands in the flat roster
     * from the single create call; createDispatch() no longer requires a
     * second round of individual /add-personnel calls for the initial set.
     */
    public function test_a_dispatch_can_be_created_with_multiple_equal_release_personnel(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'personnel_ids' => [$staffA, $staffB],
            ]);

        $response->assertStatus(201);
        $dispatchId = (int) $response->json('data.dispatch_id');
        $rosterIds = DB::table('dispatch_personnel_assignments')
            ->where('dispatch_id', $dispatchId)
            ->pluck('personnel_user_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expected = [$staffA, $staffB];
        sort($expected);
        $this->assertSame($expected, $rosterIds);
    }

    public function test_a_dispatch_cannot_be_created_with_an_empty_roster(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'personnel_ids' => [],
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('dispatches')->count());
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
                'personnel_ids' => [$otherHeadId],
            ])
            ->assertStatus(201);

        // An Administrator is still refused.
        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'personnel_ids' => [$adminId],
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('Head Maintenance or EELab Staff', (string) $response->json('message'));
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
                'personnel_ids' => [$inactiveStaffId],
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
    // 2. Roster-membership-only release
    // -----------------------------------------------------------------

    public function test_a_roster_member_can_release(): void
    {
        [$adminId, $staffId, $dispatchId, $itemId] = $this->seedApprovedDispatch();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    /**
     * Primary/Additional hierarchy removal — a roster of TWO people, neither
     * one "primary". The second-added person must be just as able to release
     * as the first.
     */
    public function test_a_non_first_roster_member_can_also_release(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [], [$staffA, $staffB]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffB, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
    }

    /**
     * THE core security rule of TASK 13. This user has the correct role, is
     * active, and is in the same department — and must still be refused,
     * because they are not on the roster.
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

    public function test_a_maintenance_staff_member_cannot_release_a_dispatch_with_an_empty_roster(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        // A legacy/edge row: approved, roster empty (pre-TASK-13 shape, or a
        // dispatch whose roster was removed down to zero after approval).
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
        $dispatchId = $this->seedDispatch($itemId, 2, [], [$staffId]);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $headId])
            ->assertStatus(403);

        $this->assertSame('pending', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
    }

    public function test_administrator_cannot_add_release_personnel(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [], [$staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/add-personnel", ['personnel_user_id' => $otherStaffId])
            ->assertStatus(403);

        $this->assertFalse(
            DB::table('dispatch_personnel_assignments')
                ->where('dispatch_id', $dispatchId)
                ->where('personnel_user_id', $otherStaffId)
                ->exists()
        );
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
                'personnel_ids' => [$staffId],
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
        // The named release personnel is still recorded — in the flat roster,
        // not the now write-once-historical release_assigned_to column.
        $this->assertTrue(
            DB::table('dispatch_personnel_assignments')
                ->where('dispatch_id', $dispatch->id)
                ->where('personnel_user_id', $staffId)
                ->exists()
        );

        // Creating still does not touch stock — that remains release-only.
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    // -----------------------------------------------------------------
    // 4. Roster modification window (add / remove personnel)
    // -----------------------------------------------------------------

    public function test_head_may_add_and_remove_personnel_while_pending_and_while_approved(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        // 2026-10-28 — canAssignReleasePersonnel() now also requires the
        // actor be this dispatch's creator OR already on its roster (not
        // just "any Head Maintenance account"). $headId is the one managing
        // this roster below, so it must be recorded as the creator, exactly
        // as the real create flow would have recorded it.
        $dispatchId = $this->seedDispatch($itemId, 2, ['department_id' => self::DEPT_COMPUTER, 'requested_by' => $headId], [$staffA]);

        // While pending: add staffB, then remove staffA.
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/add-personnel", ['personnel_user_id' => $staffB])
            ->assertOk();
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/remove-personnel", ['personnel_user_id' => $staffA])
            ->assertOk();

        $rosterIds = DB::table('dispatch_personnel_assignments')
            ->where('dispatch_id', $dispatchId)
            ->pluck('personnel_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertSame([$staffB], $rosterIds);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        // While approved but not released: add staffA back.
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/add-personnel", ['personnel_user_id' => $staffA])
            ->assertOk();

        $rosterIds = DB::table('dispatch_personnel_assignments')
            ->where('dispatch_id', $dispatchId)
            ->pluck('personnel_user_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $expected = [$staffA, $staffB];
        sort($expected);
        $this->assertSame($expected, $rosterIds);
    }

    /**
     * 2026-10-28 — THE fix for the reported bug: an uninvolved Head
     * Maintenance account (one who neither created this dispatch nor is on
     * its roster) must no longer be able to manage it, even though "any
     * maintenance_admin" used to be sufficient. Mirrors the real-world
     * report: Head A's dispatch assigns Head/Staff B as release personnel;
     * an unrelated Head C must not see or be able to use Add/Remove
     * Personnel on it.
     */
    public function test_an_uninvolved_head_cannot_add_or_remove_personnel_on_someone_elses_dispatch(): void
    {
        $creatorHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $uninvolvedHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaff = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['requested_by' => $creatorHeadId], [$staffA]);

        $this->actingAsSessionUserWithFlatKeys($uninvolvedHeadId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/add-personnel", ['personnel_user_id' => $otherStaff])
            ->assertStatus(403);
        $this->actingAsSessionUserWithFlatKeys($uninvolvedHeadId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/remove-personnel", ['personnel_user_id' => $staffA])
            ->assertStatus(403);

        $rosterIds = DB::table('dispatch_personnel_assignments')
            ->where('dispatch_id', $dispatchId)
            ->pluck('personnel_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertSame([$staffA], $rosterIds, 'An uninvolved Head must not be able to change a roster they have no part in.');
    }

    /**
     * 2026-10-28 — the mirror-image positive case: a Head who did NOT create
     * the dispatch, but who IS on its roster (a genuinely valid scenario
     * since TASK 57 widened Release Personnel eligibility to include Head
     * Maintenance), must still be able to manage that roster.
     */
    public function test_a_head_who_is_on_the_roster_but_did_not_create_the_dispatch_can_still_manage_it(): void
    {
        $creatorHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $rosterHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['requested_by' => $creatorHeadId], [$rosterHeadId]);

        $this->actingAsSessionUserWithFlatKeys($rosterHeadId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/add-personnel", ['personnel_user_id' => $staffA])
            ->assertOk();

        $this->assertTrue(
            DB::table('dispatch_personnel_assignments')
                ->where('dispatch_id', $dispatchId)
                ->where('personnel_user_id', $staffA)
                ->exists()
        );
    }

    /**
     * Removing a roster member down to zero is explicitly ALLOWED per product
     * decision — a zero-personnel dispatch simply becomes unapprovable (or,
     * if already approved, un-releasable) until someone is added back. It is
     * not blocked at removal time.
     */
    public function test_removing_the_last_roster_member_is_allowed_and_leaves_the_roster_empty(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        // 2026-10-28 — see the sibling test above: $headId must be recorded
        // as the creator so canAssignReleasePersonnel()'s per-dispatch check
        // allows the removal below.
        $dispatchId = $this->seedDispatch($itemId, 2, ['requested_by' => $headId], [$staffA]);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/remove-personnel", ['personnel_user_id' => $staffA])
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('dispatch_personnel_assignments')->where('dispatch_id', $dispatchId)->count()
        );

        $this->actingAsSessionUserWithFlatKeys($this->seedUser(['role' => 'super_admin']), 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => 1])
            ->assertStatus(400);
    }

    public function test_roster_becomes_read_only_once_released(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => self::DEPT_COMPUTER]);
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['department_id' => self::DEPT_COMPUTER], [$staffA]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();
        $this->actingAsSessionUserWithFlatKeys($staffA, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/add-personnel", ['personnel_user_id' => $staffB])
            ->assertStatus(403);
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/remove-personnel", ['personnel_user_id' => $staffA])
            ->assertStatus(403);

        $rosterIds = DB::table('dispatch_personnel_assignments')
            ->where('dispatch_id', $dispatchId)
            ->pluck('personnel_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertSame([$staffA], $rosterIds);
    }

    // -----------------------------------------------------------------
    // 5. Staff visibility scoping
    // -----------------------------------------------------------------

    public function test_maintenance_staff_only_see_dispatches_on_their_roster(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 30, 'reserved_quantity' => 0]);
        $mine = $this->seedDispatch($itemId, 1, [], [$staffId]);
        $this->seedDispatch($itemId, 1, [], [$otherStaffId]);
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

    /**
     * The visibility-gap fix: a non-first roster member must see the
     * dispatch too. Under the old release_assigned_to-only scoping this
     * second person — correctly on the roster, just not "primary" — could
     * never see a dispatch they were genuinely assigned to.
     */
    public function test_a_non_first_roster_member_can_see_the_dispatch_in_their_list(): void
    {
        $staffA = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $staffB = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_ELECTRICAL]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 1, [], [$staffA, $staffB]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffB, 'maintenance_staff')
            ->getJson('/api/dispatches');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertSame([$dispatchId], $ids);

        $this->actingAsSessionUserWithFlatKeys($staffB, 'maintenance_staff')
            ->getJson("/api/dispatches/{$dispatchId}")
            ->assertOk();
    }

    public function test_maintenance_staff_cannot_read_a_dispatch_not_on_their_roster(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => self::DEPT_COMPUTER]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 1, [], [$otherStaffId]);

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
        $this->seedDispatch($itemId, 1, [], [$staffId]);
        $this->seedDispatch($itemId, 1, [], [$otherStaffId]);

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
        $dispatchId = $this->seedDispatch($itemId, 2, [], [$staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        return [$adminId, $staffId, $dispatchId, $itemId];
    }

    /**
     * Primary/Additional hierarchy removal — $personnelIds writes the flat
     * roster directly into dispatch_personnel_assignments (the pivot table),
     * replacing the old single release_assigned_to column override. Pass []
     * (the default) to seed a dispatch with an empty roster, which is a
     * legitimate, intentionally-supported state (pre-TASK-13 legacy rows, or
     * a roster removed down to zero).
     */
    private function seedDispatch(int $itemId, int $quantity, array $overrides = [], array $personnelIds = []): int
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

        foreach ($personnelIds as $personnelId) {
            DB::table('dispatch_personnel_assignments')->insert([
                'dispatch_id' => $dispatchId,
                'personnel_user_id' => $personnelId,
                'assigned_by' => null,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $dispatchId;
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dispatch_personnel_assignments');
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
        $this->createDispatchPersonnelAssignmentsTable();

        Schema::enableForeignKeyConstraints();
    }
}
