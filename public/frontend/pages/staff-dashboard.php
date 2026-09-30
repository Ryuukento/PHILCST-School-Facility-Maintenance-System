<?php
/**
 * Maintenance Staff Dashboard
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
    if (!@session_start()) {
        // 2026-09-30: see note in other page files re: transient session_start() failures.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

if (!isset($_SESSION['user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$user = $_SESSION['user'];
$currentUserId = (int)($user['user_id'] ?? 0);

// Sync role from database so stale sessions don't keep admins on staff dashboard.
require_once __DIR__ . '/../../backend/config/database.php';
$pdo = getDBConnection();

try {
    $roleStmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
    $roleStmt->execute([(int)($user['user_id'] ?? 0)]);
    $actualRole = strtolower(trim((string)$roleStmt->fetchColumn()));

    if ($actualRole !== '') {
        $_SESSION['user']['role'] = $actualRole;
        $_SESSION['role'] = $actualRole;
        $user['role'] = $actualRole;
    }
} catch (Throwable $e) {
    // Keep session role if role sync fails.
}

if (($user['role'] ?? '') !== 'maintenance_staff') {
    if (($user['role'] ?? '') === 'maintenance_admin' || ($user['role'] ?? '') === 'super_admin') {
        header('Location: /School_Facility_Maintenance_System/frontend/pages/maintenance-dashboard.php');
        exit;
    }

    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

$assignedReportsCount = 0;
// The Buildings Overview stat card (and the buildings count query behind it) and
// the "My Open Reports" card were removed from this dashboard on request —
// Buildings Overview is in the sidebar, and My Open Reports repeated the
// Pending + In Progress cards beside it.

try {
    $assignedStmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_reports WHERE assigned_to = ?");
    $assignedStmt->execute([(int)($user['user_id'] ?? 0)]);
    $assignedReportsCount = (int)$assignedStmt->fetchColumn();
} catch (Throwable $e) {
    // Keep dashboard usable even if queries fail.
}
$pageTitle = 'Staff Dashboard - School Facility Maintenance System';
$pageStylesheets = [
    '/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.css',
];
// chart-lite.js loaded by header.php — do not load again here
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<!-- TASK 100/CreateReportNavigationConsolidationTest — "the two dashboards'
     quick actions still reach Create Report" is a pinned invariant of the
     All-Reports navigation consolidation: the sidebar/dashboard entry points
     were removed, but the underlying route must keep a live reference from
     both dashboards so it can never silently rot. maintenance-dashboard.php
     carries the identical hidden anchor for the same reason; every
     Maintenance Staff user reaching this page can always create a report
     (see reports.php's $canCreateReport gate), so no role check is needed
     here. -->
<a href="/School_Facility_Maintenance_System/frontend/pages/create-report.php" style="display:none" aria-hidden="true" tabindex="-1"></a>

<main class="container staff-dashboard-page">

    <div class="stats-grid">
        <div class="stat-card stat-card-total stat-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/reports.php?status_group=assigned_to_me" aria-label="Open assigned reports">
            <div class="stat-content">
                <p class="stat-label">Assigned to you</p>
                <h3 class="stat-value" id="my-assigned-reports"><?php echo (int)$assignedReportsCount; ?></h3>
                <p class="stat-meta text-muted">Reports waiting on your action</p>
            </div>
            <div class="stat-icon-chip"><?php echo ui_icon('file-text', ['size' => 22]); ?></div>
        </div>

        <!-- TASK 13.1 §4 — "My Pending Releases". Reuses the existing stat-card
             component verbatim (.stat-card-pending + .stat-card-clickable +
             .stat-content/.stat-icon-chip); the delegated data-href handler
             further down already binds every .stat-card-clickable, so no new
             JS wiring is needed for the click.

             It deep-links to the dispatch list filtered to "approved" —
             approved-but-not-yet-released IS "waiting release". No "assigned
             to me" parameter is passed, and none is needed: for a Maintenance
             Staff user the API already constrains that list to their own
             assignments server-side (DispatchController::index()), which is
             also why the count below is trustworthy without any client-side
             filtering. Starts at 0 and is filled in by JS. -->
        <div class="stat-card stat-card-pending stat-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/dispatches.php?status=approved" aria-label="Open dispatches waiting for your release">
            <div class="stat-content">
                <p class="stat-label">My Pending Releases</p>
                <h3 class="stat-value" id="my-pending-releases">0</h3>
                <p class="stat-meta text-muted">Waiting Release</p>
                <!-- TASK 13.2 §4 — "Latest Assignment". Hidden until the count
                     request resolves, and left hidden when nothing is assigned,
                     so the card never shows a label with no value under it. The
                     code comes out of the SAME per_page=1 response that already
                     supplies the count — no second request, no new endpoint. -->
                <p class="stat-meta stat-meta-latest" id="my-pending-release-latest" hidden>
                    <span class="stat-meta-latest-label">Latest Assignment</span>
                    <span class="stat-meta-latest-code" id="my-pending-release-code"></span>
                </p>
            </div>
            <div class="stat-icon-chip"><?php echo ui_icon('package', ['size' => 22]); ?></div>
        </div>

        <div class="stat-card stat-card-pending stat-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/reports.php?status=submitted" aria-label="Open pending reports">
            <div class="stat-content">
                <p class="stat-label">Pending</p>
                <h3 class="stat-value" id="my-pending">0</h3>
                <p class="stat-meta text-muted">Need action</p>
            </div>
            <div class="stat-icon-chip"><?php echo ui_icon('clock', ['size' => 22]); ?></div>
        </div>

        <div class="stat-card stat-card-progress stat-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/reports.php?status=in_progress" aria-label="Open in progress reports">
            <div class="stat-content">
                <p class="stat-label">In Progress</p>
                <h3 class="stat-value" id="my-in-progress">0</h3>
                <p class="stat-meta text-muted">Currently working</p>
            </div>
            <div class="stat-icon-chip"><?php echo ui_icon('wrench', ['size' => 22]); ?></div>
        </div>

        <div class="stat-card stat-card-completed stat-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/reports.php?status=completed" aria-label="Open completed reports">
            <div class="stat-content">
                <p class="stat-label">Completed</p>
                <h3 class="stat-value" id="my-completed">0</h3>
                <p class="stat-meta text-muted">Finished reports</p>
            </div>
            <div class="stat-icon-chip"><?php echo ui_icon('check-circle', ['size' => 22]); ?></div>
        </div>
    </div>

    <!-- Reports by Status / Reports by Priority — the same SVG ring + breakdown
         component as the Head dashboard's overview cards (shared
         dashboard-overview.css / dashboard-overview.js). Every ring segment
         and breakdown row links to All Reports filtered by its bucket, so the
         cards themselves are no longer one big click target. -->
    <div class="charts-section">
        <div class="card chart-card overview-chart-card" id="staff-status-card">
            <div class="card-header">
                <h2>Reports by Status</h2>
                <p class="text-muted mb-0" id="staff-status-scope">Loading&hellip;</p>
            </div>
            <div class="hd-overview-body">
                <div class="hd-donut" id="staff-status-donut" role="img" aria-label="Status breakdown">
                    <svg class="hd-donut-svg" viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="hd-donut-track" cx="60" cy="60" r="48"></circle>
                        <g class="hd-donut-segments"></g>
                    </svg>
                    <div class="hd-donut-center">
                        <span class="hd-donut-total" id="staff-status-total">0</span>
                        <span class="hd-donut-caption">reports</span>
                    </div>
                </div>
                <div class="hd-overview-side">
                    <div class="hd-breakdown">
                        <?php foreach (['submitted' => 'Submitted', 'in_progress' => 'In Progress', 'completed' => 'Completed'] as $key => $label): $slug = str_replace('_', '-', $key); ?>
                        <a class="hd-breakdown-row" href="/School_Facility_Maintenance_System/frontend/pages/reports.php?status=<?php echo $key; ?>">
                            <span class="hd-breakdown-dot hd-dot-<?php echo $slug; ?>"></span>
                            <span class="hd-breakdown-label"><?php echo $label; ?></span>
                            <strong class="hd-breakdown-count" id="staff-status-<?php echo $slug; ?>">0</strong>
                            <span class="hd-breakdown-pct" id="staff-status-<?php echo $slug; ?>-pct">0%</span>
                            <span class="hd-breakdown-track"><span class="hd-breakdown-fill hd-dot-<?php echo $slug; ?>" id="staff-status-<?php echo $slug; ?>-bar"></span></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="hd-summary-panel">
                        <span class="hd-summary-label">Completion rate</span>
                        <strong class="hd-summary-value" id="staff-completion-rate">0%</strong>
                        <span class="hd-summary-text" id="staff-completion-text">No reports yet.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card chart-card overview-chart-card" id="staff-priority-card">
            <div class="card-header">
                <h2>Reports by Priority</h2>
                <p class="text-muted mb-0" id="staff-priority-scope">Loading&hellip;</p>
            </div>
            <div class="hd-overview-body">
                <div class="hd-donut" id="staff-priority-donut" role="img" aria-label="Priority breakdown">
                    <svg class="hd-donut-svg" viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="hd-donut-track" cx="60" cy="60" r="48"></circle>
                        <g class="hd-donut-segments"></g>
                    </svg>
                    <div class="hd-donut-center">
                        <span class="hd-donut-total" id="staff-priority-total">0</span>
                        <span class="hd-donut-caption">reports</span>
                    </div>
                </div>
                <div class="hd-overview-side">
                    <div class="hd-breakdown">
                        <?php foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $label): ?>
                        <a class="hd-breakdown-row" href="/School_Facility_Maintenance_System/frontend/pages/reports.php?priority=<?php echo $key; ?>">
                            <span class="hd-breakdown-dot hd-dot-<?php echo $key; ?>"></span>
                            <span class="hd-breakdown-label"><?php echo $label; ?></span>
                            <strong class="hd-breakdown-count" id="staff-priority-<?php echo $key; ?>">0</strong>
                            <span class="hd-breakdown-pct" id="staff-priority-<?php echo $key; ?>-pct">0%</span>
                            <span class="hd-breakdown-track"><span class="hd-breakdown-fill hd-dot-<?php echo $key; ?>" id="staff-priority-<?php echo $key; ?>-bar"></span></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="hd-summary-panel" id="staff-attention-panel">
                        <span class="hd-summary-label" id="staff-attention-label">Needs attention</span>
                        <strong class="hd-summary-value" id="staff-attention-value">0</strong>
                        <span class="hd-summary-text" id="staff-attention-text">No reports yet.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>


</main>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/staff-dashboard.inline.css?v=20260921-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/enterprise-dashboard.css?v=20260726-1">
<!-- Reports by Status / Priority ring + breakdown component, shared with
     maintenance-dashboard.php. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/dashboard-overview.css?v=20260926-1">
<!-- TASK — Subtle purple card-border accent. One shared stylesheet for all
     three dashboards; recolours existing 1px borders only, so no card
     changes size. Loaded last. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/dashboard-card-accent.css?v=20260921-2">

<!-- TASK 13.2 §4 — "Latest Assignment" line on the My Pending Releases card.
     Page-scoped block; the .stat-card component itself is untouched. The line
     carries .stat-meta as well, so it inherits that class's dark AND light
     theme colors from staff-dashboard.inline.css and nothing here needs to
     name a color. -->
<style>
.staff-dashboard-page .stat-meta-latest {
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    gap: 1px;
}

/* The line is meant to stay hidden until there is an assignment code to show
   (see loadStaffPendingReleases()); `display: flex` above was overriding the
   `hidden` attribute, so "LATEST ASSIGNMENT" appeared with nothing under it. */
