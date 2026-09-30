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
// 2026-09-30 — Activity Logs is now Administrator-only; Head Maintenance
// (maintenance_admin) lost access here and in activity-log-detail.php,
// per the sidebar link removal in includes/sidebar.php ($showAuditLogs)
// and the matching API route guard in routes/web.php.
if ($currentRole !== 'super_admin') {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

$pageTitle = 'Activity Logs - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container activity-log-page" style="margin-top:16px;">
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header">
            <div>
                <h2>Activity Logs</h2>
                <p class="text-muted mb-0">Audit user actions across authentication, inventory, dispatch, damage, repair, and replacement workflows.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="activity-log-filter-row">
                <input type="search" id="activity-log-search" class="form-control" placeholder="Search description, action, module, or user...">
                <select id="activity-log-action" class="form-control">
                    <option value="">All Actions</option>
                </select>
                <select id="activity-log-module" class="form-control">
                    <option value="">All Modules</option>
                </select>
                <input type="date" id="activity-log-from" class="form-control">
                <input type="date" id="activity-log-to" class="form-control">
                <button type="button" class="btn btn-secondary" id="activity-log-clear">Clear</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h3>Audit Trail</h3>
                <p class="text-muted mb-0" id="activity-log-summary">Loading activity logs...</p>
            </div>
            <div class="text-muted" id="activity-log-total">Total: 0</div>
        </div>
        <div class="card-body">
            <div id="activity-log-table-container">
                <div class="ui-empty-state"><strong>Loading activity logs...</strong></div>
            </div>

            <div class="activity-log-pagination">
                <button type="button" class="btn btn-secondary" id="activity-log-prev">Previous</button>
                <div class="text-muted" id="activity-log-page-label">Page 1</div>
                <button type="button" class="btn btn-secondary" id="activity-log-next">Next</button>
            </div>
        </div>
    </div>
    </div>
</main>

<!-- TASK 5 — Activity Log Details modal.
     Carries BOTH `modal` and `activity-log-modal`: the generic `modal`
     class is what main.js initializeModals() binds to (close-button click,
     backdrop click, Escape, and Tab focus-trap) and what UI.toggleModal()
     drives (.show + aria-hidden + focus in/restore), so none of that
     behaviour is reimplemented here. `activity-log-modal` exists purely so
     the styling below stays scoped to this page and does not leak a global
     `.modal` definition. The markup is static (not injected) because
     initializeModals() binds its listeners once, at DOMContentLoaded. -->
<div id="activityLogDetailModal"
     class="modal activity-log-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="activityLogDetailModalTitle"
     aria-hidden="true">
    <div class="modal-content activity-log-modal-content">
        <div class="activity-log-modal-header">
            <div class="activity-log-modal-heading">
                <h2 id="activityLogDetailModalTitle">Activity Log Details</h2>
                <p class="activity-log-modal-subtitle" id="activityLogDetailModalSubtitle"></p>
            </div>
            <button type="button" class="modal-close activity-log-modal-close" aria-label="Close activity log details">&times;</button>
        </div>
        <div class="activity-log-modal-body" id="activityLogDetailModalBody"></div>
        <div class="activity-log-modal-footer">
            <button type="button" class="btn btn-secondary" id="activityLogDetailModalCloseFooter">Close</button>
        </div>
    </div>
</div>

<script>
const ACTIVITY_LOGS_API_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/api/activity-logs') : '/api/activity-logs';
const ACTIVITY_LOGS_PAGE_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/activity-logs') : '/activity-logs';
let activityLogsState = { page: 1, totalPages: 1 };
// TASK 5 — row cache for the Details modal. GET /api/activity-logs (index)
// selects no explicit columns and ActivityLog declares no $hidden/$visible,
// so each row already carries every field the old detail page rendered
// (entity_type, entity_id, ip_address, user_agent, meta_json). The modal
// therefore reuses the row it was given instead of issuing a second
// authorized request — no new endpoint, no duplicated authorization.
let activityLogsById = new Map();
// TASK 98.1 — debounce handle for the live free-text search below. Search
// previously only fired on Enter keydown, which meant real typing (no Enter
// press) never triggered a request. Mirrors the existing live-search
// pattern already used elsewhere in this codebase (e.g. inventory.php's
// #inventory-entry-search 'input' listener).
let activityLogSearchDebounce = null;

function activityLogNotify(message, type = 'danger') {
    // UI_BROWSER_DIALOG_REPLACEMENT — Components/UI are always loaded (see
    // includes/footer.php), so this always goes through the reusable
    // in-app modal; no window.alert() fallback.
    Components.alert(message, type);
}

function escapeActivityHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatActivityLabel(value) {
    return String(value || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatActivityDate(value) {
    if (!value) return 'N/A';
    return new Date(value).toLocaleString();
}

function activityActionBadgeClass(action) {
    const normalized = String(action || '').toLowerCase();
    if (normalized.includes('delete') || normalized.includes('remove')) return 'badge-danger';
    if (normalized.includes('create') || normalized.includes('add')) return 'badge-success';
    if (normalized.includes('update') || normalized.includes('edit')) return 'badge-warning';
    if (normalized.includes('login') || normalized.includes('logout') || normalized.includes('auth')) return 'badge-info';
    return 'badge-primary';
}

function activityActionBadge(action) {
    const cls = activityActionBadgeClass(action);
    return `<span class="badge ${cls} activity-log-action-badge">${escapeActivityHtml(formatActivityLabel(action))}</span>`;
}

// TASK 5 — Activity Log Details modal renderer. Field set, ordering, and
// null-fallbacks are copied 1:1 from the standalone activity-log-detail.php
// page so the modal shows exactly what that page showed — no new fields are
// introduced and nothing extra is exposed.
function activityLogDetailRow(label, value) {
    return `<div class="activity-log-detail-item">
        <dt class="activity-log-detail-label">${escapeActivityHtml(label)}</dt>
        <dd class="activity-log-detail-value">${escapeActivityHtml(value)}</dd>
    </div>`;
}

function openActivityLogDetailModal(logId) {
    const modal = document.getElementById('activityLogDetailModal');
    if (!modal) return;

    // Guard against double-opening (repeat clicks while already open).
    if (modal.classList.contains('show')) return;

    const log = activityLogsById.get(Number(logId));
    if (!log) {
        activityLogNotify('That activity log entry is no longer loaded. Refresh the list and try again.', 'warning');
        return;
    }

    const userLabel = log.user?.full_name || (log.user_id ? `User #${log.user_id}` : 'System');

    document.getElementById('activityLogDetailModalSubtitle').textContent =
        `${formatActivityLabel(log.module || 'system')} • ${formatActivityDate(log.created_at)}`;

    const metaHtml = log.meta_json && typeof log.meta_json === 'object'
        ? `<pre class="activity-log-detail-meta">${escapeActivityHtml(JSON.stringify(log.meta_json, null, 2))}</pre>`
        : '<p class="activity-log-detail-text text-muted">No metadata recorded.</p>';

    document.getElementById('activityLogDetailModalBody').innerHTML = `
        <dl class="activity-log-detail-grid">
            ${activityLogDetailRow('Action', formatActivityLabel(log.action) || 'N/A')}
            ${activityLogDetailRow('Module', formatActivityLabel(log.module || 'system'))}
            ${activityLogDetailRow('User', userLabel)}
            ${activityLogDetailRow('User Role', formatActivityLabel(log.user_role || 'system'))}
            ${activityLogDetailRow('Entity Type', formatActivityLabel(log.entity_type || 'n/a'))}
            ${activityLogDetailRow('Entity ID', log.entity_id ?? 'N/A')}
            ${activityLogDetailRow('IP Address', log.ip_address || 'N/A')}
            ${activityLogDetailRow('Timestamp', formatActivityDate(log.created_at))}
        </dl>
        <div class="activity-log-detail-block">
            <h3 class="activity-log-detail-heading">Description</h3>
            <p class="activity-log-detail-text">${escapeActivityHtml(log.details || 'No description recorded.')}</p>
        </div>
        <div class="activity-log-detail-block">
            <h3 class="activity-log-detail-heading">Browser / User Agent</h3>
            <p class="activity-log-detail-text activity-log-detail-break">${escapeActivityHtml(log.user_agent || 'N/A')}</p>
        </div>
        <div class="activity-log-detail-block">
            <h3 class="activity-log-detail-heading">Metadata</h3>
            ${metaHtml}
        </div>
    `;

    // UI.toggleModal handles .show, aria-hidden, moving focus into the
    // dialog, and restoring it to the trigger on close.
    UI.toggleModal('activityLogDetailModal', true);
}

function closeActivityLogDetailModal() {
    UI.toggleModal('activityLogDetailModal', false);
}

async function loadActivityLogOptions() {
    try {
        const { response, data } = await Components.fetchJson(`${ACTIVITY_LOGS_API_BASE}/support/options`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to load activity log filters.');
        }

        const actions = Array.isArray(data.data?.actions) ? data.data.actions : [];
        const modules = Array.isArray(data.data?.modules) ? data.data.modules : [];

        document.getElementById('activity-log-action').innerHTML =
            '<option value="">All Actions</option>' +
            actions.map((action) => `<option value="${escapeActivityHtml(action)}">${escapeActivityHtml(formatActivityLabel(action))}</option>`).join('');

        document.getElementById('activity-log-module').innerHTML =
            '<option value="">All Modules</option>' +
            modules.map((module) => `<option value="${escapeActivityHtml(module)}">${escapeActivityHtml(formatActivityLabel(module))}</option>`).join('');
    } catch (error) {
        console.error('Activity log options error', error);
    }
}

async function loadActivityLogs(page = 1) {
    const params = new URLSearchParams({
        page: String(page),
        per_page: '15',
    });

    const search = document.getElementById('activity-log-search').value.trim();
    const action = document.getElementById('activity-log-action').value;
    const module = document.getElementById('activity-log-module').value;
    const from = document.getElementById('activity-log-from').value;
    const to = document.getElementById('activity-log-to').value;

    if (search) params.set('q', search);
    if (action) params.set('action', action);
    if (module) params.set('module', module);
    if (from) params.set('from', from);
    if (to) params.set('to', to);

    try {
        const { response, data } = await Components.fetchJson(`${ACTIVITY_LOGS_API_BASE}?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to load activity logs.');
        }

        const logs = Array.isArray(data.data?.logs?.data) ? data.data.logs.data : [];
        const total = Number(data.data?.logs?.total || 0);
        const currentPage = Number(data.data?.logs?.current_page || page);
        const lastPage = Number(data.data?.logs?.last_page || 1);
        activityLogsState = { page: currentPage, totalPages: lastPage };

        document.getElementById('activity-log-total').textContent = `Total: ${total}`;
        document.getElementById('activity-log-summary').textContent = total
            ? `Showing ${logs.length} log entr${logs.length === 1 ? 'y' : 'ies'} from the current filter set.`
            : 'No matching activity logs were found.';
        document.getElementById('activity-log-page-label').textContent = `Page ${currentPage} of ${lastPage}`;
        document.getElementById('activity-log-prev').disabled = currentPage <= 1;
        document.getElementById('activity-log-next').disabled = currentPage >= lastPage;

        activityLogsById = new Map();
        logs.forEach((log) => activityLogsById.set(Number(log.id), log));

        const container = document.getElementById('activity-log-table-container');
        if (!logs.length) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No activity logs found.</strong><span>Try adjusting your filters.</span></div>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table"><thead><tr>';
        html += '<th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Description</th><th>Details</th>';
        html += '</tr></thead><tbody>';

        logs.forEach((log) => {
            const userLabel = log.user?.full_name || (log.user_id ? `User #${log.user_id}` : 'System');
            html += '<tr>';
            html += `<td>${escapeActivityHtml(formatActivityDate(log.created_at))}</td>`;
            html += `<td>${escapeActivityHtml(userLabel)}</td>`;
            html += `<td>${escapeActivityHtml(formatActivityLabel(log.user_role || 'system'))}</td>`;
            html += `<td>${activityActionBadge(log.action)}</td>`;
            html += `<td>${escapeActivityHtml(formatActivityLabel(log.module))}</td>`;
            html += `<td>${escapeActivityHtml(log.details || 'No description')}</td>`;
            // TASK 5 — was an <a href> to /activity-logs/{id}, which navigated
            // away from this page. Now opens the details modal in place. The
            // standalone detail page and its route are intentionally left
            // intact so existing deep links keep working.
            html += `<td><button type="button" class="btn btn-sm btn-primary activity-log-view-btn" data-activity-log-id="${Number(log.id)}" aria-haspopup="dialog" aria-label="View details for ${escapeActivityHtml(formatActivityLabel(log.action) || 'activity log')} entry">View</button></td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;

        container.querySelectorAll('.activity-log-view-btn').forEach((button) => {
            button.addEventListener('click', () => {
                openActivityLogDetailModal(Number(button.getAttribute('data-activity-log-id') || 0));
            });
        });
    } catch (error) {
        document.getElementById('activity-log-table-container').innerHTML = '<div class="ui-empty-state"><strong>Failed to load activity logs.</strong></div>';
        activityLogNotify(error.message || 'Unable to load activity logs.');
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadActivityLogOptions();
    await loadActivityLogs(1);

    // TASK 98.1 — live (debounced) search-as-you-type, reusing the existing
    // loadActivityLogs()/params.set('q', ...) path already wired to the
    // backend's `q` (action/module/details/user multi-field LIKE) parameter.
    // The pre-existing Enter-key handler is kept below (and clears any
    // pending debounce) so pressing Enter still searches immediately.
    document.getElementById('activity-log-search').addEventListener('input', () => {
        clearTimeout(activityLogSearchDebounce);
        activityLogSearchDebounce = setTimeout(() => {
            loadActivityLogs(1);
        }, 300);
    });

    document.getElementById('activity-log-search').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            clearTimeout(activityLogSearchDebounce);
            loadActivityLogs(1);
        }
    });
    document.getElementById('activity-log-action').addEventListener('change', () => loadActivityLogs(1));
    document.getElementById('activity-log-module').addEventListener('change', () => loadActivityLogs(1));
    document.getElementById('activity-log-from').addEventListener('change', () => loadActivityLogs(1));
    document.getElementById('activity-log-to').addEventListener('change', () => loadActivityLogs(1));
    document.getElementById('activity-log-clear').addEventListener('click', async () => {
        clearTimeout(activityLogSearchDebounce);
        document.getElementById('activity-log-search').value = '';
        document.getElementById('activity-log-action').value = '';
        document.getElementById('activity-log-module').value = '';
        document.getElementById('activity-log-from').value = '';
        document.getElementById('activity-log-to').value = '';
        await loadActivityLogs(1);
    });
    // The header × and backdrop click are already wired by main.js
    // initializeModals() via the generic .modal/.modal-close classes; only
    // the footer Close button needs its own handler.
    document.getElementById('activityLogDetailModalCloseFooter').addEventListener('click', closeActivityLogDetailModal);

    document.getElementById('activity-log-prev').addEventListener('click', () => loadActivityLogs(Math.max(1, activityLogsState.page - 1)));
    document.getElementById('activity-log-next').addEventListener('click', () => loadActivityLogs(Math.min(activityLogsState.totalPages, activityLogsState.page + 1)));
});
</script>

<style>
/* ============================================================
   Activity Logs — spacing/alignment polish only (scoped to
   .activity-log-page). Form ids/names, API calls, and JS
   behavior are unchanged; badge rendering keeps the same
   escaped label text, just wrapped for consistent coloring.
   ============================================================ */
.activity-log-filter-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
    gap: 10px;
    align-items: stretch;
}

.activity-log-page .activity-log-filter-row .form-control,
.activity-log-page .activity-log-filter-row .btn {
    height: 42px;
    box-sizing: border-box;
}

.activity-log-page .activity-log-filter-row .form-control {
    width: 100%;
    padding: 0 12px;
    border-radius: 10px;
}

.activity-log-page .activity-log-filter-row .btn {
    white-space: nowrap;
}

@media (max-width: 992px) {
    .activity-log-filter-row {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 640px) {
    .activity-log-filter-row {
        grid-template-columns: 1fr;
    }
}

.activity-log-page #activity-log-table-container .table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    font-size: 12px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}

.activity-log-page #activity-log-table-container .table tbody tr:nth-child(even) {
    background: rgba(148, 163, 184, 0.06);
}

.activity-log-page #activity-log-table-container .table tbody tr:hover {
    background: rgba(109, 40, 217, 0.07);
}

.activity-log-page #activity-log-table-container .table td,
.activity-log-page #activity-log-table-container .table th {
    vertical-align: middle;
}

.activity-log-action-badge {
    display: inline-block;
    white-space: nowrap;
}

.activity-log-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 14px;
    flex-wrap: wrap;
}

/* ============================================================
   TASK 5A Phase 2 — typography normalization, scoped to
   .activity-log-page only. Dean's brief: system/UI text reads at
   12px. Nothing here goes below 12px, and headings keep a clear
   step above body text so hierarchy survives. No shared
   stylesheet is touched — these rules only bind inside this page.
   ============================================================ */
.activity-log-page .card-header h2 {
    font-size: 18px;
}

.activity-log-page .card-header h3 {
    font-size: 16px;
}

.activity-log-page .card-header p,
.activity-log-page .card-header .text-muted {
    font-size: 12px;
}

.activity-log-page .activity-log-filter-row .form-control,
.activity-log-page .activity-log-filter-row .form-control::placeholder,
.activity-log-page .activity-log-filter-row .btn {
    font-size: 12px;
}

.activity-log-page #activity-log-table-container .table td,
.activity-log-page #activity-log-table-container .table th,
.activity-log-page .activity-log-action-badge,
.activity-log-page .ui-empty-state {
    font-size: 12px;
}

.activity-log-page .activity-log-pagination .btn,
.activity-log-page .activity-log-pagination .text-muted {
    font-size: 12px;
}

/* ============================================================
   TASK 5 — Activity Log Details modal. Styling is deliberately
   keyed off .activity-log-modal (not the bare .modal class the
   behaviour hooks use) so this page cannot leak a global .modal
   definition. z-index 1200 keeps it below .system-modal-overlay
   (z-index 2100) so Components.alert() still layers on top.

   TASK 5A — the surface is now theme-aware. Every colour resolves
   through the page-scoped --alm-* variables below, which default
   to the shared light-theme tokens; the
   :root[data-theme-resolved='dark'] block further down restores
   the original Enterprise Dark gradient/violet treatment byte for
   byte, so dark mode is visually unchanged. The backdrop stays
   dark and translucent in both themes, which is the conventional
   scrim treatment and keeps focus on the dialog.
   ============================================================ */
.activity-log-modal {
    /* Light theme (default) — shared tokens, no hardcoded surface. */
    --alm-surface: var(--surface, #ffffff);
    --alm-surface-2: var(--surface-muted, #f8fafc);
    --alm-border: var(--border, #e5e7eb);
    --alm-divider: var(--border, #e5e7eb);
    --alm-title: var(--text-primary, #111827);
    --alm-text: var(--text-primary, #111827);
    --alm-text-muted: var(--text-secondary, #6b7280);
    --alm-text-subtle: var(--text-secondary, #6b7280);
    --alm-meta-text: var(--text-primary, #111827);
    --alm-accent: var(--color-primary-700, #6d28d9);
    --alm-close: var(--text-secondary, #6b7280);
    --alm-close-hover-bg: rgba(109, 40, 217, 0.12);
    --alm-close-hover-border: rgba(109, 40, 217, 0.28);
    --alm-close-hover-text: var(--color-primary-700, #6d28d9);
    --alm-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);

    display: none;
    position: fixed;
    inset: 0;
    z-index: 1200;
    padding: 20px;
    background: rgba(9, 12, 22, 0.72);
}

:root[data-theme-resolved='dark'] .activity-log-modal {
    --alm-surface: linear-gradient(180deg, #221634 0%, #171225 100%);
    --alm-surface-2: rgba(11, 17, 32, 0.6);
    --alm-border: rgba(168, 139, 250, 0.26);
    --alm-divider: rgba(255, 255, 255, 0.08);
    --alm-title: #ffffff;
    --alm-text: #f8fafc;
    --alm-text-muted: #cbb9f2;
    --alm-text-subtle: #94a3b8;
    --alm-meta-text: #e2e8f0;
    --alm-accent: #a78bfa;
    --alm-close: #cbd5e1;
    --alm-close-hover-bg: rgba(168, 139, 250, 0.16);
    --alm-close-hover-border: rgba(168, 139, 250, 0.32);
    --alm-close-hover-text: #ffffff;
    --alm-shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
}

.activity-log-modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.activity-log-modal .activity-log-modal-content {
    width: min(760px, 100%);
    max-height: calc(100vh - 40px);
    display: flex;
    flex-direction: column;
    border-radius: 18px;
    background: var(--alm-surface);
    border: 1px solid var(--alm-border);
    box-shadow: var(--alm-shadow);
    overflow: hidden;
}

.activity-log-modal .activity-log-modal-header,
.activity-log-modal .activity-log-modal-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    flex: 0 0 auto;
}

.activity-log-modal .activity-log-modal-header {
    justify-content: space-between;
    border-bottom: 1px solid var(--alm-divider);
}

.activity-log-modal .activity-log-modal-footer {
    justify-content: flex-end;
    border-top: 1px solid var(--alm-divider);
}

.activity-log-modal .activity-log-modal-footer .btn {
    font-size: 12px;
}

.activity-log-modal .activity-log-modal-heading {
    min-width: 0;
}

.activity-log-modal .activity-log-modal-header h2 {
    margin: 0;
    color: var(--alm-title);
    font-size: 18px;
}

.activity-log-modal .activity-log-modal-subtitle {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--alm-text-muted);
}

.activity-log-modal .activity-log-modal-close {
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 9px;
    background: transparent;
    color: var(--alm-close);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
}

.activity-log-modal .activity-log-modal-close:hover,
.activity-log-modal .activity-log-modal-close:focus-visible {
    background: var(--alm-close-hover-bg);
    border-color: var(--alm-close-hover-border);
    color: var(--alm-close-hover-text);
    outline: none;
}

.activity-log-modal .activity-log-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 18px 20px;
}

.activity-log-modal .activity-log-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px 18px;
    margin: 0;
}

.activity-log-modal .activity-log-detail-item {
    min-width: 0;
}

.activity-log-modal .activity-log-detail-label {
    margin: 0 0 2px;
    /* 12px floor: never smaller than the system text size. */
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--alm-accent);
}

.activity-log-modal .activity-log-detail-value {
    margin: 0;
    font-size: 13px;
    color: var(--alm-text);
    overflow-wrap: anywhere;
}

.activity-log-modal .activity-log-detail-block {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid var(--alm-divider);
}

.activity-log-modal .activity-log-detail-heading {
    margin: 0 0 6px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--alm-accent);
}

.activity-log-modal .activity-log-detail-text {
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    color: var(--alm-text);
    overflow-wrap: anywhere;
}

/* Wins over the global `.text-muted` !important rule via specificity
   only where that rule is not !important; the explicit !important
   below keeps the muted placeholder readable in light theme too. */
.activity-log-modal .activity-log-detail-text.text-muted {
    color: var(--alm-text-subtle) !important;
}

.activity-log-modal .activity-log-detail-break {
    word-break: break-word;
}

.activity-log-modal .activity-log-detail-meta {
    margin: 0;
    padding: 12px;
    max-height: 240px;
    overflow: auto;
    border-radius: 10px;
    border: 1px solid var(--alm-divider);
    background: var(--alm-surface-2);
    color: var(--alm-meta-text);
    font-size: 12px;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

@media (max-width: 640px) {
    .activity-log-modal {
        padding: 12px;
    }

    .activity-log-modal .activity-log-detail-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 480px) {
    .activity-log-pagination {
        justify-content: center;
        text-align: center;
    }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
