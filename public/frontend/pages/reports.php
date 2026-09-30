<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
    if (!@session_start()) {
        // 2026-09-30: transient Windows/antivirus file-lock on the session
        // save path (C:\xampp\tmp) can make session_start() fail; suppress
        // the raw warning and log it instead so users just see a clean
        // logged-out state (e.g. after auto-logout) rather than PHP noise.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

if (!isset($_SESSION['user']) && !isset($_SESSION['auth_user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$currentUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? null;
$currentRole = strtolower(trim((string)($currentUser['role'] ?? '')));
$currentUserId = (int)($currentUser['user_id'] ?? 0);
$isSuperAdmin = ($currentRole === 'super_admin');
// RBAC POLICY UPDATE — Administrator (super_admin) reviews/assigns/monitors
// reports but does not submit them; Head Maintenance (maintenance_admin) and
// Maintenance Staff are the report submitters.
$canCreateReport = in_array($currentRole, ['maintenance_admin', 'maintenance_staff'], true);
$canEditOwnReports = in_array($currentRole, ['maintenance_staff'], true);
// TASK 9 — Role + Department Based Authorization. Head Maintenance may edit
// reports in their OWN department only (backend CASE A already allows
// maintenance_admin to edit any report, gated by canModifyReport()).
$canEditDepartmentReports = ($currentRole === 'maintenance_admin');


$isMaintenanceAdmin = ($currentRole === 'maintenance_admin');
$canFilterByDepartment = in_array($currentRole, ['maintenance_staff', 'maintenance_admin', 'super_admin'], true);

// Fetch departments for filter dropdown (Maintenance Staff, Head, and Administrator)
$filterDepartments = [];
if ($canFilterByDepartment) {
    require_once __DIR__ . '/../../backend/config/database.php';
    $pdo = getDBConnection();
    $deptStmt = $pdo->query("SELECT department_id, name FROM departments WHERE status = 'active' ORDER BY name");
    $filterDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    $myDeptStmt = $pdo->prepare("SELECT department_id FROM users WHERE user_id = ? LIMIT 1");
    $myDeptStmt->execute([(int)($currentUser['user_id'] ?? 0)]);
    $myDeptId = $myDeptStmt->fetchColumn();
}

// TASK 9 — the current user's department, used to hide/disable modification
// actions whenever user.department_id != report.department_id (Administrator
// is exempt). Prefer the session value written at login by AuthController,
// falling back to the lookup already performed above for the filter dropdown.
$currentUserDepartmentId = $currentUser['department_id'] ?? ($myDeptId ?? null);
$currentUserDepartmentId = ($currentUserDepartmentId === null || $currentUserDepartmentId === '')
    ? null
    : (int) $currentUserDepartmentId;

// Problem Type vocabulary for the Edit Report modal's card grid. Same
// `require` of the same config file that create-report.php performs, so the
// two grids cannot offer different categories — and the list the backend's
// `in:` rule validates against is that same array. The file is deliberately
// free of env()/config() calls precisely so this plain-PHP page can read it
// without booting Laravel.
$problemTypeConfig = require __DIR__ . '/../../../config/maintenance_reports.php';
$problemTypes = $problemTypeConfig['problem_types'] ?? [];
$problemTypeOtherValue = $problemTypeConfig['problem_type_other_value'] ?? 'Other';
$problemTypeOtherMax = (int) ($problemTypeConfig['problem_type_other_max'] ?? 100);

$pageTitle = 'All Reports - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container reports-page-container">
    <div class="card" style="border-left:3px solid var(--primary-color);">
        <div class="card-header d-flex justify-between align-center">
            <div class="reports-title-block">
                <h2>Maintenance Reports</h2>
                <p class="text-muted mb-0">View and manage all maintenance reports</p>
            </div>
            <div class="d-flex gap-sm align-center reports-header-actions">
                <?php if ($canCreateReport): ?>
                <!-- TASK 100 — Create Report moved out of the sidebar and into
                     the All Reports module. This is a navigation entry point,
                     not a second implementation: it links to the existing
                     create-report.php workflow, which keeps its own role guard
                     and already returns here on successful submission.

                     $canCreateReport is the gate this page has always used; it
                     hides the action from roles that cannot submit, but it is
                     not the security boundary — EnsureRole on POST /api/reports
                     and create-report.php's redirect guard remain authoritative. -->
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/create-report.php')); ?>"
                   id="create-report-btn"
                   class="btn btn-primary reports-header-btn reports-header-icon-btn">
                    + Report a Problem
                </a>
                <?php endif; ?>
                <button type="button" id="print-report-btn" class="btn btn-secondary reports-header-btn reports-header-icon-btn">
                    <?php if ($isSuperAdmin): ?>
                    Export Reports (PDF/Excel)
                    <?php else: ?>
                    Print Summary Report
                    <?php endif; ?>
                </button>
                <div class="reports-month-dropdown-wrap" style="position:relative;">
                    <button type="button" id="month-picker-btn" class="btn btn-secondary reports-header-btn reports-header-icon-btn" style="display:inline-flex;align-items:center;gap:6px;">
                        <?php /* TASK 7.1 — was <span>&#128197;</span>, the calendar emoji written as an
                                 HTML entity, which is why the original TASK 7 literal-character sweep
                                 did not catch it. The trailing chevron below is left exactly as-is. */ ?>
                        <?php echo ui_icon('calendar', ['size' => 15]); ?> Browse by Month
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <div id="month-picker-dropdown" style="display:none;position:absolute;top:calc(100% + 6px);right:0;z-index:999;background:var(--card-color,#1a1f2e);border:1px solid rgba(148,163,184,0.24);border-radius:12px;box-shadow:0 16px 40px rgba(2,6,23,0.45);min-width:200px;overflow:hidden;">
                        <div style="padding:10px 14px 6px;font-size:11px;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:#64748b;">Select Month</div>
                        <?php
                        $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                        $currentYear = (int)date('Y');
                        $currentMonth = (int)date('n');
                        ?>
                        <?php /* The month list lives in its own scroll container so that a long
                                 list scrolls INSIDE the dropdown instead of growing past the bottom
                                 of the surrounding .card, which clips it (see reports.inline1.css).
                                 The "Select Month" label above stays pinned. The month options
                                 themselves — including which months are offered — are unchanged. */ ?>
                        <div class="month-picker-list">
                        <?php if ($currentMonth <= 1): ?>
                        <div style="padding:10px 16px 14px;font-size:13px;color:#64748b;">No previous months yet.</div>
                        <?php endif; ?>
                        <?php foreach ($months as $mIdx => $mName):
                            if ($mIdx + 1 > $currentMonth) continue;
                            $mNum = str_pad($mIdx + 1, 2, '0', STR_PAD_LEFT);
                        ?>
                        <button type="button" class="month-picker-item" data-month="<?php echo $currentYear . '-' . $mNum; ?>" style="display:block;width:100%;text-align:left;padding:9px 16px;background:none;border:none;color:inherit;cursor:pointer;font-size:14px;transition:background 0.15s;">
                            <?php echo $mName . ' ' . $currentYear; ?>
                        </button>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <!-- Report Archive — past-term reports grouped by the School
                     Years recorded in "Manage Academic Session". Filled from
                     GET /api/academic-sessions by loadArchiveMenu(). Picking a
                     term sets the From/To dates exactly like Browse by Month. -->
                <div class="reports-archive-dropdown-wrap" style="position:relative;">
                    <button type="button" id="archive-picker-btn" class="btn btn-secondary reports-header-btn reports-header-icon-btn" aria-haspopup="true" aria-expanded="false" style="display:inline-flex;align-items:center;gap:6px;">
                        <?php echo ui_icon('archive', ['size' => 15]); ?> Report Archive
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <div id="archive-picker-dropdown" class="report-archive-menu" role="menu" aria-label="Report Archive" hidden>
                        <div class="ra-head">
                            <span class="ra-head-icon"><?php echo ui_icon('archive', ['size' => 18]); ?></span>
                            <div class="ra-head-text">
                                <strong>Report Archive</strong>
                                <span>Browse reports by school year or semester</span>
                            </div>
                        </div>
                        <div id="archive-picker-list" class="ra-list">
                            <div class="ra-empty">Loading school years…</div>
                        </div>
                        <div class="ra-foot">
                            <?php echo ui_icon('lock', ['size' => 13]); ?>
                            <span>Finished reports from past terms are view-only.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Filters -->
            <div class="d-flex gap-sm mb-md reports-filters-row">
                <select id="filter-status" class="form-control reports-filter-select">
                    <option value="">All Status</option>
                    <option value="submitted">Submitted</option>
                    <option value="assigned">Assigned</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="closed">Closed</option>
                </select>

                <select id="filter-resolution" class="form-control reports-filter-select">
                    <option value="">All Resolution</option>
                    <option value="unresolved">Unresolved</option>
                    <option value="resolved">Resolved</option>
                </select>

                <!-- TASK 99 — Report Type. Damage Report is a classification of
                     a Maintenance Report (the item could not be repaired and had
                     to be replaced), not a separate module, so it is selected
                     here rather than navigated to. Sent to the backend as
                     report_type so the list AND the export below both read the
                     same authorized, server-filtered result set. -->
                <select id="filter-report-type" class="form-control reports-filter-select" title="Report Type">
                    <option value="">All Reports</option>
                    <option value="maintenance">Maintenance Reports</option>
                    <option value="damage">Damage Reports</option>
                </select>

                <?php if ($canFilterByDepartment && !empty($filterDepartments)): ?>
                <select id="filter-department" class="form-control reports-filter-select">
                    <option value="">All Departments</option>
                    <?php foreach ($filterDepartments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>"
                        <?php echo (isset($myDeptId) && $myDeptId == $dept['department_id']) ? 'data-my-dept="1"' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name']); ?>
                        <?php echo (isset($myDeptId) && $myDeptId == $dept['department_id']) ? ' (My Dept)' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select id="filter-priority" class="form-control reports-filter-select">
                    <option value="">All Priority</option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>

                <input type="date" id="filter-date-from" class="form-control reports-filter-date" title="From date">
                <input type="date" id="filter-date-to" class="form-control reports-filter-date" title="To date">
                <button id="clear-date-filters" class="btn btn-secondary" type="button">Clear Date</button>
            </div>

            <!-- A date-scope summary line used to sit here (id="reports-scope-notice"):
                 it restated the active range as a chip — "Sep 1, 2026 – Sep 16, 2026" —
                 followed by "Reports are limited to this date range." It has been
                 removed by request as redundant display text; the two date inputs
                 directly above it already show the active range, and they remain the
                 live, authoritative control.

                 REMOVED FROM THE SCREEN ONLY. The date filter itself is untouched:
                 the current-month default (1st of the month -> today) still applies on
                 a plain load in both the JS and the no-JS PHP path, "Clear Date" still
                 re-applies that same current month, ?last_month=1 and ?date_scope=today
                 still work, and date_from/date_to are still sent to /api/reports. -->

            <!-- Report Archive — shown while a past term is being browsed. -->
            <div id="report-archive-banner" class="report-archive-banner" role="status" hidden>
                <span class="report-archive-banner-icon"><?php echo ui_icon('archive', ['size' => 18]); ?></span>
                <div class="report-archive-banner-text">
                    <strong>Viewing: <span id="report-archive-banner-label"></span></strong>
                    <span>Finished reports from past terms are view-only. Unfinished ones are carried over and can still be worked on.</span>
                </div>
                <button type="button" id="report-archive-exit" class="btn btn-sm btn-secondary">Back to current reports</button>
            </div>

            <div id="week-pagination" class="week-pagination reports-week-pagination"></div>
            
            <!-- Reports Table -->
            <div id="reports-container">
                <?php
                // PHP fallback: fetch reports on server side in case JS fails
                try {
                    require_once __DIR__ . '/../../backend/config/database.php';
                    $pdo = getDBConnection();

                    // This no-JavaScript fallback mirrors the JS default scope
                    // above, so the two paths can never disagree about what a
                    // plain visit to All Reports means: current month.
                    //
                    // ISS-02 had briefly changed this to "no date bound"
                    // (all-time) to match the JS change; both were reported as
                    // a regression and both are restored together. Keeping
                    // them in step is the whole reason this comment exists.
                    //
                    // last_month=1 is an EXPLICIT caller-supplied scope and is
                    // preserved exactly as before.
                    $isLastMonth = isset($_GET['last_month']) && $_GET['last_month'] === '1';
                    if ($isLastMonth) {
                        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
                        $dateTo = date('Y-m-t', strtotime('last month'));
                    } else {
                        $dateFrom = date('Y-m-01');
                        $dateTo = date('Y-m-d');
                    }

                    $sql = "SELECT r.report_id, r.title, r.priority, r.status, r.location,
                                   r.created_at, r.created_by, r.department_id, creator.full_name as creator_name
                              FROM maintenance_reports r
                              LEFT JOIN users creator ON r.created_by = creator.user_id";
                    $params = [];
                    if ($dateFrom !== null && $dateTo !== null) {
                        $sql .= " WHERE DATE(r.created_at) BETWEEN ? AND ?";
                        $params = [$dateFrom, $dateTo];
                    }
                    $sql .= " ORDER BY r.created_at DESC";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $phpReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $phpReports = [];
                }

                if (!empty($phpReports)) {
                    echo '<table class="table reports-fallback-table">';
                    echo '<thead><tr><th>ID</th><th>Title</th><th>Location</th><th>Priority</th><th>Status</th><th>Created By</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
                    foreach ($phpReports as $r) {
                        $date = date('M d, Y', strtotime($r['created_at']));
                        echo '<tr>';
                        echo '<td>#' . htmlspecialchars($r['report_id']) . '</td>';
                        echo '<td><strong>' . htmlspecialchars($r['title']) . '</strong></td>';
                        echo '<td>' . htmlspecialchars($r['location']) . '</td>';
                        echo '<td>' . strtoupper(htmlspecialchars($r['priority'])) . '</td>';
                        echo '<td>' . strtoupper(str_replace('_',' ',htmlspecialchars($r['status']))) . '</td>';
                        echo '<td>' . htmlspecialchars($r['creator_name'] ?? '') . '</td>';
                        echo '<td>' . $date . '</td>';
                        echo '<td>';
                        echo '<a href="maintenance-report-detail.php?id=' . $r['report_id'] . '&back=all_reports" class="btn btn-sm btn-primary">View</a>';
                        // TASK 9 — Role + Department Based Authorization: the Edit
                        // action is only rendered when the user may actually modify
                        // this report. Administrator is exempt from the department
                        // check; Head Maintenance needs a department match; Maintenance
                        // Staff needs a department match AND ownership. The backend
                        // still rejects unauthorized requests with HTTP 403.
                        $rowDeptId = ($r['department_id'] === null || $r['department_id'] === '') ? null : (int)$r['department_id'];
                        $sameDepartment = ($isSuperAdmin || $rowDeptId === $currentUserDepartmentId);
                        $canEditThisRow = $sameDepartment && (
                            ($canEditOwnReports && $currentUserId > 0 && (int)($r['created_by'] ?? 0) === $currentUserId)
                            || $canEditDepartmentReports
                        );
                        if ($canEditThisRow) {
                            echo ' <button type="button" class="btn btn-sm btn-secondary" onclick="openEditReportModal(' . (int)$r['report_id'] . ')">Edit</button>';
                        }
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    if ($canCreateReport) {
                        echo '<div class="ui-empty-state"><strong>No reports found in the database.</strong><span>Create a report or import sample data.</span></div>';
                    } else {
                        echo '<div class="ui-empty-state"><strong>No reports found in the database.</strong><span>No reports are available for the selected period.</span></div>';
                    }
                }
                ?>
            </div>

            <nav id="pagination-container" class="reports-pagination pagination" aria-label="Reports pagination"></nav>
        </div>
    </div>
</main>

<div id="report-edit-modal" class="report-edit-modal" aria-hidden="true">
    <div class="report-edit-modal-card">
        <div class="report-edit-modal-header">
            <h3 id="report-edit-title">Edit Maintenance Report</h3>
            <button type="button" class="report-edit-close" id="report-edit-close" aria-label="Close">&times;</button>
        </div>
        <div class="report-edit-modal-body">
            <div id="report-edit-alert"></div>
            <form id="report-edit-form">
                <!-- Problem Type. Same component as create-report.php: same
                     stylesheet, same config-driven option list, same clipped
                     radios. Only the ids differ (report_edit_ prefix), because
                     this markup shares a DOM with the rest of the page.

                     Placed first to match the Create Report field order, so a
                     user editing a report sees the fields in the order they
                     filled them in.

                     Editing is gated by the SAME check that already guarded
                     this modal — problem_type simply rides ReportController's
                     existing CASE A field list. No new permission, and no
                     change to who may open this form. -->
                <fieldset class="form-group problem-type-fieldset">
                    <legend class="problem-type-legend">What kind of problem? *</legend>
                    <div class="problem-type-grid" id="report_edit_problem_type_grid">
                        <?php foreach ($problemTypes as $problemType): ?>
                            <?php
                                $ptValue = (string) ($problemType['value'] ?? '');
                                $ptSlug  = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $ptValue));
                                $ptId    = 'report-edit-problem-type-' . trim($ptSlug, '-');
                            ?>
                            <input
                                type="radio"
                                class="problem-type-input"
                                name="report_edit_problem_type"
                                id="<?php echo htmlspecialchars($ptId, ENT_QUOTES); ?>"
                                value="<?php echo htmlspecialchars($ptValue, ENT_QUOTES); ?>">
                            <label class="problem-type-card" for="<?php echo htmlspecialchars($ptId, ENT_QUOTES); ?>">
                                <?php echo ui_icon((string) ($problemType['icon'] ?? ''), ['size' => 22]); ?>
                                <span class="problem-type-card-label"><?php echo htmlspecialchars($ptValue); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <span class="problem-type-error" id="report_edit_problem_type_error" hidden>Please select a problem type.</span>

                    <div class="problem-type-other-group" id="report_edit_problem_type_other_group" hidden>
                        <label for="report_edit_problem_type_other">Please specify the problem type *</label>
                        <input
                            type="text"
                            id="report_edit_problem_type_other"
                            class="form-control"
                            maxlength="<?php echo $problemTypeOtherMax; ?>"
                            placeholder="e.g., Pest control">
                        <span class="problem-type-error" id="report_edit_problem_type_other_error" hidden>Please specify the problem type.</span>
                    </div>
                </fieldset>

                <div class="form-group">
                    <label for="report_edit_title">Report Title *</label>
                    <input type="text" id="report_edit_title" required>
                </div>

                <div class="form-group">
                    <label for="report_edit_location">Location *</label>
                    <input type="text" id="report_edit_location" required>
                </div>

                <div class="form-group">
                    <label for="report_edit_priority">Priority *</label>
                    <select id="report_edit_priority" required>
                        <option value="low">Low - Can wait</option>
                        <option value="medium">Medium - Normal</option>
                        <option value="high">High - Important</option>
                        <option value="critical">Critical - Immediate</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="report_edit_description">Description *</label>
                    <textarea id="report_edit_description" rows="4" required></textarea>
                </div>

                <!-- TASK 29 — Need Change support in Edit Report. Markup mirrors
                     create-report.php's Replacement Item section exactly (same
                     .need-change-* classes, same toggle -> search -> select
                     pattern), with report_edit_-prefixed ids since this lives in
                     the same DOM as the rest of the edit modal's fields. -->
                <div class="form-group need-change-group">
                    <label class="d-block mb-1">Replacement Item <span class="text-muted">(Optional)</span></label>
                    <div class="need-change-panel">
                        <label for="report_edit_need_change_toggle" class="need-change-toggle-label">
                            <input type="checkbox" id="report_edit_need_change_toggle">
                            <span class="need-change-toggle-text">
                                <strong>Needs Replacement Item</strong>
                                <small>Enable this only if the issue requires inventory replacement.</small>
                            </span>
                        </label>
                        <div id="report_edit_need_change_wrap" class="need-change-wrap" style="display:none;">
                            <label for="report_edit_need_change_search" class="need-change-item-label">Search Replacement Item</label>
                            <input type="text" id="report_edit_need_change_search" class="form-control need-change-search" placeholder="Type item name to search..." autocomplete="off">
                            <input type="hidden" id="report_edit_need_change_item" value="">
                            <div id="report_edit_need_change_selected" class="need-change-selected" style="display:none;"></div>
                            <div id="report_edit_need_change_results" class="need-change-results" style="display:none;"></div>
                            <small class="text-muted d-block need-change-note">Stock will only be deducted after Administrator approval.</small>
                        </div>
                        <!-- TASK 29.1 — Data Integrity Protection: once need_change_status
                             is approved/deducted, ReportController::update() CASE A rejects
                             any attempt to modify these fields (inventory has already moved).
                             This notice replaces the interactive toggle/picker so the user
                             understands why, instead of hitting a silent no-op or a confusing
                             error only after clicking Save. -->
                        <div id="report_edit_need_change_locked" class="alert alert-warning mb-0" style="display:none;"></div>
                    </div>
                </div>

                <div class="d-flex gap-sm">
                    <button type="submit" class="btn btn-primary" id="report-edit-save-btn">Save Changes</button>
                    <button type="button" class="btn btn-secondary" id="report-edit-cancel-btn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="print-report-modal" class="report-edit-modal" aria-hidden="true">
    <div class="report-edit-modal-card print-report-modal-card">
        <div class="report-edit-modal-header">
            <h3><?php echo $isSuperAdmin ? 'Export Report Filters' : 'Print Report Filters'; ?></h3>
            <button type="button" class="report-edit-close" id="print-report-close" aria-label="Close">&times;</button>
        </div>
        <div class="report-edit-modal-body">
            <!-- TASK 99 — the same Report Type filter as the page filter bar,
                 surfaced here because this is where the Dean asked for Damage
                 Reports to be selectable. It is not a second implementation:
                 changing it drives the page-level #filter-report-type through
                 the existing filterReports() path, so the export still exports
                 exactly the server-filtered, authorization-scoped rows the list
                 is showing. -->
            <div class="form-group">
                <label for="print-filter-report-type">Report Type</label>
                <select id="print-filter-report-type" class="form-control">
                    <option value="">All Reports</option>
                    <option value="maintenance">Maintenance Reports</option>
                    <option value="damage">Damage Reports</option>
                </select>
            </div>

            <div class="form-group">
                <label for="print-filter-mode"><?php echo $isSuperAdmin ? 'Export Scope' : 'Print Scope'; ?></label>
                <select id="print-filter-mode" class="form-control">
                    <option value="current">Current filtered results</option>
                    <option value="month" selected>Specific month</option>
                    <option value="semester">Entire semester</option>
                    <option value="week">Specific week of month</option>
                    <option value="date">Specific date</option>
                </select>
            </div>

            <div class="form-group" id="print-month-group">
                <label for="print-month-input">Month</label>
                <input type="month" id="print-month-input" class="form-control">
            </div>

            <!-- Read-only: the semester is derived from Semester Settings
                 (SchoolSetting::current()), never manually picked here, so an
                 export can't drift from the schedule the Administrator
                 configured. -->
            <div class="form-group" id="print-semester-group" style="display: none;">
                <label for="print-semester-info">Semester</label>
                <div id="print-semester-info" class="form-control" style="background: var(--surface-2, #f1f5f9); cursor: default;">Loading current semester&hellip;</div>
            </div>

            <div class="form-group" id="print-week-group" style="display: none;">
                <label for="print-week-select">Week</label>
                <select id="print-week-select" class="form-control">
                    <option value="1">Week 1</option>
                    <option value="2">Week 2</option>
                    <option value="3">Week 3</option>
                    <option value="4">Week 4</option>
                </select>
            </div>

            <div class="form-group" id="print-date-group" style="display: none;">
                <label for="print-date-input">Date</label>
                <input type="date" id="print-date-input" class="form-control">
            </div>

            <div class="d-flex gap-sm">
                <button type="button" class="btn btn-secondary" id="print-modal-cancel">Cancel</button>
                <?php if ($isSuperAdmin): ?>
                <button type="button" class="btn btn-primary" id="print-modal-export-pdf">Export PDF</button>
                <button type="button" class="btn btn-primary" id="print-modal-export-excel">Export Excel</button>
                <?php else: ?>
                <button type="button" class="btn btn-primary" id="print-modal-generate">Generate Print</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/design-system-components.css?v=20260726-1">
<!-- Cache-buster bumped 20260913 -> 20260916 because reports.inline1.css's
     column-width rules were re-indexed for the removed Lifecycle column. This
     bump is not cosmetic: those rules address columns BY POSITION, so a
     browser holding the 20260913 copy would apply the old nth-child(8) nowrap
     to what is now the Location column and widen the table. The 20260913 bump
     that preceded this one was for the `.reports-pagination:empty` rule, which
     is unchanged and still present.

     Bumped again 20260916 -> 20260916-2 for the Browse by Month clipping fix.
     A same-day bump is needed because the 20260916 token was already published
     earlier today, so browsers are holding a copy that predates the new
     `.reports-page-container .card { overflow: visible }` and
     `.month-picker-list` scroll rules. Without the suffix those browsers would
     load the new markup (which adds the .month-picker-list wrapper) against the
     old stylesheet (which has no rule for it) — the wrapper would have no
     max-height and the dropdown would stay clipped, looking like the fix
     failed.

     Bumped again 20260921-2 -> 20260922 for the All Reports responsiveness
     task: the table went from 12 columns to 9 (ID merged into a stacked
     Report cell, Type and Created By dropped as standalone columns), and the
     new <colgroup>/data-label markup this HTML now renders needs the new
     table-layout:fixed / column-width / mobile-card rules that ship with
     this stylesheet version. A browser holding the old stylesheet against
     this new markup would apply the old BY-POSITION nth-child nowrap rules
     to the wrong (now re-indexed) logical columns and would have no rules
     at all for .reports-cell-report/.reports-cell-location/data-label, so
     the bump is required, not cosmetic.

     Bumped again 20260922 -> 20260922-2 for the Actions-overflow / Report-
     width fix: the <colgroup> widths changed from percentages to pixel
     values (Actions widened so View+Edit always fit; Report narrowed), and
     the Actions cell markup now wraps View/Edit in a new
     `.reports-actions-buttons` flex div. A browser holding the 20260922
     stylesheet has no rule for that new div (it would sit unstyled, inline,
     with no gap) and would still apply the old 9% Actions column against
     the new markup, so the bump is required, not cosmetic. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/reports.inline1.css?v=20260922-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1">
<!-- TASK 29 — Need Change (Edit Report): reuses create-report.php's existing
     .need-change-* component styles wholesale (unscoped rules, lines 29-172
     of create-report.inline.css) so the Edit Report modal's Replacement Item
     section is visually identical to Create Report, with zero duplicated
     CSS. Only create-report.php's own .create-report-page-scoped rules are
     irrelevant here and are simply not matched by anything in this page. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/create-report.inline.css">
<!-- Problem Type card selector — the same stylesheet create-report.php loads,
     so the grid in the Edit Report modal is the identical component rather
     than a second copy of its rules in reports.inline1.css. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/problem-type-selector.css?v=20260920-2">

<!-- A page-local <style> block for the date-scope summary line lived here
     (.reports-scope-notice / -chip / -chip-active / -warn, plus their
     light-theme overrides). The element it styled has been removed, so the
     rules are removed with it rather than left as dead CSS. Every one of those
     selectors was introduced for that element alone and appeared nowhere else
     in the codebase, so deleting them cannot affect any other component on this
     page or any other page — no shared stylesheet was involved in either
     direction. -->

<!-- Report Archive — menu, banner and per-report tags. Dark is the default
     theme here (matching the Browse by Month dropdown); light overrides below.
     Selectors are anchored on IDs and the light-theme colors are !important
     on purpose: reports.inline1.css forces every text element inside the
     page's .card to #111827 !important in light mode, which would otherwise
     flatten the menu's colored headings, chips and tags to plain black. -->
<style>
/* ── Menu ─────────────────────────────────────────────────────────────── */
#archive-picker-dropdown.report-archive-menu {
    position: absolute; top: calc(100% + 8px); right: 0; z-index: 999;
    width: 340px; max-width: calc(100vw - 32px);
    background: var(--card-color, #151b2b); border: 1px solid rgba(148,163,184,0.22); border-radius: 16px;
    box-shadow: 0 22px 48px rgba(2,6,23,0.5); overflow: hidden;
    animation: raMenuIn 0.14s ease-out;
}
#archive-picker-dropdown[hidden] { display: none; }
@keyframes raMenuIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

#archive-picker-dropdown .ra-head {
    display: flex; align-items: center; gap: 12px; padding: 14px 16px;
    background: linear-gradient(135deg, rgba(139,92,246,0.22), rgba(99,102,241,0.08));
    border-bottom: 1px solid rgba(148,163,184,0.16);
}
#archive-picker-dropdown .ra-head-icon {
    width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(139,92,246,0.28); color: #ddd6fe;
}
#archive-picker-dropdown .ra-head-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
#archive-picker-dropdown .ra-head-text strong { font-size: 14.5px; font-weight: 800; color: #f8fafc; }
#archive-picker-dropdown .ra-head-text span { font-size: 12px; color: #a5b4cb; }

#archive-picker-dropdown .ra-list { max-height: 380px; overflow-y: auto; padding: 6px 8px 8px; }
#archive-picker-dropdown .ra-group + .ra-group { margin-top: 4px; padding-top: 6px; border-top: 1px dashed rgba(148,163,184,0.18); }
#archive-picker-dropdown .ra-group-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 10px 6px; }
#archive-picker-dropdown .ra-group-name { font-size: 11.5px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #a78bfa; }

#archive-picker-dropdown .ra-item {
    display: flex; align-items: center; gap: 12px; width: 100%; padding: 9px 10px; margin: 1px 0;
    background: none; border: 1px solid transparent; border-radius: 10px; color: inherit; cursor: pointer; text-align: left;
    transition: background 0.14s ease, border-color 0.14s ease;
}
#archive-picker-dropdown .ra-item:hover:not(:disabled),
#archive-picker-dropdown .ra-item:focus-visible { background: rgba(139,92,246,0.12); outline: none; }
#archive-picker-dropdown .ra-item:disabled { cursor: not-allowed; opacity: 0.5; }
#archive-picker-dropdown .ra-item-icon {
    width: 32px; height: 32px; flex-shrink: 0; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(148,163,184,0.12); color: #c4b5fd;
}
#archive-picker-dropdown .ra-item-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
#archive-picker-dropdown .ra-item-title { font-size: 14px; font-weight: 700; color: #f1f5f9; }
#archive-picker-dropdown .ra-item-dates { font-size: 12px; color: #94a3b8; }
#archive-picker-dropdown .ra-item-check { display: none; color: #a78bfa; }
#archive-picker-dropdown .ra-item.is-selected { background: rgba(139,92,246,0.16); border-color: rgba(139,92,246,0.45); }
#archive-picker-dropdown .ra-item.is-selected .ra-item-icon { background: #7c3aed; color: #fff; }
#archive-picker-dropdown .ra-item.is-selected .ra-item-check { display: inline-flex; }
#archive-picker-dropdown .ra-item.is-selected .ra-chip { display: none; }

