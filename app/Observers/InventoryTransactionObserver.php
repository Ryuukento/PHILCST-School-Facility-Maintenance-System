<?php

namespace App\Observers;

use App\Exceptions\DuplicateDeploymentException;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Services\ActivityLogService;
use App\Services\InventoryStatusService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

class InventoryTransactionObserver
{
    /**
     * TASK 36 PHASE 5 — Deploy-to-Room duplicate-submission guard window, in
     * seconds. Deliberately its own constant, not a reuse of
     * ActivityLogService's dedupe_window_seconds (that mechanism dedupes an
     * audit-log row after a mutation, unlocked; this one gates the mutation
     * itself, under the lock below — see guardAgainstDuplicateDeployment()).
     * 5s approximates "a human re-submitting after an ambiguous response" per
     * TASK_36_PHASE_4_DEDUPE_WINDOW_DESIGN_ANALYSIS_REPORT.md Section 7.
     * Kept as a single named constant so it is trivial to retune later.
     */
    private const DEPLOY_DEDUPE_WINDOW_SECONDS = 5;

    public function creating(InventoryTransaction $tx): void
    {
        // Validate that this transaction will not create negative stock
        // lockForUpdate() ensures the row is exclusively locked for the duration of the
        // surrounding DB::transaction() (e.g. DispatchService@releaseDispatch), preventing
        // two concurrent requests from both reading the same quantity and both passing.
        $item = Item::lockForUpdate()->find($tx->item_id);
        if (!$item) {
            throw new ModelNotFoundException('Item not found for transaction');
        }

        $qty = (int)$tx->quantity;

        switch ($tx->transaction_type) {
            case 'reserve':
                // reserved cannot exceed available stock
                $available = (int)$item->quantity - (int)$item->reserved_quantity;
                if ($qty > $available) {
                    throw new \RuntimeException('Insufficient available stock to reserve');
                }
                break;
            case 'deploy':
            case 'dispose':
                // deployment/dispose reduces actual quantity
                if ($qty > (int)$item->quantity) {
                    throw new \RuntimeException('Insufficient stock to deploy/dispose');
                }
                // TASK 36 PHASE 5 — duplicate-deployment guard, 'deploy' only
                // (NOT 'dispose', a distinct action). Runs here, after the
                // Item::lockForUpdate() above, deliberately: two requests
                // deploying the SAME item_id always serialize on that lock
                // (it is held for the life of the caller's DB::transaction,
                // e.g. InventoryStockController::deploy()'s
                // DB::beginTransaction()/DB::commit()), so by the time a
                // second request reaches this line, a first request's
                // 'deploy' InventoryTransaction row — if any — has already
                // committed and is visible to the plain, unlocked SELECT
                // below. No independent lock on inventory_transactions is
                // required for correctness; placing this check before the
                // lock above (e.g. as a controller pre-check) would
                // reintroduce the exact TOCTOU race this guard exists to
                // close. See TASK_36_PHASE_4_DEDUPE_WINDOW_DESIGN_ANALYSIS_REPORT.md
                // Section 9 for the full argument.
                if ($tx->transaction_type === 'deploy') {
                    $this->guardAgainstDuplicateDeployment($tx);
                }
                break;
            case 'release':
                if ($qty > (int)$item->reserved_quantity) {
                    throw new \RuntimeException('Cannot release more than reserved quantity');
                }
                break;
            case 'return':
            case 'adjustment':
                // allow, adjustments can be positive or negative but in creating we expect positive; adjustments negative handled elsewhere
                break;
            default:
                break;
        }
    }

