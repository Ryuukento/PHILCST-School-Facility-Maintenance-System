<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        // TASK 18 — notify once on a genuine NORMAL -> LOW/OUT_OF_STOCK transition.
        private readonly InventoryLowStockNotifier $inventoryLowStockNotifier
    ) {
    }

    /**
     * Manually increases or decreases an item's on-hand quantity through the
     * standard ledger (InventoryTransaction -> Observer) path, reusing the
     * 'adjustment' transaction type and its existing 'DEDUCT:' reference-note
     * convention for decreases (see ItemController::store(),
     * InventoryTransactionObserver::created()/deleted()). This Service never
     * writes Item.quantity/reserved_quantity directly (ARCHITECTURE.md Section 5).
     */
    public function adjust(Item $item, string $direction, int $quantity, string $reason, int $performedBy): Item
    {
        return DB::transaction(function () use ($item, $direction, $quantity, $reason, $performedBy): Item {
            $locked = Item::query()->lockForUpdate()->find($item->id);
            if (!$locked) {
                throw new ModelNotFoundException('Item not found');
            }

            // Captured BEFORE creating the InventoryTransaction below — see
            // the identical note in PurchaseReceiptPostingService::postReceipt().
            // The Observer's created() hook re-derives and persists
            // Item.status synchronously inside that create() call, so
            // capturing $previousStatus any later (e.g. after refresh()
            // following create()) would already reflect the post-mutation
            // status and handleStatusChange() would never notify.
            $previousStatus = $locked->status;

            if ($direction === 'decrease') {
                // The Observer's creating() hook does not validate 'adjustment'
                // transactions for insufficient stock (negative adjustments are
                // "handled elsewhere" per its own comment) — this Service is the
                // one place that enforces it, against the locked row.
                $available = (int) $locked->quantity - (int) $locked->reserved_quantity;
                if ($quantity > $available) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Insufficient stock for adjustment. Available: '
                            . $available . ', requested: ' . $quantity,
                    ]);
                }
            }

            $referenceNote = ($direction === 'decrease' ? 'DEDUCT: ' : '')
                . 'Manual stock adjustment - ' . $reason;

            InventoryTransaction::query()->create([
                'item_id' => $locked->id,
                'report_id' => null,
                'room_id' => null,
                'transaction_type' => 'adjustment',
                'quantity' => $quantity,
                'reference_note' => $referenceNote,
                'performed_by' => $performedBy,
            ]);

            // Mirrors ItemController::store()'s post-transaction status
            // re-derivation for the same 'adjustment' transaction type. The
            // Observer already derived and saved the post-mutation status as
            // part of the create() call above; refresh() here just pulls that
            // value back into this in-memory model and the redundant
            // derive+save keeps this resilient if that invariant ever changes.
            $locked->refresh();
            $locked->status = InventoryStatusService::deriveStatus((int) $locked->quantity, (int) ($locked->reorder_level ?? 0));
            $locked->save();

            // TASK 18 — fires only on a genuine NORMAL -> LOW/OUT_OF_STOCK transition.
            $this->inventoryLowStockNotifier->handleStatusChange(
                $locked->id,
                $locked->name,
                $previousStatus,
                $locked->status
            );

            $this->activityLogService->logFromSession([
                'user_id' => $performedBy,
                'action' => 'ADJUST_STOCK',
                'module' => 'inventory',
                'entity_type' => 'item',
                'entity_id' => $locked->id,
                'details' => ucfirst($direction) . 'd stock of "' . $locked->name . '" by ' . $quantity . '. Reason: ' . $reason,
                'meta' => [
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'reason' => $reason,
                ],
            ]);

            return $locked;
        });
    }
}
