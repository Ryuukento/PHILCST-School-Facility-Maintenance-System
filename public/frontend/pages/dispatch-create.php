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

require_once __DIR__ . '/../../backend/config/settings.php';

$_dcUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? [];
$_dcRole = strtolower(trim((string)($_dcUser['role'] ?? '')));

// TASK 41 — Administrator Create Dispatch Without Approval. Both Head
// Maintenance and Administrator may create; the page gate matches the
// EnsureRole:maintenance_admin,super_admin gate on POST /api/dispatches so
// nobody reaches a form whose submit would 403.
//
// The two roles share this form but NOT the resulting workflow: a Head's
// dispatch is created 'pending' and waits for approval, an Administrator's is
// created 'approved' (no approval step). The copy below reflects whichever
// applies to the current user.
if (!in_array($_dcRole, ['maintenance_admin', 'super_admin'], true)) {
    header('Location: ' . public_url('/dispatches'));
    exit;
}

// TASK 41 — drives the two role-specific pieces of this page: the Release
// Personnel helper text, and the workflow notice explaining what happens on
// submit. Everything else on the form is identical for both roles.
$_dcIsAdmin = ($_dcRole === 'super_admin');

$pageTitle = 'Create Dispatch - SFMS';
$pageStylesheets = [
    public_url('/frontend/assets/css/enterprise-reports.css?v=20260726-1'),
    public_url('/frontend/assets/css/enterprise-workflow.css?v=20260726-1'),
];
include __DIR__ . '/../includes/header.php';
?>

<main class="container dispatch-create-page" style="margin-top:16px;">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2>Create Dispatch Request</h2>
                <p class="text-muted mb-0">Request items to be moved from inventory stock to a room or lab.</p>
            </div>
            <a href="<?php echo htmlspecialchars(public_url('/dispatches')); ?>" class="btn btn-secondary"><?php echo ui_icon('arrow-left'); ?> Back to Dispatches</a>
        </div>
        <div class="card-body">
            <form id="dc-form" novalidate>

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">1</span>
                        <div>
                            <h3 class="form-section-title">Dispatch Details</h3>
                            <p class="form-section-subtitle">Destination department and room for this dispatch.</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <label>Dispatch ID</label>
                            <input type="text" class="form-control" value="Auto-generated upon submit" readonly>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="dc-dept-search">Department <span class="text-muted" style="font-weight:400;">(reporting only)</span></label>
                                <input type="text" id="dc-dept-search" class="form-control" placeholder="Search and add departments…" autocomplete="off" style="margin-bottom:8px;">
                                <input type="hidden" id="dc-dept-id">
                                <div id="dc-dept-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;"></div>
                                <!-- Attribution tag for Analytics' department-usage
                                     report only — a dispatch's items are not
                                     restricted to one department, and this
                                     selection no longer affects who can be
                                     picked as Release Personnel below. -->
                                <small class="text-muted">Add multiple departments for reporting. Used for department usage reports only.</small>
                            </div>
                            <div class="form-group">
                                <label for="dc-room-search">Room / Lab <span style="color:#ef4444;">*</span></label>
                                <!-- TASK 60 — the free-text "Location Details"
                                     supplement (room_note) that used to sit below
                                     this field was removed: in practice it was
                                     almost always typed as a duplicate of the
                                     already-selected room name, which then showed
                                     as two identical-looking cards on Dispatch
                                     Detail ("Room / Lab" and "Location Details").
                                     This field (a real rooms.id, used by
                                     Deployment Tracking and room-usage reporting)
                                     is now the single, required source of where
                                     the Release Personnel should go.

                                     TASK 76 — was a plain <select> populated with
                                     every room in one flat, unfiltered list; with
                                     enough rooms that becomes a long scroll with
                                     no way to jump to a name. Swapped for the same
                                     Components.SearchableSelect widget already
                                     used by Release Personnel and OR Number just
                                     below — type-to-filter against /api/rooms?q=,
                                     which already supported search + pagination
                                     server-side. No new component, no new
                                     dependency, no backend change. -->
                                <input type="text" id="dc-room-search" class="form-control" placeholder="Search room or lab…" autocomplete="off">
                                <input type="hidden" id="dc-room-id">
                                <small class="text-muted">Where the Release Personnel should go to hand off these items.</small>
                            </div>
                        </div>

                        <!-- TASK 13 — Dispatch Release Assignment Workflow.
                             Primary/Additional hierarchy removal — the two
                             separate widgets this section used to have
                             (Primary Release Personnel + Additional
                             Personnel) are now one flat, equal "Release
                             Personnel" roster. Reuses the existing
                             Components.SearchableSelect widget and the
                             existing .form-control styling; no new component
                             and no new styles were introduced.
                             Department-based restriction on this list was
                             removed (see DispatchAuthorizationService) — the
                             helper text below is the same for every role. -->
                        <div class="form-group">
                            <label for="dc-release-personnel-search">Release Personnel <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="dc-release-personnel-search" class="form-control" placeholder="Search and add EELab staff…" autocomplete="off" style="margin-bottom:8px;">
                            <input type="hidden" id="dc-release-personnel-id">
                            <div id="dc-release-personnel-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;"></div>
                            <small class="text-muted">At least one person is required. Add everyone who will be responsible for releasing this dispatch.</small>
                        </div>
                    </div>
                </div>

                <!-- The optional "Source & Notes" section (Source OR Number +
                     Notes) was removed from this form. Both fields stay
                     nullable on POST /api/dispatches, so the API is unchanged;
                     existing dispatches that already carry notes still show
                     them on Dispatch Detail. -->

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">2</span>
                        <div>
                            <h3 class="form-section-title">Items to Dispatch</h3>
                            <p class="form-section-subtitle">At least one item is required. The number beside each item is its available stock.</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <div class="d-flex justify-between align-center" style="margin-bottom:10px;">
                                <label style="margin:0;">Items <span style="color:#ef4444;">*</span></label>
                                <button type="button" id="dc-add-row" class="btn btn-secondary">+ Add Item</button>
                            </div>

                            <div id="dc-items-loading" class="text-muted" style="font-size:13px;margin-bottom:8px;">Loading inventory items…</div>
                            <div id="dc-items-rows"></div>
                        </div>

                        <p id="dc-form-error" style="color:#ef4444;margin:0;display:none;font-size:14px;"></p>
                    </div>
                </div>

                <!-- Submit row -->
                <div class="d-flex gap-sm create-report-actions">
                    <button type="submit" id="dc-submit-btn" class="btn btn-primary">Submit Dispatch Request</button>
                    <a href="<?php echo htmlspecialchars(public_url('/dispatches')); ?>" class="btn btn-secondary">Cancel</a>
                </div>

            </form>
        </div>
    </div>