#archive-picker-dropdown .ra-chip {
    flex-shrink: 0; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 800; letter-spacing: 0.03em; white-space: nowrap;
}
#archive-picker-dropdown .ra-chip-current  { background: rgba(16,185,129,0.16); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.34); }
#archive-picker-dropdown .ra-chip-upcoming { background: rgba(148,163,184,0.14); color: #cbd5e1; border: 1px solid rgba(148,163,184,0.28); }

#archive-picker-dropdown .ra-empty { padding: 14px 12px; font-size: 13px; color: #94a3b8; }
#archive-picker-dropdown .ra-foot {
    display: flex; align-items: center; gap: 8px; padding: 10px 16px; font-size: 12px; color: #94a3b8;
    border-top: 1px solid rgba(148,163,184,0.16); background: rgba(148,163,184,0.05);
}

#archive-picker-btn.is-active { border-color: #8b5cf6 !important; box-shadow: 0 0 0 3px rgba(139,92,246,0.18); }

/* ── Banner ───────────────────────────────────────────────────────────── */
#report-archive-banner.report-archive-banner {
    display: flex; align-items: center; gap: 14px; margin: 0 0 14px; padding: 12px 16px; border-radius: 14px;
    border: 1px solid rgba(139,92,246,0.35); background: linear-gradient(135deg, rgba(139,92,246,0.14), rgba(99,102,241,0.05));
}
#report-archive-banner[hidden] { display: none; }
#report-archive-banner .report-archive-banner-icon {
    width: 38px; height: 38px; flex-shrink: 0; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(139,92,246,0.25); color: #ddd6fe;
}
#report-archive-banner .report-archive-banner-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; font-size: 13px; color: #cbd5e1; }
#report-archive-banner .report-archive-banner-text strong { font-size: 14px; color: #f1f5f9; }

