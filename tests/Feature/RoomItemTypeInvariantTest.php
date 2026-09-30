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
 * TASK 42 — the items.room_id / items.item_type invariant.
 *
 * The rule these tests lock in is not invented here. It is the rule migration
 * 2026_04_23_000800_make_items_room_id_nullable.php established when item_type
 * was introduced, by backfilling the column with exactly two statements:
 *
 *     UPDATE items SET item_type = 'room_asset'      WHERE room_id IS NOT NULL;
 *     UPDATE items SET item_type = 'inventory_stock' WHERE room_id IS NULL;
 *
 * So: room_id IS NOT NULL <=> item_type = 'room_asset'.
 *
 * A 'room_asset' is one physical unit installed in a room. An 'inventory_stock'
 * row is warehouse supply — the only kind of row that may be dispatched or
 * consumed as a replacement. Buildings Overview -> Add Item records the former;
 * it does not allocate out of the latter (the form takes a free-text item name,
 * not a reference to an existing inventory row), which is why nothing is
 * deducted from stock when a room item is added.
 */
class RoomItemTypeInvariantTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('room_item_type_invariant_testing');
        $this->createTestSchema();

        // ItemController::store() validates room_id with `exists:rooms,id`, and
        // the shared schema has no rooms table, so this test provides one.
        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->timestamps();
            });
        }

        $this->forceLocalTestUrl();
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
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

        Schema::enableForeignKeyConstraints();
    }

    private function seedRoom(string $name = 'Room 102'): int
    {
        return DB::table('rooms')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'need_change_status' => 'pending',
            'need_change_approved_by' => null,
            'need_change_approved_at' => null,
            'need_change_deducted_at' => null,
            'completion_proof_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }

    // ---------------------------------------------------------------------
    // Creation layer — the root cause
    // ---------------------------------------------------------------------

    public function test_item_created_with_a_room_is_typed_as_a_room_asset(): void
    {
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Keyboard',
                'quantity' => 40,
                'room_id' => $roomId,
            ]);

        $response->assertStatus(201);
        $item = DB::table('items')->where('id', $response->json('data.item_id'))->first();

        $this->assertSame('room_asset', $item->item_type);
        $this->assertSame($roomId, (int) $item->room_id);
    }

    public function test_item_created_without_a_room_is_typed_as_inventory_stock(): void
    {
        $userId = $this->seedUser();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Keyboard',
                'quantity' => 8,
            ]);

        $response->assertStatus(201);
        $item = DB::table('items')->where('id', $response->json('data.item_id'))->first();

        $this->assertSame('inventory_stock', $item->item_type);
        $this->assertNull($item->room_id);
    }

    public function test_client_cannot_override_item_type_to_break_the_invariant(): void
    {
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();

        // item_type is derived server-side, never accepted from the request.
        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Smuggled Keyboard',
                'quantity' => 5,
                'room_id' => $roomId,
                'item_type' => 'inventory_stock',
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            'room_asset',
            DB::table('items')->where('id', $response->json('data.item_id'))->value('item_type')
        );
    }

    public function test_room_item_creation_still_applies_quantity_through_the_ledger(): void
    {
        // The fix must not bypass InventoryTransactionObserver, which remains the
        // sole writer of Item.quantity (ARCHITECTURE.md Section 5).
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Keyboard',
                'quantity' => 40,
                'room_id' => $roomId,
            ]);

        $itemId = $response->json('data.item_id');

        $this->assertSame(40, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
        $this->assertSame(
            'adjustment',
            DB::table('inventory_transactions')->where('item_id', $itemId)->value('transaction_type')
        );
    }

    // ---------------------------------------------------------------------
    // The reported bug, reproduced end to end
    // ---------------------------------------------------------------------

    public function test_adding_a_room_item_does_not_change_existing_warehouse_stock(): void
    {
        // Room assets are recorded, not allocated out of stock. Adding 40
        // keyboards to Room 102 must leave the warehouse Keyboard row alone.
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();
        $warehouseId = $this->seedItem(['name' => 'Keyboard', 'quantity' => 8]);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Keyboard',
                'quantity' => 40,
                'room_id' => $roomId,
            ])
            ->assertStatus(201);

        $this->assertSame(8, (int) DB::table('items')->where('id', $warehouseId)->value('quantity'));
    }

    public function test_room_asset_is_excluded_from_the_replacement_item_query(): void
    {
        // This is the exact query the Replacement Item picker now issues.
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();
        $warehouseId = $this->seedItem(['name' => 'Keyboard', 'quantity' => 8]);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', ['name' => 'Keyboard', 'quantity' => 40, 'room_id' => $roomId])
            ->assertStatus(201);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/items?per_page=200&item_type=inventory_stock');

        $response->assertOk();
        $ids = array_column($response->json('data.data'), 'id');

        $this->assertContains($warehouseId, $ids);
        $this->assertCount(1, $ids, 'Only the warehouse Keyboard may be offered as a replacement item.');
    }

    public function test_room_asset_is_still_listed_as_the_contents_of_its_room(): void
    {
        // Buildings Overview must keep showing the item it just created.
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();

        $created = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', ['name' => 'Keyboard', 'quantity' => 40, 'room_id' => $roomId])
            ->json('data.item_id');

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/items?room_id=' . $roomId);

        $response->assertOk();
        $this->assertSame([$created], array_column($response->json('data.data'), 'id'));
    }

    // ---------------------------------------------------------------------
    // Update layer — the invariant must survive a room change
    // ---------------------------------------------------------------------

    public function test_moving_an_item_into_a_room_retypes_it_as_a_room_asset(): void
    {
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['name' => 'Keyboard', 'quantity' => 8]);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/items/{$itemId}", ['room_id' => $roomId])
            ->assertOk();

        $this->assertSame('room_asset', DB::table('items')->where('id', $itemId)->value('item_type'));
    }

    public function test_removing_an_item_from_a_room_retypes_it_as_inventory_stock(): void
    {
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem([
            'name' => 'Keyboard',
            'quantity' => 1,
            'room_id' => $roomId,
            'item_type' => 'room_asset',
        ]);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/items/{$itemId}", ['room_id' => null])
            ->assertOk();

        $this->assertSame('inventory_stock', DB::table('items')->where('id', $itemId)->value('item_type'));
    }

    public function test_updating_an_unrelated_field_leaves_item_type_alone(): void
    {
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem([
            'name' => 'Keyboard',
            'quantity' => 1,
            'room_id' => $roomId,
            'item_type' => 'room_asset',
        ]);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/items/{$itemId}", ['brand' => 'Logitech'])
            ->assertOk();

        $row = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame('room_asset', $row->item_type);
        $this->assertSame($roomId, (int) $row->room_id);
    }

    // ---------------------------------------------------------------------
    // Duplicate room allocation (Phase 13)
    // ---------------------------------------------------------------------

    public function test_assigning_the_same_item_to_the_same_room_twice_is_rejected(): void
    {
        $userId = $this->seedUser();
        $roomId = $this->seedRoom();

        $payload = ['name' => 'Keyboard', 'quantity' => 40, 'room_id' => $roomId];

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', $payload)
            ->assertStatus(201);

        // ItemController::store()'s existing duplicate guard already covers this;
        // no new constraint was added for it.
        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', $payload)
            ->assertStatus(409);

        $this->assertSame(1, DB::table('items')->where('room_id', $roomId)->count());
    }

    public function test_the_same_item_name_may_exist_in_two_different_rooms(): void
    {
        $userId = $this->seedUser();
        $roomA = $this->seedRoom('Room 102');
        $roomB = $this->seedRoom('Room 103');

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', ['name' => 'Keyboard', 'quantity' => 40, 'room_id' => $roomA])
            ->assertStatus(201);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', ['name' => 'Keyboard', 'quantity' => 10, 'room_id' => $roomB])
            ->assertStatus(201);

        $this->assertSame(2, DB::table('items')->where('item_type', 'room_asset')->count());
    }

    // ---------------------------------------------------------------------
    // Server-side authority — the rule is not merely a UI filter
    // ---------------------------------------------------------------------

    public function test_need_change_approval_rejects_a_room_asset_as_the_replacement_item(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom();
        $roomAssetId = $this->seedItem([
            'name' => 'Keyboard',
            'quantity' => 40,
            'room_id' => $roomId,
            'item_type' => 'room_asset',
        ]);
        $reportId = $this->seedReport([
            'need_change_item_id' => $roomAssetId,
            'need_change_quantity' => 5,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertStatus(422);

        // Nothing was deducted and no ledger entry was written.
        $this->assertSame(40, (int) DB::table('items')->where('id', $roomAssetId)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_transactions')->where('report_id', $reportId)->count());

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertNull($report->need_change_deducted_at);
    }

    public function test_need_change_approval_still_accepts_inventory_stock(): void
    {
        // Guard against the fix over-reaching: the normal path must be untouched.
        $approverId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Keyboard', 'quantity' => 8]);
        $reportId = $this->seedReport([
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 5,
            'need_change_status' => 'pending',
        ]);

        $this->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true])
            ->assertOk();

        $this->assertSame(3, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('report_id', $reportId)->count());
    }
}
