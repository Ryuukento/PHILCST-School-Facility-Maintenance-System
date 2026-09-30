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
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1',
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-workflow.css?v=20260726-1',
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
                                <label for="dc-dept">Department <span class="text-muted" style="font-weight:400;">(reporting only)</span></label>
                                <select id="dc-dept" class="form-control">
                                    <option value="">— Loading departments… —</option>
                                </select>
                                <!-- Attribution tag for Analytics' department-usage
                                     report only — a dispatch's items are not
                                     restricted to one department, and this
                                     selection no longer affects who can be
                                     picked as Release Personnel below. -->
                                <small class="text-muted">Used for department usage reports. Does not restrict who can release this dispatch.</small>
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
                             Reuses the existing Components.SearchableSelect
                             widget and the existing .form-control styling; no
                             new component and no new styles were introduced.
                             Department-based restriction on this list was
                             removed (see DispatchAuthorizationService) — the
                             helper text below is now the same for every role. -->
                        <div class="form-group">
                            <label for="dc-release-personnel-search">Release Personnel <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="dc-release-personnel-search" class="form-control" placeholder="Search maintenance staff…" autocomplete="off">
                            <input type="hidden" id="dc-release-personnel-id">
                            <small class="text-muted">The Maintenance Staff member who will hand off these items. Any active Maintenance Staff member may be selected, regardless of department.</small>
                        </div>

                        <?php if ($_dcIsAdmin): ?>
                        <!-- TASK 41 — Administrator dispatches skip the approval
                             step, so the Administrator is told up front that no
                             approval will be requested. Head Maintenance sees no
                             such notice and keeps the unchanged pending flow. -->
                        <div class="form-group">
                            <div class="alert alert-info" style="margin:0;">
                                <strong>No approval required.</strong>
                                Dispatches you create are ready for release immediately — they are not sent to anyone for approval.
                                The assigned Release Personnel is notified and performs the actual hand-off, which is when stock is deducted.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">2</span>
                        <div>
                            <h3 class="form-section-title">Source &amp; Notes <span class="form-section-optional">(Optional)</span></h3>
                            <p class="form-section-subtitle">Link a purchase receipt and add context for this dispatch.</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <label for="dc-or-search">Source OR Number <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                            <input type="text" id="dc-or-search" class="form-control" placeholder="Search by OR number or supplier…">
                            <input type="hidden" id="dc-or-id">
                            <small class="text-muted">Links this dispatch to a purchase receipt for deployment tracking.</small>
                        </div>

                        <div class="form-group">
                            <label for="dc-notes">Notes <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                            <textarea id="dc-notes" class="form-control" rows="4" style="resize:vertical;" placeholder="Purpose of dispatch, special instructions, etc."></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">3</span>
                        <div>
                            <h3 class="form-section-title">Items to Dispatch</h3>
                            <p class="form-section-subtitle">At least one item is required. Available stock shown in parentheses.</p>
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

