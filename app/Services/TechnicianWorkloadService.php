<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * TASK — Technician Workload (Administrator + Head Maintenance dashboards).
 *
 * ONE aggregate answer to "how much work does each member of maintenance
 * personnel currently have on their plate", for the read-only workload card on
 * both management dashboards. "Personnel" means the Head (maintenance_admin)
 * and Maintenance Staff alike — see PERSONNEL_ROLES for why the Head belongs
 * in the same list. Read-only: this service never writes, never assigns, and
 * grants no permission — it only counts rows that the existing report
 * assignment workflow has already produced.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS (and is not "a conflicting definition of assigned")
 * ---------------------------------------------------------------------------
 * The brief says: reuse the existing report assignment/status logic; do NOT
 * create a conflicting definition of "assigned report". Reusing it required
 * first establishing which existing definition is *the* one, because the tree
 * already contains TWO that disagree about `cancelled`:
 *
 *   (a) status NOT IN ('completed','closed')
 *       - DashboardController::maintenanceStats()  (overdue KPI)
 *       - ReportController::index()                (overdue / due_soon filters)
 *
 *   (b) status NOT IN ('completed','closed','cancelled')
 *       - DashboardController::superAdminStats()   (overdue KPI)
 *       - maintenance-dashboard.php's client-side `assignedActive` filter,
 *         which is literally the Head Maintenance dashboard's own existing
 *         notion of "assigned and still active".
 *
 * This service follows (b), for two reasons rather than by preference:
 *   1. (b) is what the Head Maintenance dashboard already shows its user as
 *      active assigned work, and this card sits on that same page. Two
 *      widgets one scroll apart disagreeing about the same technician would
 *      be the actual inconsistency.
 *   2. A cancelled report is not work. (a)'s call sites are both "overdue"
 *      KPIs, where including cancelled rows is arguably a latent bug rather
 *      than an intentional rule; either way, counting cancelled work as
 *      current workload would directly contradict the brief's "Do NOT count
 *      ... Closed historical reports / Retired workflows".
 *
 * No existing call site is changed. The (a)/(b) split predates this task and
 * is left exactly as found — resolving it would mean editing overdue KPIs,
 * which this task explicitly puts off-limits.
 *
 * "Completed" likewise reuses the pair the system already treats as the
 * finished-work terminal states — ReportService::updateReport() stamps
 * completed_date and logs COMPLETE_REPORT for `completed` and `closed`
 * alike, and DashboardController's per-department "completed" aggregate
 * already reads SUM(status IN ('completed','closed')).
 *
 * ---------------------------------------------------------------------------
 * SCOPE OF WHAT IS COUNTED
 * ---------------------------------------------------------------------------
 * Only `maintenance_reports`, only rows whose assigned_to points at the
 * technician. That is enforced structurally by the LEFT JOIN condition, so:
 *   - unassigned reports           never join (assigned_to is null),
 *   - deleted reports              are gone (no soft deletes on this table),
 *   - repair requests / dispatches / preventive maintenance / damage-report
 *     workflows are different tables and are never touched here.
 * A technician with no matching reports still comes back, with zeroes, because
 * the join is a LEFT join — the brief requires zero-workload technicians to
 * stay visible.
 */
class TechnicianWorkloadService
{
    /**
     * Statuses that mean the assignment is no longer live work.
     * See the class docblock for why `cancelled` is in this list.
     */
    public const INACTIVE_STATUSES = ['completed', 'closed', 'cancelled'];

    /** Statuses the system already treats as "the work got done". */
    public const COMPLETED_STATUSES = ['completed', 'closed'];

    /**
     * The canonical roles counted as "maintenance personnel" for this card.
     * Resolved through RoleNormalizerService so technicians stored under a
     * legacy alias ('eelab_staff', 'maintenance_personnel') are included —
     * the pre-existing personnel widgets compare `role = 'maintenance_staff'`
     * literally and silently miss those rows.
     *
     * -----------------------------------------------------------------------
     * WHY maintenance_admin (Head) IS IN THIS LIST
     * -----------------------------------------------------------------------
     * It was originally omitted on the assumption that the Head only *assigns*
     * work rather than carrying it. That assumption is not what the system
     * actually does: maintenance-report-detail.php builds the Administrator's
     * assignment picker as "Assign To (Maintenance Team)" and populates it from
     * `u.role IN ('maintenance_admin')` *and* `u.role IN ('maintenance_staff')`
     * — so a maintenance_report's assigned_to can legitimately point at a Head,
     * and every such report was silently invisible on this card.
     *
     * Including the role changes nothing about who may assign to whom; it only
     * stops the card under-reporting work the existing assignment workflow has
     * already created. super_admin is deliberately NOT here — no assignment
     * surface in the system offers the Administrator as an assignee, so
     * counting them would always be a guaranteed zero row.
     */
    public const PERSONNEL_ROLES = ['maintenance_admin', 'maintenance_staff'];

    /**
     * Short display vocabulary for the roles above.
     *
     * These two strings are not invented here — they are the labels the app
     * already shows for these roles in User Management
     * (users.php::getRoleLabel(), which maps maintenance_admin => 'Head' and
     * maintenance_staff => 'Staff'). They are repeated server-side rather than
     * re-derived in the widget so the card has exactly one source for the
     * designation text, and so a Head is never rendered as an unlabelled name
     * that a reader could mistake for a technician.
     *
     * This is display vocabulary only. It grants nothing and is never consulted
     * for authorization — that stays with EnsureRole / RoleNormalizerService.
     */
    private const ROLE_LABELS = [
        'maintenance_admin' => 'Head',
        'maintenance_staff' => 'Staff',
    ];