/* ── Per-report tag (All Reports rows) ────────────────────────────────── */
#reports-container .report-archive-tag {
    display: flex; align-items: center; gap: 4px; margin-top: 5px; padding: 1px 8px; border-radius: 999px;
    font-size: 11px; font-weight: 700; white-space: nowrap; width: fit-content;
}
#reports-container .report-archive-tag.is-locked   { background: rgba(148,163,184,0.16); color: #cbd5e1; border: 1px solid rgba(148,163,184,0.3); }
#reports-container .report-archive-tag.is-carried  { background: rgba(245,158,11,0.14);  color: #fbbf24; border: 1px solid rgba(245,158,11,0.32); }
#reports-container .report-archive-tag.is-reopened { background: rgba(139,92,246,0.14);  color: #c4b5fd; border: 1px solid rgba(139,92,246,0.34); }

/* ── Light theme ──────────────────────────────────────────────────────── */
:root[data-theme-resolved='light'] #archive-picker-dropdown.report-archive-menu { background: #ffffff; border-color: #e9e5f5; box-shadow: 0 22px 48px rgba(76,29,149,0.14), 0 2px 6px rgba(15,23,42,0.06); }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-head { background: linear-gradient(135deg, #f5f3ff, #eef2ff); border-bottom-color: #ede9fe; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-head-icon { background: #7c3aed; color: #ffffff !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-head-text strong { color: #1e1b4b !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-head-text span { color: #6b7280 !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-group + .ra-group { border-top-color: #ede9fe; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-group-name { color: #6d28d9 !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item:hover:not(:disabled),
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item:focus-visible { background: #f5f3ff; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item-icon { background: #f3f0ff; color: #7c3aed !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item-title { color: #111827 !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item-dates { color: #6b7280 !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item-check { color: #7c3aed !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item.is-selected { background: #f5f3ff; border-color: #c4b5fd; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-item.is-selected .ra-item-icon { background: #7c3aed; color: #ffffff !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-chip-current  { background: #ecfdf5; color: #047857 !important; border-color: #a7f3d0; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-chip-upcoming { background: #f1f5f9; color: #64748b !important; border-color: #e2e8f0; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-empty { color: #6b7280 !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-foot { background: #fafafa; border-top-color: #f1f0f7; color: #6b7280 !important; }
:root[data-theme-resolved='light'] #archive-picker-dropdown .ra-foot span { color: #6b7280 !important; }

:root[data-theme-resolved='light'] #report-archive-banner.report-archive-banner { background: linear-gradient(135deg, #f5f3ff, #eef2ff); border-color: #ddd6fe; }
:root[data-theme-resolved='light'] #report-archive-banner .report-archive-banner-icon { background: #7c3aed; color: #ffffff !important; }
:root[data-theme-resolved='light'] #report-archive-banner .report-archive-banner-text,
:root[data-theme-resolved='light'] #report-archive-banner .report-archive-banner-text span { color: #4b5563 !important; }
:root[data-theme-resolved='light'] #report-archive-banner .report-archive-banner-text strong,
:root[data-theme-resolved='light'] #report-archive-banner .report-archive-banner-text strong span { color: #111827 !important; }

:root[data-theme-resolved='light'] #reports-container .report-archive-tag.is-locked   { background: #f1f5f9; color: #475569 !important; border-color: #cbd5e1; }
:root[data-theme-resolved='light'] #reports-container .report-archive-tag.is-carried  { background: #fffbeb; color: #92400e !important; border-color: #fde68a; }
:root[data-theme-resolved='light'] #reports-container .report-archive-tag.is-reopened { background: #f5f3ff; color: #6d28d9 !important; border-color: #ddd6fe; }

@media (max-width: 640px) {
    #report-archive-banner.report-archive-banner { flex-wrap: wrap; }
    #archive-picker-dropdown.report-archive-menu { right: auto; left: 0; }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<!-- Shared branded printout (logo letterhead) used by renderPrintWindow(). -->
<script src="/School_Facility_Maintenance_System/frontend/assets/js/sfms-print.js?v=20260927-1"></script>

<script>
let allReports = [];
let currentPage = 1;
let rowsPerPage = 10;
// All Reports pagination appears only once the list has at least this many
// reports (see renderPagination()).
const PAGINATION_MIN_REPORTS = 10;
let lastMonthOnly = false;
let selectedWeek = 0;
let statusGroupFilter = '';
// `lastLoadedScope` and `allTimeReportCount` were declared here. Both existed
// solely to feed the removed date-scope summary line: the first recorded the
// range the visible rows were fetched under, the second cached an all-time
// count used only inside that summary. With the summary gone nothing reads
// either one, so they are removed rather than kept as unread state. The date
// range itself is NOT tracked here and never was — it lives in the two date
// inputs and is read from them by filterReports() on every request.
const CURRENT_USER_ID = <?php echo json_encode($currentUserId); ?>;
const CURRENT_USER_ROLE = <?php echo json_encode($currentRole); ?>;
// "Prepared by" line on the printed Maintenance Reports Summary.
const CURRENT_USER_NAME = <?php echo json_encode((string)($currentUser['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const CAN_CREATE_REPORT = <?php echo json_encode($canCreateReport); ?>;
const CAN_EDIT_OWN_REPORTS = <?php echo json_encode($canEditOwnReports); ?>;
// TASK 9 — Role + Department Based Authorization (frontend defense-in-depth).
const IS_SUPER_ADMIN = <?php echo json_encode($isSuperAdmin); ?>;
const CAN_EDIT_DEPARTMENT_REPORTS = <?php echo json_encode($canEditDepartmentReports); ?>;
const CURRENT_USER_DEPARTMENT_ID = <?php echo json_encode($currentUserDepartmentId); ?>;

/**
 * Mirrors App\Services\ReportAuthorizationService::canModifyReport().
 * Administrator is exempt; everyone else needs the report to belong to their
 * own department. The backend re-checks this and returns HTTP 403 regardless —
 * this only hides/disables actions the user could not perform anyway.
 */
function isSameDepartmentAsUser(report) {
    if (IS_SUPER_ADMIN) return true;
    const userDept = (CURRENT_USER_DEPARTMENT_ID === null || CURRENT_USER_DEPARTMENT_ID === undefined)
        ? null
        : Number(CURRENT_USER_DEPARTMENT_ID);
    const reportDept = (!report || report.department_id === null || report.department_id === undefined || report.department_id === '')
        ? null
        : Number(report.department_id);
    return userDept === reportDept;
}

/**
 * TASK 9 — a report is editable when the department matches AND the role has
 * an edit permission for it: Maintenance Staff may edit reports they created,
 * Head Maintenance may edit any report in their own department.
 */
function canEditReport(report) {
    if (!report || !isSameDepartmentAsUser(report)) return false;
    // Report Archive — a finished report from a past academic term is
    // view-only (the API refuses the edit too; this just hides the button).
    if (report.archive && report.archive.is_locked) return false;

    const ownsReport = CAN_EDIT_OWN_REPORTS
        && CURRENT_USER_ID > 0
        && Number(report.created_by || 0) === Number(CURRENT_USER_ID);

    return ownsReport || CAN_EDIT_DEPARTMENT_REPORTS === true;
}

// Report Archive — small tag under a report's title in the list. The term
// ("Second Semester 2025–2026") is in the tooltip.
function archiveTagHtml(report) {
    const archive = report && report.archive;
    if (!archive || !archive.is_archived) return '';

    const term = archive.term_label ? escapeHtml(archive.term_label) : 'a past academic term';
    if (archive.is_locked) {
        return `<span class="report-archive-tag is-locked" title="From ${term}. Finished reports from past terms are view-only.">${repIcon('lock')} Archived · View only</span>`;
    }
    if (archive.is_reopened) {
        return `<span class="report-archive-tag is-reopened" title="From ${term}. Reopened by the Administrator for changes.">Reopened · ${term}</span>`;
    }
    return `<span class="report-archive-tag is-carried" title="Unfinished report from ${term}. It stays actionable until it is completed.">Carried over · ${term}</span>`;
}

const REPORTS_API = window.SFMS_PUBLIC_URL('/api/reports');
const LEGACY_REPORTS_API = window.SFMS_PUBLIC_URL('/api/reports');
let activeEditReportId = null;

function applyStatusGroupFilter(reports) {
    if (!Array.isArray(reports) || !statusGroupFilter) {
        return Array.isArray(reports) ? reports : [];
    }

    if (statusGroupFilter === 'pending_tasks') {
        return reports.filter((report) => {
            const status = String(report.status || '').toLowerCase();
            return status === 'submitted' || status === 'assigned';
        });
    }

    if (statusGroupFilter === 'unresolved') {
        return reports.filter((report) => {
            const status = String(report.status || '').toLowerCase();
            return status !== 'completed' && status !== 'closed';
        });
    }

    if (statusGroupFilter === 'resolved') {
        return reports.filter((report) => {
            const status = String(report.status || '').toLowerCase();
            return status === 'completed' || status === 'closed';
        });
    }

    if (statusGroupFilter === 'assigned_to_me') {
        return reports.filter((report) => Number(report.assigned_to || 0) === Number(CURRENT_USER_ID));
    }

    if (statusGroupFilter === 'overdue') {
        const today = new Date();
        const todayKey = formatLocalDate(today);
        return reports.filter((report) => {
            const status = String(report.status || '').toLowerCase();
            const dueDate = extractReportDateKey(report.due_date);
            return Number(report.assigned_to || 0) === Number(CURRENT_USER_ID)
                && dueDate !== null
                && dueDate < todayKey
                && status !== 'completed'
                && status !== 'closed';
        });
    }

    if (statusGroupFilter === 'due_soon') {
        const today = new Date();
        const endDate = new Date(today);
        endDate.setDate(endDate.getDate() + 7);
        const todayKey = formatLocalDate(today);
        const endKey = formatLocalDate(endDate);
        return reports.filter((report) => {
            const status = String(report.status || '').toLowerCase();
            const dueDate = extractReportDateKey(report.due_date);
            return Number(report.assigned_to || 0) === Number(CURRENT_USER_ID)
                && dueDate !== null
                && dueDate >= todayKey
                && dueDate <= endKey
                && status !== 'completed'
                && status !== 'closed';
        });
    }

    if (statusGroupFilter === 'recent_assignments') {
        const limitDate = new Date();
        limitDate.setDate(limitDate.getDate() - 7);
        const limitKey = formatLocalDate(limitDate);
        return reports.filter((report) => {
            const updatedKey = extractReportDateKey(report.updated_at || report.created_at);
            return Number(report.assigned_to || 0) === Number(CURRENT_USER_ID)
                && String(report.status || '').toLowerCase() === 'assigned'
                && updatedKey !== null
                && updatedKey >= limitKey;
        });
    }

    return reports;
}

function getReportWeekNumber(createdAt) {
    const dateKey = extractReportDateKey(createdAt);
    if (!dateKey) return null;

    const date = new Date(`${dateKey}T00:00:00`);
    if (Number.isNaN(date.getTime())) return null;

    const day = date.getDate();
    if (day <= 7) return 1;
    if (day <= 14) return 2;
    if (day <= 21) return 3;
    return 4;
}

function getReportsBySelectedWeek(reports) {
    if (!selectedWeek) return reports;
    return reports.filter((report) => getReportWeekNumber(report.created_at) === selectedWeek);
}

function renderWeekPagination(sourceReports) {
    const container = document.getElementById('week-pagination');
    if (!container) return;

    const counts = { 1: 0, 2: 0, 3: 0, 4: 0 };
    sourceReports.forEach((report) => {
        const week = getReportWeekNumber(report.created_at);
        if (week && counts[week] !== undefined) {
            counts[week] += 1;
        }
    });

    let html = '<div class="week-pagination-controls">';
    html += `<button type="button" class="week-page-btn ${selectedWeek === 0 ? 'active' : ''}" data-week="0">All Weeks (${sourceReports.length})</button>`;

    for (let week = 1; week <= 4; week += 1) {
        const isActive = selectedWeek === week ? 'active' : '';
        const isDisabled = counts[week] === 0 ? 'disabled' : '';
        html += `<button type="button" class="week-page-btn ${isActive}" data-week="${week}" ${isDisabled}>Week ${week} (${counts[week]})</button>`;
    }

    html += '</div>';
    container.innerHTML = html;
}

/*
 * renderReportsScopeNotice() and fetchAllTimeReportCount() USED TO LIVE HERE.
 *
 * WHAT WAS REMOVED
 * ----------------
 * A summary line painted above the table that restated the active date range
 * as a chip ("Sep 1, 2026 - Sep 16, 2026") plus one of two sentences,
 * "Reports are limited to this date range." or "No reports fall in this date
 * range." When the range was empty it also fetched an unscoped count so it
 * could add "N reports exist outside it". All of that was display text about
 * the filter; none of it was the filter.
 *
 * WHY IT IS GONE
 * --------------
 * Removed by request as redundant: the two date inputs sit immediately above
 * where this line rendered and already show the active range, so the chip was
 * a second, lagging copy of information the controls carry natively.
 *
 * WHAT IS EXPLICITLY NOT AFFECTED
 * -------------------------------
 * This was the only consumer of the scope state, and it never filtered, never
 * re-queried and never touched a row. The date filtering behaviour is exactly
 * as it was:
 *   - plain load        -> current month (1st -> today), JS and no-JS PHP path
 *   - "Clear Date"      -> re-applies that same current month
 *   - ?last_month=1     -> previous month
 *   - ?date_scope=today -> today
 *   - user-typed range  -> that range
 * and date_from/date_to are still sent to /api/reports on every request.
 *
 * ONE THING WORTH KNOWING BEFORE ANYONE "SIMPLIFIES" THE DEFAULT AGAIN
 * -------------------------------------------------------------------
 * This notice was originally added because an empty current-month result read
 * as "this system has no reports" rather than "nothing matched this month".
 * The notice is now gone, so that ambiguity is back in the narrow case where
 * the current month happens to be empty. It is an accepted trade: the fix for
 * it is to look at the date inputs, which are visible, populated and directly
 * editable. What must NOT happen is "fixing" it by widening the default scope
 * to all-time - that was tried once, shipped months-old reports on a plain
 * visit, and was reported as a regression and reverted. The default scope and
 * this display text are separate decisions; only the display text was removed.
 */


function renderReportsView() {
    const groupedReports = applyStatusGroupFilter(allReports);
    renderWeekPagination(groupedReports);
    const filteredReports = getReportsBySelectedWeek(groupedReports);
    const totalReports = filteredReports.length;
    // A renderReportsScopeNotice() call sat here, repainting the date-scope
    // summary line on every render. The line is gone; the render order around
    // it (week pagination -> week slice -> page slice -> table -> pagination)
    // is otherwise untouched.
    const totalPages = Math.max(1, Math.ceil(totalReports / rowsPerPage));

    if (currentPage > totalPages) {
        currentPage = totalPages;
    }

    const startIndex = (currentPage - 1) * rowsPerPage;
    const endIndex = startIndex + rowsPerPage;
    const pagedReports = filteredReports.slice(startIndex, endIndex);

    displayReports(pagedReports);
    renderPagination(totalReports, totalPages, startIndex);
}

function renderPagination(totalReports, totalPages, startIndex) {
    const container = document.getElementById('pagination-container');
    if (!container) return;

    // ALL REPORTS PAGINATION VISIBILITY — the whole pagination/status bar (the
    // "Showing X-Y of N reports" summary, the page buttons and the rows-per-page
    // control) appears only once the list reaches PAGINATION_MIN_REPORTS reports,
    // and is hidden below that. The threshold is deliberately independent of the
    // selected page size: the previous "hide when everything fits on one page"
    // rule meant picking 20 rows on a 15-report list hid the bar — including the
    // rows-per-page control itself — leaving no way to switch back.
    if (totalReports < PAGINATION_MIN_REPORTS) {
        container.innerHTML = '';
        return;
    }

    const endIndex = Math.min(startIndex + rowsPerPage, totalReports);
    const pageButtons = [];
    for (let page = 1; page <= totalPages; page += 1) {
        const isActive = currentPage === page;
        pageButtons.push(`
            <li><button type="button" class="reports-pagination-btn pagination-link ${isActive ? 'active is-active' : ''}" data-page="${page}" ${isActive ? 'aria-current="page"' : ''}>${page}</button></li>
        `);
    }

    container.innerHTML = `
        <div class="reports-pagination-summary">Showing <strong>${startIndex + 1}-${endIndex}</strong> of <strong>${totalReports}</strong> reports</div>
        <ul class="reports-pagination-controls pagination-list">
            <li><button type="button" class="reports-pagination-btn pagination-link pagination-prev" data-page="1" ${currentPage === 1 ? 'disabled' : ''}>&laquo;</button></li>
            <li><button type="button" class="reports-pagination-btn pagination-link pagination-prev" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''}>&lsaquo;</button></li>
            ${pageButtons.join('')}
            <li><button type="button" class="reports-pagination-btn pagination-link pagination-next" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''}>&rsaquo;</button></li>
            <li><button type="button" class="reports-pagination-btn pagination-link pagination-next" data-page="${totalPages}" ${currentPage === totalPages ? 'disabled' : ''}>&raquo;</button></li>
        </ul>
        <label class="reports-pagination-size">
            Rows per page:
            <select id="rows-per-page-select">
                <option value="5" ${rowsPerPage === 5 ? 'selected' : ''}>5</option>
                <option value="7" ${rowsPerPage === 7 ? 'selected' : ''}>7</option>
                <option value="10" ${rowsPerPage === 10 ? 'selected' : ''}>10</option>
                <option value="20" ${rowsPerPage === 20 ? 'selected' : ''}>20</option>
            </select>
        </label>
    `;
}

function getLastMonthDateRange() {
    const today = new Date();
    const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
    const lastMonthStart = new Date(lastMonthEnd.getFullYear(), lastMonthEnd.getMonth(), 1);

    return {
        start: lastMonthStart,
        end: lastMonthEnd
    };
}

function getCurrentMonthDateRange() {
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    return {
        start,
        end: today
    };
}

function formatLocalDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function extractReportDateKey(createdAt) {
    if (!createdAt) return null;

    // MySQL DATETIME usually comes as "YYYY-MM-DD HH:MM:SS"
    const raw = String(createdAt).trim();
    const directMatch = raw.match(/^(\d{4}-\d{2}-\d{2})/);
    if (directMatch) {
        return directMatch[1];
    }

    // Fallback parser for other possible formats.
    const normalized = raw.replace(' ', 'T');
    const parsed = new Date(normalized);
    if (Number.isNaN(parsed.getTime())) return null;
    return formatLocalDate(parsed);
}

function getReportsLoadingMarkup() {
    return `
        <div class="ui-empty-state ui-fade-in" aria-live="polite">
            <strong>Loading reports...</strong>
            <div class="ui-skeleton-list" style="margin-top: 12px;">
                <div class="ui-skeleton-row w-90"></div>
                <div class="ui-skeleton-row w-75"></div>
                <div class="ui-skeleton-row w-55"></div>
                <div class="ui-skeleton-row w-40"></div>
            </div>
        </div>
    `;
}

function getReportsEmptyMarkup() {
    if (CAN_CREATE_REPORT) {
        return '<div class="ui-empty-state ui-fade-in"><strong>No reports found.</strong><span>You can <a href="' + window.SFMS_PUBLIC_URL('/reports/create') + '">create a new report</a> to get started.</span></div>';
    }

    return '<div class="ui-empty-state ui-fade-in"><strong>No reports found.</strong><span>No reports are available for the selected period.</span></div>';
}

function getReportsErrorMarkup(message) {
    return `<div class="alert alert-danger ui-fade-in"><strong>Error loading reports:</strong><br>${escapeHtml(message)}</div>`;
}

// Load all reports
async function loadReports(filters = {}) {
    try {
        const reportsContainer = document.getElementById('reports-container');
        if (reportsContainer) {
            reportsContainer.innerHTML = getReportsLoadingMarkup();
        }

        const params = new URLSearchParams({
            per_page: 200,
            ...filters
        });

        console.log('[DEBUG] API Request URL:', `${REPORTS_API}?${params.toString()}`);
        const res = await fetch(`${REPORTS_API}?${params.toString()}`, {
            credentials: 'include'
        });
        const response = await res.json();
        console.log('[DEBUG] API Response:', response);
        
        if (!response.success) {
            throw new Error(response.message || 'Failed to fetch reports');
        }
        
        if (!response.data || !Array.isArray(response.data.reports)) {
            console.warn('⚠️ Unexpected response format:', response);
            throw new Error('Invalid response format - missing reports array');
        }
        
        allReports = response.data.reports;
        // The removed date-scope summary line recorded `lastLoadedScope` here —
        // the range this result set was fetched under plus the server's own
        // total — purely so it could describe them on screen. Nothing reads it
        // now, so the bookkeeping goes with the display it fed. The request
        // itself is unchanged: `filters` still carries date_from/date_to.
        //
        // One diagnostic the summary provided is worth keeping, so it moves to
        // the console rather than disappearing: loadReports() always asks for
        // per_page=200 and paginates client-side, so a server total larger than
        // the rows received means the remainder was silently dropped.
        const serverTotal = Number(response.data.total ?? allReports.length);
        console.log(`📊 Total reports loaded: ${allReports.length}`);
        if (serverTotal > allReports.length) {
            console.warn(
                `⚠️ Showing the ${allReports.length} most recent of ${serverTotal} matching reports ` +
                '(per_page=200 cap) — narrow the date range to reach the rest.'
            );
        }

        renderReportsView();
    } catch (error) {
        console.error('❌ Error loading reports:', error);
        console.error('Error stack:', error.stack);
        document.getElementById('reports-container').innerHTML = getReportsErrorMarkup(error.message || 'Unable to fetch data.');
    }
}

// getLifecycleBadge() USED TO LIVE HERE. It rendered the Lifecycle column's
// "Damage: <status>" badge from report.damage_report_status, and it was the
// ONLY caller of that field on this page. The Lifecycle column has been removed
// from the All Reports table, so the helper is removed with it rather than left
// behind as dead code.
//
// THIS IS A UI-ONLY REMOVAL — nothing downstream of it was touched:
//   - damage_reports.status (the column, the rows, the history) is untouched.
//   - ReportController::index() still SELECTs it and still returns
//     damage_report_status on every report; ReportUnifiedReadSurfaceTest pins
//     that API contract and still passes.
//   - The Damage Report module remains the owner and primary display of that
//     lifecycle, and getReportTypeBadge() below still classifies rows from the
//     same damage-report data.
// So the field is still on every row object here; only this table stopped
// painting a column for it.

/**
 * TASK 99 — Report Type badge. report_type is computed in SQL by
 * ReportController::index() from the linked damage_reports row's replacement
 * outcome, so this renders the classification rather than re-deriving it: the
 * filter, the table and the export can never disagree.
 */
function getReportTypeLabel(report) {
    return String(report?.report_type || 'maintenance').toLowerCase() === 'damage'
        ? 'Damage'
        : 'Maintenance';
}

function getReportTypeBadge(report) {
    const label = getReportTypeLabel(report);
    const badgeClass = label === 'Damage' ? 'badge-danger' : 'badge-info';
    return `<span class="badge ${badgeClass}">${label}</span>`;
}

// Display reports
function displayReports(reports) {
    const container = document.getElementById('reports-container');
    
    console.log('📊 displayReports called with:', reports);
    
    if (!reports || reports.length === 0) {
        console.warn('⚠️ No reports to display');
        container.innerHTML = getReportsEmptyMarkup();
        return;
    }
    
    console.log('✅ Building table for', reports.length, 'reports');

    // TASK 9 — canEditReport() is now defined once at the top of this script
    // (department-aware, mirrors ReportAuthorizationService::canModifyReport()).

    // Start building HTML
    //
    // TASK — ALL REPORTS TABLE RESPONSIVENESS. This table used to carry 12
    // columns (ID, Title, Department, Priority, Status, Resolution, Assigned
    // To, Location, Created By, Date, Type, Actions), which overflowed on
    // normal laptop screens. UI-ONLY simplification to 9 columns:
    //   - ID is no longer its own column. It now renders INSIDE the first
    //     cell, stacked above the title (see .reports-cell-report below) —
    //     the report_id value itself is untouched, still present on every
    //     row object, still the same value used by the View link's href.
    //   - Type (report_type / getReportTypeBadge()) is no longer a table
    //     column. report.report_type is still returned by the API and is
    //     unchanged; getReportTypeBadge()/getReportTypeLabel() are left
    //     defined above (still used elsewhere) rather than deleted, and
    //     the full type is still shown on the View Report detail page.
    //   - Created By is no longer a table column. report.creator_name is
    //     still returned by the API and still shown on the View Report
    //     detail page; it is simply not repeated as a 10th table column
    //     here.
    // No column's underlying data was removed from the API response or the
    // database — only which columns this ONE table paints.
    //
    // A <colgroup> gives every remaining column a fixed share of the table's
    // width (table-layout: fixed, see reports.inline1.css) instead of
    // letting long content (long titles, long locations) stretch the table
    // wider than its card and force page-level horizontal scroll.
    let html = '';
    html += '<div class="reports-table-wrap ui-fade-in">';
    html += '<table class="reports-table">';
    html += '<colgroup>';
    html += '<col class="col-report">';
    html += '<col class="col-department">';
    html += '<col class="col-priority">';
    html += '<col class="col-status">';
    html += '<col class="col-resolution">';
    html += '<col class="col-assigned">';
    html += '<col class="col-location">';
    html += '<col class="col-date">';
    html += '<col class="col-actions">';
    html += '</colgroup>';
    html += '<thead>';
    html += '<tr>';
    html += '<th>Report</th>';
    html += '<th>Department</th>';
    html += '<th>Priority</th>';
    html += '<th>Status</th>';
    html += '<th>Resolution</th>';
    // A Lifecycle column sat here, rendering a "Damage: <status>" badge from
    // report.damage_report_status. It was REMOVED from this table by request.
    //
    // UI ONLY — the COLUMN is gone, the DATA is not. ReportController::index()
    // still LEFT JOINs damage_reports and still selects dr.status AS
    // damage_report_status, so the field is still on every row object here; the
    // Damage Report module remains its owner and primary display; and no
    // damage_reports row, column or history was touched. Putting the column
    // back is a matter of re-adding a <th> and a <td>, nothing more.
    html += '<th>Assigned To</th>';
    html += '<th>Location</th>';
    html += '<th>Date</th>';
    html += '<th>Actions</th>';
    html += '</tr>';
    html += '</thead>';
    html += '<tbody>';
    
    reports.forEach((report) => {
        const reportId = report.report_id;
        const title = escapeHtml(report.title);
        const priority = (report.priority || 'medium').toLowerCase();
        const status = (report.status || 'submitted').toLowerCase();
        const location = escapeHtml(report.location);
        const creatorName = escapeHtml(report.creator_name || 'Unknown');
        const createdAt = formatDate(report.created_at);
        
        // Need Change indicator
        const hasNeedChange = !!(report.need_change_item_id && report.need_change_item_id > 0);
        const needChangeStatus = String(report.need_change_status || '').toLowerCase();
        let needChangeBadge = '';
        let needChangeItem = '';
        if (hasNeedChange) {
            needChangeItem = escapeHtml(report.need_change_item_name || ('Item #' + report.need_change_item_id));
            if (needChangeStatus === 'deducted' || needChangeStatus === 'approved') {
                // TASK 7 — the ✓ / ✕ / ⏳ glyphs become registry icons. The
                // status WORD is retained in every branch, so meaning never
                // rests on the icon or on the badge colour alone, and the
                // status values themselves are untouched.
                needChangeBadge = '<span class="badge badge-success">' + repIcon('check') + ' Approved</span>';
            } else if (needChangeStatus === 'rejected') {
                needChangeBadge = '<span class="badge badge-danger">' + repIcon('x') + ' Rejected</span>';
            } else {
                needChangeBadge = '<span class="badge badge-warning">' + repIcon('clock') + ' Pending</span>';
            }
        } else {
            needChangeBadge = '<span class="badge badge-info">None</span>';
        }
        
        const priorityClass = getPriorityBadgeClass(priority);
        const statusClass = getStatusBadgeClass(status);
        // IT-expert color correction: Resolved=Green, Unresolved=Solid Amber.
        // Uses dedicated badge-resolved/badge-unresolved classes (see
        // color-scheme.css) instead of the generic badge-success/badge-warning
        // classes, so this column can't drift in tone with the Status column's
        // Assigned badge or be affected by unrelated badge-warning tweaks.
        const resolutionClass = (status === 'completed' || status === 'closed') ? 'badge-resolved' : 'badge-unresolved';
        const resolutionLabel = (status === 'completed' || status === 'closed') ? 'Resolved' : 'Unresolved';
        const statusLabel = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        
        // set data-id for highlighting later
        html += `<tr data-id="${reportId}">`;
        const deptName = escapeHtml(report.department_name || '\u2014');
        // TASK \u2014 ALL REPORTS TABLE RESPONSIVENESS. Report ID + Title merged
        // into one "Report" cell (ID stacked above the title, matching the
        // "#75 / Test" example) instead of a separate ID column. Every other
        // <td> below carries a data-label attribute \u2014 inert on normal
        // laptop/desktop widths, but read by the ::before rule in
        // reports.inline1.css's @media (max-width: 640px) block, which is
        // what turns each row into a labelled mobile card instead of an
        // unlabelled horizontally-scrolling table. creatorName is still
        // computed above (still used by the View Report detail page's own
        // data) even though it is no longer a column in this table.
        html += `<td class="reports-cell-report" data-label="Report"><span class="reports-id">#${reportId}</span><span class="reports-title">${title}</span>${archiveTagHtml(report)}</td>`;
        html += `<td data-label="Department">${deptName}</td>`;
        html += `<td data-label="Priority"><span class="badge ${priorityClass}">${priority.toUpperCase()}</span></td>`;
        html += `<td data-label="Status"><span class="badge ${statusClass}">${statusLabel}</span></td>`;
        html += `<td data-label="Resolution"><span class="badge ${resolutionClass}">${resolutionLabel}</span></td>`;
        html += `<td class="reports-cell-compact" data-label="Assigned To">${report.assigned_name ? escapeHtml(report.assigned_name) : '<span class="report-unassigned">Unassigned</span>'}</td>`;
        html += `<td class="reports-cell-location" data-label="Location"><span class="reports-location-text" title="${location}">${location}</span></td>`;
        html += `<td class="reports-cell-compact" data-label="Date">${createdAt}</td>`;
        html += `<td class="reports-cell-actions" data-label="Actions">
            <div class="reports-actions-buttons">
            <a href="maintenance-report-detail.php?id=${reportId}&back=all_reports" class="btn btn-sm btn-primary">View</a>${canEditReport(report) ? `<button type="button" class="btn btn-sm btn-secondary" onclick="openEditReportModal(${reportId})">Edit</button>` : ''}
            </div>
        </td>`;
        html += '</tr>';
    });
    
    html += '</tbody>';
    html += '</table>';
    html += '</div>';
    
    console.log('✅ HTML generated, setting to container');
    
    container.innerHTML = html;
    console.log('✅ Reports displayed successfully!');
    // check URL for new_id to highlight
    const newId = getQueryParam('new_id');
    if (newId) highlightNewReport(newId);
}

function showEditAlert(message, type = 'danger') {
    const alertBox = document.getElementById('report-edit-alert');
    if (!alertBox) return;
    if (!message) {
        alertBox.innerHTML = '';
        return;
    }
    alertBox.innerHTML = `<div class="alert alert-${type}">${escapeHtml(message)}</div>`;
}

function setEditLoading(isLoading) {
    const saveBtn = document.getElementById('report-edit-save-btn');
    if (saveBtn) {
        saveBtn.disabled = isLoading;
        saveBtn.textContent = isLoading ? 'Saving...' : 'Save Changes';
    }
}

function closeEditModal() {
    const modal = document.getElementById('report-edit-modal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    activeEditReportId = null;
    const alertBox = document.getElementById('report-edit-alert');
    if (alertBox) alertBox.innerHTML = '';
}

function openEditModal() {
    const modal = document.getElementById('report-edit-modal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function normalizeGetResponse(payload) {
    if (!payload) return null;
    if (payload.report) return payload.report;
    if (payload.data && payload.data.report) return payload.data.report;
    if (payload.data && payload.data.report_id) return payload.data;
    if (payload.report_id) return payload;
    return null;
}

/* -------------------------------------------------------------------
   Problem Type — Edit Report modal.

   The same four helpers create-report.php declares, against the
   report_edit_-prefixed ids. They are not shared as a common .js file
   for the same reason the Need Change block above is not: the two pages
   are never loaded together, and the duplication here is ~40 lines of
   DOM plumbing, while the parts that actually MATTER — the option list
   and the "Other" rule — are genuinely shared (the PHP config file and
   ReportService::resolveProblemTypeOther() respectively).

   The "Other" string comes from that same config file rather than a
   literal, matching Create Report.
   ------------------------------------------------------------------- */
const EDIT_PROBLEM_TYPE_OTHER = <?php echo json_encode($problemTypeOtherValue); ?>;

function editProblemTypeInputs() {
    return Array.from(document.querySelectorAll('.problem-type-input[name="report_edit_problem_type"]'));
}

function selectedEditProblemType() {
    const checked = editProblemTypeInputs().find((input) => input.checked);
    return checked ? checked.value : null;
}

function editProblemTypeOtherValue() {
    if (selectedEditProblemType() !== EDIT_PROBLEM_TYPE_OTHER) return null;
    const value = document.getElementById('report_edit_problem_type_other')?.value.trim() || '';
    return value === '' ? null : value;
}

function editProblemTypeErrorText() {
    if (!selectedEditProblemType()) return 'Please select a problem type.';
    if (selectedEditProblemType() === EDIT_PROBLEM_TYPE_OTHER && !editProblemTypeOtherValue()) {
        return 'Please specify the problem type.';
    }
    return '';
}

function validateEditProblemType() {
    const message = editProblemTypeErrorText();
    const missingSelection = !selectedEditProblemType();
    const missingOther = !missingSelection && message !== '';

    const selectError = document.getElementById('report_edit_problem_type_error');
    const otherError = document.getElementById('report_edit_problem_type_other_error');
    if (selectError) selectError.hidden = !missingSelection;
    if (otherError) otherError.hidden = !missingOther;
    document.getElementById('report_edit_problem_type_grid')
        ?.classList.toggle('is-invalid', missingSelection);

    return message === '';
}

// Pre-selects the saved category and prefills the custom text. A report
// created before this feature existed has problem_type = null; nothing is
// selected in that case and the user must choose one before saving, which is
// the intended migration path for legacy rows — it is the only point at which
// a human is looking at the report and can say what kind of problem it is.
function applyEditProblemType(report) {
    const saved = report.problem_type || '';
    const otherInput = document.getElementById('report_edit_problem_type_other');
    const otherGroup = document.getElementById('report_edit_problem_type_other_group');

    editProblemTypeInputs().forEach((input) => {
        input.checked = (input.value === saved);
    });

    const isOther = saved === EDIT_PROBLEM_TYPE_OTHER;
    if (otherInput) otherInput.value = isOther ? (report.problem_type_other || '') : '';
    if (otherGroup) otherGroup.hidden = !isOther;

    // Clear any message left over from a previous report opened in this
    // modal — the DOM persists between openings.
    const selectError = document.getElementById('report_edit_problem_type_error');
    const otherError = document.getElementById('report_edit_problem_type_other_error');
    if (selectError) selectError.hidden = true;
    if (otherError) otherError.hidden = true;
    document.getElementById('report_edit_problem_type_grid')?.classList.remove('is-invalid');
}

document.addEventListener('DOMContentLoaded', () => {
    const otherInput = document.getElementById('report_edit_problem_type_other');

    document.getElementById('report_edit_problem_type_grid')?.addEventListener('change', () => {
        const isOther = selectedEditProblemType() === EDIT_PROBLEM_TYPE_OTHER;
        const otherGroup = document.getElementById('report_edit_problem_type_other_group');
        if (otherGroup) otherGroup.hidden = !isOther;
        if (!isOther && otherInput) otherInput.value = '';

        const selectError = document.getElementById('report_edit_problem_type_error');
        if (selectError) selectError.hidden = true;
        document.getElementById('report_edit_problem_type_grid')?.classList.remove('is-invalid');
        if (isOther) otherInput?.focus();
    });

    otherInput?.addEventListener('input', () => {
        const otherError = document.getElementById('report_edit_problem_type_other_error');
        if (otherError && otherInput.value.trim() !== '') otherError.hidden = true;
    });
});

async function openEditReportModal(reportId) {
    try {
        const targetReport = allReports.find((report) => Number(report.report_id) === Number(reportId));
        // TASK 9 — Role + Department Based Authorization: refuse to even open the
        // editor for a report the user may not modify. The backend enforces the
        // same rule and answers HTTP 403 (see ReportController::update()).
        if (!targetReport) {
            throw new Error('Report could not be found.');
        }
        if (!isSameDepartmentAsUser(targetReport)) {
            throw new Error('This report belongs to a different department. You have view-only access and cannot modify it.');
        }
        if (!canEditReport(targetReport)) {
            throw new Error('You can only edit reports that you created.');
        }

        activeEditReportId = Number(reportId);
        showEditAlert('', 'danger');
        const titleEl = document.getElementById('report-edit-title');
        if (titleEl) titleEl.textContent = `Edit Maintenance Report #${activeEditReportId}`;

        openEditModal();
        setEditLoading(true);

        const response = await fetch(`${LEGACY_REPORTS_API}/${activeEditReportId}`, {
            credentials: 'include'
        });
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'Failed to load report details.');
        }

        const report = normalizeGetResponse(result);
        if (!report) {
            throw new Error('Invalid report payload.');
        }

        document.getElementById('report_edit_title').value = report.title || '';
        document.getElementById('report_edit_location').value = report.location || '';
        document.getElementById('report_edit_priority').value = (report.priority || 'medium').toLowerCase();
        document.getElementById('report_edit_description').value = report.description || '';
        applyEditProblemType(report);

        await applyNeedChangeEditState(report);

        const alertBox = document.getElementById('report-edit-alert');
        if (alertBox) alertBox.innerHTML = '';
    } catch (error) {
        showEditAlert(error.message || 'Could not load report.');
    } finally {
        setEditLoading(false);
    }
}

// TASK 29 / 29.1 — Need Change support in Edit Report.
// Ported from create-report.php's loadNeedChangeItems() /
// renderNeedChangeOptions() / updateNeedChangeSelection() (same fetch,
// filtering, and rendering logic), using report_edit_-prefixed ids/state so
// it does not collide with anything else on this page. Not shared as a
// common .js file because create-report.php and reports.php are never
// loaded together and Create Report is out of scope for this task per the
// approved Phase 2 plan.
let editNeedChangeItemsCache = [];
let selectedEditNeedChangeItem = null;
let reportEditNeedChangeLocked = false;

async function loadEditNeedChangeItems() {
    const results = document.getElementById('report_edit_need_change_results');
    if (!results) return;

    try {
        // TASK 42 — see create-report.php loadNeedChangeItems(). This is the same
        // Replacement Item picker on the report edit modal and needs the same
        // warehouse-supply-only filter.
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/items') + '?per_page=200&item_type=inventory_stock', {
            credentials: 'include'
        });
        const result = await response.json();

        const rawItems = result?.data?.data ?? result?.data?.items ?? result?.items ?? [];
        if (!result.success || !Array.isArray(rawItems)) {
            throw new Error(result.message || 'Failed to load inventory items');
        }

        editNeedChangeItemsCache = rawItems.filter((item) => Number(item.quantity || 0) > 0);
        renderEditNeedChangeOptions();
    } catch (error) {
        results.innerHTML = '<div class="need-change-empty">Unable to load items</div>';
        console.error('Failed to load need change items:', error);
    }
}

function renderEditNeedChangeOptions() {
    const searchInput = document.getElementById('report_edit_need_change_search');
    const results = document.getElementById('report_edit_need_change_results');
    if (!results) return;

    const keyword = String(searchInput?.value || '').trim().toLowerCase();

    if (!keyword) {
        results.style.display = 'none';
        results.innerHTML = '';
        return;
    }

    const filteredItems = editNeedChangeItemsCache.filter((item) =>
        String(item.name || '').toLowerCase().includes(keyword)
    );

    if (!filteredItems.length) {
        results.innerHTML = '<div class="need-change-empty">No matching inventory items</div>';
        results.style.display = 'block';
        return;
    }

    results.innerHTML = filteredItems.map((item) => {
        const isActive = selectedEditNeedChangeItem && String(selectedEditNeedChangeItem.id) === String(item.id);
        return `<button type="button" class="need-change-result-item${isActive ? ' active' : ''}" data-item-id="${item.id}">${escapeHtml(item.name)} <span>(Stock: ${escapeHtml(String(item.quantity))})</span></button>`;
    }).join('');
    results.style.display = 'block';
}

function updateEditNeedChangeSelection(item) {
    const hiddenInput = document.getElementById('report_edit_need_change_item');
    const selectedDisplay = document.getElementById('report_edit_need_change_selected');
    const searchInput = document.getElementById('report_edit_need_change_search');
    if (!hiddenInput || !selectedDisplay) return;

    selectedEditNeedChangeItem = item || null;
    hiddenInput.value = item ? String(item.id) : '';

    if (item) {
        selectedDisplay.style.display = 'block';
        selectedDisplay.textContent = `Selected: ${item.name} (Stock: ${item.quantity})`;
        if (searchInput) {
            searchInput.value = item.name || '';
        }
        const results = document.getElementById('report_edit_need_change_results');
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    } else {
        selectedDisplay.style.display = 'none';
        selectedDisplay.textContent = '';
    }

    renderEditNeedChangeOptions();
}

// TASK 29 — prepopulates the panel from the report already loaded by
// openEditReportModal(); TASK 29.1 — locks it read-only once
// need_change_status is approved/deducted (mirrors the backend guard in
// ReportController::update() CASE A, which is the actual enforcement — this
// is UX only, so the user understands why before ever clicking Save).
async function applyNeedChangeEditState(report) {
    const toggle = document.getElementById('report_edit_need_change_toggle');
    const wrap = document.getElementById('report_edit_need_change_wrap');
    const lockedNotice = document.getElementById('report_edit_need_change_locked');
    if (!toggle || !wrap || !lockedNotice) return;

    const status = String(report.need_change_status || '').toLowerCase();
    const isLocked = status === 'approved' || status === 'deducted';
    reportEditNeedChangeLocked = isLocked;
    toggle.disabled = isLocked;

    // Reset to a clean slate before repopulating (openEditReportModal reuses
    // the same modal/DOM across multiple reports).
    updateEditNeedChangeSelection(null);
    const searchInput = document.getElementById('report_edit_need_change_search');
    if (searchInput) searchInput.value = '';
    const results = document.getElementById('report_edit_need_change_results');
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }

    if (isLocked) {
        toggle.checked = true;
        wrap.style.display = 'none';
        const itemName = report.need_change_item_name || (report.need_change_item_id ? ('Item #' + report.need_change_item_id) : 'Unknown item');
        const hasStock = report.need_change_item_quantity !== undefined && report.need_change_item_quantity !== null;
        const stockText = hasStock ? ` (Stock: ${escapeHtml(String(report.need_change_item_quantity))})` : '';
        const reason = status === 'deducted'
            ? 'already been processed — inventory has been deducted'
            : 'already been approved';
        lockedNotice.style.display = 'block';
        lockedNotice.innerHTML = `<strong>Replacement Item:</strong> ${escapeHtml(itemName)}${stockText}`
            + `<br><span>This replacement request has ${reason}, so it can no longer be changed or removed here.</span>`;
        return;
    }

    lockedNotice.style.display = 'none';
    lockedNotice.innerHTML = '';

    if (report.need_change_item_id) {
        toggle.checked = true;
        wrap.style.display = 'block';
        await loadEditNeedChangeItems();
        const existingItem = editNeedChangeItemsCache.find((item) => String(item.id) === String(report.need_change_item_id));
        updateEditNeedChangeSelection(existingItem || {
            id: report.need_change_item_id,
            name: report.need_change_item_name || ('Item #' + report.need_change_item_id),
            quantity: report.need_change_item_quantity ?? 0
        });
    } else {
        toggle.checked = false;
        wrap.style.display = 'none';
    }
}

document.getElementById('report_edit_need_change_toggle')?.addEventListener('change', (event) => {
    if (reportEditNeedChangeLocked) {
        // Defensive only: the control is disabled, so this should not fire,
        // but never let a locked request silently be turned off.
        event.target.checked = true;
        return;
    }
    const wrap = document.getElementById('report_edit_need_change_wrap');
    if (wrap) {
        wrap.style.display = event.target.checked ? 'block' : 'none';
        if (event.target.checked) {
            loadEditNeedChangeItems();
        } else {
            updateEditNeedChangeSelection(null);
        }
    }
});

document.getElementById('report_edit_need_change_search')?.addEventListener('input', renderEditNeedChangeOptions);

document.getElementById('report_edit_need_change_results')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-item-id]');
    if (!button) return;

    const itemId = String(button.getAttribute('data-item-id') || '');
    const matchedItem = editNeedChangeItemsCache.find((item) => String(item.id) === itemId);
    if (!matchedItem) return;

    updateEditNeedChangeSelection(matchedItem);
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#report_edit_need_change_wrap')) {
        const results = document.getElementById('report_edit_need_change_results');
        if (results) results.style.display = 'none';
    }
});

