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
$allowedRoles = ['super_admin', 'maintenance_admin', 'maintenance_staff'];
if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

// DISPOSAL ARCHIVE — only Head Maintenance and the Administrator may confirm
// a physical disposal (mirrors ReplacementTrackingController::DISPOSAL_ROLES).
// Maintenance Staff keep the read-only view they already had.
$canConfirmDisposal = in_array($user['role'] ?? '', ['maintenance_admin', 'super_admin'], true);

$pageTitle = 'Replacement Tracking - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.css">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.inline.css?v=20260921-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/inventory.inline.css?v=20260921-2">
<?php /* Token bumped with inventory.php's: this page shares
         inventory-redesign.css AND the `main.inventory-page` class, so the
         container border token applies here too. Leaving the old token would
         serve this page a cached grey-bordered copy while inventory.php —
         one click away via its Replacement Tracking button — shows lavender. */ ?>
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/inventory-redesign.css?v=20260921-3">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/replacement-tracking.inline.css?v=20260921-2">

<main class="container maintenance-admin-dashboard-page inventory-page replacement-tracking-page">
    <div class="card inventory-page-header-card">
        <div class="card-body inventory-page-header-body">
            <div class="page-header inventory-header-row inventory-page-header-row">
                <div>
                    <h1 class="inventory-title">Replacement Tracking</h1>
                    <p class="text-muted inventory-subtitle">Track item replacement requests from pending approval through disposal.</p>
                </div>
                <div class="inventory-header-actions">
                    <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/inventory.php')); ?>" class="btn btn-secondary">Back to Inventory</a>
                </div>
            </div>
        </div>
    </div>

    <div class="replacement-summary-grid" id="replacement-summary-grid">
        <div class="summary-card replacement-summary-card tone-pending">
            <div class="summary-card-content">
                <h3 class="summary-card-title">Pending Replacement</h3>
                <div class="summary-card-value" id="replacement-summary-pending">0</div>
                <p class="summary-card-desc">Awaiting approval or stock deduction</p>
            </div>
        </div>
        <div class="summary-card replacement-summary-card tone-replaced">
            <div class="summary-card-content">
                <h3 class="summary-card-title">Replaced</h3>
                <div class="summary-card-value" id="replacement-summary-replaced">0</div>
                <p class="summary-card-desc">Replacement item already issued</p>
            </div>
        </div>
        <div class="summary-card replacement-summary-card tone-disposal">
            <div class="summary-card-content">
                <h3 class="summary-card-title">For Disposal</h3>
                <div class="summary-card-value" id="replacement-summary-disposal">0</div>
                <p class="summary-card-desc">Old items ready for disposal flow</p>
            </div>
        </div>
        <div class="summary-card replacement-summary-card tone-archived">
            <div class="summary-card-content">
                <h3 class="summary-card-title">Disposed (Archived)</h3>
                <div class="summary-card-value" id="replacement-summary-archived">0</div>
                <p class="summary-card-desc">Confirmed disposed — kept as history</p>
            </div>
        </div>
        <div class="summary-card replacement-summary-card tone-total">
            <div class="summary-card-content">
                <h3 class="summary-card-title">Tracked Requests</h3>
                <div class="summary-card-value" id="replacement-summary-total">0</div>
                <p class="summary-card-desc">Replacement workflow records</p>
            </div>
        </div>
    </div>

    <div class="card replacement-filters-card">
        <div class="card-header">
            <h3 class="inventory-section-title">Replacement Filters</h3>
        </div>
        <div class="card-body">
            <div class="replacement-filters-grid">
                <select id="replacement-filter-building" class="form-control">
                    <option value="">All Buildings</option>
                </select>
                <select id="replacement-filter-floor" class="form-control">
                    <option value="">All Floors</option>
                </select>
                <select id="replacement-filter-status" class="form-control">
                    <option value="">All Statuses</option>
                </select>
                <input type="date" id="replacement-filter-date-from" class="form-control" title="From date">
                <input type="date" id="replacement-filter-date-to" class="form-control" title="To date">
                <button type="button" class="btn btn-secondary replacement-clear-btn" id="replacement-clear-filters">Clear Filters</button>
            </div>
        </div>
    </div>

    <div class="replacement-cards-grid" id="replacement-cards-grid">
        <div class="ui-empty-state ui-fade-in">
            <strong>Loading replacement tracking...</strong>
            <span>Please wait while we collect replacement workflow records.</span>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const REPLACEMENT_TRACKING_API_BASE = window.SFMS_PUBLIC_URL('/api/replacement-tracking');