    /**
     * TASK 36 PHASE 5 — throws when a 'deploy' transaction with the same
     * (item_id, room_id, quantity, performed_by) signature as $tx was
     * recorded within the last self::DEPLOY_DEDUPE_WINDOW_SECONDS seconds.
     *
     * Signature rationale (TASK_36_PHASE_4 report Sections 4-7):
     *  - item_id + room_id + quantity: "what, how many, where" — the part of
     *    the request that stays identical across an accidental replay
     *    (refresh/retry/second tab) but legitimately differs across two
     *    genuinely distinct deployments (different room, different
     *    quantity, different item).
     *  - performed_by: scopes the guard to "the same actor repeating their
     *    own request", not "any two users who happen to deploy the same
     *    thing" — two different admins independently deploying the same
     *    item/room/quantity within the window is a legitimate coincidence,
     *    not a replay, and must not be blocked.
     *  - Deliberately excludes asset_code (assigned per-unit, after this
     *    check, by createOrUpdateRoomAsset() — a single deployment can
     *    mint several) and dispatch_id (always NULL for this transaction
     *    type; Deploy-to-Room has no dispatch record by design).
     *
     * $tx->id is not yet set at this point (creating() fires pre-insert), so
     * this query can never match $tx against itself.
     */
    private function guardAgainstDuplicateDeployment(InventoryTransaction $tx): void
    {
        $recent = InventoryTransaction::query()
            ->where('item_id', $tx->item_id)
            ->where('room_id', $tx->room_id)
            ->where('quantity', $tx->quantity)
            ->where('performed_by', $tx->performed_by)
            ->where('transaction_type', 'deploy')
            ->where('created_at', '>=', now()->subSeconds(self::DEPLOY_DEDUPE_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if ($recent !== null) {
            throw new DuplicateDeploymentException([
                'inventory_transaction_id' => $recent->id,
                'item_id'                  => $recent->item_id,
                'room_id'                  => $recent->room_id,
                'quantity'                 => $recent->quantity,
                'performed_by'             => $recent->performed_by,
                'deployed_at'              => optional($recent->created_at)->toIso8601String(),
            ]);
        }
    }

    public function created(InventoryTransaction $tx): void
    {
        // Re-acquire the lock so this write is part of the same serialised transaction.
        $item = Item::lockForUpdate()->find($tx->item_id);
        if (!$item) {
            return;
        }

        $qty = (int)$tx->quantity;

        switch ($tx->transaction_type) {
            case 'reserve':
                $item->reserved_quantity = (int)$item->reserved_quantity + $qty;
                break;
            case 'release':
                $item->reserved_quantity = max(0, (int)$item->reserved_quantity - $qty);
                break;
            case 'deploy':
                // if deployed, reduce reserved if exists, and reduce quantity
                $item->reserved_quantity = max(0, (int)$item->reserved_quantity - $qty);
                $item->quantity = max(0, (int)$item->quantity - $qty);
                break;
            case 'return':
                $item->quantity = (int)$item->quantity + $qty;
                break;
            case 'adjustment':
                // Positive qty = stock increase. reference_note prefix 'DEDUCT:' signals a reduction.
                if (str_starts_with((string)($tx->reference_note ?? ''), 'DEDUCT:')) {
                    $item->quantity = max(0, (int)$item->quantity - $qty);
                } else {
                    $item->quantity = (int)$item->quantity + $qty;
                }
                break;
            case 'dispose':
                $item->quantity = max(0, (int)$item->quantity - $qty);
                break;
            default:
                break;
        }

        // "Status is always derived, never set" (ARCHITECTURE.md Section
        // 5.2). This Observer is the sole writer of Item.quantity for every
        // flow that creates an InventoryTransaction directly (Dispatch
        // release, Need Change approval, Damage replacement, etc. — several
        // of which document that they intentionally leave all Item mutation
        // to this Observer), so it must also be the one to re-derive status
        // here — otherwise Item.status silently goes stale relative to the
        // new quantity whenever those flows run.
        $item->status = InventoryStatusService::deriveStatus((int) $item->quantity, (int) ($item->reorder_level ?? 0));

        $item->save();

        app(ActivityLogService::class)->log([
            'user_id' => $tx->performed_by,
            'action' => 'INVENTORY_TRANSACTION',
            'module' => 'inventory',
            'entity_type' => 'item',
            'entity_id' => $item->id,
            'details' => 'Recorded inventory transaction ' . $tx->transaction_type . ' for item ' . $item->name . ' (qty ' . $tx->quantity . ').',
            'meta' => [
                'transaction_id' => $tx->id,
                'transaction_type' => $tx->transaction_type,
                'quantity' => (int) $tx->quantity,
                'reference_note' => $tx->reference_note,
            ],
            'dedupe_window_seconds' => 1,
        ]);

        // Invalidate analytics caches after transactions that change stock
        try {
            Cache::tags(['analytics'])->flush();
        } catch (\Throwable $e) {
            Cache::forget('analytics.inventorySummary:' . md5(json_encode([])));
            Cache::forget('analytics.overview:' . md5(json_encode([])));
            Cache::forget('analytics.lowStock:' . md5(json_encode([])));
            Cache::forget('analytics.inventoryHealth:' . md5(json_encode([])));
        }
    }

    public function deleted(InventoryTransaction $tx): void
    {
        // Rollback created changes conservatively. lockForUpdate() matches the
        // locking strategy used in creating()/created() above, preventing a
        // concurrent mutation from reading a stale quantity while this rollback
        // is in progress.
        $item = Item::lockForUpdate()->find($tx->item_id);
        if (!$item) {
            return;
        }

        $qty = (int)$tx->quantity;
        switch ($tx->transaction_type) {
            case 'reserve':
                $item->reserved_quantity = max(0, (int)$item->reserved_quantity - $qty);
                break;
            case 'release':
                $item->reserved_quantity = (int)$item->reserved_quantity + $qty;
                break;
            case 'deploy':
                $item->reserved_quantity = (int)$item->reserved_quantity + $qty;
                $item->quantity = (int)$item->quantity + $qty;
                break;
            case 'return':
                $item->quantity = max(0, (int)$item->quantity - $qty);
                break;
            case 'adjustment':
                // Reverse the original adjustment: DEDUCT: transactions added stock on rollback.
                if (str_starts_with((string)($tx->reference_note ?? ''), 'DEDUCT:')) {
                    $item->quantity = (int)$item->quantity + $qty;
                } else {
                    $item->quantity = max(0, (int)$item->quantity - $qty);
                }
                break;
            case 'dispose':
                $item->quantity = (int)$item->quantity + $qty;
                break;
            default:
                break;
        }

        // Same rationale as created() above — the rollback path mutates
        // quantity too, so status must be re-derived here as well or it goes
        // stale in the opposite direction (e.g. a deleted deploy transaction
        // restores quantity above the reorder level but status stays
        // 'low_stock').
        $item->status = InventoryStatusService::deriveStatus((int) $item->quantity, (int) ($item->reorder_level ?? 0));

        $item->save();

        try {
            Cache::tags(['analytics'])->flush();
        } catch (\Throwable $e) {
            Cache::forget('analytics.inventorySummary:' . md5(json_encode([])));
            Cache::forget('analytics.overview:' . md5(json_encode([])));
            Cache::forget('analytics.lowStock:' . md5(json_encode([])));
            Cache::forget('analytics.inventoryHealth:' . md5(json_encode([])));
        }
    }
}
