<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('inventory_adjustment_testing');
        $this->createTestSchema();

        // See ConfiguresIsolatedSqliteConnection::forceLocalTestUrl() for why
        // this is required for HTTP test requests in this environment.
        $this->forceLocalTestUrl();
    }

    public function test_the_duplicate_inventory_stock_adjust_endpoint_is_retired(): void
    {
        // 2026-09-27 — manual adjustment has one path only
        // (POST /api/items/{item}/adjust-stock); the unused duplicate is gone.
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 5, 'reorder_level' => 2, 'status' => 'available']);

        $status = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson("/api/inventory-stock/{$itemId}/adjust", ['quantity_change' => 3, 'reason' => 'x'])
            ->status();

        $this->assertContains($status, [404, 405]);
        $this->assertSame(5, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    public function test_maintenance_admin_can_increase_stock(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 5, 'reorder_level' => 2, 'status' => 'available']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 10,
                'reason' => 'Restock from storage closet',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.item.quantity', 15);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(15, (int) $item->quantity);
        $this->assertSame('available', $item->status);

        $tx = DB::table('inventory_transactions')->where('item_id', $itemId)->first();
        $this->assertSame('adjustment', $tx->transaction_type);
        $this->assertSame(10, (int) $tx->quantity);
        $this->assertStringNotContainsString('DEDUCT:', (string) $tx->reference_note);

        $this->assertSame(1, DB::table('activity_logs')->where('action', 'ADJUST_STOCK')->count());
    }

    public function test_maintenance_admin_can_decrease_stock(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Fire Extinguisher', 'quantity' => 10, 'reorder_level' => 2, 'status' => 'available']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 4,
                'reason' => 'Damaged during inspection',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.item.quantity', 6);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(6, (int) $item->quantity);

        $tx = DB::table('inventory_transactions')->where('item_id', $itemId)->first();
        $this->assertSame('adjustment', $tx->transaction_type);
        $this->assertSame(4, (int) $tx->quantity);
        $this->assertStringStartsWith('DEDUCT:', (string) $tx->reference_note);
    }

    public function test_decrease_larger_than_available_stock_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Extension Cord', 'quantity' => 5, 'reserved_quantity' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 6,
                'reason' => 'Correction',
            ]);

        $response->assertStatus(422);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(5, (int) $item->quantity);
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    public function test_decrease_larger_than_available_after_reservation_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        // 10 on hand, 6 already reserved for a pending dispatch -> only 4 available.
        $itemId = $this->seedItem(['name' => 'Projector Bulb', 'quantity' => 10, 'reserved_quantity' => 6]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 5,
                'reason' => 'Correction',
            ]);

        $response->assertStatus(422);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(10, (int) $item->quantity);
        $this->assertSame(6, (int) $item->reserved_quantity);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 0,
                'reason' => 'Restock',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    public function test_negative_quantity_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => -3,
                'reason' => 'Restock',
            ]);

        $response->assertStatus(422);
    }

    public function test_missing_reason_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 3,
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'sideways',
                'quantity' => 3,
                'reason' => 'Restock',
            ]);

        $response->assertStatus(422);
    }

    public function test_super_admin_can_adjust_stock(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 2,
                'reason' => 'Restock',
            ]);

        $response->assertOk();
    }

    public function test_staff_role_is_forbidden_from_adjusting_stock(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Mouse', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_staff')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 2,
                'reason' => 'Restock',
            ]);

        $response->assertStatus(403);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(5, (int) $item->quantity);
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    public function test_decrease_never_drives_quantity_negative_and_recomputes_status(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Fire Extinguisher', 'quantity' => 3, 'reorder_level' => 2, 'status' => 'available']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId,'maintenance_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 3,
                'reason' => 'Disposed - expired',
            ]);

        $response->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(0, (int) $item->quantity);
        $this->assertGreaterThanOrEqual(0, (int) $item->quantity);
        $this->assertSame('out_of_stock', $item->status);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        Schema::enableForeignKeyConstraints();
    }
}
