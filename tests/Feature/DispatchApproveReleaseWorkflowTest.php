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
 * TASK 4 — Approve & Release Workflow, SUPERSEDED by TASK 13.
 *
 * Task 4 modelled approval as a single atomic act in which the Administrator
 * also chose the releasing personnel and wrote the release remarks. The
 * revised business process rejects that outright:
 *   - the Administrator MAY NOT assign release personnel (Head Maintenance
 *     does, during dispatch creation);
 *   - approval MAY NOT move inventory (the assigned staff member does, at
 *     release);
 *   - the release remarks belong to whoever performed the physical hand-off.
 *
 * So the four Task 4 scenarios that asserted the old contract
 * ("Administrator confirms a different releasing personnel", "omitting
 * released_by falls back to the requester", "remarks are persisted at
 * approval", "remarks are optional at approval") could not be repaired — the
 * behaviour they described is the behaviour this task was asked to remove.
 * They are replaced below by the equivalent guarantees at their new location,
 * plus explicit negative tests proving the removed capabilities really are
 * gone rather than merely hidden in the UI.
 */
class DispatchApproveReleaseWorkflowTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_approve_release_testing');
        $this->createTestSchema();
        $this->roomId = $this->seedRoom();
        $this->forceLocalTestUrl();
    }

    /** TASK 60 — room_id is now the required location field on every create. */
    private int $roomId;

    public function test_full_workflow_runs_create_assign_approve_release_in_order(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 3, 'full_name' => 'Hector Head']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 3, 'full_name' => 'Sara Staff']);
        $adminId = $this->seedUser(['role' => 'super_admin', 'full_name' => 'Admin Approver']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);

        // 1. Head Maintenance creates the dispatch AND picks the personnel.
        $created = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);
        $created->assertStatus(201);
        $dispatchId = (int) $created->json('data.dispatch_id');

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('pending', $dispatch->status);
        $this->assertSame($staffId, (int) $dispatch->release_assigned_to);

        // 2. Administrator approves — decision only, no stock movement.
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->assertSame(20, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));

        // 3. The assigned staff member releases — stock moves here.
        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame(18, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
    }

    /**
     * TASK 13 — the Release Remarks field moved from the Administrator's
     * approve dialog to the releasing staff member's release dialog. Task 4's
     * "remarks are persisted" guarantee survives; only its author changed.
     */
    public function test_release_remarks_are_persisted_when_supplied_at_release(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [
                'release_remarks' => 'Handed over at the maintenance office front desk.',
            ])
            ->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('Handed over at the maintenance office front desk.', $dispatch->release_remarks);
    }

    public function test_release_remarks_remain_optional(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertNull($dispatch->release_remarks);
        $this->assertSame('released', $dispatch->status);
    }

    /**
     * TASK 13 — the negative counterpart of Task 4's first scenario. An
     * Administrator who posts released_by/release_remarks to the approve
     * endpoint (an old client, or a hand-crafted request) must not be able to
     * nominate a releaser: those keys are no longer in the validated payload,
     * so they are dropped, and released_by must stay null until a real
     * release happens.
     */
    public function test_approval_ignores_any_released_by_supplied_by_the_administrator(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", [
                'approved_by' => $adminId,
                'released_by' => $otherStaffId,
                'release_remarks' => 'Injected by an out-of-date client.',
            ])
            ->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertNull($dispatch->released_by, 'Approval must not set released_by, whatever the client sends.');
        $this->assertNull($dispatch->release_remarks, 'Approval must not write release remarks.');
        $this->assertSame($staffId, (int) $dispatch->release_assigned_to, 'The Head-chosen assignment must be untouched.');
    }

    /**
     * TASK 13 — the release endpoint no longer accepts released_by either. The
     * attribution comes from the session, so a staff member cannot credit the
     * release to somebody else.
     */
    public function test_release_attributes_the_release_to_the_session_user_not_the_request_body(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Sara Staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Someone Else']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", ['released_by' => $otherStaffId])
            ->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame($staffId, (int) $dispatch->released_by);
        $this->assertNotSame($otherStaffId, (int) $dispatch->released_by);
        $this->assertSame($staffId, (int) DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->value('performed_by'));
    }

    public function test_dispatch_detail_endpoint_exposes_release_remarks_and_released_by_name(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Maria Releaser']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [
                'release_remarks' => 'Confirmed via the release dialog.',
            ])
            ->assertOk();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->getJson("/api/dispatches/{$dispatchId}");

        $response->assertOk();
        $dispatch = $response->json('data.dispatch');

        $this->assertSame('Maria Releaser', $dispatch['released_by_name']);
        $this->assertSame('Confirmed via the release dialog.', $dispatch['release_remarks']);
    }

    /**
     * TASK 13 — rejection is the Administrator's other decision. It is a
     * terminal state, moves no stock, and records its reason.
     */
    public function test_administrator_can_reject_a_pending_dispatch(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, [
            'requested_by' => $headId,
            'release_assigned_to' => $staffId,
        ]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/reject", ['reason' => 'Stock needed for a higher-priority repair.'])
            ->assertOk();

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('cancelled', $dispatch->status);
        $this->assertStringContainsString('Rejected: Stock needed for a higher-priority repair.', (string) $dispatch->notes);
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'), 'Rejection must not move stock.');
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'REJECT_DISPATCH')->count());
    }

    public function test_a_rejected_dispatch_cannot_then_be_approved(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 2, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/reject", ['reason' => 'Not required.'])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertStatus(400);

        $this->assertSame('cancelled', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
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
        // even when a dispatch has no report_id — the table just needs to exist.
        $this->createMaintenanceReportsTable();

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
        });

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }
}
