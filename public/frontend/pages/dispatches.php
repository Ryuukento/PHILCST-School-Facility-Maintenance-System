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

$_dspUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? [];
$_dspRole = strtolower(trim((string)($_dspUser['role'] ?? '')));

// TASK 41 — Administrator Create Dispatch Without Approval. Both Head
// Maintenance and Administrator may now create dispatches; they follow
// different workflows (Head -> requires approval, Administrator -> skips it),
// but the button and the create page are shared.
//
// All four gates stay aligned on this same role list: this button, the
// dispatch-create.php page guard, EnsureRole:maintenance_admin,super_admin on
// POST /api/dispatches, and DispatchAuthorizationService::canCreateDispatch().
$canCreateDispatch = in_array($_dspRole, ['maintenance_admin', 'super_admin'], true);

$pageTitle = 'Dispatches - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container dispatches-page" style="margin-top:16px;">
    <h1 class="print-only" style="display:none; margin: 0 0 16px; font-size: 20px; font-weight: 700;">Dispatch Report</h1>
    <section class="dispatches-page__header">
        <div class="dispatches-page__title-group">
            <p class="dispatches-page__eyebrow">Inventory Deployment</p>
            <h1 class="dispatches-page__title">Dispatches</h1>
            <p class="dispatches-page__description">Manage item deployment from inventory rooms to rooms and laboratories.</p>
        </div>
        <div class="dispatches-page__actions">
            <button type="button" id="dispatch-print-btn" class="btn dispatches-page__secondary-action">Print</button>
            <button type="button" id="dispatch-print-labels-btn" class="btn dispatches-page__secondary-action" title="Print Dispatch Code stickers for every approved/released dispatch matching the current filters">Print All Labels</button>
            <?php if ($canCreateDispatch): ?>
            <a href="<?php echo htmlspecialchars(public_url('/dispatches/create')); ?>" class="btn dispatches-page__primary-action">+ Create Dispatch</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="dispatches-panel">
        <div class="dispatches-toolbar">
            <div class="dispatches-toolbar__search">
                <label class="dispatches-toolbar__label" for="dispatch-search">Search</label>
                <input type="search" id="dispatch-search" class="form-control dispatches-toolbar__control" placeholder="Search by dispatch code...">
            </div>
            <div class="dispatches-toolbar__filter">
                <label class="dispatches-toolbar__label" for="dispatch-status">Status</label>
                <select id="dispatch-status" class="form-control dispatches-toolbar__control">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="released">Released</option>
                </select>
            </div>
            <div class="dispatches-toolbar__action">
                <button type="button" id="dispatch-filter-clear" class="btn dispatches-page__ghost-action">Clear Filters</button>
            </div>
        </div>

        <div class="dispatches-table-panel">
            <div class="dispatches-table-panel__header">
                <div>
                    <h2 class="dispatches-table-panel__title">Dispatch Table</h2>
                    <p class="dispatches-table-panel__description">Review dispatch activity, approval history, and release status at a glance.</p>
                </div>
            </div>

            <div id="dispatch-list-container" class="dispatches-table-shell">
                <div class="ui-empty-state ui-fade-in" aria-live="polite">
                    <strong>Loading dispatches...</strong>
                    <div class="ui-skeleton-list" style="margin-top: 12px;">
                        <div class="ui-skeleton-row w-90"></div>
                        <div class="ui-skeleton-row w-75"></div>
                        <div class="ui-skeleton-row w-55"></div>
                    </div>
                </div>
            </div>

            <div class="dispatches-pagination">
                <button type="button" class="btn dispatches-page__ghost-action" id="dispatch-prev">Previous</button>
                <span id="dispatch-page-info" class="dispatches-pagination__info">Page 1</span>
                <button type="button" class="btn dispatches-page__ghost-action" id="dispatch-next">Next</button>
            </div>
        </div>
    </section>

    <!-- TASK 6 — Enterprise Dispatch Table Redesign: lightweight "View Items"
         modal. Reuses the existing GET /api/dispatches/{id} endpoint (already
         used by dispatch-detail.php) on demand rather than adding a new
         endpoint or eager-loading every row's full item list in the list
         payload — no backend change. -->
    <div id="dispatch-items-modal" class="modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="dispatch-items-modal-title">
        <div class="modal-content dispatches-items-modal">
            <div class="modal-header">
                <h3 class="modal-title" id="dispatch-items-modal-title">Dispatch Items</h3>
                <button type="button" class="modal-close" id="dispatch-items-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="dispatch-items-modal-body"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="dispatch-items-modal-dismiss">Close</button>
            </div>
        </div>
    </div>
</main>

<!-- Shared branded printout (logo letterhead) used by printDispatchReport(). -->
<script src="/School_Facility_Maintenance_System/frontend/assets/js/sfms-print.js?v=20260928-1"></script>
<!-- Dispatch Code sticker labels (QR) used by the row "Labels" button. -->
<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/vendor/qrcode-generator.js?v=1.4.4')); ?>"></script>
<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/dispatch-labels.js?v=20260928-2')); ?>"></script>
<script>
// "Prepared by" line on the printed Dispatch Report.
const DSP_CURRENT_USER_NAME = <?php echo json_encode((string)($_dspUser['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const DSP_CURRENT_USER_ROLE = <?php echo json_encode($_dspRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let dispatchPage = 1;
let dispatchLastPage = 1;
// TASK 98.1 — debounce handle for the live free-text search below. Search-
// by-dispatch-code previously only fired on Enter keydown, which meant real
// typing (no Enter press) never triggered a request. Mirrors the existing
// live-search pattern already used elsewhere in this codebase (e.g.
// inventory.php's #inventory-entry-search 'input' listener).
let dispatchSearchDebounce = null;

const DISPATCH_API_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/api/dispatches') : '/api/dispatches';
const DISPATCH_PAGE_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/dispatches') : '/dispatches';

// TASK 6 — Enterprise Dispatch Table Redesign: readable labels for the
// three roles that can appear as Approved By (matches the role strings
// AuthController/User already use — 'super_admin', 'maintenance_admin',
// 'maintenance_staff'). Falls back to a Title-Cased version of whatever
// role string is present, so an unrecognized future role still renders
// something reasonable instead of the raw snake_case value.
const DISPATCH_ROLE_LABELS = {
    super_admin: 'Super Admin',
    maintenance_admin: 'Administrator',
    maintenance_staff: 'Maintenance Staff',
};

function dspEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function dspNotify(message, type = 'danger') {
    if (window.Components && typeof Components.alert === 'function') {
        Components.alert(message, type);
        return;
    }
    const _div = document.createElement('div');
    _div.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;background:#dc2626;color:white;padding:12px 20px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,0.3);max-width:350px;';
    _div.textContent = message;
    document.body.appendChild(_div);
    setTimeout(() => _div.remove(), 4000);
}

function dspMutedDash() {
    return '<span class="dispatches-muted-cell">&mdash;</span>';
}

function dspTextCell(value, { strong = false, fallback = dspMutedDash() } = {}) {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }

    const content = dspEscapeHtml(value);
    return strong ? `<strong class="dispatches-emphasis-cell">${content}</strong>` : content;
}

// TASK 6 — splits a timestamp into separate date / time strings so the
// Date column can render "Jul 29, 2026" above "10:24 AM" instead of one
// long locale string.
function dspFormatDateParts(value) {
    if (!value) {
        return { date: null, time: null };
    }

    try {
        const parsed = new Date(value);
        return {
            date: parsed.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }),
            time: parsed.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
        };
    } catch (error) {
        return { date: String(value), time: null };
    }
}