async function saveEditedReport(event) {
    event.preventDefault();
    if (!activeEditReportId) return;

    // Problem Type is validated before the payload is assembled so the user
    // gets the specific sentence rather than the generic one below.
    if (!validateEditProblemType()) {
        showEditAlert(editProblemTypeErrorText());
        return;
    }

    const payload = {
        report_id: activeEditReportId,
        // problem_type_other is always sent alongside problem_type, including
        // as null. Sending null is what CLEARS a stale custom value when the
        // user edits from Other to a fixed category — the server derives it
        // through ReportService::resolveProblemTypeOther() either way, so this
        // key is really just carrying the text when it is relevant.
        problem_type: selectedEditProblemType(),
        problem_type_other: editProblemTypeOtherValue(),
        title: document.getElementById('report_edit_title').value.trim(),
        location: document.getElementById('report_edit_location').value.trim(),
        priority: document.getElementById('report_edit_priority').value,
        description: document.getElementById('report_edit_description').value.trim()
    };

    if (!payload.title || !payload.location || !payload.description) {
        showEditAlert('Please fill in all required fields.');
        return;
    }

    // TASK 29 / 29.1 — need_change_item_id is only ever added to the payload
    // while the request is still editable (no Need Change / Pending /
    // Rejected). Once approved or deducted, applyNeedChangeEditState() has
    // locked the toggle and reportEditNeedChangeLocked is true, so this
    // block is skipped entirely and the field is never sent — leaving the
    // already-processed value untouched. ReportController::update() CASE A
    // independently rejects the field too if it's ever sent while locked;
    // this is just the normal (never-triggered-in-practice) path staying
    // clean, not the actual enforcement.
    if (!reportEditNeedChangeLocked) {
        const needChangeToggle = document.getElementById('report_edit_need_change_toggle');
        const needChangeItemInput = document.getElementById('report_edit_need_change_item');
        if (needChangeToggle?.checked) {
            if (!needChangeItemInput?.value) {
                showEditAlert('Please select a replacement inventory item.');
                return;
            }
            payload.need_change_item_id = Number(needChangeItemInput.value);
        } else {
            // Toggle turned off (or never had a Need Change) before saving —
            // clear any existing selection. Safe here because we only reach
            // this branch when the request was not approved/deducted.
            payload.need_change_item_id = null;
        }
    }

    try {
        setEditLoading(true);
        const response = await fetch(`${LEGACY_REPORTS_API}/${activeEditReportId}`, {
            method: 'PATCH',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'Failed to update report.');
        }

        closeEditModal();
        filterReports();
    } catch (error) {
        showEditAlert(error.message || 'Failed to update report.');
    } finally {
        setEditLoading(false);
    }
}

