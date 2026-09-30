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
 * TASK 52 — regression coverage for the same class of notification defect
 * Task 49 fixed in InventoryAdjustmentService::adjust() and
 * PurchaseReceiptPostingService::postReceipt(): NeedChangeService::approve()
 * also creates a 'deploy' InventoryTransaction that can push an item below
 * its reorder level, but never captured a pre-mutation Item.status and never
 * called InventoryLowStockNotifier::handleStatusChange() at all — so a Need
 * Change approval that dropped an item into LOW/OUT_OF_STOCK silently
 * produced zero "Item Low Stock" notifications.
 *
 * Fixed by capturing the item's status immediately before creating the
 * InventoryTransaction (mirroring InventoryAdjustmentService::adjust()) and
 * calling handleStatusChange() with the freshly re-derived post-mutation
 * status afterward.
 */
class NeedChangeApprovalLowStockNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('need_change_approval_low_stock_notification_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_approving_need_change_across_the_reorder_level_notifies_admins(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $inactiveAdminId = $this->seedUser(['role' => 'super_admin', 'status' => 'inactive']);

        // quantity(20) > reorder_level(5) -> 'available'
        $itemId = $this->seedItem([
            'name' => 'Projector Bulb',
            'quantity' => 20,
            'reorder_level' => 5,
            'status' => 'available',
        ]);
        $reportId = $this->seedReport([
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 16,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertOk();

        // quantity now 4 <= reorder_level(5) -> 'low_stock'
        $this->assertSame('low_stock', DB::table('items')->where('id', $itemId)->value('status'));

        $lowStock = DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $itemId);
        $this->assertSame(1, (clone $lowStock)->where('user_id', $approverId)->count());
        $this->assertSame(1, (clone $lowStock)->where('user_id', $headId)->count());
        $this->assertSame(0, (clone $lowStock)->where('user_id', $staffId)->count());
        $this->assertSame(0, (clone $lowStock)->where('user_id', $inactiveAdminId)->count());

        $notification = (clone $lowStock)->where('user_id', $headId)->first();
        $this->assertStringContainsString('Low Stock', $notification->title);
        $this->assertStringContainsString('Projector Bulb', $notification->message);
    }

    public function test_approving_second_need_change_that_stays_below_reorder_level_does_not_renotify(): void
    {
        $approverId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);

        // Already low_stock (quantity 5 <= reorder_level 5).
        $itemId = $this->seedItem([
            'name' => 'Ceiling Fan Blade',
            'quantity' => 5,
            'reorder_level' => 5,
            'status' => 'low_stock',
        ]);
        $reportId = $this->seedReport([
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 5,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($approverId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['approve_need_change' => true]);

        $response->assertOk();

        // quantity now 0 -> 'out_of_stock', still "below threshold" throughout.
        $this->assertSame('out_of_stock', DB::table('items')->where('id', $itemId)->value('status'));
        $this->assertSame(
            0,
            DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $itemId)->count(),
            'LOW -> OUT_OF_STOCK must not re-notify; both are "below threshold".'
        );
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
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
}
