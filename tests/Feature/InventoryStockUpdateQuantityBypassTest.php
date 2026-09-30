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
 * Covers the quantity-edit bypass confirmed during the Inventory Module QA
 * review: PUT /api/inventory-stock/{id} (InventoryStockController::update(),
 * used by the "Edit Item" modal in inventory.php) used to accept a `quantity`
 * field and write it straight to items.quantity via raw SQL, plus a raw
 * inventory_transactions insert — bypassing InventoryAdjustmentService,
 * InventoryTransactionObserver, and activity logging entirely. Manual Stock
 * Adjustment (ItemController::adjustStock -> InventoryAdjustmentService) is
 * the only sanctioned path for changing quantity; these tests lock in that
 * the update endpoint now silently ignores any incoming quantity value.
 */
class InventoryStockUpdateQuantityBypassTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('inventory_stock_update_quantity_bypass_testing');
        $this->createTestSchema();

        // InventoryStockController's raw SQL uses GREATEST() and NOW(), both
        // MySQL functions with no SQLite equivalent. Register them on this
        // isolated in-memory connection so the real endpoint can be
        // exercised over HTTP; this is test-only scaffolding and changes no
        // application code.
        $pdo = DB::connection('inventory_stock_update_quantity_bypass_testing')->getPdo();
        $pdo->sqliteCreateFunction('GREATEST', fn ($a, $b) => max($a, $b), 2);
        $pdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);

        $this->forceLocalTestUrl();
    }

    public function test_editing_metadata_still_works(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $roomId = $this->seedInventoryRoom();
        $itemId = $this->seedInventoryStockItem(['name' => 'Whiteboard Marker', 'quantity' => 10, 'inventory_room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->putJson("/api/inventory-stock/{$itemId}", [
                'name' => 'Whiteboard Marker (Blue)',
                'description' => 'Restocked shelf and relabeled',
                'reorder_level' => 5,
            ]);

        $response->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame('Whiteboard Marker (Blue)', $item->name);
        $this->assertSame('Restocked shelf and relabeled', $item->description);
        $this->assertSame(5, (int) $item->reorder_level);
        $this->assertSame(10, (int) $item->quantity, 'Quantity must be untouched by a metadata-only edit.');
    }

    public function test_update_endpoint_ignores_a_quantity_field_in_the_request_body(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $roomId = $this->seedInventoryRoom();
        $itemId = $this->seedInventoryStockItem(['name' => 'Fire Extinguisher', 'quantity' => 10, 'inventory_room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->putJson("/api/inventory-stock/{$itemId}", [
                'description' => 'Attempted quantity bypass via Edit Item',
                'quantity' => 999,
            ]);

        $response->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(10, (int) $item->quantity, 'quantity must never change via the update() endpoint.');
        $this->assertSame('Attempted quantity bypass via Edit Item', $item->description);

        $this->assertSame(
            0,
            DB::table('inventory_transactions')->where('item_id', $itemId)->count(),
            'No inventory_transactions row should be written by the metadata-update endpoint.'
        );
    }

    private function seedInventoryStockItem(array $overrides = []): int
    {
        return $this->seedItem(array_merge([
            'inventory_room_id' => 1,
            'unit_type' => 'pc',
            'item_condition' => 'good',
            'reorder_level' => 2,
            'status' => 'available',
        ], $overrides));
    }

    private function seedInventoryRoom(array $overrides = []): int
    {
        return DB::table('inventory_rooms')->insertGetId(array_merge([
            'name' => 'Electrical Inventory Room ' . uniqid(),
            'code' => 'E-' . uniqid(),
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('inventory_rooms');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        Schema::create('inventory_rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }
}