    /**
     * The label for a stored (possibly aliased) role string. Normalized first,
     * so 'eelab_staff' reads as 'Staff' rather than falling through.
     */
    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[RoleNormalizerService::normalize($role)] ?? 'Personnel';
    }

    /**
     * Every active maintenance technician with their current workload.
     *
     * ONE query, regardless of how many technicians exist — the per-technician
     * active/completed counts are conditional aggregates inside the same
     * GROUP BY, not a lookup per row.
     *
     * @param  int|null $departmentId Restrict to one department (Head
     *         Maintenance, whose authority is department-scoped everywhere
     *         else in the system — see ReportAuthorizationService). Null means
     *         system-wide (Administrator).
     * @return array<int, array{user_id:int, full_name:string, role:string, role_label:string,
     *         designation:?string, department_name:?string, avatar:?string, active_count:int,
     *         completed_count:int, workload_percent:int}>
     */
    public function workload(?int $departmentId = null): array
    {
        $inactive  = self::INACTIVE_STATUSES;
        $completed = self::COMPLETED_STATUSES;

        $inactivePlaceholders  = implode(',', array_fill(0, count($inactive), '?'));
        $completedPlaceholders = implode(',', array_fill(0, count($completed), '?'));

        $query = DB::table('users as u')
            ->selectRaw(
                'u.user_id, u.full_name, u.role, u.designation, u.avatar, d.name as department_name, '
                . "COUNT(CASE WHEN mr.status IS NOT NULL AND mr.status NOT IN ($inactivePlaceholders) THEN mr.report_id END) as active_count, "
                . "COUNT(CASE WHEN mr.status IN ($completedPlaceholders) THEN mr.report_id END) as completed_count",
                array_merge($inactive, $completed)
            )
            ->leftJoin('maintenance_reports as mr', 'u.user_id', '=', 'mr.assigned_to')
            // LEFT so a technician with no department still appears. The join
            // is inside the same grouped statement — the roster still costs one
            // query however many people are on it.
            ->leftJoin('departments as d', 'd.department_id', '=', 'u.department_id')
            ->whereIn('u.role', RoleNormalizerService::rawValuesFor(self::PERSONNEL_ROLES))
            ->where('u.status', 'active')
            // Every selected non-aggregate column is grouped, so the statement
            // is valid under MySQL's ONLY_FULL_GROUP_BY.
            ->groupBy('u.user_id', 'u.full_name', 'u.role', 'u.designation', 'u.avatar', 'd.name')
            // §5 — busiest first, then name as the stable tie-break.
            ->orderByDesc('active_count')
            ->orderBy('u.full_name');

        if ($departmentId !== null) {
            $query->where('u.department_id', $departmentId);
        }

        $rows = $query->get();

        $technicians = [];
        foreach ($rows as $row) {
            $technicians[] = [
                'user_id'   => (int) $row->user_id,
                'full_name' => (string) ($row->full_name ?? ''),
                // Normalized so the client never has to know the alias table.
                'role'       => RoleNormalizerService::normalize((string) ($row->role ?? '')),
                'role_label' => self::roleLabel((string) ($row->role ?? '')),
                // Both are stored user data and both are genuinely optional in
                // the real schema, so blanks come back as null rather than ''
                // — the widget then omits them instead of rendering a stray
                // separator for a value that does not exist.
                'designation'     => self::nullIfBlank($row->designation ?? null),
                'department_name' => self::nullIfBlank($row->department_name ?? null),
                // The same users.avatar URL the sidebar already renders; null
                // means "no upload", and the widget falls back to the initial.
                'avatar'          => self::nullIfBlank($row->avatar ?? null),
                'active_count'    => (int) $row->active_count,
                'completed_count' => (int) $row->completed_count,
            ];
        }

        return self::withRelativeBars($technicians);
    }

    /** Trim, and treat an empty string as "not recorded". */
    private static function nullIfBlank($value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The workload bar is a RELATIVE comparison, per the brief: the busiest
     * technician's bar is full and everyone else is a share of that. It is
     * deliberately NOT a capacity gauge — this system has no configured
     * workload ceiling anywhere, so there is nothing to call "overloaded" and
     * no threshold is invented here.
     *
     * When nobody has any active work the peak is 0 and every bar is 0%,
     * rather than 100% for everyone (0/0).
     *
     * Static + separately testable so the percentage rule can be exercised
     * without a database.
     *
     * @param  array<int, array<string, mixed>> $technicians
     * @return array<int, array<string, mixed>>
     */
    public static function withRelativeBars(array $technicians): array
    {
        $peak = 0;
        foreach ($technicians as $technician) {
            $peak = max($peak, (int) ($technician['active_count'] ?? 0));
        }

        foreach ($technicians as $index => $technician) {
            $active = (int) ($technician['active_count'] ?? 0);
            $technicians[$index]['workload_percent'] = $peak > 0
                ? (int) round(($active / $peak) * 100)
                : 0;
        }

        return $technicians;
    }
}