function dspRoleLabel(role) {
    const key = String(role || '').toLowerCase().trim();
    if (!key) return '';
    return DISPATCH_ROLE_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (s) => s.toUpperCase());
}

function dspStatusBadge(status) {
    const normalized = String(status || '').toLowerCase().replace(/[^a-z0-9-]/g, '');
    const label = String(status || '').replace(/\b\w/g, (s) => s.toUpperCase());
    return `<span class="badge badge-${normalized} dispatches-status-badge"><span class="dispatches-status-dot" aria-hidden="true"></span>${dspEscapeHtml(label || 'Unknown')}</span>`;
}

function getDispatchLoadingMarkup() {
    return `
        <div class="ui-empty-state ui-fade-in" aria-live="polite">
            <strong>Loading dispatches...</strong>
            <div class="ui-skeleton-list" style="margin-top: 12px;">
                <div class="ui-skeleton-row w-90"></div>
                <div class="ui-skeleton-row w-75"></div>
                <div class="ui-skeleton-row w-55"></div>
            </div>
        </div>
    `;
}

// TASK 6 — builds the "Dispatch Code" cell: the code itself as the primary,
// high-emphasis value, with the linked Maintenance Report's title (when
// this dispatch has one) as a smaller, muted secondary line underneath.
// Uses fields already present on the list payload (report:report_id,title,...)
// — no new API call.
function dspCodeCell(row) {
    const secondary = row.report && row.report.title
        ? dspEscapeHtml(row.report.title)
        : '<span class="dispatches-muted-cell">No linked report</span>';

    return `
        <div class="dispatches-code-primary">${dspEscapeHtml(row.dispatch_code)}</div>
        <div class="dispatches-code-secondary">${secondary}</div>
    `;
}

function dspDepartmentCell(row) {
    const name = row.department_name || row.department?.name;
    if (!name) {
        return dspMutedDash();
    }

    return `
        <div class="dispatches-cell-icon-row">
            <span class="dispatches-cell-icon" aria-hidden="true">${window.UIIcons ? window.UIIcons.svg('building', { size: 14 }) : ''}</span>
            <span class="dispatches-cell-primary">${dspEscapeHtml(name)}</span>
        </div>
    `;
}

function dspRoomCell(row) {
    const name = row.room_name || row.room?.name;
    if (!name) {
        return dspMutedDash();
    }

    // Room capacity was retired from the system, so only the room name shows.
    return `<div class="dispatches-cell-primary">${dspEscapeHtml(name)}</div>`;
}

function dspItemsCell(row) {
    const count = Number(row.item_count || 0);
    const label = `${count} Item${count === 1 ? '' : 's'}`;

    return `
        <div class="dispatches-cell-primary">${dspEscapeHtml(label)}</div>
        <button type="button" class="dispatches-view-items-btn" data-dispatch-id="${dspEscapeHtml(row.id)}" data-dispatch-code="${dspEscapeHtml(row.dispatch_code)}">View Items</button>
    `;
}

function dspApproverCell(row) {
    if (!row.approved_by_name) {
        return '<span class="dispatches-muted-cell">Not yet approved</span>';
    }

    const role = dspRoleLabel(row.approved_by_user?.role);
    const secondary = role ? `<div class="dispatches-cell-secondary">${dspEscapeHtml(role)}</div>` : '';

    return `
        <div class="dispatches-cell-primary">${dspEscapeHtml(row.approved_by_name)}</div>
        ${secondary}
    `;
}

// TASK 13.1 §2 — "Release Personnel" column. Everything rendered here is
// already on the list payload (release_assigned_to_name is an appended
// attribute; release_assigned_to_user is eager-loaded by
// DispatchController::index()), so this adds no API call and no new endpoint.
//
// The department line is deliberately conservative. The assignee's OWN
// department name is not in the payload — only their department_id is — and
// eager-loading the relation would be a backend change this task excludes. In
// this workflow the assignee always belongs to the dispatch's department, so
// dispatch.department_name IS their department; but rather than assume that,
// the ids are compared and the caption is simply omitted when they disagree.
// Better a missing line than a confidently wrong one.
function dspAssigneeDepartment(row) {
    const assignee = row.release_assigned_to_user;
    const deptName = row.department_name || row.department?.name;
    if (!assignee || !deptName) return null;

    const a = assignee.department_id === null || assignee.department_id === undefined ? null : Number(assignee.department_id);
    const d = row.department_id === null || row.department_id === undefined ? null : Number(row.department_id);
    return a !== null && a === d ? deptName : null;
}

// TASK 13.2 §7 — the RELEASE state, which is a different fact from the
// dispatch status in the adjacent Status column: that column says where the
// dispatch is, this says what the assigned person can do about it right now.
// Mirrors ddAssignmentStatus() in dispatch-detail.php and opsAssignmentStatus()
// in maintenance-dashboard.php character for character, so one dispatch reads
// identically on all three surfaces. Every tone maps onto a badge class that
// already exists in color-scheme.css — no new colors.
function dspAssignmentStatus(row) {
    const status = String(row.status || '').toLowerCase();

    if (!row.release_assigned_to) return { label: 'Not Assigned', tone: 'pending' };
    if (status === 'cancelled') {
        return /Rejected:/i.test(String(row.notes || ''))
            ? { label: 'Rejected', tone: 'cancelled' }
            : { label: 'Cancelled', tone: 'cancelled' };
    }
    if (status === 'released') return { label: 'Released', tone: 'released' };
    if (status === 'approved') return { label: 'Ready for Release', tone: 'approved' };
    return { label: 'Waiting for Approval', tone: 'pending' };
}

