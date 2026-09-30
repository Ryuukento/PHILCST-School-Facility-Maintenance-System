<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 52 — regression coverage for the same class of notification defect
 * Task 49 fixed in InventoryAdjustmentService::adjust() and
 * PurchaseReceiptPostingService::postReceipt(): the 'replaced' branch of
 * DamageReportService::updateStatus() also creates a 'deploy'
 * InventoryTransaction for the replacement item — which can push that item
 * below its reorder level — but never captured a pre-mutation Item.status
 * and never called InventoryLowStockNotifier::handleStatusChange() at all.
 * Marking a damage report as replaced with a replacement item that dropped
 * into LOW/OUT_OF_STOCK as a result silently produced zero "Item Low Stock"
 * notifications.
 *
 * Fixed by capturing the replacement item's status immediately before
 * creating the InventoryTransaction and calling handleStatusChange() with
 * its freshly re-derived post-mutation status afterward.
 */
class DamageReportReplacementLowStockNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('damage_report_replacement_low_stock_notification_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_marking_replaced_across_the_reorder_level_notifies_admins(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId, 'status' => 'active']);
        $superId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId, 'status' => 'active']);
        $inactiveAdminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId, 'status' => 'inactive']);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId, 'status' => 'active']);

        // quantity(5) > reorder_level(3) -> 'available'
        $replacementItemId = $this->seedItem([
            'name' => 'Ceiling Fan Motor',
            'item_type' => 'inventory_stock',
            'quantity' => 5,
            'reorder_level' => 3,
            'status' => 'available',
        ]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'under_review',
            'reported_by' => $reporterId,
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", [
                'status' => 'replaced',
                'replacement_item_id' => $replacementItemId,
                'replacement_quantity' => 3,
            ]);
        $response->assertOk();

        // quantity now 2 <= reorder_level(3) -> 'low_stock'
        $this->assertSame('low_stock', DB::table('items')->where('id', $replacementItemId)->value('status'));

        $lowStock = DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $replacementItemId);
        $this->assertSame(1, (clone $lowStock)->where('user_id', $adminId)->count());
        $this->assertSame(1, (clone $lowStock)->where('user_id', $superId)->count());
        $this->assertSame(0, (clone $lowStock)->where('user_id', $staffId)->count());
        $this->assertSame(0, (clone $lowStock)->where('user_id', $inactiveAdminId)->count());
        // The original reporter gets a "Damage Report Replacement Update"
        // notification, but must not also get the inventory low-stock one.
        $this->assertSame(0, (clone $lowStock)->where('user_id', $reporterId)->count());

        $notification = (clone $lowStock)->where('user_id', $superId)->first();
        $this->assertStringContainsString('Low Stock', $notification->title);
        $this->assertStringContainsString('Ceiling Fan Motor', $notification->message);
    }

    public function test_marking_replaced_that_stays_below_reorder_level_does_not_renotify(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId, 'status' => 'active']);

        // Already low_stock (quantity 3 <= reorder_level 3).
        $replacementItemId = $this->seedItem([
            'name' => 'Rare Sensor',
            'item_type' => 'inventory_stock',
            'quantity' => 3,
            'reorder_level' => 3,
            'status' => 'low_stock',
        ]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'under_review',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", [
                'status' => 'replaced',
                'replacement_item_id' => $replacementItemId,
                'replacement_quantity' => 3,
            ]);
        $response->assertOk();

        // quantity now 0 -> 'out_of_stock', still "below threshold" throughout.
        $this->assertSame('out_of_stock', DB::table('items')->where('id', $replacementItemId)->value('status'));
        $this->assertSame(
            0,
            DB::table('notifications')->where('entity_type', 'inventory')->where('entity_id', $replacementItemId)->count(),
            'LOW -> OUT_OF_STOCK must not re-notify; both are "below threshold".'
        );
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function ($table): void {
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

    private function createDamageReportsTable(): void
    {
        Schema::create('damage_reports', function ($table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDamageReportHistoriesTable(): void
    {
        Schema::create('damage_report_histories', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    private function seedDamageReport(array $overrides = []): int
    {
        return DB::table('damage_reports')->insertGetId(array_merge([
            'damage_report_code' => 'DMG-' . uniqid(),
            'report_id' => null,
            'item_id' => null,
            'room_id' => null,
            'department_id' => null,
            'damage_description' => 'Something is broken.',
            'severity_level' => 'medium',
            'reported_by' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
