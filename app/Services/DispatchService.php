<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        // TASK 52 — notify once per genuine NORMAL -> LOW/OUT_OF_STOCK item
        // status transition caused by releasing this dispatch's 'deploy'
        // transactions. Mirrors the previousStatus-before/
        // handleStatusChange-after pattern Task 49 already established in
        // InventoryAdjustmentService::adjust() and
        // PurchaseReceiptPostingService::postReceipt() — releaseDispatch()
        // was the one remaining ledger-mutating flow missing it, because it
        // relies entirely on InventoryTransactionObserver::created() for the
        // status mutation and never previously captured a "before" value.
        private readonly InventoryLowStockNotifier $inventoryLowStockNotifier,
        // Deployed Asset registry — per-unit asset codes for dispatch lines
        // released with "Track as Assets" enabled (see releaseDispatch()).
        private readonly AssetRegistryService $assetRegistryService
    ) {
    }

    private function notifyDispatchLinkedParty(Dispatch $dispatch, string $title, string $message): void
    {
        // TASK 13 (Repair retirement) — the first branch here preferred the
        // repair request's assigned technician over the damage reporter. It is
        // removed along with the RepairRequest model it dereferenced.
        //
        // No dispatch loses its notification as a result: the only dispatches
        // that ever carried a repair_request_id were the ones
        // RepairService::fulfillReplacement() created, and it always set
        // damage_report_id on the same row, so the damage branch below was
        // already a complete fallback for exactly that population.
        if ($dispatch->damage_report_id) {
            $reporterId = (int) ($dispatch->damageReport?->reported_by ?? 0);
            if ($reporterId > 0) {
                $this->notificationService->notify($reporterId, $title, $message, 'dispatch', $dispatch->id);
            }
        }
    }

    /**
     * TASK 41 — Administrator Create Dispatch Without Approval.
     *
     * $requiresApproval defaults to TRUE so every pre-existing caller keeps the
     * unchanged Head Maintenance behaviour (created 'pending', awaiting an
     * Administrator). TASK 13 — the two-argument caller this sentence used to
     * name, RepairService::fulfillReplacement(), has been retired; the default
     * is kept because it is the correct behaviour for the workflow, not
     * because one caller relied on it.
     *
     * When FALSE (only ever passed for a super_admin creator — the decision is
     * made by DispatchAuthorizationService::creationRequiresApproval(), never
     * here and never from client input) the dispatch is created directly in
     * 'approved', which is the EXISTING enum value meaning "releasable". No new
     * status was invented.
     *
     * TASK 57 — approved_by / approved_at ARE now written for this workflow,
     * set to the creating Administrator and the creation timestamp. Earlier
     * design deliberately left them NULL to avoid claiming an approval event
     * that never happened; per product decision, an Administrator-created
     * dispatch is now recorded as self-approved at creation, so the UI never
     * shows a blank "Approved By" for a perfectly legitimate, approval-exempt
     * dispatch. The distinction from Workflow A is preserved elsewhere: the
     * activity log's `approval_required: false` meta (below) and the fact
     * that the dispatch was written directly to 'approved' without ever
     * passing through approveDispatch() are what still mark this as "no
     * approval step occurred", not "an approval was skipped and hidden".
     */
    public function createDispatch(array $data, ?int $actorUserId = null, bool $requiresApproval = true): Dispatch
    {
        $dispatchCode = 'DSP-' . strtoupper(uniqid());

        return DB::transaction(function () use ($data, $dispatchCode, $actorUserId, $requiresApproval): Dispatch {
            // Multi-Department Selection: if multiple departments are provided,
            // store the first one. The dispatch table has a single department_id
            // column for reporting; full multi-department tracking could use a
            // junction table in a future migration if needed.
            $primaryDepartmentId = null;
            if (!empty($data['department_ids']) && is_array($data['department_ids'])) {
                $primaryDepartmentId = (int) $data['department_ids'][0];
            } elseif (!empty($data['department_id'])) {
                $primaryDepartmentId = (int) $data['department_id'];
            }

            $dispatch = Dispatch::query()->create([
                'dispatch_code'       => $dispatchCode,
                'department_id'       => $primaryDepartmentId,
                // TASK 3 — Dispatch Personnel Audit Trail: records who
                // actually requested this dispatch, distinct from
                // approved_by/released_by. A caller that knows the true
                // requester can pass it explicitly; the public create endpoint
                // defaults to the acting/session user. (TASK 13 — the example
                // named here, RepairService::fulfillReplacement(), has been
                // retired. The pass-through itself is unchanged.)
                'requested_by'        => $data['requested_by']        ?? $actorUserId,
                // Primary/Additional hierarchy removal — release_assigned_to/
                // _by/_at are no longer written for dispatches created after
                // this change. They stay NULL forever on every new dispatch;
                // dispatch_personnel_assignments (written below, once per
                // selected person) is now the sole source of truth for who
                // may release a dispatch. Pre-existing dispatches keep their
                // historical values untouched — these columns are not backed
                // out, only stopped being written going forward.
                'room_id'             => $data['room_id']             ?? null,
                'room_note'           => $data['room_note']           ?? null,
                // TASK 13 (Repair retirement) — the 'repair_request_id'
                // pass-through was removed here. Its only supplier was
                // RepairService::fulfillReplacement(), which this task deletes,
                // and the attribute is no longer fillable on Dispatch. The
                // nullable dispatches.repair_request_id COLUMN is untouched.
                'damage_report_id'    => $data['damage_report_id']    ?? null,
                // SPRINT 3: optional pass-through for the new maintenance_reports
                // link (SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §8). No
                // current caller supplies this key, so this is a no-op today —
                // it only prepares the service for future wiring.
                'report_id'           => $data['report_id']           ?? null,
                'purchase_receipt_id' => $data['purchase_receipt_id'] ?? null,
                // TASK 41 — 'approved' here means "no approval step applies".
                // TASK 57 — approved_by/approved_at ARE now set for this
                // workflow (self-approved at creation); see the method
                // docblock for why this reverses the earlier NULL-only design.
                'status' => $requiresApproval ? 'pending' : 'approved',
                'approved_by' => $requiresApproval ? null : $actorUserId,
                'approved_at' => $requiresApproval ? null : now(),
                'notes'  => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $it) {
                DispatchItem::query()->create([
                    'dispatch_id' => $dispatch->id,
                    'item_id' => $it['item_id'],
                    'quantity' => $it['quantity'],
                ]);
            }

            // Primary/Additional hierarchy removal — every selected person is
            // written into the flat roster at creation time, inside this same
            // transaction. Previously only /add-personnel calls (for
            // "additional" people) wrote this pivot; the primary person was
            // written to release_assigned_to instead and never appeared here
            // at all. Now there is no primary — everyone selected lands in
            // dispatch_personnel_assignments the same way.
            $personnelIds = array_values(array_unique(array_map('intval', $data['personnel_ids'] ?? [])));
            foreach ($personnelIds as $personnelId) {
                \App\Models\DispatchPersonnelAssignment::query()->create([
                    'dispatch_id' => $dispatch->id,
                    'personnel_user_id' => $personnelId,
                    'assigned_by' => $actorUserId,
                    'assigned_at' => now(),
                ]);
            }

            // A dispatch that skips approval is "approved" from the start, so
            // it gets the same stock check approveDispatch() applies (the
            // whole transaction rolls back if stock is short).
            if (!$requiresApproval) {
                $this->assertStockCoversDispatch($dispatch);
            }

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'CREATE_DISPATCH',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $dispatch->id,
                    'details' => 'Created dispatch ' . $dispatchCode . '.',
                    'meta' => [
                        'dispatch_code' => $dispatchCode,
                        'items_count' => count($data['items']),
                        // Primary/Additional hierarchy removal — the audit
                        // trail now shows the whole flat roster assigned at
                        // creation time, not a single primary person.
                        'personnel_ids' => $personnelIds,
                        // TASK 41 — the audit trail must record that this
                        // dispatch legitimately skipped approval, since
                        // approved_by stays NULL and would otherwise look like
                        // missing data rather than a deliberate workflow.
                        'approval_required' => $requiresApproval,
                        'created_status' => $dispatch->status,
                    ],
                ]);
            }

            // TASK 41 — in the Head Maintenance workflow the assigned staff
            // member is told their work is actionable by approveDispatch()'s
            // 'Dispatch Ready For Release' notification. An Administrator-
            // created dispatch never passes through that method, so without
            // this the assignee would never be told the dispatch exists.
            //
            // The SAME title and message text is reused rather than inventing
            // a second wording, and it cannot double-fire: approveDispatch()
            // only accepts a 'pending' dispatch, and this one is already
            // 'approved'.
            if (!$requiresApproval) {
                foreach ($personnelIds as $personnelId) {
                    $this->notificationService->notify(
                        $personnelId,
                        'Dispatch Ready For Release',
                        'Dispatch ' . $dispatchCode . ' has been created and is ready for you to release.',
                        'dispatch',
                        $dispatch->id
                    );
                }
            }

            return $dispatch->load('items.item');
        });
    }

    /**
     * TASK 1 — Dispatch Inventory Automation (superseded in part by TASK 13).
     *
     * TASK 13 — Dispatch Release Assignment Workflow.
     *
     * Approval is now a DECISION ONLY. It records who approved and when, and
     * moves the dispatch pending -> approved. It deliberately performs NO
     * inventory movement: under the revised business process the physical
     * hand-off is done later by the assigned Maintenance Staff, and deducting
     * stock here would mean the ledger claimed items had left inventory
     * before anyone had actually handed them over.
     *
     * Task 1's stock validation, its InventoryTransaction('deploy') creation,
     * and its duplicate-release guard have all MOVED to releaseDispatch()
     * below — they were not deleted and not duplicated. releaseDispatch() is
     * now the single place inventory is deducted for a dispatch, which is the
     * same "one deduction path" property Task 1 established, just anchored to
     * the release step instead of the approval step.
     *
     * KNOWN BEHAVIOUR CHANGE: insufficient stock no longer blocks approval,
     * it blocks release. An Administrator can now approve a dispatch the
     * inventory cannot currently fulfil, and the assigned staff member is the one
     * who hits the "Insufficient Inventory" error later. That is an inherent
     * consequence of separating the decision from the hand-off, and is called
     * out in the Task 13 report rather than hidden behind a silent guess about
     * which behaviour was wanted.
     *
     * TASK 3 — Dispatch Personnel Audit Trail is preserved: `released_by`
     * still means "who actually released", and it is precisely because of that
     * rule that approval no longer writes it. It stays null until
     * releaseDispatch() records the real releaser.
     */
    public function approveDispatch(
        Dispatch $dispatch,
        int $approvedBy,
        ?int $actorUserId = null
    ): Dispatch {
        return DB::transaction(function () use ($dispatch, $approvedBy, $actorUserId): Dispatch {
            // Lock the dispatch row for the life of this transaction. A
            // second "Approve" click (accidental double submit) blocks here
            // until the first request commits, then re-reads the row and
            // finds status is no longer 'pending'.
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending dispatches can be approved.',
                ]);
            }

            // TASK 13 — a dispatch with an empty personnel roster could never
            // be released by anyone (canReleaseDispatch() requires roster
            // membership), so approving it would create a permanently stuck
            // record. Refuse early with an actionable message. Primary/
            // Additional hierarchy removal — this now checks the flat
            // dispatch_personnel_assignments roster instead of a single
            // release_assigned_to column.
            if ($locked->personnelAssignments()->doesntExist()) {
                throw ValidationException::withMessages([
                    'personnel_ids' => 'This dispatch has no release personnel and cannot be approved.',
                ]);
            }

            // 2026-09-27 — "check stock at approval, deduct at hand-off".
            // Approval still moves no inventory (releaseDispatch() remains the
            // single deduction path and re-checks stock), but a dispatch the
            // inventory cannot fulfil right now is refused here, instead of
            // the assigned staff member discovering it at release time.
            $this->assertStockCoversDispatch($locked);

            $locked->update([
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'status' => 'approved',
            ]);

            // Primary/Additional hierarchy removal — the roster is a flat
            // list of equals, so the audit trail and notification below cover
            // everyone on it rather than a single "the assignee" name.
            $roster = $locked->personnelAssignments()->with('personnel')->get();
            $rosterNames = $roster->pluck('personnel.full_name')->filter()->values()->all();
            $rosterIds = $roster->pluck('personnel_user_id')->map(fn ($id) => (int) $id)->values()->all();
            $assigneeSummary = $rosterNames !== [] ? implode(', ', $rosterNames) : 'the assigned personnel';

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'APPROVE_DISPATCH',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $locked->id,
                    'details' => 'Administrator approved Dispatch ' . $locked->dispatch_code . '. Awaiting release by ' . $assigneeSummary . '.',
                    'meta' => [
                        'dispatch_code' => $locked->dispatch_code,
                        'personnel_ids' => $rosterIds,
                        'personnel_names' => $rosterNames,
                    ],
                ]);
            }

            // TASK 13 §8 — every Release Personnel on the roster is notified
            // when the dispatch becomes Approved, because that is the moment
            // their work becomes actionable. Primary/Additional hierarchy
            // removal — previously only the single release_assigned_to person
            // was notified; now every roster member is, since none of them is
            // "more primary" than another.
            foreach ($rosterIds as $rosterUserId) {
                $this->notificationService->notify(
                    $rosterUserId,
                    'Dispatch Ready For Release',
                    'Dispatch ' . $locked->dispatch_code . ' has been approved and is ready for you to release.',
                    'dispatch',
                    $locked->id
                );
            }

            $this->notifyDispatchLinkedParty(
                $locked,
                'Dispatch Approved',
                'Dispatch ' . $locked->dispatch_code . ' has been approved and is awaiting release.'
            );

            return $locked->fresh('items.item');
        });
    }

    /**
     * TASK 13 — Administrator rejection.
     *
     * Rejection is a distinct *decision* from cancellation (which Head
     * Maintenance may also perform on its own request), so it gets its own
     * activity-log action and message. The resulting state is 'cancelled'
     * because that is the only terminal non-released status this schema has —
     * no enum change, and cancelDispatch()'s own behaviour is untouched.
     */
    /**
     * Read-only stock check used at approval: same available-stock formula
     * (quantity - reserved_quantity), same per-item aggregation, and the same
     * message format as releaseDispatch()'s check.
     */
    private function assertStockCoversDispatch(Dispatch $dispatch): void
    {
        $requestedByItemId = [];
        $itemsById = [];
        foreach ($dispatch->items()->with('item')->get() as $di) {
            $requestedByItemId[$di->item_id] = ($requestedByItemId[$di->item_id] ?? 0) + (int) $di->quantity;
            $itemsById[$di->item_id] = $di->item;
        }

        foreach ($requestedByItemId as $itemId => $requested) {
            $item = $itemsById[$itemId] ?? null;
            if (!$item) {
                throw ValidationException::withMessages(['items' => 'Item not found for dispatch item.']);
            }

            $available = (int) $item->quantity - (int) $item->reserved_quantity;
            if ($requested > $available) {
                throw ValidationException::withMessages([
                    'items' => self::formatInsufficientStockMessage($item->name, $available, $requested),
                ]);
            }
        }
    }

    /**
     * Plain-language stock-shortfall message shared by every stock-check
     * call site (create-without-approval, approve, release). Two distinct
     * sentences rather than one "Insufficient Inventory..." template for
     * every case — a fully out-of-stock item ("Available Stock: 0") reads
     * very differently to a non-technical user than one that just doesn't
     * have enough left for this particular request, so each gets its own
     * wording. Both still name the exact item and, for the "not enough"
     * case, the real numbers — nothing about the underlying stock check
     * changed, only how its result is phrased.
     */
    private static function formatInsufficientStockMessage(string $itemName, int $available, int $requested): string
    {
        if ($available <= 0) {
            return sprintf('%s is out of stock. You cannot dispatch this item right now.', $itemName);
        }

        return sprintf('Not enough stock for %s. Only %d available, but %d requested.', $itemName, $available, $requested);
    }

    public function rejectDispatch(Dispatch $dispatch, string $reason, ?int $actorUserId = null): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $reason, $actorUserId): Dispatch {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending dispatches can be rejected.',
                ]);
            }

            $locked->update([
                'status' => 'cancelled',
                'notes' => trim((string) $locked->notes . "\nRejected: " . $reason),
            ]);

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'REJECT_DISPATCH',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $locked->id,
                    'details' => 'Administrator rejected Dispatch ' . $locked->dispatch_code . '. Reason: ' . $reason,
                    'meta' => [
                        'dispatch_code' => $locked->dispatch_code,
                        'reason' => $reason,
                    ],
                ]);
            }

            if ($locked->requested_by) {
                $this->notificationService->notify(
                    (int) $locked->requested_by,
                    'Dispatch Rejected',
                    'Dispatch ' . $locked->dispatch_code . ' was rejected. Reason: ' . $reason,
                    'dispatch',
                    $locked->id
                );
            }

            return $locked->fresh('items.item');
        });
    }

    public function isCancellationEligible(Dispatch $dispatch): bool
    {
        return in_array($dispatch->status, ['pending', 'approved'], true)
            && !InventoryTransaction::query()
                ->where('dispatch_id', $dispatch->id)
                ->where('transaction_type', 'deploy')
                ->exists();
    }

    public function cancelDispatch(Dispatch $dispatch, string $reason, ?int $actorUserId = null): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $reason, $actorUserId): Dispatch {
            // Release, per-item dispatch, and auto-completion lock this same row.
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            if (!$actorUserId || !User::query()->whereKey($actorUserId)
                ->where('role', 'super_admin')->where('status', 'active')->exists()) {
                throw new AuthorizationException('Only the Administrator may cancel dispatches.');
            }
            if (!$this->isCancellationEligible($locked)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending or approved dispatches with no deployment history can be cancelled.',
                ]);
            }

            $fromStatus = $locked->status;
            $locked->update([
                'status' => 'cancelled',
                'notes' => $locked->notes
                    ? $locked->notes . ' | CANCELLED: ' . $reason
                    : 'CANCELLED: ' . $reason,
            ]);

            $audit = $this->activityLogService->logFromSession([
                'user_id'     => $actorUserId,
                'user_role'   => 'super_admin',
                'action'      => 'CANCEL_DISPATCH',
                'module'      => 'dispatch',
                'entity_type' => 'dispatch',
                'entity_id'   => $locked->id,
                'details'     => 'Cancelled dispatch ' . $locked->dispatch_code . '. Reason: ' . $reason,
                'meta'        => ['dispatch_code' => $locked->dispatch_code, 'reason' => $reason, 'from_status' => $fromStatus],
                'dedupe_window_seconds' => 0,
            ]);
            if (!$audit) {
                throw new \RuntimeException('Cancellation audit could not be persisted.');
            }

            return $locked->fresh('items.item');
        });
    }

    /**
     * TASK 13 — Dispatch Release Assignment Workflow.
     *
     * This is now THE inventory-deduction path for a dispatch. Task 1's stock
     * validation, its InventoryTransaction('deploy') pipeline, its
     * duplicate-release guard and its row locking were moved here from
     * approveDispatch() — moved, not copied, so there is still exactly one
     * place a dispatch deducts stock.
     *
     * Compared to the pre-Task-13 version of this method, three things were
     * hardened, because it is no longer a rarely-taken legacy path:
     *   1. the dispatch row is now locked with lockForUpdate() (it previously
     *      had no lock at all, so two concurrent releases could both pass the
     *      status guard);
     *   2. the stock check moved INSIDE the transaction (it previously ran
     *      before DB::transaction() opened, against unlocked rows);
     *   3. the InventoryTransaction duplicate guard from Task 1 was brought
     *      across, so a dispatch that somehow already has 'deploy' rows cannot
     *      be deducted twice even if its status were wrong.
     *
     * The actual quantity / reserved_quantity arithmetic still belongs to
     * InventoryTransactionObserver, exactly as before — no stock math is
     * duplicated in this service.
     *
     * Deployed Asset registry — $assets is the optional "Track as Assets"
     * payload, keyed by dispatch_item_id, one entry per unit (an existing
     * official code, or blank to auto-generate an SFMS code). It is validated
     * against this dispatch's own lines BEFORE any stock moves, and the
     * deployed_assets rows are written in this same transaction AFTER the
     * deploy transactions, so an invalid or duplicate code rolls the entire
     * release back (no stock deducted, status unchanged). An empty array is
     * the untracked release, identical to the behaviour before this feature.
     *
     * @param  array<int|string, list<string|null>>  $assets
     * @return array{dispatch: Dispatch, transactions: Collection<int, InventoryTransaction>, assets: Collection<int, \App\Models\DeployedAsset>}
     */
    public function releaseDispatch(
        Dispatch $dispatch,
        int $releasedBy,
        ?int $receiverUserId = null,
        ?int $actorUserId = null,
        ?string $releaseRemarks = null,
        array $assets = []
    ): array {
        return DB::transaction(function () use ($dispatch, $releasedBy, $receiverUserId, $actorUserId, $releaseRemarks, $assets): array {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => 'Only approved dispatches can be released.',
                ]);
            }

            // Belt-and-suspenders duplicate protection carried over from
            // Task 1: independently confirm no 'deploy' transaction already
            // exists for this dispatch. The status guard above should make
            // this impossible, but the dispatch_id FK on
            // inventory_transactions makes it cheap to verify rather than
            // assume.
            $alreadyReleased = InventoryTransaction::query()
                ->where('dispatch_id', $locked->id)
                ->where('transaction_type', 'deploy')
                ->exists();
            if ($alreadyReleased) {
                throw ValidationException::withMessages([
                    'status' => 'Inventory for this dispatch has already been released.',
                ]);
            }

            $items = $locked->items()->with('item')->get();

            // TASK 48: dispatches created before Task 47's `distinct` item_id
            // validation could contain multiple dispatch_items rows for the
            // same item_id. Aggregate requested quantity per item_id so the
            // stock check below compares against the item's true total
            // demand rather than each row in isolation — otherwise two rows
            // that individually pass the check can still overdraw the item,
            // which previously surfaced as an unhandled RuntimeException from
            // InventoryTransactionObserver::creating() (HTTP 500) instead of
            // this clean validation error.
            $requestedByItemId = [];
            foreach ($items as $di) {
                $requestedByItemId[$di->item_id] = ($requestedByItemId[$di->item_id] ?? 0) + (int) $di->quantity;
            }

            // STOCK VALIDATION — must happen before any InventoryTransaction
            // is created, so an insufficient request makes zero inventory
            // changes. Same available-stock formula (quantity -
            // reserved_quantity) and same message format Task 1 used at
            // approval time, so the error text users already recognise is
            // unchanged — only the step it appears at has moved.
            // TASK 52 — captured BEFORE any InventoryTransaction is created
            // below, per distinct item_id, so the later handleStatusChange()
            // call compares a genuine pre-mutation status rather than a
            // value the Observer has already overwritten (see the identical
            // note in InventoryAdjustmentService::adjust()).
            $previousStatusByItemId = [];

            $checkedItemIds = [];
            foreach ($items as $di) {
                $item = $di->item;
                if (!$item) {
                    throw ValidationException::withMessages([
                        'items' => 'Item not found for dispatch item.',
                    ]);
                }

                if (isset($checkedItemIds[$di->item_id])) {
                    continue;
                }
                $checkedItemIds[$di->item_id] = true;
                $previousStatusByItemId[$di->item_id] = $item->status;

                $available = (int) $item->quantity - (int) $item->reserved_quantity;
                $requested = $requestedByItemId[$di->item_id];
                if ($requested > $available) {
                    throw ValidationException::withMessages([
                        'items' => self::formatInsufficientStockMessage($item->name, $available, $requested),
                    ]);
                }
            }

            // Deployed Asset registry — validated here, after the stock check
            // and before the first InventoryTransaction, so a bad asset
            // payload makes zero inventory changes.
            $assetPlan = $this->assetRegistryService->buildReleasePlan($items, $assets);

            $releasedToName = User::query()->find($releasedBy)?->full_name ?? 'Unknown personnel';

            $transactions = collect();
            foreach ($items as $di) {
                $transactions->push(InventoryTransaction::query()->create([
                    'item_id' => $di->item_id,
                    // SPRINT 3: report_id passes through from the dispatch
                    // (SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §4.4).
                    'report_id' => $locked->report_id,
                    // SPRINT 3: dispatch_id closes the traceability gap flagged
                    // in SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §2/§5.
                    'dispatch_id' => $locked->id,
                    'room_id' => $locked->room_id,
                    'transaction_type' => 'deploy',
                    'quantity' => $di->quantity,
                    'reference_note' => 'Released via Dispatch ' . $locked->dispatch_code . ' - Released By: ' . $releasedToName,
                    // TASK 13: the releasing staff member, not the approving
                    // Administrator. performed_by is the ledger's record of
                    // who physically moved the stock.
                    'performed_by' => $releasedBy,
                ]));
            }

            // Deployed Asset registry — one row per tracked unit, inside this
            // transaction. Registering is attributed to the releasing staff
            // member, the same person recorded as performed_by above.
            $registeredAssets = $this->assetRegistryService->registerReleasePlan($locked, $assetPlan, $releasedBy);

            // TASK 52 — fires only on a genuine NORMAL -> LOW/OUT_OF_STOCK
            // transition, once per distinct item touched by this release
            // (a dispatch can carry several dispatch_items rows for the same
            // item_id — see Task 48's aggregation above). Each item's fresh
            // post-mutation status is re-read here because
            // InventoryTransactionObserver::created() already derived and
            // saved it synchronously as part of the create() calls above.
            foreach (array_keys($previousStatusByItemId) as $itemId) {
                $freshItem = Item::query()->find($itemId);
                if (!$freshItem) {
                    continue;
                }

                $this->inventoryLowStockNotifier->handleStatusChange(
                    (int) $itemId,
                    $freshItem->name,
                    $previousStatusByItemId[$itemId],
                    $freshItem->status
                );
            }

            $locked->update([
                'released_by' => $releasedBy,
                'receiver_user_id' => $receiverUserId ?? $locked->receiver_user_id,
                // TASK 13: remarks are now written by the person performing the
                // hand-off rather than by the Administrator at approval time.
                'release_remarks' => $releaseRemarks ?? $locked->release_remarks,
                'status' => 'released',
            ]);

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'RELEASE_DISPATCH',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $locked->id,
                    'details' => 'Released dispatch ' . $locked->dispatch_code . ' by ' . $releasedToName . '.',
                    'meta' => [
                        'dispatch_code' => $locked->dispatch_code,
                        'released_by' => $releasedBy,
                        'released_by_name' => $releasedToName,
                        'receiver_user_id' => $receiverUserId,
                        'release_remarks' => $releaseRemarks,
                        'transaction_count' => $transactions->count(),
                        'transaction_ids' => $transactions->pluck('id')->all(),
                        'tracked_asset_count' => $registeredAssets->count(),
                        'asset_codes' => $registeredAssets->pluck('asset_code')->all(),
                    ],
                ]);
            }

            // TASK 13 §8 — Head Maintenance is notified after the release.
            // requested_by is the Head who created the dispatch under the new
            // workflow. release_assigned_by is permanently NULL on every
            // dispatch created after the Primary/Additional hierarchy removal
            // (nothing writes it anymore), so this fallback only ever
            // resolves through requested_by for those dispatches — it is kept
            // here, unchanged, purely so a legacy dispatch that still carries
            // a release_assigned_by value keeps notifying that same person.
            $headToNotify = (int) ($locked->release_assigned_by ?? $locked->requested_by ?? 0);
            if ($headToNotify > 0 && $headToNotify !== $releasedBy) {
                $this->notificationService->notify(
                    $headToNotify,
                    'Dispatch Released',
                    'Dispatch ' . $locked->dispatch_code . ' has been released by ' . $releasedToName . '.',
                    'dispatch',
                    $locked->id
                );
            }

            if ($receiverUserId) {
                $this->notificationService->notify(
                    $receiverUserId,
                    'Dispatch Released',
                    'Dispatch ' . $locked->dispatch_code . ' has been released and items are ready.',
                    'dispatch',
                    $locked->id
                );
            } else {
                $this->notifyDispatchLinkedParty(
                    $locked,
                    'Dispatch Released',
                    'Dispatch ' . $locked->dispatch_code . ' has been released and items are ready.'
                );
            }

            return [
                'dispatch' => $locked->fresh('items.item'),
                'transactions' => $transactions,
                'assets' => $registeredAssets,
            ];
        });
    }

    /**
     * Multi-Personnel Dispatch Support: Assign personnel to a dispatch.
     *
     * Allows multiple maintenance staff from different departments to be
     * assigned to a single dispatch. Each person will only dispatch items
     * relevant to their department.
     *
     * Primary/Additional hierarchy removal — this is now the ONE primitive
     * that adds anyone to a dispatch's roster, used both by createDispatch()
     * (everyone selected at creation) and by the add-personnel endpoint
     * (anyone added afterward). There is no separate "primary" write path
     * anymore — release_assigned_to is never populated going forward.
     */
    public function assignPersonnelToDispatch(
        Dispatch $dispatch,
        int $personnelUserId,
        ?int $assignedBy = null,
        ?int $actorUserId = null
    ): Dispatch {
        return DB::transaction(function () use ($dispatch, $personnelUserId, $assignedBy, $actorUserId): Dispatch {
            // Check if already assigned
            $existing = \App\Models\DispatchPersonnelAssignment::query()
                ->where('dispatch_id', $dispatch->id)
                ->where('personnel_user_id', $personnelUserId)
                ->first();

            if ($existing) {
                return $dispatch->fresh('personnelAssignments.personnel', 'items.item');
            }

            // Create the assignment
            \App\Models\DispatchPersonnelAssignment::query()->create([
                'dispatch_id' => $dispatch->id,
                'personnel_user_id' => $personnelUserId,
                'assigned_by' => $assignedBy ?? $actorUserId,
                'assigned_at' => now(),
            ]);

            $personnelName = User::query()->find($personnelUserId)?->full_name ?? 'Unknown personnel';

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'ASSIGN_DISPATCH_PERSONNEL',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $dispatch->id,
                    'details' => 'Assigned ' . $personnelName . ' to dispatch ' . $dispatch->dispatch_code . '.',
                    'meta' => [
                        'dispatch_code' => $dispatch->dispatch_code,
                        'personnel_user_id' => $personnelUserId,
                        'personnel_name' => $personnelName,
                    ],
                ]);
            }

            // Notify the assigned personnel
            $this->notificationService->notify(
                $personnelUserId,
                'Assigned to Dispatch',
                'You have been assigned to dispatch ' . $dispatch->dispatch_code . '.',
                'dispatch',
                $dispatch->id
            );

            return $dispatch->fresh('personnelAssignments.personnel', 'items.item');
        });
    }

    /**
     * Primary/Additional hierarchy removal — the flat-roster counterpart to
     * assignPersonnelToDispatch(): deletes one person's pivot row.
     *
     * No minimum-roster-size check: per product decision the roster is
     * allowed to go to zero. A zero-personnel dispatch simply becomes
     * unapprovable (see approveDispatch()'s guard) / unreleasable (see
     * DispatchAuthorizationService::canReleaseDispatch()) until someone is
     * added back — removal itself is never blocked by roster size.
     *
     * Authorization (role + status window) is enforced by
     * DispatchAuthorizationService::canRemoveDispatchPersonnel() at the
     * controller boundary — this method re-checks the status under a row
     * lock only, because status is the one input that can change between the
     * controller's check and this write.
     */
    public function removePersonnelFromDispatch(
        Dispatch $dispatch,
        int $personnelUserId,
        ?int $actorUserId = null
    ): Dispatch {
        return DB::transaction(function () use ($dispatch, $personnelUserId, $actorUserId): Dispatch {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->first();

            if (!$locked || !in_array($locked->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Release personnel can only be changed before the dispatch is released.',
                ]);
            }

            $assignment = \App\Models\DispatchPersonnelAssignment::query()
                ->where('dispatch_id', $locked->id)
                ->where('personnel_user_id', $personnelUserId)
                ->first();

            if (!$assignment) {
                return $locked->fresh('personnelAssignments.personnel', 'items.item');
            }

            $personnelName = User::query()->find($personnelUserId)?->full_name ?? 'Unknown personnel';

            $assignment->delete();

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'REMOVE_DISPATCH_PERSONNEL',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $locked->id,
                    'details' => 'Removed ' . $personnelName . ' from dispatch ' . $locked->dispatch_code . '.',
                    'meta' => [
                        'dispatch_code' => $locked->dispatch_code,
                        'personnel_user_id' => $personnelUserId,
                        'personnel_name' => $personnelName,
                    ],
                ]);
            }

            return $locked->fresh('personnelAssignments.personnel', 'items.item');
        });
    }

    /**
     * Multi-Personnel Dispatch Support: Mark an item as dispatched by personnel.
     *
     * When a person from a specific department dispatches an item, record who
     * dispatched it and when. Multiple items in the same dispatch can be
     * dispatched by different people.
     */
    public function dispatchItem(
        DispatchItem $item,
        int $dispatchedBy,
        ?int $actorUserId = null
    ): DispatchItem {
        return DB::transaction(function () use ($item, $dispatchedBy, $actorUserId): DispatchItem {
            // Check dispatch status - should be approved or released
            $dispatch = Dispatch::query()->whereKey($item->dispatch_id)->lockForUpdate()->first();

            if (!$dispatch || !in_array($dispatch->status, ['approved', 'released'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Item can only be dispatched if the dispatch is approved or released.',
                ]);
            }

            // Update the item with who dispatched it and when
            $item->update([
                'dispatched_by' => $dispatchedBy,
                'dispatched_at' => now(),
            ]);

            $dispatchedByName = User::query()->find($dispatchedBy)?->full_name ?? 'Unknown personnel';
            $itemName = $item->item?->name ?? 'Unknown item';

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'DISPATCH_ITEM',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch_item',
                    'entity_id' => $item->id,
                    'details' => $dispatchedByName . ' dispatched ' . $itemName . ' (quantity: ' . $item->quantity . ') from dispatch ' . $dispatch->dispatch_code . '.',
                    'meta' => [
                        'dispatch_code' => $dispatch->dispatch_code,
                        'dispatch_id' => $dispatch->id,
                        'item_name' => $itemName,
                        'quantity' => $item->quantity,
                        'dispatched_by' => $dispatchedBy,
                        'dispatched_by_name' => $dispatchedByName,
                    ],
                ]);
            }

            return $item->fresh('dispatch', 'item', 'dispatchedByUser');
        });
    }

    /**
     * Multi-Personnel Dispatch Support: Check if all items in a dispatch are dispatched.
     *
     * Returns true if every item in the dispatch has been marked as dispatched
     * (has a dispatched_by value). Used to determine if a dispatch is complete.
     */
    public function isDispatchComplete(Dispatch $dispatch): bool
    {
        $totalItems = $dispatch->items()->count();
        $dispatchedItems = $dispatch->items()
            ->whereNotNull('dispatched_by')
            ->count();

        return $totalItems > 0 && $totalItems === $dispatchedItems;
    }

    /**
     * Multi-Personnel Dispatch Support: Mark dispatch as released when all items are dispatched.
     *
     * This is called after an item is dispatched. If all items are now dispatched,
     * the dispatch is released if it's still 'approved'.
     *
     * Deployed Asset registry fix — this used to write status='released'
     * directly, which skipped everything releaseDispatch() does: no 'deploy'
     * InventoryTransaction (so stock was never deducted), no stock check, no
     * release notifications, and it would have bypassed asset registration.
     * It now delegates to releaseDispatch(), so there is exactly one code path
     * that releases a dispatch. Stock is deducted once, by that method's deploy
     * transactions (its duplicate-release guard still applies), and any
     * "Track as Assets" payload is validated and registered there.
     *
     * @param  array<int|string, list<string|null>>  $assets  same shape as releaseDispatch()
     */
    public function completeDispatchIfAllItemsDispatched(
        Dispatch $dispatch,
        ?int $releasedBy = null,
        ?int $actorUserId = null,
        array $assets = []
    ): Dispatch {
        return DB::transaction(function () use ($dispatch, $releasedBy, $actorUserId, $assets): Dispatch {
            $locked = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== 'approved') {
                return $locked ?? $dispatch;
            }

            // Check if all items are dispatched
            if (!$this->isDispatchComplete($locked)) {
                return $locked;
            }

            // releaseDispatch() records who physically released the stock, so
            // it needs a real person. Fall back to the earliest-assigned
            // roster member; with neither, the dispatch stays approved rather
            // than being released without an accountable releaser.
            //
            // Primary/Additional hierarchy removal — release_assigned_to is
            // permanently NULL on every dispatch created after this change,
            // so this can no longer fall back to it. The pivot row with the
            // oldest assigned_at is used instead (same tie-break a "primary"
            // person would have had, since they were always the first one
            // written at creation time).
            if (!$releasedBy) {
                $earliestAssignment = $locked->personnelAssignments()->oldest('assigned_at')->first();
                $releasedBy = $earliestAssignment ? (int) $earliestAssignment->personnel_user_id : null;
            }
            if (!$releasedBy) {
                return $locked;
            }

            $releasedByUser = User::query()->find($releasedBy)?->full_name ?? 'Unknown personnel';

            $this->releaseDispatch($locked, $releasedBy, null, $actorUserId, null, $assets);

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'AUTO_COMPLETE_DISPATCH',
                    'module' => 'dispatch',
                    'entity_type' => 'dispatch',
                    'entity_id' => $locked->id,
                    'details' => 'All items have been dispatched. Dispatch ' . $locked->dispatch_code . ' is now complete.',
                    'meta' => [
                        'dispatch_code' => $locked->dispatch_code,
                        'completed_by' => $releasedByUser,
                    ],
                ]);
            }

            return $locked->fresh('items.item', 'personnelAssignments.personnel');
        });
    }
}
