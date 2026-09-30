<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateDamageReportException;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceReport;
use App\Services\ActivityLogService;
use App\Services\NeedChangeService;
use App\Services\ReportArchiveService;
use App\Services\ReportAuthorizationService;
use App\Services\ReportService;
use App\Services\RoleNormalizerService;
use App\Support\ApiResponder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly NeedChangeService $needChangeService,
        // TASK 9 — Role + Department Based Authorization: single reusable
        // helper for "can this user modify this report", used by update()
        // instead of duplicating role+department checks per case. Still
        // injected here, not behind ReportService: authorization is decided
        // at the HTTP boundary so a denial can be answered with the exact
        // status code and message the API has always returned.
        private readonly ReportAuthorizationService $reportAuthorizationService,
        // TASK 50 — ReportService Extraction. The report-domain orchestration
        // that used to live in this controller's private methods (creation,
        // update application, deletion, structured location resolution, the
        // status-transition map, and the report activity log / notification
        // side effects that are inseparable from those writes).
        //
        // ActivityLogService, DamageReportService and NotificationService are
        // no longer injected here because this controller no longer calls
        // them directly — ReportService owns those collaborations now. They
        // are the same three service instances, resolved through the same
        // container, just one layer down; no logging, notification or damage
        // report mechanism was added, removed or duplicated.
        private readonly ReportService $reportService,
        // Report Archive — past-term reports: which are view-only, and the
        // Administrator's reopen / lock-again actions below.
        private readonly ReportArchiveService $reportArchiveService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser    = $request->session()->get('auth_user', []);
        $userId      = (int)($authUser['user_id'] ?? 0);
        $role        = $this->normalizeRole((string)($authUser['role'] ?? ''));

        $perPage     = max(1, min(200, (int)$request->query('per_page', 20)));
        $page        = max(1, (int)$request->query('page', 1));
        $statusGroup = strtolower(trim((string)$request->query('status_group', '')));
        $deptId      = (int)$request->query('department_id', 0);
        $dateFrom    = $request->query('date_from');
        $dateTo      = $request->query('date_to');
        // TASK 99 — Damage Report as a report CLASSIFICATION, not a module.
        // Accepted values are 'maintenance' and 'damage'; anything else
        // (including '' and 'all') means "no classification filter", matching
        // how every other optional filter on this endpoint behaves.
        $reportType  = strtolower(trim((string)$request->query('report_type', '')));

        // TASK 44 (M1 — Unified Read Surface) — surface the linked
        // compatibility-layer DamageReport lifecycle state on the All Reports
        // list, the same way show() has done since Sprint 6 (see the
        // Schema::hasTable() guard below, identical pattern/reason: stay safe
        // on any environment/test schema without the compatibility table).
        // The join is 1:1 (report_id is the unique FK, per
        // DamageReportService::createReport()), so it cannot fan out rows or
        // change pagination counts. No write behavior changes.
        //
        // TASK 13 (Repair retirement) — the parallel $hasRepairRequests guard
        // was removed with the repair_requests join it protected; see below.
        $hasDamageReports = Schema::hasTable('damage_reports');
        $hasReportCategory = Schema::hasColumn('maintenance_reports', 'report_category');

        $query = MaintenanceReport::query()
            ->select([
                'maintenance_reports.report_id',
                'maintenance_reports.title',
                'maintenance_reports.priority',
                'maintenance_reports.status',
                'maintenance_reports.location',
                'maintenance_reports.created_at',
                'maintenance_reports.updated_at',
                'maintenance_reports.created_by',
                'maintenance_reports.assigned_to',
                'maintenance_reports.department_id',
                'maintenance_reports.due_date',
                'maintenance_reports.need_change_item_id',
                'maintenance_reports.need_change_quantity',
                'maintenance_reports.need_change_status',
                'maintenance_reports.need_change_approved_by',
                'maintenance_reports.need_change_approved_at',
                'maintenance_reports.need_change_deducted_at',
                DB::raw('assignee.full_name AS assigned_name'),
                DB::raw('creator.full_name  AS creator_name'),
                DB::raw('dept.name          AS department_name'),
            ])
            ->leftJoin('users AS assignee',       'maintenance_reports.assigned_to',  '=', 'assignee.user_id')
            ->leftJoin('users AS creator',        'maintenance_reports.created_by',   '=', 'creator.user_id')
            ->leftJoin('departments AS dept',     'maintenance_reports.department_id','=', 'dept.department_id');

        if ($hasReportCategory) {
            $query->addSelect(['maintenance_reports.report_category']);
        }

        // TASK 99 — the classification the Report Type filter below selects on,
        // surfaced as a field so the list/export UI renders it from the same
        // single source of truth the filter uses, instead of re-deriving
        // "is this a Damage Report" in JavaScript.
        //
        // WHY THIS PREDICATE: damage_reports.status already distinguishes the
        // two repair OUTCOMES this classification is about — 'repaired' (the
        // item could still be fixed) versus 'replaced' (it could not and had to
        // be replaced). A
        // linked damage_reports row on its own means only "this report is about
        // a damaged asset", which is NOT the same question, so report_category
        // / item_id are deliberately not used here.
        //
        // replaced_at is OR'd in because it is permanent: a replaced case may
        // later transition 'replaced' -> 'closed' (DamageReportService's
        // transition map), which would otherwise erase the classification from
        // status alone. replacement_item_id/replaced_by/replaced_at are only
        // ever written together, at replacement time, and never cleared.
        $hasDamageReplacedAt = $hasDamageReports && Schema::hasColumn('damage_reports', 'replaced_at');

        if ($hasDamageReports) {
            $damageOutcomeSql = 'dr.status = \'replaced\''
                . ($hasDamageReplacedAt ? ' OR dr.replaced_at IS NOT NULL' : '');

            $query->addSelect([
                DB::raw('dr.status AS damage_report_status'),
                // A report with no damage_reports row yields NULL on both
                // sides, so the CASE falls through to 'maintenance' in both
                // MySQL and SQLite.
                DB::raw("CASE WHEN ({$damageOutcomeSql}) THEN 'damage' ELSE 'maintenance' END AS report_type"),
            ])->leftJoin('damage_reports AS dr', 'dr.report_id', '=', 'maintenance_reports.report_id');
        } else {
            $query->addSelect([DB::raw("'maintenance' AS report_type")]);
        }

        // TASK 13 (Repair retirement) — the repair_requests join that added
        // `repair_status` and `repair_technician_name` to every row of the All
        // Reports list was removed here, together with its users AS technician
        // join.
        //
        // NOTHING ELSE ON THIS ENDPOINT CHANGES. The two fields were the only
        // Repair Request data the list exposed; title, priority, status,
        // location, department, assignee, creator, dates, need-change fields,
        // report_category, report_type and damage_report_status are all
        // selected above and are untouched. The removed joins were LEFT joins
        // used purely for projection — no WHERE, ORDER BY, GROUP BY or
        // pagination count referenced `rr`, so row counts and ordering are
        // identical.
        //
        // The list's single consumer, reports.php, read `repair_status` in its
        // lifecycle badge, which already fell back to damage_reports.status;
        // that fallback is now the only branch (see reports.php).

        // ── status_group: user-centric views override role scoping ────────
        if ($statusGroup === 'assigned_to_me') {
            $query->where('maintenance_reports.assigned_to', $userId);

        } elseif ($statusGroup === 'overdue') {
            $query->where('maintenance_reports.assigned_to', $userId)
                  ->whereNotNull('maintenance_reports.due_date')
                  ->whereDate('maintenance_reports.due_date', '<', today())
                  ->whereNotIn('maintenance_reports.status', ['completed', 'closed']);

        } elseif ($statusGroup === 'due_soon') {
            $query->where('maintenance_reports.assigned_to', $userId)
                  ->whereNotNull('maintenance_reports.due_date')
                  ->whereBetween('maintenance_reports.due_date', [today(), today()->addDays(7)])
                  ->whereNotIn('maintenance_reports.status', ['completed', 'closed']);

        } elseif ($statusGroup === 'recent_assignments') {
            $query->where('maintenance_reports.assigned_to', $userId)
                  ->where('maintenance_reports.status', 'assigned')
                  ->whereDate('maintenance_reports.updated_at', '>=', today()->subDays(7));

        } elseif (!in_array($role, ['super_admin', 'maintenance_staff', 'maintenance_admin'], true)) {
            // Non-maintenance roles: own reports only (no status_group set)
            $query->where(function ($b) use ($userId): void {
                $b->where('maintenance_reports.created_by', $userId)
                  ->orWhere('maintenance_reports.assigned_to', $userId);
            });
        }
        // TASK 9 — Role + Department Based Authorization: Head Maintenance
        // (maintenance_admin) can view/search reports from ALL departments,
        // same as super_admin/maintenance_staff. This deliberately replaces
        // the earlier Sprint 1 / Feature 2 automatic department scope, which
        // restricted Head Maintenance's default listing to their own
        // department — viewing is now unrestricted; only modification
        // (update()) remains department-gated, via canModifyReport().

        // ── column filters ─────────────────────────────────────────────────
        if ($deptId > 0) {
            $query->where('maintenance_reports.department_id', $deptId);
        }

        if ($request->filled('status')) {
            $query->where('maintenance_reports.status', $request->string('status')->toString());
        }

        if ($request->filled('priority')) {
            $query->where('maintenance_reports.priority', $request->string('priority')->toString());
        }

        // TASK 99 — Report Type. Applied here, alongside every other column
        // filter, so it composes with role scoping above rather than replacing
        // it: a user who may not see a report cannot reach it by asking for a
        // classification. The two branches are mutually exclusive and together
        // cover the whole base set, so 'maintenance' + 'damage' always sums to
        // the unfiltered total.
        if ($reportType === 'damage') {
            if (!$hasDamageReports) {
                // No compatibility table means no report can carry a
                // replacement outcome, so the correct answer is "none" — not
                // "everything".
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($b) use ($hasDamageReplacedAt): void {
                    $b->where('dr.status', 'replaced');
                    if ($hasDamageReplacedAt) {
                        $b->orWhereNotNull('dr.replaced_at');
                    }
                });
            }
        } elseif ($reportType === 'maintenance' && $hasDamageReports) {
            // Written as an explicit positive condition rather than NOT(...):
            // for a report with no damage_reports row dr.status is NULL, and
            // NOT (NULL = 'replaced') is NULL, which SQL treats as false and
            // would silently drop every general report from the result.
            $query->where(function ($b) use ($hasDamageReplacedAt): void {
                $b->where(function ($c): void {
                    $c->whereNull('dr.status')
                      ->orWhere('dr.status', '<>', 'replaced');
                });
                if ($hasDamageReplacedAt) {
                    $b->whereNull('dr.replaced_at');
                }
            });
        }

        if ($request->filled('search')) {
            $kw = '%' . $request->string('search')->toString() . '%';
            $query->where(function ($b) use ($kw): void {
                $b->where('maintenance_reports.title',        'like', $kw)
                  ->orWhere('maintenance_reports.description', 'like', $kw)
                  ->orWhere('maintenance_reports.location',   'like', $kw);
            });
        }

        if ($dateFrom) {
            $query->whereDate('maintenance_reports.created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('maintenance_reports.created_at', '<=', $dateTo);
        }

        // ── flat response — frontend reads data.reports[] ─────────────────
        $total   = (clone $query)->count();

        // Report Archive — the reopen marker is only selected where the
        // column exists (it is absent from the isolated test schema).
        $hasReopenColumn = Schema::hasColumn('maintenance_reports', 'archive_reopened_at');
        if ($hasReopenColumn) {
            $query->addSelect('maintenance_reports.archive_reopened_at');
        }

        $reports = $query
            ->orderByDesc('maintenance_reports.created_at')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Report Archive — per-row term / view-only state for the list.
        foreach ($reports as $row) {
            $row->archive = $this->reportArchiveService->describe(
                $row->created_at,
                (string) $row->status,
                $hasReopenColumn ? $row->archive_reopened_at : null
            );
        }

        return $this->ok('Reports retrieved', [
            'reports'  => $reports,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    public function store(Request $request)
    {
        $authUser = $request->session()->get('auth_user', []);
        $submitterName = (string)($authUser['full_name'] ?? 'A staff member');

        $validated = $request->validate(array_merge($this->problemTypeRules(false), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            // TASK 38 — Create Report location validation against Buildings
            // Overview. See ReportService::resolveStructuredLocation() for why
            // these are deliberately named location_* rather than reusing
            // room_id: room_id already means "the room this damaged ASSET sits
            // in" and, together with item_id, routes this request to
            // ReportService::createAssetReport(). These three describe where
            // the ISSUE is, which is a different question, so they must not
            // collide.
            'location_building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'location_floor_id' => ['nullable', 'integer', 'exists:floors,id'],
            'location_room_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'status' => ['nullable', 'string', 'in:submitted,in_progress,completed,closed,cancelled'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,user_id'],
            // TASK 19 — Target Maintenance Department: the reporter now picks
            // which department is responsible for fixing the issue, so this
            // must resolve to an active department, never a client-trusted
            // free value. Stays nullable (not required) so the asset/damage
            // path below, which still derives department_id from the
            // reporter when none is supplied, is unaffected.
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'department_id')->where('status', 'active')],
            'due_date' => ['nullable', 'date'],
            // SPRINT 5 — optional asset/damage fields, mirroring
            // DamageReportController::store()'s own validation. When item_id
            // + room_id are both supplied, this Maintenance Report concerns a
            // specific deployed asset and is delegated to
            // DamageReportService::createReport() (reused wholesale,
            // not duplicated) so /api/reports can now serve as the primary
            // entry point for the full repair/replacement lifecycle, not
            // just general (non-asset) reports.
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'severity_level' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'source_dispatch_id' => ['nullable', 'integer', 'exists:dispatches,id'],
            'damage_description' => ['nullable', 'string', 'min:5'],
            'repair_notes' => ['nullable', 'string'],
            'damage_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'override_duplicate' => ['nullable', 'boolean'],
        ]), $this->problemTypeMessages());

        // TASK 10 (Security Audit) — creation must not be usable as an
        // escalation vector. Maintenance Staff cannot assign personnel and
        // cannot drive status transitions (BUSINESS_RULES.md), so a
        // client-supplied assigned_to/status from that role is ignored rather
        // than honored: otherwise a staff member could assign work to anybody,
        // or plant an already-'completed' report and thereby skip both
        // STATUS_TRANSITIONS and the completion-proof requirement.
        $canAssignAtCreation = in_array(
            $this->normalizeRole((string)($authUser['role'] ?? '')),
            ['super_admin', 'maintenance_admin'],
            true
        );

        // TASK 38 — resolved BEFORE the asset fork so both creation branches
        // (general and createAssetReport(), which reads
        // $validated['location']) get the same server-derived, verified
        // location string. A client-supplied 'location' is overwritten, never
        // trusted, whenever the structured triple is present: that is what
        // makes a hand-crafted request unable to claim a room it did not
        // actually pass validation for.
        // TASK 50 — the resolution rule itself moved to ReportService; it is
        // still called at exactly this point, still uncaught, so an invalid
        // triple still surfaces as Laravel's standard 422 validation response.
        $structuredLocation = $this->reportService->resolveStructuredLocation($validated);
        if ($structuredLocation !== null) {
            $validated['location'] = $structuredLocation;
        }

        // SPRINT 5 — when item_id + room_id are both supplied this Maintenance
        // Report concerns a specific deployed asset, and creation is delegated
        // to DamageReportService (via ReportService::createAssetReport()) so
        // the /api/damage-reports validation, duplicate prevention, image
        // handling, history and notification behaviour are reused wholesale
        // rather than duplicated.
        if (!empty($validated['item_id']) && !empty($validated['room_id'])) {
            try {
                $damageReport = $this->reportService->createAssetReport(
                    $validated,
                    $authUser,
                    $request->file('damage_image')
                );
            } catch (DuplicateDamageReportException $e) {
                return $this->fail($e->getMessage(), 409, ['duplicate' => $e->getDuplicate()]);
            }

            return $this->ok('Report created successfully', [
                'report_id' => $damageReport->report_id,
                'damage_report_id' => $damageReport->id,
            ], 201);
        }

        $report = $this->reportService->createGeneralReport(
            $validated,
            $authUser,
            $canAssignAtCreation,
            $submitterName
        );

        return $this->ok('Report created successfully', ['report_id' => $report->report_id], 201);
    }

    public function recent(Request $request): JsonResponse
    {
        $authUser = $request->session()->get('auth_user', []);
        $userId   = (int)($authUser['user_id'] ?? 0);
        $role     = $this->normalizeRole((string)($authUser['role'] ?? ''));
        $limit    = max(1, min(100, (int)$request->query('limit', 10)));

        $query = MaintenanceReport::query()
            ->select([
                'maintenance_reports.report_id',
                'maintenance_reports.title',
                'maintenance_reports.description',
                'maintenance_reports.priority',
                'maintenance_reports.status',
                'maintenance_reports.location',
                'maintenance_reports.created_at',
                'maintenance_reports.created_by',
                'maintenance_reports.assigned_to',
                'maintenance_reports.department_id',
                DB::raw('assignee.full_name AS assigned_name'),
                DB::raw('creator.full_name  AS creator_name'),
            ])
            ->leftJoin('users AS assignee', 'maintenance_reports.assigned_to', '=', 'assignee.user_id')
            ->leftJoin('users AS creator',  'maintenance_reports.created_by',  '=', 'creator.user_id')
            ->whereDate('maintenance_reports.created_at', today());

        // Role scoping — mirrors legacy getRecentReports()
        // TASK 9 — Head Maintenance (maintenance_admin) can view ALL
        // departments, same as super_admin/maintenance_staff. See index()
        // above for the same policy change.
        if (in_array($role, ['super_admin', 'maintenance_staff', 'maintenance_admin'], true)) {
            // no scope — sees all today's reports
        } else {
            $query->where(function ($b) use ($userId): void {
                $b->where('maintenance_reports.created_by', $userId)
                  ->orWhere('maintenance_reports.assigned_to', $userId);
            });
        }

        $reports = $query
            ->orderByDesc('maintenance_reports.created_at')
            ->limit($limit)
            ->get();

        return $this->ok('Recent reports retrieved', ['reports' => $reports]);
    }

    public function show(Request $request, MaintenanceReport $report): JsonResponse
    {
        $authUser = $request->session()->get('auth_user', []);
        $userId   = (int)($authUser['user_id'] ?? 0);
        $role     = $this->normalizeRole((string)($authUser['role'] ?? ''));

        // Auth check on the bound model (no extra query)
        if (!in_array($role, ['super_admin', 'maintenance_admin', 'maintenance_staff'], true)
            && (int)$report->created_by !== $userId
            && (int)$report->assigned_to !== $userId) {
            return $this->fail('Forbidden', 403);
        }

        // Flat join query — returns all fields the frontend reads directly
        //
        // SPRINT 6 — Legacy Consolidation. Adds the linked compatibility-layer
        // DamageReport (if any) as flat fields on this same response, via the
        // existing report_id FK (Sprint 3/4). This makes the Maintenance
        // Report detail endpoint the single place a caller needs to check to
        // see the full lifecycle state of a report — previously a frontend had
        // to separately know and query /api/damage-reports/{id} by a
        // damage_report_id it would have had to look up first. The join is
        // safe 1:1 (a report has at most one linked DamageReport per SPRINT
        // 4's creation path), so this cannot fan out rows. All added columns
        // are nullable/new — no existing field is renamed or removed. Guarded
        // by Schema::hasTable() (same pattern as Schema::hasColumn() used
        // elsewhere in this controller) so this stays safe against any
        // environment/test schema that only has the core maintenance_reports
        // table and not the compatibility table.
        //
        // TASK 13 (Repair retirement) — the parallel $hasRepairRequests guard
        // was removed with the repair_requests block it protected; see below.
        $hasDamageReports = Schema::hasTable('damage_reports');

        $reportQuery = DB::table('maintenance_reports AS r')
            ->select([
                'r.report_id',
                'r.title',
                'r.description',
                // Problem Type — surfaced so the detail page can show the
                // category, and so the Edit Report modal (which loads through
                // this same endpoint) can pre-select the saved card.
                'r.problem_type',
                'r.problem_type_other',
                'r.priority',
                'r.status',
                'r.location',
                'r.created_at',
                'r.updated_at',
                'r.created_by',
                'r.assigned_to',
                'r.department_id',
                'r.due_date',
                'r.completed_date',
                'r.need_change_item_id',
                'r.need_change_quantity',
                'r.need_change_status',
                'r.need_change_approved_by',
                'r.need_change_approved_at',
                'r.need_change_deducted_at',
                DB::raw('creator.full_name  AS creator_name'),
                DB::raw('creator.email      AS creator_email'),
                DB::raw('assignee.full_name AS assigned_name'),
                DB::raw('assignee.email     AS assigned_email'),
                DB::raw('dept.name          AS department_name'),
                DB::raw('nc_item.name       AS need_change_item_name'),
                DB::raw('nc_item.quantity   AS need_change_item_quantity'),
            ])
            ->leftJoin('users AS creator',    'r.created_by',          '=', 'creator.user_id')
            ->leftJoin('users AS assignee',   'r.assigned_to',         '=', 'assignee.user_id')
            ->leftJoin('departments AS dept', 'r.department_id',       '=', 'dept.department_id')
            ->leftJoin('items AS nc_item',    'r.need_change_item_id', '=', 'nc_item.id');

        if ($hasDamageReports) {
            $reportQuery->addSelect([
                DB::raw('dr.id              AS damage_report_id'),
                DB::raw('dr.damage_report_code AS damage_report_code'),
                DB::raw('dr.status          AS damage_report_status'),
            ])->leftJoin('damage_reports AS dr', 'dr.report_id', '=', 'r.report_id');
        }

        // TASK 13 (Repair retirement) — the repair_requests block that added
        // repair_request_id / repair_code / repair_status /
        // repair_technician_user_id / repair_technician_name was removed here,
        // along with the two joins it needed (users AS technician, and
        // dispatches AS replacement_dispatch).
        //
        // THE REPLACEMENT DISPATCH FIELDS WENT WITH IT, AND THAT IS DELIBERATE.
        // replacement_dispatch_id/_code/_status were reachable ONLY through
        // repair_requests.replacement_dispatch_id — that column was the join
        // key. There is no equivalent link anywhere else: damage_reports has
        // no replacement_dispatch_id, and dispatches.report_id is a general,
        // user-suppliable field that can be one-to-many against a report, so
        // joining through it instead would risk duplicating this row (the
        // original code called that out explicitly as the reason it did NOT
        // use it). Re-sourcing the field would therefore mean redesigning the
        // relationship, which is out of scope here.
        //
        // The primary workflow is not broken by the loss. Every field the
        // detail page needs to run Create -> Assign -> Monitor -> Resolve is
        // still selected above, and the damage_report_id/_code/_status block
        // immediately above is untouched. maintenance-report-detail.php
        // guards each row of its "Damage & Dispatch Information" card
        // independently (`if (report.replacement_dispatch_id)`), so the
        // Replacement Dispatch row simply does not render rather than
        // rendering an `undefined` link. repair_requests holds zero rows, so
        // no report loses a row it displays today.
        $data = $reportQuery->where('r.report_id', (int)$report->report_id)->first();

        if (!$data) {
            return $this->fail('Report not found', 404);
        }

        // Report Archive — term / view-only state for the detail page.
        $data->archive = $this->reportArchiveService->describe(
            $report->created_at,
            (string) $report->status,
            $report->getAttribute('archive_reopened_at')
        );

        return $this->ok('Report retrieved', ['report' => $data]);
    }

    public function update(Request $request, MaintenanceReport $report): JsonResponse
    {
        $authUser = $request->session()->get('auth_user', []);
        $userId   = (int)($authUser['user_id'] ?? 0);
        $role     = $this->normalizeRole((string)($authUser['role'] ?? ''));

        // TASK 9 — Role + Department Based Authorization: every update
        // endpoint must verify role AND department ownership before any
        // modification (Assign, Status, Priority, Need Change, Cancel, Edit,
        // Close, completion-proof upload). super_admin always passes;
        // maintenance_admin/maintenance_staff must own the report's
        // department (and, for staff, be the assignee). Never rely on the
        // frontend alone — see canModifyReport() for the exact rule.
        if (!$this->reportAuthorizationService->canModifyReport($authUser, $report)) {
            return $this->fail('You are not authorized to modify this report', 403);
        }

        // Report Archive — a finished report from a past academic term is
        // view-only for everyone (including the Administrator) until the
        // Administrator explicitly reopens it via archiveReopen().
        if ($this->reportArchiveService->isLocked($report)) {
            return $this->fail(ReportArchiveService::LOCKED_MESSAGE, 423);
        }

        $previousStatus = (string) $report->status;
        // TASK 20 — Assignment Notification Scoping: captured before any
        // changes are applied so the notify-on-assign check below can tell
        // a genuine reassignment (previous assignee -> new assignee) apart
        // from an idempotent resubmission of the same assignee.
        $previousAssignedTo = $report->assigned_to !== null ? (int) $report->assigned_to : null;
        $changes = [];

        // CASE D/E — need-change approval/rejection (super_admin only)
        //
        // TASK 49 — the rule is no longer restated here as an inline role
        // comparison. Both branches now ask ReportAuthorizationService, which
        // is the same single source of truth canModifyReport() above already
        // uses, so there is one implementation of the Need Change rule rather
        // than two literals that could drift.
        if ($request->boolean('approve_need_change')) {
            if (!$this->reportAuthorizationService->canApproveNeedChange($authUser)) {
                return $this->fail(ReportAuthorizationService::NEED_CHANGE_APPROVE_DENIED_MESSAGE, 403);
            }

            try {
                $this->needChangeService->approve($report, $authUser);
            } catch (AuthorizationException $e) {
                // TASK 49 — the service enforces the same rule independently.
                // Reaching here means the gate above was bypassed or removed;
                // it must still surface as a 403 denial and not as a 500, so
                // this arm is placed ahead of the \Throwable catch below.
                return $this->fail($e->getMessage(), 403);
            } catch (\Throwable $e) {
                // TASK 2 — Need Change Inventory Automation: approval also
                // deducts stock inside a DB transaction, so this mirrors
                // DispatchController::approve()'s broader \Throwable catch
                // (an unexpected failure mid-transaction should roll back
                // cleanly and surface a friendly message, not bubble up as
                // an unhandled 500).
                $code = $e instanceof ValidationException ? 422 : 500;
                $message = $e instanceof ValidationException
                    ? (collect($e->errors())->flatten()->first() ?: 'Unable to approve Need Change request')
                    : 'Failed to approve Need Change: ' . $e->getMessage();
                return $this->fail($message, $code);
            }

            return $this->ok('Need Change approved and inventory deducted successfully');
        } elseif ($request->boolean('reject_need_change')) {
            if (!$this->reportAuthorizationService->canRejectNeedChange($authUser)) {
                return $this->fail(ReportAuthorizationService::NEED_CHANGE_REJECT_DENIED_MESSAGE, 403);
            }
            $changes['need_change_status'] = 'rejected';
        }

        // CASE B — status change
        if ($request->has('status')) {
            $newStatus     = strtolower(trim((string)$request->input('status', '')));
            // TASK 50 — the status vocabulary and the transition map moved to
            // ReportService together, so this HTTP-boundary "is that even a
            // status" check and the domain-level "is that transition legal"
            // check still read from one list.
            $validStatuses = ReportService::STATUSES;
            if (!in_array($newStatus, $validStatuses, true)) {
                return $this->fail('Invalid status value', 422);
            }

            // TASK 55 — Security & Input Validation Hardening: assigned_to,
            // due_date, and completed_date below were previously read
            // straight from $request->input() with zero validation, unlike
            // store()'s equivalent 'assigned_to' (exists:users,user_id) and
            // 'due_date' (date) rules above. assigned_to DOES carry a
            // DB-level FK constraint (see
            // 2026_03_27_000400_create_maintenance_reports_table), but
            // relying on that alone means a nonexistent user_id surfaced as
            // an uncaught QueryException — a raw 500 instead of a clean 422
            // (see Phase 14: error handling) — rather than being rejected
            // here; due_date/completed_date have no DB-level equivalent at
            // all, so a malformed value throws an uncaught Carbon parse
            // exception straight out of Eloquent's date-cast handling
            // (proven directly: PATCHing due_date/completed_date with
            // 'not-a-real-date' crashed with a raw 500 and a full stack
            // trace before this fix). Validated here, at the exact point
            // each value is read, mirroring store()'s rules for consistency.
            $caseBValidated = $request->validate([
                'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,user_id'],
                'due_date' => ['sometimes', 'nullable', 'date'],
                'completed_date' => ['sometimes', 'nullable', 'date'],
            ]);

            // Sprint 2 / Feature 2 — enforce the transition map before any
            // role-permission check, so an invalid jump (e.g. submitted ->
            // closed) is rejected regardless of who requests it.
            $fromStatus = strtolower(trim((string)$report->status));
            try {
                $this->reportService->assertValidStatusTransition($fromStatus, $newStatus);
            } catch (ValidationException $e) {
                return $this->fail(collect($e->errors())->flatten()->first() ?: 'Invalid status transition', 422);
            }

            $staffAllowed = ['in_progress', 'completed'];
            $adminAllowed = ['submitted', 'assigned', 'in_progress', 'completed', 'closed', 'cancelled'];
            // 2026-09-30 — the Administrator (super_admin) no longer has any
            // status-change action on a report (previously could only
            // Cancel it; that capability was retired at the Capstone
            // Panel's request). There is deliberately no super_admin branch
            // here anymore, so a super_admin's status-update request falls
            // through to the final else below and is rejected outright.
            // Head Maintenance (maintenance_admin/department_admin) keeps
            // its own, separate 'cancelled' option via $adminAllowed above,
            // unaffected by this change.
            if (in_array($role, ['maintenance_admin', 'department_admin'], true)) {
                if (!in_array($newStatus, $adminAllowed, true)) {
                    return $this->fail('You cannot set that status', 403);
                }
            } elseif ($role === 'maintenance_staff') {
                // RBAC POLICY UPDATE — Maintenance Staff may only update the
                // status of reports assigned to them, not any report they can see.
                if ((int) $report->assigned_to !== $userId) {
                    return $this->fail('You can only update status of reports assigned to you', 403);
                }
                if (!in_array($newStatus, $staffAllowed, true)) {
                    return $this->fail('Maintenance Staff can only set status to In Progress or Completed', 403);
                }
            } else {
                return $this->fail('You are not allowed to change report status', 403);
            }
            // Rules the detail page already enforced, now enforced here too
            // so they cannot be skipped by calling the API directly:
            //  - "Assigned" needs someone assigned (in this request or already
            //    on the report);
            //  - "Completed" needs a completion proof image (uploaded now or
            //    already on the report).
            if ($newStatus === 'assigned' && $newStatus !== $fromStatus) {
                $assigneeAfter = array_key_exists('assigned_to', $caseBValidated)
                    ? $caseBValidated['assigned_to']
                    : $report->assigned_to;
                if (empty($assigneeAfter)) {
                    return $this->fail('Please select maintenance staff to assign before setting the status to Assigned.', 422);
                }
            }
            if ($newStatus === 'completed' && $newStatus !== $fromStatus
                && !$request->hasFile('completion_proof_image')
                && empty($report->completion_proof_image)) {
                return $this->fail('Please upload a completion proof image before marking this report as completed.', 422);
            }

            $changes['status'] = $newStatus;
            if ($newStatus === 'assigned' && array_key_exists('assigned_to', $caseBValidated)) {
                $at = $caseBValidated['assigned_to'];
                $changes['assigned_to'] = $at ? (int) $at : null;
            }
            if (in_array($newStatus, ['completed', 'closed'], true)) {
                $changes['completed_date'] = $caseBValidated['completed_date'] ?? now()->toDateString();
            }
            if (array_key_exists('due_date', $caseBValidated)) {
                $changes['due_date'] = $caseBValidated['due_date'] ?: null;
            }
        }

        // CASE C — completion proof file upload
        if ($request->hasFile('completion_proof_image')) {
            $file         = $request->file('completion_proof_image');
            $allowedMimes = [
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                'image/png'  => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            ];
            $mime = $file->getMimeType();
            if (!isset($allowedMimes[$mime])) {
                return $this->fail('Only JPG, PNG, WEBP, or GIF images are allowed', 422);
            }
            if ($file->getSize() > 5 * 1024 * 1024) {
                return $this->fail('Completion proof image must be 5MB or smaller', 422);
            }
            $uploadDir = public_path('frontend/uploads/completion-proofs');
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            $ext      = $allowedMimes[$mime];
            $fileName = 'report-' . (int) $report->report_id
                      . '-' . date('YmdHis')
                      . '-' . bin2hex(random_bytes(4))
                      . '.' . $ext;
            $file->move($uploadDir, $fileName);
            $changes['completion_proof_image'] = '/School_Facility_Maintenance_System/frontend/uploads/completion-proofs/' . $fileName;
        }

        // CASE A — basic field edit
        // Problem Type is editable here and nowhere else, which is what keeps
        // "authorized users can update it according to their EXISTING
        // permissions" literally true: it rides the CASE A gate below
        // (owner, or super_admin/maintenance_admin) that already governs
        // title/description/location/priority. No new permission, no new role
        // check, no change to canModifyReport().
        //
        // problem_type_other is deliberately NOT listed: it is derived from
        // problem_type by ReportService::resolveProblemTypeOther() rather than
        // copied from the request, so an edit away from 'Other' clears it
        // instead of leaving a stale custom value behind.
        $caseAFields = ['title', 'description', 'problem_type', 'location', 'priority',
                        'department_id', 'need_change_item_id', 'need_change_quantity'];
        // TASK 38 — editing must be held to the same standard as creating,
        // otherwise a report could be created with a verified location and
        // then quietly edited to a room that does not exist.
        $caseALocationFields = ['location_building_id', 'location_floor_id', 'location_room_id'];
        $hasCaseA    = collect(array_merge($caseAFields, $caseALocationFields))
            ->contains(fn ($f) => $request->has($f));
        if ($hasCaseA) {
            $isOwner    = (int) $report->created_by === $userId;
            $canEditAny = in_array($role, ['super_admin', 'maintenance_admin'], true);
            if (!$isOwner && !$canEditAny) {
                return $this->fail('You can only edit your own reports', 403);
            }

            // TASK 29.1 — Need Change Data Integrity Protection: once a
            // replacement request has been approved or deducted,
            // NeedChangeService::approve() has already run a DB-transactional
            // stock deduction (InventoryTransaction). need_change_item_id /
            // need_change_quantity must become immutable at that point —
            // editing OR clearing either field (including turning the Edit
            // Report "Need Change" toggle off) would desync the report from
            // the inventory movement that already happened. The backend is
            // the sole source of truth for this rule; the Edit Report modal
            // additionally disables these controls client-side, but that is
            // UX only — this check is what actually enforces it.
            $touchesNeedChange = $request->has('need_change_item_id') || $request->has('need_change_quantity');
            if ($touchesNeedChange && in_array($report->need_change_status, ['approved', 'deducted'], true)) {
                return $this->fail(
                    $report->need_change_status === 'deducted'
                        ? 'This replacement request has already been processed and inventory has been affected. It can no longer be modified.'
                        : 'This replacement request has already been approved and can no longer be modified.',
                    422
                );
            }

            // TASK 55 — Security & Input Validation Hardening: these fields
            // were previously copied straight from $request->input() with
            // zero validation, unlike store()'s equivalent rules above.
            // department_id in particular had neither an application-level
            // exists() check nor a DB-level foreign key (department_id has
            // no FK constraint — see
            // 2026_03_27_000400_create_maintenance_reports_table), so any
            // actor who passed the canModifyReport() gate above could
            // silently persist a nonexistent or inactive department_id,
            // moving the report outside anyone's modification authority
            // except super_admin. (Re-targeting to a DIFFERENT but real,
            // active department remains intentionally allowed — store()'s
            // own "TASK 19 — Target Maintenance Department" rule already
            // lets any role freely pick the handling department at creation,
            // so this validation only closes the invalid-value gap, not a
            // privilege boundary.) priority also had no enum whitelist,
            // unlike store()'s 'in:low,medium,high,critical'. Mirrors
            // store()'s validation exactly so create/update stay consistent.
            $caseAValidated = $request->validate(array_merge($this->problemTypeRules(true), [
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'string'],
                'location' => ['sometimes', 'nullable', 'string', 'max:255'],
                // TASK 38 — same triple as store(), resolved by the same
                // helper. Not duplicated logic: only the rules differ (the
                // 'sometimes' prefix this endpoint uses throughout).
                'location_building_id' => ['sometimes', 'nullable', 'integer', 'exists:buildings,id'],
                'location_floor_id' => ['sometimes', 'nullable', 'integer', 'exists:floors,id'],
                'location_room_id' => ['sometimes', 'nullable', 'integer'],
                'priority' => ['sometimes', 'nullable', 'string', 'in:low,medium,high,critical'],
                'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'department_id')->where('status', 'active')],
                'need_change_item_id' => ['sometimes', 'nullable', 'integer', 'exists:items,id'],
                'need_change_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]), $this->problemTypeMessages());

            // TASK 38 — resolved before the copy loop so the verified,
            // server-derived string wins over any 'location' the same request
            // also tried to supply.
            $structuredLocation = $this->reportService->resolveStructuredLocation($caseAValidated);
            if ($structuredLocation !== null) {
                $caseAValidated['location'] = $structuredLocation;
            }

            foreach ($caseAFields as $field) {
                if (array_key_exists($field, $caseAValidated)) {
                    $value = $caseAValidated[$field];
                    $changes[$field] = is_string($value) ? trim($value) : $value;
                }
            }

            // Problem Type: the paired free-text column is recomputed from the
            // NEW category through the same single implementation store() uses,
            // so create and edit cannot disagree about when a custom value is
            // kept. Only ever touched when the category itself is part of this
            // request — a PATCH that does not mention problem_type leaves both
            // columns exactly as they were.
            if (array_key_exists('problem_type', $changes)) {
                $changes['problem_type_other'] = $this->reportService->resolveProblemTypeOther(
                    $changes['problem_type'],
                    $caseAValidated['problem_type_other'] ?? null
                );
            }
        }

        if (empty($changes)) {
            return $this->fail('No valid fields to update', 422);
        }

        // TASK 50 — everything that follows the authorization, validation
        // and $changes assembly above is report-domain work: the write
        // itself, the single audit entry that describes it, and the
        // completion/assignment notifications that must follow it. All of
        // it moved to ReportService::applyUpdate() unchanged and in the same
        // order, still with no surrounding transaction. $previousStatus and
        // $previousAssignedTo were captured at the top of this method,
        // before any modification, which is what lets applyUpdate() tell a
        // genuine transition from an idempotent resubmission.
        $this->reportService->applyUpdate(
            $report,
            $changes,
            $authUser,
            $previousStatus,
            $previousAssignedTo
        );

        return $this->ok('Report updated successfully');
    }

    public function destroy(Request $request, MaintenanceReport $report): JsonResponse
    {
        $authUser = $request->session()->get('auth_user', []);
        $userId   = (int)($authUser['user_id'] ?? 0);
        $role     = $this->normalizeRole((string)($authUser['role'] ?? ''));

        // TASK 10 (Security Audit) — deletion is the most destructive
        // modification there is, so it must respect the same department
        // ownership rule as every other modification (TASK 9). Previously only
        // authorship was checked, which left a report that had been moved to
        // another department permanently deletable by its original author.
        // Department matching is delegated to ReportAuthorizationService so
        // the comparison still lives in exactly one place.
        if ($role !== 'super_admin') {
            if (!$this->reportAuthorizationService->sharesDepartment($authUser, $report)) {
                return $this->fail('You are not authorized to modify this report', 403);
            }

            if ((int)$report->created_by !== $userId) {
                return $this->fail('You can only delete your own reports', 403);
            }
        }

        // Report Archive — archived records are never deleted while locked.
        if ($this->reportArchiveService->isLocked($report)) {
            return $this->fail(ReportArchiveService::LOCKED_MESSAGE, 423);
        }

        // Deletion is permanent, so it is limited to reports nobody has
        // started working on. Once a report is assigned, in progress, or
        // finished it carries work history (and possibly inventory
        // movements), so it is cancelled instead of deleted.
        if (strtolower((string) $report->status) !== 'submitted') {
            return $this->fail('Only reports that are still Submitted can be deleted. Cancel the report instead.', 422);
        }

        // TASK 50 — the delete and the DELETE_REPORT audit entry that must
        // accompany it moved to ReportService::deleteReport() as a pair, so
        // the row can never be removed without the entry being written.
        $this->reportService->deleteReport($report, $authUser);

        return $this->ok('Report deleted successfully');
    }

    /**
     * Report Archive — Administrator reopens a view-only past-term report so
     * it can be corrected. Route-gated to super_admin. Recorded in the
     * Activity Log. The report locks again when finished again, or via
     * archiveLock().
     */
    public function archiveReopen(Request $request, MaintenanceReport $report): JsonResponse
    {
        if (!$this->reportArchiveService->isLocked($report)) {
            return $this->fail('Only a view-only archived report can be reopened.', 422);
        }

        $authUser = $request->session()->get('auth_user', []);
        $report->forceFill([
            'archive_reopened_at' => now(),
            'archive_reopened_by' => (int) ($authUser['user_id'] ?? 0) ?: null,
        ])->save();

        $archive = $this->reportArchiveService->describe($report->created_at, (string) $report->status, $report->archive_reopened_at);
        $this->activityLogService->log([
            'action'      => 'REOPEN_ARCHIVED_REPORT',
            'module'      => 'reports',
            'entity_type' => 'maintenance_report',
            'entity_id'   => (int) $report->report_id,
            'details'     => sprintf('Reopened archived report #%d (%s) for changes.', (int) $report->report_id, $archive['term_label'] ?? 'past term'),
        ], $request);

        return $this->ok('Archived report reopened for changes', ['archive' => $archive]);
    }

    /**
     * Report Archive — Administrator makes a reopened past-term report
     * view-only again once the correction is done. Route-gated to super_admin.
     */
    public function archiveLock(Request $request, MaintenanceReport $report): JsonResponse
    {
        $archive = $this->reportArchiveService->describe($report->created_at, (string) $report->status, $report->getAttribute('archive_reopened_at'));
        if (!$archive['is_reopened']) {
            return $this->fail('This report is not a reopened archived report.', 422);
        }

        $report->forceFill(['archive_reopened_at' => null, 'archive_reopened_by' => null])->save();
        $archive = $this->reportArchiveService->describe($report->created_at, (string) $report->status, null);

        $this->activityLogService->log([
            'action'      => 'LOCK_ARCHIVED_REPORT',
            'module'      => 'reports',
            'entity_type' => 'maintenance_report',
            'entity_id'   => (int) $report->report_id,
            'details'     => sprintf('Locked archived report #%d (%s) as view-only again.', (int) $report->report_id, $archive['term_label'] ?? 'past term'),
        ], $request);

        return $this->ok('Archived report is view-only again', ['archive' => $archive]);
    }

    /**
     * Problem Type validation rules, shared by store() and the CASE A edit
     * path.
     *
     * ONE DEFINITION, TWO CALLERS — the brief's "do not create duplicate
     * validation logic across controllers" applied inside this one. The only
     * difference between create and edit is the presence prefix, which is
     * exactly the $isUpdate ? 'sometimes' : 'required' shape
     * PreventiveMaintenanceController::rules() already uses in this codebase,
     * so this is the established pattern rather than a new one.
     *
     * THE ALLOW-LIST IS NOT WRITTEN HERE. Rule::in() reads
     * config/maintenance_reports.php, the same file the Create Report and Edit
     * Report card grids render from, so a category can never be offered by the
     * UI but rejected by the backend (or vice versa). This is the server-side
     * enforcement the brief requires: the card grid is convenience only, and a
     * hand-crafted POST naming 'Nuclear' is rejected here regardless of what
     * the browser sent.
     *
     * required_if is what makes the free-text field conditionally mandatory
     * server-side, matching the client-side rule rather than trusting it. Note
     * it is only ever REQUIRED — never merely allowed — for 'Other'; a custom
     * value sent alongside 'Electrical' passes validation but is then
     * discarded by ReportService::resolveProblemTypeOther(), so it cannot be
     * persisted against a predefined category.
     */
    private function problemTypeRules(bool $isUpdate): array
    {
        $presence = $isUpdate ? 'sometimes' : 'required';
        $otherValue = (string) config('maintenance_reports.problem_type_other_value', 'Other');
        $otherMax = (int) config('maintenance_reports.problem_type_other_max', 100);

        return [
            'problem_type' => [
                $presence,
                'string',
                Rule::in(array_column((array) config('maintenance_reports.problem_types', []), 'value')),
            ],
            'problem_type_other' => [
                'nullable',
                'string',
                'max:' . $otherMax,
                'required_if:problem_type,' . $otherValue,
            ],
        ];
    }

    /**
     * The two messages the brief specifies verbatim. Defined beside the rules
     * so the wording the user sees is not restated in each page's JavaScript
     * as the authoritative copy — the frontend shows the same strings for a
     * fast local check, but these are what an API caller actually receives.
     */
    private function problemTypeMessages(): array
    {
        return [
            'problem_type.required' => 'Please select a problem type.',
            'problem_type.in' => 'Please select a problem type.',
            'problem_type_other.required_if' => 'Please specify the problem type.',
        ];
    }

    /**
     * Stays in the controller: this is the role normalization used by the
     * read-scoping and per-case permission checks in this class, all of which
     * remain HTTP-boundary decisions. It has always delegated to
     * RoleNormalizerService, which is still the only place role aliases are
     * resolved.
     */
    private function normalizeRole(string $role): string
    {
        return RoleNormalizerService::normalizeWithStaffDefault($role);
    }
}