</main>

<script>
// NOTE: these are RAW paths — Components.fetchJson / resolveAppUrl will add
// the SFMS_BASE_PATH prefix automatically via SFMS_PUBLIC_URL, so do NOT
// pre-wrap them here (that would cause double-prefix: /base/base/...).
const DC_BACKEND   = '/backend/api';
const DC_ITEMS_API = '/api/items';

const DC_BASE      = window.SFMS_PUBLIC_URL
    ? window.SFMS_PUBLIC_URL('/dispatches')
    : '/dispatches';

// TASK 41 — mirrors the PHP role gate above. Used only for presentation and
// for scoping the Release Personnel selector; the authoritative role checks
// (who may create, whether approval is required, who may be assigned) all run
// server-side from the session.
const DC_IS_ADMIN = <?php echo $_dcIsAdmin ? 'true' : 'false'; ?>;

let dcItems      = [];
let dcRowCounter = 0;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dcEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function dcFetch(url, options) {
    const fetcher = window.Components && typeof Components.fetchJson === 'function'
        ? Components.fetchJson
        : async (u, o) => { const r = await fetch(u, o); return { response: r, data: await r.json() }; };
    return fetcher(url, options);
}

function dcShowError(msg) {
    const el = document.getElementById('dc-form-error');
    el.textContent   = msg;
    el.style.display = 'block';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function dcHideError() {
    const el = document.getElementById('dc-form-error');
    el.style.display = 'none';
    el.textContent   = '';
}

// ---------------------------------------------------------------------------
// Load dropdowns
// ---------------------------------------------------------------------------

// Multi-Department Selection for reporting
let dcDepartmentSelect = null;
const dcSelectedDepartments = new Set();

function dcBuildDepartmentSelect() {
    if (!(window.Components && typeof Components.SearchableSelect === 'function')) return;

    if (dcDepartmentSelect && typeof dcDepartmentSelect.destroy === 'function') {
        dcDepartmentSelect.destroy();
        dcDepartmentSelect = null;
    }

    dcDepartmentSelect = new Components.SearchableSelect({
        inputId:    'dc-dept-search',
        hiddenId:   'dc-dept-id',
        endpoint:   '/api/departments',
        displayKey: 'name',
        onSelect: (dept) => {
            const deptId = dept.department_id || 0;

            if (!deptId) return;

            // Prevent adding same department twice
            if (dcSelectedDepartments.has(deptId)) {
                alert(dept.name + ' is already added.');
                document.getElementById('dc-dept-search').value = '';
                document.getElementById('dc-dept-id').value = '';
                if (dcDepartmentSelect) {
                    dcDepartmentSelect.clearSelection();
                }
                return;
            }

            dcSelectedDepartments.add(deptId);
            dcUpdateDepartmentList(dept);
            // Clear the input after selection
            document.getElementById('dc-dept-search').value = '';
            document.getElementById('dc-dept-id').value = '';
            if (dcDepartmentSelect) {
                dcDepartmentSelect.clearSelection();
            }
        },
    });
}

function dcUpdateDepartmentList(dept) {
    const container = document.getElementById('dc-dept-list');
    const badge = document.createElement('div');
    badge.style.cssText = 'display:flex;align-items:center;gap:6px;padding:6px 10px;background:#dbeafe;border:1px solid #bfdbfe;border-radius:6px;font-size:13px;font-weight:500;color:#1e40af;';
    badge.id = 'dc-dept-' + dept.department_id;
    badge.innerHTML = `
        <span>${dept.name}</span>
        <button type="button" style="background:none;border:none;color:#1e40af;cursor:pointer;padding:0;font-size:16px;line-height:1;"
                onclick="dcRemoveDepartment(${dept.department_id}, event)">×</button>
    `;
    container.appendChild(badge);
}

function dcRemoveDepartment(deptId, event) {
    event.preventDefault();
    event.stopPropagation();
    dcSelectedDepartments.delete(deptId);
    const badge = document.getElementById('dc-dept-' + deptId);
    if (badge) badge.remove();
}

// TASK 76 — Room / Lab search select. Mirrors dcBuildPersonnelSelect() below:
// same Components.SearchableSelect widget, bound to /api/rooms's existing
// `q` search param instead of a full flat list, so this stays usable no
// matter how many rooms get added later.
let dcRoomSelect = null;

function dcBuildRoomSelect() {
    if (!(window.Components && typeof Components.SearchableSelect === 'function')) return;

    dcRoomSelect = new Components.SearchableSelect({
        inputId:    'dc-room-search',
        hiddenId:   'dc-room-id',
        endpoint:   '/api/rooms',
        displayKey: 'name',
    });

    // "Dispatch Items Here" from Buildings Overview opens this page with
    // ?room_id=…&room_name=… so the destination room is already chosen.
    const params = new URLSearchParams(window.location.search);
    const presetRoomId = params.get('room_id');
    const presetRoomName = params.get('room_name');
    if (presetRoomId && /^\d+$/.test(presetRoomId) && presetRoomName) {
        document.getElementById('dc-room-id').value = presetRoomId;
        document.getElementById('dc-room-search').value = presetRoomName;
    }
}

async function loadItems() {
    try {
        const { response, data: payload } = await dcFetch(
            `${DC_ITEMS_API}?per_page=200&item_type=inventory_stock`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );

        if (!response.ok || !payload.success) return;

        // Handle both paginator shapes the Laravel API may return
        const d = payload.data;
        if (Array.isArray(d?.data)) {
            dcItems = d.data;                          // paginator at root
        } else if (Array.isArray(d?.items?.data)) {
            dcItems = d.items.data;                    // named key + paginator
        } else if (Array.isArray(d?.items)) {
            dcItems = d.items;                         // named key, plain array
        } else if (Array.isArray(d)) {
            dcItems = d;                               // plain array
        }
    } catch (_) {
        // fail silently — addItemRow will show empty select
    }
}

// ---------------------------------------------------------------------------
// Dynamic item rows
// ---------------------------------------------------------------------------

// Dispatchable stock — the same quantity - reserved_quantity formula the server
// checks at approval/release (DispatchService::assertStockCoversDispatch()), so
// the number shown here is the number the server will accept.
function dcAvailableStock(item) {
    const onHand   = parseInt(item?.quantity, 10) || 0;
    const reserved = parseInt(item?.reserved_quantity, 10) || 0;
    return Math.max(0, onHand - reserved);
}

function dcFindItem(itemId) {
    return dcItems.find((item) => String(item.id) === String(itemId)) || null;
}

function addItemRow() {
    dcRowCounter++;

    const row = document.createElement('div');
    row.className = 'dc-item-row';

    // Item select — "Name — 12" for items with stock (listed first), and
    // "Name — Out of stock" for the rest, greyed out and not selectable,
    // since the server would refuse to dispatch them anyway.
    const sel = document.createElement('select');
    sel.className = 'dc-item-select form-control';
    const placeholder = document.createElement('option');
    placeholder.value       = '';
    placeholder.textContent = '— Select item —';
    sel.appendChild(placeholder);

    const byName  = (a, b) => String(a.name || '').localeCompare(String(b.name || ''));
    const inStock = dcItems.filter((item) => dcAvailableStock(item) > 0).sort(byName);
    const noStock = dcItems.filter((item) => dcAvailableStock(item) <= 0).sort(byName);

    inStock.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = item.id;
        // textContent never parses HTML, so the name is set as-is (escaping
        // it here would show "&amp;" literally for a name containing "&").
        opt.textContent = `${item.name} — ${dcAvailableStock(item)}`;
        sel.appendChild(opt);
    });

    if (inStock.length && noStock.length) {
        const divider = document.createElement('option');
        divider.disabled    = true;
        divider.textContent = '──────────';
        sel.appendChild(divider);
    }

    noStock.forEach((item) => {
        const opt = document.createElement('option');
        opt.value       = item.id;
        opt.disabled    = true;
        opt.textContent = `${item.name} — Out of stock`;
        sel.appendChild(opt);
    });

    // Quantity input, with the selected item's available stock beneath it.
    // Wrapped in one element so the row keeps the shared 3-column grid
    // (enterprise-workflow.css .dc-item-row) without touching that stylesheet.
    const qtyWrap = document.createElement('div');
    qtyWrap.className = 'dc-qty-wrap';

    const qty = document.createElement('input');
    qty.type      = 'number';
    qty.className = 'dc-qty-input form-control';
    qty.min       = '1';
    qty.value     = '1';
    qty.setAttribute('aria-label', 'Quantity');

    const availEl = document.createElement('small');
    availEl.className     = 'dc-qty-available text-muted';
    availEl.style.cssText = 'display:block;margin-top:4px;font-size:12px;';
    availEl.setAttribute('aria-live', 'polite');

    qtyWrap.appendChild(qty);
    qtyWrap.appendChild(availEl);

    sel.addEventListener('change', () => {
        const item = dcFindItem(sel.value);
        if (!item) {
            availEl.textContent = '';
            qty.removeAttribute('max');
            return;
        }
        const available = dcAvailableStock(item);
        availEl.textContent = `Available: ${available}`;
        qty.max = String(available);
        if ((parseInt(qty.value, 10) || 0) > available) {
            qty.value = String(available);
        }
    });

    // Remove button
    const rem = document.createElement('button');
    rem.type        = 'button';
    rem.className   = 'btn btn-secondary';
    rem.textContent = 'Remove';
    rem.addEventListener('click', () => {
        if (document.querySelectorAll('.dc-item-row').length <= 1) return;
        row.remove();
    });

    row.appendChild(sel);
    row.appendChild(qtyWrap);
    row.appendChild(rem);

    document.getElementById('dc-items-rows').appendChild(row);
}

