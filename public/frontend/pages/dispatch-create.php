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
                             Reuses the existing Components.SearchableSelect
                             widget and the existing .form-control styling; no
                             new component and no new styles were introduced.
                             Department-based restriction on this list was
                             removed (see DispatchAuthorizationService) — the
                             helper text below is now the same for every role. -->
                        <!-- Multi-Personnel Dispatch: Additional Personnel Assignment -->
                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="dc-release-personnel-search">Primary Release Personnel <span style="color:#ef4444;">*</span></label>
                                <input type="text" id="dc-release-personnel-search" class="form-control" placeholder="Search maintenance staff…" autocomplete="off">
                                <input type="hidden" id="dc-release-personnel-id">
                                <small class="text-muted">The primary Maintenance Staff member responsible for the dispatch.</small>
                            </div>

                            <div class="form-group">
                                <label for="dc-additional-personnel-search">Additional Personnel (Optional)</label>
                                <input type="text" id="dc-additional-personnel-search" class="form-control" placeholder="Search and add maintenance staff…" autocomplete="off" style="margin-bottom:8px;">
                                <input type="hidden" id="dc-additional-personnel-id">
                                <div id="dc-additional-personnel-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;"></div>
                                <small class="text-muted">Add personnel from different departments.</small>
                            </div>
                        </div>
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
        onSelect: (personnel) => {
            const userId = personnel.user_id || 0;
            document.getElementById('dc-release-personnel-id').value = userId;

            // Validation: if this person is already in additional personnel, remove them
            if (userId && dcSelectedAdditionalPersonnel.has(userId)) {
                dcRemoveAdditionalPersonnel(userId, new Event('click'));
                alert(personnel.full_name + ' was removed from Additional Personnel since they are now the Primary Release Personnel.');
            }
        },
    });
}

// ---------------------------------------------------------------------------
// Multi-Personnel Dispatch Support — Additional Personnel Selection
// ---------------------------------------------------------------------------

let dcAdditionalPersonnelSelect = null;
const dcSelectedAdditionalPersonnel = new Set();

function dcBuildAdditionalPersonnelSelect() {
    if (!(window.Components && typeof Components.SearchableSelect === 'function')) return;

    if (dcAdditionalPersonnelSelect && typeof dcAdditionalPersonnelSelect.destroy === 'function') {
        dcAdditionalPersonnelSelect.destroy();
        dcAdditionalPersonnelSelect = null;
    }

    dcAdditionalPersonnelSelect = new Components.SearchableSelect({
        inputId:    'dc-additional-personnel-search',
        hiddenId:   'dc-additional-personnel-id',
        endpoint:   '/api/dispatches/support/release-personnel',
        displayKey: 'full_name',
        onSelect: (personnel) => {
            const userId = personnel.user_id || 0;
            const primaryUserId = parseInt(document.getElementById('dc-release-personnel-id').value || '0', 10) || null;

            // Validation: prevent adding same person twice
            if (!userId) {
                return;
            }

            // Prevent adding primary release personnel as additional personnel
            if (userId === primaryUserId) {
                alert(personnel.full_name + ' is already assigned as Primary Release Personnel. Cannot add as Additional Personnel.');
                document.getElementById('dc-additional-personnel-search').value = '';
                document.getElementById('dc-additional-personnel-id').value = '';
                if (dcAdditionalPersonnelSelect) {
                    dcAdditionalPersonnelSelect.clearSelection();
                }
                return;
            }

            // Prevent adding same person twice in additional personnel
            if (dcSelectedAdditionalPersonnel.has(userId)) {
                alert(personnel.full_name + ' is already added to Additional Personnel.');
                document.getElementById('dc-additional-personnel-search').value = '';
                document.getElementById('dc-additional-personnel-id').value = '';
                if (dcAdditionalPersonnelSelect) {
                    dcAdditionalPersonnelSelect.clearSelection();
                }
                return;
            }

            dcSelectedAdditionalPersonnel.add(userId);
            dcUpdateAdditionalPersonnelList(personnel);
            // Clear the input after selection
            document.getElementById('dc-additional-personnel-search').value = '';
            document.getElementById('dc-additional-personnel-id').value = '';
            if (dcAdditionalPersonnelSelect) {
                dcAdditionalPersonnelSelect.clearSelection();
            }
        },
    });
}