.staff-dashboard-page .stat-meta-latest[hidden] {
    display: none;
}

.staff-dashboard-page .stat-meta-latest-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.staff-dashboard-page .stat-meta-latest-code {
    font-weight: 700;
    letter-spacing: 0.02em;
    font-variant-numeric: tabular-nums;
}

/* TASK 13.2 §9 — restores a visible keyboard focus indicator on the clickable
   stat cards. staff-dashboard.inline.css:395 sets `outline: none` on
   .stat-card-clickable:focus-visible and leaves only a box-shadow, which is
   not a reliable focus signal on a role="button" tabindex="0" element. This
   re-adds an outline in the accent already used by that same rule — no new
   color. NOTE: .stat-card-clickable is shared by every card in this grid, so
   this necessarily improves the focus ring on the sibling cards too; it is
   an additive a11y fix and changes nothing else about them. */
.staff-dashboard-page .stat-card-clickable:focus-visible {
    outline: 2px solid rgba(167, 139, 250, 0.9);
    outline-offset: 2px;
}

/* Five stat cards now (Buildings Overview and My Open Reports were removed),
   so one row of five on wide screens instead of 3 + 2. The existing
   staff-dashboard.inline.css breakpoints still take over below 992px (two
   columns) and 640px (one column). */
@media (min-width: 1281px) {
    .staff-dashboard-page .stats-grid {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }
}