// ---------------------------------------------------------------------------
// TASK 41 / Primary-Additional hierarchy removal — Release Personnel roster
//
// One flat, equal list: search-and-add, same as the old Additional
// Personnel widget, minus the mutual-exclusion-with-primary logic that no
// longer applies now that there is no "primary" to exclude against.
//
// Head Maintenance: the endpoint derives the department from the SESSION, so
// no parameter is passed and the list is already scoped to their department.
// Administrator: they have no department of their own, so the list is the
// same regardless of the chosen destination department (department-based
// restriction on this endpoint was removed — see
// DispatchAuthorizationService).
// ---------------------------------------------------------------------------

let dcPersonnelSelect = null;
const dcSelectedPersonnel = new Set();

function dcBuildPersonnelSelect() {
    if (!(window.Components && typeof Components.SearchableSelect === 'function')) return;

    if (dcPersonnelSelect && typeof dcPersonnelSelect.destroy === 'function') {
        dcPersonnelSelect.destroy();
        dcPersonnelSelect = null;
    }

    dcPersonnelSelect = new Components.SearchableSelect({
        inputId:    'dc-release-personnel-search',
        hiddenId:   'dc-release-personnel-id',
        endpoint:   '/api/dispatches/support/release-personnel',
        displayKey: 'full_name',
        // Hide anyone already added to the roster from the dropdown itself,
        // instead of letting the user click them again and hit the
        // "already added" alert below. Read fresh on every render (see
        // getExcludeIds in components.js).
        getExcludeIds: () => {
            const ids = new Set();
            dcSelectedPersonnel.forEach((id) => ids.add(Number(id)));
            return ids;
        },
        onSelect: (personnel) => {
            const userId = personnel.user_id || 0;
            if (!userId) return;

            // Defensive fallback only — getExcludeIds above already hides
            // anyone already on the roster, so this should be unreachable
            // in normal use (e.g. a stale rendered list from a search that
            // started just before a previous pick).
            if (dcSelectedPersonnel.has(userId)) {
                alert(personnel.full_name + ' is already added to Release Personnel.');
                document.getElementById('dc-release-personnel-search').value = '';
                document.getElementById('dc-release-personnel-id').value = '';
                if (dcPersonnelSelect) {
                    dcPersonnelSelect.clearSelection();
                }
                return;
            }

            dcSelectedPersonnel.add(userId);
            dcUpdatePersonnelList(personnel);
            // Clear the input after selection
            document.getElementById('dc-release-personnel-search').value = '';
            document.getElementById('dc-release-personnel-id').value = '';
            if (dcPersonnelSelect) {
                dcPersonnelSelect.clearSelection();
            }
        },
    });
}

