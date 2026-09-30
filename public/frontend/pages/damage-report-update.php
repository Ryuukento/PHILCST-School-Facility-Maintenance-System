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

$pageTitle = 'Update Damage Report - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container" style="margin-top:16px;">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2 id="update-page-title">Update Damage Report</h2>
                <p class="text-muted mb-0" id="update-page-subtitle">Adjust status, add repair notes, and record replacements.</p>
            </div>
            <div class="d-flex gap-sm">
                <a href="<?php echo htmlspecialchars(public_url('/damage-reports')); ?>" class="btn btn-secondary">Back to List</a>
                <a id="update-view-link" href="#" class="btn btn-primary">View Details</a>
            </div>
        </div>
        <div class="card-body">
            <div id="update-current-summary" style="margin-bottom:12px;"></div>
            <form id="damage-update-form">
                <div class="form-group">
                    <label for="damage-next-status">Status *</label>
                    <select id="damage-next-status" class="form-control" required>
                        <option value="pending">Pending</option>
                        <option value="under_review">Under Review</option>
                        <option value="repairing">Repairing</option>
                        <option value="repaired">Repaired</option>
                        <option value="replaced">Replaced</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="damage-update-repair-notes">Repair Notes</label>
                    <textarea id="damage-update-repair-notes" class="form-control" rows="4" placeholder="Add update notes, repair actions, or closure details..."></textarea>
                </div>

                <div id="replacement-fields" style="display:none;border:1px solid rgba(148,163,184,.25);padding:12px;border-radius:10px;margin-bottom:12px;">
                    <h4 style="margin-top:0;">Replacement Details</h4>
                    <div class="form-group">
                        <label for="replacement-item-search">Replacement Item *</label>
                        <input type="text" id="replacement-item-search" class="form-control" placeholder="Search replacement item...">
                        <input type="hidden" id="replacement-item-id">
                    </div>
                    <div class="form-group">
                        <label for="replacement-quantity">Replacement Quantity *</label>
                        <input type="number" id="replacement-quantity" class="form-control" min="1" value="1">
                    </div>
                </div>

                <div class="d-flex gap-sm">
                    <button type="submit" class="btn btn-primary" id="damage-update-save-btn">Save Update</button>
                    <a id="damage-update-cancel-link" href="#" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
let currentDamageReport = null;
const DAMAGE_REPORTS_API_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/api/damage-reports') : '/api/damage-reports';
const DAMAGE_REPORTS_PAGE_BASE = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/damage-reports') : '/damage-reports';

function updateNotify(message, type = 'danger') {
    // UI_BROWSER_DIALOG_REPLACEMENT — Components/UI are always loaded (see
    // includes/footer.php), so this always goes through the reusable
    // in-app modal; no window.alert() fallback.
    Components.alert(message, type);
}

function getUpdateId() {
    const params = new URLSearchParams(window.location.search);
    return Number(params.get('id') || 0);
}

function formatStatus(value) {
    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (s) => s.toUpperCase());
}

function initReplacementSelect() {
    new Components.SearchableSelect({
        inputId: 'replacement-item-search',
        hiddenId: 'replacement-item-id',
        endpoint: '/api/items?item_type=inventory_stock',
        displayKey: 'name',
    });
}

function bindStatusToggle() {
    const statusSelect = document.getElementById('damage-next-status');
    const replacementFields = document.getElementById('replacement-fields');

    const refresh = () => {
        replacementFields.style.display = statusSelect.value === 'replaced' ? 'block' : 'none';
    };

    statusSelect.addEventListener('change', refresh);
    refresh();
}

async function loadReport() {
    const id = getUpdateId();
    if (!id) {
        updateNotify('Invalid damage report ID.', 'warning');
        window.location.href = DAMAGE_REPORTS_PAGE_BASE;
        return;
    }

    document.getElementById('update-view-link').href = `${DAMAGE_REPORTS_PAGE_BASE}/${id}`;
    document.getElementById('damage-update-cancel-link').href = `${DAMAGE_REPORTS_PAGE_BASE}/${id}`;

    try {
        const { response, data: payload } = await Components.fetchJson(`${DAMAGE_REPORTS_API_BASE}/${id}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load report');
        }

        currentDamageReport = payload.data?.report || null;
        if (!currentDamageReport) {
            throw new Error('Invalid report payload.');
        }

        document.getElementById('update-page-title').textContent = `Update ${currentDamageReport.damage_report_code || 'Damage Report'}`;
        document.getElementById('update-page-subtitle').textContent = `Current status: ${formatStatus(currentDamageReport.status)} • Severity: ${String(currentDamageReport.severity_level || '').toUpperCase()}`;
        document.getElementById('update-current-summary').innerHTML = `<div class="text-muted">Item: <strong>${UI.escapeHtml(currentDamageReport.item?.name) || 'Unknown'}</strong> • Room: <strong>${UI.escapeHtml(currentDamageReport.room?.name) || 'N/A'}</strong></div>`;

        document.getElementById('damage-next-status').value = currentDamageReport.status || 'pending';
        document.getElementById('damage-update-repair-notes').value = currentDamageReport.repair_notes || '';

        if (currentDamageReport.replacement_item_id) {
            document.getElementById('replacement-item-id').value = String(currentDamageReport.replacement_item_id);
            document.getElementById('replacement-item-search').value = currentDamageReport.replacement_item?.name || '';
        }

        if (currentDamageReport.replacement_quantity) {
            document.getElementById('replacement-quantity').value = String(currentDamageReport.replacement_quantity);
        }

        bindStatusToggle();
    } catch (error) {
        updateNotify(error.message || 'Unable to load damage report.');
    }
}

async function submitUpdate(event) {
    event.preventDefault();

    const id = getUpdateId();
    const saveBtn = document.getElementById('damage-update-save-btn');
    const status = document.getElementById('damage-next-status').value;
    const repairNotes = document.getElementById('damage-update-repair-notes').value.trim();

    const payload = {
        status,
        repair_notes: repairNotes,
    };

    if (status === 'replaced') {
        const replacementItemId = Number(document.getElementById('replacement-item-id').value || 0);
        const replacementQty = Number(document.getElementById('replacement-quantity').value || 0);

        if (!replacementItemId || replacementQty <= 0) {
            updateNotify('Replacement item and quantity are required for replaced status.', 'warning');
            return;
        }

        payload.replacement_item_id = replacementItemId;
        payload.replacement_quantity = replacementQty;
    }

    try {
        Components.setLoading(saveBtn, true);
        const { response, data: result } = await Components.fetchJson(`${DAMAGE_REPORTS_API_BASE}/${id}/status`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to update damage report');
        }

        Components.toast(result.message || 'Damage report updated.', 'success');
        window.location.href = `${DAMAGE_REPORTS_PAGE_BASE}/${id}`;
    } catch (error) {
        updateNotify(error.message || 'Unable to update damage report.');
    } finally {
        Components.setLoading(saveBtn, false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initReplacementSelect();
    loadReport();
    document.getElementById('damage-update-form').addEventListener('submit', submitUpdate);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