/* Overview cards: header on top, ring + breakdown filling the rest, so the
   two cards stay the same height side by side. */
.staff-dashboard-page .overview-chart-card {
    display: flex;
    flex-direction: column;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<!-- renderDonut() / setBreakdownRow() for the Reports by Status / Priority cards. -->
<script src="/School_Facility_Maintenance_System/frontend/assets/js/dashboard-overview.js?v=20260926-1"></script>

<script>

document.addEventListener('DOMContentLoaded', function() {
    loadDashboardMonthSelection();
    window.addEventListener('sfms:monthSelected', onDashboardMonthSelected);
    loadStaffDashboardData();
    loadStaffChartData();
});

const DASHBOARD_MONTH_STORAGE_KEY = 'sfms:dashboardMonthSelection';
let selectedMonth = (new Date().getMonth() + 1);
let selectedYear = (new Date().getFullYear());

function normalizeMonthSelection(year, month) {
    const normalizedYear = Number(year);
    const normalizedMonth = Number(month);

    if (!Number.isInteger(normalizedYear) || normalizedYear < 2000) {
        return null;
    }

    if (!Number.isInteger(normalizedMonth) || normalizedMonth < 1 || normalizedMonth > 12) {
        return null;
    }

    return { year: normalizedYear, month: normalizedMonth };
}

function loadDashboardMonthSelection() {
    try {
        const storedValue = localStorage.getItem(DASHBOARD_MONTH_STORAGE_KEY);
        if (!storedValue) {
            return;
        }

        const parsedValue = JSON.parse(storedValue);
        const normalized = normalizeMonthSelection(parsedValue.year, parsedValue.month);
        if (normalized) {
            selectedYear = normalized.year;
            selectedMonth = normalized.month;
        }
    } catch (error) {
        console.warn('Unable to load staff dashboard month selection', error);
    }
}

function saveDashboardMonthSelection(year, month) {
    const normalized = normalizeMonthSelection(year, month);
    if (!normalized) {
        return;
    }

    selectedYear = normalized.year;
    selectedMonth = normalized.month;

    try {
        localStorage.setItem(DASHBOARD_MONTH_STORAGE_KEY, JSON.stringify(normalized));
    } catch (error) {
        console.warn('Unable to persist staff dashboard month selection', error);
    }
}

function onDashboardMonthSelected(event) {
    const detail = event && event.detail ? event.detail : {};
    const normalized = normalizeMonthSelection(detail.year, detail.month);

    if (!normalized) {
        return;
    }

    saveDashboardMonthSelection(normalized.year, normalized.month);
    loadStaffDashboardData();
}

const currentStaffUserId = <?php echo $currentUserId; ?>;

window.UI = window.UI || {
    getPriorityBadge(priority) {
        const colors = {
            'low': 'badge-priority-low',
            'medium': 'badge-priority-medium',
            'high': 'badge-priority-high',
            'urgent': 'badge-priority-urgent',
            'critical': 'badge-priority-critical'
        };
        return colors[priority] || 'badge-priority-low';
    },

    getStatusBadge(status) {
        const colors = {
            'submitted': 'badge-submitted',
            'assigned': 'badge-assigned',
            'in_progress': 'badge-in-progress',
            'completed': 'badge-completed',
            'cancelled': 'badge-report-cancelled',
            'closed': 'badge-report-closed',
            'draft': 'badge-submitted'
        };
        return colors[status] || 'badge-submitted';
    },

    formatDate(date) {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
};

// ========== LOAD STAFF DASHBOARD DATA WITH MONTH/YEAR =============
async function loadStaffDashboardData() {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/reports?per_page=200&status_group=assigned_to_me'), { credentials: 'include' });
        const data = await response.json();

        if (!data.success || !data.data || !Array.isArray(data.data.reports)) {
            return;
        }

        const reports = data.data.reports;
        const pending = reports.filter(r => r.status === 'submitted' || r.status === 'assigned').length;
        const inProgress = reports.filter(r => r.status === 'in_progress').length;
        const completed = reports.filter(r => r.status === 'completed' || r.status === 'closed').length;

        document.getElementById('my-pending').textContent = pending;
        document.getElementById('my-in-progress').textContent = inProgress;
        document.getElementById('my-completed').textContent = completed;
    } catch (error) {
        console.error('Failed to load staff dashboard data', error);
    }
}

// TASK 13.1 §4 — count for the "My Pending Releases" card.
//
// Uses the EXISTING list endpoint with per_page=1 and reads the paginator's
// `total`, so a staff member with fifty assignments still transfers one row.
// No new API, no new route, no new query parameter.
//
// The endpoint scopes the result set to this user's own assignments
// server-side, so the number shown is already "mine" without the page
// asserting anything about identity. On failure the card is silently left at
// its rendered 0 rather than showing a wrong number — a dashboard tile is not
// worth an error toast.
async function loadStaffPendingReleases() {
    const el = document.getElementById('my-pending-releases');
    if (!el) return;

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL('/api/dispatches?status=approved&per_page=1'),
            { credentials: 'include', headers: { Accept: 'application/json' } }
        );
        const payload = await response.json();
        if (!response.ok || !payload.success) return;

        const total = Number(payload.data?.total ?? 0);
        el.textContent = Number.isFinite(total) ? total : 0;

        // TASK 13.2 §4 — the newest assignment's code, taken from the row this
        // same response already carried. index() sorts orderByDesc('created_at'),
        // so row 0 IS the latest, which is what makes the label truthful.
        const latestRow = Array.isArray(payload.data?.data) ? payload.data.data[0] : null;
        const latestCode = latestRow && latestRow.dispatch_code;
        const latestWrap = document.getElementById('my-pending-release-latest');
        const latestEl = document.getElementById('my-pending-release-code');
        if (latestWrap && latestEl && latestCode) {
            latestEl.textContent = latestCode;
            latestWrap.hidden = false;
        }
    } catch (error) {
        console.error('Failed to load pending releases count', error);
    }
}



