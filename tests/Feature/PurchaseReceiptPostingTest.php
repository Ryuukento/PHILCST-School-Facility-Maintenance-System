<?php

namespace Tests\Feature;

use App\Models\InventoryTransaction;
use App\Services\ActivityLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class PurchaseReceiptPostingTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('purchase_receipt_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_posting_draft_receipt_updates_existing_item_exactly_once(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Extension Cord',
            'quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
        ]);

        $receiptId = $this->seedReceipt(['received_by' => $userId]);
        $this->seedReceiptItem($receiptId, [
            'item_id' => $itemId,
            'item_name' => 'Extension Cord',
            'quantity_received' => 4,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post");

        $response->assertOk();
        $this->assertSame('posted', DB::table('purchase_receipts')->where('id', $receiptId)->value('status'));
        $this->assertSame(14, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('item_id', $itemId)->count());

        $tx = DB::table('inventory_transactions')->where('item_id', $itemId)->first();
        $this->assertSame('adjustment', $tx->transaction_type);
        $this->assertSame(4, (int) $tx->quantity);
    }

    public function test_posting_multi_item_receipt_creates_ledger_entry_per_line(): void
    {
        $userId = $this->seedUser();
        $firstItemId = $this->seedItem([
            'name' => 'Projector',
            'quantity' => 2,
            'reorder_level' => 0,
        ]);
        $secondItemId = $this->seedItem([
            'name' => 'HDMI Cable',
            'quantity' => 7,
            'reorder_level' => 1,
        ]);

        $receiptId = $this->seedReceipt(['received_by' => $userId]);
        $this->seedReceiptItem($receiptId, [
            'item_id' => $firstItemId,
            'item_name' => 'Projector',
            'quantity_received' => 1,
        ]);
        $this->seedReceiptItem($receiptId, [
            'item_id' => $secondItemId,
            'item_name' => 'HDMI Cable',
            'quantity_received' => 5,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_staff')
            ->postJson("/api/purchase-receipts/{$receiptId}/post");

        $response->assertOk();
        $this->assertSame(3, (int) DB::table('items')->where('id', $firstItemId)->value('quantity'));
        $this->assertSame(12, (int) DB::table('items')->where('id', $secondItemId)->value('quantity'));
        $this->assertSame(2, DB::table('inventory_transactions')->count());
        $this->assertSame('posted', DB::table('purchase_receipts')->where('id', $receiptId)->value('status'));
    }

    public function test_posting_can_create_new_inventory_item_without_doubling_quantity(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $lineItemId = $this->seedReceiptItem($receiptId, [
            'item_id' => null,
            'item_name' => 'Power Strip',
            'quantity_received' => 6,
            'unit' => 'pc',
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post");

        $response->assertOk();

        $item = DB::table('items')
            ->where('name', 'Power Strip')
            ->first();

        $this->assertNotNull($item);
        $this->assertSame(6, (int) $item->quantity);
        $this->assertSame((int) $item->id, (int) DB::table('purchase_receipt_items')->where('id', $lineItemId)->value('item_id'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('item_id', $item->id)->count());
    }

    public function test_reposting_posted_receipt_is_rejected_without_duplicate_stock(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Network Switch',
            'quantity' => 9,
        ]);

        $receiptId = $this->seedReceipt([
            'received_by' => $userId,
            'status' => 'posted',
        ]);
        $this->seedReceiptItem($receiptId, [
            'item_id' => $itemId,
            'item_name' => 'Network Switch',
            'quantity_received' => 3,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post");

        $response->assertStatus(422);
        $this->assertSame(9, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    public function test_posting_rolls_back_all_stock_changes_when_exception_occurs(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Portable Fan',
            'quantity' => 5,
        ]);

        $receiptId = $this->seedReceipt(['received_by' => $userId]);
        $this->seedReceiptItem($receiptId, [
            'item_id' => $itemId,
            'item_name' => 'Portable Fan',
            'quantity_received' => 2,
        ]);

        app()->instance(ActivityLogService::class, new class extends ActivityLogService {
            public function log(array $payload, ?Request $request = null): ?\App\Models\ActivityLog
            {
                throw new \RuntimeException('Forced observer failure');
            }
        });

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post");

        $response->assertStatus(500);
        $this->assertSame('draft', DB::table('purchase_receipts')->where('id', $receiptId)->value('status'));
        $this->assertSame(5, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    public function test_posting_receipt_with_no_items_is_rejected(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post");

        $response->assertStatus(422);
        $this->assertSame('draft', DB::table('purchase_receipts')->where('id', $receiptId)->value('status'));
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    public function test_inventory_transaction_observer_adjustment_increases_quantity_once(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Mouse',
            'quantity' => 5,
        ]);

        InventoryTransaction::query()->create([
            'item_id' => $itemId,
            'report_id' => null,
            'room_id' => null,
            'transaction_type' => 'adjustment',
            'quantity' => 3,
            'reference_note' => 'Observer test',
            'performed_by' => $userId,
        ]);

        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('item_id', $itemId)->count());
    }

    // -----------------------------------------------------------------------
    // TASK H — bulk line-item entry.
    //
    // These live beside the posting tests on purpose: the point of bulk entry
    // is that it must produce line rows indistinguishable from ones added one
    // at a time, so every case below ends by posting the receipt and checking
    // the inventory result against the behaviour the tests above already pin.
    // -----------------------------------------------------------------------

    public function test_bulk_add_creates_one_line_per_item_and_posts_each_once(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Keyboard', 'quantity_received' => 3, 'unit' => 'pc'],
                    ['item_name' => 'Mouse', 'quantity_received' => 2, 'unit' => 'pc'],
                    ['item_name' => 'Monitor Stand', 'quantity_received' => 1, 'unit' => 'set'],
                ],
            ]);

        $response->assertCreated();
        $this->assertSame(3, $response->json('data.count'));
        $this->assertSame(0, $response->json('data.combined'));

        $lines = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->get();
        $this->assertCount(3, $lines);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post")
            ->assertOk();

        $this->assertSame(3, (int) DB::table('items')->where('name', 'Keyboard')->value('quantity'));
        $this->assertSame(2, (int) DB::table('items')->where('name', 'Mouse')->value('quantity'));
        $this->assertSame(1, (int) DB::table('items')->where('name', 'Monitor Stand')->value('quantity'));
        $this->assertSame(3, DB::table('inventory_transactions')->count());
    }

    public function test_bulk_add_combines_duplicate_rows_into_one_line(): void
    {
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Extension Cord',
            'quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
        ]);
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Extension Cord', 'item_id' => $itemId, 'quantity_received' => 4, 'unit' => 'pc'],
                    ['item_name' => 'Extension Cord', 'item_id' => $itemId, 'quantity_received' => 6, 'unit' => 'pc'],
                ],
            ]);

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame(1, $response->json('data.combined'));

        $lines = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->get();
        $this->assertCount(1, $lines);
        $this->assertSame(10, (int) $lines->first()->quantity_received);

        // Combining must be invisible to inventory: 4+6 on one line has to land
        // the same 10 units as two separate lines would have.
        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/post")
            ->assertOk();

        $this->assertSame(20, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    public function test_bulk_add_combines_duplicates_matched_by_name_case_insensitively(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Bond Paper', 'quantity_received' => 5, 'unit' => 'box'],
                    ['item_name' => 'bond paper', 'quantity_received' => 7, 'unit' => 'box'],
                ],
            ]);

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame(12, (int) DB::table('purchase_receipt_items')
            ->where('purchase_receipt_id', $receiptId)->value('quantity_received'));
    }

    public function test_bulk_add_combines_a_picked_item_with_a_typed_row_of_the_same_name(): void
    {
        // The search box supplies an item_id; typing the same name by hand does
        // not. Posting resolves both to the same stock row, so bulk entry must
        // recognise them as one item rather than leaving two lines behind.
        $userId = $this->seedUser();
        $itemId = $this->seedItem([
            'name' => 'Extension Cord',
            'quantity' => 1,
            'reserved_quantity' => 0,
            'reorder_level' => 0,
        ]);
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Extension Cord', 'item_id' => $itemId, 'quantity_received' => 4, 'unit' => 'pc'],
                    ['item_name' => 'extension cord', 'quantity_received' => 6, 'unit' => 'pc'],
                ],
            ]);

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.count'));

        $line = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->first();
        $this->assertSame(10, (int) $line->quantity_received);
        // The merged line keeps the id the picked row supplied.
        $this->assertSame($itemId, (int) $line->item_id);
    }

    public function test_bulk_add_keeps_distinct_stock_rows_that_share_a_name_separate(): void
    {
        $userId = $this->seedUser();
        $firstId = $this->seedItem([
            'name' => 'Cable', 'quantity' => 0,
            'reserved_quantity' => 0, 'reorder_level' => 0,
        ]);
        $secondId = $this->seedItem([
            'name' => 'Cable', 'quantity' => 0,
            'reserved_quantity' => 0, 'reorder_level' => 0,
        ]);
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Cable', 'item_id' => $firstId, 'quantity_received' => 2, 'unit' => 'pc'],
                    ['item_name' => 'Cable', 'item_id' => $secondId, 'quantity_received' => 3, 'unit' => 'pc'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.count', 2);

        $this->assertSame(2, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->count());
    }

    public function test_bulk_add_rejects_conflicting_units_for_the_same_item(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Bond Paper', 'quantity_received' => 5, 'unit' => 'box'],
                    ['item_name' => 'Bond Paper', 'quantity_received' => 7, 'unit' => 'pc'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->count());
    }

    public function test_bulk_add_writes_nothing_when_any_row_is_invalid(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Keyboard', 'quantity_received' => 3, 'unit' => 'pc'],
                    ['item_name' => '', 'quantity_received' => 2, 'unit' => 'pc'],
                    ['item_name' => 'Mouse', 'quantity_received' => 0, 'unit' => 'pc'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertCount(2, $response->json('data.errors'));
        $this->assertSame(1, $response->json('data.errors.0.index'));

        // §10 — atomic: the valid first row must not have been written either.
        $this->assertSame(0, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->count());
    }

    public function test_bulk_add_rejects_unknown_item_id_without_writing_anything(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Keyboard', 'quantity_received' => 3, 'unit' => 'pc'],
                    ['item_name' => 'Ghost', 'item_id' => 999999, 'quantity_received' => 1, 'unit' => 'pc'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->count());
    }

    public function test_bulk_add_is_rejected_on_a_posted_receipt(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId, 'status' => 'posted']);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Keyboard', 'quantity_received' => 3, 'unit' => 'pc'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot add items to a posted receipt');

        $this->assertSame(0, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->count());
    }

    public function test_bulk_add_appends_to_a_draft_that_already_has_lines(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);
        $this->seedReceiptItem($receiptId, [
            'item_name' => 'Existing Line',
            'quantity_received' => 2,
        ]);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items/bulk", [
                'items' => [
                    ['item_name' => 'Keyboard', 'quantity_received' => 3, 'unit' => 'pc'],
                ],
            ])
            ->assertCreated();

        $this->assertSame(2, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->count());
    }

    public function test_single_item_endpoint_still_behaves_identically(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items", [
                'item_name' => 'Solo Item',
                'quantity_received' => 4,
                'unit' => 'pc',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Item added to receipt');

        $line = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->first();
        $this->assertSame('Solo Item', $line->item_name);
        $this->assertSame(4, (int) $line->quantity_received);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/purchase-receipts/{$receiptId}/items", [
                'item_name' => '',
                'quantity_received' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'item_name and quantity_received (> 0) are required');
    }

    // ---------------------------------------------------------------
    // 2026-09-27 — receipt validation + removing a mistaken draft line
    // ---------------------------------------------------------------

    public function test_a_line_can_be_removed_from_a_draft_receipt_without_touching_stock(): void
    {
        $userId = $this->seedUser();
        $receiptId = $this->seedReceipt(['received_by' => $userId]);
        $keepId = $this->seedReceiptItem($receiptId, ['item_name' => 'Keep Me']);
        $wrongId = $this->seedReceiptItem($receiptId, ['item_name' => 'Wrong Line']);

        $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->deleteJson("/api/purchase-receipts/{$receiptId}/items/{$wrongId}")
            ->assertOk();

        $this->assertFalse(DB::table('purchase_receipt_items')->where('id', $wrongId)->exists());
        $this->assertTrue(DB::table('purchase_receipt_items')->where('id', $keepId)->exists());
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    public function test_a_line_cannot_be_removed_from_a_posted_receipt_or_another_receipt(): void
    {
        $userId = $this->seedUser();
        $postedId = $this->seedReceipt(['received_by' => $userId, 'status' => 'posted']);
        $postedLine = $this->seedReceiptItem($postedId);
        $draftId = $this->seedReceipt(['received_by' => $userId]);

        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->deleteJson("/api/purchase-receipts/{$postedId}/items/{$postedLine}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot remove items from a posted receipt');

        // A line id that belongs to a different receipt is not found here.
        $this->actingAsSessionUser($userId, 'maintenance_admin')
            ->deleteJson("/api/purchase-receipts/{$draftId}/items/{$postedLine}")
            ->assertStatus(404);

        $this->assertTrue(DB::table('purchase_receipt_items')->where('id', $postedLine)->exists());
    }

    public function test_receipt_date_cannot_be_in_the_future(): void
    {
        $userId = $this->seedUser();

        $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->postJson('/api/purchase-receipts', [
                'or_number' => 'OR-FUTURE-1',
                'receipt_date' => now()->addDay()->toDateString(),
                'supplier_name' => 'Test Supplier',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Receipt date cannot be in the future');

        $this->assertSame(0, DB::table('purchase_receipts')->count());
    }

    public function test_receipt_department_must_exist(): void
    {
        $userId = $this->seedUser();

        $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->postJson('/api/purchase-receipts', [
                'or_number' => 'OR-DEPT-1',
                'receipt_date' => now()->toDateString(),
                'supplier_name' => 'Test Supplier',
                'department_id' => 9999,
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('purchase_receipts')->count());
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('inventory_categories');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();

        // inventory_rooms was dropped here (and inventory_room_id removed from
        // items/purchase_receipt_items below) to match
        // database/migrations/2026_09_28_000200_drop_inventory_rooms_and_related_columns.php:
        // "Inventory is a single centralized stock pool -- this school does not
        // use separate inventory rooms." The real PurchaseReceiptController /
        // PostingService no longer read or write this column, so a fixture that
        // still required it was testing a schema the app doesn't have anymore.

        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });

        $this->createItemsTable();
        $this->createActivityLogsTable();

        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('or_number')->unique();
            $table->date('receipt_date');
            $table->string('supplier_name');
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('received_by');
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            // 2026_09_30_000200_add_proof_image_to_purchase_receipts_table —
            // nullable, matches the real schema. Not exercised by any test in
            // this file, but PurchaseReceiptController::index()/show() select
            // it explicitly, so it must exist or those endpoints error.
            $table->string('proof_image')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('category_id')->nullable();
            $table->integer('quantity_received');
            $table->string('unit', 20)->default('pc');
            $table->timestamps();
        });

        $this->createInventoryTransactionsTable();
        Schema::enableForeignKeyConstraints();
    }

    private function seedReceipt(array $overrides = []): int
    {
        return DB::table('purchase_receipts')->insertGetId(array_merge([
            'or_number' => 'OR-' . uniqid(),
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

    private function seedReceiptItem(int $receiptId, array $overrides = []): int
    {
        return DB::table('purchase_receipt_items')->insertGetId(array_merge([
            'purchase_receipt_id' => $receiptId,
            'item_id' => null,
            'item_name' => 'Receipt Item',
            'category_id' => null,
            'quantity_received' => 1,
            'unit' => 'pc',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