function dspReleasePersonnelCell(row) {
    const name = row.release_assigned_to_name;
    if (!name) {
        return '<span class="dispatches-muted-cell">Not assigned</span>';
    }

    // Reuses the shared avatar trio from styles.css (.user-avatar-wrap /
    // .avatar-circle / .avatar-img) — the same one the sidebar renders — so
    // there is no second avatar component. The initial sits underneath and the
    // photo is layered over it, then removed if it fails to load.
    const avatarUrl = row.release_assigned_to_user?.avatar;
    const initial = String(name).trim().charAt(0).toUpperCase() || '?';
    // TASK 13.2 §9 — the photo now carries the person's name as alt text
    // instead of alt="", and the initial underneath is aria-hidden, so a
    // screen reader announces the name exactly once whichever one renders.
    const photo = avatarUrl
        ? `<img src="${dspEscapeHtml(avatarUrl)}" alt="${dspEscapeHtml(name)}" class="avatar-img dispatches-assignee-photo" onerror="this.remove();">`
        : '';
    const department = dspAssigneeDepartment(row);
    const state = dspAssignmentStatus(row);

    // TASK 78 — once a dispatch is released, dspAssignmentStatus() and the
    // adjacent Status column (dspStatusBadge) both say the literal word
    // "Released" — same fact, shown twice side by side. Every other state
    // (Not Assigned / Cancelled / Rejected / Ready for Release / Waiting for
    // Approval) still genuinely differs from the Status column, so only the
    // 'released' tone is suppressed here; the Status column one column over
    // already tells the reader this dispatch was released.
    const stateBadge = state.tone === 'released'
        ? ''
        : `<span class="badge badge-${dspEscapeHtml(state.tone)} dispatches-assignee-state">${dspEscapeHtml(state.label)}</span>`;

    return `
        <div class="dispatches-assignee">
            <span class="user-avatar-wrap dispatches-assignee-avatar">
                <span class="avatar-circle" aria-hidden="true">${dspEscapeHtml(initial)}</span>${photo}
            </span>
            <div class="dispatches-assignee-text">
                <div class="dispatches-cell-primary">${dspEscapeHtml(name)}</div>
                ${department ? `<div class="dispatches-cell-secondary">${dspEscapeHtml(department)}</div>` : ''}
                ${stateBadge}
            </div>
        </div>
    `;
}

function dspDateCell(row) {
    const { date, time } = dspFormatDateParts(row.created_at);
    if (!date) {
        return dspMutedDash();
    }

    return `
        <div class="dispatches-cell-primary">${dspEscapeHtml(date)}</div>
        ${time ? `<div class="dispatches-cell-secondary">${dspEscapeHtml(time)}</div>` : ''}
    `;
}

// ── Print ─────────────────────────────────────────────────────────────────
// Branded Dispatch Report via the shared SfmsPrint layout (PHILCST letterhead
// + logo, coupon-bond landscape fit, repeated header, page numbers, sign-off)
// — the same design as the All Reports printout. It prints EVERY dispatch
// matching the current Search/Status filters, not just the 20 on the visible
// page, and leaves out the Action column (a button has no meaning on paper).
const DSP_PRINT_ROLE_LABELS = {
    super_admin: 'Administrator',
    maintenance_admin: 'Head Maintenance',
    maintenance_staff: 'Maintenance Staff',
};
const DSP_PRINT_STATUS_TONES = { pending: 'amber', approved: 'blue', released: 'green', cancelled: 'red' };

