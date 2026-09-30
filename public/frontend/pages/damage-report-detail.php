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

$pageTitle = 'Damage Report Details - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container" style="margin-top:16px;">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2 id="damage-title">Damage Report Details</h2>
                <p class="text-muted mb-0" id="damage-subtitle">Loading report...</p>
            </div>
            <div class="d-flex gap-sm">
                <a href="<?php echo htmlspecialchars(public_url('/damage-reports')); ?>" class="btn btn-secondary">Back to List</a>
                <!-- TASK 12 — the "Repair Workflow" button was removed here.
                     It pointed at /repairs?damage_report_id=..., i.e. the
                     Repair Requests page that this task deletes. It had never
                     actually shipped to users: it carried style="display:none"
                     and a "TODO: unhide when Repairs module is built" note, so
                     removing it changes nothing a user could previously see or
                     click. The "View Maintenance Report" button below is the
                     primary-workflow route and is deliberately untouched. -->
                <!-- TASK 45 PHASE 9 — route to the PRIMARY maintenance report,
                     which owns assignment, status and the Task 44
                     cross-department warning. Hidden until loadDamageDetail()
                     confirms a linked report exists, so it never dead-ends. -->
                <a id="damage-view-report-link" href="#" class="btn btn-primary" style="display:none;">View Maintenance Report</a>
                <a id="damage-update-link" href="#" class="btn btn-secondary">Update Status / Notes</a>
            </div>
        </div>
        <div class="card-body" id="damage-detail-container">
            <div class="ui-empty-state"><strong>Loading damage report...</strong></div>
        </div>
    </div>

    <div class="card" style="margin-top:14px;">
        <div class="card-header">
            <h3>Damage History</h3>
        </div>
        <div class="card-body" id="damage-history-container">
            <div class="ui-empty-state"><strong>Loading history...</strong></div>
        </div>
    </div>
</main>

<script>
const DAMAGE_REPORTS_API_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/api/damage-reports') : '/api/damage-reports';
const DAMAGE_REPORTS_PAGE_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/damage-reports') : '/damage-reports';
// TASK 45 — the existing maintenance report detail page. Damage Reports links
// TO this workflow rather than reimplementing any part of it.
const MAINTENANCE_REPORT_DETAIL_BASE = window.SFMS_PUBLIC_URL
    ? window.SFMS_PUBLIC_URL('/frontend/pages/maintenance-report-detail.php')
    : '/frontend/pages/maintenance-report-detail.php';

function detailNotify(message, type = 'danger') {
    // UI_BROWSER_DIALOG_REPLACEMENT — Components/UI are always loaded (see
    // includes/footer.php), so this always goes through the reusable
    // in-app modal; no window.alert() fallback.
    Components.alert(message, type);
}

function getDamageIdFromQuery() {
    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get('id') || 0);
    return id > 0 ? id : 0;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatStatus(status) {
    return String(status || '').replace(/_/g, ' ').replace(/\b\w/g, (s) => s.toUpperCase());
}

function statusBadge(status) {
    const map = {
        pending:      'background:#fef3c7;color:#92400e',
        under_review: 'background:#ffedd5;color:#9a3412',
        repairing:    'background:#dbeafe;color:#1e40af',
        repaired:     'background:#d1fae5;color:#065f46',
        replaced:     'background:#ede9fe;color:#5b21b6',
        closed:       'background:#f3f4f6;color:#374151',
    };
    const style = map[status] || 'background:#f3f4f6;color:#374151';
    const label = formatStatus(status);
    return `<span style="${style};padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;">${escapeHtml(label)}</span>`;
}

function severityBadge(severity) {
    const map = {
        low:      'background:#d1fae5;color:#065f46',
        medium:   'background:#fef3c7;color:#92400e',
        high:     'background:#ffedd5;color:#9a3412',
        critical: 'background:#fee2e2;color:#991b1b',
    };
    const style = map[severity] || 'background:#f3f4f6;color:#374151';
    const label = String(severity || '').toUpperCase();
    return `<span style="${style};padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;">${escapeHtml(label)}</span>`;
}