const CAN_CONFIRM_DISPOSAL = <?php echo $canConfirmDisposal ? 'true' : 'false'; ?>;

let replacementTrackingRecords = [];
let replacementTrackingFilters = {
    building: '',
    floor: '',
    status: '',
    date_from: '',
    date_to: ''
};

function replacementEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatReplacementDate(value) {
    if (!value) {
        return 'Pending';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return replacementEscapeHtml(value);
    }

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}

function formatReplacementStatusLabel(status) {
    return String(status || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function getReplacementStatusClass(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'disposed') return 'status-archived';
    if (normalized === 'replaced') return 'status-replaced';
    if (normalized === 'for_disposal') return 'status-disposal';
    return 'status-pending';
}

function buildLocationMarkup(record) {
    const parts = [record.building_name, record.floor_name, record.room_name].filter((value) => String(value || '').trim() !== '');
    if (!parts.length) {
        return replacementEscapeHtml(record.location_label || 'No location available');
    }

    return parts.map((value) => replacementEscapeHtml(value)).join(' • ');
}

function populateReplacementFilterOptions(filters) {
    const buildingSelect = document.getElementById('replacement-filter-building');
    const floorSelect = document.getElementById('replacement-filter-floor');
    const statusSelect = document.getElementById('replacement-filter-status');

    if (buildingSelect) {
        buildingSelect.innerHTML = '<option value="">All Buildings</option>' + (filters.buildings || [])
            .map((value) => `<option value="${replacementEscapeHtml(value)}">${replacementEscapeHtml(value)}</option>`)
            .join('');
    }

    if (floorSelect) {
        floorSelect.innerHTML = '<option value="">All Floors</option>' + (filters.floors || [])
            .map((value) => `<option value="${replacementEscapeHtml(value)}">${replacementEscapeHtml(value)}</option>`)
            .join('');
    }

    if (statusSelect) {
        statusSelect.innerHTML = '<option value="">All Statuses</option>' + (filters.statuses || [])
            .map((option) => `<option value="${replacementEscapeHtml(option.value)}">${replacementEscapeHtml(option.label)}</option>`)
            .join('');
    }
}

function updateReplacementSummary(records) {
    const summary = {
        pending_replacement: 0,
        replaced: 0,
        for_disposal: 0,
        disposed: 0,
        total: records.length
    };

    records.forEach((record) => {
        const key = String(record.tracking_status || 'pending_replacement');
        if (Object.prototype.hasOwnProperty.call(summary, key)) {
            summary[key] += 1;
        }
    });

    const pendingEl = document.getElementById('replacement-summary-pending');
    const replacedEl = document.getElementById('replacement-summary-replaced');
    const disposalEl = document.getElementById('replacement-summary-disposal');
    const archivedEl = document.getElementById('replacement-summary-archived');
    const totalEl = document.getElementById('replacement-summary-total');

    if (pendingEl) pendingEl.textContent = String(summary.pending_replacement);
    if (replacedEl) replacedEl.textContent = String(summary.replaced);
    if (disposalEl) disposalEl.textContent = String(summary.for_disposal);
    if (archivedEl) archivedEl.textContent = String(summary.disposed);
    if (totalEl) totalEl.textContent = String(summary.total);
}

function renderReplacementCards(records) {
    const container = document.getElementById('replacement-cards-grid');
    if (!container) {
        return;
    }

    if (!records.length) {
        container.innerHTML = `
            <div class="ui-empty-state ui-fade-in">
                <strong>No replacement records found.</strong>
                <span>Try adjusting the filters or wait for new replacement requests to be filed.</span>
            </div>
        `;
        updateReplacementSummary(records);
        return;
    }

    updateReplacementSummary(records);

    container.innerHTML = records.map((record) => {
        const isForDisposal = String(record.tracking_status) === 'for_disposal';
        const isDisposed = String(record.tracking_status) === 'disposed';

        let disposalSection = '';
        if (isDisposed) {
            disposalSection = `
                <div class="replacement-detail-grid replacement-disposal-info">
                    <div class="replacement-detail-block">
                        <span class="replacement-detail-label">Disposed on</span>
                        <span class="replacement-detail-copy">${replacementEscapeHtml(formatReplacementDate(record.disposed_at))}</span>
                    </div>
                    <div class="replacement-detail-block">
                        <span class="replacement-detail-label">Disposed by</span>
                        <span class="replacement-detail-copy">${replacementEscapeHtml(record.disposed_by || 'Unknown')}</span>
                    </div>
                    ${record.disposal_notes ? `
                    <div class="replacement-detail-block">
                        <span class="replacement-detail-label">Disposal notes</span>
                        <span class="replacement-detail-copy">${replacementEscapeHtml(record.disposal_notes)}</span>
                    </div>` : ''}
                </div>
            `;
        } else if (isForDisposal && CAN_CONFIRM_DISPOSAL) {
            disposalSection = `
                <div class="replacement-dispose-action">
                    <button type="button" class="btn btn-secondary replacement-dispose-btn" data-report-id="${replacementEscapeHtml(record.report_id)}">Mark as Disposed</button>
                </div>
            `;
        }

        return `
        <article class="replacement-card">
            <div class="replacement-card-head">
                <div>
                    <div class="replacement-card-kicker">Replacement Request #${replacementEscapeHtml(record.report_id)}</div>
                    <h3 class="replacement-card-title">${replacementEscapeHtml(record.item_name || record.title || 'Replacement Item')}</h3>
                </div>
                <span class="replacement-status-pill ${getReplacementStatusClass(record.tracking_status)}">${replacementEscapeHtml(formatReplacementStatusLabel(record.tracking_status))}</span>
            </div>
            <div class="replacement-card-location">${buildLocationMarkup(record)}</div>
            <div class="replacement-card-body">
                <div class="replacement-detail-block">
                    <span class="replacement-detail-label">Reason for replacement</span>
                    <strong class="replacement-detail-value">${replacementEscapeHtml(record.reason_for_replacement || 'Needs replacement')}</strong>
                </div>
                <div class="replacement-detail-block">
                    <span class="replacement-detail-label">Old item condition</span>
                    <span class="replacement-detail-copy">${replacementEscapeHtml(record.old_item_condition || 'Condition not recorded')}</span>
                </div>
                <div class="replacement-detail-block">
                    <span class="replacement-detail-label">New item details</span>
                    <span class="replacement-detail-copy">${replacementEscapeHtml(record.new_item_details || 'No replacement stock assigned')}</span>
                </div>
                <div class="replacement-detail-grid">
                    <div class="replacement-detail-block">
                        <span class="replacement-detail-label">Date replaced</span>
                        <span class="replacement-detail-copy">${replacementEscapeHtml(formatReplacementDate(record.date_replaced))}</span>
                    </div>
                    <div class="replacement-detail-block">
                        <span class="replacement-detail-label">Requested by</span>
                        <span class="replacement-detail-copy">${replacementEscapeHtml(record.requested_by || 'Unknown')}</span>
                    </div>
                    <div class="replacement-detail-block">
                        <span class="replacement-detail-label">Approved by</span>
                        <span class="replacement-detail-copy">${replacementEscapeHtml(record.approved_by || 'Pending approval')}</span>
                    </div>
                </div>
                ${disposalSection}
            </div>
        </article>
        `;
    }).join('');

    bindReplacementDisposeButtons();
}

// DISPOSAL ARCHIVE — confirms the physical disposal of the old, replaced
// item. Optional note is collected via the shared browser-dialog
// replacement (UI.systemPrompt if available; falls back to window.prompt so
// this page still works standalone). Re-fetches the full list afterward so
// the card moves from "For Disposal" into the "Disposed (Archived)" bucket
// using the server's own re-derived tracking_status, not a client-side guess.
function bindReplacementDisposeButtons() {
    document.querySelectorAll('.replacement-dispose-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const reportId = btn.getAttribute('data-report-id');

            let notes = '';
            if (window.UI && typeof window.UI.systemPrompt === 'function') {
                notes = await window.UI.systemPrompt({
                    title: 'Confirm disposal',
                    message: 'Mark this old replaced item as disposed? This is recorded permanently in the replacement history.',
                    placeholder: 'Optional note (e.g. turned over to Property Custodian)',
                }) || '';
            } else {
                notes = window.prompt('Mark this old replaced item as disposed?\nOptional note:') || '';
            }
            if (notes === null) {
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Marking...';

            try {
                const response = await fetch(`${REPLACEMENT_TRACKING_API_BASE}/${reportId}/dispose`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ notes: notes.trim() || null })
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Failed to mark item as disposed');
                }
                await loadReplacementTracking();
            } catch (error) {
                console.error('Mark as disposed error:', error);
                alert(error.message || 'Failed to mark item as disposed');
                btn.disabled = false;
                btn.textContent = 'Mark as Disposed';
            }
        });
    });
}