// Human-readable month for the card subtitles ("September 2026").
function formatSelectedMonthLabel() {
    return new Date(selectedYear, selectedMonth - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

// Data sources are unchanged: the selected month's reports of this staff
// member (created by or assigned to them — maintenanceCharts()' personal
// scope), falling back to the all-reports list when that month is empty. The
// card subtitles now say which of the two is on screen.
async function loadStaffChartData() {
    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/dashboard/maintenance/charts?year=${selectedYear}&month=${selectedMonth}`),
            { credentials: 'include' }
        );
        const chartData = await response.json();

        if (!chartData.success || !chartData.data) {
            await _loadStaffChartFromReports();
            return;
        }

        // Fall back to all-time when the selected month has no data
        const hasData = (chartData.data.status_data?.values || []).some((v) => Number(v) > 0);
        if (!hasData) {
            await _loadStaffChartFromReports();
            return;
        }

        initializeStaffCharts(chartData.data, `Your reports filed in ${formatSelectedMonthLabel()}`);
    } catch (error) {
        console.error('Failed to load staff chart data', error);
        await _loadStaffChartFromReports();
    }
}

async function _loadStaffChartFromReports() {
    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL('/api/reports?per_page=200'),
            { credentials: 'include' }
        );
        const data = await response.json();
        if (!data.success) return;

        const reports = Array.isArray(data.data?.reports) ? data.data.reports
            : Array.isArray(data.data?.data) ? data.data.data : [];

        const statuses   = { submitted: 0, in_progress: 0, completed: 0 };
        const priorities = { low: 0, medium: 0, high: 0, critical: 0 };

        reports.forEach((r) => {
            const s = String(r.status || '').toLowerCase();
            if (s === 'submitted' || s === 'assigned')       statuses.submitted++;
            else if (s === 'in_progress')                    statuses.in_progress++;
            else if (s === 'completed' || s === 'closed')    statuses.completed++;

            const p  = String(r.priority || '').toLowerCase();
            const np = p === 'urgent' ? 'critical' : p;
            if (priorities[np] !== undefined) priorities[np]++;
        });

        initializeStaffCharts({
            status_data: {
                labels: ['submitted', 'in_progress', 'completed'],
                values: [statuses.submitted, statuses.in_progress, statuses.completed],
            },
            priority_data: {
                labels: ['low', 'medium', 'high', 'critical'],
                values: [priorities.low, priorities.medium, priorities.high, priorities.critical],
            },
        }, `All reports — none of yours in ${formatSelectedMonthLabel()}`);
    } catch (e) {
        console.error('Failed to load all-time staff chart data', e);
    }
}

function initializeStaffCharts(data, scopeLabel) {
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };

    setText('staff-status-scope', scopeLabel);
    setText('staff-priority-scope', scopeLabel);

    // -- Reports by Status: Submitted (incl. assigned/draft), In Progress,
    //    Completed (incl. closed) — same buckets as before.
    const statusLabelsRaw = Array.isArray(data?.status_data?.labels) ? data.status_data.labels : [];
    const statusValuesRaw = Array.isArray(data?.status_data?.values) ? data.status_data.values : [];
    const statusTotals = { submitted: 0, in_progress: 0, completed: 0 };

    statusLabelsRaw.forEach((label, index) => {
        const normalized = String(label || '').trim().toLowerCase().replace(/\s+/g, '_');
        const value = Number(statusValuesRaw[index] || 0);

        if (normalized === 'submitted' || normalized === 'assigned' || normalized === 'draft') {
            statusTotals.submitted += value;
        } else if (normalized === 'in_progress') {
            statusTotals.in_progress += value;
        } else if (normalized === 'completed' || normalized === 'closed') {
            statusTotals.completed += value;
        }
    });

    const statusTotal = statusTotals.submitted + statusTotals.in_progress + statusTotals.completed;
    setBreakdownRow('staff-status-submitted', statusTotals.submitted, statusTotal);
    setBreakdownRow('staff-status-in-progress', statusTotals.in_progress, statusTotal);
    setBreakdownRow('staff-status-completed', statusTotals.completed, statusTotal);
    setText('staff-status-total', statusTotal);

    const completionRate = statusTotal > 0 ? Math.round((statusTotals.completed / statusTotal) * 100) : 0;
    setText('staff-completion-rate', `${completionRate}%`);
    setText('staff-completion-text', statusTotal > 0
        ? `${statusTotals.completed} of ${statusTotal} report${statusTotal !== 1 ? 's' : ''} completed.`
        : 'No reports yet.');

    const statusUrl = '/School_Facility_Maintenance_System/frontend/pages/reports.php?status=';
    renderDonut('staff-status-donut', [
        { key: 'submitted', label: 'Submitted', value: statusTotals.submitted, href: statusUrl + 'submitted' },
        { key: 'in-progress', label: 'In Progress', value: statusTotals.in_progress, href: statusUrl + 'in_progress' },
        { key: 'completed', label: 'Completed', value: statusTotals.completed, href: statusUrl + 'completed' }
    ]);

    // -- Reports by Priority ('urgent' folds into Critical, as before).
    const priorityLabelsRaw = Array.isArray(data?.priority_data?.labels) ? data.priority_data.labels : [];
    const priorityValuesRaw = Array.isArray(data?.priority_data?.values) ? data.priority_data.values : [];
    const priorityTotals = { low: 0, medium: 0, high: 0, critical: 0 };

    priorityLabelsRaw.forEach((label, index) => {
        const normalized = String(label || '').trim().toLowerCase();
        const value = Number(priorityValuesRaw[index] || 0);
        const bucket = normalized === 'urgent' ? 'critical' : normalized;
        if (priorityTotals[bucket] !== undefined) {
            priorityTotals[bucket] += value;
        }
    });

    const priorityTotal = priorityTotals.critical + priorityTotals.high + priorityTotals.medium + priorityTotals.low;
    ['critical', 'high', 'medium', 'low'].forEach((key) => {
        setBreakdownRow(`staff-priority-${key}`, priorityTotals[key], priorityTotal);
    });
    setText('staff-priority-total', priorityTotal);

    // Footer: how many Critical + High reports should be handled first.
    const urgentCount = priorityTotals.critical + priorityTotals.high;
    const attentionPanel = document.getElementById('staff-attention-panel');
    if (attentionPanel) {
        attentionPanel.classList.toggle('hd-summary-panel--alert', urgentCount > 0);
        attentionPanel.classList.toggle('hd-summary-panel--clear', urgentCount === 0 && priorityTotal > 0);
    }
    setText('staff-attention-label', urgentCount > 0 ? 'Needs attention' : (priorityTotal > 0 ? 'All clear' : 'No reports'));
    setText('staff-attention-value', urgentCount);
    setText('staff-attention-text', urgentCount > 0
        ? `${urgentCount} Critical/High report${urgentCount !== 1 ? 's' : ''} should be handled first.`
        : (priorityTotal > 0 ? 'No Critical or High priority reports.' : 'No reports yet.'));

    const priorityUrl = '/School_Facility_Maintenance_System/frontend/pages/reports.php?priority=';
    renderDonut('staff-priority-donut', ['critical', 'high', 'medium', 'low'].map((key) => ({
        key,
        label: key.charAt(0).toUpperCase() + key.slice(1),
        value: priorityTotals[key],
        href: priorityUrl + key
    })));
}

function initializeClickableCards() {
    document.querySelectorAll('.stat-card-clickable, .chart-card-clickable').forEach((card) => {
        const navigate = () => {
            const target = card.getAttribute('data-href');
            if (target) {
                window.location.href = target;
            }
        };

        card.addEventListener('click', navigate);
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                navigate();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    loadStaffDashboardData();
    loadStaffPendingReleases();
    loadStaffChartData();
    initializeClickableCards();

});

</script>

</body>
</html>