async function loadDamageDetail() {
    const id = getDamageIdFromQuery();
    if (!id) {
        detailNotify('Invalid damage report ID.', 'warning');
        window.location.href = DAMAGE_REPORTS_PAGE_BASE;
        return;
    }

    document.getElementById('damage-update-link').href = `${DAMAGE_REPORTS_PAGE_BASE}/${id}/update`;

    try {
        const { response, data: payload } = await Components.fetchJson(`${DAMAGE_REPORTS_API_BASE}/${id}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load damage report');
        }

        const report = payload.data?.report;
        if (!report) {
            throw new Error('Damage report payload is invalid.');
        }

        document.getElementById('damage-title').textContent = report.damage_report_code || 'Damage Report';
        document.getElementById('damage-subtitle').innerHTML = `Status: ${statusBadge(report.status)} &nbsp; Severity: ${severityBadge(report.severity_level)}`;

        let imageMarkup = '<span class="text-muted">No image uploaded.</span>';
        if (report.image_path) {
            const safeUrl = String(report.image_path);
            imageMarkup = `<a href="${safeUrl}" target="_blank" rel="noopener"><img src="${safeUrl}" alt="Damage image" style="max-width:260px;border:1px solid rgba(148,163,184,.3);border-radius:8px;"></a>`;
        }

        const createdAt = report.created_at ? new Date(report.created_at).toLocaleString() : 'N/A';

        // TASK 45 (Damage Report Role Redesign) — the detail view is organised
        // into four sections that make the architecture visible: the asset
        // damage information THIS record owns, and then the PRIMARY maintenance
        // report it is attached to, that report's assignment, and the
        // repair/resolution state. Every value below is read straight from the
        // API payload; nothing is inferred or fabricated, and each section
        // degrades to an explicit placeholder when the data genuinely is absent
        // (notably `report`, which is null for legacy pre-Sprint-4 rows).
        const linked = report.report || null;
        const linkedId = report.report_id || null;

        const assetSection = `
            <h3 style="margin:0 0 8px;">Damage / Asset Information</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><strong>Item:</strong> ${escapeHtml(report.item?.name || 'Unknown')}</div>
                <div><strong>Room/Laboratory:</strong> ${escapeHtml(report.room?.name || 'N/A')}</div>
                <div><strong>Building:</strong> ${escapeHtml(report.room?.building?.name || 'N/A')}</div>
                <div><strong>Severity:</strong> ${severityBadge(report.severity_level)}</div>
                <div><strong>Department:</strong> ${escapeHtml(report.department?.name || 'N/A')}</div>
                <div><strong>Reported By:</strong> ${escapeHtml(report.reporter?.full_name || 'Unknown')}</div>
            </div>
            <div style="margin-top:12px;"><strong>Damage Description:</strong><br>${escapeHtml(report.damage_description || '')}</div>
            <div style="margin-top:12px;"><strong>Photo:</strong><br>${imageMarkup}</div>
        `;

        const maintenanceSection = linkedId
            ? `
            <h3 style="margin:18px 0 8px;">Maintenance Report</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><strong>Report Code:</strong> <a href="${MAINTENANCE_REPORT_DETAIL_BASE}?id=${encodeURIComponent(linkedId)}">#${escapeHtml(linkedId)}</a></div>
                <div><strong>Title:</strong> ${escapeHtml(linked?.title || 'N/A')}</div>
                <div><strong>Department:</strong> ${escapeHtml(linked?.department?.name || 'N/A')}</div>
                <div><strong>Priority:</strong> ${escapeHtml(formatStatus(linked?.priority) || 'N/A')}</div>
                <div><strong>Submitted By:</strong> ${escapeHtml(linked?.creator?.full_name || 'Unknown')}</div>
                <div><strong>Date Reported:</strong> ${escapeHtml(createdAt)}</div>
                <div><strong>Report Status:</strong> ${escapeHtml(formatStatus(linked?.status) || 'N/A')}</div>
                <div><strong>Location:</strong> ${escapeHtml(linked?.location || 'N/A')}</div>
            </div>
            <div style="margin-top:12px;"><strong>Description:</strong><br>${escapeHtml(linked?.description || '')}</div>
            `
            : `
            <h3 style="margin:18px 0 8px;">Maintenance Report</h3>
            <div class="text-muted">This damage record is not linked to a maintenance report. It predates the unified Report a Problem flow, in which every asset damage case is recorded together with its maintenance report.</div>
            `;

        // Assignment is DISPLAY-ONLY here. Damage Reports deliberately provides
        // no assignment control: assigning personnel remains the sole
        // responsibility of the maintenance report detail page, which owns the
        // authorization checks and the Task 44 cross-department warning.
        const assignmentSection = linkedId
            ? `
            <h3 style="margin:18px 0 8px;">Assignment</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><strong>Assigned Personnel:</strong> ${escapeHtml(linked?.assignee?.full_name || 'Unassigned')}</div>
                <div><strong>Personnel Department:</strong> ${escapeHtml(linked?.assignee?.department?.name || 'N/A')}</div>
                <div><strong>Assignment Status:</strong> ${escapeHtml(linked?.assigned_to ? formatStatus(linked?.status) : 'Not yet assigned')}</div>
            </div>
            <div class="text-muted" style="margin-top:8px;font-size:13px;">Personnel are assigned on the maintenance report, which is the primary workflow for this case.</div>
            `
            : '';

        const repairSection = `
            <h3 style="margin:18px 0 8px;">Repair / Resolution</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><strong>Damage Status:</strong> ${statusBadge(report.status)}</div>
                <div><strong>Replacement:</strong> ${report.replacement_item_id ? `${escapeHtml(report.replacement_item?.name || 'Unknown Item')} (Qty ${escapeHtml(report.replacement_quantity || 1)})` : 'Not yet replaced'}</div>
            </div>
            <div style="margin-top:12px;"><strong>Repair Notes:</strong><br>${escapeHtml(report.repair_notes || 'No repair notes yet.')}</div>
        `;

        document.getElementById('damage-detail-container').innerHTML =
            assetSection + maintenanceSection + assignmentSection + repairSection;

        // TASK 45 PHASE 9 — surface the route back to the primary workflow.
        // Only shown when a link actually exists, so it can never lead nowhere.
        const viewReportLink = document.getElementById('damage-view-report-link');
        if (viewReportLink && linkedId) {
            viewReportLink.href = `${MAINTENANCE_REPORT_DETAIL_BASE}?id=${encodeURIComponent(linkedId)}`;
            viewReportLink.style.display = '';
        }
    } catch (error) {
        document.getElementById('damage-detail-container').innerHTML = '<div class="ui-empty-state"><strong>Failed to load damage report.</strong></div>';
        detailNotify(error.message || 'Unable to load details.');
    }
}

async function loadDamageHistory() {
    const id = getDamageIdFromQuery();
    if (!id) return;

    try {
        const { response, data: payload } = await Components.fetchJson(`${DAMAGE_REPORTS_API_BASE}/${id}/history`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load history');
        }

        const histories = Array.isArray(payload.data?.histories) ? payload.data.histories : [];
        if (histories.length === 0) {
            document.getElementById('damage-history-container').innerHTML = '<div class="ui-empty-state"><strong>No history entries yet.</strong></div>';
            return;
        }

        let html = '<div style="display:grid;gap:10px;">';
        histories.forEach((entry) => {
            const actor = entry.changed_by?.full_name || entry.changedBy?.full_name || 'System';
            const when = entry.created_at ? new Date(entry.created_at).toLocaleString() : 'N/A';
            html += '<div style="border:1px solid rgba(148,163,184,.25);border-radius:8px;padding:10px;">';
            html += `<div><strong>${escapeHtml(formatStatus(entry.action_type || 'updated'))}</strong> • ${escapeHtml(when)}</div>`;
            if (entry.from_status || entry.to_status) {
                html += `<div class="text-muted" style="font-size:13px;">${escapeHtml(formatStatus(entry.from_status || ''))} → ${escapeHtml(formatStatus(entry.to_status || ''))}</div>`;
            }
            html += `<div style="margin-top:6px;">${escapeHtml(entry.notes || 'No notes')}</div>`;
            html += `<div class="text-muted" style="margin-top:4px;font-size:12px;">By: ${escapeHtml(actor)}</div>`;
            html += '</div>';
        });
        html += '</div>';
        document.getElementById('damage-history-container').innerHTML = html;
    } catch (error) {
        document.getElementById('damage-history-container').innerHTML = '<div class="ui-empty-state"><strong>Failed to load history.</strong></div>';
        detailNotify(error.message || 'Unable to load history.');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadDamageDetail();
    loadDamageHistory();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
