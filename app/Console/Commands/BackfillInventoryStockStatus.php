<?php

namespace App\Console\Commands;

use App\Services\InventoryStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK 80 — Inventory Stale-Data Status Backfill.
 *
 * "Status is always derived, never set" (ARCHITECTURE.md Section 5.2) is
 * already enforced going forward by InventoryTransactionObserver,
 * InventoryStockController::update()/deploy(), ItemController, and
 * PurchaseReceiptPostingService — every write path re-derives status via
 * InventoryStatusService::deriveStatus() before persisting.
 *
 * Task 78 audited existing data against that same rule and found 4 rows
 * (items.id 1, 2, 3, 5; item_type = 'inventory_stock') whose stored status
 * predates the standardization and never got corrected retroactively.
 * Task 78 deliberately left them unmodified (out of that task's scope).
 *
 * This command performs that one-time (but safely repeatable) reconciliation:
 * it recalculates the canonical status for every inventory_stock row and
 * corrects only the rows where the stored status disagrees with
 * InventoryStatusService::deriveStatus() — the single source of truth also
 * used by every live write path. It never recomputes the classification
 * rule itself (no raw-SQL reimplementation), never touches quantity,
 * reorder_level, item metadata, transactions, purchase receipts, or
 * room_asset rows, and is idempotent: a second run reports zero changes
 * once the data is synchronized.
 *
 * room_asset rows are intentionally out of scope — Task 78 confirmed they
 * follow a separate, per-unit rule (each row represents a single physical
 * unit and is deliberately left 'available' regardless of reorder_level;
 * see InventoryStockController::createOrUpdateRoomAsset()), not the
 * pooled-quantity reorder rule this command reconciles.
 */
class BackfillInventoryStockStatus extends Command
{
    protected $signature = 'inventory:backfill-status {--dry-run : Report what would change without writing to the database}';

    protected $description = 'Reconciles items.status for inventory_stock rows against InventoryStatusService::deriveStatus() (item_type=inventory_stock only; quantity/reorder_level/room_asset rows are never touched)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = DB::table('items')
            ->where('item_type', 'inventory_stock')
            ->orderBy('id')
            ->get(['id', 'name', 'quantity', 'reorder_level', 'status']);

        $scanned    = 0;
        $mismatched = 0;
        $updated    = 0;
        $unchanged  = 0;
        $mismatchRows = [];

        foreach ($rows as $row) {
            $scanned++;

            $canonical = InventoryStatusService::deriveStatus((int) $row->quantity, (int) $row->reorder_level);

            if ($canonical === $row->status) {
                $unchanged++;
                continue;
            }

            $mismatched++;
            $mismatchRows[] = [
                'id'        => $row->id,
                'name'      => $row->name,
                'quantity'  => $row->quantity,
                'reorder'   => $row->reorder_level,
                'from'      => $row->status,
                'to'        => $canonical,
            ];

            if (! $dryRun) {
                // Scoped by id AND item_type, and only ever touches status +
                // updated_at — mirrors the narrow-scope UPDATE pattern used
                // by InventoryStockController::update()/deploy().
                DB::table('items')
                    ->where('id', $row->id)
                    ->where('item_type', 'inventory_stock')
                    ->update([
                        'status'     => $canonical,
                        'updated_at' => now(),
                    ]);
                $updated++;
            }
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Inventory stock status backfill complete.');
        $this->line("Scanned:    {$scanned}");
        $this->line("Mismatched: {$mismatched}");
        $this->line($dryRun ? "Would update: {$mismatched}" : "Updated:    {$updated}");
        $this->line("Unchanged:  {$unchanged}");

        if ($mismatchRows !== []) {
            $this->table(
                ['id', 'name', 'quantity', 'reorder_level', 'from', 'to'],
                array_map(
                    fn (array $r) => [$r['id'], $r['name'], $r['quantity'], $r['reorder'], $r['from'], $r['to']],
                    $mismatchRows
                )
            );
        }

        return self::SUCCESS;
    }
}
