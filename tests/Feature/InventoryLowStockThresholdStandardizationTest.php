<?php

namespace Tests\Feature;

use App\Services\AnalyticsService;
use App\Services\InventoryStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Task 78 (Low-Stock Threshold Standardization) — regression coverage.
 *
 * Before this task, four call sites computed "low stock" three different
 * ways:
 *   - Item.status (canonical) — derived by InventoryStatusService::deriveStatus()
 *     from quantity vs. reorder_level, written by ItemController /
 *     InventoryTransactionObserver / InventoryAdjustmentService /
 *     PurchaseReceiptPostingService.
 *   - AnalyticsService::inventorySummary()/lowStock()/inventoryHealthIndicators()
 *     and StockController::summary() — all independently recomputed
 *     "low stock" from COALESCE(i.low_stock_threshold_override,
 *     c.default_low_stock_threshold, 0), which is a *different* number
 *     than reorder_level and disagreed with Item.status for a large
 *     fraction of real inventory rows.
 *
 * These tests seed items with an explicit status (mirroring what the real
 * observer/services would have derived via InventoryStatusService, so the
 * fixture is internally consistent) and prove every remaining consumer now
 * agrees with that canonical status — including when low_stock_threshold_override
 * and the category's default_low_stock_threshold are deliberately set to
 * values that would produce a DIFFERENT classification under the old logic.
 *
 * NOTE ON SCOPE: InventoryStockController::index()/summary() use raw SQL
 * with GREATEST(), a MySQL-only function with no SQLite equivalent, so they
 * cannot be exercised against this suite's in-memory SQLite connection (see
 * AnalyticsIntegrationTest's use of AnalyticsMocks for the same constraint).
 * That controller's low_stock_warning column already reads reorder_level
 * directly (never touched override/category-default), so it was not a
 * Task 78 outlier and is out of scope for this file. ItemController::index()
 * (GET /api/items) is used instead as the SQLite-safe "Inventory page"
 * listing endpoint, since it queries via Eloquent and is what the frontend's
 * status_filter=low_stock control actually calls.
 */
class InventoryLowStockThresholdStandardizationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('threshold_standardization_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    // -----------------------------------------------------------------
    // 1-5: canonical classification boundaries (InventoryStatusService)
    // -----------------------------------------------------------------

    public function test_quantity_above_reorder_level_is_available(): void
    {
        $this->assertSame('available', InventoryStatusService::deriveStatus(10, 5));
    }

    public function test_quantity_equal_to_reorder_level_is_low_stock(): void
    {
        $this->assertSame('low_stock', InventoryStatusService::deriveStatus(5, 5));
    }

    public function test_quantity_below_reorder_level_but_positive_is_low_stock(): void
    {
        $this->assertSame('low_stock', InventoryStatusService::deriveStatus(3, 5));
    }

    public function test_quantity_zero_is_out_of_stock(): void
    {
        $this->assertSame('out_of_stock', InventoryStatusService::deriveStatus(0, 5));
    }

    public function test_negative_quantity_is_out_of_stock(): void
    {
        $this->assertSame('out_of_stock', InventoryStatusService::deriveStatus(-2, 5));
    }

    public function test_different_reorder_levels_yield_different_boundaries_for_the_same_quantity(): void
    {
        // Same quantity (6), different reorder_level per item — classification
        // must track each item's OWN reorder_level, not a shared constant.
        $this->assertSame('available', InventoryStatusService::deriveStatus(6, 5));
        $this->assertSame('low_stock', InventoryStatusService::deriveStatus(6, 6));
        $this->assertSame('low_stock', InventoryStatusService::deriveStatus(6, 10));
    }

    // -----------------------------------------------------------------
    // 6-7: category default threshold / item override are NOT canonical
    // -----------------------------------------------------------------

    public function test_category_default_threshold_does_not_affect_item_classification(): void
    {
        $categoryId = DB::table('inventory_categories')->insertGetId([
            'name' => 'High Threshold Category',
            // Old logic would classify ANY item in this category with
            // quantity <= 50 as low stock. New logic must ignore this.
            'default_low_stock_threshold' => 50,
            'is_active' => 1,
            'sort_order' => 0,
        ]);

        $itemId = $this->seedItem([
            'name' => 'Category-Threshold-Immune Item',
            'category_id' => $categoryId,
            'quantity' => 20,
            'reorder_level' => 5,
            'status' => InventoryStatusService::deriveStatus(20, 5),
        ]);

        $status = DB::table('items')->where('id', $itemId)->value('status');
        $this->assertSame('available', $status, 'Category default_low_stock_threshold (50) must not override reorder_level (5).');
    }

    public function test_item_level_override_does_not_affect_item_classification(): void
    {
        $itemId = $this->seedItem([
            'name' => 'Override-Immune Item',
            'quantity' => 8,
            'reorder_level' => 3,
            // Old logic would use this (8 <= 100 -> NOT low stock either way here,
            // so invert: set override so old logic disagrees with new logic).
            'low_stock_threshold_override' => 100,
            'status' => InventoryStatusService::deriveStatus(8, 3),
        ]);

        // Old COALESCE logic: 8 <= 100 -> would have been flagged low stock.
        // New canonical logic: 8 > reorder_level(3) -> available.
        $status = DB::table('items')->where('id', $itemId)->value('status');
        $this->assertSame('available', $status, 'low_stock_threshold_override (100) must not override reorder_level (3).');
    }

    // -----------------------------------------------------------------
    // 8-10: AnalyticsService methods agree with Item.status
    // -----------------------------------------------------------------

    public function test_analytics_inventory_summary_matches_item_status(): void
    {
        [$availableId, $lowId, $outId] = $this->seedThreeClassifiedItems();

        $result = (new AnalyticsService())->inventorySummary();

        $lowStockIds = collect($result['low_stock_items'])->pluck('id')->all();
        $this->assertContains($lowId, $lowStockIds);
        $this->assertNotContains($availableId, $lowStockIds);
        $this->assertNotContains($outId, $lowStockIds);
        $this->assertSame(1, $result['low_stock_count']);
    }

    public function test_analytics_low_stock_matches_item_status(): void
    {
        [$availableId, $lowId, $outId] = $this->seedThreeClassifiedItems();

        $result = (new AnalyticsService())->lowStock();

        $ids = collect($result['items'])->pluck('id')->all();
        $this->assertSame([$lowId], $ids);
    }

    public function test_analytics_inventory_health_indicators_matches_item_status_counts(): void
    {
        [$availableId, $lowId, $outId] = $this->seedThreeClassifiedItems();

        $result = (new AnalyticsService())->inventoryHealthIndicators();

        $this->assertSame(3, $result['total_items']);
        $this->assertSame(1, $result['low_stock_count']);
        $this->assertSame(1, $result['out_of_stock_count']);
    }

    // -----------------------------------------------------------------
    // 11: StockController::summary() (HTTP)
    // -----------------------------------------------------------------

    public function test_stock_controller_summary_matches_item_status(): void
    {
        [$availableId, $lowId, $outId] = $this->seedThreeClassifiedItems();
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/stock/summary');

        $response->assertOk();
        $json = $response->json('data');

        $this->assertSame(1, $json['low_stock_count']);
        $ids = collect($json['low_stock_items'])->pluck('id')->all();
        $this->assertSame([$lowId], $ids);
    }

    // -----------------------------------------------------------------
    // 12: Inventory page listing (GET /api/items) — SQLite-safe endpoint
    // -----------------------------------------------------------------

    public function test_inventory_page_listing_matches_item_status(): void
    {
        [$availableId, $lowId, $outId] = $this->seedThreeClassifiedItems();
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/items?status_filter=low_stock&per_page=50');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$lowId], $ids);
    }

    // -----------------------------------------------------------------
    // 13: same items produce identical classification everywhere
    // -----------------------------------------------------------------

    public function test_same_items_produce_identical_low_stock_classification_across_all_consumers(): void
    {
        // Deliberately adversarial thresholds: category default and item
        // override both disagree with reorder_level, so any remaining
        // consumer still reading the old COALESCE logic would diverge here.
        $categoryId = DB::table('inventory_categories')->insertGetId([
            'name' => 'Adversarial Category',
            'default_low_stock_threshold' => 1,
            'is_active' => 1,
            'sort_order' => 0,
        ]);

        $items = [
            'available' => ['quantity' => 20, 'reorder_level' => 4, 'low_stock_threshold_override' => 20],
            'low_stock' => ['quantity' => 4, 'reorder_level' => 4, 'low_stock_threshold_override' => null],
            'out_of_stock' => ['quantity' => 0, 'reorder_level' => 4, 'low_stock_threshold_override' => null],
        ];

        $ids = [];
        foreach ($items as $label => $attrs) {
            $ids[$label] = $this->seedItem(array_merge($attrs, [
                'name' => 'Adversarial ' . $label,
                'category_id' => $categoryId,
                'status' => InventoryStatusService::deriveStatus((int) $attrs['quantity'], (int) $attrs['reorder_level']),
            ]));
        }

        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        // Canonical source
        $canonicalLowStockId = DB::table('items')->where('status', 'low_stock')->value('id');
        $this->assertSame($ids['low_stock'], $canonicalLowStockId);

        // AnalyticsService::inventorySummary()
        $summary = (new AnalyticsService())->inventorySummary();
        $this->assertSame([$ids['low_stock']], collect($summary['low_stock_items'])->pluck('id')->all());

        // AnalyticsService::lowStock()
        $low = (new AnalyticsService())->lowStock();
        $this->assertSame([$ids['low_stock']], collect($low['items'])->pluck('id')->all());

        // AnalyticsService::inventoryHealthIndicators()
        $health = (new AnalyticsService())->inventoryHealthIndicators();
        $this->assertSame(1, $health['low_stock_count']);
        $this->assertSame(1, $health['out_of_stock_count']);

        // StockController::summary() (HTTP)
        $stockResponse = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/stock/summary');
        $stockResponse->assertOk();
        $this->assertSame(
            [$ids['low_stock']],
            collect($stockResponse->json('data.low_stock_items'))->pluck('id')->all()
        );

        // Inventory page (GET /api/items, status_filter=low_stock)
        $itemsResponse = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/items?status_filter=low_stock&per_page=50');
        $itemsResponse->assertOk();
        $this->assertSame(
            [$ids['low_stock']],
            collect($itemsResponse->json('data.data'))->pluck('id')->all()
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Seeds one available, one low_stock, and one out_of_stock item (in that
     * order) and returns their IDs as [$availableId, $lowId, $outId].
     */
    private function seedThreeClassifiedItems(): array
    {
        $availableId = $this->seedItem([
            'name' => 'Available Widget',
            'quantity' => 20,
            'reorder_level' => 5,
            'status' => InventoryStatusService::deriveStatus(20, 5),
        ]);

        $lowId = $this->seedItem([
            'name' => 'Low Stock Widget',
            'quantity' => 5,
            'reorder_level' => 5,
            'status' => InventoryStatusService::deriveStatus(5, 5),
        ]);

        $outId = $this->seedItem([
            'name' => 'Out Of Stock Widget',
            'quantity' => 0,
            'reorder_level' => 5,
            'status' => InventoryStatusService::deriveStatus(0, 5),
        ]);

        return [$availableId, $lowId, $outId];
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_stock_entries');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('items');
        Schema::dropIfExists('inventory_categories');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createInventoryCategoriesTable();
        $this->createInventoryStockEntriesTable();
        // AnalyticsService::inventorySummary()'s v6 room_items/room_quantity
        // bucket now unions in released dispatch_items (same shape as
        // App\Support\DeployedItemsQuery), so this connection needs the
        // tables even though this file seeds no dispatch rows.
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createInventoryCategoriesTable(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->integer('default_low_stock_threshold')->nullable();
            $table->boolean('allow_threshold_override')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Minimal shape (no FKs — this connection runs with
     * foreign_key_constraints disabled) needed only so that
     * StockController::summary()'s InventoryStockEntry::sum('quantity')
     * query has a table to hit. Not itself part of Task 78's scope.
     */
    private function createInventoryStockEntriesTable(): void
    {
        Schema::create('inventory_stock_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('stock_entry_id', 40)->unique();
            $table->string('or_number', 100);
            $table->string('supplier_name', 255);
            $table->date('date_received');
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('inventory_room_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('receiver_user_id')->nullable();
            $table->string('item_name', 255);
            $table->unsignedInteger('quantity');
            $table->string('unit_type', 50);
            $table->text('description')->nullable();
            $table->string('item_condition', 50);
            $table->timestamps();
        });
    }
}
