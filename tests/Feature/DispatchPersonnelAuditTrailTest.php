<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 3 — Dispatch Personnel Audit Trail, extended by TASK 13.
 *
 * Task 3's rule — the people in a dispatch's lifecycle are DISTINCT
 * responsibilities and must never be conflated — is unchanged. TASK 13 adds a
 * fourth party (the assigned release personnel, plus the Head who assigned
 * them) and separates approval from release, so the trail this file asserts is
 * now:
 *   requested_by         — the Head Maintenance who created the dispatch
 *   release_assigned_to  — the Maintenance Staff who will perform the release
 *   release_assigned_by  — the Head who chose them
 *   approved_by          — the Administrator who authorised it
 *   released_by          — whoever actually performed the release
 *
 * Scenarios:
 *   1. Head Maintenance creates a Dispatch -> requested_by = the creator, and
 *      the chosen release personnel is recorded at creation time
 *   2. Administrator approves -> approved_by set, requested_by AND the
 *      assignment both left untouched
 *   3. Release -> released_by = the assigned staff member, never the
 *      approving Administrator
 *   4. GET /api/dispatches/{id} exposes all five names correctly
 *   5. Regression -> Task 1 inventory deduction still works end to end
 */
class DispatchPersonnelAuditTrailTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_audit_trail_testing');
        $this->createTestSchema();
        $this->roomId = $this->seedRoom();
        $this->forceLocalTestUrl();
    }

    /** TASK 60 — room_id is now the required location field on every create. */
    private int $roomId;

    /**
     * TASK 13 — POST /api/dispatches is now restricted to maintenance_admin
     * (Head Maintenance) alone, because creating a dispatch now includes
     * choosing its release personnel, and an Administrator explicitly may not
     * assign personnel. Task 3's own rule is untouched: requested_by is
     * whichever acting user created the dispatch.
     */
    public function test_creating_a_dispatch_records_the_creator_and_the_chosen_release_personnel(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 7]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 7]);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);

        $dispatch = DB::table('dispatches')->where('id', $response->json('data.dispatch_id'))->first();
        $this->assertSame($headId, (int) $dispatch->requested_by);
        // TASK 13 — the assignment is captured at creation, along with who
        // made it and when, so the trail records all three from the start.
        $this->assertSame($staffId, (int) $dispatch->release_assigned_to);
        $this->assertSame($headId, (int) $dispatch->release_assigned_by);
        $this->assertNotNull($dispatch->release_assigned_at);
        $this->assertNull($dispatch->approved_by);
        $this->assertNull($dispatch->released_by);
    }

    public function test_administrator_approval_sets_approved_by_and_leaves_requester_and_assignment_unchanged(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'requested_by' => $headId,
            'release_assigned_to' => $staffId,
            'release_assigned_by' => $headId,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId]);

        $response->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame($adminId, (int) $dispatch->approved_by);
        $this->assertSame($headId, (int) $dispatch->requested_by, 'requested_by must never be overwritten by approval.');
        // TASK 13 — the Administrator may not assign personnel, so approval
        // must leave the existing assignment exactly as the Head set it.
        $this->assertSame($staffId, (int) $dispatch->release_assigned_to, 'Approval must not touch the assignment.');
        $this->assertSame($headId, (int) $dispatch->release_assigned_by);
    }

    /**
     * TASK 55 — Security & Input Validation Hardening.
     *
     * DispatchController::approve() used to trust a client-supplied
     * 'approved_by' value and persist it VERBATIM into the permanent
     * dispatches.approved_by audit column, even though this endpoint is
     * already restricted to super_admin (routes/web.php) and the real
     * actor's identity is already known from the session — it's the exact
     * value used a few lines later for the activity-log entry this test
     * file's other scenarios assert on. That meant any super_admin could,
     * via a direct API call (never through the UI — dispatch-detail.php's
     * DSP_USER_ID is server-rendered from the session and not an editable
     * form field), misattribute an approval to a DIFFERENT, arbitrary
     * existing user_id, corrupting the very non-repudiation trail this
     * file exists to protect.
     *
     * approved_by must now be derived solely from the session, never from
     * request input — proven here by having the authenticated actor
     * (adminA) submit a DIFFERENT admin's id (adminB) and asserting the
     * persisted approved_by is adminA's, not adminB's.
     */
    public function test_approved_by_is_always_the_authenticated_actor_and_cannot_be_spoofed_via_request_input(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminA = $this->seedUser(['role' => 'super_admin', 'full_name' => 'Admin A (real actor)']);
        $adminB = $this->seedUser(['role' => 'super_admin', 'full_name' => 'Admin B (spoofed identity)']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'requested_by' => $headId,
            'release_assigned_to' => $staffId,
            'release_assigned_by' => $headId,
        ]);

        // adminA is the real, authenticated actor, but the request body
        // claims the approval was made by adminB.
        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminA, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminB]);

        $response->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame($adminA, (int) $dispatch->approved_by, 'approved_by must reflect the real authenticated actor.');
        $this->assertNotSame($adminB, (int) $dispatch->approved_by, 'A client-supplied approved_by must never override the session actor.');
    }

    public function test_released_by_reflects_the_assigned_personnel_never_the_approving_administrator(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Hector Head']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Juan Releaser']);
        $adminId = $this->seedUser(['role' => 'super_admin', 'full_name' => 'Admin Approver']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'requested_by' => $headId,
            'release_assigned_to' => $staffId,
            'release_assigned_by' => $headId,
        ]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('released', $dispatch->status);
        $this->assertSame($staffId, (int) $dispatch->released_by);
        $this->assertNotSame($adminId, (int) $dispatch->released_by, 'Released By must not automatically become the Administrator.');
        $this->assertNotSame($headId, (int) $dispatch->released_by, 'Released By must not become the Head who assigned the work.');

        // Regression guard: inventory must still be deducted through the
        // exact same Task 1 ledger path regardless of who released_by is.
        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(18, (int) $item->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->where('transaction_type', 'deploy')->count());
    }

    public function test_dispatch_history_displays_every_responsible_party_distinctly(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Hector Head']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Juan Releaser']);
        $adminId = $this->seedUser(['role' => 'super_admin', 'full_name' => 'Admin Approver']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'requested_by' => $headId,
            'release_assigned_to' => $staffId,
            'release_assigned_by' => $headId,
        ]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->getJson("/api/dispatches/{$dispatchId}");

        $response->assertOk();
        $dispatch = $response->json('data.dispatch');

        // All four responsibilities must render as their own person — this is
        // the assertion that catches any future conflation of the roles.
        $this->assertSame('Hector Head', $dispatch['requested_by_name']);
        $this->assertSame('Juan Releaser', $dispatch['release_assigned_to_name']);
        $this->assertSame('Hector Head', $dispatch['release_assigned_by_name']);
        $this->assertSame('Admin Approver', $dispatch['approved_by_name']);
        $this->assertSame('Juan Releaser', $dispatch['released_by_name']);
    }

    public function test_regression_dispatch_inventory_deduction_still_works_end_to_end(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 4, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(6, (int) $item->quantity);

        $tx = DB::table('inventory_transactions')->where('item_id', $itemId)->first();
        $this->assertNotNull($tx);
        $this->assertSame('deploy', $tx->transaction_type);
        $this->assertSame(4, (int) $tx->quantity);

        $this->assertSame(1, DB::table('activity_logs')->where('action', 'APPROVE_DISPATCH')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'RELEASE_DISPATCH')->count());
    }

    private function seedDispatch(int $itemId, int $quantity, array $overrides = []): int
    {
        $dispatchId = DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid(),
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
        // DispatchController::index()/show() eager-load report/report.creator
        // (Dispatch::getRequestedByNameAttribute()'s fallback path) even when
        // a dispatch has no report_id — the table just needs to exist.
        $this->createMaintenanceReportsTable();

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
        });

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }
}