// Utility functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// TASK 7 — renders an icon from the shared registry (includes/icon-paths.php,
// handed to the browser by header.php). Used by the Need Change status badges,
// which previously carried ✓ / ✕ / ⏳ glyphs. Inside a badge the icon inherits
// the badge's own foreground colour, so no theme-specific rule is needed.
function repIcon(name) {
    return window.UIIcons ? window.UIIcons.svg(name, { size: 13 }) : '';
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (e) {
        return dateString;
    }
}

function getPriorityClass(priority) {
    switch (priority) {
        case 'urgent':
        case 'critical':
            return 'danger';
        case 'high':
            return 'warning';
        case 'medium':
            return 'info';
        case 'low':
            return 'secondary';
        default:
            return 'light';
    }
}

// IT-expert priority-color correction: Critical=Red, High=Orange,
// Medium=Yellow, Low=Blue. (This function is unused elsewhere in this file —
// getPriorityBadgeClass() below is what All Reports actually renders with —
// but its stale colors are fixed too so nothing here still documents the
// old, wrong ramp.)
function getPriorityColor(priority) {
    switch (priority.toLowerCase()) {
        case 'urgent':
        case 'critical':
            return '#dc2626'; // red
        case 'high':
            return '#f97316'; // orange
        case 'medium':
            return '#eab308'; // yellow
        case 'low':
            return '#3b82f6'; // blue
        default:
            return '#6c757d';
    }
}

