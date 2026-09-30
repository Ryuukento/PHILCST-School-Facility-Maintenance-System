<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class ItemControllerInventoryTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('item_controller_testing');
        $this->createTestSchema();

        // See ConfiguresIsolatedSqliteConnection::forceLocalTestUrl() for why
        // this is needed for HTTP test requests in this environment.
        $this->forceLocalTestUrl();
    }

    public function test_creating_item_with_initial_quantity_applies_it_through_ledger(): void
    {
        $userId = $this->seedUser();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Whiteboard Marker',
                'quantity' => 10,
                'reorder_level' => 2,
                'unit_type' => 'box',
            ]);

        $response->assertStatus(201);
        $itemId = $response->json('data.item_id');
        $this->assertNotNull($itemId);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(10, (int) $item->quantity);
        $this->assertSame('available', $item->status);

        $this->assertSame(1, DB::table('inventory_transactions')->where('item_id', $itemId)->count());

        $tx = DB::table('inventory_transactions')->where('item_id', $itemId)->first();
        $this->assertSame('adjustment', $tx->transaction_type);
        $this->assertSame(10, (int) $tx->quantity);
    }

    public function test_creating_item_with_zero_quantity_creates_no_transaction(): void
    {
        $userId = $this->seedUser();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Spare Projector Bulb',
                'quantity' => 0,
                'reorder_level' => 3,
            ]);

        $response->assertStatus(201);
        $itemId = $response->json('data.item_id');
        $this->assertNotNull($itemId);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(0, (int) $item->quantity);
        $this->assertSame('out_of_stock', $item->status);

        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    public function test_updating_item_ignores_quantity_in_payload_but_updates_metadata(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Ceiling Fan',
            'quantity' => 5,
            'reorder_level' => 1,
            'status' => 'available',
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/items/{$itemId}", [
                'quantity' => 999,
                'name' => 'Ceiling Fan (16-inch)',
                'reorder_level' => 3,
            ]);

        $response->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(5, (int) $item->quantity);
        $this->assertSame('Ceiling Fan (16-inch)', $item->name);
        $this->assertSame(3, (int) $item->reorder_level);
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    public function test_updating_item_ignores_status_in_payload(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Fire Extinguisher',
            'quantity' => 5,
            'reorder_level' => 1,
            'status' => 'available',
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/items/{$itemId}", [
                'status' => 'out_of_stock',
                'description' => 'Inspected quarterly.',
            ]);

        $response->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame('available', $item->status);
        $this->assertSame('Inspected quarterly.', $item->description);
    }

    public function test_metadata_only_update_persists_normally(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Office Chair',
            'quantity' => 8,
            'reorder_level' => 2,
            'status' => 'available',
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/items/{$itemId}", [
                'name' => 'Office Chair (Ergonomic)',
                'brand' => 'ErgoSeat',
                'model' => 'ES-100',
                'unit_type' => 'pc',
                'item_condition' => 'good',
                'reorder_level' => 4,
                'low_stock_threshold_override' => 6,
                'description' => 'Adjustable lumbar support.',
            ]);

        $response->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame('Office Chair (Ergonomic)', $item->name);
        $this->assertSame('ErgoSeat', $item->brand);
        $this->assertSame('ES-100', $item->model);
        $this->assertSame('pc', $item->unit_type);
        $this->assertSame('good', $item->item_condition);
        $this->assertSame(4, (int) $item->reorder_level);
        $this->assertSame(6, (int) $item->low_stock_threshold_override);
        $this->assertSame('Adjustable lumbar support.', $item->description);

        // Untouched ledger-governed fields.
        $this->assertSame(8, (int) $item->quantity);
        $this->assertSame('available', $item->status);
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