function dcUpdateAdditionalPersonnelList(personnel) {
    const container = document.getElementById('dc-additional-personnel-list');
    const badge = document.createElement('div');
    badge.style.cssText = 'display:flex;align-items:center;gap:6px;padding:6px 10px;background:#f0e7ff;border:1px solid #ddd6fe;border-radius:6px;font-size:13px;font-weight:500;';
    badge.id = 'dc-personnel-' + personnel.user_id;
    badge.innerHTML = `
        <span>${personnel.full_name}</span>
        <button type="button" style="background:none;border:none;color:#6b7280;cursor:pointer;padding:0;font-size:16px;line-height:1;"
                onclick="dcRemoveAdditionalPersonnel(${personnel.user_id}, event)">×</button>
    `;
    container.appendChild(badge);
}

function dcRemoveAdditionalPersonnel(userId, event) {
    event.preventDefault();
    event.stopPropagation();
    dcSelectedAdditionalPersonnel.delete(userId);
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

    // Multi-Personnel Dispatch: Release Personnel is now optional. When using
    // multi-personnel dispatch, additional personnel are assigned via the
    // Additional Personnel field instead. The form requires at least one
    // personnel assignment (primary or additional).
    const releaseAssignedTo = parseInt(document.getElementById('dc-release-personnel-id').value || '0', 10) || null;
    const hasAdditionalPersonnel = dcSelectedAdditionalPersonnel.size > 0;

    if (!releaseAssignedTo && !hasAdditionalPersonnel) {
        dcShowError('Please assign at least one personnel (Primary Release Personnel or Additional Personnel) to this dispatch.');
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
                    department_ids:      departmentIds.length > 0 ? departmentIds : [],
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

        // Assign additional personnel if any were selected
        if (newId && dcSelectedAdditionalPersonnel.size > 0) {
            try {
                for (const personelUserId of dcSelectedAdditionalPersonnel) {
                    await dcFetch(
                        `/api/dispatches/${newId}/add-personnel`,
                        {
                            method:      'POST',
                            credentials: 'same-origin',
                            headers:     { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({
                                personnel_user_id: personelUserId,
                            }),
                        }
                    );
                }
            } catch (personnelErr) {
                console.warn('Some personnel assignments failed, but dispatch was created:', personnelErr);
            }
        }

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
    // OR number / Room / Release Personnel / Departments are all Components.SearchableSelect
    // instances — raw path, resolveAppUrl adds base prefix
    if (window.Components && typeof Components.SearchableSelect === 'function') {
        new Components.SearchableSelect({
            inputId:    'dc-or-search',
            hiddenId:   'dc-or-id',
            endpoint:   '/api/purchase-receipts/search',
            displayKey: 'name',   // name = or_number, code = supplier_name (shown as "OR — Supplier")
        });

        // Multi-Department Selection for reporting purposes. Allows selecting
        // multiple departments for dispatch when personnel from different
        // departments are assigned.
        dcBuildDepartmentSelect();

        // TASK 76 — Room / Lab selector. Built by dcBuildRoomSelect() above;
        // type-to-search against /api/rooms instead of a flat <select> so
        // this scales no matter how many rooms the school adds later.
        dcBuildRoomSelect();

        // TASK 13 / TASK 41 — Release Personnel selector. Built once by
        // dcBuildPersonnelSelect() above; no longer rebuilt when Department
        // changes, since the list it loads no longer depends on Department
        // for either role (see DispatchAuthorizationService class comment).
        dcBuildPersonnelSelect();

        // Multi-Personnel Dispatch: Additional Personnel selector for assigning
        // multiple staff from different departments to dispatch relevant items.
        dcBuildAdditionalPersonnelSelect();
    }

    // Items must load before the first row is added so the select is populated
    await loadItems();

    document.getElementById('dc-items-loading').style.display = 'none';
    addItemRow();

    document.getElementById('dc-add-row').addEventListener('click', addItemRow);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