function getStatusClass(status) {
    switch (status) {
        case 'completed':
            return 'success';
        case 'in_progress':
        case 'assigned':
            return 'info';
        case 'submitted':
            return 'warning';
        case 'closed':
            return 'secondary';
        default:
            return 'light';
    }
}

function getStatusColor(status) {
    switch (status.toLowerCase()) {
        case 'completed':
        case 'closed':
            return '#198754'; // green
        case 'in_progress':
        case 'assigned':
            return '#0dcaf0'; // light blue
        case 'submitted':
            return '#fd7e14'; // orange
        default:
            return '#6c757d'; // grey
    }
}

// TASK 46 — aligned with UI.getPriorityBadge()/UI.getStatusBadge() in
// assets/js/utils.js (the shared helper used by maintenance-report-detail.php,
// maintenance-dashboard.php, and report-detail.php) so the same report no
// longer shows different colors for the same priority/status depending on
// which page it's viewed from. 'in_progress' keeps a distinct color here
// (utils.js currently maps it the same as 'assigned') since that difference
// was not part of the audited cross-page swap and is left alone per Task 46's
// small/safe-changes-only scope.
//
// IT-expert priority-color correction — switched from the generic
// badge-danger/warning/info classes (which made High and Critical render
// identically red) to the dedicated badge-critical/high/medium/low/urgent
// classes in color-scheme.css, now fixed to Critical=Red, High=Orange,
// Medium=Yellow, Low=Blue.
function getPriorityBadgeClass(priority) {
    switch ((priority || '').toLowerCase()) {
        case 'critical':
            return 'badge-critical';
        case 'urgent':
            return 'badge-urgent';
        case 'high':
            return 'badge-high';
        case 'medium':
            return 'badge-medium';
        case 'low':
            return 'badge-low';
        default:
            return 'badge-low';
    }
}

function getStatusBadgeClass(status) {
    switch ((status || '').toLowerCase()) {
        case 'completed':
            return 'badge-completed';
        case 'closed':
            return 'badge-report-closed';
        case 'cancelled':
            return 'badge-report-cancelled';
        case 'in_progress':
            return 'badge-in-progress';
        case 'assigned':
            return 'badge-assigned';
        case 'submitted':
            return 'badge-submitted';
        default:
            return 'badge-submitted';
    }
}

function getPrintableReportsByMode(mode, weekValue, dateValue, monthValue) {
    const sourceReports = [...allReports];

    if (mode === 'month') {
        if (!monthValue) return sourceReports;
        return sourceReports.filter((report) => {
            const dateKey = extractReportDateKey(report.created_at);
            return dateKey && dateKey.startsWith(monthValue);
        });
    }

    if (mode === 'week') {
        const parsedWeek = Number(weekValue);
        if (Number.isNaN(parsedWeek) || parsedWeek < 1 || parsedWeek > 4) return [];
        return sourceReports.filter((report) => getReportWeekNumber(report.created_at) === parsedWeek);
    }

    if (mode === 'date') {
        if (!dateValue) return [];
        return sourceReports.filter((report) => extractReportDateKey(report.created_at) === dateValue);
    }

    return getReportsBySelectedWeek(sourceReports);
}

/**
 * TASK 99 — the Export modal's Report Type control is a view onto the page
 * filter, not a second filter. These two helpers are the only link between
 * them, so the classification is still decided in exactly one place
 * (ReportController::index()).
 */
function syncPrintReportTypeFromFilter() {
    const pageTypeEl = document.getElementById('filter-report-type');
    const printTypeEl = document.getElementById('print-filter-report-type');
    if (pageTypeEl && printTypeEl) {
        printTypeEl.value = pageTypeEl.value;
    }
}

function setPrintExportBusy(isBusy) {
    ['print-modal-export-pdf', 'print-modal-export-excel', 'print-modal-generate'].forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = isBusy;
    });
}

function togglePrintFilterFields() {
    const modeEl = document.getElementById('print-filter-mode');
    const monthGroup = document.getElementById('print-month-group');
    const weekGroup = document.getElementById('print-week-group');
    const dateGroup = document.getElementById('print-date-group');
    const semesterGroup = document.getElementById('print-semester-group');
    if (!modeEl) return;

    if (monthGroup) monthGroup.style.display = modeEl.value === 'month' ? 'block' : 'none';
    if (weekGroup) weekGroup.style.display = modeEl.value === 'week' ? 'block' : 'none';
    if (dateGroup) dateGroup.style.display = modeEl.value === 'date' ? 'block' : 'none';
    if (semesterGroup) semesterGroup.style.display = modeEl.value === 'semester' ? 'block' : 'none';

    if (modeEl.value === 'semester') {
        refreshPrintSemesterInfo();
    }
}

// ── Entire Semester export scope ───────────────────────────────────────────
// The semester is always read from the existing Semester Settings
// (SchoolSetting::current(), surfaced via GET /api/school-settings) — never
// hardcoded or manually picked here. Cached per modal session; refreshed
// (force=true) is not needed since the schedule doesn't change while this
// modal is open.
let cachedSchoolSettings = null;

async function fetchSchoolSettings() {
    if (cachedSchoolSettings) return cachedSchoolSettings;

    const res = await fetch(window.SFMS_PUBLIC_URL('/api/school-settings'), {
        credentials: 'include'
    });
    const response = await res.json();
    if (!response.success || !response.data) {
        throw new Error(response.message || 'Failed to load semester settings');
    }

    cachedSchoolSettings = response.data;
    return cachedSchoolSettings;
}

// TASK 25.5-style formatter reused here: "2026-2027" -> "2026–2027". Never
// invents a school year; unexpected shapes are shown exactly as returned.
function formatSchoolYearLabel(schoolYear) {
    if (!schoolYear) return '';
    return /^\d{4}-\d{4}$/.test(schoolYear) ? schoolYear.replace('-', '–') : schoolYear;
}

// Returns null whenever no semester is literally running today (Upcoming /
// Break / Completed / Not Configured) — this export scope must never guess
// a semester, matching SchoolSetting's own "no fallback" rule.
function getActiveSemesterRange(settings) {
    if (!settings || !settings.semester_active || !settings.current_semester) return null;

    const isSecond = settings.current_semester === 'Second Semester';
    const start = isSecond ? settings.second_sem_start : settings.first_sem_start;
    const end = isSecond ? settings.second_sem_end : settings.first_sem_end;
    if (!start || !end) return null;

    const yearLabel = formatSchoolYearLabel(settings.school_year);
    return {
        start,
        end,
        label: yearLabel ? `${settings.current_semester} ${yearLabel}` : settings.current_semester
    };
}

async function refreshPrintSemesterInfo() {
    const infoEl = document.getElementById('print-semester-info');
    if (!infoEl) return;

    infoEl.textContent = 'Loading current semester…';
    try {
        const settings = await fetchSchoolSettings();
        const range = getActiveSemesterRange(settings);
        infoEl.textContent = range
            ? range.label
            : 'No active semester is currently configured in Semester Settings.';
    } catch (error) {
        console.error('Unable to load semester settings', error);
        infoEl.textContent = 'Unable to load semester information.';
    }
}

// Fetches EVERY report in [dateFrom, dateTo] that matches the other
// currently-selected filters (Report Type, Department, Status, Priority),
// looping over /api/reports's existing per_page=200 cap instead of relying
// on the already-loaded (and capped) `allReports`. This reuses the exact
// same endpoint — same role scoping/authorization, same WHERE clauses — as
// every other filter on this page; nothing here re-implements them.
async function fetchAllReportsForRange(dateFrom, dateTo) {
    const baseFilters = { date_from: dateFrom, date_to: dateTo, per_page: 200 };

    const status = document.getElementById('filter-status')?.value || '';
    const priority = document.getElementById('filter-priority')?.value || '';
    const deptEl = document.getElementById('filter-department');
    const reportType = document.getElementById('print-filter-report-type')?.value || '';

    if (status) baseFilters.status = status;
    if (priority) baseFilters.priority = priority;
    if (reportType) baseFilters.report_type = reportType;
    if (deptEl) baseFilters.department_id = deptEl.value || '';

    let page = 1;
    let fetched = [];
    let total = Infinity;
    const MAX_PAGES = 200; // 200 x per_page(200) = 40,000 reports safety ceiling

    while (fetched.length < total && page <= MAX_PAGES) {
        const params = new URLSearchParams({ ...baseFilters, page });
        const res = await fetch(`${REPORTS_API}?${params.toString()}`, { credentials: 'include' });
        const response = await res.json();
        if (!response.success || !response.data || !Array.isArray(response.data.reports)) {
            throw new Error(response.message || 'Failed to fetch reports for the selected semester');
        }

        if (response.data.reports.length === 0) break;
        fetched = fetched.concat(response.data.reports);
        total = Number(response.data.total ?? fetched.length);
        page += 1;
    }

    // Resolution (resolved/unresolved) is a client-side concept elsewhere on
    // this page (see applyStatusGroupFilter/renderReportsView) — reused here
    // rather than re-implemented so "resolved" means the same thing in the
    // export as it does on screen.
    return applyStatusGroupFilter(fetched);
}

// Shared by both Export PDF and Export Excel: resolves the active semester,
// fetches its full authorization-scoped report set, and surfaces the
// "no active semester" / "no matching reports" cases as a message instead of
// producing an empty file. Returns null when the caller should abort (the
// message has already been shown).
async function resolveSemesterExportData() {
    setPrintExportBusy(true);
    try {
        const settings = await fetchSchoolSettings();
        const range = getActiveSemesterRange(settings);
        if (!range) {
            Components.alert('No active semester is currently configured in Semester Settings.', 'warning');
            return null;
        }

        const reports = await fetchAllReportsForRange(range.start, range.end);
        if (!reports.length) {
            Components.alert(`No reports found for ${range.label} with the selected filters.`, 'warning');
            return null;
        }

        return { reports, label: `Entire Semester: ${range.label}` };
    } catch (error) {
        console.error('Semester export failed', error);
        Components.alert(error.message || 'Unable to export reports for the selected semester.', 'danger');
        return null;
    } finally {
        setPrintExportBusy(false);
    }
}