async function fetchAllDispatchesForPrint() {
    const search = document.getElementById('dispatch-search').value.trim();
    const status = document.getElementById('dispatch-status').value;
    const rows = [];

    for (let page = 1, lastPage = 1; page <= lastPage && page <= 100; page += 1) {
        const params = new URLSearchParams({ page: String(page), per_page: '100' });
        if (search) params.set('search', search);
        if (status) params.set('status', status);

        const response = await fetch(`${DISPATCH_API_BASE}?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load dispatches for printing');
        }

        rows.push(...(Array.isArray(payload.data?.data) ? payload.data.data : []));
        lastPage = Number(payload.data?.last_page || 1);
    }

    return { rows, search, status };
}

async function printDispatchReport() {
    const button = document.getElementById('dispatch-print-btn');
    if (button) button.disabled = true;

    try {
        const { rows, search, status } = await fetchAllDispatchesForPrint();
        if (rows.length === 0) {
            dspNotify('No dispatches match the current filters, so there is nothing to print.', 'warning');
            return;
        }

        const esc = SfmsPrint.escape;
        const titleCase = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

        const rowsHtml = rows.map((row, index) => {
            const statusKey = String(row.status || '').toLowerCase();
            const { date, time } = dspFormatDateParts(row.created_at);
            const deptName = row.department_name || row.department?.name;
            const roomName = row.room_name || row.room?.name;
            const itemCount = Number(row.item_count || 0);
            const assignee = row.release_assigned_to_name;
            const approverRole = dspRoleLabel(row.approved_by_user?.role);

            return `
                <tr>
                    <td class="c-no">${index + 1}</td>
                    <td class="c-key">${esc(row.dispatch_code)}<span class="sub">${row.report?.title ? esc(row.report.title) : 'No linked report'}</span></td>
                    <td>${deptName ? esc(deptName) : '<span class="muted">—</span>'}</td>
                    <td class="c-strong">${roomName ? esc(roomName) : '<span class="muted">—</span>'}</td>
                    <td>${esc(`${itemCount} Item${itemCount === 1 ? '' : 's'}`)}</td>
                    <td>${SfmsPrint.pill(titleCase(statusKey) || 'Unknown', DSP_PRINT_STATUS_TONES[statusKey] || 'gray')}</td>
                    <td>${assignee ? `${esc(assignee)}<span class="sub">${esc(dspAssignmentStatus(row).label)}</span>` : '<span class="muted">Not assigned</span>'}</td>
                    <td>${row.approved_by_name ? `${esc(row.approved_by_name)}${approverRole ? `<span class="sub">${esc(approverRole)}</span>` : ''}` : '<span class="muted">Not yet approved</span>'}</td>
                    <td class="c-nowrap">${date ? esc(date) : '—'}${time ? `<span class="sub">${esc(time)}</span>` : ''}</td>
                </tr>
            `;
        }).join('');

        const count = (key) => rows.filter((r) => String(r.status || '').toLowerCase() === key).length;
        const totalItems = rows.reduce((sum, r) => sum + Number(r.item_count || 0), 0);
        const filterParts = [status ? `Status: ${titleCase(status)}` : 'All statuses'];
        if (search) filterParts.push(`Search: "${search}"`);

        const opened = SfmsPrint.open({
            title: 'Dispatch Report',
            subtitle: filterParts.join(' · '),
            preparedBy: DSP_CURRENT_USER_NAME,
            preparedRole: DSP_PRINT_ROLE_LABELS[DSP_CURRENT_USER_ROLE] || '',
            recordLabel: 'Dispatches',
            recordCount: rows.length,
            stats: [
                { label: 'Total Dispatches', value: rows.length, tone: 'purple' },
                { label: 'Pending', value: count('pending'), tone: 'amber' },
                { label: 'Approved', value: count('approved'), tone: 'blue' },
                { label: 'Released', value: count('released'), tone: 'green' },
                { label: 'Items Dispatched', value: totalItems, tone: 'violet' },
            ],
            columns: [
                { label: 'No.', width: '4%' },
                { label: 'Dispatch Code', width: '15%' },
                { label: 'Department', width: '10%' },
                { label: 'Room', width: '12%' },
                { label: 'Items', width: '7%' },
                { label: 'Status', width: '9%' },
                { label: 'Release Personnel', width: '17%' },
                { label: 'Approved By', width: '14%' },
                { label: 'Date', width: '12%' },
            ],
            rowsHtml,
        });

        if (!opened) {
            dspNotify('Unable to open the print preview. Please allow pop-ups for this site.', 'warning');
        }
    } catch (error) {
        dspNotify(error.message || 'Unable to print dispatches.');
    } finally {
        if (button) button.disabled = false;
    }
}

// Bulk "Print All Labels": stickers for every approved/released dispatch that
// matches the current Search/Status filters, so staff don't print one by one.
async function printAllDispatchLabels() {
    if (!window.DispatchLabels) return;
    const button = document.getElementById('dispatch-print-labels-btn');
    if (button) button.disabled = true;
    try {
        await DispatchLabels.printMany(async () => {
            const { rows } = await fetchAllDispatchesForPrint();
            return rows.filter((row) => DispatchLabels.isPrintable(row.status)).map((row) => row.id);
        }, DISPATCH_API_BASE);
    } finally {
        if (button) button.disabled = false;
    }
}

async function loadDispatches() {
    const search = document.getElementById('dispatch-search').value.trim();
    const status = document.getElementById('dispatch-status').value;

    const listContainer = document.getElementById('dispatch-list-container');
    if (listContainer) listContainer.innerHTML = getDispatchLoadingMarkup();

    const params = new URLSearchParams({ page: String(dispatchPage), per_page: '20' });
    if (search) params.set('search', search);
    if (status) params.set('status', status);

    try {
        const fetcher = window.Components && typeof Components.fetchJson === 'function'
            ? Components.fetchJson
            : async (url, options) => {
                const response = await fetch(url, options);
                return { response, data: await response.json() };
            };

        const { response, data: payload } = await fetcher(`${DISPATCH_API_BASE}?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load dispatches');
        }

        const paginator = payload.data;
        const rows = Array.isArray(paginator?.data) ? paginator.data : [];
        dispatchPage = Number(paginator?.current_page || 1);
        dispatchLastPage = Number(paginator?.last_page || 1);

        const container = document.getElementById('dispatch-list-container');

        if (rows.length === 0) {
            container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>No dispatches found.</strong><span>Try adjusting your filters or create a new dispatch.</span></div>';
        } else {
            let html = '<div class="table-responsive"><table class="table dispatches-table ui-fade-in"><thead><tr>'
                + '<th>Dispatch Code</th><th>Department</th><th>Room</th><th>Items</th>'
                + '<th>Status</th><th>Release Personnel</th><th>Approved By</th><th>Date</th><th class="dispatches-table__action-head">Action</th>'
                + '</tr></thead><tbody>';

            rows.forEach((row) => {
                html += '<tr>';
                html += `<td class="dispatches-table__code-cell">${dspCodeCell(row)}</td>`;
                html += `<td class="dispatches-table__dept-cell">${dspDepartmentCell(row)}</td>`;
                html += `<td class="dispatches-table__room-cell">${dspRoomCell(row)}</td>`;
                html += `<td class="dispatches-table__items-cell">${dspItemsCell(row)}</td>`;
                html += `<td class="dispatches-table__status-cell">${dspStatusBadge(row.status)}</td>`;
                html += `<td class="dispatches-table__assignee-cell">${dspReleasePersonnelCell(row)}</td>`;
                html += `<td class="dispatches-table__approver-cell">${dspApproverCell(row)}</td>`;
                html += `<td class="dispatches-table__date-cell">${dspDateCell(row)}</td>`;
                html += `<td class="dispatches-table__action-cell">
                    <a class="dispatches-table__view-btn" href="${DISPATCH_PAGE_BASE}/${row.id}">
                        <svg class="dispatches-table__view-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                        <span>View</span>
                    </a>
                </td>`;
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        }

        document.getElementById('dispatch-page-info').textContent = `Page ${dispatchPage} / ${dispatchLastPage}`;
        document.getElementById('dispatch-prev').disabled = dispatchPage <= 1;
        document.getElementById('dispatch-next').disabled = dispatchPage >= dispatchLastPage;
    } catch (error) {
        document.getElementById('dispatch-list-container').innerHTML =
            '<div class="ui-empty-state"><strong>Failed to load dispatches.</strong></div>';
        dspNotify(error.message || 'Unable to load dispatches.');
    }
}

// TASK 6 — "View Items" modal: fetches the existing single-dispatch
// endpoint on demand and lists item name / quantity. No new endpoint.
async function dspOpenItemsModal(dispatchId, dispatchCode) {
    const modal = document.getElementById('dispatch-items-modal');
    const body = document.getElementById('dispatch-items-modal-body');
    const title = document.getElementById('dispatch-items-modal-title');

    title.textContent = dispatchCode ? `Items — ${dispatchCode}` : 'Dispatch Items';
    body.innerHTML = `
        <div class="ui-skeleton-list">
            <div class="ui-skeleton-row w-90"></div>
            <div class="ui-skeleton-row w-75"></div>
        </div>
    `;
    modal.style.display = 'flex';
    modal.removeAttribute('aria-hidden');

    try {
        const response = await fetch(`${DISPATCH_API_BASE}/${dispatchId}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load items');
        }

        const items = Array.isArray(payload.data?.dispatch?.items) ? payload.data.dispatch.items : [];

        if (items.length === 0) {
            body.innerHTML = '<p class="dispatches-muted-cell">No items recorded for this dispatch.</p>';
            return;
        }

        body.innerHTML = '<ul class="dispatches-items-list">' + items.map((it) => `
            <li class="dispatches-items-list__row">
                <span class="dispatches-items-list__name">${dspEscapeHtml(it.item?.name ?? 'Unknown item')}</span>
                <span class="dispatches-items-list__qty">Qty: ${dspEscapeHtml(it.quantity)}</span>
            </li>
        `).join('') + '</ul>';
    } catch (error) {
        body.innerHTML = '<p class="dispatches-muted-cell">Failed to load items for this dispatch.</p>';
    }
}

function dspCloseItemsModal() {
    const modal = document.getElementById('dispatch-items-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

// TASK 13.1 §4 — the "My Pending Releases" widget on the Maintenance Staff
// dashboard deep-links here with ?status=approved. Seeding the existing filter
// controls from the query string (instead of adding a separate "assigned to
// me" mode) means the arriving user sees the filter that produced their view
// and can clear or change it like any other. Nothing is trusted from the URL
// beyond selecting an option that already exists in the <select>: the API
// re-validates the status, and the list is ALREADY scoped server-side to the
// staff member's own assignments — this changes presentation only, never
// visibility.
function dspApplyQueryStringFilters() {
    const params = new URLSearchParams(window.location.search);

    const status = String(params.get('status') || '').toLowerCase();
    const statusSelect = document.getElementById('dispatch-status');
    if (status && statusSelect && Array.from(statusSelect.options).some((o) => o.value === status)) {
        statusSelect.value = status;
    }

    const search = params.get('search');
    if (search) {
        document.getElementById('dispatch-search').value = search;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    dspApplyQueryStringFilters();

    // TASK 98.1 — live (debounced) search-as-you-type, reusing the existing
    // loadDispatches()/params.set('search', ...) path already wired to the
    // backend's `search` (dispatch_code LIKE) parameter. The pre-existing
    // Enter-key handler is kept below (and clears any pending debounce) so
    // pressing Enter still searches immediately, exactly as before.
    document.getElementById('dispatch-search').addEventListener('input', () => {
        clearTimeout(dispatchSearchDebounce);
        dispatchSearchDebounce = setTimeout(() => {
            dispatchPage = 1;
            loadDispatches();
        }, 300);
    });

    document.getElementById('dispatch-search').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            clearTimeout(dispatchSearchDebounce);
            dispatchPage = 1;
            loadDispatches();
        }
    });

    document.getElementById('dispatch-status').addEventListener('change', () => {
        dispatchPage = 1;
        loadDispatches();
    });

    document.getElementById('dispatch-filter-clear').addEventListener('click', () => {
        clearTimeout(dispatchSearchDebounce);
        document.getElementById('dispatch-search').value = '';
        document.getElementById('dispatch-status').value = '';
        dispatchPage = 1;
        loadDispatches();
    });

    document.getElementById('dispatch-print-btn').addEventListener('click', printDispatchReport);
    document.getElementById('dispatch-print-labels-btn').addEventListener('click', printAllDispatchLabels);

    document.getElementById('dispatch-prev').addEventListener('click', () => {
        if (dispatchPage > 1) {
            dispatchPage -= 1;
            loadDispatches();
        }
    });

    document.getElementById('dispatch-next').addEventListener('click', () => {
        if (dispatchPage < dispatchLastPage) {
            dispatchPage += 1;
            loadDispatches();
        }
    });

    // Event delegation: table rows are re-rendered on every load, so the
    // "View Items" buttons are bound once on the stable container instead
    // of being re-bound after every render.
    document.getElementById('dispatch-list-container').addEventListener('click', (e) => {
        const btn = e.target.closest('.dispatches-view-items-btn');
        if (btn) {
            dspOpenItemsModal(btn.dataset.dispatchId, btn.dataset.dispatchCode);
        }
    });

    document.getElementById('dispatch-items-modal-close').addEventListener('click', dspCloseItemsModal);
    document.getElementById('dispatch-items-modal-dismiss').addEventListener('click', dspCloseItemsModal);
    document.getElementById('dispatch-items-modal').addEventListener('click', (e) => {
        if (e.target === document.getElementById('dispatch-items-modal')) dspCloseItemsModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') dspCloseItemsModal();
    });

    loadDispatches();
});
</script>

<style>
.dispatches-page {
    --dispatch-bg: var(--app-bg);
    --dispatch-surface: #ffffff;
    --dispatch-surface-muted: #f8fafc;
    --dispatch-surface-header: #f8fafc;
    --dispatch-border: #dbe3f0;
    --dispatch-border-strong: #cbd5e1;
    --dispatch-text: #0f172a;
    --dispatch-text-muted: #64748b;
    --dispatch-text-faint: #94a3b8;
    --dispatch-row-odd: #ffffff;
    --dispatch-row-even: #f8fafc;
    --dispatch-row-hover: #f5f3ff;
    --dispatch-badge-bg: rgba(109, 40, 217, 0.1);
    --dispatch-badge-border: rgba(109, 40, 217, 0.16);
    --dispatch-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
    --dispatch-accent: #7c3aed;
    color: var(--dispatch-text);
}

:root[data-theme-resolved='dark'] .dispatches-page {
    --dispatch-surface: #0f172a;
    --dispatch-surface-muted: #111c31;
    --dispatch-surface-header: #13203a;
    --dispatch-border: #23314b;
    --dispatch-border-strong: #31415d;
    --dispatch-text: #e2e8f0;
    --dispatch-text-muted: #94a3b8;
    --dispatch-text-faint: #64748b;
    --dispatch-row-odd: #0f172a;
    --dispatch-row-even: #111c31;
    --dispatch-row-hover: #16233d;
    --dispatch-badge-bg: rgba(139, 92, 246, 0.16);
    --dispatch-badge-border: rgba(139, 92, 246, 0.24);
    --dispatch-shadow: 0 22px 48px rgba(2, 8, 23, 0.28);
    --dispatch-accent: #a78bfa;
}

.dispatches-page__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 20px;
}

.dispatches-page__title-group {
    max-width: 720px;
}

.dispatches-page__eyebrow {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #7c3aed;
}

.dispatches-page__title {
    margin: 0;
    font-size: clamp(1.75rem, 2.4vw, 2.35rem);
    line-height: 1.05;
    color: var(--dispatch-text);
}

.dispatches-page__description {
    margin: 10px 0 0;
    max-width: 62ch;
    color: var(--dispatch-text-muted);
    font-size: 0.98rem;
}

.dispatches-page__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 12px;
}

.dispatches-panel {
    background: var(--dispatch-surface);
    border: 1px solid var(--dispatch-border);
    border-radius: 18px;
    box-shadow: var(--dispatch-shadow);
    overflow: hidden;
}

.dispatches-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(190px, 0.8fr) auto;
    gap: 14px;
    padding: 22px 26px;
    border-bottom: 1px solid var(--dispatch-border);
    background: color-mix(in srgb, var(--dispatch-surface) 86%, var(--dispatch-surface-muted));
}

.dispatches-toolbar__label {
    display: block;
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--dispatch-text-muted);
}

.dispatches-toolbar__control,
.dispatches-page__primary-action,
.dispatches-page__secondary-action,
.dispatches-page__ghost-action {
    min-height: 44px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

.dispatches-toolbar__control {
    width: 100%;
    padding: 0 14px;
    background: var(--dispatch-surface);
    color: var(--dispatch-text);
    border: 1px solid var(--dispatch-border-strong);
    box-shadow: none;
}

.dispatches-toolbar__control:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.12);
    transform: none;
}

.dispatches-page__primary-action {
    padding: 10px 18px;
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
    border: 1px solid rgba(109, 40, 217, 0.38);
    color: #ffffff;
    box-shadow: none;
}

.dispatches-page__primary-action:hover {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
}

.dispatches-page__secondary-action,
.dispatches-page__ghost-action {
    padding: 10px 16px;
    background: var(--dispatch-surface);
    color: var(--dispatch-text);
    border: 1px solid var(--dispatch-border-strong);
    box-shadow: none;
}

.dispatches-page__secondary-action:hover,
.dispatches-page__ghost-action:hover {
    background: var(--dispatch-surface-muted);
    border-color: #a78bfa;
}

.dispatches-toolbar__action {
    display: flex;
    align-items: end;
}

.dispatches-table-panel {
    padding: 0;
}

.dispatches-table-panel__header {
    padding: 26px 26px 18px;
    border-bottom: 1px solid var(--dispatch-border);
    background: var(--dispatch-surface);
}

.dispatches-table-panel__title {
    margin: 0;
    font-size: 1.08rem;
    font-weight: 700;
    color: var(--dispatch-text);
}

.dispatches-table-panel__description {
    margin: 8px 0 0;
    color: var(--dispatch-text-muted);
    font-size: 0.93rem;
}

.dispatches-table-shell {
    padding: 0 26px 26px;
}

.dispatches-table-shell .ui-empty-state {
    margin-top: 24px;
    background: color-mix(in srgb, var(--dispatch-surface-muted) 88%, transparent);
    border-color: var(--dispatch-border-strong);
    color: var(--dispatch-text-muted);
}

.dispatches-table-shell .ui-empty-state strong {
    color: var(--dispatch-text);
}

.dispatches-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    padding-top: 22px;
}

/* TASK 6 — Enterprise Dispatch Table Redesign: taller, roomier rows,
   stronger header contrast, and per-column primary/secondary text
   hierarchy replace the previous single-line, cramped table. */
/* Fit-to-screen pass: the table used to need ~1450px (a 1040px floor, fixed
   column minimums, 18px cell padding and non-wrapping headers), which pushed
   the Action column off-screen at 1920px with the sidebar open. It now fits
   the standard desktop content width; the 880px floor only kicks in on much
   narrower screens, where .table-responsive's horizontal scroll takes over. */
.dispatches-page .dispatches-table {
    width: 100%;
    min-width: 880px;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--dispatch-surface);
    border: 1px solid var(--dispatch-border);
    border-radius: 14px;
    overflow: hidden;
}

.dispatches-page .dispatches-table thead,
.dispatches-page .dispatches-table thead tr,
.dispatches-page .dispatches-table thead th {
    background: var(--dispatch-surface-header);
}

.dispatches-page .dispatches-table thead {
    display: table-header-group;
}

.dispatches-page .dispatches-table thead tr {
    display: table-row;
}

.dispatches-page .dispatches-table thead th {
    display: table-cell;
}

/* Headers may wrap ("RELEASE / PERSONNEL") so a label is never what decides
   a column's width. */
.dispatches-page .dispatches-table th {
    padding: 14px 12px;
    border: 0;
    border-bottom: 1px solid var(--dispatch-border);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    line-height: 1.3;
    text-transform: uppercase;
    color: var(--dispatch-text-muted);
    white-space: normal;
    vertical-align: bottom;
}

.dispatches-table__action-head {
    text-align: center;
}

.dispatches-page .dispatches-table tbody tr {
    display: table-row;
    background: var(--dispatch-row-odd);
    transition: background-color 160ms ease;
}

.dispatches-page .dispatches-table tbody tr:nth-child(even) {
    background: var(--dispatch-row-even);
}

.dispatches-page .dispatches-table tbody tr:nth-child(odd) {
    background: var(--dispatch-row-odd);
}

.dispatches-page .dispatches-table tbody tr:hover {
    background: var(--dispatch-row-hover);
}

.dispatches-page .dispatches-table td {
    display: table-cell;
    padding: 14px 12px;
    border: 0;
    border-bottom: 1px solid var(--dispatch-border);
    background: transparent;
    color: var(--dispatch-text);
    vertical-align: middle;
    line-height: 1.35;
}

.dispatches-page .dispatches-table tbody tr:last-child td {
    border-bottom: 0;
}

.dispatches-emphasis-cell {
    font-weight: 700;
    color: var(--dispatch-text);
}

.dispatches-muted-cell {
    color: var(--dispatch-text-faint);
}

/* Dispatch Code column — primary/secondary hierarchy, the table's
   strongest visual anchor. */
.dispatches-table__code-cell {
    min-width: 150px;
}

/* nowrap: the code used to break after "DSP-", splitting one identifier over
   two lines. */
.dispatches-code-primary {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--dispatch-accent);
    letter-spacing: 0.01em;
    white-space: nowrap;
}

.dispatches-code-secondary {
    margin-top: 4px;
    font-size: 12px;
    color: var(--dispatch-text-faint);
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dispatches-cell-icon-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dispatches-cell-icon {
    font-size: 14px;
    line-height: 1;
    opacity: 0.85;
}

.dispatches-cell-primary {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--dispatch-text);
}

.dispatches-cell-secondary {
    margin-top: 3px;
    font-size: 12px;
    color: var(--dispatch-text-faint);
}

/* Items column */
.dispatches-table__items-cell {
    min-width: 90px;
}

.dispatches-view-items-btn {
    margin-top: 5px;
    padding: 0;
    border: 0;
    background: none;
    font-size: 12px;
    font-weight: 600;
    color: var(--dispatch-accent);
    cursor: pointer;
}

.dispatches-view-items-btn:hover {
    text-decoration: underline;
}

.dispatches-view-items-btn:focus-visible {
    outline: 2px solid var(--dispatch-accent);
    outline-offset: 2px;
    border-radius: 4px;
}

/* Status column */
.dispatches-status-badge {
    display: inline-flex !important;
    align-items: center;
    padding: 6px 12px !important;
    border-radius: 999px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: 0.03em !important;
    text-transform: none !important;
    box-shadow: none;
}

.dispatches-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block;
    margin-right: 6px;
    flex-shrink: 0;
}

/* Release Personnel column — TASK 13.1 §2. Reuses the existing
   .dispatches-cell-primary / .dispatches-cell-secondary hierarchy and the
   shared .user-avatar-wrap / .avatar-circle / .avatar-img avatar from
   styles.css, so this column introduces no new component and no new colour.
   The avatar is scaled down to 32px to suit a table row. */
.dispatches-table__assignee-cell {
    min-width: 150px;
}

/* TASK 13.2 §7 — flex-start rather than center now that the cell can stack
   three lines; centering would have floated the avatar against the middle
   line and broken the top alignment shared with every other column. */
.dispatches-assignee {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.dispatches-assignee-avatar {
    position: relative;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    font-size: 13px;
}

/* The initial-circle defines the box and the photo is layered over it, so a
   404 on the photo (its onerror removes the <img>) reveals the initial
   underneath instead of a broken-image frame. */
.dispatches-assignee-photo {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.dispatches-assignee-text {
    min-width: 0;
}

/* TASK 13.2 §7 — the release state. Held one step below the Status column's
   badge (10px vs 11px, tighter padding, no status dot) on purpose: the two
   sit side by side, and the release state is the supporting fact. Same
   .badge-* classes, so no new colors enter the table. */
.dispatches-assignee-state {
    display: inline-block;
    margin-top: 4px;
    font-size: 10px;
    padding: 2px 8px;
    white-space: nowrap;
}

/* Date column */
.dispatches-table__date-cell {
    min-width: 100px;
}

/* Action column */
.dispatches-table__action-cell {
    text-align: center;
}

/* View + Labels sit side by side; stack cleanly if the column is narrow. */
.dispatches-table__labels-btn {
    margin-left: 6px;
    cursor: pointer;
    font-family: inherit;
}

.dispatches-table__view-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 36px;
    padding: 8px 16px;
    border-radius: 10px;
    border: 1px solid var(--dispatch-badge-border);
    background: var(--dispatch-badge-bg);
    color: #7c3aed;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background-color 160ms ease, border-color 160ms ease, transform 160ms ease;
}

.dispatches-table__view-icon {
    flex-shrink: 0;
}

.dispatches-table__view-btn:hover {
    background: rgba(124, 58, 237, 0.16);
    border-color: rgba(124, 58, 237, 0.24);
    color: #6d28d9;
    transform: translateY(-1px);
}

.dispatches-table__view-btn:focus-visible {
    outline: 2px solid #7c3aed;
    outline-offset: 2px;
}

:root[data-theme-resolved='dark'] .dispatches-page .dispatches-table__view-btn {
    color: #c4b5fd;
}

:root[data-theme-resolved='dark'] .dispatches-page .dispatches-table__view-btn:hover {
    color: #ede9fe;
}

.dispatches-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 0 26px 26px;
}

.dispatches-pagination__info {
    color: var(--dispatch-text-muted);
    font-size: 0.95rem;
}

/* View Items modal */
.dispatches-items-modal {
    max-width: 420px;
}

.dispatches-items-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.dispatches-items-list__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 14px;
    border: 1px solid var(--dispatch-border);
    border-radius: 10px;
    background: var(--dispatch-surface-muted);
}

.dispatches-items-list__name {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--dispatch-text);
}

.dispatches-items-list__qty {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--dispatch-text-muted);
    white-space: nowrap;
}

@media (max-width: 980px) {
    .dispatches-page__header {
        flex-direction: column;
    }

    .dispatches-page__actions {
        justify-content: flex-start;
    }

    .dispatches-toolbar {
        grid-template-columns: minmax(0, 1fr) minmax(180px, 0.7fr);
    }

    .dispatches-toolbar__action {
        grid-column: 1 / -1;
        justify-content: flex-start;
    }
}

@media (max-width: 768px) {
    .dispatches-toolbar,
    .dispatches-table-panel__header,
    .dispatches-table-shell,
    .dispatches-pagination {
        padding-left: 16px;
        padding-right: 16px;
    }

    .dispatches-toolbar {
        grid-template-columns: 1fr;
    }

    .dispatches-page__actions {
        width: 100%;
    }

    .dispatches-page__actions .btn {
        flex: 1 1 180px;
    }

    .dispatches-pagination {
        flex-wrap: wrap;
        justify-content: flex-start;
    }
}

/* Tablets only: keep a real, horizontally scrollable table. On phones
   (<= 640px) the table becomes one card per row instead — see
   assets/css/mobile-table-cards.css, which also supplies the column labels
   that the rules below would otherwise hide. */
@media (min-width: 641px) and (max-width: 768px) {
    .dispatches-page .dispatches-table {
        min-width: 900px;
    }

    .dispatches-page .dispatches-table thead {
        display: table-header-group !important;
    }

    .dispatches-page .dispatches-table tbody tr {
        display: table-row !important;
        margin-bottom: 0;
        border: 0;
        border-radius: 0;
    }

    .dispatches-page .dispatches-table th,
    .dispatches-page .dispatches-table td {
        display: table-cell !important;
        text-align: left !important;
        padding-left: 16px !important;
    }

    .dispatches-page .dispatches-table td::before,
    .dispatches-page .dispatches-table td::after {
        content: none !important;
        display: none !important;
    }
}

@media (max-width: 540px) {
    .dispatches-page__actions .btn {
        width: 100%;
    }
}
</style>

<style media="print">
  /* Page chrome — sidebar/nav/top bar are already hidden globally by
     sidebar.css's own @media print block; the rules below cover what that
     shared block does not: this page's own header/toolbar/pagination/
     modal, the utility bar, and the mobile hamburger toggle (sidebar.css's
     print block only hides .sidebar-toggle, not the actual
     #sidebarToggleMobile / .sidebar-toggle-mobile button rendered by
     sidebar.php, so it was slipping through onto the printed page). */
  aside, nav, .sidebar, header, .utility-bar,
  #sidebarToggleMobile,
  .sidebar-toggle-mobile,
  .dispatches-page__header,
  .dispatches-toolbar,
  .dispatches-pagination,
  #dispatch-items-modal,
  .app-footer,
  .btn { display: none !important; }

  .print-only { display: block !important; }

  /* Action column is web-only (View button/link) — never part of the
     printed report. Targets the existing markup classes from
     loadDispatches(); no HTML/JS change needed. */
  .dispatches-table__action-head,
  .dispatches-table__action-cell { display: none !important; }

  /* styles.css's body[data-user-role] main.container rule (and
     utility-bar.css's matching padding-top rule) reserve a 260px sidebar
     gutter and a fixed-utility-bar top offset — both still applied here
     since only the sidebar/utility-bar *elements* are hidden above, not
     the space this page's <main> was pushed over to leave for them. Left
     unreset, the table was rendering into a ~775px-wide column instead of
     the full page, which is what was forcing every column below its
     content's minimum width and breaking words vertically. */
  body[data-user-role] main.dispatches-page,
  main.dispatches-page {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
  }

  .dispatches-panel,
  .dispatches-table-panel {
    box-shadow: none !important;
    border: none !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
  }

  .dispatches-table-panel__header {
    display: none;
  }

  /* Let the table use the full printable width instead of the 1040px
     workstation min-width (dispatches.php:813-823) that previously forced
     horizontal overflow/cut-off columns when printed. */
  .dispatches-page .table-responsive {
    overflow: visible !important;
    padding-top: 0 !important;
  }

  .dispatches-page .dispatches-table {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed;
    border: 1px solid #999 !important;
    border-radius: 0 !important;
    font-size: 10.5px;
  }

  .dispatches-page .dispatches-table th,
  .dispatches-page .dispatches-table td {
    border: 1px solid #ccc !important;
    padding: 6px 8px !important;
    white-space: normal !important;
    overflow-wrap: break-word;
    word-break: break-word;
  }

  .dispatches-page .dispatches-table th {
    background: #f0f0f0 !important;
    color: #000 !important;
    white-space: normal !important;
  }

  body { background: white; color: black; }

  /* table-layout: fixed sizes columns from these percentages instead of
     the screen-mode content widths, so the 8 remaining columns (the 9th,
     Action, is display:none above and contributes no column) always sum
     to exactly one page width regardless of cell content length. Items
     and the two people columns get the most room since they carry the
     longest wrapped text; Status/Room/Date need the least. */
  .dispatches-page .dispatches-table th:nth-child(1),
  .dispatches-page .dispatches-table td:nth-child(1) { width: 12%; }
  .dispatches-page .dispatches-table th:nth-child(2),
  .dispatches-page .dispatches-table td:nth-child(2) { width: 12%; }
  .dispatches-page .dispatches-table th:nth-child(3),
  .dispatches-page .dispatches-table td:nth-child(3) { width: 10%; }
  .dispatches-page .dispatches-table th:nth-child(4),
  .dispatches-page .dispatches-table td:nth-child(4) { width: 20%; }
  .dispatches-page .dispatches-table th:nth-child(5),
  .dispatches-page .dispatches-table td:nth-child(5) { width: 8%; }
  .dispatches-page .dispatches-table th:nth-child(6),
  .dispatches-page .dispatches-table td:nth-child(6) { width: 14%; }
  .dispatches-page .dispatches-table th:nth-child(7),
  .dispatches-page .dispatches-table td:nth-child(7) { width: 14%; }
  .dispatches-page .dispatches-table th:nth-child(8),
  .dispatches-page .dispatches-table td:nth-child(8) { width: 10%; }

  /* These screen-mode min-widths (190/130/190/120px) are what fights
     table-layout: fixed and forces the table past the page width — reset
     them so the percentage widths above are the only sizing in effect. */
  .dispatches-page .dispatches-table__code-cell,
  .dispatches-page .dispatches-table__items-cell,
  .dispatches-page .dispatches-table__assignee-cell,
  .dispatches-page .dispatches-table__date-cell {
    min-width: 0 !important;
  }

  /* These were sized/truncated for a fixed-pixel screen column; in a
     percentage-wide print column they need to wrap instead of ellipsing
     or clipping. */
  .dispatches-code-secondary {
    max-width: none !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
  }

  .dispatches-assignee-state {
    white-space: normal !important;
  }

  /* Eight remaining columns (Dispatch Code, Department, Room, Items,
     Status, Release Personnel, Approved By, Date) are too wide for
     portrait — landscape gives each column room to wrap without
     shrinking text past readability. */
  @page {
    size: landscape;
    margin: 12mm;
  }

  /* Browser print (Ctrl+P) of the page itself: the Action column's View
     button has no meaning on paper. The Print button uses the branded
     printDispatchReport() layout instead, which omits it too. */
  .dispatches-page .dispatches-table__action-head,
  .dispatches-page .dispatches-table__action-cell {
    display: none !important;
  }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
