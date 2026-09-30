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
$pageTitle = 'Notifications - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container maintenance-admin-dashboard-page inventory-page">
    <div class="card">
        <div class="card-body">
            <div id="notif-alert"></div>
            <div id="notifications-list"><div class="loading">Loading notifications...</div></div>
        </div>
    </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
function showNotifAlert(message, type = 'info') {
    const box = document.getElementById('notif-alert');
    if (!box) return;
    box.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}

async function loadNotifications() {
    const list = document.getElementById('notifications-list');
    if (!list) return;

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/notifications') + '?limit=100');
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to load notifications');

        const notifications = (data.data && data.data.notifications) || [];
        if (!notifications.length) {
            list.innerHTML = '<p class="text-muted">No notifications found.</p>';
            return;
        }

        let html = '<div style="display:grid; gap:10px;">';
        notifications.forEach((n) => {
            const unread = Number(n.is_read) === 0;
            html += `<div class="card" style="border:1px solid var(--border); ${unread ? 'box-shadow: inset 0 0 0 1px rgba(99,102,241,.35);' : ''}">`;
            html += `<div class="card-body" style="padding:12px;">`;
            html += `<div style="display:flex; justify-content:space-between; gap:10px; align-items:start;">`;
            html += `<div><div style="font-weight:700;">${UI.escapeHtml(n.title) || 'Notification'}</div>`;
            html += `<div class="text-muted" style="margin-top:4px;">${UI.escapeHtml(n.message)}</div>`;
            html += `<div class="text-muted" style="margin-top:6px; font-size:12px;">${UI.escapeHtml(n.created_at)}</div></div>`;
            html += `<div style="display:flex; gap:6px; flex-wrap:wrap;">`;
            if (unread) {
                html += `<button class="btn btn-sm btn-secondary" onclick="markNotificationRead(${Number(n.notification_id)})">Mark Read</button>`;
            }
            html += `<button class="btn btn-sm btn-secondary" onclick="deleteNotification(${Number(n.notification_id)})">Delete</button>`;
            html += `</div></div></div></div>`;
        });
        html += '</div>';
        list.innerHTML = html;
    } catch (error) {
        list.innerHTML = '<p class="text-danger">Failed to load notifications.</p>';
        showNotifAlert(error.message, 'danger');
    }
}

async function markNotificationRead(notificationId) {
    try {
        const res = await fetch(
            window.SFMS_PUBLIC_URL(`/api/notifications/${notificationId}/read`),
            { method: 'POST' }
        );
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to mark read');
        await loadNotifications();
    } catch (error) {
        showNotifAlert(error.message, 'danger');
    }
}

async function deleteNotification(notificationId) {
    try {
        const res = await fetch(window.SFMS_PUBLIC_URL(`/api/notifications/${notificationId}`), {
            method: 'DELETE',
            credentials: 'include'
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to delete');
        await loadNotifications();
    } catch (error) {
        showNotifAlert(error.message, 'danger');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadNotifications();
    const markAllBtn = document.getElementById('mark-all-read-btn');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', async () => {
            try {
                const res = await fetch(window.SFMS_PUBLIC_URL('/api/notifications/read-all'), { method: 'POST' });
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Failed to mark all read');
                showNotifAlert('All notifications marked as read.', 'success');
                await loadNotifications();
            } catch (error) {
                showNotifAlert(error.message, 'danger');
            }
        });
    }
});
</script>

</body>
</html>