function openPrintReportModal() {
    const modal = document.getElementById('print-report-modal');
    const modeEl = document.getElementById('print-filter-mode');
    const dateInput = document.getElementById('print-date-input');
    const monthInput = document.getElementById('print-month-input');
    if (!modal || !modeEl) return;

    // Report Archive — while a past term is browsed, default to printing
    // exactly what is on screen (that term) rather than the current month.
    modeEl.value = reportArchiveSelection ? 'current' : 'month';
    if (dateInput) dateInput.value = formatLocalDate(new Date());
    if (monthInput) {
        const now = new Date();
        monthInput.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    }
    // TASK 99 — open showing the Report Type that actually produced the rows
    // currently in allReports, so the modal never claims a scope the loaded
    // data does not have.
    syncPrintReportTypeFromFilter();
    togglePrintFilterFields();

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closePrintReportModal() {
    const modal = document.getElementById('print-report-modal');
    if (!modal) return;

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

async function printSummaryReport() {
    const modeEl = document.getElementById('print-filter-mode');
    if (!modeEl) return;
    const mode = modeEl.value;

    let printableReports;
    let periodLabel = 'Current filtered results';

    if (mode === 'semester') {
        const result = await resolveSemesterExportData();
        if (!result) return; // message already shown to the user
        printableReports = result.reports;
        periodLabel = result.label;
    } else {
        const weekEl = document.getElementById('print-week-select');
        const dateEl = document.getElementById('print-date-input');
        const monthEl = document.getElementById('print-month-input');
        const weekValue = weekEl ? weekEl.value : '';
        const dateValue = dateEl ? dateEl.value : '';
        const monthValue = monthEl ? monthEl.value : '';
        printableReports = getPrintableReportsByMode(mode, weekValue, dateValue, monthValue);

        if (!Array.isArray(printableReports) || printableReports.length === 0) {
            Components.alert('No reports available to print for the selected period.', 'warning');
            return;
        }

        periodLabel = describePrintPeriod(mode, weekValue, dateValue, monthValue);
    }

    renderPrintWindow(printableReports, periodLabel);
}

// Human-readable "Period" line for the printout, from the Print modal's own
// selection. Month/date values are local calendar keys (YYYY-MM[-DD]), so they
// are built with the local-time Date constructor to avoid a UTC day shift.
function describePrintPeriod(mode, weekValue, dateValue, monthValue) {
    const monthName = (year, month) => new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    if (mode === 'month' && /^\d{4}-\d{2}$/.test(monthValue)) {
        const [y, m] = monthValue.split('-').map(Number);
        return `Month of ${monthName(y, m)}`;
    }
    if (mode === 'week' && weekValue) {
        const ranges = { 1: '1–7', 2: '8–14', 3: '15–21', 4: '22–end' };
        return `Week ${weekValue} (days ${ranges[weekValue] || ''} of the month)`;
    }
    if (mode === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
        const [y, m, d] = dateValue.split('-').map(Number);
        return new Date(y, m - 1, d).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    // Report Archive — printing while a past term is browsed prints that term.
    if (reportArchiveSelection) {
        return reportArchiveSelection.label;
    }
    return 'Current filtered results';
}

// Printed "Maintenance Reports Summary" — drawn by the shared, branded
// SfmsPrint layout (assets/js/sfms-print.js: PHILCST letterhead + logo,
// coupon-bond landscape fit, repeated header, page numbers, sign-off), the
// same one the Dispatches printout uses. This function only supplies the
// report-specific title, summary counts, columns and rows.
const PRINT_ROLE_LABELS = {
    super_admin: 'Administrator',
    maintenance_admin: 'Head Maintenance',
    maintenance_staff: 'Maintenance Staff'
};

const PRINT_STATUS_TONES = {
    submitted: 'blue', draft: 'blue', assigned: 'amber', in_progress: 'violet',
    completed: 'green', closed: 'green', cancelled: 'red'
};

function renderPrintWindow(printableReports, periodLabel = 'Current filtered results') {
    const norm = (value) => String(value || '').trim().toLowerCase();
    const statusOf = (r) => norm(r.status).replace(/\s+/g, '_') || 'submitted';
    const priorityOf = (r) => (norm(r.priority) === 'urgent' ? 'critical' : norm(r.priority));
    const titleCase = (s) => s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

    const rowsHtml = printableReports.map((report, index) => {
        const priorityKey = priorityOf(report);
        const statusKey = statusOf(report);
        return `
            <tr>
                <td class="c-no">${index + 1}</td>
                <td class="c-key">#${escapeHtml(String(report.report_id || ''))}</td>
                <td class="c-strong">${escapeHtml(report.title || '—')}</td>
                <td>${escapeHtml(getReportTypeLabel(report))}</td>
                <td class="text-${escapeHtml(priorityKey || 'none')}">${escapeHtml(priorityKey ? titleCase(priorityKey) : 'N/A')}</td>
                <td>${SfmsPrint.pill(titleCase(statusKey), PRINT_STATUS_TONES[statusKey] || 'gray')}</td>
                <td>${escapeHtml(report.location || '—')}</td>
                <td>${escapeHtml(report.creator_name || 'Unknown')}</td>
                <td class="c-nowrap">${escapeHtml(formatDate(report.created_at))}</td>
            </tr>
        `;
    }).join('');

    // Summary strip — counted from exactly the rows printed.
    const count = (predicate) => printableReports.filter(predicate).length;

    closePrintReportModal();

    const opened = SfmsPrint.open({
        title: 'Maintenance Reports Summary',
        subtitle: periodLabel,
        preparedBy: CURRENT_USER_NAME,
        preparedRole: PRINT_ROLE_LABELS[CURRENT_USER_ROLE] || '',
        recordLabel: 'Records',
        recordCount: printableReports.length,
        stats: [
            { label: 'Total Reports', value: printableReports.length, tone: 'purple' },
            { label: 'Submitted / Assigned', value: count((r) => ['submitted', 'assigned', 'draft'].includes(statusOf(r))), tone: 'blue' },
            { label: 'In Progress', value: count((r) => statusOf(r) === 'in_progress'), tone: 'violet' },
            { label: 'Completed', value: count((r) => ['completed', 'closed'].includes(statusOf(r))), tone: 'green' },
            { label: 'Critical / High', value: count((r) => ['critical', 'high'].includes(priorityOf(r))), tone: 'red' }
        ],
        columns: [
            { label: 'No.', width: '4%' },
            { label: 'Report ID', width: '7%' },
            { label: 'Title', width: '16%' },
            { label: 'Type', width: '10%' },
            { label: 'Priority', width: '8%' },
            { label: 'Status', width: '10%' },
            { label: 'Location', width: '18%' },
            { label: 'Created By', width: '16%' },
            { label: 'Date', width: '11%' }
        ],
        rowsHtml
    });

    if (!opened) {
        Components.alert('Unable to open print preview. Please allow pop-ups for this site.', 'warning');
    }
}

async function exportReportsToExcel() {
    const modeEl = document.getElementById('print-filter-mode');
    if (!modeEl) return;
    const mode = modeEl.value;

    let exportReports;

    if (mode === 'semester') {
        const result = await resolveSemesterExportData();
        if (!result) return; // message already shown to the user
        exportReports = result.reports;
    } else {
        const weekEl = document.getElementById('print-week-select');
        const dateEl = document.getElementById('print-date-input');
        const monthEl = document.getElementById('print-month-input');
        const weekValue = weekEl ? weekEl.value : '';
        const dateValue = dateEl ? dateEl.value : '';
        const monthValue = monthEl ? monthEl.value : '';
        exportReports = getPrintableReportsByMode(mode, weekValue, dateValue, monthValue);

        if (!Array.isArray(exportReports) || exportReports.length === 0) {
            Components.alert('No reports available to export.', 'warning');
            return;
        }
    }

    const headers = ['Report ID', 'Title', 'Type', 'Priority', 'Status', 'Location', 'Created By', 'Date'];
    const rows = exportReports.map((report) => [
        String(report.report_id || ''),
        String(report.title || ''),
        getReportTypeLabel(report),
        String((report.priority || 'N/A')).toUpperCase(),
        String((report.status || 'N/A')).replace(/_/g, ' ').toUpperCase(),
        String(report.location || ''),
        String(report.creator_name || 'Unknown'),
        String(formatDate(report.created_at))
    ]);

    const csvEscape = (value) => {
        const safeValue = String(value ?? '');
        return `"${safeValue.replace(/"/g, '""')}"`;
    };

    const csvLines = [headers, ...rows].map((line) => line.map(csvEscape).join(','));
    const csvContent = '\uFEFF' + csvLines.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);

    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const fileName = `maintenance_reports_${yyyy}-${mm}-${dd}.csv`;

    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    closePrintReportModal();
}

// Filter reports
function filterReports() {
    const status = document.getElementById('filter-status').value;
    const resolution = document.getElementById('filter-resolution').value;
    const priority = document.getElementById('filter-priority').value;
    const department = document.getElementById('filter-department')?.value || '';
    const dateFrom = document.getElementById('filter-date-from').value;
    const dateTo = document.getElementById('filter-date-to').value;
    const hasManualDateRange = Boolean(dateFrom || dateTo);
    const filters = {};

    // If 'All Departments' at walang manual date range, huwag magpadala ng date filter (show all reports)
    const deptEl = document.getElementById('filter-department');
    const isAllDepartments = deptEl && (department === '' || department === 'all');
    if (!hasManualDateRange && isAllDepartments) {
        // Do not set date_from/date_to, show all
    } else if (!hasManualDateRange) {
        if (lastMonthOnly) {
            const range = getLastMonthDateRange();
            filters.date_from = formatLocalDate(range.start);
            filters.date_to = formatLocalDate(range.end);
        } else {
            const range = getCurrentMonthDateRange();
            filters.date_from = formatLocalDate(range.start);
            filters.date_to = formatLocalDate(range.end);
        }
    } else {
        if (dateFrom) filters.date_from = dateFrom;
        if (dateTo) filters.date_to = dateTo;
    }

    if (status) filters.status = status;
    if (priority) filters.priority = priority;
    // TASK 99 — Report Type is resolved in SQL (ReportController::index()),
    // never client-side: per_page caps the response, so narrowing an already
    // truncated page here would silently hide matching reports.
    const reportType = document.getElementById('filter-report-type')?.value || '';
    if (reportType) filters.report_type = reportType;
    // Always send department_id so backend knows if 'All Departments' was explicitly chosen
    if (deptEl) filters.department_id = department;

    // Resolution filter is client-side.
    //
    // TASK — only 'unresolved'/'resolved' are actually owned by this
    // dropdown. Special views such as 'pending_tasks' (set by the
    // dashboard's Pending Tasks KPI card via ?status_group=pending_tasks —
    // see the DOMContentLoaded handler below) or 'assigned_to_me' are set
    // BEFORE this function's first call (initializeReportsPage() calls
    // filterReports() on every load), and this line used to unconditionally
    // stomp them back to '' because the Resolution dropdown itself starts
    // empty. That silently discarded the URL's status_group on the very
    // first render, so a card that asked for "Pending" reports rendered
    // every status instead. Only the dropdown's own two values are assigned
    // here now; any other value already in statusGroupFilter survives
    // unless the dropdown is explicitly reset away from unresolved/resolved,
    // which still clears it exactly as before.
    if (resolution === 'unresolved' || resolution === 'resolved') {
        statusGroupFilter = resolution;
    } else if (['unresolved', 'resolved'].includes(statusGroupFilter)) {
        statusGroupFilter = '';
    }

    if (statusGroupFilter && !['unresolved', 'resolved'].includes(statusGroupFilter)) {
        filters.status_group = statusGroupFilter;
    }

    console.log('[DEBUG] Filters sent to backend:', filters);
    selectedWeek = 0;
    currentPage = 1;
    // Returned (not just fired) so the Export modal's Report Type control can
    // await the refreshed result set before the export buttons become usable
    // again — otherwise a fast click could export the previous type's rows.
    return loadReports(filters);
}

// Event listeners
document.getElementById('filter-status').addEventListener('change', filterReports);
document.getElementById('filter-department')?.addEventListener('change', filterReports);
document.getElementById('filter-resolution').addEventListener('change', filterReports);
document.getElementById('filter-report-type')?.addEventListener('change', filterReports);
document.getElementById('filter-priority').addEventListener('change', filterReports);
document.getElementById('filter-date-from').addEventListener('change', () => {
    // Manual date range should take priority over quick last-month mode.
    if (document.getElementById('filter-date-from').value || document.getElementById('filter-date-to').value) {
        lastMonthOnly = false;
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.delete('last_month');
        window.history.replaceState({}, '', nextUrl.toString());
    }
    filterReports();
});
document.getElementById('filter-date-to').addEventListener('change', () => {
    // Manual date range should take priority over quick last-month mode.
    if (document.getElementById('filter-date-from').value || document.getElementById('filter-date-to').value) {
        lastMonthOnly = false;
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.delete('last_month');
        window.history.replaceState({}, '', nextUrl.toString());
    }
    filterReports();
});
// month picker dropdown
const monthPickerBtn = document.getElementById('month-picker-btn');
const monthPickerDropdown = document.getElementById('month-picker-dropdown');

if (monthPickerBtn && monthPickerDropdown) {
    monthPickerBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = monthPickerDropdown.style.display !== 'none';
        monthPickerDropdown.style.display = isOpen ? 'none' : 'block';
    });

    monthPickerDropdown.querySelectorAll('.month-picker-item').forEach((item) => {
        item.addEventListener('mouseenter', () => { item.style.background = 'rgba(148,163,184,0.12)'; });
        item.addEventListener('mouseleave', () => { item.style.background = 'none'; });
        item.addEventListener('click', () => {
            const month = item.getAttribute('data-month');
            if (!month) return;
            const [y, m] = month.split('-');
            const dateFrom = month + '-01';
            const lastDay = new Date(Number(y), Number(m), 0).getDate();
            const dateTo = month + '-' + String(lastDay).padStart(2, '0');
            document.getElementById('filter-date-from').value = dateFrom;
            document.getElementById('filter-date-to').value = dateTo;
            lastMonthOnly = false;
            selectedWeek = 0;
            currentPage = 1;
            if (typeof setArchiveSelection === 'function') setArchiveSelection(null);
            monthPickerDropdown.style.display = 'none';
            filterReports();
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.delete('last_month');
            window.history.replaceState({}, '', nextUrl.toString());

            try {
                localStorage.setItem('sfms:dashboardMonthSelection', JSON.stringify({
                    year: Number(y),
                    month: Number(m)
                }));
            } catch (storageError) {
                console.warn('Unable to persist selected month', storageError);
            }

            // --- Dispatch custom event for dashboard sync ---
            window.dispatchEvent(new CustomEvent('sfms:monthSelected', {
                detail: { year: Number(y), month: Number(m) }
            }));
        });
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.reports-month-dropdown-wrap')) {
            monthPickerDropdown.style.display = 'none';
        }
    });
}

// ── Report Archive ───────────────────────────────────────────────────────
// Past-term browsing by the School Years recorded in "Manage Academic
// Session" (GET /api/academic-sessions). Choosing a term fills the same
// From/To date inputs Browse by Month uses, so the list, the week tabs and
// the printout all follow it with no second filter path. Which reports are
// view-only is decided by the API (report.archive), never here.
let reportArchiveSelection = null; // { label } while a past term is shown