function applyReplacementTrackingFilters() {
    const filtered = replacementTrackingRecords.filter((record) => {
        if (replacementTrackingFilters.building && record.building_name !== replacementTrackingFilters.building) {
            return false;
        }

        if (replacementTrackingFilters.floor && record.floor_name !== replacementTrackingFilters.floor) {
            return false;
        }

        if (replacementTrackingFilters.status && record.tracking_status !== replacementTrackingFilters.status) {
            return false;
        }

        const recordDate = String(record.date_replaced || '').slice(0, 10);
        if (replacementTrackingFilters.date_from && recordDate && recordDate < replacementTrackingFilters.date_from) {
            return false;
        }

        if (replacementTrackingFilters.date_to && recordDate && recordDate > replacementTrackingFilters.date_to) {
            return false;
        }

        return true;
    });

    renderReplacementCards(filtered);
}

function bindReplacementFilters() {
    const buildingSelect = document.getElementById('replacement-filter-building');
    const floorSelect = document.getElementById('replacement-filter-floor');
    const statusSelect = document.getElementById('replacement-filter-status');
    const dateFromInput = document.getElementById('replacement-filter-date-from');
    const dateToInput = document.getElementById('replacement-filter-date-to');
    const clearButton = document.getElementById('replacement-clear-filters');

    buildingSelect?.addEventListener('change', () => {
        replacementTrackingFilters.building = buildingSelect.value;
        applyReplacementTrackingFilters();
    });

    floorSelect?.addEventListener('change', () => {
        replacementTrackingFilters.floor = floorSelect.value;
        applyReplacementTrackingFilters();
    });

    statusSelect?.addEventListener('change', () => {
        replacementTrackingFilters.status = statusSelect.value;
        applyReplacementTrackingFilters();
    });

    dateFromInput?.addEventListener('change', () => {
        replacementTrackingFilters.date_from = dateFromInput.value;
        applyReplacementTrackingFilters();
    });

    dateToInput?.addEventListener('change', () => {
        replacementTrackingFilters.date_to = dateToInput.value;
        applyReplacementTrackingFilters();
    });

    clearButton?.addEventListener('click', () => {
        replacementTrackingFilters = {
            building: '',
            floor: '',
            status: '',
            date_from: '',
            date_to: ''
        };

        if (buildingSelect) buildingSelect.value = '';
        if (floorSelect) floorSelect.value = '';
        if (statusSelect) statusSelect.value = '';
        if (dateFromInput) dateFromInput.value = '';
        if (dateToInput) dateToInput.value = '';

        applyReplacementTrackingFilters();
    });
}

async function loadReplacementTracking() {
    const container = document.getElementById('replacement-cards-grid');

    try {
        const response = await fetch(REPLACEMENT_TRACKING_API_BASE, { credentials: 'include' });
        const payload = await response.json();

        if (!payload.success || !payload.data) {
            throw new Error(payload.message || 'Failed to load replacement tracking');
        }

        replacementTrackingRecords = Array.isArray(payload.data.records) ? payload.data.records : [];
        populateReplacementFilterOptions(payload.data.filters || {});
        bindReplacementFilters();
        applyReplacementTrackingFilters();
    } catch (error) {
        console.error('Replacement tracking load error:', error);
        if (container) {
            container.innerHTML = `
                <div class="alert alert-danger">
                    Could not load replacement tracking right now.
                </div>
            `;
        }
    }
}

document.addEventListener('DOMContentLoaded', loadReplacementTracking);
</script>