function dcUpdatePersonnelList(personnel) {
    const container = document.getElementById('dc-release-personnel-list');
    const badge = document.createElement('div');
    badge.style.cssText = 'display:flex;align-items:center;gap:6px;padding:6px 10px;background:#f0e7ff;border:1px solid #ddd6fe;border-radius:6px;font-size:13px;font-weight:500;';
    badge.id = 'dc-personnel-' + personnel.user_id;
    badge.innerHTML = `
        <span>${personnel.full_name}</span>
        <button type="button" style="background:none;border:none;color:#6b7280;cursor:pointer;padding:0;font-size:16px;line-height:1;"
                onclick="dcRemovePersonnel(${personnel.user_id}, event)">×</button>
    `;
    container.appendChild(badge);
}

function dcRemovePersonnel(userId, event) {
    event.preventDefault();
    event.stopPropagation();
    dcSelectedPersonnel.delete(userId);
    const badge = document.getElementById('dc-personnel-' + userId);
    if (badge) badge.remove();
}

// ---------------------------------------------------------------------------
// Form submit
// ---------------------------------------------------------------------------

document.getElementById('dc-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    dcHideError();

    // Multi-Department Selection: convert Set to array of department IDs
    const departmentIds = Array.from(dcSelectedDepartments);
    const roomId   = document.getElementById('dc-room-id').value;

    // TASK 60 — Room / Lab is now the sole, required location field (the
    // free-text room_note supplement was removed; see the field's comment
    // above for why). Mirrors the server's 'required' rule on room_id (see
    // DispatchController::store()) so the obvious case doesn't need a round
    // trip; the server rule is still the one that decides.
    if (!roomId) {
        dcShowError('Please select the Room / Lab this dispatch is going to.');
        return;
    }

    const rows  = Array.from(document.querySelectorAll('.dc-item-row'));
    const items = rows
        .map((row) => ({
            item_id:  parseInt(row.querySelector('.dc-item-select').value, 10) || 0,
            quantity: parseInt(row.querySelector('.dc-qty-input').value, 10)   || 0,
        }))
        .filter((it) => it.item_id > 0 && it.quantity > 0);

    if (items.length === 0) {
        dcShowError('At least one item must be selected with a quantity of 1 or more.');
        return;
    }

    // TASK 47 — Dispatch Create & Assignment Workflow: the same item chosen
    // in two rows is rejected server-side (see DispatchController::store()'s
    // 'distinct' rule on items.*.item_id), so surface that as an immediate,
    // actionable message here rather than letting the user hit a generic
    // validation failure after submitting.
    const seenItemIds = new Set();
    const hasDuplicateItem = items.some((it) => {
        if (seenItemIds.has(it.item_id)) return true;
        seenItemIds.add(it.item_id);
        return false;
    });
    if (hasDuplicateItem) {
        dcShowError('Each item can only appear once. Combine duplicate rows into a single quantity.');
        return;
    }

    // Quantity cannot exceed the item's available stock. A convenience check
    // only — the server re-checks stock at approval and release.
    const overStock = items.find((it) => it.quantity > dcAvailableStock(dcFindItem(it.item_id)));
    if (overStock) {
        const item = dcFindItem(overStock.item_id);
        dcShowError(`Only ${dcAvailableStock(item)} ${item?.name ?? 'unit(s)'} available. Please lower the quantity.`);
        return;
    }

    // Primary/Additional hierarchy removal — Release Personnel is one flat,
    // required roster. Mirrors the server's 'required|array|min:1' rule on
    // personnel_ids (see DispatchController::store()).
    const personnelIds = Array.from(dcSelectedPersonnel);

    if (personnelIds.length === 0) {
        dcShowError('Please add at least one Release Personnel to this dispatch.');
        return;
    }

    const submitBtn  = document.getElementById('dc-submit-btn');
    const origText   = submitBtn.textContent;
    submitBtn.disabled    = true;
    submitBtn.textContent = 'Submitting…';

    try {
        // NOTE: Use dcFetch (→ Components.fetchJson → resolveAppUrl) so the
        // SFMS_BASE_PATH prefix is added automatically. Raw fetch('/api/…')
        // would resolve to the wrong origin on a subdirectory install.
        const { response, data: payload } = await dcFetch(
            '/api/dispatches',
            {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    department_ids: departmentIds.length > 0 ? departmentIds : [],
                    room_id:        parseInt(roomId, 10),
                    // Primary/Additional hierarchy removal — the whole
                    // roster is created in one request now; createDispatch()
                    // writes every id into dispatch_personnel_assignments in
                    // the same transaction as the dispatch itself, so the
                    // old create-then-loop-/add-personnel sequence is gone.
                    personnel_ids:  personnelIds,
                    items,
                }),
            }
        );

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to create dispatch');
        }

        const newId = payload.data?.dispatch_id;

        window.location.href = newId ? `${DC_BASE}/${newId}` : DC_BASE;
    } catch (err) {
        dcShowError(err.message || 'An error occurred. Please try again.');
        submitBtn.disabled    = false;
        submitBtn.textContent = origText;
    }
});

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', async () => {
    // Room / Release Personnel / Departments are all Components.SearchableSelect
    // instances — raw path, resolveAppUrl adds base prefix
    if (window.Components && typeof Components.SearchableSelect === 'function') {
        // Multi-Department Selection for reporting purposes. Allows selecting
        // multiple departments for dispatch when personnel from different
        // departments are assigned.
        dcBuildDepartmentSelect();

        // TASK 76 — Room / Lab selector. Built by dcBuildRoomSelect() above;
        // type-to-search against /api/rooms instead of a flat <select> so
        // this scales no matter how many rooms the school adds later.
        dcBuildRoomSelect();

        // TASK 13 / TASK 41 / Primary-Additional hierarchy removal — Release
        // Personnel roster selector. Built once; the list it loads no
        // longer depends on Department for either role (see
        // DispatchAuthorizationService class comment).
        dcBuildPersonnelSelect();
    }

    // Items must load before the first row is added so the select is populated
    await loadItems();

    document.getElementById('dc-items-loading').style.display = 'none';
    addItemRow();

    document.getElementById('dc-add-row').addEventListener('click', addItemRow);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