function formatArchiveDate(dateKey) {
    const [y, m, d] = String(dateKey).split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function dayBefore(dateKey) {
    const [y, m, d] = String(dateKey).split('-').map(Number);
    return formatLocalDate(new Date(y, m - 1, d - 1));
}

function setArchiveSelection(selection) {
    reportArchiveSelection = selection;
    const banner = document.getElementById('report-archive-banner');
    const label = document.getElementById('report-archive-banner-label');
    if (banner) banner.hidden = !selection;
    if (label) label.textContent = selection ? selection.label : '';
    document.getElementById('archive-picker-btn')?.classList.toggle('is-active', Boolean(selection));

    // Highlight the chosen term inside the menu (check mark + tint).
    document.querySelectorAll('#archive-picker-list .ra-item').forEach((item) => {
        const isSelected = Boolean(selection) && item.dataset.from === selection.from && item.dataset.to === selection.to;
        item.classList.toggle('is-selected', isSelected);
        item.setAttribute('aria-checked', String(isSelected));
    });
}

function closeArchiveMenu() {
    const menu = document.getElementById('archive-picker-dropdown');
    if (menu) menu.hidden = true;
    document.getElementById('archive-picker-btn')?.setAttribute('aria-expanded', 'false');
}

function applyArchiveTerm(from, to, label) {
    document.getElementById('filter-date-from').value = from;
    document.getElementById('filter-date-to').value = to;
    lastMonthOnly = false;
    selectedWeek = 0;
    currentPage = 1;
    setArchiveSelection({ label, from, to });
    closeArchiveMenu();
    filterReports();
}

// One menu row: icon · title + date range · status chip / check mark.
// Upcoming terms are listed for context but disabled — they cannot hold
// reports yet.
function archiveMenuItemHtml({ from, to, label, title, dates, icon, chip, disabled }) {
    const iconSvg = window.UIIcons ? window.UIIcons.svg(icon, { size: 16 }) : '';
    const checkSvg = window.UIIcons ? window.UIIcons.svg('check', { size: 16 }) : '';
    const chipHtml = chip ? `<span class="ra-chip ra-chip-${chip.tone}">${escapeHtml(chip.text)}</span>` : '';
    return `
        <button type="button" class="ra-item" role="menuitemradio" aria-checked="false"
                data-from="${escapeHtml(from)}" data-to="${escapeHtml(to)}" data-label="${escapeHtml(label)}"
                ${disabled ? 'disabled title="This term has not started yet."' : ''}>
            <span class="ra-item-icon">${iconSvg}</span>
            <span class="ra-item-body">
                <span class="ra-item-title">${escapeHtml(title)}</span>
                <span class="ra-item-dates">${escapeHtml(dates)}</span>
            </span>
            ${chipHtml}
            <span class="ra-item-check">${checkSvg}</span>
        </button>`;
}

async function loadArchiveMenu() {
    const list = document.getElementById('archive-picker-list');
    if (!list) return;

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/academic-sessions'), { credentials: 'include' });
        const payload = await res.json();
        if (!payload.success || !payload.data) throw new Error(payload.message || 'Unable to load school years');

        const { school_years: schoolYears = [], earliest_start: earliestStart, has_earlier_reports: hasEarlier } = payload.data;
        const today = formatLocalDate(new Date());
        const range = (from, to) => `${formatArchiveDate(from)} – ${formatArchiveDate(to)}`;
        const termChip = (from, to) => {
            if (from > today) return { text: 'Upcoming', tone: 'upcoming' };
            if (from <= today && today <= to) return { text: 'Current', tone: 'current' };
            return null;
        };

        let html = '';
        schoolYears.forEach((sy) => {
            const isCurrentYear = sy.start <= today && today <= sy.end;
            html += `
                <section class="ra-group">
                    <div class="ra-group-head">
                        <span class="ra-group-name">${escapeHtml(sy.label)}</span>
                        ${isCurrentYear ? '<span class="ra-chip ra-chip-current">Current school year</span>' : ''}
                    </div>`;
            html += archiveMenuItemHtml({
                from: sy.start, to: sy.end, label: `Whole ${sy.label}`,
                title: 'Whole school year', dates: range(sy.start, sy.end), icon: 'calendar',
                disabled: sy.start > today
            });
            (sy.semesters || []).forEach((sem) => {
                const chip = termChip(sem.start, sem.end);
                html += archiveMenuItemHtml({
                    from: sem.start, to: sem.end, label: `${sem.semester} · ${sy.label}`,
                    title: sem.semester, dates: range(sem.start, sem.end), icon: 'book',
                    chip, disabled: chip && chip.tone === 'upcoming'
                });
            });
            html += '</section>';
        });

        if (hasEarlier && earliestStart) {
            const firstLabel = schoolYears.length ? schoolYears[schoolYears.length - 1].label : 'the first recorded school year';
            html += `
                <section class="ra-group">
                    <div class="ra-group-head"><span class="ra-group-name">Before recorded school years</span></div>
                    ${archiveMenuItemHtml({
                        from: '2000-01-01', to: dayBefore(earliestStart), label: `Earlier records (before ${firstLabel})`,
                        title: 'Earlier records', dates: `Filed before ${formatArchiveDate(earliestStart)}`, icon: 'clock'
                    })}
                </section>`;
        }

        list.innerHTML = html || '<div class="ra-empty">No school years recorded yet. They are added from Manage Academic Session.</div>';
        setArchiveSelection(reportArchiveSelection); // re-apply the highlight
    } catch (error) {
        console.error('Unable to load the report archive menu', error);
        list.innerHTML = '<div class="ra-empty">Unable to load school years right now.</div>';
    }
}

(function initReportArchive() {
    const button = document.getElementById('archive-picker-btn');
    const menu = document.getElementById('archive-picker-dropdown');
    const list = document.getElementById('archive-picker-list');
    if (!button || !menu || !list) return;

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        const willOpen = menu.hidden;
        menu.hidden = !willOpen;
        button.setAttribute('aria-expanded', String(willOpen));
    });

    list.addEventListener('click', (event) => {
        const choice = event.target.closest('.ra-item');
        if (!choice || choice.disabled) return;
        applyArchiveTerm(choice.dataset.from, choice.dataset.to, choice.dataset.label);
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.reports-archive-dropdown-wrap')) closeArchiveMenu();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeArchiveMenu();
    });

    // "Back to current reports" is exactly Clear Date (current month).
    document.getElementById('report-archive-exit')?.addEventListener('click', () => {
        document.getElementById('clear-date-filters').click();
    });

    // Typing a date by hand leaves the archive term, so drop its banner.
    ['filter-date-from', 'filter-date-to'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', () => setArchiveSelection(null));
    });

    loadArchiveMenu();
})();

document.getElementById('clear-date-filters').addEventListener('click', () => {
    const range = getCurrentMonthDateRange();
    setArchiveSelection(null);
    document.getElementById('filter-date-from').value = formatLocalDate(range.start);
    document.getElementById('filter-date-to').value = formatLocalDate(range.end);
    lastMonthOnly = false;
    selectedWeek = 0;
    currentPage = 1;

    const nextUrl = new URL(window.location.href);
    nextUrl.searchParams.delete('last_month');
    window.history.replaceState({}, '', nextUrl.toString());

    filterReports();
});

document.getElementById('pagination-container').addEventListener('click', (event) => {
    const pageButton = event.target.closest('[data-page]');
    if (!pageButton || pageButton.hasAttribute('disabled')) return;

    const nextPage = Number(pageButton.dataset.page);
    if (Number.isNaN(nextPage) || nextPage < 1) return;

    currentPage = nextPage;
    renderReportsView();
});

document.getElementById('pagination-container').addEventListener('change', (event) => {
    if (event.target.id !== 'rows-per-page-select') return;

    const nextRows = Number(event.target.value);
    if (Number.isNaN(nextRows) || nextRows < 1) return;

    rowsPerPage = nextRows;
    currentPage = 1;
    renderReportsView();
});

document.addEventListener('click', (event) => {
    const editButton = event.target.closest('.js-edit-report');
    if (editButton) {
        const reportId = Number(editButton.getAttribute('data-report-id'));
        if (!Number.isNaN(reportId) && reportId > 0) {
            openEditReportModal(reportId);
        }
        return;
    }

    const modal = document.getElementById('report-edit-modal');
    if (modal && event.target === modal) {
        closeEditModal();
    }

    const printModal = document.getElementById('print-report-modal');
    if (printModal && event.target === printModal) {
        closePrintReportModal();
    }
});

document.getElementById('report-edit-form').addEventListener('submit', saveEditedReport);
document.getElementById('report-edit-close').addEventListener('click', closeEditModal);
document.getElementById('report-edit-cancel-btn').addEventListener('click', closeEditModal);

const printReportBtn = document.getElementById('print-report-btn');
if (printReportBtn) {
    printReportBtn.addEventListener('click', openPrintReportModal);
}

const printReportCloseBtn = document.getElementById('print-report-close');
if (printReportCloseBtn) {
    printReportCloseBtn.addEventListener('click', closePrintReportModal);
}

const printReportCancelBtn = document.getElementById('print-modal-cancel');
if (printReportCancelBtn) {
    printReportCancelBtn.addEventListener('click', closePrintReportModal);
}

const printFilterModeEl = document.getElementById('print-filter-mode');
if (printFilterModeEl) {
    printFilterModeEl.addEventListener('change', togglePrintFilterFields);
}

// TASK 99 — picking a Report Type inside Export Reports drives the page filter
// through the normal filterReports() path (one server-side implementation), and
// the export buttons stay disabled until the refreshed, authorization-scoped
// result set has actually arrived.
const printFilterReportTypeEl = document.getElementById('print-filter-report-type');
if (printFilterReportTypeEl) {
    printFilterReportTypeEl.addEventListener('change', async () => {
        const pageTypeEl = document.getElementById('filter-report-type');
        if (!pageTypeEl) return;

        pageTypeEl.value = printFilterReportTypeEl.value;
        setPrintExportBusy(true);
        try {
            await filterReports();
        } finally {
            setPrintExportBusy(false);
        }
    });
}

const printGenerateBtn = document.getElementById('print-modal-generate');
if (printGenerateBtn) {
    printGenerateBtn.addEventListener('click', printSummaryReport);
}

const exportPdfBtn = document.getElementById('print-modal-export-pdf');
if (exportPdfBtn) {
    exportPdfBtn.addEventListener('click', printSummaryReport);
}

const exportExcelBtn = document.getElementById('print-modal-export-excel');
if (exportExcelBtn) {
    exportExcelBtn.addEventListener('click', exportReportsToExcel);
}

document.getElementById('week-pagination').addEventListener('click', (event) => {
    const button = event.target.closest('.week-page-btn');
    if (!button || button.hasAttribute('disabled')) return;

    const nextWeek = Number(button.dataset.week);
    if (Number.isNaN(nextWeek)) return;

    selectedWeek = nextWeek;
    currentPage = 1;
    renderReportsView();
});

// Initialize - ensure API is available
function initializeReportsPage() {
    // Match maintenance dashboard behavior: always use server-side filters.
    filterReports();
}

document.addEventListener('DOMContentLoaded', async () => {
    const urlParams = new URLSearchParams(window.location.search);

    const statusParam = (urlParams.get('status') || '').toLowerCase();
    const priorityParam = (urlParams.get('priority') || '').toLowerCase();
    const dateScopeParam = (urlParams.get('date_scope') || '').toLowerCase();
    const statusGroupParam = (urlParams.get('status_group') || '').toLowerCase();

    if (statusGroupParam === 'pending_tasks') {
        statusGroupFilter = 'pending_tasks';
    } else if (statusGroupParam === 'assigned_to_me') {
        statusGroupFilter = 'assigned_to_me';
    }

    if (urlParams.get('last_month') === '1') {
        lastMonthOnly = true;
        const range = getLastMonthDateRange();
        document.getElementById('filter-date-from').value = formatLocalDate(range.start);
        document.getElementById('filter-date-to').value = formatLocalDate(range.end);
        // TASK 8 BUG FIX. The three lines that used to live here toggled the
        // active styling of a "Last Month Report" header button
        // (<a id="last-month-report-link">). That button, and its own click
        // listener, were removed from this page's markup in commit 6bbd38a
        // ("Phase 4"), but this styling code was left behind — so
        // `getElementById('last-month-report-link')` returned null and
        // `btn.classList.remove(...)` threw
        // `TypeError: Cannot read properties of null (reading 'classList')`.
        // Because that threw inside this DOMContentLoaded handler and BEFORE
        // initializeReportsPage() below, the whole handler aborted and the
        // page never initialised: empty table, no week pagination, inert UI.
        // The parameter itself is NOT legacy — super-admin-dashboard.php
        // still links to reports.php?last_month=1 — so the last-month state
        // is preserved. Only the dead styling of a deleted element is gone;
        // `lastMonthOnly` and the date range above are what actually drive
        // the filter, and they are untouched.
    } else if (dateScopeParam === 'today') {
        const today = formatLocalDate(new Date());
        document.getElementById('filter-date-from').value = today;
        document.getElementById('filter-date-to').value = today;
    } else if (dateScopeParam === 'semester') {
        // TASK — Total Reports / Pending Tasks / In Progress / Completed on
        // the dashboard are computed by DashboardController::stats() scoped
        // to the ACTIVE SEMESTER (created_at >= semester_started_at). Those 4
        // KPI cards link here with ?date_scope=semester so the date range
        // shown matches the number just clicked, instead of silently falling
        // into the unrelated "current calendar month" default below — a
        // different window that produced a mismatched, confusing report
        // count (e.g. Total Reports = 9 on the dashboard but a handful of
        // rows here because most of them were created outside this month).
        //
        // Reuses the exact same lookup already built for the "Entire
        // Semester" export scope further up this file
        // (fetchSchoolSettings() / getActiveSemesterRange()) — one source of
        // truth for "what is the active semester's date range", not a third
        // reimplementation of that logic.
        let semesterRange = null;
        try {
            const settings = await fetchSchoolSettings();
            semesterRange = getActiveSemesterRange(settings);
        } catch (error) {
            console.error('Unable to resolve active semester for date_scope=semester', error);
        }

        if (semesterRange) {
            document.getElementById('filter-date-from').value = semesterRange.start;
            document.getElementById('filter-date-to').value = semesterRange.end;
        } else {
            // No semester is currently active — the dashboard itself shows
            // "No Active Semester" (a dash, not a number) for these 4 cards
            // in that case, so there is no real semester window to match
            // here either. Fall back to the same current-month default a
            // plain page load uses, rather than inventing a date range.
            const range = getCurrentMonthDateRange();
            document.getElementById('filter-date-from').value = formatLocalDate(range.start);
            document.getElementById('filter-date-to').value = formatLocalDate(range.end);
        }
    } else {
        // DEFAULT SCOPE = CURRENT MONTH (first of this month → today).
        //
        // HISTORY, so this does not get "fixed" back and forth again:
        // this branch originally pre-filled the two date inputs with
        // getCurrentMonthDateRange(). ISS-02 replaced that with empty strings
        // so that a plain visit meant all-time, on the reasoning that the page
        // is called "All Reports". That produced a worse problem in practice —
        // opening All Reports showed an "All dates" chip and listed months-old
        // April/May reports instead of the current month's work — and it was
        // reported as a regression. The current-month pre-fill is the intended
        // product behaviour and is restored here.
        //
        // "All Reports" names the report COLLECTION (the module), it is not an
        // instruction to drop the date filter.
        //
        // The three other scopes stay distinct, exactly as the architecture
        // already separates them:
        //   - ?last_month=1     → previous month   (branch above; super-admin-
        //                         dashboard.php still links to it)
        //   - ?date_scope=today → today            (branch above)
        //   - user-typed range  → that range       (inputs + filterReports)
        //   - plain load        → current month    (here)
        // "Clear Date" independently resets to the current month, so a plain
        // load and a cleared filter now agree, which is the point.
        //
        // The ISS-02 scope indicator (#reports-scope-notice) that once named
        // this range in words above the table has since been removed as
        // redundant display text — the two date inputs seeded immediately
        // below are now the only place the active range is shown, and they
        // are populated on every load, so it is still stated on screen.
        const range = getCurrentMonthDateRange();
        document.getElementById('filter-date-from').value = formatLocalDate(range.start);
        document.getElementById('filter-date-to').value = formatLocalDate(range.end);
    }

    if (statusParam) {
        document.getElementById('filter-status').value = statusParam;
    }

    if (priorityParam) {
        document.getElementById('filter-priority').value = priorityParam;
    }

    console.log('📄 DOM Content Loaded - starting initialization');
    selectedWeek = 0;
    initializeReportsPage();
});

// after loading reports, check query param for new_id to highlight
function getQueryParam(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name);
}

function highlightNewReport(id) {
    if (!id) return;
    const row = document.querySelector(`#reports-container table tr[data-id='${id}']`);
    if (row) {
        row.style.transition = 'background-color 0.5s';
        row.style.backgroundColor = '#d4edda';
        setTimeout(() => { row.style.backgroundColor = ''; }, 3000);
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// modify displayReports to add data-id attributes
// NOTE: we'll insert additional logic inside displayReports function above - patch separately

</script>
