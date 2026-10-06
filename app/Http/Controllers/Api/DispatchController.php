<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateDeploymentException;
use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Services\ActivityLogService;
use App\Services\DispatchAuthorizationService;
use App\Services\DispatchService;
use App\Services\PersonnelDirectoryService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly DispatchService $dispatchService,
        // TASK 13 — Dispatch Release Assignment Workflow. All role/department/
        // assignment checks live in this one service so no controller method
        // re-derives them.
        private readonly DispatchAuthorizationService $dispatchAuthorizationService,
        // TASK 13 — reused (not duplicated) for the release-personnel
        // searchable selector; see releasePersonnel() below.
        // TASK 65 — this was RepairService until the generic personnel lookup
        // was extracted. Dispatch no longer depends on the legacy Repair
        // service; the query it calls is unchanged.
        private readonly PersonnelDirectoryService $personnelDirectoryService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    /**
     * TASK 13 — the legacy session bridge shape used by every other
     * authorization consumer in this app ($_SESSION['auth_user'] with a
     * fallback to $_SESSION['user']). Read from the session, never from the
     * request body, so a client cannot claim a role or department.
     */
    private function authUser(Request $request): array
    {
        return (array) $request->session()->get('auth_user', $request->session()->get('user', []));
    }

    /**
     * Deployed Asset registry — shape-checks the optional `assets` payload
     * (assets[dispatch_item_id][] = existing code or blank). Ownership,
     * per-line counts, code format and duplicates are validated server-side
     * by AssetRegistryService::buildReleasePlan() inside the release
     * transaction; this only rejects structurally invalid input early.
     *
     * @return array<int|string, list<string|null>>
     */
    private function validatedAssetPayload(Request $request): array
    {
        $validated = $request->validate([
            'assets'     => ['sometimes', 'nullable', 'array'],
            'assets.*'   => ['array'],
            'assets.*.*' => ['nullable', 'string', 'max:100'],
        ], [
            'assets.array'        => 'Asset details are invalid.',
            'assets.*.array'      => 'Asset details are invalid.',
            'assets.*.*.string'   => 'Each asset code must be text.',
            'assets.*.*.max'      => 'Asset codes may not be longer than 50 characters.',
        ]);

        $assets = $validated['assets'] ?? [];

        // A line whose list is empty was not tracked; dropping it here keeps
        // "checkbox ticked, then unticked" payloads equivalent to omission.
        return array_filter($assets, static fn ($entries) => is_array($entries) && $entries !== []);
    }

    public function index(Request $request)
    {
        // HEAD DASHBOARD BUG FIX — Pending Dispatch Requests widget needs a
        // "Requested By" field, but `dispatches` has no requester/created_by
        // column of its own (only approved_by/released_by/receiver_user_id).
        // The requester is one hop away via the linked maintenance report
        // (maintenance_reports.created_by -> users), so `report.creator` is
        // added to the existing eager-load list (and `created_by` added to
        // the existing partial `report` select so the relation can resolve)
        // instead of adding a new column/endpoint. No new API, no schema
        // change, no change to filtering/business logic below.
        $query = Dispatch::query()
            ->with([
                'department', 'room', 'requestedByUser', 'approvedByUser', 'releasedByUser', 'receiverUser',
                // TASK 13 — eager-loaded so release_assigned_to_name /
                // release_assigned_by_name resolve without an N+1 per row.
                'releaseAssignedToUser', 'releaseAssignedByUser',
                'report:report_id,title,status,created_by',
                'report.creator:user_id,full_name',
            ])
            ->withCount(['items as item_count'])
            // INVENTORY REPORTS SEMESTRAL RECONCILIATION -- additive field.
            // item_count is a line-count, not a unit total; the reconciliation
            // table needs the actual dispatched quantity, which lives on
            // dispatch_items.quantity.
            ->withSum('items as total_quantity', 'quantity');

        // TASK 13 — Maintenance Staff may only see dispatches assigned to
        // them. Applied as a query constraint rather than a post-filter so it
        // cannot be bypassed by paging, and so no other role's view narrows.
        $authUser = $this->authUser($request);
        if ($this->dispatchAuthorizationService->shouldScopeIndexToAssignments($authUser)) {
            $query->where('release_assigned_to', (int) ($authUser['user_id'] ?? 0));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $q = $request->string('search')->toString();
            $query->where('dispatch_code', 'like', "%{$q}%");
        }

        // SPRINT 6 — lets a caller trace all dispatches originating from a
        // given Maintenance Report. Additive filter; omitted by default so
        // existing callers are unaffected. (TASK 13 — the example this named,
        // a replacement dispatch created via
        // RepairService::fulfillReplacement(), has been retired; the filter
        // itself is a plain dispatches.report_id lookup and is unchanged.)
        if ($request->filled('report_id')) {
            $query->where('report_id', (int)$request->query('report_id'));
        }

        // INVENTORY REPORTS ACCOUNTABLE PERSON -- additive filter. Per Room
        // report needs "who most recently received items into this room",
        // which means looking up the latest released dispatch for that room.
        // Omitted by default so existing callers are unaffected.
        if ($request->filled('room_id')) {
            $query->where('room_id', (int)$request->query('room_id'));
        }

        // INVENTORY REPORTS SEMESTRAL/YEARLY FIX — additive date-range filter
        // for the reporting module. `dispatches` has no dedicated transaction
        // date column, only timestamps(), so `created_at` (already rendered
        // as the "Date" column in the report) is the correct field. Uses the
        // project's established whereDate() convention (see AnalyticsService/
        // ReportController) so date-only comparisons stay correct against a
        // datetime column. Omitted by default so existing callers are unaffected.
        // Dispatches page date filter reuses these two parameters. A
        // malformed date is ignored rather than passed to the database.
        if ($request->filled('date_from') && strtotime((string) $request->input('date_from')) !== false) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to') && strtotime((string) $request->input('date_to')) !== false) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Label print tracking — "Not yet printed" / "Printed" filter on the
        // Dispatches page, also applied by Print All Labels. Any other value
        // is ignored, so existing callers are unaffected.
        $labelStatus = (string) $request->query('label_status', '');
        if ($labelStatus === 'not_printed') {
            $query->whereNull('labels_printed_at');
        } elseif ($labelStatus === 'printed') {
            $query->whereNotNull('labels_printed_at');
        }

        $dispatches = $query->orderByDesc('created_at')->paginate((int)$request->integer('per_page', 20));

        return $this->ok('Dispatches retrieved', $dispatches);
    }

    /**
     * POST /api/dispatches/labels-printed — records that the stickers for the
     * given dispatches were printed, after the user confirms the print came
     * out correctly. Drives the "Not yet printed" filter so Print All Labels
     * does not re-print stickers already on the equipment.
     *
     * Only dispatches the user can see (Maintenance Staff: their own assigned
     * dispatches, same scoping as index()) and only printable ones
     * (approved/released, the statuses DispatchLabels prints) are marked;
     * any other id in the request is silently skipped. A reprint updates the
     * timestamp to the latest print.
     */
    public function markLabelsPrinted(Request $request)
    {
        $validated = $request->validate([
            'dispatch_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'dispatch_ids.*' => ['integer', 'distinct'],
        ]);

        $authUser = $this->authUser($request);
        $userId = (int) ($authUser['user_id'] ?? 0);

        $query = Dispatch::query()
            ->whereIn('id', array_map('intval', $validated['dispatch_ids']))
            ->whereIn('status', ['approved', 'released']);

        if ($this->dispatchAuthorizationService->shouldScopeIndexToAssignments($authUser)) {
            $query->where('release_assigned_to', $userId);
        }

        $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids !== []) {
            Dispatch::query()->whereIn('id', $ids)->update([
                'labels_printed_at' => now(),
                'labels_printed_by' => $userId > 0 ? $userId : null,
            ]);

            $this->activityLogService->logFromSession([
                'user_id' => $userId,
                'action' => 'MARK_DISPATCH_LABELS_PRINTED',
                'module' => 'dispatch',
                'entity_type' => 'dispatch',
                'entity_id' => count($ids) === 1 ? $ids[0] : null,
                'details' => 'Marked labels as printed for ' . count($ids) . ' dispatch' . (count($ids) === 1 ? '' : 'es') . '.',
                'meta' => ['dispatch_ids' => $ids],
            ]);
        }

        return $this->ok('Labels marked as printed', [
            'marked_count' => count($ids),
            'dispatch_ids' => $ids,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Multi-Department Selection: accepts department_ids as array for
            // reporting when multiple departments are involved. Maintains
            // backward compatibility with single department_id.
            'department_ids'      => ['nullable', 'array'],
            'department_ids.*'    => ['integer', 'exists:departments,department_id'],
            'department_id'       => ['nullable', 'integer', 'exists:departments,department_id'],
            // TASK 60 — Dispatch Create simplified to a single location
            // source. room_id (the "Room / Lab" dropdown) is now REQUIRED and
            // is the sole location the Release Personnel is told to go to;
            // the free-text room_note supplement was removed from the Create
            // form because in practice it was almost always typed as a
            // duplicate of the already-selected room name, which then showed
            // as two identical-looking cards on Dispatch Detail ("Room / Lab"
            // and "Location Details"). room_note stays in the schema and
            // stays accepted here (nullable) purely so historical dispatches
            // that already have one keep displaying it, and so nothing else
            // that still writes to this column (if anything does) breaks —
            // no new dispatch is expected to populate it going forward.
            'room_id'             => ['required', 'integer', 'exists:rooms,id'],
            'room_note'           => ['nullable', 'string', 'max:500'],
            'purchase_receipt_id' => ['nullable', 'integer', 'exists:purchase_receipts,id'],
            // SPRINT 6 — Legacy Consolidation. Lets a dispatch created through
            // this public endpoint express its link to the originating
            // maintenance report / damage report. Both are optional and
            // default to null, so no existing caller's behavior changes.
            //
            // TASK 13 (Repair retirement) — the third key, 'repair_request_id'
            // => exists:repair_requests,id, was removed here. It is the only
            // place a CLIENT could put a repair_request_id onto a dispatch, and
            // the Repair Request module it linked to no longer exists, so
            // accepting it would create a dispatch pointing at an unreachable
            // record. It is also the app's last `exists:repair_requests`
            // validation rule — leaving it would make this endpoint fail
            // outright once the table is dropped in the database-cleanup task.
            'report_id'            => ['nullable', 'integer', 'exists:maintenance_reports,report_id'],
            'damage_report_id'     => ['nullable', 'integer', 'exists:damage_reports,id'],
            'notes'               => ['nullable', 'string'],
            // Multi-Personnel Dispatch: release_assigned_to is now optional.
            // When using multi-personnel dispatch, personnel may be assigned
            // via the Additional Personnel field (added via /add-personnel
            // endpoint after creation). At least one personnel assignment
            // (primary or additional) is enforced on the client.
            'release_assigned_to' => ['nullable', 'integer', 'exists:users,user_id'],
            'items'               => ['required', 'array', 'min:1'],
            // TASK 47 — Dispatch Create & Assignment Workflow: 'distinct'
            // rejects two rows for the same item_id in one request. Without
            // it, createDispatch() wrote two separate dispatch_items rows for
            // the same item, and releaseDispatch()'s stock check evaluates
            // each dispatch_item independently against the item's current
            // available stock (quantity - reserved_quantity) BEFORE any of
            // that dispatch's own transactions are created — so two rows for
            // the same item can each individually pass a check that their
            // combined total would fail, deferring the real shortfall to a
            // raw RuntimeException from InventoryTransactionObserver at
            // release time instead of a clean validation error at creation
            // time. Rejecting the duplicate here, at the one point the user
            // can still fix it (combine the rows into a single quantity), is
            // cheaper and safer than trying to detect it during release.
            'items.*.item_id'     => ['required', 'integer', 'exists:items,id', 'distinct'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
        ]);

        $authUser = $this->authUser($request);

        // Multi-Personnel Dispatch: only validate release personnel if one was
        // assigned. Validation is skipped when additional personnel will be
        // assigned via the /add-personnel endpoint after creation.
        if (!empty($validated['release_assigned_to'])) {
            try {
                // SERVER-SIDE role/active enforcement. The searchable selector on
                // the create page is already filtered to Head Maintenance +
                // Maintenance Staff (TASK 57 — Administrator excluded), but
                // that is a convenience only — this is the check that actually
                // stops a crafted request from naming an inactive user or an
                // ineligible role. Department-based restriction was removed here
                // (see DispatchAuthorizationService class doc comment) —
                // department_id is a reporting tag, not an eligibility filter.
                $this->dispatchAuthorizationService->assertAssignableReleasePersonnel(
                    $authUser,
                    (int) $validated['release_assigned_to']
                );
            } catch (ValidationException $e) {
                return $this->fail(collect($e->errors())->flatten()->first() ?: 'Invalid release personnel.', 400);
            }
        }

        // TASK 41 — the approval-bypass decision is derived from the SESSION
        // role via the centralized authorization service. It is never read
        // from the request body, so a crafted payload cannot claim it.
        $requiresApproval = $this->dispatchAuthorizationService->creationRequiresApproval($authUser);

        // TASK — an Administrator-created dispatch skips the approval step
        // and is stock-checked immediately inside createDispatch()
        // (assertStockCoversDispatch(), DispatchService.php), which throws a
        // specific ValidationException like "Insufficient Inventory for
        // Chair. Available Stock: 0. Requested: 40." Previously this call
        // was NOT wrapped here, so that exception escaped uncaught to
        // Laravel's default handler, which replies with the generic
        // "The given data was invalid." — losing the real, actionable
        // message the service had already built. Every other mutating
        // action in this controller (approve/reject/release/cancel) already
        // catches ValidationException and surfaces its real message via
        // $this->fail(); this brings store() in line with that same
        // pattern instead of inventing a new one.
        try {
            $dispatch = $this->dispatchService->createDispatch(
                $validated,
                (int)$request->session()->get('user_id'),
                $requiresApproval
            );
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Unable to create dispatch.', 422);
        }

        return $this->ok('Dispatch created', [
            'dispatch_id' => $dispatch->id,
            // TASK 41 — lets the create page tell the user whether the
            // dispatch is awaiting approval or already releasable, without
            // the frontend having to re-derive the rule from the role.
            'status' => $dispatch->status,
            'approval_required' => $requiresApproval,
        ], 201);
    }

    public function show(Request $request, Dispatch $dispatch)
    {
        // TASK 13 — a Maintenance Staff member must not be able to read a
        // dispatch that is not theirs by guessing its id. index() is scoped by
        // query; show() needs the equivalent per-record check.
        $authUser = $this->authUser($request);
        if ($this->dispatchAuthorizationService->shouldScopeIndexToAssignments($authUser)
            && (int) ($dispatch->release_assigned_to ?? 0) !== (int) ($authUser['user_id'] ?? 0)) {
            return $this->fail('Forbidden', 403);
        }

        $dispatch->load(['items.item', 'department', 'room', 'requestedByUser', 'approvedByUser', 'releasedByUser', 'receiverUser', 'releaseAssignedToUser', 'releaseAssignedByUser', 'report:report_id,title,status,created_by', 'report.creator:user_id,full_name']);
        return $this->ok('Dispatch retrieved', ['dispatch' => $dispatch]);
    }

    /**
     * TASK 13 — Release Personnel selector source.
     *
     * Uses PersonnelDirectoryService::searchAssignablePersonnel() with a
     * role set narrower than "everyone" — see TASK 57 below. Department
     * scoping (previously applied here — Head's own department, or an
     * Administrator's chosen destination department) was REMOVED: see
     * DispatchAuthorizationService's class doc comment for why. Every active
     * eligible-role member is now a candidate, for every role that can reach
     * this endpoint.
     *
     * TASK 57 — widened from Maintenance Staff only to Maintenance Staff +
     * Head Maintenance (maintenance_admin). Administrator (super_admin)
     * stays deliberately excluded from the candidate pool, matching
     * DispatchAuthorizationService::assertAssignableReleasePersonnel() and
     * canReleaseDispatch() — the selector's candidate list must never offer
     * a choice the server-side checks would then refuse.
     *
     * TASK 65 — previously RepairService::searchTechnicians(). The underlying
     * query and returned JSON shape are unchanged; only the owning service
     * moved, and (as of this change) the department/role arguments passed to
     * it.
     */
    public function releasePersonnel(Request $request)
    {
        return $this->ok('Release personnel retrieved', [
            'users' => $this->personnelDirectoryService->searchAssignablePersonnel(
                trim((string) $request->query('q', '')),
                (int) $request->query('per_page', 20),
                ['maintenance_admin', 'maintenance_staff']
            ),
        ]);
    }

    /**
     * TASK 13 — assign / reassign Release Personnel after creation.
     */
    public function assignPersonnel(Request $request, Dispatch $dispatch)
    {
        $validated = $request->validate([
            'release_assigned_to' => ['required', 'integer', 'exists:users,user_id'],
        ]);

        $authUser = $this->authUser($request);

        if (!$this->dispatchAuthorizationService->canAssignReleasePersonnel($authUser, $dispatch)) {
            return $this->fail('You are not allowed to change the release personnel for this dispatch.', 403);
        }

        try {
            $this->dispatchAuthorizationService->assertAssignableReleasePersonnel(
                $authUser,
                (int) $validated['release_assigned_to']
            );

            $this->dispatchService->assignReleasePersonnel(
                $dispatch,
                (int) $validated['release_assigned_to'],
                (int) $request->session()->get('user_id')
            );
        } catch (\Throwable $e) {
            $code = $e instanceof ValidationException ? 400 : 500;
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?: 'Unable to assign release personnel')
                : 'Failed to assign release personnel: ' . $e->getMessage();

            return $this->fail($message, $code);
        }

        $this->activityLogService->log([
            'action' => 'ASSIGN_DISPATCH_RELEASE_PERSONNEL',
            'module' => 'dispatch',
            'entity_type' => 'dispatch',
            'entity_id' => $dispatch->id,
            'details' => 'Assigned release personnel for dispatch ' . $dispatch->dispatch_code . '.',
        ], $request);

        return $this->ok('Release personnel assigned');
    }

    public function approve(Request $request, Dispatch $dispatch)
    {
        // TASK 13 — the Release Personnel selector is GONE from the approve
        // payload. It is chosen by Head Maintenance at creation time, so an
        // Administrator supplying it here would be exercising a permission
        // they explicitly do not have. `released_by` and `release_remarks` are
        // no longer accepted at approval either: they now belong to the
        // release step, where the person who actually performs the hand-off
        // supplies them.
        //
        // TASK 55 — Security & Input Validation Hardening: 'approved_by' used
        // to be accepted from request input and persisted VERBATIM into the
        // permanent dispatches.approved_by audit column. This route is
        // already restricted to super_admin (routes/web.php), and the real
        // actor's identity is already available from the session — it's the
        // exact value used below for the activity-log entry — so trusting a
        // second, client-controlled copy of "who did this" served no
        // purpose except letting any super_admin misattribute an approval to
        // a different, arbitrary existing user_id via direct API access (the
        // UI never exposed this: dispatch-detail.php always renders the
        // session user's own id, never an editable field). approved_by is
        // now always derived from the session, matching the identity already
        // used for the audit log, and is never accepted from the client.
        $actorUserId = (int) $request->session()->get('user_id');
        try {
            $this->dispatchService->approveDispatch(
                $dispatch,
                $actorUserId,
                $actorUserId
            );

            // Note: DispatchService::approveDispatch() already writes the
            // APPROVE_DISPATCH activity-log entry (with richer meta) inside
            // its own DB transaction. A second log call here used to write a
            // duplicate row for every approval.

            return $this->ok('Dispatch approved');
        } catch (\Throwable $e) {
            $code = $e instanceof ValidationException ? 400 : 500;
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?: 'Unable to approve dispatch')
                : 'Failed to approve dispatch: ' . $e->getMessage();
            return $this->fail($message, $code);
        }
    }

    /**
     * TASK 13 — Administrator rejection of a pending dispatch.
     */
    public function reject(Request $request, Dispatch $dispatch)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->dispatchService->rejectDispatch(
                $dispatch,
                $validated['reason'],
                (int)$request->session()->get('user_id')
            );
        } catch (\Throwable $e) {
            $code = $e instanceof ValidationException ? 400 : 500;
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?: 'Unable to reject dispatch')
                : 'Failed to reject dispatch: ' . $e->getMessage();

            return $this->fail($message, $code);
        }

        // Note: DispatchService::rejectDispatch() already writes the
        // REJECT_DISPATCH activity-log entry (with richer meta) inside its
        // own DB transaction. A second log call here used to write a
        // duplicate row for every rejection.

        return $this->ok('Dispatch rejected');
    }

    public function release(Request $request, Dispatch $dispatch)
    {
        $validated = $request->validate([
            'receiver_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'release_remarks'  => ['nullable', 'string', 'max:1000'],
        ]);

        $authUser = $this->authUser($request);

        // TASK 13 — THE core security check of this task. Being a
        // maintenance_staff member is not sufficient; the authenticated user
        // must BE the assigned release personnel for this specific dispatch.
        if (!$this->dispatchAuthorizationService->canReleaseDispatch($authUser, $dispatch)) {
            return $this->fail('Only the assigned release personnel may release this dispatch.', 403);
        }

        // Deployed Asset registry — optional "Track as Assets" payload,
        // assets[dispatch_item_id][] = existing code or blank. Omitting it is
        // the untracked release every existing client already sends.
        try {
            $assets = $this->validatedAssetPayload($request);
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Invalid asset details.', 400);
        }

        try {
            $this->dispatchService->releaseDispatch(
                $dispatch,
                // `released_by` is no longer taken from the request body — it
                // is the authenticated user, who has just been proven to be
                // the assigned personnel. Accepting it from the client would
                // let a caller attribute the release to somebody else.
                (int) $authUser['user_id'],
                isset($validated['receiver_user_id']) ? (int)$validated['receiver_user_id'] : null,
                (int)$request->session()->get('user_id'),
                $validated['release_remarks'] ?? null,
                $assets
            );
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Failed to release dispatch', 400);
        } catch (DuplicateDeploymentException $e) {
            // A fixed, user-facing sentence (no database detail) — surfaced
            // exactly as this endpoint always has.
            return $this->fail('Failed to release dispatch: ' . $e->getMessage(), 500);
        } catch (\Throwable $e) {
            // Unexpected failures are logged, not echoed: the raw message can
            // carry SQL and schema details. The transaction has already rolled
            // back, so nothing was released.
            report($e);
            return $this->fail('Failed to release dispatch. No stock was deducted; please try again.', 500);
        }

        // Note: DispatchService::releaseDispatch() already writes the
        // RELEASE_DISPATCH activity-log entry (with richer meta) inside its
        // own DB transaction. A second log call here used to write a
        // duplicate row for every release.

        return $this->ok('Dispatch released successfully');
    }

    public function cancel(Request $request, Dispatch $dispatch)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            $this->dispatchService->cancelDispatch($dispatch, $validated['reason'], (int)$request->session()->get('user_id'));
        } catch (\Throwable $e) {
            $code = $e instanceof \Illuminate\Validation\ValidationException ? 400 : 500;
            $message = $e instanceof \Illuminate\Validation\ValidationException
                ? (collect($e->errors())->flatten()->first() ?: 'Failed to cancel dispatch')
                : 'Failed to cancel dispatch: ' . $e->getMessage();
            return $this->fail($message, $code);
        }

        $this->activityLogService->log([
            'action' => 'CANCEL_DISPATCH',
            'module' => 'dispatch',
            'entity_type' => 'dispatch',
            'entity_id' => $dispatch->id,
            'details' => 'Cancelled dispatch ' . $dispatch->dispatch_code . ': ' . $validated['reason'] . '.',
        ], $request);

        return $this->ok('Dispatch cancelled successfully');
    }

    public function print(Request $request, Dispatch $dispatch)
    {
        $dispatch->load('items.item');
        // Simple HTML printable report
        $html = '<html><head><title>Dispatch ' . $dispatch->dispatch_code . '</title></head><body>';
        $html .= '<h1>Dispatch ' . $dispatch->dispatch_code . '</h1>';
        $html .= '<p>Status: ' . $dispatch->status . '</p>';
        $html .= '<table border="1" cellpadding="6"><thead><tr><th>Item</th><th>Quantity</th></tr></thead><tbody>';
        foreach ($dispatch->items as $di) {
            $html .= '<tr><td>' . htmlspecialchars($di->item->name ?? 'Unknown') . '</td><td>' . $di->quantity . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    /**
     * Multi-Personnel Dispatch: Add personnel to a dispatch.
     * Allows multiple maintenance staff from different departments to be assigned
     * to a single dispatch (distinct from the single release_assigned_to flow).
     */
    public function addPersonnelToDispatch(Request $request, Dispatch $dispatch)
    {
        $validated = $request->validate([
            'personnel_user_id' => ['required', 'integer', 'exists:users,user_id'],
        ]);

        $authUser = $this->authUser($request);
        $actorUserId = (int) $request->session()->get('user_id');

        // Only Head Maintenance (maintenance_admin) can assign personnel
        if ($authUser['role'] !== 'maintenance_admin') {
            return $this->fail('Only Head Maintenance can assign dispatch personnel.', 403);
        }

        try {
            $this->dispatchService->assignPersonnelToDispatch(
                $dispatch,
                (int) $validated['personnel_user_id'],
                $actorUserId,
                $actorUserId
            );

            return $this->ok('Personnel assigned to dispatch');
        } catch (\Throwable $e) {
            $code = $e instanceof ValidationException ? 400 : 500;
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?: 'Unable to assign personnel')
                : 'Failed to assign personnel: ' . $e->getMessage();

            return $this->fail($message, $code);
        }
    }

    /**
     * Multi-Personnel Dispatch: Mark an item as dispatched by a personnel.
     * Called when a maintenance staff member physically dispatches an item.
     */
    public function dispatchItem(Request $request, Dispatch $dispatch, DispatchItem $dispatchItem)
    {
        // The parameter name must match the route's {dispatchItem} segment for
        // implicit model binding (with `$item` the model was never bound, so
        // every call failed the ownership check below), and DispatchItem had
        // no import, so the type-hint named a class that does not exist.
        $item = $dispatchItem;

        // Verify the item belongs to this dispatch
        if ((int) $item->dispatch_id !== (int) $dispatch->id) {
            return $this->fail('This item does not belong to this dispatch.', 404);
        }

        $validated = $request->validate([
            'dispatched_by' => ['required', 'integer', 'exists:users,user_id'],
        ]);

        $authUser = $this->authUser($request);
        $actorUserId = (int) $request->session()->get('user_id');

        // Only Maintenance Staff (maintenance_staff) can dispatch items
        if ($authUser['role'] !== 'maintenance_staff') {
            return $this->fail('Only Maintenance Staff can dispatch items.', 403);
        }

        // Deployed Asset registry — dispatching the final item now performs a
        // real release (see DispatchService::completeDispatchIfAllItemsDispatched()),
        // so a "Track as Assets" payload may accompany it. Registering assets
        // is a release action: only the dispatch's assigned release personnel
        // may submit one, exactly as on POST /release.
        try {
            $assets = $this->validatedAssetPayload($request);
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Invalid asset details.', 400);
        }

        if ($assets !== [] && !$this->dispatchAuthorizationService->canReleaseDispatch($authUser, $dispatch)) {
            return $this->fail('Only the assigned release personnel may register assets for this dispatch.', 403);
        }

        try {
            // One transaction: if completing the dispatch fails (insufficient
            // stock, invalid asset code), the item is not left marked as
            // dispatched on an unreleased dispatch.
            DB::transaction(function () use ($item, $dispatch, $validated, $actorUserId, $assets): void {
                $this->dispatchService->dispatchItem(
                    $item,
                    (int) $validated['dispatched_by'],
                    $actorUserId
                );

                $completed = $this->dispatchService->completeDispatchIfAllItemsDispatched(
                    $dispatch,
                    (int) $validated['dispatched_by'],
                    $actorUserId,
                    $assets
                );

                // Asset details are only consumed by the release that
                // completes the dispatch; refuse them rather than silently
                // dropping codes the user typed.
                if ($assets !== [] && $completed->status !== 'released') {
                    throw ValidationException::withMessages([
                        'assets' => 'Asset codes can only be submitted when the dispatch is released.',
                    ]);
                }
            });

            return $this->ok('Item dispatched successfully');
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Unable to dispatch item', 400);
        } catch (DuplicateDeploymentException $e) {
            return $this->fail('Failed to dispatch item: ' . $e->getMessage(), 500);
        } catch (\Throwable $e) {
            report($e);
            return $this->fail('Failed to dispatch item. Please try again.', 500);
        }
    }
}
