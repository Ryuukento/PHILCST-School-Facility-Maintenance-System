<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Services\ActivityLogService;
use App\Services\DispatchAuthorizationService;
use App\Services\DispatchService;
use App\Services\PersonnelDirectoryService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
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
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $dispatches = $query->orderByDesc('created_at')->paginate((int)$request->integer('per_page', 20));

        return $this->ok('Dispatches retrieved', $dispatches);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
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
            // TASK 13 — Dispatch Release Assignment Workflow: Head Maintenance
            // chooses the Release Personnel while creating the dispatch.
            // Required here (unlike at the service layer) because every
            // dispatch created through the UI must be releasable by someone.
            // (TASK 13 — the internal path this named,
            // RepairService::fulfillReplacement(), has been retired; the
            // service layer still accepts the key as optional.)
            'release_assigned_to' => ['required', 'integer', 'exists:users,user_id'],
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
                $validated['release_remarks'] ?? null
            );
        } catch (\Throwable $e) {
            $code = $e instanceof ValidationException ? 400 : 500;
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?: 'Failed to release dispatch')
                : 'Failed to release dispatch: ' . $e->getMessage();
            return $this->fail($message, $code);
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
}
