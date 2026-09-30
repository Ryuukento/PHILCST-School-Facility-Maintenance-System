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
 * TASK 49 — regression coverage for a real defect found while auditing
 * InventoryAdjustmentService::adjust() and PurchaseReceiptPostingService::
 * postReceipt(): both captured "$previousStatus" via `$item->refresh()`
 * AFTER creating the InventoryTransaction that triggers
 * InventoryTransactionObserver::created(). That Observer synchronously
 * re-derives and *saves* Item.status as part of the same create() call, so
 * the refresh() picked up the POST-mutation status, not the pre-mutation
 * one — meaning InventoryLowStockNotifier::handleStatusChange() always
 * compared a status against itself and could never detect a genuine
 * NORMAL -> LOW/OUT_OF_STOCK transition. In practice this silently disabled
 * the "Item becomes Low Stock" notification for every manual Stock
 * Adjustment (POST /api/items/{item}/adjust-stock, the sanctioned UI path
 * for changing quantity — see InventoryStockUpdateQuantityBypassTest).
 *
 * Fixed by capturing $previousStatus from the locked row BEFORE creating
 * the InventoryTransaction. These tests lock in the corrected behavior.
 */
class InventoryAdjustmentLowStockNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('inventory_adjustment_low_stock_notification_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_decreasing_stock_across_the_reorder_level_notifies_admins(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $inactiveAdminId = $this->seedUser(['role' => 'super_admin', 'status' => 'inactive']);

        // quantity(10) > reorder_level(5) -> 'available'
        $itemId = $this->seedItem([
            'name' => 'Printer Toner',
            'quantity' => 10,
            'reorder_level' => 5,
            'status' => 'available',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 6,
                'reason' => 'Bulk withdrawal for printer maintenance',
            ]);

        $response->assertOk();

        // quantity now 4 <= reorder_level(5) -> 'low_stock'
        $this->assertSame('low_stock', DB::table('items')->where('id', $itemId)->value('status'));

        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $headId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $staffId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $inactiveAdminId)->count());

        $notification = DB::table('notifications')->where('user_id', $headId)->first();
        $this->assertStringContainsString('Low Stock', $notification->title);
        $this->assertStringContainsString('Printer Toner', $notification->message);
        $this->assertSame('inventory', $notification->entity_type);
        $this->assertSame($itemId, (int) $notification->entity_id);
    }

    public function test_further_decrease_that_stays_below_reorder_level_does_not_renotify(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);

        // Already low_stock (quantity 4 <= reorder_level 5).
        $itemId = $this->seedItem([
            'name' => 'Whiteboard Marker',
            'quantity' => 4,
            'reorder_level' => 5,
            'status' => 'low_stock',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 4,
                'reason' => 'Used remaining stock',
            ]);

        $response->assertOk();

        // quantity now 0 -> 'out_of_stock', still "below threshold" throughout.
        $this->assertSame('out_of_stock', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(
            0,
            DB::table('notifications')->count(),
            'LOW -> OUT_OF_STOCK must not re-notify; both are "below threshold".'
        );
    }

    public function test_increase_back_above_reorder_level_clears_state_without_notifying(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);

        $itemId = $this->seedItem([
            'name' => 'Extension Cord',
            'quantity' => 2,
            'reorder_level' => 5,
            'status' => 'low_stock',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 20,
                'reason' => 'Restocked from supplier',
            ]);

        $response->assertOk();
        $this->assertSame('available', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(0, DB::table('notifications')->count(), 'LOW -> NORMAL must not notify.');
    }

    public function test_dropping_below_reorder_level_again_after_clearing_notifies_again(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);

        $itemId = $this->seedItem([
            'name' => 'HDMI Cable',
            'quantity' => 20,
            'reorder_level' => 5,
            'status' => 'available',
        ]);

        // NORMAL -> LOW (first drop): notifies.
        $this->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 16,
                'reason' => 'First withdrawal',
            ])->assertOk();
        $this->assertSame('low_stock', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->count());

        // LOW -> NORMAL (restock): clears, no notify.
        $this->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'increase',
                'quantity' => 30,
                'reason' => 'Restock',
            ])->assertOk();
        $this->assertSame('available', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->count(), 'Still just the first notification.');

        // NORMAL -> LOW again: a genuinely new transition, must notify again.
        $this->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/items/{$itemId}/adjust-stock", [
                'direction' => 'decrease',
                'quantity' => 32,
                'reason' => 'Second withdrawal',
            ])->assertOk();
        $this->assertSame('low_stock', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(2, DB::table('notifications')->where('user_id', $adminId)->count());
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();

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
