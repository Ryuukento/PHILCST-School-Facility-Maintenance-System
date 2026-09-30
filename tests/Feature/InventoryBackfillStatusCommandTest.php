<?php

namespace Tests\Feature;

use App\Services\InventoryStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\TestCase;

/**
 * TASK 80 — Inventory Stale-Data Status Backfill.
 *
 * Regression coverage for the `inventory:backfill-status` Artisan command,
 * which reconciles items.status for item_type='inventory_stock' rows
 * against the canonical InventoryStatusService::deriveStatus() rule (the
 * same source of truth already used by InventoryTransactionObserver,
 * InventoryStockController, ItemController, and
 * PurchaseReceiptPostingService going forward).
 *
 * Task 78 found 4 real rows (items.id 1, 2, 3, 5) whose stored status
 * predates that standardization and was deliberately left unmodified —
 * this command performs the one-time (but safely repeatable) correction.
 */
class InventoryBackfillStatusCommandTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('inventory_backfill_status_testing');
        $this->createTestSchema();
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
    }

    // -----------------------------------------------------------------
    // 1-3: canonical classification boundaries are applied correctly
    // -----------------------------------------------------------------

    public function test_stale_row_with_zero_quantity_becomes_out_of_stock(): void
    {
        $id = $this->seedItem(['name' => 'Stale A', 'quantity' => 0, 'reorder_level' => 5, 'status' => 'available']);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $this->assertSame('out_of_stock', DB::table('items')->where('id', $id)->value('status'));
    }

    public function test_stale_row_with_positive_quantity_at_or_below_reorder_becomes_low_stock(): void
    {
        $id = $this->seedItem(['name' => 'Stale B', 'quantity' => 2, 'reorder_level' => 5, 'status' => 'available']);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $this->assertSame('low_stock', DB::table('items')->where('id', $id)->value('status'));
    }

    public function test_stale_row_with_quantity_above_reorder_becomes_available(): void
    {
        $id = $this->seedItem(['name' => 'Stale C', 'quantity' => 18, 'reorder_level' => 5, 'status' => 'low_stock']);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $this->assertSame('available', DB::table('items')->where('id', $id)->value('status'));
    }

    // -----------------------------------------------------------------
    // 4-6: correctness / scope of the mutation
    // -----------------------------------------------------------------

    public function test_already_correct_rows_are_not_unnecessarily_touched(): void
    {
        $id = $this->seedItem(['name' => 'Already Correct', 'quantity' => 10, 'reorder_level' => 5, 'status' => 'available']);
        $originalUpdatedAt = DB::table('items')->where('id', $id)->value('updated_at');

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $row = DB::table('items')->where('id', $id)->first();
        $this->assertSame('available', $row->status);
        $this->assertSame($originalUpdatedAt, $row->updated_at);
    }

    public function test_only_stale_rows_are_changed_others_are_left_alone(): void
    {
        $staleId    = $this->seedItem(['name' => 'Stale', 'quantity' => 1, 'reorder_level' => 5, 'status' => 'available']);
        $correctId1 = $this->seedItem(['name' => 'Correct 1', 'quantity' => 10, 'reorder_level' => 5, 'status' => 'available']);
        $correctId2 = $this->seedItem(['name' => 'Correct 2', 'quantity' => 0, 'reorder_level' => 5, 'status' => 'out_of_stock']);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $this->assertSame('low_stock', DB::table('items')->where('id', $staleId)->value('status'));
        $this->assertSame('available', DB::table('items')->where('id', $correctId1)->value('status'));
        $this->assertSame('out_of_stock', DB::table('items')->where('id', $correctId2)->value('status'));
    }

    public function test_quantity_and_reorder_level_remain_unchanged(): void
    {
        $id = $this->seedItem(['name' => 'Stale D', 'quantity' => 2, 'reorder_level' => 5, 'status' => 'available']);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $row = DB::table('items')->where('id', $id)->first();
        $this->assertSame(2, (int) $row->quantity);
        $this->assertSame(5, (int) $row->reorder_level);
    }

    // -----------------------------------------------------------------
    // 7-8: idempotency and dry-run
    // -----------------------------------------------------------------

    public function test_command_is_idempotent(): void
    {
        $id = $this->seedItem(['name' => 'Stale E', 'quantity' => 2, 'reorder_level' => 5, 'status' => 'available']);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);
        $this->assertSame('low_stock', DB::table('items')->where('id', $id)->value('status'));
        $afterFirstRun = DB::table('items')->where('id', $id)->value('updated_at');

        // Second run must report zero further changes and leave the row untouched.
        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        $row = DB::table('items')->where('id', $id)->first();
        $this->assertSame('low_stock', $row->status);
        $this->assertSame($afterFirstRun, $row->updated_at);
    }

    public function test_dry_run_does_not_modify_the_database(): void
    {
        $id = $this->seedItem(['name' => 'Stale F', 'quantity' => 2, 'reorder_level' => 5, 'status' => 'available']);

        $this->artisan('inventory:backfill-status --dry-run')->assertExitCode(0);

        // Status must remain the stale value — dry-run must not write anything.
        $this->assertSame('available', DB::table('items')->where('id', $id)->value('status'));
    }

    // -----------------------------------------------------------------
    // 9: reuses InventoryStatusService — never reimplements the rule
    // -----------------------------------------------------------------

    public function test_command_produces_the_same_classification_as_inventory_status_service_across_boundaries(): void
    {
        $cases = [
            ['quantity' => -3, 'reorder_level' => 5],
            ['quantity' => 0,  'reorder_level' => 5],
            ['quantity' => 5,  'reorder_level' => 5],
            ['quantity' => 4,  'reorder_level' => 5],
            ['quantity' => 6,  'reorder_level' => 5],
            ['quantity' => 0,  'reorder_level' => 0],
        ];

        $ids = [];
        foreach ($cases as $case) {
            $ids[] = $this->seedItem([
                'name'          => 'Boundary ' . $case['quantity'] . '/' . $case['reorder_level'],
                'quantity'      => $case['quantity'],
                'reorder_level' => $case['reorder_level'],
                // Deliberately wrong so every row is stale and must be corrected.
                'status'        => 'maintenance',
            ]);
        }

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        foreach ($ids as $i => $id) {
            $expected = InventoryStatusService::deriveStatus($cases[$i]['quantity'], $cases[$i]['reorder_level']);
            $this->assertSame($expected, DB::table('items')->where('id', $id)->value('status'));
        }
    }

    // -----------------------------------------------------------------
    // 10: room_asset rows are never touched, even when "stale" under the
    // pooled-quantity rule (they intentionally follow a separate,
    // per-unit rule — see InventoryStockController::createOrUpdateRoomAsset()).
    // -----------------------------------------------------------------

    public function test_room_asset_rows_are_not_affected(): void
    {
        $roomAssetId = $this->seedItem([
            'name'          => 'Aircon Unit',
            'item_type'     => 'room_asset',
            'quantity'      => 1,
            'reorder_level' => 5,
            'status'        => 'available', // "stale" under the pooled rule (would be low_stock), but must stay untouched
        ]);
        $stockId = $this->seedItem([
            'name'          => 'Stale Stock',
            'item_type'     => 'inventory_stock',
            'quantity'      => 2,
            'reorder_level' => 5,
            'status'        => 'available',
        ]);

        $this->artisan('inventory:backfill-status')->assertExitCode(0);

        // room_asset must remain exactly as seeded.
        $this->assertSame('available', DB::table('items')->where('id', $roomAssetId)->value('status'));
        // inventory_stock row must have been corrected.
        $this->assertSame('low_stock', DB::table('items')->where('id', $stockId)->value('status'));
    }

    public function test_handles_zero_stale_rows_cleanly(): void
    {
        $this->seedItem(['name' => 'Fine', 'quantity' => 10, 'reorder_level' => 5, 'status' => 'available']);

        $this->artisan('inventory:backfill-status')
            ->expectsOutputToContain('Mismatched: 0')
            ->assertExitCode(0);
    }
}
