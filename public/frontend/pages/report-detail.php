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

$pageTitle = 'Report Details - SFMS';
include __DIR__ . '/../includes/header.php';

$reportId = $_GET['id'] ?? 0;
$user = $_SESSION['user'] ?? $_SESSION['auth_user'];
$isSuperAdmin = ($user['role'] ?? '') === 'super_admin';
// TASK 9 — Role + Department Based Authorization: report.department_id is
// only known once the report loads (client-side fetch), so the actual
// hide/disable decision happens in JS (see CURRENT_USER_DEPARTMENT_ID /
// applyDepartmentAuthorizationUI() below). This mirrors the backend's
// canModifyReport() rule: Administrator is exempt, everyone else needs
// user.department_id === report.department_id.
$currentUserDepartmentId = $user['department_id'] ?? null;
?>

<main class="container report-view-page">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div style="flex: 1;">
                <h2 id="report-title">Loading...</h2>
                <p class="text-muted mb-0">Report Details</p>
            </div>
            <a href="/School_Facility_Maintenance_System/frontend/pages/reports.php" class="btn btn-secondary btn-sm" style="height: fit-content; margin-top: 0;">
                <?php echo ui_icon('arrow-left'); ?> Back to Reports
            </a>
        </div>
        
        <div class="card-body" id="report-details">
            <div class="loading">Loading report details...</div>
        </div>
    </div>
    
    <?php if ($isSuperAdmin): ?>
    <div class="card" style="margin-top: 20px;">
        <div class="card-body" style="display: flex; justify-content: flex-end;">
            <a id="assign-report-btn"
               href="/School_Facility_Maintenance_System/frontend/pages/maintenance-report-detail.php?id=<?php echo intval($reportId); ?>&back=all_reports"
               class="btn btn-primary">
                Assign Report
            </a>
        </div>
    </div>
    <?php elseif (in_array($user['role'], ['maintenance_admin', 'maintenance_staff'])): ?>
    <div class="card" style="margin-top: 20px;" id="update-status-card">
        <div class="card-header">
            <h2>Update Report Status</h2>
        </div>
        <div class="card-body">
            <div id="department-restricted-notice" class="alert alert-info" style="display:none;">
                This report belongs to a different department. You have view-only access and cannot modify it.
            </div>
            <div id="update-alert"></div>
            <form id="update-form">
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="submitted">Submitted</option>
                        <option value="assigned">Assigned</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" id="update-btn">
                    Update Status
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</main>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/report-detail.inline.css">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1">

<script>
const reportId = <?php echo intval($reportId); ?>;
// TASK 9 — Role + Department Based Authorization (frontend enforcement).
// Administrator is exempt; every other role must match departments with the
// report before being allowed to submit a modification. The backend
// (ReportAuthorizationService::canModifyReport(), enforced in
// ReportController::update()) is the real authority — this is defense in
// depth only, never relied on alone.
const IS_SUPER_ADMIN = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
const CURRENT_USER_DEPARTMENT_ID = <?php echo json_encode($currentUserDepartmentId); ?>;

function canModifyReportClientSide(report) {
    if (IS_SUPER_ADMIN) return true;
    const userDept = CURRENT_USER_DEPARTMENT_ID === null || CURRENT_USER_DEPARTMENT_ID === undefined ? null : Number(CURRENT_USER_DEPARTMENT_ID);
    const reportDept = (report && (report.department_id === null || report.department_id === undefined)) ? null : Number(report.department_id);
    return userDept === reportDept;
}

function applyDepartmentAuthorizationUI(report) {
    const form = document.getElementById('update-form');
    const updateBtn = document.getElementById('update-btn');
    const notice = document.getElementById('department-restricted-notice');
    if (!form || !updateBtn) return;

    const allowed = canModifyReportClientSide(report);
    updateBtn.disabled = !allowed;
    form.querySelectorAll('select, input, textarea, button').forEach((el) => {
        if (el !== updateBtn) el.disabled = !allowed;
    });
    if (notice) notice.style.display = allowed ? 'none' : 'block';
}

