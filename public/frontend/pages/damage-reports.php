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

$pageTitle = 'Damage Reports - SFMS';
$pageStylesheets = [
    '/School_Facility_Maintenance_System/frontend/assets/css/design-system-components.css?v=20260726-1',
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1',
];
include __DIR__ . '/../includes/header.php';
?>

<main class="container damage-reports-page" style="margin-top:16px;">
    <div class="card" style="border-left:3px solid var(--primary-color);">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <!-- TASK 45 (Damage Report Role Redesign) — this page is a
                     specialised VIEW over the primary maintenance workflow, not
                     a second reporting system. The heading says so explicitly so
                     the purpose is obvious without reading documentation. There
                     is deliberately no "Create Damage Report" control here:
                     creation happens only through Create Report, which already
                     records the asset details alongside the maintenance report. -->
                <h2>Damage Reports <span class="text-muted" style="font-size:.6em;font-weight:500;">Asset Damage Cases</span></h2>
                <p class="text-muted mb-0">Track maintenance reports involving damaged physical assets and their repair/replacement progress.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="report-filters-row">
                <div class="search-bar" style="max-width:none;">
                    <span class="search-bar-icon" aria-hidden="true"></span>
                    <input type="search" id="damage-search" class="search-bar-input" placeholder="Search report code, item, room, or description...">
                </div>
                <select id="damage-status" class="filter-select">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="under_review">Under Review</option>
                    <option value="repairing">Repairing</option>
                    <option value="repaired">Repaired</option>
                    <option value="replaced">Replaced</option>
                    <option value="closed">Closed</option>
                </select>
                <select id="damage-severity" class="filter-select">
                    <option value="">All Severity</option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>
                <button type="button" id="damage-filter-clear" class="btn btn-secondary">Clear</button>
            </div>

            <div id="damage-reports-container" class="table-responsive">
                <div class="ui-empty-state ui-fade-in" aria-live="polite">
                    <strong>Loading damage reports...</strong>
                    <div class="ui-skeleton-list" style="margin-top: 12px;">
                        <div class="ui-skeleton-row w-90"></div>
                        <div class="ui-skeleton-row w-75"></div>
                        <div class="ui-skeleton-row w-55"></div>
                    </div>
                </div>
            </div>

            <nav class="pagination report-pagination-bar" aria-label="Damage reports pagination">
                <div class="report-pagination-controls">
                    <button type="button" class="btn btn-secondary" id="damage-prev">Previous</button>
                    <span id="damage-page-info" class="text-muted">Page 1</span>
                    <button type="button" class="btn btn-secondary" id="damage-next">Next</button>
                </div>
            </nav>
        </div>
    </div>
    </div>
</main>

<script>
let damagePage = 1;
let damageLastPage = 1;
const DAMAGE_REPORTS_API_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/api/damage-reports') : '/api/damage-reports';
const DAMAGE_REPORTS_PAGE_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/damage-reports') : '/damage-reports';
// TASK 45 — target of the "open the primary maintenance report" link. Points at
// the EXISTING maintenance report detail page (which owns assignment, the Task 44
// cross-department warning, and status updates); Damage Reports deliberately
// does not reimplement any of that.
const MAINTENANCE_REPORT_DETAIL_BASE = window.SFMS_PUBLIC_URL
    ? window.SFMS_PUBLIC_URL('/frontend/pages/maintenance-report-detail.php')
    : '/frontend/pages/maintenance-report-detail.php';

function damageEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function damageNotify(message, type = 'danger') {
    // UI_BROWSER_DIALOG_REPLACEMENT — Components/UI are always loaded (see
    // includes/footer.php), so this always goes through the reusable
    // in-app modal; no window.alert() fallback.
    Components.alert(message, type);
}

function formatStatus(status) {
    return String(status || '').replace(/_/g, ' ').replace(/\b\w/g, (s) => s.toUpperCase());
}

// TASK 45 — helpers for the asset-damage view. All of them read ONLY fields the
// API already returns (the damage row plus its eager-loaded item/room/building
// and its linked maintenance report); none of them invent or infer values, and
// each degrades to an honest placeholder when the underlying data is absent.

