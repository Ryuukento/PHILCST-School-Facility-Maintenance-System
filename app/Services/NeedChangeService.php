<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\MaintenanceReport;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NeedChangeService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        // TASK 18 — "Replacement Approved" notification.
        private readonly NotificationService $notificationService,
        // TASK 52 — notify once on a genuine NORMAL -> LOW/OUT_OF_STOCK
        // transition for the item deducted by this approval. Mirrors the
        // previousStatus-before/handleStatusChange-after pattern Task 49
        // established in InventoryAdjustmentService::adjust() and
        // PurchaseReceiptPostingService::postReceipt() — approve() was
        // another 'deploy'-creating flow missing it.
        private readonly InventoryLowStockNotifier $inventoryLowStockNotifier,
        // TASK 49 — the authorization boundary. Deliberately the SAME service
        // ReportController already consults for every other report write, so
        // this class enforces the rule without owning a copy of it: there is
        // still exactly one implementation of "may this user approve a Need
        // Change", in ReportAuthorizationService.
        private readonly ReportAuthorizationService $reportAuthorizationService
    ) {
    }

    /**
     * TASK 2 — Need Change Inventory Automation.
     *
     * Approves a report's Need Change request: deducts the requested item
     * through the exact same ledger (InventoryTransaction -> Observer) path
     * DispatchService::approveDispatch() uses for Dispatch approval (see
     * TASK1_DISPATCH_INVENTORY_AUTOMATION.md), and stamps
     * need_change_deducted_at — all inside one DB transaction, so approval
     * either succeeds completely or has no effect at all. Idempotent —
     * re-approving an already-deducted report is a no-op, not a second
     * deduction.
     *
     * TASK 49 — takes the session auth-user array rather than a bare approver
     * id. TASK 48 found that this method performed NO authorization of its own
     * and was protected solely by the invariant that its one and only caller
     * checked the role first — an invariant that no test enforced, so the
     * controller's gate could have been deleted without a single failure.
     *
     * Accepting the auth-user array is what gives this method enough context to
     * enforce the rule itself, and it closes a second gap at the same time: the
     * approver id is now DERIVED from the authorized session
     * (assertCanApproveNeedChange() returns it) instead of being supplied as an
     * independent argument, so the user who passed authorization and the user
     * stamped into need_change_approved_by are the same value by construction.
     *
     * The check runs BEFORE DB::transaction() opens: an unauthorized attempt
     * must not acquire a row lock, must not touch inventory, and must leave no
     * trace beyond the refusal itself.
     */
    public function approve(MaintenanceReport $report, array $authUser): MaintenanceReport
    {
        $approvedBy = $this->reportAuthorizationService->assertCanApproveNeedChange($authUser);

        return DB::transaction(function () use ($report, $approvedBy): MaintenanceReport {
            // Lock the report row for the life of this transaction — mirrors
            // DispatchService::approveDispatch()'s primary duplicate guard. A
            // second "Approve" click blocks here until the first request
            // commits, then re-reads the row and finds need_change_deducted_at
            // already set.
            $locked = MaintenanceReport::query()
                ->where('report_id', $report->report_id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new ModelNotFoundException('Maintenance report not found');
            }

            // Duplicate-approval guard: need_change_deducted_at is only ever set
            // once, inside this same locked transaction, so a second concurrent
            // or repeated approval call observes it already set and no-ops here
            // instead of creating a second InventoryTransaction.
            if ($locked->need_change_deducted_at !== null) {
                return $locked;
            }

            if (empty($locked->need_change_item_id)) {
                throw ValidationException::withMessages([
                    'need_change_item_id' => 'This report has no Need Change request to process.',
                ]);
            }

            // Business rule: a Need Change that was already rejected must
            // never be deducted — approval and rejection are mutually
            // exclusive terminal outcomes for the same request.
            if ($locked->need_change_status === 'rejected') {
                throw ValidationException::withMessages([
                    'need_change_status' => 'This Need Change request was rejected and cannot be approved.',
                ]);
            }

            // Belt-and-suspenders duplicate protection (mirrors
            // DispatchService::approveDispatch()'s independent
            // InventoryTransaction-existence check): confirm no 'deploy'
            // transaction already exists for this report, even though the
            // row lock + need_change_deducted_at guard above should already
            // make this impossible.
            $alreadyDeployed = InventoryTransaction::query()
                ->where('report_id', $locked->report_id)
                ->where('transaction_type', 'deploy')
                ->exists();
            if ($alreadyDeployed) {
                throw ValidationException::withMessages([
                    'need_change_status' => 'Inventory for this Need Change request has already been released.',
                ]);
            }

            $quantity = max(1, (int) $locked->need_change_quantity);

            $item = Item::query()->lockForUpdate()->find($locked->need_change_item_id);
            if (!$item) {
                throw ValidationException::withMessages([
                    'need_change_item_id' => 'Need Change item was not found in inventory.',
                ]);
            }

            // TASK 42 — only warehouse stock can be consumed as a replacement.
            // A 'room_asset' row is one physical unit already installed in a
            // room, not supply: approving it would deploy a keyboard that is
            // already screwed into Room 102, and would decrement that room's
            // physical count as though it were stock on a shelf. The picker is
            // filtered to inventory_stock in the UI, but the rule is enforced
            // here so it holds for any caller, not just the browser.
            if ($item->item_type !== 'inventory_stock') {
                throw ValidationException::withMessages([
                    'need_change_item_id' => sprintf(
                        '%s is a room asset, not inventory stock, and cannot be used as a replacement item.',
                        $item->name
                    ),
                ]);
            }

            // STOCK VALIDATION — reuses the exact available-stock formula
            // (quantity - reserved_quantity) DispatchService::approveDispatch()
            // already uses, so "Available Stock" means the same thing across
            // both approval paths and correctly accounts for stock already
            // reserved elsewhere (e.g. by a pending Dispatch).
            $available = (int) $item->quantity - (int) $item->reserved_quantity;
            if ($quantity > $available) {
                throw ValidationException::withMessages([
                    'need_change_quantity' => sprintf(
                        'Insufficient Inventory for %s. Available Stock: %d. Requested: %d.',
                        $item->name,
                        $available,
                        $quantity
                    ),
                ]);
            }

            // TASK 52 — captured BEFORE creating the InventoryTransaction
            // below, for the same reason documented in
            // InventoryAdjustmentService::adjust(): the Observer's created()
            // hook re-derives and saves Item.status synchronously as part of
            // that create() call, so capturing this any later would already
            // reflect the post-mutation status.
            $previousStatus = $item->status;

            // 'deploy' — the requested item physically leaves inventory to be
            // used as the replacement part for this report, mirroring the
            // Dispatch release path. The Observer performs and audits the
            // actual quantity mutation; this Service never touches Item
            // directly (see ARCHITECTURE.md Section 5 - single writer principle).
            $transaction = InventoryTransaction::query()->create([
                'item_id' => $item->id,
                'report_id' => $locked->report_id,
                'room_id' => null,
                'transaction_type' => 'deploy',
                'quantity' => $quantity,
                'reference_note' => 'Automatically Released via Need Change Approval - Report #' . $locked->report_id,
                'performed_by' => $approvedBy,
            ]);

            $now = now();
            $locked->update([
                'need_change_status' => 'deducted',
                'need_change_approved_by' => $approvedBy,
                'need_change_approved_at' => $now,
                'need_change_deducted_at' => $now,
            ]);

            // TASK 52 — fires only on a genuine NORMAL -> LOW/OUT_OF_STOCK
            // transition. $item->refresh() pulls back the status the
            // Observer already derived and saved as part of the
            // InventoryTransaction::create() call above.
            $item->refresh();
            $this->inventoryLowStockNotifier->handleStatusChange(
                (int) $item->id,
                $item->name,
                $previousStatus,
                $item->status
            );

            $this->activityLogService->logFromSession([
                'user_id' => $approvedBy,
                'action' => 'APPROVE_NEED_CHANGE',
                'module' => 'report',
                'entity_type' => 'report',
                'entity_id' => $locked->report_id,
                'details' => 'Administrator approved Need Change for Maintenance Report #' . $locked->report_id
                    . '. Replacement inventory automatically released.',
                'meta' => [
                    'need_change_item_id' => $item->id,
                    'quantity' => $quantity,
                    'transaction_id' => $transaction->id,
                ],
            ]);

            // TASK 18 — "Replacement Approved" notification.
            $this->notifyReplacementApproved($locked, $item->name, $quantity, $approvedBy);

            return $locked;
        });
    }

    /**
     * TASK 18 — notifies the report's creator and assignee (if any), plus all
     * active Super Admins, that the Need Change replacement was approved and
     * inventory released. Reuses entity_type 'report' (Task 17 architecture)
     * so the notification deep-links to the same report page. Excludes the
     * approving user from the recipient list to avoid self-notification.
     */
    private function notifyReplacementApproved(MaintenanceReport $locked, string $itemName, int $quantity, int $approvedBy): void
    {
        $title = 'Replacement Approved — Report #' . $locked->report_id;
        $message = 'Your Need Change request for "' . $itemName . '" (qty: ' . $quantity
            . ') on Report #' . $locked->report_id . ' has been approved and released from inventory.';

        $recipientIds = collect([$locked->created_by, $locked->assigned_to])
            ->filter(fn ($id) => !empty($id))
            ->map(fn ($id) => (int) $id);

        $superAdminRoles = RoleNormalizerService::rawValuesFor(['super_admin']);
        $superAdminIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', $superAdminRoles)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        $recipientIds = $recipientIds->merge($superAdminIds)
            ->unique()
            ->reject(fn (int $id) => $id === (int) $approvedBy);

        foreach ($recipientIds as $recipientId) {
            $this->notificationService->notify($recipientId, $title, $message, 'report', $locked->report_id);
        }
    }
}