async function loadDepartments() {
    try {
        const { response, data: payload } = await dcFetch(
            `/api/departments`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        const sel = document.getElementById('dc-dept');
        if (!response.ok || !payload.success) {
            sel.innerHTML = '<option value="">— No departments available —</option>';
            return;
        }

        const list = Array.isArray(payload.data?.departments)
            ? payload.data.departments
            : (Array.isArray(payload.data) ? payload.data : []);

        sel.innerHTML = '<option value="">— None / Not specified —</option>';
        list.forEach((d) => {
            const opt = document.createElement('option');
            opt.value       = d.department_id;
            opt.textContent = d.name;
            sel.appendChild(opt);
        });
    } catch (_) {
        document.getElementById('dc-dept').innerHTML = '<option value="">— Could not load —</option>';
    }
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

function addItemRow() {
    dcRowCounter++;

    const row = document.createElement('div');
    row.className = 'dc-item-row';

    // Item select
    const sel = document.createElement('select');
    sel.className = 'dc-item-select form-control';
    const placeholder = document.createElement('option');
    placeholder.value       = '';
    placeholder.textContent = '— Select item —';
    sel.appendChild(placeholder);

    dcItems.forEach((item) => {
        const opt      = document.createElement('option');
        opt.value      = item.id;
        const avail    = (item.quantity != null)
            ? ` (${item.quantity} in stock)`
            : '';
        opt.textContent = dcEscapeHtml(item.name) + avail;
        sel.appendChild(opt);
    });

    // Quantity input
    const qty = document.createElement('input');
    qty.type      = 'number';
    qty.className = 'dc-qty-input form-control';
    qty.min       = '1';
    qty.value     = '1';
    qty.setAttribute('aria-label', 'Quantity');

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
    row.appendChild(qty);
    row.appendChild(rem);

    document.getElementById('dc-items-rows').appendChild(row);
}

// ---------------------------------------------------------------------------
// TASK 41 — Release Personnel selector
//
// Head Maintenance: the endpoint derives the department from the SESSION, so
// no parameter is passed and the list is already scoped to their department.
//
// Administrator: they have no department of their own, so the endpoint is
// scoped by the chosen DESTINATION department instead. SearchableSelect takes
// its endpoint as a fixed string and already handles one containing a query
// string, so the instance is destroyed and rebuilt when the department
// changes rather than modifying the shared component.
// ---------------------------------------------------------------------------

let dcPersonnelSelect = null;

// Department-based scoping of this endpoint was removed (see
// DispatchAuthorizationService) — the list is now the same regardless of
// role or the chosen Department, so this only needs to build once.
function dcBuildPersonnelSelect() {
    if (!(window.Components && typeof Components.SearchableSelect === 'function')) return;

    if (dcPersonnelSelect && typeof dcPersonnelSelect.destroy === 'function') {
        dcPersonnelSelect.destroy();
        dcPersonnelSelect = null;
    }

    // TASK 75 — SearchableSelect's default hidden-value fallback chain checks
    // it.id then it.department_id before it.user_id; a personnel row has no
    // `id` but DOES have `department_id`, so without this override the hidden
    // field would hold a department id instead of the selected user's id.
    dcPersonnelSelect = new Components.SearchableSelect({
        inputId:    'dc-release-personnel-search',
        hiddenId:   'dc-release-personnel-id',
        endpoint:   '/api/dispatches/support/release-personnel',
        displayKey: 'full_name',
        onSelect: (it) => { document.getElementById('dc-release-personnel-id').value = it.user_id || ''; },
    });
}

// ---------------------------------------------------------------------------
// Form submit
// ---------------------------------------------------------------------------

document.getElementById('dc-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    dcHideError();

    const deptId   = document.getElementById('dc-dept').value;
    const roomId   = document.getElementById('dc-room-id').value;
    const notes    = document.getElementById('dc-notes').value.trim();

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

    const orId = parseInt(document.getElementById('dc-or-id').value || '0', 10) || null;

    // TASK 13 — Release Personnel is required. This is a UX guard only; the
    // authoritative role/department check runs server-side in
    // DispatchAuthorizationService::assertAssignableReleasePersonnel().
    const releaseAssignedTo = parseInt(document.getElementById('dc-release-personnel-id').value || '0', 10) || null;
    if (!releaseAssignedTo) {
        dcShowError('Please select the Release Personnel for this dispatch.');
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
                    department_id:       deptId ? parseInt(deptId, 10) : null,
                    room_id:             parseInt(roomId, 10),
                    purchase_receipt_id: orId,
                    notes:               notes  || null,
                    release_assigned_to: releaseAssignedTo,
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
    // Departments load independently of the rest of the form
    loadDepartments();

    // OR number / Room / Release Personnel are all Components.SearchableSelect
    // instances — raw path, resolveAppUrl adds base prefix
    if (window.Components && typeof Components.SearchableSelect === 'function') {
        new Components.SearchableSelect({
            inputId:    'dc-or-search',
            hiddenId:   'dc-or-id',
            endpoint:   '/api/purchase-receipts/search',
            displayKey: 'name',   // name = or_number, code = supplier_name (shown as "OR — Supplier")
        });

        // TASK 76 — Room / Lab selector. Built by dcBuildRoomSelect() above;
        // type-to-search against /api/rooms instead of a flat <select> so
        // this scales no matter how many rooms the school adds later.
        dcBuildRoomSelect();

        // TASK 13 / TASK 41 — Release Personnel selector. Built once by
        // dcBuildPersonnelSelect() above; no longer rebuilt when Department
        // changes, since the list it loads no longer depends on Department
        // for either role (see DispatchAuthorizationService class comment).
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
