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

if (!isset($_SESSION['user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$user = $_SESSION['user'];
$currentRole = strtolower(trim((string)($user['role'] ?? '')));
// 2026-09-30 — Activity Logs is now Administrator-only; see activity-log.php
// for the matching note.
if ($currentRole !== 'super_admin') {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

$pageTitle = 'Activity Log Detail - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container" style="margin-top:16px;max-width:980px;">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2 id="activity-log-detail-title">Activity Log Detail</h2>
                <p class="text-muted mb-0" id="activity-log-detail-subtitle">Loading log entry...</p>
            </div>
            <a href="<?php echo htmlspecialchars(public_url('/activity-logs')); ?>" class="btn btn-secondary">Back to Activity Logs</a>
        </div>
        <div class="card-body" id="activity-log-detail-container">
            <div class="ui-empty-state"><strong>Loading log entry...</strong></div>
        </div>
    </div>
</main>

<script>
const ACTIVITY_LOGS_DETAIL_API_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/api/activity-logs') : '/api/activity-logs';

function getActivityLogId() {
    const id = Number(new URLSearchParams(window.location.search).get('id') || 0);
    return id > 0 ? id : 0;
}

function activityDetailNotify(message, type = 'danger') {
    // UI_BROWSER_DIALOG_REPLACEMENT — Components/UI are always loaded (see
    // includes/footer.php), so this always goes through the reusable
    // in-app modal; no window.alert() fallback.
    Components.alert(message, type);
}

function escapeActivityDetailHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatActivityDetailLabel(value) {
    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadActivityLogDetail() {
    const id = getActivityLogId();
    if (!id) {
        activityDetailNotify('Invalid activity log ID.', 'warning');
        window.location.href = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/activity-logs') : '/activity-logs';
        return;
    }

    try {
        const { response, data } = await Components.fetchJson(`${ACTIVITY_LOGS_DETAIL_API_BASE}/${id}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to load activity log detail.');
        }

        const log = data.data?.log;
        if (!log) {
            throw new Error('Log payload is invalid.');
        }

        document.getElementById('activity-log-detail-title').textContent = formatActivityDetailLabel(log.action || 'Activity Log');
        document.getElementById('activity-log-detail-subtitle').textContent = `${formatActivityDetailLabel(log.module || 'system')} | ${log.created_at ? new Date(log.created_at).toLocaleString() : 'N/A'}`;

        const meta = log.meta_json && typeof log.meta_json === 'object'
            ? `<pre style="margin:0;white-space:pre-wrap;">${escapeActivityDetailHtml(JSON.stringify(log.meta_json, null, 2))}</pre>`
            : '<span class="text-muted">No metadata recorded.</span>';

        document.getElementById('activity-log-detail-container').innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><strong>Action:</strong> ${escapeActivityDetailHtml(formatActivityDetailLabel(log.action))}</div>
                <div><strong>Module:</strong> ${escapeActivityDetailHtml(formatActivityDetailLabel(log.module || 'system'))}</div>
                <div><strong>User:</strong> ${escapeActivityDetailHtml(log.user?.full_name || (log.user_id ? `User #${log.user_id}` : 'System'))}</div>
                <div><strong>User Role:</strong> ${escapeActivityDetailHtml(formatActivityDetailLabel(log.user_role || 'system'))}</div>
                <div><strong>Entity Type:</strong> ${escapeActivityDetailHtml(formatActivityDetailLabel(log.entity_type || 'n/a'))}</div>
                <div><strong>Entity ID:</strong> ${escapeActivityDetailHtml(log.entity_id || 'N/A')}</div>
                <div><strong>IP Address:</strong> ${escapeActivityDetailHtml(log.ip_address || 'N/A')}</div>
                <div><strong>Timestamp:</strong> ${escapeActivityDetailHtml(log.created_at ? new Date(log.created_at).toLocaleString() : 'N/A')}</div>
            </div>
            <div style="margin-top:14px;">
                <strong>Description:</strong>
                <div style="margin-top:6px;">${escapeActivityDetailHtml(log.details || 'No description recorded.')}</div>
            </div>
            <div style="margin-top:14px;">
                <strong>Browser / User Agent:</strong>
                <div style="margin-top:6px;word-break:break-word;">${escapeActivityDetailHtml(log.user_agent || 'N/A')}</div>
            </div>
            <div style="margin-top:14px;">
                <strong>Metadata:</strong>
                <div style="margin-top:6px;">${meta}</div>
            </div>
        `;
    } catch (error) {
        document.getElementById('activity-log-detail-container').innerHTML = '<div class="ui-empty-state"><strong>Failed to load activity log detail.</strong></div>';
        activityDetailNotify(error.message || 'Unable to load activity log detail.');
    }
}

document.addEventListener('DOMContentLoaded', loadActivityLogDetail);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