function hasActiveDamageFilters() {
    return Boolean(
        document.getElementById('damage-search').value.trim()
        || document.getElementById('damage-status').value
        || document.getElementById('damage-severity').value
    );
}

/**
 * Building + Room for the damaged asset. This comes from damage_reports.room_id
 * (the authoritative asset location), NOT from the maintenance report's free-text
 * `location` string, which is a display field and is inconsistent on older rows.
 */
function formatDamageLocation(row) {
    const building = row.room?.building?.name;
    const room = row.room?.name;
    if (building && room) return `${building} / ${room}`;
    return room || building || 'N/A';
}

/**
 * The repair/replacement state is derived from fields that already exist on the
 * damage row. `replacement_item_id` being set is the system's existing signal
 * that a replacement was issued, so we report that rather than inventing a new
 * state machine (Task 45 explicitly does not introduce one).
 */
function formatRepairState(row) {
    if (row.replacement_item_id) {
        const name = row.replacement_item?.name || 'Replacement item';
        const qty = row.replacement_quantity || 1;
        return `Replaced with ${name} (Qty ${qty})`;
    }
    if (row.status === 'repaired') return 'Repaired';
    if (row.status === 'repairing') return 'Repair in progress';
    if (row.repair_notes) return 'Repair notes recorded';
    return 'Not started';
}

/**
 * Links the damage case back to its PRIMARY maintenance report. Legacy rows
 * created before the unified flow have report_id = null, so this must not
 * pretend a link exists.
 */
function renderLinkedReportCell(row) {
    const reportId = row.report_id;
    if (!reportId) {
        return '<span class="text-muted">Not linked</span>';
    }
    const status = row.report?.status ? formatStatus(row.report.status) : '';
    const href = `${MAINTENANCE_REPORT_DETAIL_BASE}?id=${encodeURIComponent(reportId)}`;
    return `<a href="${href}">#${damageEscapeHtml(reportId)}</a>`
        + (status ? ` <span class="text-muted">(${damageEscapeHtml(status)})</span>` : '');
}

function getDamageLoadingMarkup() {
    return `
        <div class="ui-empty-state ui-fade-in" aria-live="polite">
            <strong>Loading damage reports...</strong>
            <div class="ui-skeleton-list" style="margin-top: 12px;">
                <div class="ui-skeleton-row w-90"></div>
                <div class="ui-skeleton-row w-75"></div>
                <div class="ui-skeleton-row w-55"></div>
            </div>
        </div>
    `;
}

