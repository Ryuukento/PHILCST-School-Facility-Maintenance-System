<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceReport;
use App\Models\SchoolSetting;
use App\Services\RoleNormalizerService;
use App\Services\TechnicianWorkloadService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponder;

    // -------------------------------------------------------------------------
    // General dashboard stats (used by main dashboard page)
    // -------------------------------------------------------------------------

    public function stats(Request $request): JsonResponse
    {
        $authUser = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        $userId   = (int) ($authUser['user_id'] ?? 0);
        $role     = $this->normalizeRole((string) ($authUser['role'] ?? ''));

        $query = MaintenanceReport::query();
        if (!in_array($role, ['super_admin', 'maintenance_admin'], true)) {
            $query->where(function ($builder) use ($userId): void {
                $builder->where('created_by', $userId)->orWhere('assigned_to', $userId);
            });
        }

        // TASK 16 / 25.2 — Semester-based KPI scoping.
        //
        // "Total reports / Pending / In progress / Completed" must reflect
        // only the CURRENT semester, not every report ever created. The
        // active semester is derived automatically from an Administrator-
        // configured schedule (school_year + 4 semester dates — see
        // SchoolSetting model), never hand-picked.
        //
        // TASK 25.2 — Enterprise Semester Lifecycle: these 4 KPI numbers are
        // only ever computed when a semester is LITERALLY running right now
        // ($schoolSettings->isActive()). If today falls outside every
        // configured range (Upcoming First Semester / Semester Break /
        // School Year Completed), showing a number scoped to a stale or
        // nonexistent "current" semester would be misleading — so instead
        // these 4 fields are returned as `null` and the frontend renders
        // "No Active Semester" using `semester_active` / `semester_status`.
        // `reports_today` and `low_stock` are NOT semester-scoped and are
        // completely unaffected either way. This never reads or writes
        // maintenance_reports.* beyond an ordinary WHERE clause, and never
        // modifies report history, IDs, audit logs, or dates — historical
        // reports remain fully accessible via /api/reports regardless.
        $schoolSettings = SchoolSetting::current();
        $semesterActive = $schoolSettings->isActive();

        if ($semesterActive) {
            $semesterScoped = clone $query;
            $semesterScoped->where('created_at', '>=', $schoolSettings->semester_started_at);

            $stats = $semesterScoped
                ->selectRaw('COUNT(*) as total_reports')
                ->selectRaw("SUM(CASE WHEN status IN ('submitted', 'assigned') THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->first();

            $totalReports = (int) ($stats->total_reports ?? 0);
            $pending      = (int) ($stats->pending       ?? 0);
            $inProgress   = (int) ($stats->in_progress   ?? 0);
            $completed    = (int) ($stats->completed     ?? 0);
        } else {
            // No active semester — do NOT guess/compute misleading numbers.
            $totalReports = null;
            $pending      = null;
            $inProgress   = null;
            $completed    = null;
        }

        // "Reports today" is intentionally NOT semester-scoped — it is
        // already a same-day count, unaffected by lifetime accumulation.
        $reportsToday = (clone $query)
            ->selectRaw("SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as reports_today")
            ->first();

        // TASK 5 — "Low stock items" must count ONLY items classified as
        // low_stock. This previously counted low_stock + out_of_stock under a
        // label that says "Low stock items", which contradicted both the
        // Inventory page (4 low / 1 out, shown as separate badges) and
        // Analytics → Inventory Health (LOW STOCK 4 / OUT OF STOCK 1), and
        // disagreed with this endpoint's own drill-through link below
        // (/api/items?status=low_stock — low stock only).
        //
        // Classification is InventoryStatusService::deriveStatus()'s canonical
        // items.status column (out_of_stock when qty <= 0, low_stock when
        // qty <= reorder_level) — the same single source of truth the
        // Inventory page and AnalyticsService already read. No threshold is
        // recomputed or assumed here.
        //
        // Scoped to item_type='inventory_stock' to match the population the
        // Inventory module itself reports on (/api/inventory-stock filters on
        // exactly this). room_asset rows are deployed fixed assets, not
        // replenishable warehouse stock, so they can never be "low stock".
        $lowStock = DB::table('items')
            ->where('item_type', 'inventory_stock')
            ->where('status', 'low_stock')
            ->count();

        return $this->ok('Dashboard stats retrieved', [
            'total_reports' => $totalReports,
            'reports_today' => (int) ($reportsToday->reports_today ?? 0),
            'pending'       => $pending,
            'in_progress'   => $inProgress,
            'completed'     => $completed,
            'low_stock'     => (int) $lowStock,
            'school_year'      => $schoolSettings->school_year,
            'current_semester' => $schoolSettings->current_semester,
            // TASK 25.2 — the Dashboard must never display an incorrect
            // active semester; these 3 fields are what let it show the
            // exact lifecycle state instead of guessing.
            'semester_active'           => $semesterActive,
            'semester_status'           => $schoolSettings->semesterStatus(),
            'semester_days_until_start' => $schoolSettings->daysUntilFirstSemesterStart(),
            // TASK 25 — included so the dashboard can render "Semester
            // Duration" (the active semester's date range) without a
            // second request. Purely additive; no existing key changed.
            'first_sem_start'  => optional($schoolSettings->first_sem_start)->toDateString(),
            'first_sem_end'    => optional($schoolSettings->first_sem_end)->toDateString(),
            'second_sem_start' => optional($schoolSettings->second_sem_start)->toDateString(),
            'second_sem_end'   => optional($schoolSettings->second_sem_end)->toDateString(),
            // ISS-01 — the EXACT lower bound this method just used to scope
            // total_reports / pending / in_progress / completed (the
            // `created_at >= semester_started_at` clause above). Exposed so
            // the Dashboard can state that boundary verbatim when those four
            // KPIs legitimately come back 0 while older reports still exist,
            // instead of the frontend re-deriving it from first/second_sem_start
            // and risking a different date than the one actually queried.
            // Purely additive and read-only; no existing key, query, or scope
            // changed, and it is `null` whenever no semester is running (in
            // which case the 4 KPIs are already `null` too).
            'semester_started_at' => $semesterActive
                ? optional($schoolSettings->semester_started_at)->toDateString()
                : null,
            'links'         => [
                'total_reports' => '/api/reports',
                'reports_today' => '/api/reports?date=today',
                'pending'       => '/api/reports?status=submitted',
                'in_progress'   => '/api/reports?status=in_progress',
                'completed'     => '/api/reports?status=completed',
                'low_stock'     => '/api/items?status=low_stock',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Maintenance dashboard — stats (GET /api/dashboard/maintenance/stats)
    // -------------------------------------------------------------------------

    /**
     * Role-aware monthly stats for the maintenance dashboard.
     *
     * Sprint 2 / Feature 1 (Role-Differentiated Dashboard): this previously
     * bucketed super_admin, maintenance_admin, AND maintenance_staff together
     * into one system-wide "isAdmin" branch. It now has 3 branches:
     *   - super_admin: system-wide (unchanged from before).
     *   - maintenance_admin: department-scoped (NEW), via resolveDepartmentId(),
     *     the same helper pattern established in ReportController for report
     *     listing (Sprint 1 / Feature 2). Falls back to system-wide if the
     *     admin has no department_id assigned, mirroring that same feature's
     *     fallback rule.
     *   - everyone else (maintenance_staff included): per-user filtered view,
     *     using the SQL shape that already existed here but was previously
     *     unreachable for staff.
     *
     * Query params: ?year=, ?month=
     * Response (wrapped): { total_reports, pending, in_progress,
     *   completed_this_month, overdue, avg_completion_days, buildings_overview,
     *   pending_assignments }
     */
    public function maintenanceStats(Request $request): JsonResponse
    {
        $authUser     = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        $userId       = (int) ($authUser['user_id'] ?? 0);
        $role         = $this->normalizeRole((string) ($authUser['role'] ?? ''));
        $departmentId = $role === 'maintenance_admin' ? $this->resolveDepartmentId($userId) : null;
        $isSystemWide = $role === 'super_admin' || ($role === 'maintenance_admin' && $departmentId === null);
        $isDeptScoped = $role === 'maintenance_admin' && $departmentId !== null;

        $year     = max(2000, (int) $request->query('year',  date('Y')));
        $month    = max(1, min(12, (int) $request->query('month', date('n'))));
        // TASK 53 — see monthDateBounds() doc comment: $dateTo must include a
        // time component so it correctly bounds the DATETIME `created_at`
        // column for the entire last calendar day of the month, not just its
        // midnight instant.
        // $dueDateCutoff is deliberately the bare last day, NOT $dateTo — see
        // monthDateBounds()'s doc comment for why the overdue KPI's strict
        // `due_date < ?` test must not receive the '23:59:59' DATETIME bound.
        [$dateFrom, $dateTo, $dueDateCutoff] = self::monthDateBounds($year, $month);

        if ($isSystemWide || $isDeptScoped) {
            $sql = "SELECT
                        COUNT(*) as total_reports,
                        COUNT(CASE WHEN status = 'submitted'  AND created_at   >= ? AND created_at   <= ? THEN report_id END) as pending,
                        COUNT(CASE WHEN status = 'in_progress' AND created_at  >= ? AND created_at   <= ? THEN report_id END) as in_progress,
                        COUNT(CASE WHEN status = 'completed'  AND completed_date >= ? AND completed_date <= ? THEN report_id END) as completed_this_month,
                        COUNT(CASE WHEN due_date < ? AND status NOT IN ('completed','closed') AND created_at >= ? AND created_at <= ? THEN report_id END) as overdue,
                        AVG(CASE WHEN status IN ('completed','closed') AND created_at >= ? AND created_at <= ?
                            THEN TIMESTAMPDIFF(DAY, created_at, COALESCE(completed_date, updated_at)) END) as avg_completion_days,
                        (SELECT COUNT(*) FROM buildings) as buildings_overview,
                        COUNT(CASE WHEN status = 'submitted' AND assigned_to IS NULL THEN report_id END) as pending_assignments
                    FROM maintenance_reports
                    WHERE created_at >= ? AND created_at <= ?";

            $params = [
                $dateFrom, $dateTo,         // pending
                $dateFrom, $dateTo,         // in_progress
                $dateFrom, $dateTo,         // completed_this_month
                $dueDateCutoff, $dateFrom, $dateTo, // overdue
                $dateFrom, $dateTo,         // avg_completion_days
                $dateFrom, $dateTo,         // WHERE
            ];

            if ($isDeptScoped) {
                $sql .= ' AND department_id = ?';
                $params[] = $departmentId;
            }
        } else {
            $sql = "SELECT
                        COUNT(DISTINCT CASE WHEN created_by = ? AND created_at >= ? AND created_at <= ? THEN report_id END) as total_reports,
                        COUNT(DISTINCT CASE WHEN status = 'submitted'   AND assigned_to = ? AND created_at >= ? AND created_at <= ? THEN report_id END) as pending,
                        COUNT(DISTINCT CASE WHEN status = 'in_progress' AND assigned_to = ? AND created_at >= ? AND created_at <= ? THEN report_id END) as in_progress,
                        COUNT(DISTINCT CASE WHEN status = 'completed'   AND completed_date >= ? AND completed_date <= ? AND assigned_to = ? THEN report_id END) as completed_this_month,
                        COUNT(DISTINCT CASE WHEN due_date < ? AND status NOT IN ('completed','closed') AND assigned_to = ? AND created_at >= ? AND created_at <= ? THEN report_id END) as overdue,
                        AVG(CASE WHEN status IN ('completed','closed') AND created_at >= ? AND created_at <= ?
                            THEN TIMESTAMPDIFF(DAY, created_at, COALESCE(completed_date, updated_at)) END) as avg_completion_days,
                        (SELECT COUNT(*) FROM buildings) as buildings_overview
                    FROM maintenance_reports
                    WHERE (created_by = ? OR assigned_to = ?) AND created_at >= ? AND created_at <= ?";

            $params = [
                $userId, $dateFrom, $dateTo,       // total_reports
                $userId, $dateFrom, $dateTo,       // pending
                $userId, $dateFrom, $dateTo,       // in_progress
                $dateFrom, $dateTo, $userId,       // completed_this_month
                $dueDateCutoff, $userId, $dateFrom, $dateTo, // overdue
                $dateFrom, $dateTo,                // avg_completion_days
                $userId, $userId, $dateFrom, $dateTo, // WHERE
            ];
        }

        $row = DB::selectOne($sql, $params);

        return $this->ok('Dashboard stats retrieved', [
            'total_reports'       => (int)   ($row->total_reports        ?? 0),
            'pending'             => (int)   ($row->pending              ?? 0),
            'in_progress'         => (int)   ($row->in_progress          ?? 0),
            'completed_this_month'=> (int)   ($row->completed_this_month ?? 0),
            'overdue'             => (int)   ($row->overdue              ?? 0),
            'avg_completion_days' => round((float) ($row->avg_completion_days ?? 0), 1),
            'buildings_overview'  => (int)   ($row->buildings_overview   ?? 0),
            'pending_assignments' => (int)   ($row->pending_assignments  ?? 0),
        ]);
    }

    // -------------------------------------------------------------------------
    // Maintenance dashboard — charts (GET /api/dashboard/maintenance/charts)
    // Also used by the staff dashboard.
    // -------------------------------------------------------------------------

    /**
     * Status distribution, priority distribution, and 6-month trend charts.
     *
     * Sprint 2 / Feature 1: same 3-way role branch as maintenanceStats() —
     * super_admin (system-wide), maintenance_admin (department-scoped, NEW,
     * falls back to system-wide with no department_id), everyone else
     * (personal-scoped, pre-existing SQL shape, now reachable for staff).
     *
     * Query params: ?year=, ?month=
     * Response (wrapped): { status_data: {labels, values},
     *   priority_data: {labels, values}, trend_data: {labels, created, completed} }
     */
    public function maintenanceCharts(Request $request): JsonResponse
    {
        $authUser     = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        $userId       = (int) ($authUser['user_id'] ?? 0);
        $role         = $this->normalizeRole((string) ($authUser['role'] ?? ''));
        $departmentId = $role === 'maintenance_admin' ? $this->resolveDepartmentId($userId) : null;
        $isSystemWide = $role === 'super_admin' || ($role === 'maintenance_admin' && $departmentId === null);
        $isDeptScoped = $role === 'maintenance_admin' && $departmentId !== null;

        $year     = max(2000, (int) $request->query('year',  date('Y')));
        $month    = max(1, min(12, (int) $request->query('month', date('n'))));
        // TASK 53 — see monthDateBounds() doc comment: $dateTo must include a
        // time component so it correctly bounds the DATETIME `created_at`
        // column for the entire last calendar day of the month, not just its
        // midnight instant.
        [$dateFrom, $dateTo] = self::monthDateBounds($year, $month);

        // -- Status distribution --
        $statusSql    = "SELECT
                            CASE WHEN status IS NULL OR TRIM(status) = '' THEN 'submitted' ELSE status END AS normalized_status,
                            COUNT(*) as count
                         FROM maintenance_reports
                         WHERE created_at >= ? AND created_at <= ?";
        $statusParams = [$dateFrom, $dateTo];
        if ($isDeptScoped) {
            $statusSql    .= ' AND department_id = ?';
            $statusParams[] = $departmentId;
        } elseif (!$isSystemWide) {
            $statusSql    .= ' AND (created_by = ? OR assigned_to = ?)';
            $statusParams[] = $userId;
            $statusParams[] = $userId;
        }
        $statusSql .= ' GROUP BY normalized_status ORDER BY count DESC';

        $statusRows    = DB::select($statusSql, $statusParams);
        $statusLabels  = [];
        $statusValues  = [];
        foreach ($statusRows as $row) {
            $statusLabels[] = ucfirst(str_replace('_', ' ', $row->normalized_status));
            $statusValues[] = (int) $row->count;
        }

        // -- Priority distribution --
        $prioritySql    = "SELECT priority, COUNT(*) as count
                           FROM maintenance_reports
                           WHERE created_at >= ? AND created_at <= ?";
        $priorityParams = [$dateFrom, $dateTo];
        if ($isDeptScoped) {
            $prioritySql    .= ' AND department_id = ?';
            $priorityParams[] = $departmentId;
        } elseif (!$isSystemWide) {
            $prioritySql    .= ' AND (created_by = ? OR assigned_to = ?)';
            $priorityParams[] = $userId;
            $priorityParams[] = $userId;
        }
        $prioritySql .= " GROUP BY priority ORDER BY FIELD(priority,'low','medium','high','urgent','critical')";

        $priorityRows   = DB::select($prioritySql, $priorityParams);
        $priorityLabels = [];
        $priorityValues = [];
        foreach ($priorityRows as $row) {
            $priorityLabels[] = ucfirst((string) ($row->priority ?? ''));
            $priorityValues[] = (int) $row->count;
        }

        // -- 6-month trend (single grouped query) --
        // GROUP BY both the sort key and the display label so MySQL ONLY_FULL_GROUP_BY
        // mode doesn't reject a SELECT expression that differs from the GROUP BY expression.
        $trendSql    = "SELECT
                            DATE_FORMAT(created_at, '%b %y') as month,
                            COUNT(*) as created,
                            SUM(CASE WHEN status IN ('completed','closed') THEN 1 ELSE 0 END) as completed
                        FROM maintenance_reports
                        WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)";
        $trendParams = [];
        if ($isDeptScoped) {
            $trendSql    .= ' AND department_id = ?';
            $trendParams[] = $departmentId;
        } elseif (!$isSystemWide) {
            $trendSql    .= ' AND (created_by = ? OR assigned_to = ?)';
            $trendParams[] = $userId;
            $trendParams[] = $userId;
        }
        $trendSql .= " GROUP BY DATE_FORMAT(created_at,'%Y-%m'), DATE_FORMAT(created_at, '%b %y') ORDER BY MIN(created_at)";

        $trendRows      = DB::select($trendSql, $trendParams);
        $trendLabels    = [];
        $trendCreated   = [];
        $trendCompleted = [];
        foreach ($trendRows as $row) {
            $trendLabels[]    = $row->month;
            $trendCreated[]   = (int) $row->created;
            $trendCompleted[] = (int) $row->completed;
        }

        return $this->ok('Chart data retrieved', [
            'status_data'   => ['labels' => $statusLabels,  'values' => $statusValues],
            'priority_data' => ['labels' => $priorityLabels, 'values' => $priorityValues],
            'trend_data'    => ['labels' => $trendLabels, 'created' => $trendCreated, 'completed' => $trendCompleted],
        ]);
    }

    // -------------------------------------------------------------------------
    // Super-admin dashboard (flat response — frontend reads data.key directly)
    // -------------------------------------------------------------------------

    /**
     * GET /api/dashboard/super-admin/stats
     * System-wide counts + role/status breakdowns for selected month.
     * Query params: ?year=, ?month=
     * Response FLAT (no .data wrapper): { success, totalUsers, totalReports, ... }
     */
    public function superAdminStats(Request $request): JsonResponse
    {
        $year  = max(2000, (int) $request->query('year',  date('Y')));
        $month = max(1, min(12, (int) $request->query('month', date('n'))));

        // Total active users
        $totalUsers = DB::table('users')->where('status', 'active')->count();

        // Role breakdown (active users)
        $roleRows     = DB::table('users')
            ->selectRaw('role, COUNT(*) as count')
            ->where('status', 'active')
            ->groupBy('role')
            ->get();
        $roleBreakdown = [];
        foreach ($roleRows as $row) {
            $roleBreakdown[$row->role] = (int) $row->count;
        }

        // Monthly-scoped report counts
        $totalReports = DB::table('maintenance_reports')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->count();

        $statusRows = DB::table('maintenance_reports')
            ->selectRaw('status, COUNT(*) as count')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->groupBy('status')
            ->get();
        $statusBreakdown = [];
        foreach ($statusRows as $row) {
            $statusBreakdown[$row->status] = (int) $row->count;
        }

        $completedThisMonth = DB::table('maintenance_reports')
            ->where('status', 'completed')
            ->whereMonth('completed_date', $month)
            ->whereYear('completed_date', $year)
            ->count();

        $pendingReports = DB::table('maintenance_reports')
            ->whereIn('status', ['submitted', 'assigned'])
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->count();

        $overdueReports = DB::table('maintenance_reports')
            ->where('due_date', '<', date('Y-m-d'))
            ->whereNotIn('status', ['completed', 'closed', 'cancelled'])
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->count();

        $inProgressReports = DB::table('maintenance_reports')
            ->where('status', 'in_progress')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->count();

        $totalDepartments = DB::table('departments')->count();
        $totalBuildings = DB::table('buildings')->count();

        return response()->json([
            'success'            => true,
            'totalUsers'         => (int) $totalUsers,
            'totalReports'       => (int) $totalReports,
            'totalDepartments'   => (int) $totalDepartments,
            'buildingsOverview'  => (int) $totalBuildings,
            'completedThisMonth' => (int) $completedThisMonth,
            'pendingReports'     => (int) $pendingReports,
            'overdueReports'     => (int) $overdueReports,
            'inProgressReports'  => (int) $inProgressReports,
            'roleBreakdown'      => $roleBreakdown,
            'statusBreakdown'    => $statusBreakdown,
        ]);
    }

    /**
     * GET /api/dashboard/super-admin/charts
     * Status, priority, department, and trend charts for selected month.
     * Query params: ?year=, ?month=
     * Response FLAT: { success, statusChart, priorityChart, departmentChart, trendChart }
     */
    public function superAdminCharts(Request $request): JsonResponse
    {
        $year  = max(2000, (int) $request->query('year',  date('Y')));
        $month = max(1, min(12, (int) $request->query('month', date('n'))));

        $statusColors   = self::statusColorMap();
        $priorityColors = self::priorityColorMap();

        // Status chart
        $statusRows    = DB::table('maintenance_reports')
            ->selectRaw('status, COUNT(*) as count')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->groupBy('status')
            ->get();
        $statusLabels  = [];
        $statusCounts  = [];
        foreach ($statusRows as $row) {
            $statusLabels[] = ucfirst((string) ($row->status ?? ''));
            $statusCounts[] = (int) $row->count;
        }

        // Priority chart
        $priorityRows   = DB::select(
            "SELECT priority, COUNT(*) as count
             FROM maintenance_reports
             WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?
             GROUP BY priority
             ORDER BY FIELD(priority,'critical','urgent','high','medium','low')",
            [$month, $year]
        );
        $priorityLabels = [];
        $priorityCounts = [];
        foreach ($priorityRows as $row) {
            $priorityLabels[] = ucfirst((string) ($row->priority ?? ''));
            $priorityCounts[] = (int) $row->count;
        }

        // Department chart (all-time, top 6)
        $deptRows   = DB::table('departments as d')
            ->selectRaw('d.name, COUNT(mr.report_id) as count')
            ->leftJoin('maintenance_reports as mr', 'd.department_id', '=', 'mr.department_id')
            ->groupBy('d.department_id', 'd.name')
            ->orderByDesc('count')
            ->limit(6)
            ->get();
        $deptLabels = [];
        $deptCounts = [];
        foreach ($deptRows as $row) {
            $deptLabels[] = $row->name;
            $deptCounts[] = (int) $row->count;
        }

        // Trend chart (last 6 months)
        // GROUP BY both the sort key and the display label so MySQL strict-mode
        // (ONLY_FULL_GROUP_BY) doesn't reject a SELECT expression that differs
        // from the GROUP BY expression.
        $trendRows   = DB::select(
            "SELECT DATE_FORMAT(created_at, '%b %Y') as month, COUNT(*) as count
             FROM maintenance_reports
             WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
             ORDER BY MIN(created_at)"
        );
        $trendLabels = [];
        $trendCounts = [];
        foreach ($trendRows as $row) {
            $trendLabels[] = $row->month;
            $trendCounts[] = (int) $row->count;
        }

        return response()->json([
            'success'         => true,
            'statusChart'     => [
                'labels' => $statusLabels,
                'data'   => $statusCounts,
                'colors' => array_map(
                    static fn (string $label): string => self::colorForLabel($label, $statusColors, true),
                    $statusLabels
                ),
            ],
            'priorityChart'   => [
                'labels' => $priorityLabels,
                'data'   => $priorityCounts,
                'colors' => array_map(
                    static fn (string $label): string => self::colorForLabel($label, $priorityColors, false),
                    $priorityLabels
                ),
            ],
            'departmentChart' => [
                'labels' => $deptLabels,
                'data'   => $deptCounts,
            ],
            'trendChart'      => [
                'labels' => $trendLabels,
                'data'   => $trendCounts,
            ],
        ]);
    }

    /**
     * GET /api/dashboard/super-admin/overview
     * Users-per-department and most-active maintenance staff.
     * Response FLAT: { success, departmentUsers, activeStaff }
     */
    public function superAdminOverview(Request $request): JsonResponse
    {
        $departmentUsers = DB::table('departments as d')
            ->selectRaw('d.name, COUNT(u.user_id) as count')
            ->leftJoin('users as u', function ($join) {
                $join->on('d.department_id', '=', 'u.department_id')
                     ->where('u.status', '=', 'active');
            })
            ->groupBy('d.department_id', 'd.name')
            ->orderByDesc('count')
            ->get();

        $activeStaff = DB::table('users as u')
            ->selectRaw('u.full_name, COUNT(mr.report_id) as assigned_count')
            ->leftJoin('maintenance_reports as mr', 'u.user_id', '=', 'mr.assigned_to')
            ->where('u.role', 'maintenance_staff')
            ->where('u.status', 'active')
            ->groupBy('u.user_id', 'u.full_name')
            ->orderByDesc('assigned_count')
            ->limit(5)
            ->get();

        return response()->json([
            'success'         => true,
            'departmentUsers' => $departmentUsers,
            'activeStaff'     => $activeStaff,
        ]);
    }

    /**
     * GET /api/dashboard/super-admin/activity
     * Last 10 activity log entries with user name.
     * Response FLAT: { success, activities }
     */
    public function superAdminActivity(Request $request): JsonResponse
    {
        $activities = DB::table('activity_logs as al')
            ->select([
                'al.id',
                'al.action',
                'al.entity_type',
                'al.entity_id',
                'al.details',
                'al.created_at',
                'u.full_name',
            ])
            ->leftJoin('users as u', 'al.user_id', '=', 'u.user_id')
            ->orderByDesc('al.created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success'    => true,
            'activities' => $activities,
        ]);
    }

    // -------------------------------------------------------------------------
    // Maintenance dashboard — personnel & activity (Sprint 2 / Feature 1)
    // Role-appropriate variants of superAdminOverview()/superAdminActivity(),
    // reusing the same query shapes rather than duplicating a new pattern.
    // -------------------------------------------------------------------------

    /**
     * GET /api/dashboard/maintenance/personnel
     * "Assigned Personnel" widget for the Head Maintenance dashboard.
     *
     * - maintenance_admin (with a department): active maintenance_staff in
     *   the same department, with their assigned-report counts — the same
     *   query shape as superAdminOverview()'s "activeStaff", scoped down to
     *   one department instead of system-wide.
     * - super_admin: delegates to the existing system-wide "activeStaff"
     *   list from superAdminOverview() so the same data isn't computed twice.
     * - Anyone else (maintenance_staff, etc.): empty list — this widget is
     *   not part of the Staff dashboard's required widget set.
     *
     * Response FLAT: { success, personnel }
     */
    public function maintenancePersonnel(Request $request): JsonResponse
    {
        $authUser     = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        $userId       = (int) ($authUser['user_id'] ?? 0);
        $role         = $this->normalizeRole((string) ($authUser['role'] ?? ''));
        $departmentId = $role === 'maintenance_admin' ? $this->resolveDepartmentId($userId) : null;

        if ($role === 'super_admin') {
            $overview = $this->superAdminOverview($request)->getData(true);

            return response()->json([
                'success'   => true,
                'personnel' => $overview['activeStaff'] ?? [],
            ]);
        }

        if ($role !== 'maintenance_admin' || $departmentId === null) {
            return response()->json([
                'success'   => true,
                'personnel' => [],
            ]);
        }

        $personnel = DB::table('users as u')
            ->selectRaw('u.full_name, COUNT(mr.report_id) as assigned_count')
            ->leftJoin('maintenance_reports as mr', 'u.user_id', '=', 'mr.assigned_to')
            ->where('u.role', 'maintenance_staff')
            ->where('u.status', 'active')
            ->where('u.department_id', $departmentId)
            ->groupBy('u.user_id', 'u.full_name')
            ->orderByDesc('assigned_count')
            ->limit(5)
            ->get();

        return response()->json([
            'success'   => true,
            'personnel' => $personnel,
        ]);
    }

    /**
     * GET /api/dashboard/technician-workload
     *
     * TASK — Technician Workload card (Administrator + Head Maintenance
     * dashboards). READ-ONLY: returns counts only. It creates no assignment
     * capability and touches no permission.
     *
     * Authorization is NOT re-implemented here. The route itself carries the
     * existing centralized middleware —
     * EnsureRole::class . ':super_admin,maintenance_admin' — which is the same
     * gate already used for every other role-restricted endpoint in
     * routes/web.php and which normalizes the session role through
     * RoleNormalizerService. Maintenance Staff therefore get a 403 from the
     * middleware before this method runs; there is no second role check in
     * this body to drift away from it.
     *
     * Data scope mirrors the authority each role already has elsewhere:
     *   - super_admin       : system-wide, exactly like superAdminOverview().
     *   - maintenance_admin : their own department, exactly like
     *                         maintenancePersonnel() and
     *                         ReportAuthorizationService's department rule.
     * A maintenance_admin with no department_id gets an empty list rather than
     * silently falling through to a system-wide view.
     *
     * Unlike the two pre-existing personnel widgets this does NOT limit(5)
     * and does not drop zero-workload technicians — the brief requires the
     * full roster, including idle technicians, since "who is free" is half
     * the question the card exists to answer.
     *
     * Response FLAT: { success, scope, technicians: [{ user_id, full_name,
     *                  active_count, completed_count, workload_percent }] }
     */
    public function technicianWorkload(Request $request, TechnicianWorkloadService $workloadService): JsonResponse
    {
        $authUser = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        $userId   = (int) ($authUser['user_id'] ?? 0);
        $role     = $this->normalizeRole((string) ($authUser['role'] ?? ''));

        if ($role === 'super_admin') {
            return response()->json([
                'success'     => true,
                'scope'       => 'system',
                'technicians' => $workloadService->workload(null),
            ]);
        }

        $departmentId = $this->resolveDepartmentId($userId);
        if ($departmentId === null) {
            return response()->json([
                'success'     => true,
                'scope'       => 'department',
                'technicians' => [],
            ]);
        }

        return response()->json([
            'success'     => true,
            'scope'       => 'department',
            'technicians' => $workloadService->workload($departmentId),
        ]);
    }

    /**
     * GET /api/dashboard/maintenance/activity
     * "Recent Department Activity" (Head Maintenance) / "Personal Activity
     * Timeline" (Maintenance Staff) widget.
     *
     * - super_admin: delegates directly to the existing superAdminActivity()
     *   (system-wide, last 10) — zero duplication for this branch.
     * - maintenance_admin (with a department): last 10 activity_logs entries
     *   from users in the same department — the same query shape as
     *   superAdminActivity(), with a department filter added via the join.
     * - Everyone else (maintenance_staff, etc.): last 10 activity_logs
     *   entries belonging to that user only.
     *
     * Response FLAT: { success, activities }
     */
    public function maintenanceActivity(Request $request): JsonResponse
    {
        $authUser     = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        $userId       = (int) ($authUser['user_id'] ?? 0);
        $role         = $this->normalizeRole((string) ($authUser['role'] ?? ''));
        $departmentId = $role === 'maintenance_admin' ? $this->resolveDepartmentId($userId) : null;

        if ($role === 'super_admin') {
            return $this->superAdminActivity($request);
        }

        $query = DB::table('activity_logs as al')
            ->select([
                'al.id',
                'al.action',
                'al.entity_type',
                'al.entity_id',
                'al.details',
                'al.created_at',
                'u.full_name',
            ])
            ->leftJoin('users as u', 'al.user_id', '=', 'u.user_id');

        if ($role === 'maintenance_admin' && $departmentId !== null) {
            $query->where('u.department_id', $departmentId);
        } else {
            $query->where('al.user_id', $userId);
        }

        $activities = $query
            ->orderByDesc('al.created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success'    => true,
            'activities' => $activities,
        ]);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function normalizeRole(string $role): string
    {
        return RoleNormalizerService::normalizeWithStaffDefault($role);
    }

    /**
     * TASK 53 — extracted from maintenanceStats()/maintenanceCharts(), which
     * both independently built an identical [$dateFrom, $dateTo] pair for
     * "the selected month" and used it to bound the DATETIME `created_at`
     * column via raw `created_at >= ? AND created_at <= ?`.
     *
     * $dateTo previously had no time component (e.g. '2026-02-28'), which —
     * under both MySQL (implicit cast to '2026-02-28 00:00:00') and SQLite
     * (lexicographic string comparison, where any non-empty time suffix
     * sorts greater than the bare date) — meant `created_at <= $dateTo` only
     * matched rows created at exactly midnight on the last day of the month.
     * Any report created later that day (the overwhelming majority) was
     * silently excluded from every one of these methods' KPIs and from the
     * "Reports by Status"/"Reports by Priority" charts, on precisely the
     * last day of any selected month. superAdminStats()/superAdminCharts()
     * never had this bug — they use whereMonth()/whereYear(), which are
     * boundary-safe regardless of time-of-day.
     *
     * Appending '23:59:59' to $dateTo fixes this for both DB drivers without
     * changing $dateFrom, the SQL shape, or any caller's parameter order.
     *
     * The third element, $dueDateCutoff, is the SAME last day WITHOUT the time
     * component, and exists because `due_date`/`completed_date` are DATE
     * columns, not DATETIME. maintenanceStats()'s overdue KPI tests
     * `due_date < ?`, a STRICT less-than meaning "due strictly before the end
     * of the period". Feeding it the '23:59:59' bound would silently relax
     * that to `<=` the last calendar day, flagging a report that is due ON the
     * last day (i.e. due today, not yet late) as already overdue. So the
     * created_at bounds must carry the time component and this one must not —
     * they are deliberately different values, not a copy-paste slip.
     *
     * @return array{0: string, 1: string, 2: string} [$dateFrom, $dateTo, $dueDateCutoff]
     */
    private static function monthDateBounds(int $year, int $month): array
    {
        $dateFrom      = sprintf('%04d-%02d-01', $year, $month);
        $lastDay       = date('Y-m-d', strtotime("$dateFrom +1 month -1 day"));
        $dateTo        = $lastDay . ' 23:59:59';

        return [$dateFrom, $dateTo, $lastDay];
    }

    /**
     * TASK 53 — the chart color map for maintenance_reports.status.
     *
     * 'closed' is one of the 6 canonical status values (see
     * 2026_07_28_000800_add_assigned_status_to_maintenance_reports_table) but
     * was missing from this map, so a 'Closed' doughnut segment silently fell
     * back to the generic gray ('#6b7280') used for genuinely unrecognized
     * values, instead of getting a distinct color like every other status.
     *
     * Extracted from superAdminCharts() so the map itself is unit-testable —
     * see DashboardControllerHelpersTest.
     *
     * @return array<string, string>
     */
    private static function statusColorMap(): array
    {
        return [
            'submitted'   => '#3b82f6',
            'assigned'    => '#8b5cf6',
            'in_progress' => '#f59e0b',
            'completed'   => '#10b981',
            'closed'      => '#6b7280',
            'cancelled'   => '#ef4444',
        ];
    }

    /**
     * TASK 53 — the chart color map for maintenance_reports.priority.
     *
     * 'critical' is a valid priority value (the same value Task 46 added
     * frontend display support for in UI.getPriorityBadge()) but was missing
     * from this map, so a 'Critical' bar silently fell back to the generic
     * gray ('#6b7280') rather than a color more alarming than 'urgent'.
     * 'critical' was also missing from superAdminCharts()'s
     * ORDER BY FIELD(...) list, where MySQL's FIELD() returns 0 for an
     * unlisted value — sorting 'critical' to the very front of the
     * (implicitly ascending) result set, ahead of 'urgent'.
     *
     * @return array<string, string>
     */
    private static function priorityColorMap(): array
    {
        // IT-expert priority-color correction: Critical=Red, High=Orange,
        // Medium=Yellow, Low=Blue (standardized app-wide).
        return [
            'critical' => '#991b1b',
            'urgent'   => '#ef4444',
            'high'     => '#f97316',
            'medium'   => '#eab308',
            'low'      => '#3b82f6',
        ];
    }

    /**
     * TASK 53 — extracted from superAdminCharts()'s two inline array_map()
     * closures (identical shape, only the lookup table and label-normalization
     * rule differed) so the label->color lookup is independently unit-testable
     * without needing a DB connection or MySQL-only SQL (superAdminCharts()'s
     * priority query uses MONTH()/YEAR()/FIELD(), none of which SQLite
     * supports, so this logic could not otherwise be exercised under this
     * project's SQLite-backed Feature test harness).
     *
     * $normalizeSpaces controls whether spaces in the label are folded to
     * underscores before lookup — status labels are ucfirst()'d from
     * snake_case ('In_progress' has no space, but this mirrors the original
     * status closure's `str_replace(' ', '_', ...)` defensively) while
     * priority labels never contained underscores/spaces to begin with.
     */
    private static function colorForLabel(string $label, array $colorMap, bool $normalizeSpaces): string
    {
        $key = strtolower($normalizeSpaces ? str_replace(' ', '_', $label) : $label);

        return $colorMap[$key] ?? '#6b7280';
    }

    /**
     * Resolve a user's department_id, or null if unset.
     * Mirrors ReportController::resolveUserDepartmentId() (Sprint 1 / Feature 2)
     * — same lookup pattern, kept local to this controller since Laravel
     * controllers here don't share a common base for this kind of helper.
     */
    private function resolveDepartmentId(int $userId): ?int
    {
        $departmentId = DB::table('users')->where('user_id', $userId)->value('department_id');

        return $departmentId ? (int) $departmentId : null;
    }
}
