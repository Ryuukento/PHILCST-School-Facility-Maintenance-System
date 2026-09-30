<?php
/**
 * Maintenance Report Detail View
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
$reportId = $_GET['id'] ?? 0;

require_once __DIR__ . '/../../backend/config/database.php';
$pdo = getDBConnection();

$currentRole = strtolower(trim((string)($user['role'] ?? ($_SESSION['role'] ?? ''))));
if ($currentRole === 'super admin' || $currentRole === 'superadmin') {
    $currentRole = 'super_admin';
}
$isSuperAdmin = ($currentRole === 'super_admin');
// TASK 9 — Role + Department Based Authorization. report.department_id is
// only known once the report loads (client-side fetch), so the actual
// hide/disable decision happens in JS (see CURRENT_USER_DEPARTMENT_ID /
// applyDepartmentAuthorizationUI() below), mirroring the backend's
// canModifyReport() rule: Administrator is exempt, everyone else needs
// user.department_id === report.department_id.
$currentUserDepartmentId = $user['department_id'] ?? null;

// Problem Type — only the value -> icon mapping is needed here, since this
// page displays a saved category rather than offering the choice. Read from
// the same config file the Create/Edit grids and the backend's `in:` rule use,
// so a category can never render with the wrong icon or be missing one.
$problemTypeConfig = require __DIR__ . '/../../../config/maintenance_reports.php';
$problemTypeIcons = [];
foreach (($problemTypeConfig['problem_types'] ?? []) as $problemType) {
    $problemTypeIcons[(string) ($problemType['value'] ?? '')] = (string) ($problemType['icon'] ?? '');
}
$problemTypeOtherValue = $problemTypeConfig['problem_type_other_value'] ?? 'Other';

// 2026-09-30 — the Administrator no longer has any status-change action on
// a report (previously could only Cancel it; that capability was retired at
// the Capstone Panel's request). The Administrator now only approves/
// monitors and can reopen an archived report; Head Maintenance runs the
// work end to end. The API enforces the same rule in
// ReportController::update() (no super_admin branch there anymore).
$allowedStatusOptions = [];
if ($currentRole === 'maintenance_admin') {
    $allowedStatusOptions = [
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled'
    ];
} elseif ($currentRole === 'maintenance_staff') {
    // Staff may only set In Progress / Completed (API-enforced); "Closed"
    // was offered here before but the API always refused it.
    $allowedStatusOptions = [
        'in_progress' => 'In Progress',
        'completed' => 'Completed'
    ];
}
// Reopen = completed -> in_progress: Head, and the assigned Staff.
$canReopenReport = in_array($currentRole, ['maintenance_admin', 'maintenance_staff'], true);

$canAssignUser = $currentRole === 'maintenance_admin';
$assignmentTargetsByRole = [
    'maintenance_admin' => [],
    'maintenance_staff' => [],
];
$assignmentLabel = 'Assign To';
$assignmentPlaceholder = 'Select assignee...';
$defaultAssignmentRoleFilter = 'maintenance_staff';
$backContext = strtolower(trim((string)($_GET['back'] ?? '')));
$backToReportsUrl = in_array($currentRole, ['super_admin', 'maintenance_admin'], true)
    ? '/School_Facility_Maintenance_System/frontend/pages/reports.php'
    : '/School_Facility_Maintenance_System/frontend/pages/reports.php';

if ($backContext === 'all_reports') {
    $backToReportsUrl = '/School_Facility_Maintenance_System/frontend/pages/reports.php';
}

if ($canAssignUser) {
    // TASK 44 (Cross-Department Assignment Warning) — these are the same
    // personnel queries that already ran to build the assignment picker; the
    // only change is that each row now also carries the personnel's own
    // department id and name, so the confirmation dialog below can tell the
    // user which department the selected personnel belongs to. No additional
    // query is issued, and no filtering is applied: personnel from every
    // department remain selectable exactly as before.
    $assignmentTargetSelect = "SELECT u.user_id, u.username, u.full_name, u.email, u.designation,"
        . " COALESCE(NULLIF(u.full_name, ''), u.username, u.designation) as display_name,"
        . " u.department_id, d.name AS department_name"
        . " FROM users u"
        . " LEFT JOIN departments d ON d.department_id = u.department_id";
    $assignmentTargetOrder = " AND u.status = 'active' ORDER BY u.full_name, u.username";

    if ($currentRole === 'maintenance_admin') {
        $assignmentLabel = 'Assign To (Maintenance Staff)';
        $assignmentPlaceholder = 'Select maintenance staff...';
        $stmt = $pdo->query($assignmentTargetSelect . " WHERE u.role IN ('maintenance_staff')" . $assignmentTargetOrder);
        $assignmentTargetsByRole['maintenance_staff'] = $stmt->fetchAll();
    } else {
        $assignmentLabel = 'Assign To (Maintenance Team)';
        $stmt = $pdo->query($assignmentTargetSelect . " WHERE u.role IN ('maintenance_admin')" . $assignmentTargetOrder);
        $assignmentTargetsByRole['maintenance_admin'] = $stmt->fetchAll();
        $stmt = $pdo->query($assignmentTargetSelect . " WHERE u.role IN ('maintenance_staff')" . $assignmentTargetOrder);
        $assignmentTargetsByRole['maintenance_staff'] = $stmt->fetchAll();
    }
}
$pageTitle = 'Report Details - School Facility Maintenance System';
// Theme is applied by the inline script in header.php's own <head>; this page no longer
// carries its own duplicate copy now that it shares a single document shell. header.php's
// own <body> tag also already sets data-user-role from the same $user['role'] value.
$pageStylesheets = [
    '/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.css',
    '/School_Facility_Maintenance_System/frontend/assets/css/light-mode-polish.css?v=20260921-2',
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1',
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-workflow.css?v=20260726-1',
];
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container assigned-report-page maintenance-workflow-page">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <div class="report-title-row">
                    <span id="report-id-badge" class="report-id-badge"></span>
                    <h2 id="report-title" class="report-detail-title">Loading...</h2>
                </div>
                <p class="text-muted mb-0">Report Details</p>
            </div>
            <a href="<?php echo htmlspecialchars($backToReportsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">
                <?php echo ui_icon('arrow-left'); ?> Back to Reports
            </a>
        </div>
        
        <div class="card-body" id="report-details">
            <div class="loading">Loading report details...</div>
        </div>
    </div>

    <!-- Report Archive — shown for a report filed in a past academic term.
         Filled by applyArchiveUI(); the API (report.archive) decides the state
         and enforces it. Only the Administrator sees the reopen / lock-again
         buttons, and only the Administrator's routes accept them. -->
    <div class="card mt-lg report-archive-card" id="archive-state-card" hidden>
        <div class="card-body report-archive-card-body">
            <span class="report-archive-card-icon"><?php echo ui_icon('archive', ['size' => 22]); ?></span>
            <div class="report-archive-card-text">
                <strong id="archive-state-title">Archived report</strong>
                <span id="archive-state-text"></span>
                <div id="archive-state-alert"></div>
            </div>
            <?php if ($isSuperAdmin): ?>
            <div class="report-archive-card-actions">
                <button type="button" class="btn btn-warning" id="archive-reopen-btn" hidden><?php echo ui_icon('rotate-ccw'); ?> Reopen for changes</button>
                <button type="button" class="btn btn-secondary" id="archive-lock-btn" hidden><?php echo ui_icon('lock'); ?> Lock again</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Update Card — 2026-09-30: the Administrator (super_admin) has
         no status-change action left on a report (the former Cancel-only
         capability was retired at the Capstone Panel's request), so this
         entire card is no longer rendered for that role. -->
    <?php if ($currentRole !== 'super_admin'): ?>
    <div class="card mt-lg" id="update-status-card">
        <div class="card-header">
            <span class="report-actions-kicker">Report Actions</span>
            <h2>Update Status</h2>
            <p class="text-muted mb-0" style="font-size:13px;margin-top:2px;">Change the status, assign staff, or attach completion proof for this report.</p>
        </div>
        <div class="card-body">
            <div id="department-restricted-notice" class="alert alert-info" style="display:none;">
                This report belongs to a different department. You have view-only access and cannot modify it.
            </div>
            <div id="status-alert"></div>
            <form id="status-update-form">
                <div class="form-group">
                    <label for="new-status">New Status</label>
                    <select id="new-status" required>
                        <option value="">Select new status...</option>
                        <?php foreach ($allowedStatusOptions as $statusValue => $statusLabel): ?>
                            <option value="<?php echo htmlspecialchars($statusValue); ?>" <?php echo ($statusValue === 'assigned') ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($canAssignUser): ?>
                <div class="form-group" id="assigned-to-group" style="display:none;">
                    <label for="assigned-to"><?php echo htmlspecialchars($assignmentLabel); ?></label>

                    <div class="assignment-search-wrap">
                        <input type="text" id="assigned-to-search" class="form-control" placeholder="Type name to search..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                        <input type="hidden" id="assigned-to" value="">
                        <div id="assigned-to-selected" class="assignment-selected"></div>
                        <div id="assigned-to-results" class="assignment-results"></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group" id="completion-proof-group" style="display:none;">
                    <label for="completion-proof-image">Completion Proof Image</label>
                    <input type="file" id="completion-proof-image" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small class="text-muted">Upload a photo showing that the issue has been fixed.</small>
                    <div id="completion-proof-preview" style="margin-top: 10px;"></div>
                </div>

                <div class="form-group">
                    <label for="status-comment">Comment (optional)</label>
                    <textarea id="status-comment" placeholder="Add a comment about this status change..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary" id="update-status-btn">Update Status</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canReopenReport): ?>
    <div class="card mt-lg" id="reopen-report-card" style="display:none;">
        <div class="card-header">
            <h2>Reopen Report</h2>
            <p class="text-muted mb-0">Revert this report back to In Progress for further action.</p>
        </div>
        <div class="card-body">
            <div id="reopen-alert"></div>
            <button type="button" class="btn btn-warning" id="reopen-report-btn"><?php echo ui_icon('rotate-ccw'); ?> Reopen Report</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mt-lg" id="need-change-approval-card" style="display:none;">
        <div class="card-header">
            <h2>Need Change Approval</h2>
            <p class="text-muted mb-0">Approve or reject replacement request. Stock is deducted only after approval.</p>
        </div>
        <div class="card-body">
            <div id="need-change-approval-meta" class="need-change-approval-meta"></div>
            <div id="need-change-approval-alert"></div>
            <div class="d-flex gap-sm">
                <button type="button" class="btn btn-success" id="approve-need-change-btn"><?php echo ui_icon('check'); ?> Approve Request</button>
                <button type="button" class="btn btn-danger" id="reject-need-change-btn"><?php echo ui_icon('x'); ?> Reject Request</button>
            </div>
        </div>
    </div>

</main>

<!-- UI_BROWSER_DIALOG_REPLACEMENT — the page-local confirm modal markup/CSS
     that used to live here (duplicate #system-confirm-modal, colliding with
     the shared component's dynamically-created element) has been removed.
     Confirmations on this page now go through the single shared
     UI.systemConfirm() modal via the showSystemConfirm() wrapper below. -->

<style>
/* Report Archive card (see applyArchiveUI()). */
.report-archive-card[hidden] { display: none; }
.report-archive-card { border-left: 4px solid #8b5cf6; }
.report-archive-card.is-carried { border-left-color: #f59e0b; }
.report-archive-card-body { display: flex; align-items: flex-start; gap: 14px; flex-wrap: wrap; }
.report-archive-card-icon { color: #8b5cf6; display: inline-flex; margin-top: 2px; }
.report-archive-card.is-carried .report-archive-card-icon { color: #f59e0b; }
.report-archive-card-text { flex: 1; min-width: 220px; display: flex; flex-direction: column; gap: 3px; font-size: 13.5px; color: var(--text-secondary, #cbd5e1); }
.report-archive-card-text strong { font-size: 15px; color: var(--text-primary, #f8fafc); }
.report-archive-card-actions { display: flex; gap: 8px; align-items: center; }
.report-archive-card-actions .btn[hidden] { display: none; }
:root[data-theme-resolved='light'] .report-archive-card-text { color: #4b5563; }
:root[data-theme-resolved='light'] .report-archive-card-text strong { color: #111827; }

.assignment-role-switcher {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.assignment-role-switcher {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}

.assignment-role-btn {
    flex: 1;
    border: 2px solid rgba(148, 163, 184, 0.25);
    background: rgba(15, 23, 42, 0.72);
    color: #94a3b8;
    border-radius: 10px;
    padding: 10px 14px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.2s ease;
    position: relative;
}

.assignment-role-btn:hover:not(.is-active) {
    border-color: rgba(148, 163, 184, 0.5);
    color: #e2e8f0;
    background: rgba(30, 41, 59, 0.9);
}

.assignment-role-btn.is-active {
    background: linear-gradient(135deg, #7c3aed, #a855f7);
    border-color: #a855f7;
    color: #ffffff;
    box-shadow: 0 6px 18px rgba(124, 58, 237, 0.4);
}

/* TASK 7 — was content: ' ✓', a font glyph that rendered at a different weight
   on every platform. A ::after pseudo-element cannot hold an <svg>, so the same
   check icon is applied as a mask instead: the box is painted with
   currentColor and the mask cuts the glyph out of it. That keeps the mark
   inheriting the button's text colour, so Light and Dark theme both work with
   no second rule, and it no longer depends on an OS font. Sized in em and kept
   inline so the button's metrics are unchanged — no layout shift. */
.assignment-role-btn.is-active::after {
    content: '';
    display: inline-block;
    width: 0.9em;
    height: 0.9em;
    margin-left: 0.3em;
    vertical-align: -0.12em;
    background-color: currentColor;
    -webkit-mask: var(--assignment-check-mask) no-repeat center / contain;
    mask: var(--assignment-check-mask) no-repeat center / contain;
}

.assignment-role-btn.is-active {
    --assignment-check-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'/%3E%3C/svg%3E");
}
</style>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/maintenance-report-detail.inline.css?v=20260921-2">
<!-- Problem Type. This page only uses the read-only .problem-type-chip rule at
     the end of the file; the card/grid rules above it match nothing here. It is
     still the same stylesheet rather than a copied chip rule, so the chip and
     the selected card cannot drift apart in colour. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/problem-type-selector.css?v=20260920-2">

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- utils.js and api.js loaded by footer.php — do not load again here -->

<script>
const reportId = <?php echo intval($reportId); ?>;
const currentUserRole = <?php echo json_encode($currentRole); ?>;
const isSuperAdminUser = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
// TASK 9 — Role + Department Based Authorization (frontend enforcement).
// Administrator is exempt; every other role must match departments with the
// report before being allowed to submit a modification (Assign, Status,
// Priority, Need Change, Cancel, Close). The backend
// (ReportAuthorizationService::canModifyReport(), enforced in
// ReportController::update()) is the real authority — this is defense in
// depth only, never relied on alone.
const CURRENT_USER_DEPARTMENT_ID = <?php echo json_encode($currentUserDepartmentId); ?>;

// Problem Type display. The map is emitted from config/maintenance_reports.php
// (see the top of this file) rather than written out here, so adding a
// category there is all that is ever needed to make it render correctly.
const PROBLEM_TYPE_ICONS = <?php echo json_encode($problemTypeIcons); ?>;
const PROBLEM_TYPE_OTHER = <?php echo json_encode($problemTypeOtherValue); ?>;

// Renders the saved category as the read-only .problem-type-chip.
//
// Three cases, all of which occur in real data:
//   - no category  -> legacy row created before this field existed. Shown as
//                     "Not specified" in the same muted style every other
//                     unset field on this page already uses, NOT as an error;
//                     those reports are still perfectly valid.
//   - "Other"      -> the user's own words are what maintenance personnel
//                     actually need, so they lead, with the category kept in
//                     parentheses so the row is still recognisably a category.
//   - anything else-> icon + label, matching the card the reporter picked.
function renderProblemType(report) {
    const value = report.problem_type || '';
    if (!value) {
        return '<span class="report-unassigned">Not specified</span>';
    }

    const icon = (window.UIIcons && PROBLEM_TYPE_ICONS[value])
        ? window.UIIcons.svg(PROBLEM_TYPE_ICONS[value], { size: 14 })
        : '';

    let label = UI.escapeHtml(value);
    if (value === PROBLEM_TYPE_OTHER && report.problem_type_other) {
        label = `${UI.escapeHtml(report.problem_type_other)} <span class="text-muted">(${UI.escapeHtml(value)})</span>`;
    }

    return `<span class="problem-type-chip">${icon}${label}</span>`;
}

// Report Archive — a finished report from a past academic term is view-only
// for everyone until the Administrator reopens it (API-enforced).
function isArchiveLocked(report) {
    return Boolean(report && report.archive && report.archive.is_locked);
}

function canModifyReportClientSide(report) {
    if (isArchiveLocked(report)) return false;
    if (isSuperAdminUser) return true;
    const userDept = CURRENT_USER_DEPARTMENT_ID === null || CURRENT_USER_DEPARTMENT_ID === undefined ? null : Number(CURRENT_USER_DEPARTMENT_ID);
    const reportDept = (report && (report.department_id === null || report.department_id === undefined)) ? null : Number(report.department_id);
    return userDept === reportDept;
}

function applyDepartmentAuthorizationUI(report) {
    const allowed = canModifyReportClientSide(report);
    const notice = document.getElementById('department-restricted-notice');
    if (notice) notice.style.display = allowed ? 'none' : 'block';

    const statusForm = document.getElementById('status-update-form');
    if (statusForm) {
        statusForm.querySelectorAll('select, input, textarea, button').forEach((el) => {
            el.disabled = !allowed;
        });
    }

    const reopenBtn = document.getElementById('reopen-report-btn');
    if (reopenBtn) reopenBtn.disabled = !allowed;

    return allowed;
}
const assignmentTargetsByRole = <?php echo json_encode($assignmentTargetsByRole, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const defaultAssignmentRoleFilter = <?php echo json_encode($defaultAssignmentRoleFilter); ?>;
const assignmentPlaceholder = <?php echo json_encode($assignmentPlaceholder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let currentReport = null;
let activeAssignmentRole = defaultAssignmentRoleFilter;
// TASK 44 — set for the whole duration of a status/assignment submit,
// including while the cross-department confirmation dialog is open, so a
// second submit can never race the first one into a duplicate request.
let isStatusUpdateInFlight = false;

window.UI = window.UI || {
    getPriorityBadge(priority) {
        // IT-expert priority-color correction: Critical=Red, High=Orange,
        // Medium=Yellow, Low=Blue (standardized app-wide). Dead fallback
        // (window.UI is always defined by utils.js before this runs), fixed
        // here for defense-in-depth consistency.
        const colors = {
            'low': 'badge-low',
            'medium': 'badge-medium',
            'high': 'badge-high',
            'urgent': 'badge-urgent',
            'critical': 'badge-critical'
        };
        return colors[priority] || 'badge-low';
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

function renderPriorityBadge(priority) {
    const safePriority = String(priority || '').trim().toLowerCase();
    const fallbackLabel = safePriority ? safePriority.toUpperCase() : 'N/A';

    if (!window.UI || typeof window.UI.getPriorityBadge !== 'function') {
        return `<span class="badge badge-info">${fallbackLabel}</span>`;
    }

    const raw = window.UI.getPriorityBadge(safePriority);
    const rawText = String(raw || '').trim();

    // Support both helper styles:
    // 1) returns full HTML markup: <span class="badge ...">TEXT</span>
    // 2) returns only class name: badge-danger
    if (rawText.toLowerCase().includes('<span')) {
        return rawText;
    }

    return `<span class="badge ${rawText || 'badge-info'}">${fallbackLabel}</span>`;
}

function renderStatusBadge(status) {
    const safeStatus = String(status || '').trim().toLowerCase();
    const fallbackLabel = safeStatus ? safeStatus.replace(/_/g, ' ').toUpperCase() : 'N/A';

    if (!window.UI || typeof window.UI.getStatusBadge !== 'function') {
        return `<span class="badge badge-info">${fallbackLabel}</span>`;
    }

    const raw = window.UI.getStatusBadge(safeStatus);
    const rawText = String(raw || '').trim();

    if (rawText.toLowerCase().includes('<span')) {
        return rawText;
    }

    return `<span class="badge ${rawText || 'badge-info'}">${fallbackLabel}</span>`;
}

function syncAssignedToVisibility() {
    const group = document.getElementById('assigned-to-group');
    const hiddenInput = document.getElementById('assigned-to');
    const statusSelect = document.getElementById('new-status');
    if (!group || !hiddenInput || !statusSelect) return;

    if (statusSelect.value === 'assigned') {
        group.style.display = 'block';
        renderAssignmentOptions(hiddenInput.value || (currentReport && currentReport.assigned_to ? String(currentReport.assigned_to) : ''));
    } else {
        group.style.display = 'none';
        hiddenInput.value = '';
        const si = document.getElementById('assigned-to-search');
        const se = document.getElementById('assigned-to-selected');
        const re = document.getElementById('assigned-to-results');
        if (si) si.value = '';
        if (se) { se.style.display = 'none'; se.textContent = ''; }
        if (re) { re.style.display = 'none'; re.innerHTML = ''; }
    }
}

function getAssignmentRoleLabel(roleKey) {
    if (roleKey === 'maintenance_admin') return 'Maintenance Admin';
    return 'Maintenance Staff';
}

function getAssignmentPrimaryLabel(person) {
    return String(person?.full_name || person?.username || person?.display_name || 'Unknown').trim();
}

function getAssignmentSecondaryLabel(person) {
    const details = [];
    const username = String(person?.username || '').trim();
    const designation = String(person?.designation || '').trim();
    const email = String(person?.email || '').trim();

    if (username) details.push('@' + username);
    if (designation) details.push(designation);
    if (email) details.push(email);

    return details.join(' • ');
}

function getAssignmentSearchBlob(person) {
    return [
        person?.full_name,
        person?.username,
        person?.display_name,
        person?.designation,
        person?.email,
    ].join(' ').toLowerCase();
}

function renderAssignmentOptions(selectedValue = '') {
    const searchInput = document.getElementById('assigned-to-search');
    const hiddenInput = document.getElementById('assigned-to');
    const resultsEl = document.getElementById('assigned-to-results');
    const selectedEl = document.getElementById('assigned-to-selected');
    const roleButtons = document.querySelectorAll('.assignment-role-btn');
    if (!hiddenInput || !resultsEl) return;

    // Update active role button UI
    roleButtons.forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-assignment-role') === activeAssignmentRole);
    });

    // If a selectedValue is passed (e.g. pre-existing assignment), show it
    if (selectedValue) {
        const allTargets = Object.values(assignmentTargetsByRole).flat();
        const match = allTargets.find((p) => String(p.user_id) === String(selectedValue));
        if (match) {
            hiddenInput.value = String(match.user_id);
            if (searchInput) searchInput.value = getAssignmentPrimaryLabel(match);
            if (selectedEl) {
                selectedEl.style.display = 'block';
                selectedEl.textContent = 'Assigned: ' + getAssignmentPrimaryLabel(match);
            }
        }
    }
}

function filterAssignmentResults() {
    const searchInput = document.getElementById('assigned-to-search');
    const resultsEl = document.getElementById('assigned-to-results');
    const hiddenInput = document.getElementById('assigned-to');
    if (!searchInput || !resultsEl) return;

    const keyword = searchInput.value.trim().toLowerCase();
    if (!keyword) {
        resultsEl.style.display = 'none';
        resultsEl.innerHTML = '';
        return;
    }

    // Search across all roles
    const allTargets = Object.values(assignmentTargetsByRole).flat();
    const filtered = allTargets.filter((p) => getAssignmentSearchBlob(p).includes(keyword));

    if (!filtered.length) {
        resultsEl.innerHTML = '<div class="assignment-empty">No matching personnel</div>';
        resultsEl.style.display = 'block';
        return;
    }

    const currentVal = hiddenInput ? hiddenInput.value : '';
    resultsEl.innerHTML = filtered.map((p) => {
        const isActive = String(p.user_id) === String(currentVal);
        const primary = getAssignmentPrimaryLabel(p);
        const secondary = getAssignmentSecondaryLabel(p);
        return `<button type="button" class="assignment-result-item${isActive ? ' active' : ''}" data-person-id="${p.user_id}"><div>${primary}</div>${secondary ? `<span>${secondary}</span>` : ''}</button>`;
    }).join('');
    resultsEl.style.display = 'block';
}

/* ------------------------------------------------------------------
 * TASK 44 — Cross-Department Assignment Warning
 *
 * Assigning a report to personnel from another department is ALLOWED.
 * These helpers only decide whether the user should be asked to confirm
 * that choice first. They are NOT an authorization check: nothing here
 * can block an assignment on its own, and the backend
 * (ReportAuthorizationService::canModifyReport(), enforced in
 * ReportController::update()) remains the sole authority on who may
 * modify a report. Department mismatch alone never produces a 403/422.
 *
 * Comparison is done on stable department IDs (maintenance_reports.
 * department_id vs users.department_id), never on display names; the
 * names are used only to word the message for the reader.
 * ------------------------------------------------------------------ */
function findAssignmentTargetById(userId) {
    if (userId === null || userId === undefined || String(userId).trim() === '') {
        return null;
    }
    const allTargets = Object.values(assignmentTargetsByRole).flat();
    return allTargets.find((p) => String(p.user_id) === String(userId)) || null;
}

function normalizeDepartmentId(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const numeric = Number(value);
    return Number.isNaN(numeric) ? null : numeric;
}

function describeDepartmentLabel(name, departmentId) {
    const label = String(name || '').trim();
    if (label) {
        return label;
    }
    return departmentId === null ? 'No department assigned' : ('Department #' + departmentId);
}

function isCrossDepartmentAssignment(report, person) {
    if (!report || !person) {
        return false;
    }
    return normalizeDepartmentId(report.department_id) !== normalizeDepartmentId(person.department_id);
}

function confirmCrossDepartmentAssignment(report, person) {
    const reportDepartmentId = normalizeDepartmentId(report && report.department_id);
    const personnelDepartmentId = normalizeDepartmentId(person && person.department_id);

    return showSystemConfirm({
        title: '⚠ Cross-Department Assignment',
        message:
            'Report Department: ' + describeDepartmentLabel(report && report.department_name, reportDepartmentId) + '\n'
            + 'Selected Personnel: ' + getAssignmentPrimaryLabel(person) + '\n'
            + 'Personnel Department: ' + describeDepartmentLabel(person && person.department_name, personnelDepartmentId) + '\n\n'
            + 'This report belongs to a different department from the selected personnel.\n\n'
            + 'Cross-department assignments are allowed. Please confirm that this personnel is appropriate to handle this report.',
        confirmText: 'Assign Anyway',
        cancelText: 'Cancel',
        confirmClass: 'btn btn-warning'
    });
}

function syncCompletionProofVisibility() {
    const statusSelect = document.getElementById('new-status');
    const proofGroup = document.getElementById('completion-proof-group');
    const proofInput = document.getElementById('completion-proof-image');
    const proofPreview = document.getElementById('completion-proof-preview');

    if (!statusSelect || !proofGroup || !proofInput || !proofPreview) {
        return;
    }

    const isCompleted = statusSelect.value === 'completed';
    proofGroup.style.display = isCompleted ? 'block' : 'none';

    if (!isCompleted) {
        proofInput.required = false;
        proofInput.value = '';
        proofPreview.innerHTML = '';
        return;
    }

    const hasExistingProof = Boolean(currentReport && currentReport.completion_proof_image);
    proofInput.required = !hasExistingProof;

    if (hasExistingProof) {
        const safeUrl = String(currentReport.completion_proof_image);
        proofPreview.innerHTML = `
            <div class="text-muted" style="margin-bottom: 6px;">Current proof image:</div>
            <a href="${safeUrl}" target="_blank" rel="noopener">
                <img src="${safeUrl}" alt="Completion proof" style="max-width: 240px; width: 100%; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.25);">
            </a>
        `;
    } else {
        proofPreview.innerHTML = '<div class="text-muted">No proof image uploaded yet.</div>';
    }
}

// UI_BROWSER_DIALOG_REPLACEMENT — this page previously had its own
// hand-rolled confirm modal (duplicate DOM id `system-confirm-modal`,
// colliding with the shared component's dynamically-created element). It
// has been removed in favor of the single shared UI.systemConfirm() used
// application-wide; see call sites below.
function showSystemConfirm(options = {}) {
    const confirmClass = String(options.confirmClass || '');
    // 'warning' is one of the shared component's existing variants (see
    // UI.systemConfirm in utils.js); it was simply never requested from this
    // page before TASK 44's cross-department confirmation needed it.
    const variant = confirmClass.includes('btn-success')
        ? 'success'
        : (confirmClass.includes('btn-danger')
            ? 'danger'
            : (confirmClass.includes('btn-warning') ? 'warning' : 'primary'));
    const message = options.title
        ? `<strong>${UI.escapeHtml(options.title)}</strong>\n\n${UI.escapeHtml(String(options.message || 'Proceed?'))}`
        : UI.escapeHtml(String(options.message || 'Proceed?'));
    return UI.systemConfirm(message, String(options.confirmText || 'OK'), String(options.cancelText || 'Cancel'), variant);
}

function updateNeedChangeApprovalUI(report) {
    const approvalCard = document.getElementById('need-change-approval-card');
    const approvalMeta = document.getElementById('need-change-approval-meta');
    const approvalAlert = document.getElementById('need-change-approval-alert');
    const approvalBtn = document.getElementById('approve-need-change-btn');
    const rejectBtn = document.getElementById('reject-need-change-btn');

    if (!approvalCard || !approvalMeta || !approvalBtn) {
        return;
    }

    if (!isSuperAdminUser) {
        approvalCard.style.display = 'none';
        return;
    }

    if (!(report.need_change_item_id || report.need_change_item_name)) {
        approvalCard.style.display = 'block';
        approvalMeta.innerHTML = '<div><strong>Need Change Status:</strong> No replacement request for this report.</div>';
        approvalBtn.disabled = true;
        approvalBtn.textContent = 'Approve';
        if (rejectBtn) {
            rejectBtn.disabled = true;
        }
        return;
    }

    const status = String(report.need_change_status || 'pending').toLowerCase();
    const isApproved = Boolean(report.need_change_deducted_at) || status === 'deducted' || status === 'approved';
    approvalCard.style.display = 'block';
    approvalAlert.innerHTML = '';

    approvalMeta.innerHTML = `
        <div><strong>Replacement Item:</strong> ${UI.escapeHtml(report.need_change_item_name) || ('Item #' + report.need_change_item_id)}</div>
        <div><strong>Current Need Change Status:</strong> ${UI.escapeHtml(status.replace(/_/g, ' ').toUpperCase())}</div>
        ${report.need_change_deducted_at ? `<div><strong>Deducted At:</strong> ${UI.escapeHtml(report.need_change_deducted_at)}</div>` : ''}
    `;

    if (isApproved) {
        approvalBtn.disabled = true;
        approvalBtn.textContent = 'Approved & Released';
        if (rejectBtn) {
            rejectBtn.disabled = true;
        }
    } else {
        approvalBtn.disabled = false;
        // Approval IS the hand-off for a replacement item (there is no
        // separate release step), so the label says what it does.
        approvalBtn.textContent = 'Approve & Release Item';
        if (rejectBtn) {
            rejectBtn.disabled = false;
        }
    }
}


// Load report details
async function loadReport() {
    if (!reportId) {
        document.getElementById('report-details').innerHTML = 
            '<p class="text-danger">Invalid report ID</p>';
        return;
    }
    
    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/reports/${reportId}`),
            { credentials: 'include' }
        );
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to load report');
        }
        
        const report = data.data.report;
        currentReport = report;
        document.getElementById('report-id-badge').textContent = `#${report.report_id}`;
        document.getElementById('report-title').textContent = report.title;

        // Enterprise info-card grid layout, mirroring report-detail.php's
        // (Report Management phase) .report-detail-grid/.report-info-card
        // pattern. Same report fields as before; only the markup changed.
        let html = '<div class="report-detail-grid">';

        // Basic Info card
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">Basic Information</h3>';
        html += '<dl class="report-info-list">';
        html += `<div class="report-info-row"><dt>Report ID</dt><dd>#${report.report_id}</dd></div>`;
        html += `<div class="report-info-row"><dt>Title</dt><dd><strong>${UI.escapeHtml(report.title)}</strong></dd></div>`;
        // Directly under Title, because the two answer adjacent questions
        // ("what kind of problem" / "which specific issue") and reading them
        // together is how a maintenance person triages the report.
        html += `<div class="report-info-row"><dt>Problem Type</dt><dd>${renderProblemType(report)}</dd></div>`;
        html += `<div class="report-info-row"><dt>Location</dt><dd>${UI.escapeHtml(report.location)}</dd></div>`;
        html += `<div class="report-info-row"><dt>Priority</dt><dd>${renderPriorityBadge(report.priority)}</dd></div>`;
        html += `<div class="report-info-row"><dt>Status</dt><dd>${renderStatusBadge(report.status)}</dd></div>`;
        if (report.need_change_item_id || report.need_change_item_name) {
            const needChangeStatusRaw = String(report.need_change_status || 'pending').toLowerCase();
            const needChangeStatusLabel = needChangeStatusRaw.replace(/_/g, ' ').toUpperCase();
            // Task 28.3: badge-only presentation (was plain text + a separate
            // "Deducted" badge shown side-by-side, duplicating the same value).
            // Color mapping mirrors reports.php's existing need-change badge
            // convention (deducted/approved -> success, rejected -> danger,
            // pending -> warning) so the same status renders the same color
            // app-wide. No change to report.need_change_status itself.
            let needChangeStatusBadgeClass = 'badge-info';
            if (needChangeStatusRaw === 'deducted' || needChangeStatusRaw === 'approved') {
                needChangeStatusBadgeClass = 'badge-success';
            } else if (needChangeStatusRaw === 'rejected') {
                needChangeStatusBadgeClass = 'badge-danger';
            } else if (needChangeStatusRaw === 'pending') {
                needChangeStatusBadgeClass = 'badge-warning';
            }
            html += `<div class="report-info-row"><dt>Need Change</dt><dd>Yes</dd></div>`;
            html += `<div class="report-info-row"><dt>Replacement Item</dt><dd><strong>${UI.escapeHtml(report.need_change_item_name) || ('Item #' + report.need_change_item_id)}</strong>${report.need_change_item_quantity ? ` <span class="text-muted">(Stock: ${UI.escapeHtml(report.need_change_item_quantity)})</span>` : ''}</dd></div>`;
            html += `<div class="report-info-row"><dt>Need Change Status</dt><dd><span class="badge ${needChangeStatusBadgeClass}">${UI.escapeHtml(needChangeStatusLabel)}</span></dd></div>`;
        } else {
            html += `<div class="report-info-row"><dt>Need Change</dt><dd class="report-unassigned">Not requested</dd></div>`;
        }
        html += `<div class="report-info-row"><dt>Department</dt><dd>${UI.escapeHtml(report.department_name) || 'Not assigned'}</dd></div>`;
        html += '</dl>';
        html += '</section>';

        // Description card
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">Description</h3>';
        html += `<div class="report-description-box">${UI.escapeHtml(report.description) || 'No description provided'}</div>`;
        html += '</section>';

        // People card
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">People</h3>';
        html += '<dl class="report-info-list">';
        html += `<div class="report-info-row"><dt>Created By</dt><dd>${UI.escapeHtml(report.creator_name)} ${report.creator_email ? `(${UI.escapeHtml(report.creator_email)})` : ''}</dd></div>`;
        html += `<div class="report-info-row"><dt>Assigned To</dt><dd>${report.assigned_name ? `${UI.escapeHtml(report.assigned_name)} ${report.assigned_email ? `(${UI.escapeHtml(report.assigned_email)})` : ''}` : '<span class="report-unassigned">Not assigned yet</span>'}</dd></div>`;
        html += '</dl>';
        html += '</section>';

        // TASK 44 (M1 — Unified Read Surface) — Damage & Dispatch Information
        // card. Only rendered when a linked damage_reports / dispatches
        // compatibility-layer row exists for this report (fields come from
        // ReportController::show()'s Schema::hasTable()-guarded LEFT JOINs).
        //
        // TASK 12 — the "Repair Request" and "Repair Technician" rows were
        // removed from this card. The Repair Request row rendered a real
        // anchor to repair-detail.php, which this task deletes, so leaving it
        // would have produced a broken link from a PRIMARY-workflow page —
        // the one thing the retirement must not do. "Repair Technician"
        // (repair_requests.technician_user_id) went with it because it is a
        // Repair Request field with no page left to reach.
        //
        // The Damage Report and Replacement Dispatch rows are deliberately
        // KEPT — they are shared primary-workflow information, not Repair
        // module UI, and both of their detail pages still exist. The card
        // title dropped "Repair" only because the repair rows are gone.
        //
        // The guard now tests damage_report_id || replacement_dispatch_id
        // rather than damage_report_id || repair_request_id. That keeps the
        // card visible for every case that previously showed content: a
        // repair-linked report that also has a replacement dispatch still
        // renders its dispatch row, while a repair-ONLY report no longer
        // renders an empty card with nothing but a heading.
        if (report.damage_report_id || report.replacement_dispatch_id) {
            html += '<section class="report-info-card">';
            html += '<h3 class="report-info-card-title">Damage &amp; Dispatch Information</h3>';
            html += '<dl class="report-info-list">';
            if (report.damage_report_id) {
                const damageStatusLabel = String(report.damage_report_status || '').replace(/_/g, ' ').toUpperCase();
                html += `<div class="report-info-row"><dt>Damage Report</dt><dd><a href="damage-report-detail.php?id=${report.damage_report_id}">${UI.escapeHtml(report.damage_report_code || ('#' + report.damage_report_id))}</a>${damageStatusLabel ? ` <span class="badge badge-info">${UI.escapeHtml(damageStatusLabel)}</span>` : ''}</dd></div>`;
            }
            if (report.replacement_dispatch_id) {
                const dispatchStatusLabel = String(report.replacement_dispatch_status || '').replace(/_/g, ' ').toUpperCase();
                html += `<div class="report-info-row"><dt>Replacement Dispatch</dt><dd><a href="dispatch-detail.php?id=${report.replacement_dispatch_id}">${UI.escapeHtml(report.replacement_dispatch_code || ('#' + report.replacement_dispatch_id))}</a>${dispatchStatusLabel ? ` <span class="badge badge-info">${UI.escapeHtml(dispatchStatusLabel)}</span>` : ''}</dd></div>`;
            }
            html += '</dl>';
            html += '</section>';
        }

        // Timeline card
        if (report.created_at_full || report.created_at || report.updated_at_formatted || report.updated_at) {
            html += '<section class="report-info-card report-info-card-wide">';
            html += '<h3 class="report-info-card-title">Timeline</h3>';
            html += '<ol class="report-timeline">';
            if (report.created_at_full || report.created_at) {
                html += `<li class="report-timeline-item"><span class="report-timeline-dot"></span><div class="report-timeline-content"><strong>Created</strong><span>${report.created_at_full || report.created_at}</span></div></li>`;
            }
            if (report.updated_at_formatted || report.updated_at) {
                html += `<li class="report-timeline-item"><span class="report-timeline-dot"></span><div class="report-timeline-content"><strong>Last Updated</strong><span>${report.updated_at_formatted || report.updated_at}</span></div></li>`;
            }
            if (report.due_date) {
                html += `<li class="report-timeline-item"><span class="report-timeline-dot"></span><div class="report-timeline-content"><strong>Due Date</strong><span>${report.due_date_formatted || report.due_date}</span></div></li>`;
            }
            if (report.completed_date) {
                html += `<li class="report-timeline-item report-timeline-item-done"><span class="report-timeline-dot"></span><div class="report-timeline-content"><strong>Completed</strong><span>${report.completed_date_formatted || report.completed_date}</span></div></li>`;
            }
            html += '</ol>';
            html += '</section>';
        }

        // Completion proof card
        if (report.completion_proof_image) {
            const proofUrl = String(report.completion_proof_image);
            html += '<section class="report-info-card report-info-card-wide">';
            html += '<h3 class="report-info-card-title">Completion Proof</h3>';
            html += `<a href="${proofUrl}" target="_blank" rel="noopener">`;
            html += `<img src="${proofUrl}" alt="Completion proof" style="max-width: 360px; width: 100%; border-radius: 10px; border: 1px solid var(--border);">`;
            html += `</a>`;
            html += '</section>';
        }

        html += '</div>';

        document.getElementById('report-details').innerHTML = html;
        
        // Set current status in form
        document.getElementById('new-status').value = report.status;
        const assignGroup = document.getElementById('assigned-to-group');
        const assignSelect = document.getElementById('assigned-to');
        if (assignGroup && assignSelect) {
            renderAssignmentOptions(report.assigned_to ? String(report.assigned_to) : '');
        }
        syncAssignedToVisibility();
        syncCompletionProofVisibility();
        updateNeedChangeApprovalUI(report);
        updateReopenCardUI(report);

        // TASK 9 — hide/disable modification controls once we know the
        // report's actual department_id (Administrator is exempt). Applied
        // last so it can override the visibility/disabled state the helpers
        // above just set (e.g. re-disable a reopen button they enabled).
        applyDepartmentAuthorizationUI(report);
        applyArchiveUI(report);

    } catch (error) {
        console.error('Error:', error);
        document.getElementById('report-details').innerHTML = 
            '<p class="text-danger">' + error.message + '</p>';
    }
}

// Handle status update
document.getElementById('status-update-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    const newStatus = document.getElementById('new-status').value;
    const comment = document.getElementById('status-comment').value;
    const btn = document.getElementById('update-status-btn');
    const alertDiv = document.getElementById('status-alert');
    const completionProofInput = document.getElementById('completion-proof-image');

    // TASK 9 — frontend defense-in-depth; the backend still rejects this
    // with HTTP 403 regardless (see ReportController::update()).
    if (!canModifyReportClientSide(currentReport)) {
        alertDiv.innerHTML = '<div class="alert alert-danger">This report belongs to a different department. You do not have permission to modify it.</div>';
        return;
    }

    if (!newStatus) {
        alertDiv.innerHTML = '<div class="alert alert-danger">Please select a new status</div>';
        return;
    }

    const assignSelect = document.getElementById('assigned-to');
    if (newStatus === 'assigned' && assignSelect && !assignSelect.value) {
        alertDiv.innerHTML = '<div class="alert alert-danger">Please select an assignee before updating status.</div>';
        return;
    }

    if (newStatus === 'completed' && completionProofInput && !completionProofInput.files.length && !(currentReport && currentReport.completion_proof_image)) {
        alertDiv.innerHTML = '<div class="alert alert-danger">Please upload a completion proof image before marking this report as completed.</div>';
        return;
    }
    
    // TASK 44 — the button is disabled BEFORE the (async) cross-department
    // confirmation opens, and `isStatusUpdateInFlight` guards the handler
    // itself, so neither a double-click nor a repeated Enter press while the
    // dialog is open can queue a second submit. Exactly one PATCH is sent per
    // confirmed assignment, and none at all if the user cancels.
    if (isStatusUpdateInFlight) {
        return;
    }

    const originalText = btn.innerHTML;
    isStatusUpdateInFlight = true;
    btn.disabled = true;
    alertDiv.innerHTML = '';

    try {
        if (newStatus === 'assigned' && assignSelect) {
            const selectedPerson = findAssignmentTargetById(assignSelect.value);
            if (selectedPerson && isCrossDepartmentAssignment(currentReport, selectedPerson)) {
                const proceedWithAssignment = await confirmCrossDepartmentAssignment(currentReport, selectedPerson);
                if (!proceedWithAssignment) {
                    // Cancel: no request is sent and nothing is changed.
                    alertDiv.innerHTML = '<div class="alert alert-info">Assignment cancelled. No changes were made.</div>';
                    return;
                }
            }
        }

        btn.innerHTML = 'Updating...';

        const formData = new FormData();
        formData.append('status', newStatus);
        formData.append('comment', comment || '');
        if (newStatus === 'assigned' && assignSelect) {
            formData.append('assigned_to', assignSelect.value || '');
        }
        if (completionProofInput && completionProofInput.files.length > 0) {
            formData.append('completion_proof_image', completionProofInput.files[0]);
        }
        // Browsers/PHP do not parse multipart/form-data bodies on PATCH requests,
        // so the actual request is sent as POST with Laravel's method-spoofing
        // field; the route itself remains PATCH (routes/web.php) and is unchanged.
        formData.append('_method', 'PATCH');

        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/reports/${reportId}`),
            {
                method: 'POST',
                credentials: 'include',
                body: formData
            }
        );

        const data = await response.json();

        if (data.success) {
            alertDiv.innerHTML = '<div class="alert alert-success">Status updated successfully!</div>';
            setTimeout(() => {
                loadReport();
                document.getElementById('status-comment').value = '';
                if (completionProofInput) {
                    completionProofInput.value = '';
                }
            }, 1500);
        } else {
            throw new Error(data.message || 'Failed to update status');
        }
    } catch (error) {
        alertDiv.innerHTML = '<div class="alert alert-danger">' + error.message + '</div>';
    } finally {
        isStatusUpdateInFlight = false;
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

document.getElementById('approve-need-change-btn')?.addEventListener('click', async () => {
    const approvalBtn = document.getElementById('approve-need-change-btn');
    const rejectBtn = document.getElementById('reject-need-change-btn');
    const approvalAlert = document.getElementById('need-change-approval-alert');

    if (!currentReport) {
        return;
    }

    const status = String(currentReport.need_change_status || 'pending').toLowerCase();
    const isAlreadyApproved = Boolean(currentReport.need_change_deducted_at) || status === 'deducted' || status === 'approved';
    if (isAlreadyApproved) {
        approvalAlert.innerHTML = '<div class="alert alert-info">Need Change is already approved.</div>';
        return;
    }

    const isConfirmed = await showSystemConfirm({
        title: 'Approve & Release Item',
        message: 'Approve this replacement request and release the item?\n\nThe item will be deducted from inventory now.',
        confirmText: 'Approve & Release',
        cancelText: 'Cancel',
        confirmClass: 'btn btn-success'
    });

    if (!isConfirmed) {
        approvalAlert.innerHTML = '<div class="alert alert-info">Approval cancelled.</div>';
        return;
    }

    const originalText = approvalBtn.textContent;
    approvalBtn.disabled = true;
    rejectBtn.disabled = true;
    approvalBtn.textContent = 'Approving...';
    approvalAlert.innerHTML = '';

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/reports/${reportId}`),
            {
                method: 'PATCH',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    approve_need_change: true,
                    comment: 'Need Change approved by Admin'
                })
            }
        );

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to approve Need Change');
        }

        approvalAlert.innerHTML = '<div class="alert alert-success">Need Change approved successfully. Inventory updated.</div>';
        loadReport();
    } catch (error) {
        approvalAlert.innerHTML = '<div class="alert alert-danger">' + error.message + '</div>';
        approvalBtn.disabled = false;
        rejectBtn.disabled = false;
        approvalBtn.textContent = originalText;
    }
});

document.getElementById('reject-need-change-btn')?.addEventListener('click', async () => {
    const approvalBtn = document.getElementById('approve-need-change-btn');
    const rejectBtn = document.getElementById('reject-need-change-btn');
    const approvalAlert = document.getElementById('need-change-approval-alert');

    if (!currentReport) {
        return;
    }

    const status = String(currentReport.need_change_status || 'pending').toLowerCase();
    const isAlreadyProcessed = Boolean(currentReport.need_change_deducted_at) || status === 'deducted' || status === 'approved' || status === 'rejected';
    if (isAlreadyProcessed) {
        approvalAlert.innerHTML = '<div class="alert alert-info">This Need Change request has already been processed.</div>';
        return;
    }

    const isRejectConfirmed = await showSystemConfirm({
        title: 'Reject Need Change',
        message: 'Reject this Need Change request? The item will not be replaced.',
        confirmText: 'OK',
        cancelText: 'Cancel',
        confirmClass: 'btn btn-danger'
    });

    if (!isRejectConfirmed) {
        return;
    }

    const originalText = rejectBtn.textContent;
    approvalBtn.disabled = true;
    rejectBtn.disabled = true;
    rejectBtn.textContent = 'Rejecting...';
    approvalAlert.innerHTML = '';

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/reports/${reportId}`),
            {
                method: 'PATCH',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reject_need_change: true,
                    comment: 'Need Change rejected by Admin'
                })
            }
        );

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to reject Need Change');
        }

        approvalAlert.innerHTML = '<div class="alert alert-success">Need Change rejected successfully.</div>';
        loadReport();
    } catch (error) {
        approvalAlert.innerHTML = '<div class="alert alert-danger">' + error.message + '</div>';
        approvalBtn.disabled = false;
        rejectBtn.disabled = false;
        rejectBtn.textContent = originalText;
    }
});

// Report Archive — the archive card, and hiding the action cards while the
// report is view-only. Runs after applyDepartmentAuthorizationUI() so it has
// the final say on those cards.
function applyArchiveUI(report) {
    const archive = (report && report.archive) || {};
    const card = document.getElementById('archive-state-card');
    const locked = Boolean(archive.is_locked);
    const term = archive.term_label || 'a past academic term';

    // The reopen / need-change cards are re-shown by their own helpers on
    // every load; the status card has no helper, so restore it explicitly
    // (it must come back after the Administrator reopens the report).
    const statusCard = document.getElementById('update-status-card');
    if (statusCard) statusCard.style.display = locked ? 'none' : '';
    ['reopen-report-card', 'need-change-approval-card'].forEach((id) => {
        const el = document.getElementById(id);
        if (el && locked) el.style.display = 'none';
    });
    const notice = document.getElementById('department-restricted-notice');
    if (notice && locked) notice.style.display = 'none';

    if (!card) return;
    card.hidden = !archive.is_archived;
    if (!archive.is_archived) return;

    const title = document.getElementById('archive-state-title');
    const text = document.getElementById('archive-state-text');
    card.classList.toggle('is-locked', locked);
    card.classList.toggle('is-carried', Boolean(archive.is_carried_over));
    card.classList.toggle('is-reopened', Boolean(archive.is_reopened));

    if (locked) {
        title.textContent = 'Archived report · View only';
        text.textContent = `Filed in ${term}. Finished reports from past terms can be viewed and printed but not changed.`
            + (isSuperAdminUser ? ' As Administrator you can reopen it if a correction is needed.' : ' Only the Administrator can reopen it.');
    } else if (archive.is_reopened) {
        title.textContent = 'Archived report · Reopened for changes';
        text.textContent = `Filed in ${term}. Reopened by the Administrator — it becomes view-only again when it is finished, or when locked again.`;
    } else {
        title.textContent = 'Carried over from a past term';
        text.textContent = `Filed in ${term} and not finished yet, so it can still be worked on. It becomes view-only once it is completed.`;
    }

    const reopenBtn = document.getElementById('archive-reopen-btn');
    const lockBtn = document.getElementById('archive-lock-btn');
    if (reopenBtn) reopenBtn.hidden = !locked;
    if (lockBtn) lockBtn.hidden = !archive.is_reopened;
}

async function runArchiveAction(action) {
    const alertDiv = document.getElementById('archive-state-alert');
    const btn = document.getElementById(action === 'reopen' ? 'archive-reopen-btn' : 'archive-lock-btn');

    const confirmed = await showSystemConfirm(action === 'reopen'
        ? { title: 'Reopen archived report?', message: 'This report is from a past academic term. Reopening lets it be changed again, and is recorded in the Activity Log.', confirmText: 'Reopen for changes', confirmClass: 'btn-warning' }
        : { title: 'Lock this report again?', message: 'It will become view-only again (archived).', confirmText: 'Lock again' });
    if (!confirmed) return;

    if (btn) btn.disabled = true;
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/reports/${reportId}/archive-${action === 'reopen' ? 'reopen' : 'lock'}`), {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Request failed');
        alertDiv.innerHTML = '';
        await loadReport();
    } catch (error) {
        alertDiv.innerHTML = `<div class="alert alert-danger" style="margin-top:8px;">${UI.escapeHtml(error.message || 'Something went wrong.')}</div>`;
    } finally {
        if (btn) btn.disabled = false;
    }
}

document.getElementById('archive-reopen-btn')?.addEventListener('click', () => runArchiveAction('reopen'));
document.getElementById('archive-lock-btn')?.addEventListener('click', () => runArchiveAction('lock'));

function updateReopenCardUI(report) {
    const card = document.getElementById('reopen-report-card');
    if (!card) return;
    const status = String(report.status || '').toLowerCase();
    card.style.display = (status === 'completed' || status === 'closed') ? 'block' : 'none';
}

document.getElementById('reopen-report-btn')?.addEventListener('click', async () => {
    const btn = document.getElementById('reopen-report-btn');
    const alertDiv = document.getElementById('reopen-alert');

    // TASK 9 — frontend defense-in-depth; the backend still rejects this
    // with HTTP 403 regardless (see ReportController::update()).
    if (!canModifyReportClientSide(currentReport)) {
        alertDiv.innerHTML = '<div class="alert alert-danger">This report belongs to a different department. You do not have permission to modify it.</div>';
        return;
    }

    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Reopening...';
    alertDiv.innerHTML = '';
    try {
        const formData = new FormData();
        formData.append('status', 'in_progress');
        formData.append('comment', 'Report reopened');
        // See status-update-form handler above: multipart bodies aren't parsed by
        // PHP on PATCH requests, so POST + method-spoofing is used instead. The
        // route itself remains PATCH (routes/web.php) and is unchanged.
        formData.append('_method', 'PATCH');
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/reports/${reportId}`), { method: 'POST', credentials: 'include', body: formData });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Failed to reopen report');
        alertDiv.innerHTML = '<div class="alert alert-success">Report reopened successfully!</div>';
        setTimeout(() => loadReport(), 1500);
    } catch (error) {
        alertDiv.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        btn.disabled = false;
        btn.textContent = original;
    }
});

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.assignment-role-btn').forEach((button) => {
        button.addEventListener('click', () => {
            activeAssignmentRole = button.dataset.assignmentRole || defaultAssignmentRoleFilter;
            // Clear search and selection when switching role
            const si = document.getElementById('assigned-to-search');
            const hi = document.getElementById('assigned-to');
            const se = document.getElementById('assigned-to-selected');
            const re = document.getElementById('assigned-to-results');
            if (si) si.value = '';
            if (hi) hi.value = '';
            if (se) { se.style.display = 'none'; se.textContent = ''; }
            if (re) { re.style.display = 'none'; re.innerHTML = ''; }
            renderAssignmentOptions('');
        });
    });

    renderAssignmentOptions('');

    // Search input for assign personnel
    const assignSearchInput = document.getElementById('assigned-to-search');
    const assignResultsEl = document.getElementById('assigned-to-results');

    if (assignSearchInput) {
        assignSearchInput.placeholder = assignmentPlaceholder || 'Type name or username to search...';
        assignSearchInput.addEventListener('input', filterAssignmentResults);
        assignSearchInput.addEventListener('focus', filterAssignmentResults);
    }

    if (assignResultsEl) {
        assignResultsEl.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-person-id]');
            if (!btn) return;
            const personId = btn.getAttribute('data-person-id');
            const targets = Object.values(assignmentTargetsByRole).flat();
            const match = targets.find((p) => String(p.user_id) === String(personId));
            if (!match) return;

            const hiddenInput = document.getElementById('assigned-to');
            const selectedEl = document.getElementById('assigned-to-selected');
            if (hiddenInput) hiddenInput.value = String(match.user_id);
            if (assignSearchInput) assignSearchInput.value = getAssignmentPrimaryLabel(match);
            if (selectedEl) {
                selectedEl.style.display = 'block';
                selectedEl.textContent = 'Assigned: ' + getAssignmentPrimaryLabel(match);
            }
            assignResultsEl.style.display = 'none';
            assignResultsEl.innerHTML = '';
        });
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#assigned-to-group')) {
            if (assignResultsEl) assignResultsEl.style.display = 'none';
        }
    });

    loadReport();
});

// Toggle assignment dropdown for assign flow only.
document.getElementById('new-status').addEventListener('change', () => {
    syncAssignedToVisibility();
    syncCompletionProofVisibility();
});
</script>

</body>
</html>


