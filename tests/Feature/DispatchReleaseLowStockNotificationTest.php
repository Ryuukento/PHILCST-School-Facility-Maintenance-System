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
 * TASK 52 — regression coverage for a real notification defect found while
 * auditing every notification-producing flow: DispatchService::releaseDispatch()
 * is the dominant inventory-depletion pathway (Deploy-to-Room was retired,
 * see TASK 36/37), yet — unlike InventoryAdjustmentService::adjust() and
 * PurchaseReceiptPostingService::postReceipt(), both fixed by Task 49 for the
 * identical class of bug — it never captured a pre-mutation Item.status and
 * never called InventoryLowStockNotifier::handleStatusChange() at all. A
 * dispatch release that pushed an item from NORMAL stock into LOW/OUT_OF_STOCK
 * silently produced zero "Item Low Stock" notifications.
 *
 * Fixed by capturing each distinct item's status (per Task 48's
 * dispatch_items aggregation-by-item_id) BEFORE the 'deploy'
 * InventoryTransaction rows are created, then calling handleStatusChange()
 * once per distinct item afterward with its freshly re-derived status —
 * mirroring the exact pattern Task 49 already established.
 */
class DispatchReleaseLowStockNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_release_low_stock_notification_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_releasing_dispatch_across_the_reorder_level_notifies_admins(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $inactiveAdminId = $this->seedUser(['role' => 'super_admin', 'status' => 'inactive']);
        $releaserId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        // quantity(10) > reorder_level(5) -> 'available'
        $itemId = $this->seedItem([
            'name' => 'Projector Lamp',
            'quantity' => 10,
            'reorder_level' => 5,
            'status' => 'available',
        ]);
        $dispatchId = $this->seedDispatch($itemId, 6, ['release_assigned_to' => $releaserId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($releaserId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        // quantity now 4 <= reorder_level(5) -> 'low_stock'
        $this->assertSame('low_stock', DB::table('items')->where('id', $itemId)->value('status'));

        $lowStock = DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $itemId);
        $this->assertSame(1, (clone $lowStock)->where('user_id', $adminId)->count());
        $this->assertSame(1, (clone $lowStock)->where('user_id', $headId)->count());
        $this->assertSame(0, (clone $lowStock)->where('user_id', $staffId)->count());
        $this->assertSame(0, (clone $lowStock)->where('user_id', $inactiveAdminId)->count());
        // The releasing staff member gets a "Dispatch Released" notification,
        // but must not also get the inventory low-stock one.
        $this->assertSame(0, (clone $lowStock)->where('user_id', $releaserId)->count());

        $notification = (clone $lowStock)->where('user_id', $headId)->first();
        $this->assertStringContainsString('Low Stock', $notification->title);
        $this->assertStringContainsString('Projector Lamp', $notification->message);
    }

    public function test_release_that_stays_below_reorder_level_does_not_renotify(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $releaserId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        // Already low_stock (quantity 6 <= reorder_level 5... use 4 <= 5).
        $itemId = $this->seedItem([
            'name' => 'Whiteboard Marker',
            'quantity' => 4,
            'reorder_level' => 5,
            'status' => 'low_stock',
        ]);
        $dispatchId = $this->seedDispatch($itemId, 4, ['release_assigned_to' => $releaserId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($releaserId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        // quantity now 0 -> 'out_of_stock', still "below threshold" throughout.
        $this->assertSame('out_of_stock', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(
            0,
            DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $itemId)->count(),
            'LOW -> OUT_OF_STOCK must not re-notify; both are "below threshold".'
        );
    }

    /**
     * TASK 48/52 interaction: a single release can touch several distinct
     * items. Each item's before/after status must be compared independently
     * — an item that stays above its own reorder level must not be notified
     * just because a different item in the same release crossed its own.
     */
    public function test_release_only_notifies_for_the_item_that_crosses_its_own_threshold(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $releaserId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        // Crosses: 10 -> 4, reorder 5.
        $crossingItemId = $this->seedItem([
            'name' => 'HDMI Cable',
            'quantity' => 10,
            'reorder_level' => 5,
            'status' => 'available',
        ]);
        // Stays comfortably above: 20 -> 15, reorder 5.
        $safeItemId = $this->seedItem([
            'name' => 'USB Drive',
            'quantity' => 20,
            'reorder_level' => 5,
            'status' => 'available',
        ]);

        $dispatchId = $this->seedDispatch($crossingItemId, 6, ['release_assigned_to' => $releaserId]);
        $this->addDispatchItem($dispatchId, $safeItemId, 5);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($releaserId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $this->assertSame('low_stock', DB::table('items')->where('id', $crossingItemId)->value('status'));
        $this->assertSame('available', DB::table('items')->where('id', $safeItemId)->value('status'));

        $this->assertSame(
            1,
            DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $crossingItemId)->count()
        );
        $this->assertSame(
            0,
            DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $safeItemId)->count()
        );
    }

    private function seedDispatch(int $itemId, int $quantity, array $overrides = []): int
    {
        $dispatchId = DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        $this->addDispatchItem($dispatchId, $itemId, $quantity);

        return $dispatchId;
    }

    private function addDispatchItem(int $dispatchId, int $itemId, int $quantity): void
    {
        DB::table('dispatch_items')->insert([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();

        // BuildsSharedTestSchema::createInventoryTransactionsTable() predates
        // the dispatch_id FK (added by the real
        // 2026_07_28_002000_add_report_id_relationships_for_maintenance_report_centralization
        // migration), so add it here the same way that migration does, as
        // DispatchApprovalInventoryTest does.
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
        });

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }
}
