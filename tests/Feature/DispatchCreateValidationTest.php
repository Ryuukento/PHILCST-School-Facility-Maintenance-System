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
 * TASK 47 (Dispatch Create & Assignment Workflow).
 *
 * Covers the backend validation surface of POST /api/dispatches that had no
 * direct regression coverage before this task: invalid/duplicate items,
 * non-positive quantities, missing release personnel, an optional
 * destination, and role-gated create access. Personnel/department
 * authorization itself is already covered by
 * DispatchReleaseAssignmentAuthorizationTest and is not repeated here.
 */
class DispatchCreateValidationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /** TASK 60 — every test in this file exercises a real, required room_id. */
    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_create_validation_testing');
        $this->createTestSchema();
        $this->roomId = $this->seedRoom();
        $this->forceLocalTestUrl();
    }

    /**
     * TASK 60 — was test_authorized_create_succeeds_with_no_destination_
     * specified(): "destination" meant department_id/room_id were both
     * optional back when room_note (required) carried the location. Now
     * room_id is the required location field, so this test instead proves
     * department_id (still optional — a dispatch need not belong to a
     * department) can be omitted while room_id is supplied.
     */
    public function test_authorized_create_succeeds_with_no_department_specified(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 2]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(201);
        $dispatchId = $response->json('data.dispatch_id');
        $this->assertNull(DB::table('dispatches')->where('id', $dispatchId)->value('department_id'));
        $this->assertSame($this->roomId, (int) DB::table('dispatches')->where('id', $dispatchId)->value('room_id'));
    }

    public function test_duplicate_item_id_is_rejected(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [
                    ['item_id' => $itemId, 'quantity' => 2],
                    ['item_id' => $itemId, 'quantity' => 3],
                ],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('items.0.item_id', $response->json('errors'));
        $this->assertSame(0, DB::table('dispatches')->count());
    }

    public function test_nonexistent_item_id_is_rejected(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => 999999, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('dispatches')->count());
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 0]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('dispatches')->count());
    }

    public function test_missing_release_assigned_to_is_rejected(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('release_assigned_to', $response->json('errors'));
        $this->assertSame(0, DB::table('dispatches')->count());
    }

    /**
     * TASK 60 — was test_missing_room_note_is_rejected(). room_id (the
     * "Room / Lab" dropdown) is now the required location field, not
     * room_note, so this proves the same "you must say where" rule against
     * the field that now actually carries it.
     */
    public function test_missing_room_id_is_rejected(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('room_id', $response->json('errors'));
        $this->assertSame(0, DB::table('dispatches')->count());
    }

    /**
     * TASK 60 — was test_too_short_room_note_is_rejected(). The 'min:3' rule
     * lived on room_note, which is no longer validated that way (it's an
     * optional legacy field now). room_id's real validation edge is
     * 'exists:rooms,id', so this test now covers that instead: a room_id
     * that doesn't correspond to any real room must still be rejected.
     */
    public function test_nonexistent_room_id_is_rejected(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => 1]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => 999999,
                'release_assigned_to' => $staffId,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('room_id', $response->json('errors'));
        $this->assertSame(0, DB::table('dispatches')->count());
    }

    public function test_maintenance_staff_cannot_create_a_dispatch(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 1]);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 10, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson('/api/dispatches', [
                'items' => [['item_id' => $itemId, 'quantity' => 1]],
                'room_id' => $this->roomId,
                'release_assigned_to' => $otherStaffId,
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, DB::table('dispatches')->count());
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