async function loadDamageReports() {
    const search = document.getElementById('damage-search').value.trim();
    const status = document.getElementById('damage-status').value;
    const severity = document.getElementById('damage-severity').value;

    const listContainer = document.getElementById('damage-reports-container');
    if (listContainer) listContainer.innerHTML = getDamageLoadingMarkup();

    const params = new URLSearchParams({
        page: String(damagePage),
        per_page: '15',
    });

    if (search) params.set('q', search);
    if (status) params.set('status', status);
    if (severity) params.set('severity_level', severity);

    try {
        const fetcher = window.Components && typeof Components.fetchJson === 'function'
            ? Components.fetchJson
            : async (url, options) => {
                const response = await fetch(url, options);
                return { response, data: await response.json() };
            };
        const { response, data: payload } = await fetcher(`${DAMAGE_REPORTS_API_BASE}?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load damage reports');
        }

        const paginator = payload.data?.reports;
        const rows = Array.isArray(paginator?.data) ? paginator.data : [];
        damagePage = Number(paginator?.current_page || 1);
        damageLastPage = Number(paginator?.last_page || 1);

        const container = document.getElementById('damage-reports-container');
        if (rows.length === 0) {
            // TASK 45 — the empty state explains what this page is FOR, rather
            // than implying the user forgot to create something. It is
            // filter-aware: blaming the filters when none are set would be
            // misleading, and explaining the feature when the user has just
            // filtered everything out would be unhelpful. Deliberately offers
            // no "Create Damage Report" action — creation is via Create Report.
            container.innerHTML = hasActiveDamageFilters()
                ? '<div class="ui-empty-state ui-fade-in"><strong>No asset damage reports match your filters.</strong><span>Try clearing the search, status, or severity filters.</span></div>'
                : '<div class="ui-empty-state ui-fade-in"><strong>No asset damage reports found.</strong><span>Asset-related maintenance reports will appear here when a physical item is reported as damaged. To report one, use <em>Report a Problem</em> and include the affected item.</span></div>';
        } else {
            let html = '<table class="table ui-fade-in"><thead><tr>';
            html += '<th>Damage Case</th><th>Maintenance Report</th><th>Asset / Item</th><th>Location</th><th>Department</th><th>Severity</th><th>Assigned Personnel</th><th>Damage Status</th><th>Repair / Replacement</th><th>Date Reported</th><th>Actions</th>';
            html += '</tr></thead><tbody>';

            rows.forEach((row) => {
                html += '<tr>';
                html += `<td><strong>${damageEscapeHtml(row.damage_report_code)}</strong></td>`;
                html += `<td>${renderLinkedReportCell(row)}</td>`;
                html += `<td>${damageEscapeHtml(row.item?.name || 'Unknown')}</td>`;
                html += `<td>${damageEscapeHtml(formatDamageLocation(row))}</td>`;
                html += `<td>${damageEscapeHtml(row.department?.name || 'N/A')}</td>`;
                html += `<td><span class="badge badge-${String(row.severity_level || '').toLowerCase().replace(/[^a-z0-9-]/g, '')}">${damageEscapeHtml(formatStatus(row.severity_level))}</span></td>`;
                html += `<td>${damageEscapeHtml(row.report?.assignee?.full_name || 'Unassigned')}</td>`;
                html += `<td><span class="badge badge-${String(row.status || '').toLowerCase().replace(/_/g, '-').replace(/[^a-z0-9-]/g, '')}">${damageEscapeHtml(formatStatus(row.status))}</span></td>`;
                html += `<td>${damageEscapeHtml(formatRepairState(row))}</td>`;
                html += `<td>${damageEscapeHtml(new Date(row.created_at).toLocaleString())}</td>`;
                html += `<td><a class="btn btn-sm btn-primary" href="${DAMAGE_REPORTS_PAGE_BASE}/${row.id}">View</a> <a class="btn btn-sm btn-secondary" href="${DAMAGE_REPORTS_PAGE_BASE}/${row.id}/update">Update</a></td>`;
                html += '</tr>';
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        document.getElementById('damage-page-info').textContent = `Page ${damagePage} / ${damageLastPage}`;
        document.getElementById('damage-prev').disabled = damagePage <= 1;
        document.getElementById('damage-next').disabled = damagePage >= damageLastPage;
    } catch (error) {
        document.getElementById('damage-reports-container').innerHTML = '<div class="ui-empty-state"><strong>Failed to load damage reports.</strong></div>';
        damageNotify(error.message || 'Unable to load damage reports.');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('damage-search').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            damagePage = 1;
            loadDamageReports();
        }
    });

    document.getElementById('damage-status').addEventListener('change', () => {
        damagePage = 1;
        loadDamageReports();
    });

    document.getElementById('damage-severity').addEventListener('change', () => {
        damagePage = 1;
        loadDamageReports();
    });

    document.getElementById('damage-filter-clear').addEventListener('click', () => {
        document.getElementById('damage-search').value = '';
        document.getElementById('damage-status').value = '';
        document.getElementById('damage-severity').value = '';
        damagePage = 1;
        loadDamageReports();
    });

    document.getElementById('damage-prev').addEventListener('click', () => {
        if (damagePage > 1) {
            damagePage -= 1;
            loadDamageReports();
        }
    });

    document.getElementById('damage-next').addEventListener('click', () => {
        if (damagePage < damageLastPage) {
            damagePage += 1;
            loadDamageReports();
        }
    });

    loadDamageReports();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
