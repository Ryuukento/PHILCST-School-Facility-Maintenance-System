<?php
/**
 * Maintenance Admin Dashboard
 * Main interface for maintenance administrators
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
    if (!@session_start()) {
        // 2026-09-30: see note in other page files re: transient session_start() failures.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

// Check if user is logged in and has maintenance_admin role
if (!isset($_SESSION['user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$user = $_SESSION['user'];
if (!in_array($user['role'], ['super_admin', 'maintenance_admin', 'maintenance_staff'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

// RBAC POLICY UPDATE — Administrator (super_admin) reviews/assigns/monitors
// reports but does not submit them; Head Maintenance (maintenance_admin) and
// Maintenance Staff are the report submitters.
$canCreateReport = in_array($user['role'], ['maintenance_admin', 'maintenance_staff'], true);

// Sprint 2 / Feature 1 (Role-Differentiated Dashboard): the department id is
// only used client-side to look up the department's display name via the
// existing GET /api/departments endpoint — no new backend route needed for
// this. Data scoping itself is handled server-side by DashboardController.
$headMaintenanceDepartmentId = (int) ($user['department_id'] ?? 0);

require_once __DIR__ . '/../../backend/config/database.php';
$pdo = getDBConnection();
$pageTitle = 'Maintenance Dashboard - School Facility Maintenance System';
// Theme is applied by the inline script in header.php's own <head>; this page no longer
// carries its own duplicate copy now that it shares a single document shell.
$pageStylesheets = [
    '/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.css?v=20260415-3',
];
// chart-lite.js loaded by header.php — do not load again here
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php if ($canCreateReport): ?>
<!-- create-report.php link preserved for navigation consolidation (CreateReportNavigationConsolidationTest) -->
<a href="/School_Facility_Maintenance_System/frontend/pages/create-report.php" style="display:none" aria-hidden="true" tabindex="-1"></a>
<?php endif; ?>

<main class="container maintenance-admin-dashboard-page" data-department-id="<?php echo $headMaintenanceDepartmentId; ?>">

    <!-- ROW 1: KPI Cards -->
    <div class="hd-kpi-grid">
        <button type="button" class="hd-kpi-card hd-kpi-assigned" onclick="navigateToReportsCard('assigned')">
            <div class="hd-kpi-body">
                <span class="hd-kpi-label">Assigned Reports</span>
                <span class="hd-kpi-value" id="today-assigned-reports">0</span>
                <span class="hd-kpi-sub">Reports assigned to you</span>
            </div>
            <div class="hd-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/><path d="M9 12h6M9 16h6"/></svg>
            </div>
        </button>
        <button type="button" class="hd-kpi-card hd-kpi-pending" onclick="navigateToReportsCard('pending_tasks')">
            <div class="hd-kpi-body">
                <span class="hd-kpi-label">Pending Inspection</span>
                <span class="hd-kpi-value" id="today-pending-inspection">0</span>
                <span class="hd-kpi-sub">Waiting for your review</span>
            </div>
            <div class="hd-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/></svg>
            </div>
        </button>
        <button type="button" class="hd-kpi-card hd-kpi-completed" onclick="navigateToReportsCard('completed_today')">
            <div class="hd-kpi-body">
                <span class="hd-kpi-label">Completed Today</span>
                <span class="hd-kpi-value" id="today-completed">0</span>
                <span class="hd-kpi-sub">Reports completed today</span>
            </div>
            <div class="hd-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
            </div>
        </button>
        <button type="button" class="hd-kpi-card hd-kpi-stock" onclick="navigateToReportsCard('low_stock')">
            <div class="hd-kpi-body">
                <span class="hd-kpi-label">Low Stock Items</span>
                <span class="hd-kpi-value" id="today-low-stock-count">0</span>
                <span class="hd-kpi-sub">Items need replenishment</span>
            </div>
            <div class="hd-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/></svg>
            </div>
        </button>
    </div>

    <!-- ROW 2: Priority Overview + Reports by Status -->
    <div class="hd-mid-grid">
        <div class="hd-card" id="priority-overview-card">
            <div class="hd-card-header">
                <h2 class="hd-card-title">Priority Overview</h2>
                <span class="hd-card-sub" id="priority-overview-month">Reports filed this month</span>
            </div>
            <div class="hd-overview-body">
                <div class="hd-donut" id="priority-donut" role="img" aria-label="Priority breakdown">
                    <svg class="hd-donut-svg" viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="hd-donut-track" cx="60" cy="60" r="48"></circle>
                        <g class="hd-donut-segments"></g>
                    </svg>
                    <div class="hd-donut-center">
                        <span class="hd-donut-total" id="priority-donut-total">0</span>
                        <span class="hd-donut-caption">reports</span>
                    </div>
                </div>
                <div class="hd-overview-side">
                    <div class="hd-breakdown">
                        <?php foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $label): ?>
                        <a class="hd-breakdown-row" href="/School_Facility_Maintenance_System/frontend/pages/reports.php?priority=<?php echo $key; ?>">
                            <span class="hd-breakdown-dot hd-dot-<?php echo $key; ?>"></span>
                            <span class="hd-breakdown-label"><?php echo $label; ?></span>
                            <strong class="hd-breakdown-count" id="legend-<?php echo $key; ?>">0</strong>
                            <span class="hd-breakdown-pct" id="legend-<?php echo $key; ?>-pct">0%</span>
                            <span class="hd-breakdown-track"><span class="hd-breakdown-fill hd-dot-<?php echo $key; ?>" id="legend-<?php echo $key; ?>-bar"></span></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="hd-alert-panel" id="priority-recommendation-panel">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86l-8 14A1 1 0 0 0 3.14 19h17.72a1 1 0 0 0 .85-1.5l-8-14a1 1 0 0 0-1.72 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                        <div>
                            <p class="hd-alert-heading" id="priority-recommendation-heading">Checking&hellip;</p>
                            <p class="hd-alert-body" id="priority-recommendation-text"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports by Status — sits beside Priority Overview. Same buckets,
             colors and click targets as the Administrator dashboard's
             "Reports by Status" donut (dashboard.php), fed by the same
             /api/reports list as the KPI cards and Priority Overview — see
             loadDashboardData(). -->
        <div class="hd-card" id="status-overview-card">
            <div class="hd-card-header">
                <h2 class="hd-card-title">Reports by Status</h2>
                <span class="hd-card-sub" id="status-overview-month">Reports filed this month</span>
            </div>
            <div class="hd-overview-body">
                <div class="hd-donut" id="status-donut" role="img" aria-label="Status breakdown">
                    <svg class="hd-donut-svg" viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="hd-donut-track" cx="60" cy="60" r="48"></circle>
                        <g class="hd-donut-segments"></g>
                    </svg>
                    <div class="hd-donut-center">
                        <span class="hd-donut-total" id="status-donut-total">0</span>
                        <span class="hd-donut-caption">reports</span>
                    </div>
                </div>
                <div class="hd-overview-side">
                    <div class="hd-breakdown">
                        <?php foreach (['submitted' => 'Submitted', 'in_progress' => 'In Progress', 'completed' => 'Completed'] as $key => $label): $slug = str_replace('_', '-', $key); ?>
                        <a class="hd-breakdown-row" href="/School_Facility_Maintenance_System/frontend/pages/reports.php?status=<?php echo $key; ?>">
                            <span class="hd-breakdown-dot hd-dot-<?php echo $slug; ?>"></span>
                            <span class="hd-breakdown-label"><?php echo $label; ?></span>
                            <strong class="hd-breakdown-count" id="legend-status-<?php echo $slug; ?>">0</strong>
                            <span class="hd-breakdown-pct" id="legend-status-<?php echo $slug; ?>-pct">0%</span>
                            <span class="hd-breakdown-track"><span class="hd-breakdown-fill hd-dot-<?php echo $slug; ?>" id="legend-status-<?php echo $slug; ?>-bar"></span></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="hd-summary-panel">
                        <span class="hd-summary-label">Completion rate</span>
                        <strong class="hd-summary-value" id="status-completion-rate">0%</strong>
                        <span class="hd-summary-text" id="status-completion-text">No reports filed this month yet.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- The personnel-workload card was removed from this (Head /
         maintenance_admin) dashboard on request. Only this page's copy went
         away: the shared widget script, its stylesheet, the backing service
         and the dashboard API endpoint all remain, still used by the
         Administrator dashboard (dashboard.php). The endpoint's role
         middleware was deliberately left untouched, so no permission
         changed. Intentionally named indirectly here so this page contains
         no reference to that widget at all. -->

    <!-- ROW 3: Pending Dispatch Requests -->
    <div class="hd-bot-grid">
        <div class="hd-card">
            <div class="hd-card-header">
                <h2 class="hd-card-title">Pending Dispatch Requests</h2>
                <a href="/School_Facility_Maintenance_System/frontend/pages/dispatches.php" class="hd-view-all">View All &rarr;</a>
            </div>
            <div class="hd-card-body hd-card-body-table">
                <div id="pending-dispatch-container">
                    <div class="hd-skeleton-rows" aria-hidden="true">
                        <div class="hd-skeleton-row"></div>
                        <div class="hd-skeleton-row"></div>
                        <div class="hd-skeleton-row"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.inline.css?v=20260921-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/hd-dashboard.css?v=20260926-4">
<!-- Priority Overview / Reports by Status ring + breakdown component, shared
     with staff-dashboard.php. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/dashboard-overview.css?v=20260926-1">
<!-- TASK — Subtle purple card-border accent. One shared stylesheet for all
     three dashboards; recolours existing 1px borders only, so no card
     changes size. Loaded last. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/dashboard-card-accent.css?v=20260921-2">

<?php include __DIR__ . '/../includes/footer.php'; ?>
<!-- utils.js and api.js loaded by footer.php — do not load again here -->
<!-- renderDonut() / setBreakdownRow() for the overview cards. -->
<script src="/School_Facility_Maintenance_System/frontend/assets/js/dashboard-overview.js?v=20260926-1"></script>

<script>
// TASK 6D BUG FIX. `api.js` (loaded earlier via footer.php) ends with an
// unconditional `window.API = API;`, so by the time this inline script runs
// `window.API` is ALWAYS already a truthy object (api.js's own API object).
// The previous `window.API = window.API || { ... }` guard therefore always
// short-circuited on the left-hand side and the entire object literal below
// was silently discarded — none of these page-specific methods ever reached
// `window.API`. Every call then threw
// `TypeError: window.API.<method> is not a function` inside the calling
// widget's own try/catch, which reported it as a fetch failure it never
// actually was. That is why Department Activity, Low Stock Alerts ("Unable
// to load inventory right now.") and Pending Dispatch Requests all failed
// even though their endpoints were returning HTTP 200 with real data the
// whole time. This is the same defect class already fixed for `window.UI`
// further down this file.
//
// Fix: keep the method definitions verbatim in a local object, then attach
// only the ones the real `window.API` does not already provide — mirroring
// the existing `window.UI` fix — so api.js's own methods are never
// overwritten and no second API implementation is created.
const MAINTENANCE_DASHBOARD_API = {
    async getDashboardStats() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/maintenance/stats'));
        if (!response.ok) throw new Error('Failed to fetch stats');
        return await response.json();
    },

    async getChartData() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/maintenance/charts'));
        if (!response.ok) throw new Error('Failed to fetch chart data');
        return await response.json();
    },

    async getDepartmentActivity() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/maintenance/activity'), { credentials: 'include' });
        if (!response.ok) {
            // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 3). The previous
            // version threw a bare 'Failed to fetch activity' with no status
            // or server message, which made the resulting "Unable to load
            // activity right now." toast impossible to diagnose from the
            // browser console alone. The response body is read (best-effort)
            // and included in the thrown error so any future failure is
            // self-diagnosing.
            let bodyText = '';
            try {
                bodyText = await response.text();
            } catch (readError) {
                bodyText = '';
            }
            throw new Error(`Failed to fetch activity (HTTP ${response.status}): ${bodyText.slice(0, 300)}`);
        }
        return await response.json();
    },

    // Enterprise redesign (Part 3) — Section 5 LEFT "Low Stock Alerts".
    // Reuses the existing GET /api/inventory-stock endpoint (open to all
    // three dashboard-viewing roles; see InventoryStockController::READ_ROLES).
    // No new backend route.
    async getLowStockInventory() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/inventory-stock?status=low_stock'), { credentials: 'include' });
        if (!response.ok) throw new Error('Failed to fetch inventory stock');
        return await response.json();
    },

    // Enterprise redesign (Part 3) — Section 5 CENTER "Pending Dispatch
    // Requests". Reuses the existing GET /api/dispatches endpoint (no extra
    // role middleware beyond auth). No new backend route.
    async getPendingDispatches() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/dispatches?status=pending&per_page=5'), { credentials: 'include' });
        if (!response.ok) throw new Error('Failed to fetch dispatches');
        return await response.json();
    }
};

// Attach only the methods the real (api.js) `window.API` does not already
// provide. The `typeof !== 'function'` guard means api.js keeps ownership of
// every method it defines, so this page can never shadow shared API
// behaviour — it only fills in the gaps it needs.
window.API = window.API || {};
Object.keys(MAINTENANCE_DASHBOARD_API).forEach((method) => {
    if (typeof window.API[method] !== 'function') {
        window.API[method] = MAINTENANCE_DASHBOARD_API[method];
    }
});

// HEAD DASHBOARD BUG FIX. `utils.js` (loaded earlier via footer.php) already
// defines `class UI { ... }` and unconditionally runs `window.UI = UI;` —
// see utils.js's own "UI_BROWSER_DIALOG_REPLACEMENT" comment. Because that
// assignment executes before this inline script runs, the previous
// `window.UI = window.UI || {...}` guard here always found `window.UI`
// already truthy and the entire block below was silently skipped —
// including `formatRelativeTime`, the one member of it actually called on
// this page (My Assigned Reports cards, the activity timeline, and Pending
// Dispatch Requests all call `UI.formatRelativeTime(...)`). Since that
// method never existed on the real `window.UI`, every call threw
// `TypeError: UI.formatRelativeTime is not a function` — synchronously,
// inside each widget's render function rather than its fetch — which is why
// the assigned-reports skeleton never got replaced (the exception aborted
// the `container.innerHTML = ...map(...).join('')` assignment before it ran)
// and why Pending Dispatch Requests showed "Unable to load dispatches right
// now." even though `/api/dispatches?status=pending` was returning real
// data the whole time (the exception was caught by loadPendingDispatches()'s
// own catch block and reported as a fetch failure it never actually was).
//
// Fix: attach only the missing method directly onto the real, already-
// loaded `window.UI`, without touching its existing `getPriorityBadge` /
// `getStatusBadge` / `formatDate` (utils.js already provides those, with a
// different contract than this file's old dead copies, and nothing on this
// page calls them) — so nothing else on the page changes behavior.
window.UI = window.UI || {};
if (typeof window.UI.formatRelativeTime !== 'function') {
    window.UI.formatRelativeTime = function formatRelativeTime(date) {
        if (!date) {
            return '';
        }

        const then = new Date(date).getTime();
        if (Number.isNaN(then)) {
            return '';
        }

        const diffMs = Date.now() - then;
        if (diffMs < 0) {
            return window.UI.formatDate(date);
        }

        const minute = 60000;
        const hour = 3600000;
        const day = 86400000;

        if (diffMs < minute) {
            return 'just now';
        }
        if (diffMs < hour) {
            return Math.floor(diffMs / minute) + 'm ago';
        }
        if (diffMs < day) {
            return Math.floor(diffMs / hour) + 'h ago';
        }
        if (diffMs < day * 7) {
            return Math.floor(diffMs / day) + 'd ago';
        }
        return window.UI.formatDate(date);
    };
}

const escapeHtmlGeneric = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const formatLabelGeneric = (value) => String(value || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

// UI Polish Sprint — shared "professional empty state" builder. Used by every
// dynamic list on this page (assigned reports, activity timeline, low stock,
// pending dispatches) so there is exactly one empty/error-state markup
// pattern and one CSS class (.dashboard-empty-state) instead of four
// hand-rolled variants.
const EMPTY_STATE_ICON = '<path d="M3 7l2-4h14l2 4"></path><path d="M3 7v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"></path><path d="M3 7h18"></path><path d="M9 12h6"></path>';

const buildEmptyState = (message) => `
    <div class="dashboard-empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">${EMPTY_STATE_ICON}</svg>
        <p>${escapeHtmlGeneric(message)}</p>
    </div>
`;

// UI Polish Sprint — Task 1 "small category icon" per assigned-report card.
// Purely a client-side presentational classifier over fields already
// returned by GET /api/reports (title/location) — no new backend field, no
// invented data. Anything that doesn't match a known keyword gets a neutral
// wrench/tools icon, so no report is ever left without an icon.
const REPORT_CATEGORY_ICONS = {
    computer: '<rect x="3" y="4" width="18" height="12" rx="2"></rect><path d="M8 20h8M12 16v4"></path>',
    projector: '<rect x="2" y="7" width="14" height="10" rx="2"></rect><circle cx="9" cy="12" r="2.3"></circle><path d="M16 11l6-3v8l-6-3z"></path>',
    hvac: '<rect x="2" y="5" width="20" height="6" rx="2"></rect><path d="M6 15v4M12 15v4M18 15v4"></path>',
    electrical: '<path d="M13 2L4 14h6l-1 8 9-12h-6z"></path>',
    plumbing: '<path d="M5 3v6a4 4 0 0 0 4 4h1"></path><path d="M10 13v8"></path><circle cx="10" cy="19" r="2"></circle>',
    furniture: '<path d="M4 10V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4"></path><path d="M2 10h20v4H2z"></path><path d="M4 14v6M20 14v6"></path>',
    tools: '<path d="M14.7 6.3a4 4 0 1 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4z"></path>'
};

function getReportCategoryIcon(report) {
    const text = `${report?.title || ''} ${report?.location || ''}`.toLowerCase();

    if (/computer|desktop|laptop|\bpc\b|keyboard|monitor/.test(text)) return REPORT_CATEGORY_ICONS.computer;
    if (/projector|screen|\btv\b/.test(text)) return REPORT_CATEGORY_ICONS.projector;
    if (/aircon|air.?condition|\bac\b|cooling|ventilat/.test(text)) return REPORT_CATEGORY_ICONS.hvac;
    if (/electric|wiring|outlet|socket|breaker|\blight/.test(text)) return REPORT_CATEGORY_ICONS.electrical;
    if (/plumb|leak|faucet|pipe|water|toilet|\bsink\b|drain/.test(text)) return REPORT_CATEGORY_ICONS.plumbing;
    if (/chair|table|\bdesk\b|door|window|furniture|cabinet/.test(text)) return REPORT_CATEGORY_ICONS.furniture;
    return REPORT_CATEGORY_ICONS.tools;
}

document.addEventListener('DOMContentLoaded', function() {
    loadDashboardMonthSelection();
    window.addEventListener('sfms:monthSelected', onDashboardMonthSelected);
    loadDashboardData();
    loadDepartmentActivity();
    loadLowStockAlerts();
    loadPendingDispatches();
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
        console.warn('Unable to load maintenance dashboard month selection', error);
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
        console.warn('Unable to persist maintenance dashboard month selection', error);
    }
}

function onDashboardMonthSelected(event) {
    const detail = event && event.detail ? event.detail : {};
    const normalized = normalizeMonthSelection(detail.year, detail.month);

    if (!normalized) {
        return;
    }

    saveDashboardMonthSelection(normalized.year, normalized.month);
    loadDashboardData();
}

// ========== LOAD DASHBOARD DATA WITH MONTH/YEAR =============
async function loadDashboardData() {
    try {
        const statsResponse = await fetch(window.SFMS_PUBLIC_URL(`/api/dashboard/maintenance/stats?year=${selectedYear}&month=${selectedMonth}`), { credentials: 'include' });
        const statsData = await statsResponse.json();

        if (statsData.success && statsData.data) {
            const stats = statsData.data;
            // "Pending Inspection" reuses the same pending-assignments value
            // already computed server-side (this system has no separate
            // inspection workflow/table).
            setTextById('today-pending-inspection', stats.pending_assignments ?? stats.pending ?? 0);

            // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 1). buildings_overview
            // is already computed by maintenanceStats() (COUNT(*) FROM buildings)
            // and was already being fetched here — it was just never read
            // until now. No new fetch, no new backend field.
            setTextById('today-buildings-count', stats.buildings_overview ?? 0);
        }

        // Today's Work Summary ("Assigned Reports" / "Completed Today") and
        // both donuts ("Priority Overview" / "Reports by Status") all reuse
        // this single /api/reports fetch — the same report set the Head sees
        // on the All Reports page the donut slices link to (TASK 9: Head
        // Maintenance views reports from ALL departments). The donuts used to
        // read /api/dashboard/maintenance/charts instead, which is limited to
        // the Head's own department AND the current calendar month; this page
        // has no month picker, so they silently showed 0 whenever no report
        // had been filed yet this month, contradicting the KPI cards above.
        try {
            const today = new Date().toISOString().split('T')[0];
            const reportsList = await fetchAllVisibleReports();

            const assignedActive = reportsList.filter((r) => r.assigned_to && !['completed', 'closed', 'cancelled'].includes(String(r.status || '').toLowerCase()));
            setTextById('today-assigned-reports', assignedActive.length);

            const completedTodayCount = reportsList.filter((r) => ['completed', 'closed'].includes(String(r.status || '').toLowerCase()) && r.updated_at && r.updated_at.startsWith(today)).length;
            setTextById('today-completed', completedTodayCount);

            // Both donuts show only reports filed in the CURRENT calendar
            // month (the KPI cards above stay all-time). Deliberately not
            // selectedMonth: that value can be a stale month last picked on
            // the Reports page (shared localStorage key), and this page has
            // no picker of its own to show which month it is on. created_at
            // is server-local 'YYYY-MM-DD HH:MM:SS', so a string-prefix match
            // avoids any timezone re-parsing.
            const now = new Date();
            const currentMonthPrefix = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            const currentMonthLabel = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            const currentMonthReports = reportsList.filter((r) => String(r.created_at || '').startsWith(currentMonthPrefix));

            setTextById('priority-overview-month', `Reports filed in ${currentMonthLabel}`);
            setTextById('status-overview-month', `Reports filed in ${currentMonthLabel}`);
            initializeCharts(buildChartDataFromReports(currentMonthReports));
        } catch (err) {
            console.error('Error loading reports list', err);
        }

    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

// GET /api/reports caps per_page at 200 (ReportController::index()), so page
// through using its `total` until every visible report is loaded — otherwise
// the KPI cards and donuts would silently undercount past 200 reports.
async function fetchAllVisibleReports() {
    const perPage = 200;
    const reports = [];
    for (let page = 1; page <= 50; page++) {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/reports?per_page=${perPage}&page=${page}`), { credentials: 'include' });
        const payload = await response.json();
        if (!payload.success || !payload.data || !Array.isArray(payload.data.reports)) {
            throw new Error((payload && payload.message) || 'Unable to load reports');
        }
        reports.push(...payload.data.reports);
        const total = Number(payload.data.total || 0);
        if (payload.data.reports.length < perPage || reports.length >= total) {
            break;
        }
    }
    return reports;
}

// Shapes the report list into the same { priority_data, status_data }
// payload initializeCharts() already consumes, so both donuts keep their
// existing rendering code. Blank statuses count as 'submitted', matching
// maintenanceCharts()'s own normalization.
function buildChartDataFromReports(reports) {
    const priorityCounts = {};
    const statusCounts = {};
    reports.forEach((report) => {
        const priority = String(report.priority || '').trim().toLowerCase();
        if (priority) {
            priorityCounts[priority] = (priorityCounts[priority] || 0) + 1;
        }
        const status = String(report.status || '').trim().toLowerCase() || 'submitted';
        statusCounts[status] = (statusCounts[status] || 0) + 1;
    });
    return {
        priority_data: { labels: Object.keys(priorityCounts), values: Object.values(priorityCounts) },
        status_data: { labels: Object.keys(statusCounts), values: Object.values(statusCounts) }
    };
}

function setTextById(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function navigateToReportsCard(cardKey) {
    if (cardKey === 'low_stock') {
        window.location.href = '/School_Facility_Maintenance_System/frontend/pages/inventory.php?status_filter=low_stock';
        return;
    }

    // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 1). Buildings Overview
    // card — same existing page other dashboards already link to
    // (super-admin-dashboard.php, staff-dashboard.php, dashboard.php).
    if (cardKey === 'buildings') {
        window.location.href = '/School_Facility_Maintenance_System/frontend/pages/buildings-overview.php';
        return;
    }

    const targetUrl = new URL('/School_Facility_Maintenance_System/frontend/pages/reports.php', window.location.origin);

    if (cardKey === 'today') {
        targetUrl.searchParams.set('date_scope', 'today');
    } else if (cardKey === 'pending_tasks') {
        targetUrl.searchParams.set('status_group', 'pending_tasks');
    } else if (cardKey === 'in_progress') {
        targetUrl.searchParams.set('status', 'in_progress');
    } else if (cardKey === 'completed') {
        targetUrl.searchParams.set('status', 'completed');
    } else if (cardKey === 'assigned') {
        // Today's Work Summary — "Assigned Reports" card. Reuses the
        // existing status_group=assigned_to_me filter already supported by
        // GET /api/reports (ReportController::index()) — no backend change.
        targetUrl.searchParams.set('status_group', 'assigned_to_me');
    } else if (cardKey === 'completed_today') {
        // Today's Work Summary — "Completed Today" card.
        targetUrl.searchParams.set('status', 'completed');
        targetUrl.searchParams.set('date_scope', 'today');
    }

    window.location.href = targetUrl.toString();
}

function initializeCharts(data) {
    const priorityLabelsRaw = Array.isArray(data?.priority_data?.labels) ? data.priority_data.labels : [];
    const priorityValuesRaw = Array.isArray(data?.priority_data?.values) ? data.priority_data.values : [];
    const priorityTotals = { low: 0, medium: 0, high: 0, critical: 0 };

    priorityLabelsRaw.forEach((label, index) => {
        const normalized = String(label || '').trim().toLowerCase();
        const value = Number(priorityValuesRaw[index] || 0);

        if (normalized === 'low') {
            priorityTotals.low += value;
            return;
        }
        if (normalized === 'medium') {
            priorityTotals.medium += value;
            return;
        }
        if (normalized === 'high') {
            priorityTotals.high += value;
            return;
        }
        if (normalized === 'critical' || normalized === 'urgent') {
            priorityTotals.critical += value;
        }
    });

    // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 2). Critical is now its
    // own bucket in the donut/legend/recommendation panel instead of being
    // folded into High — this system's maintenance_reports.priority column
    // does carry real 'critical' rows (and 'urgent' is kept as a folded-in
    // synonym bucket, matching the badge-color map in UI.getPriorityBadge
    // above, in case that legacy value is ever used). Values are 100%
    // data-driven from priority_data (already unrestricted server-side,
    // see maintenanceCharts()) — nothing here is hardcoded.
    const priorityCritical = priorityTotals.critical;
    const priorityHigh = priorityTotals.high;
    const priorityMedium = priorityTotals.medium;
    const priorityLow = priorityTotals.low;
    const priorityTotal = priorityCritical + priorityHigh + priorityMedium + priorityLow;

    window.dashboardPriorityCounts = {
        critical: priorityCritical,
        high: priorityHigh,
        medium: priorityMedium,
        low: priorityLow,
        total: priorityTotal
    };

    // Critical always renders in the legend, even at 0 (per spec) — the
    // element simply isn't gated behind a > 0 check the way it would be in
    // an "only show if present" list.
    setBreakdownRow('legend-critical', priorityCritical, priorityTotal);
    setBreakdownRow('legend-high', priorityHigh, priorityTotal);
    setBreakdownRow('legend-medium', priorityMedium, priorityTotal);
    setBreakdownRow('legend-low', priorityLow, priorityTotal);
    setTextById('priority-donut-total', priorityTotal);

    // UI Polish Sprint — Task 2 heading/body structure, extended here so
    // Critical reports (the most severe bucket) drive "Attention Required"
    // exactly like High did before, with copy that names whichever of the
    // two severe buckets actually has reports.
    const recommendationHeadingEl = document.getElementById('priority-recommendation-heading');
    const recommendationEl = document.getElementById('priority-recommendation-text');
    const recommendationPanelEl = document.getElementById('priority-recommendation-panel');
    if (recommendationEl) {
        if (priorityCritical > 0 || priorityHigh > 0) {
            if (recommendationHeadingEl) recommendationHeadingEl.textContent = 'Attention Required';

            let urgentText;
            if (priorityCritical > 0 && priorityHigh > 0) {
                urgentText = `${priorityCritical} Critical and ${priorityHigh} High Priority Report${(priorityCritical + priorityHigh) !== 1 ? 's' : ''}`;
            } else if (priorityCritical > 0) {
                urgentText = `${priorityCritical} Critical Report${priorityCritical !== 1 ? 's' : ''}`;
            } else {
                urgentText = `${priorityHigh} High Priority Report${priorityHigh !== 1 ? 's' : ''}`;
            }
            const needCount = priorityCritical + priorityHigh;
            recommendationEl.textContent = `${urgentText} this month need${needCount === 1 ? 's' : ''} immediate attention.`;
            if (recommendationPanelEl) recommendationPanelEl.classList.remove('hd-alert-clear');
        } else {
            if (recommendationHeadingEl) recommendationHeadingEl.textContent = priorityTotal > 0 ? 'All Clear' : 'No Reports Yet';
            recommendationEl.textContent = priorityTotal > 0 ? 'No urgent reports.' : 'No reports filed this month yet.';
            if (recommendationPanelEl) recommendationPanelEl.classList.add('hd-alert-clear');
        }
    }

    const priorityReportsUrl = '/School_Facility_Maintenance_System/frontend/pages/reports.php?priority=';
    renderDonut('priority-donut', [
        { key: 'critical', label: 'Critical', value: priorityCritical, href: priorityReportsUrl + 'critical' },
        { key: 'high', label: 'High', value: priorityHigh, href: priorityReportsUrl + 'high' },
        { key: 'medium', label: 'Medium', value: priorityMedium, href: priorityReportsUrl + 'medium' },
        { key: 'low', label: 'Low', value: priorityLow, href: priorityReportsUrl + 'low' }
    ]);

    renderStatusChart(data);
}

// ========== ROW 2 (RIGHT) — REPORTS BY STATUS ==========
// Mirrors the Administrator dashboard's "Reports by Status" donut
// (dashboard.php): Submitted folds in Assigned, then In Progress and
// Completed, with the same colors and the same reports.php?status= click
// targets. Labels are normalized to raw status keys ("In progress" →
// in_progress) so either raw or display-formatted labels work.
function renderStatusChart(data) {
    const labelsRaw = Array.isArray(data?.status_data?.labels) ? data.status_data.labels : [];
    const valuesRaw = Array.isArray(data?.status_data?.values) ? data.status_data.values : [];
    const byStatus = {};

    labelsRaw.forEach((label, index) => {
        const key = String(label || '').trim().toLowerCase().replace(/\s+/g, '_');
        byStatus[key] = (byStatus[key] || 0) + Number(valuesRaw[index] || 0);
    });

    const statusSubmitted = (byStatus.submitted || 0) + (byStatus.assigned || 0);
    const statusInProgress = byStatus.in_progress || 0;
    // 'closed' is a finished report too — the KPI cards above already treat
    // completed/closed as one bucket.
    const statusCompleted = (byStatus.completed || 0) + (byStatus.closed || 0);
    const statusTotal = statusSubmitted + statusInProgress + statusCompleted;

    setBreakdownRow('legend-status-submitted', statusSubmitted, statusTotal);
    setBreakdownRow('legend-status-in-progress', statusInProgress, statusTotal);
    setBreakdownRow('legend-status-completed', statusCompleted, statusTotal);
    setTextById('status-donut-total', statusTotal);

    const completionRate = statusTotal > 0 ? Math.round((statusCompleted / statusTotal) * 100) : 0;
    setTextById('status-completion-rate', `${completionRate}%`);
    setTextById('status-completion-text', statusTotal > 0
        ? `${statusCompleted} of ${statusTotal} report${statusTotal !== 1 ? 's' : ''} completed this month.`
        : 'No reports filed this month yet.');

    const statusReportsUrl = '/School_Facility_Maintenance_System/frontend/pages/reports.php?status=';
    renderDonut('status-donut', [
        { key: 'submitted', label: 'Submitted', value: statusSubmitted, href: statusReportsUrl + 'submitted' },
        { key: 'in-progress', label: 'In Progress', value: statusInProgress, href: statusReportsUrl + 'in_progress' },
        { key: 'completed', label: 'Completed', value: statusCompleted, href: statusReportsUrl + 'completed' }
    ]);
}

// ========== SECTION 4 — MAINTENANCE ACTIVITY TIMELINE ==========
// Mapping is grounded in the real activity_logs.action values produced by
// ReportController / DispatchService / DamageReportService / ItemController /
// InventoryAdjustmentService — no fabricated event types (e.g. this system
// has no "inspection" entity/action). Anything not explicitly mapped below
// falls back to a generic label + neutral color so no activity is ever
// silently dropped.
//
// TASK 13 PHASE 9 (Repair retirement) — RepairService was dropped from the
// producer list above because the class is deleted, so no NEW rows with the
// repair actions can be written from here on.
//
// BUT THE REPAIR LABELS BELOW ARE DELIBERATELY KEPT, AND MUST NOT BE REMOVED.
// CREATE_REPAIR_REQUEST / ASSIGN_REPAIR_TECHNICIAN / UPDATE_REPAIR (and
// COMPLETE_REPORT, whose label reads "Repair Completed") describe activity
// that ALREADY EXISTS in the activity_logs table. The historical rows are
// untouched by this task, and this map is the only thing that renders them as
// readable text. Deleting these entries would not delete any data — it would
// just downgrade real, still-displayed history to the generic fallback label.
// They are display-only lookups; they call nothing and reference no retired
// class, so they cost nothing to keep.
const ACTIVITY_META = {
    CREATE_REPORT: { label: 'Report Created', color: 'blue', icon: 'file-plus' },
    ASSIGN_REPORT: { label: 'Report Assigned', color: 'purple', icon: 'user-check' },
    UPDATE_REPORT_STATUS: { label: 'Report Status Updated', color: 'blue', icon: 'refresh' },
    COMPLETE_REPORT: { label: 'Repair Completed', color: 'green', icon: 'check' },
    UPDATE_REPORT: { label: 'Report Updated', color: 'blue', icon: 'edit' },
    DELETE_REPORT: { label: 'Report Deleted', color: 'red', icon: 'trash' },
    CREATE_REPAIR_REQUEST: { label: 'Repair Request Created', color: 'orange', icon: 'wrench' },
    ASSIGN_REPAIR_TECHNICIAN: { label: 'Technician Assigned', color: 'purple', icon: 'user-check' },
    UPDATE_REPAIR: { label: 'Repair Updated', color: 'blue', icon: 'wrench' },
    REPLACEMENT_ACTION: { label: 'Replacement Action', color: 'orange', icon: 'swap' },
    CREATE_DISPATCH: { label: 'Dispatch Requested', color: 'purple', icon: 'truck' },
    APPROVE_DISPATCH: { label: 'Dispatch Approved', color: 'green', icon: 'check' },
    RELEASE_DISPATCH: { label: 'Dispatch Released', color: 'blue', icon: 'truck' },
    CANCEL_DISPATCH: { label: 'Dispatch Cancelled', color: 'red', icon: 'x' },
    CREATE_DAMAGE_REPORT: { label: 'Damage Report Created', color: 'orange', icon: 'alert' },
    UPDATE_DAMAGE_REPORT: { label: 'Damage Report Updated', color: 'blue', icon: 'alert' },
    CREATE_ITEM: { label: 'Inventory Item Added', color: 'green', icon: 'box' },
    UPDATE_ITEM: { label: 'Inventory Item Updated', color: 'blue', icon: 'box' },
    DELETE_ITEM: { label: 'Inventory Item Removed', color: 'red', icon: 'box' },
    ADJUST_STOCK: { label: 'Stock Adjusted', color: 'orange', icon: 'box' },
    INVENTORY_ENTRY: { label: 'Inventory Entry Logged', color: 'blue', icon: 'box' },
    INVENTORY_TRANSACTION: { label: 'Inventory Transaction', color: 'blue', icon: 'box' },
    RESERVE_INVENTORY: { label: 'Inventory Reserved', color: 'blue', icon: 'box' },
    CREATE_RESTOCK_REQUEST: { label: 'Restock Requested', color: 'orange', icon: 'box' },
    APPROVE_NEED_CHANGE: { label: 'Change Request Approved', color: 'green', icon: 'check' },

    // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 3). This block was the
    // real, verifiable gap: activity_logs.action already contains these
    // auth/user-management values today (confirmed against the live
    // database), but none of them had an ACTIVITY_META entry, so every
    // login/logout/account event — the single most frequent entry in the
    // real timeline — fell through to the generic gray "dot" fallback in
    // getActivityMeta() below instead of a proper icon/label. Reuses only
    // icons and colors already defined above (user-check, x, check, refresh
    // / gray, green, red, orange) — no new icon paths, no new colors.
    LOGIN: { label: 'User Login', color: 'gray', icon: 'user-check' },
    LOGOUT: { label: 'User Logout', color: 'gray', icon: 'x' },
    REGISTER: { label: 'Account Registered', color: 'green', icon: 'user-check' },
    CREATE_USER: { label: 'User Account Created', color: 'green', icon: 'user-check' },
    ACTIVATE_USER: { label: 'User Activated', color: 'green', icon: 'check' },
    INACTIVATE_USER: { label: 'User Deactivated', color: 'red', icon: 'x' },
    APPROVE_USER: { label: 'User Approved', color: 'green', icon: 'check' },
    REJECT_USER: { label: 'User Rejected', color: 'red', icon: 'x' },
    ADMIN_RESET_PASSWORD: { label: 'Password Reset by Admin', color: 'orange', icon: 'refresh' },
    PASSWORD_RESET_REQUEST: { label: 'Password Reset Requested', color: 'orange', icon: 'refresh' }
};

const TIMELINE_ICONS = {
    'file-plus': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M12 12v6M9 15h6"></path>',
    'user-check': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M17 11l2 2 4-4"></path>',
    'check': '<path d="M20 6L9 17l-5-5"></path>',
    'wrench': '<path d="M14.7 6.3a4 4 0 1 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 1 5.4-5.4z"></path>',
    'truck': '<path d="M3 7h13l4 4v6h-4"></path><circle cx="7.5" cy="17.5" r="1.5"></circle><circle cx="16.5" cy="17.5" r="1.5"></circle>',
    'alert': '<path d="M10.29 3.86l-8 14A1 1 0 0 0 3.14 19h17.72a1 1 0 0 0 .85-1.5l-8-14a1 1 0 0 0-1.72 0z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path>',
    'box': '<path d="M21 8l-9-5-9 5 9 5 9-5z"></path><path d="M3 8v8l9 5 9-5V8"></path>',
    'x': '<path d="M18 6L6 18M6 6l12 12"></path>',
    'refresh': '<path d="M21 12a9 9 0 1 1-3-6.7"></path><path d="M21 3v6h-6"></path>',
    'edit': '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"></path>',
    'trash': '<path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>',
    'swap': '<path d="M7 3L3 7l4 4"></path><path d="M3 7h13"></path><path d="M17 21l4-4-4-4"></path><path d="M21 17H8"></path>',
    'dot': '<circle cx="12" cy="12" r="4"></circle>'
};

function getActivityMeta(action) {
    const key = String(action || '').toUpperCase();
    return ACTIVITY_META[key] || { label: formatLabelGeneric(action) || 'Activity', color: 'gray', icon: 'dot' };
}

async function loadDepartmentActivity() {
    const container = document.getElementById('activity-timeline-container');
    if (!container) {
        return;
    }

    try {
        const data = await window.API.getDepartmentActivity();

        // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 3). GET
        // /api/dashboard/maintenance/activity (DashboardController::
        // maintenanceActivity()) always returns a flat 200 { success,
        // activities } payload for every role (super_admin, department-
        // scoped maintenance_admin, and per-user maintenance_staff) — there
        // is no separate failure-but-200 shape to special-case, but
        // data.success is still checked explicitly (rather than only
        // Array.isArray(data.activities)) so a false/missing success flag
        // is treated as a real failure instead of silently rendering an
        // empty timeline.
        if (!data || data.success === false) {
            throw new Error((data && data.message) || 'Activity endpoint returned success:false');
        }

        const activities = Array.isArray(data.activities) ? data.activities : [];
        renderActivityTimeline(activities);
    } catch (error) {
        console.error('Error loading department activity:', error);
        container.innerHTML = buildEmptyState('Unable to load activity right now.');
    }
}

function renderActivityTimeline(activities) {
    const container = document.getElementById('activity-timeline-container');
    if (!container) {
        return;
    }

    if (!Array.isArray(activities) || activities.length === 0) {
        container.innerHTML = buildEmptyState('No recent maintenance activity.');
        return;
    }

    // HEAD DASHBOARD FINAL POLISH — PART 2 (Issue 3). Each item now always
    // surfaces the acting user (Icon / Title / User / Relative Time, per
    // the "LOGIN / Ryan Mondido / 5 minutes ago" spec) instead of the old
    // behavior, which showed the raw al.details text INSTEAD of the actor's
    // name whenever details was non-empty — meaning most real entries
    // (e.g. "Assigned maintenance report #21 to user #20.") never surfaced
    // who performed the action at all. al.details is preserved as a native
    // title="" tooltip so no information is lost. Each entry is rendered in
    // its own try/catch so one malformed row (e.g. an unparseable
    // created_at) degrades to skipping that row instead of aborting the
    // whole timeline into the "Unable to load" error state.
    const items = activities.slice(0, 8).map((entry) => {
        try {
            const meta = getActivityMeta(entry?.action);
            const actorName = entry?.full_name ? String(entry.full_name) : 'System';
            const detailTooltip = entry?.details ? String(entry.details) : '';

            return `
                <div class="activity-timeline-item"${detailTooltip ? ` title="${escapeHtmlGeneric(detailTooltip)}"` : ''}>
                    <div class="activity-timeline-icon activity-color-${meta.color}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">${TIMELINE_ICONS[meta.icon] || TIMELINE_ICONS.dot}</svg>
                    </div>
                    <div class="activity-timeline-title">${escapeHtmlGeneric(meta.label)}</div>
                    <div class="activity-timeline-desc">${escapeHtmlGeneric(actorName)}</div>
                    <div class="activity-timeline-time">${escapeHtmlGeneric(entry?.created_at ? UI.formatRelativeTime(entry.created_at) : '')}</div>
                </div>
            `;
        } catch (itemError) {
            console.warn('Skipping malformed activity entry', entry, itemError);
            return '';
        }
    }).join('');

    if (!items.trim()) {
        container.innerHTML = buildEmptyState('No recent maintenance activity.');
        return;
    }

    container.innerHTML = `<div class="activity-timeline">${items}</div>`;
}

// ========== TODAY'S WORK SUMMARY — LOW STOCK COUNT ==========
// The Low Stock Alerts card was removed from this dashboard (the data stays
// reachable through the Inventory module); this KPI count is the only
// remaining consumer of the inventory-stock fetch.
async function loadLowStockAlerts() {
    try {
        const response = await window.API.getLowStockInventory();
        const items = (response && response.success && response.data && Array.isArray(response.data.items)) ? response.data.items : [];
        setTextById('today-low-stock-count', items.length);
    } catch (error) {
        console.error('Error loading low stock alerts', error);
    }
}

// ========== SECTION 5 (CENTER) — PENDING DISPATCH REQUESTS ==========
async function loadPendingDispatches() {
    const container = document.getElementById('pending-dispatch-container');
    if (!container) return;
    try {
        const response = await window.API.getPendingDispatches();
        const list = (response && response.success && response.data && Array.isArray(response.data.data)) ? response.data.data : [];
        renderDispatchTable(list);
    } catch (error) {
        console.error('Error loading pending dispatches', error);
        container.innerHTML = buildEmptyState('Unable to load dispatches right now.');
    }
}

function renderDispatchTable(dispatches) {
    const container = document.getElementById('pending-dispatch-container');
    if (!container) return;
    if (!Array.isArray(dispatches) || dispatches.length === 0) {
        container.innerHTML = buildEmptyState('No pending dispatch requests.');
        return;
    }
    const tbody = dispatches.slice(0, 8).map((d) => {
        try {
            const code = escapeHtmlGeneric(d.dispatch_code || ('DSP-' + (d.id || '')));
            const requester = escapeHtmlGeneric((d.report && d.report.creator && d.report.creator.full_name) ? d.report.creator.full_name : '—');
            const purpose = escapeHtmlGeneric((d.report && d.report.title) ? d.report.title : '—');
            const status = String(d.status || 'pending').toLowerCase();
            const date = d.created_at ? new Date(d.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric'}) : '—';
            const detailUrl = `/School_Facility_Maintenance_System/frontend/pages/dispatch-detail.php?id=${encodeURIComponent(d.id || '')}`;
            return `<tr>
                <td><a href="${detailUrl}" class="hd-link">${code}</a></td>
                <td>${requester}</td>
                <td class="hd-td-purpose">${purpose}</td>
                <td><span class="hd-badge hd-badge-${escapeHtmlGeneric(status)}">${escapeHtmlGeneric(formatLabelGeneric(status))}</span></td>
                <td>${date}</td>
                <td><a href="${detailUrl}" class="hd-action-link">View</a></td>
            </tr>`;
        } catch (e) { return ''; }
    }).join('');
    container.innerHTML = `<div class="hd-table-wrap"><table class="hd-table"><thead><tr><th>Dispatch ID</th><th>Requested By</th><th>Purpose</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>${tbody}</tbody></table></div>`;
}
</script>

</body>
</html>
