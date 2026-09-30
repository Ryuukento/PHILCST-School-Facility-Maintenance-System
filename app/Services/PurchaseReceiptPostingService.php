<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptPostingService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        // TASK 18 — notify once on a genuine NORMAL -> LOW/OUT_OF_STOCK transition
        // for any item touched by this receipt.
        private readonly InventoryLowStockNotifier $inventoryLowStockNotifier
    ) {
    }

    public function postReceipt(int $receiptId, ?int $performedBy = null, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($receiptId, $performedBy, $ipAddress): void {
            $receipt = DB::table('purchase_receipts')
                ->where('id', $receiptId)
                ->lockForUpdate()
                ->first();

            if (!$receipt) {
                throw new ModelNotFoundException('Purchase receipt not found');
            }

            if ($receipt->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Receipt is already posted',
                ]);
            }

            $lineItems = DB::table('purchase_receipt_items')
                ->where('purchase_receipt_id', $receiptId)
                ->orderBy('id')
                ->get();

            if ($lineItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Cannot post a receipt with no items',
                ]);
            }

            foreach ($lineItems as $line) {
                $item = $this->resolveInventoryItem($line);

                // Captured BEFORE creating the InventoryTransaction below. The
                // Observer's created() hook re-derives and persists Item.status
                // synchronously as part of that create() call (same DB
                // transaction/connection), so reading $item->status any later
                // than this — e.g. after refresh() following create() — would
                // already reflect the POST-mutation status, making
                // handleStatusChange()'s wasBelowThreshold/isBelowThreshold
                // comparison always compare a value against itself and never
                // notify. See TASK 49 report.
                $previousStatus = $item->status;

                if ((int) ($line->item_id ?? 0) !== (int) $item->id) {
                    DB::table('purchase_receipt_items')
                        ->where('id', $line->id)
                        ->update([
                            'item_id' => $item->id,
                            'updated_at' => now(),
                        ]);
                }

                InventoryTransaction::query()->create([
                    'item_id' => $item->id,
                    'report_id' => null,
                    'room_id' => null,
                    'transaction_type' => 'adjustment',
                    'quantity' => (int) $line->quantity_received,
                    'reference_note' => 'Purchase receipt OR#' . $receipt->or_number,
                    'performed_by' => $performedBy ?: null,
                ]);

                // The Observer already derived and saved the post-mutation
                // status as part of the create() call above; refresh() here
                // just pulls that value back into this in-memory model and the
                // redundant derive+save below keeps this loop resilient if
                // that invariant ever changes.
                $item->refresh();
                $item->status = InventoryStatusService::deriveStatus(
                    (int) $item->quantity,
                    (int) ($item->reorder_level ?? 0)
                );
                $item->save();

                // TASK 18 — fires only on a genuine NORMAL -> LOW/OUT_OF_STOCK transition.
                $this->inventoryLowStockNotifier->handleStatusChange(
                    $item->id,
                    $item->name,
                    $previousStatus,
                    $item->status
                );
            }

            DB::table('purchase_receipts')
                ->where('id', $receiptId)
                ->update([
                    'status' => 'posted',
                    'updated_at' => now(),
                ]);

            DB::table('activity_logs')->insert([
                'user_id' => $performedBy ?: null,
                // TASK: IT Expert activity-log audit — filled in 'module' so
                // this row surfaces correctly under the Activity Logs
                // module filter, matching every other write in the app.
                // 'action' is left as the pre-existing lowercase
                // 'purchase_posted' (not renamed to POST_PURCHASE_RECEIPT)
                // to avoid silently changing a value historical rows and any
                // external consumer may already rely on.
                'action' => 'purchase_posted',
                'module' => 'inventory',
                'entity_type' => 'purchase_receipt',
                'entity_id' => $receiptId,
                'details' => 'Posted purchase receipt OR#' . $receipt->or_number
                    . ' - ' . $lineItems->count() . ' line item(s)',
                'ip_address' => $ipAddress,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // TASK 18 — "Purchase Receipt Posted" notification.
            $this->notifySuperAdmins($receiptId, (string) $receipt->or_number, $lineItems->count(), $performedBy);
        });
    }

    /**
     * TASK 18 — notifies all active Super Admins that a purchase receipt was
     * posted (per user's adjustment: Super Admin only, no Inventory Admin
     * role invented). Excludes the user who performed the posting, if any.
     */
    private function notifySuperAdmins(int $receiptId, string $orNumber, int $lineItemCount, ?int $performedBy): void
    {
        $title = 'Purchase Receipt Posted: OR#' . $orNumber;
        $message = 'Purchase receipt OR#' . $orNumber . ' was posted with '
            . $lineItemCount . ' line item(s).';

        $recipientRoles = RoleNormalizerService::rawValuesFor(['super_admin']);
        $recipientIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', $recipientRoles)
            ->when($performedBy, fn ($query) => $query->where('user_id', '!=', $performedBy))
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            $this->notificationService->notify((int) $recipientId, $title, $message, 'purchase_receipt', $receiptId);
        }
    }

    private function resolveInventoryItem(object $line): Item
    {
        $lineItemId = $line->item_id !== null ? (int) $line->item_id : null;

        if ($lineItemId !== null) {
            $existing = Item::query()->lockForUpdate()->find($lineItemId);
            if ($existing) {
                return $existing;
            }
        }

        // Inventory is a single centralized stock pool, so a stock item's
        // identity is its name alone.
        $matched = Item::query()
            ->lockForUpdate()
            ->where('item_type', 'inventory_stock')
            ->whereRaw('LOWER(name) = LOWER(?)', [(string) $line->item_name])
            ->first();

        if ($matched) {
            return $matched;
        }

        return Item::query()->create([
            'room_id' => null,
            'item_type' => 'inventory_stock',
            'name' => (string) $line->item_name,
            'status' => InventoryStatusService::deriveStatus(0, 0),
            'quantity' => 0,
            'reserved_quantity' => 0,
            'reorder_level' => 0,
            'category_id' => $line->category_id !== null ? (int) $line->category_id : null,
            'unit_type' => (string) ($line->unit ?? 'pc'),
        ]);
    }
}