// Load report details
async function loadReport() {
    if (!reportId) {
        document.getElementById('report-details').innerHTML = 
            '<p class="text-danger">Invalid report ID</p>';
        return;
    }
    
    try {
        const response = await API.getReport(reportId);
        const report = response.data.report;
        
        // Update title
        document.getElementById('report-title').textContent = `#${report.report_id} - ${report.title}`;
        
        // Build details HTML — enterprise info-card + timeline layout.
        // Same report fields as before; only the presentation markup changed.
        let html = '<div class="report-detail-grid">';

        // Basic Info card
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">Basic Information</h3>';
        html += '<dl class="report-info-list">';
        html += `<div class="report-info-row"><dt>Report ID</dt><dd>#${report.report_id}</dd></div>`;
        html += `<div class="report-info-row"><dt>Title</dt><dd><strong>${UI.escapeHtml(report.title)}</strong></dd></div>`;
        html += `<div class="report-info-row"><dt>Location</dt><dd>${UI.escapeHtml(report.location)}</dd></div>`;
        html += `<div class="report-info-row"><dt>Priority</dt><dd>${UI.getPriorityBadge(report.priority)}</dd></div>`;
        html += `<div class="report-info-row"><dt>Status</dt><dd>${UI.getStatusBadge(report.status)}</dd></div>`;
        html += `<div class="report-info-row"><dt>Department</dt><dd>${UI.escapeHtml(report.department_name) || 'Not assigned'}</dd></div>`;
        html += '</dl>';
        html += '</section>';

        // Description card
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">Description</h3>';
        html += `<div class="report-description-box">${UI.escapeHtml(report.description) || 'No description provided'}</div>`;
        html += '</section>';

        // Attachments card — the current reports API does not return attachment/file
        // data yet, so this shows an honest empty state rather than fabricating files.
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">Attachments</h3>';
        html += `<div class="report-attachments-empty">${(report.attachments && report.attachments.length) ? '' : 'No attachments have been added to this report yet.'}</div>`;
        if (report.attachments && report.attachments.length) {
            html += '<ul class="report-attachments-list">';
            report.attachments.forEach((att) => {
                const attName = (att && (att.name || att.file_name)) || 'Attachment';
                const attUrl = (att && (att.url || att.file_url)) || '#';
                html += `<li><a href="${UI.escapeHtml(attUrl)}" target="_blank" rel="noopener">${UI.escapeHtml(attName)}</a></li>`;
            });
            html += '</ul>';
        }
        html += '</section>';

        // Assigned Personnel card
        html += '<section class="report-info-card">';
        html += '<h3 class="report-info-card-title">Assigned Personnel</h3>';
        html += '<dl class="report-info-list">';
        html += `<div class="report-info-row"><dt>Created By</dt><dd>${UI.escapeHtml(report.creator_name)} ${report.creator_email ? `(${UI.escapeHtml(report.creator_email)})` : ''}</dd></div>`;
        html += `<div class="report-info-row"><dt>Assigned To</dt><dd>${report.assigned_name ? `${UI.escapeHtml(report.assigned_name)} ${report.assigned_email ? `(${UI.escapeHtml(report.assigned_email)})` : ''}` : '<span class="report-unassigned">Not assigned yet</span>'}</dd></div>`;
        html += '</dl>';
        html += '</section>';

        // Timeline card (Created / Updated / Due / Completed act as the report's status history)
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

        html += '</div>';

        document.getElementById('report-details').innerHTML = html;
        
        // Set current status in form
        if (document.getElementById('status')) {
            document.getElementById('status').value = report.status;
        }

        // TASK 9 — hide/disable modification controls once we know the
        // report's actual department_id (Administrator is exempt).
        applyDepartmentAuthorizationUI(report);

    } catch (error) {
        document.getElementById('report-details').innerHTML = 
            '<p class="text-danger">Failed to load report details</p>';
        console.error('Failed to load report:', error);
    }
}

// Handle status update
<?php if (in_array($user['role'], ['maintenance_admin', 'maintenance_staff'])): ?>
document.getElementById('update-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const updateBtn = document.getElementById('update-btn');
    const alertDiv = document.getElementById('update-alert');
    const newStatus = document.getElementById('status').value;

    // TASK 9 — frontend defense-in-depth; the backend still rejects this
    // with HTTP 403 regardless (see ReportController::update()).
    if (updateBtn.disabled) {
        alertDiv.innerHTML = '<div class="alert alert-danger">This report belongs to a different department. You do not have permission to modify it.</div>';
        return;
    }

    const originalText = updateBtn.innerHTML;
    updateBtn.innerHTML = 'Updating...';
    updateBtn.disabled = true;
    alertDiv.innerHTML = '';
    
    try {
        await API.updateReport({
            report_id: reportId,
            status: newStatus
        });
        
        alertDiv.innerHTML = '<div class="alert alert-success">Status updated successfully!</div>';
        
        // Reload report details
        setTimeout(() => {
            loadReport();
            alertDiv.innerHTML = '';
        }, 1500);
        
    } catch (error) {
        alertDiv.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
    } finally {
        updateBtn.innerHTML = originalText;
        updateBtn.disabled = false;
    }
});
<?php endif; ?>

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    loadReport();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Ensure API and Session are defined globally - CRITICAL
window.API = window.API || {
    async getReport(id) {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/reports/${id}`), {
            credentials: 'include'
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message);
        return data;
    },
    async updateReport(data) {
        const { report_id, ...fields } = data;
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/reports/${report_id}`), {
            method: 'PATCH',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(fields)
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.message);
        return result;
    },
    async logout() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/auth/logout'), {
            method: 'POST',
            credentials: 'include'
        });
        const data = await response.json();
        return data;
    }
};

window.Session = window.Session || {
    get(key) { 
        const v = localStorage.getItem(key);
        return v ? JSON.parse(v) : null;
    },
    set(key, value) { localStorage.setItem(key, JSON.stringify(value)); },
    clear() { localStorage.clear(); }
};
</script>


