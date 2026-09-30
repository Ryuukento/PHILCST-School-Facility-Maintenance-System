<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * INVENTORY REPORTS — Semestral/Yearly date filter fix.
 *
 * The read-only Inventory Reports investigation found that the Semestral/
 * Yearly Report's year+period selector (irGetDateRange() in
 * inventory-reports.php) has NO effect on any of its three source tables:
 *
 *   - Report A (Receipts):       GET /api/purchase-receipts never receives
 *     date_from/date_to from the frontend, and PurchaseReceiptController::
 *     index() has no filtering support at all — it always returns the
 *     entire purchase_receipts table.
 *   - Report B (Dispatches):     the frontend already sends date_from/
 *     date_to, but DispatchController::index() never reads them.
 *   - Report C (Damage Reports): the frontend already sends date_from/
 *     date_to, but DamageReportService::listReports() never reads them.
 *
 * This file locks in the fix for all three: each endpoint's *_date /
 * created_at column (whichever the endpoint already renders as "Date" in
 * the report table) must be filterable via date_from/date_to using the
 * project's established whereDate() convention (see ReportController::
 * index(), AnalyticsService), additively — i.e. omitting the params must
 * preserve today's existing (unfiltered) behavior exactly.
 */
class InventoryReportsDateFilterTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('inventory_reports_date_filter_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    // -----------------------------------------------------------------
    // Report A — Purchase Receipts (receipt_date)
    // -----------------------------------------------------------------

    public function test_purchase_receipts_index_filters_by_date_range(): void
    {
        $userId = $this->seedUser();
        $before = $this->seedReceipt(['or_number' => 'OR-BEFORE', 'receipt_date' => '2025-12-31', 'received_by' => $userId]);
        $inRange = $this->seedReceipt(['or_number' => 'OR-IN', 'receipt_date' => '2026-03-15', 'received_by' => $userId]);
        $after = $this->seedReceipt(['or_number' => 'OR-AFTER', 'receipt_date' => '2026-07-01', 'received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/purchase-receipts?date_from=2026-01-01&date_to=2026-06-30');

        $response->assertOk();
        $ids = collect($response->json('data.receipts'))->pluck('id')->all();

        $this->assertSame([$inRange], $ids);
        $this->assertNotContains($before, $ids);
        $this->assertNotContains($after, $ids);
    }

    public function test_purchase_receipts_index_without_date_filters_returns_all_receipts(): void
    {
        $userId = $this->seedUser();
        $this->seedReceipt(['or_number' => 'OR-A', 'receipt_date' => '2025-01-01', 'received_by' => $userId]);
        $this->seedReceipt(['or_number' => 'OR-B', 'receipt_date' => '2026-06-01', 'received_by' => $userId]);
        $this->seedReceipt(['or_number' => 'OR-C', 'receipt_date' => '2027-12-31', 'received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/purchase-receipts');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.receipts'), 'Omitting date filters must preserve existing (unfiltered) behavior.');
    }

    /**
     * receipt_date is a plain `date` column, so this exercises the exact
     * boundaries irGetDateRange() produces: 1st Semester = Jan 1..Jun 30,
     * 2nd Semester = Jul 1..Dec 31, Full Year = Jan 1..Dec 31.
     */
    public function test_purchase_receipts_index_respects_semester_and_year_boundaries(): void
    {
        $userId = $this->seedUser();
        $jun30 = $this->seedReceipt(['or_number' => 'OR-JUN30', 'receipt_date' => '2026-06-30', 'received_by' => $userId]);
        $jul1 = $this->seedReceipt(['or_number' => 'OR-JUL1', 'receipt_date' => '2026-07-01', 'received_by' => $userId]);
        $dec31 = $this->seedReceipt(['or_number' => 'OR-DEC31', 'receipt_date' => '2026-12-31', 'received_by' => $userId]);
        $nextJan1 = $this->seedReceipt(['or_number' => 'OR-NEXTJAN1', 'receipt_date' => '2027-01-01', 'received_by' => $userId]);

        // 1st Semester 2026 (Jan 1 - Jun 30): must include Jun 30, exclude Jul 1.
        $sem1 = $this->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/purchase-receipts?date_from=2026-01-01&date_to=2026-06-30');
        $sem1Ids = collect($sem1->json('data.receipts'))->pluck('id')->all();
        $this->assertContains($jun30, $sem1Ids);
        $this->assertNotContains($jul1, $sem1Ids);

        // 2nd Semester 2026 (Jul 1 - Dec 31): must include Jul 1 AND Dec 31, exclude next Jan 1.
        $sem2 = $this->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/purchase-receipts?date_from=2026-07-01&date_to=2026-12-31');
        $sem2Ids = collect($sem2->json('data.receipts'))->pluck('id')->all();
        $this->assertContains($jul1, $sem2Ids);
        $this->assertContains($dec31, $sem2Ids);
        $this->assertNotContains($nextJan1, $sem2Ids);

        // Full Year 2026 (Jan 1 - Dec 31): must include Dec 31, exclude next Jan 1.
        $fullYear = $this->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/purchase-receipts?date_from=2026-01-01&date_to=2026-12-31');
        $fullYearIds = collect($fullYear->json('data.receipts'))->pluck('id')->all();
        $this->assertContains($jun30, $fullYearIds);
        $this->assertContains($jul1, $fullYearIds);
        $this->assertContains($dec31, $fullYearIds);
        $this->assertNotContains($nextJan1, $fullYearIds);
    }

    // -----------------------------------------------------------------
    // Report B — Dispatches (created_at)
    // -----------------------------------------------------------------

    public function test_dispatches_index_filters_by_date_range(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 10]);
        $before = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-BEFORE', 'created_at' => '2025-12-31 10:00:00']);
        $inRange = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-IN', 'created_at' => '2026-03-15 10:00:00']);
        $after = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-AFTER', 'created_at' => '2026-07-01 10:00:00']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/dispatches?date_from=2026-01-01&date_to=2026-06-30');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$inRange], $ids);
        $this->assertNotContains($before, $ids);
        $this->assertNotContains($after, $ids);
    }

    public function test_dispatches_index_date_filter_combines_with_existing_status_filter(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 10]);
        $matching = $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-MATCH',
            'status' => 'approved',
            'created_at' => '2026-03-15 10:00:00',
        ]);
        // Right status, wrong date.
        $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-WRONG-DATE',
            'status' => 'approved',
            'created_at' => '2027-01-01 10:00:00',
        ]);
        // Right date, wrong status.
        $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-WRONG-STATUS',
            'status' => 'pending',
            'created_at' => '2026-03-15 10:00:00',
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/dispatches?status=approved&date_from=2026-01-01&date_to=2026-06-30');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$matching], $ids, 'date_from/date_to must combine with (not replace) the existing status filter.');
    }

    public function test_dispatches_index_without_date_filters_returns_all_dispatches(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 10]);
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-A', 'created_at' => '2025-01-01 00:00:00']);
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-B', 'created_at' => '2026-06-01 00:00:00']);
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-C', 'created_at' => '2027-12-31 00:00:00']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/dispatches');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'), 'Omitting date filters must preserve existing (unfiltered) behavior.');
    }

    /**
     * created_at is a datetime column (unlike receipt_date's plain date), so
     * this specifically proves the fix uses whereDate() (date-part
     * comparison) rather than a naive string comparison that would wrongly
     * exclude a same-day record with a non-midnight timestamp.
     */
    public function test_dispatches_index_respects_day_boundary_including_end_of_day_timestamps(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 10]);
        $lastMomentOfSem1 = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-2359', 'created_at' => '2026-06-30 23:59:59']);
        $firstMomentOfSem2 = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-0000', 'created_at' => '2026-07-01 00:00:01']);

        $sem1 = $this->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/dispatches?date_from=2026-01-01&date_to=2026-06-30');
        $sem1Ids = collect($sem1->json('data.data'))->pluck('id')->all();
        $this->assertContains($lastMomentOfSem1, $sem1Ids);
        $this->assertNotContains($firstMomentOfSem2, $sem1Ids);

        $sem2 = $this->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/dispatches?date_from=2026-07-01&date_to=2026-12-31');
        $sem2Ids = collect($sem2->json('data.data'))->pluck('id')->all();
        $this->assertContains($firstMomentOfSem2, $sem2Ids);
        $this->assertNotContains($lastMomentOfSem1, $sem2Ids);
    }

    // -----------------------------------------------------------------
    // Report C — Damage Reports (created_at)
    // -----------------------------------------------------------------

    public function test_damage_reports_index_filters_by_date_range(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $before = $this->seedDamageReport(['damage_report_code' => 'DR-BEFORE', 'created_at' => '2025-12-31 10:00:00']);
        $inRange = $this->seedDamageReport(['damage_report_code' => 'DR-IN', 'created_at' => '2026-03-15 10:00:00']);
        $after = $this->seedDamageReport(['damage_report_code' => 'DR-AFTER', 'created_at' => '2026-07-01 10:00:00']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/damage-reports?date_from=2026-01-01&date_to=2026-06-30');

        $response->assertOk();
        $ids = collect($response->json('data.reports.data'))->pluck('id')->all();

        $this->assertSame([$inRange], $ids);
        $this->assertNotContains($before, $ids);
        $this->assertNotContains($after, $ids);
    }

    public function test_damage_reports_index_date_filter_combines_with_existing_severity_filter(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $matching = $this->seedDamageReport([
            'damage_report_code' => 'DR-MATCH',
            'severity_level' => 'critical',
            'created_at' => '2026-03-15 10:00:00',
        ]);
        // Right severity, wrong date.
        $this->seedDamageReport([
            'damage_report_code' => 'DR-WRONG-DATE',
            'severity_level' => 'critical',
            'created_at' => '2027-01-01 10:00:00',
        ]);
        // Right date, wrong severity.
        $this->seedDamageReport([
            'damage_report_code' => 'DR-WRONG-SEVERITY',
            'severity_level' => 'low',
            'created_at' => '2026-03-15 10:00:00',
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/damage-reports?severity_level=critical&date_from=2026-01-01&date_to=2026-06-30');

        $response->assertOk();
        $ids = collect($response->json('data.reports.data'))->pluck('id')->all();

        $this->assertSame([$matching], $ids, 'date_from/date_to must combine with (not replace) the existing severity_level filter.');
    }

    public function test_damage_reports_index_without_date_filters_returns_all_reports(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $this->seedDamageReport(['damage_report_code' => 'DR-A', 'created_at' => '2025-01-01 00:00:00']);
        $this->seedDamageReport(['damage_report_code' => 'DR-B', 'created_at' => '2026-06-01 00:00:00']);
        $this->seedDamageReport(['damage_report_code' => 'DR-C', 'created_at' => '2027-12-31 00:00:00']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/damage-reports');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.reports.data'), 'Omitting date filters must preserve existing (unfiltered) behavior.');
    }

    // -----------------------------------------------------------------
    // Schema / seeding
    // -----------------------------------------------------------------

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createActivityLogsTable();
        // DispatchController::index() eager-loads report/report.creator even
        // when a dispatch has no report_id — the table just needs to exist
        // (same reasoning as DispatchPersonnelAuditTrailTest).
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::create('purchase_receipts', function ($table): void {
            $table->bigIncrements('id');
            $table->string('or_number')->unique();
            $table->date('receipt_date');
            $table->string('supplier_name');
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('received_by');
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('proof_image')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('inventory_room_id')->nullable();
            $table->integer('quantity_received');
            $table->string('unit', 20)->default('pc');
            $table->timestamps();
        });

        Schema::create('damage_reports', function ($table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedBigInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedBigInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function seedReceipt(array $overrides = []): int
    {
        return DB::table('purchase_receipts')->insertGetId(array_merge([
            'or_number' => 'OR-' . uniqid('', true),
            'receipt_date' => '2026-07-14',
            'supplier_name' => 'Test Supplier',
            'department_id' => null,
            'received_by' => $this->seedUser(),
            'remarks' => null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedDispatch(int $itemId, int $quantity, array $overrides = []): int
    {
        $dispatchId = DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid('', true),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        DB::table('dispatch_items')->insert([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dispatchId;
    }

    private function seedDamageReport(array $overrides = []): int
    {
        return DB::table('damage_reports')->insertGetId(array_merge([
            'damage_report_code' => 'DR-' . uniqid('', true),
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
