<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
    if (!@session_start()) {
        // 2026-09-30: transient Windows/antivirus file-lock on the session
        // save path (C:\xampp\tmp) can make session_start() fail; suppress
        // the raw warning and log it instead so users just see a clean
        // logged-out state (e.g. after auto-logout) rather than PHP noise.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

require_once __DIR__ . '/../../backend/config/settings.php';

if (!isset($_SESSION['auth_user']) && !isset($_SESSION['user'])) {
    header('Location: ' . public_url('/login'), true, 302);
    exit;
}

// 2026-10-27 — Supplier Directory used to render its own standalone HTML
// document (no sidebar/navbar) under the belief that including header.php
// would "corrupt the layout". In practice that just made this page look
// like a broken escape from the rest of the system the moment a user
// reached it (e.g. via purchase-receipts.php's "Manage Suppliers" link) --
// no sidebar, no top bar, and its bare <table> fell back to styles.css's
// legacy dark-theme defaults with nothing to override them. header.php
// already runs the exact same stale-session + idle-timeout checks
// session-guard.php was built to let standalone pages share, so including
// it here replaces those two direct calls, not just the markup around them.
$pageTitle = 'Supplier Directory - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<!-- .modal/.modal-content (View Purchase History modal below) come from
     layout.css, already linked by header.php -- no longer duplicated here. -->
<style>
    /* 2026-10-27 — Supplier Directory visual redesign (reference: user-supplied
       screenshot). Scoped sd-* rules, same approach the page already used for
       its toolbar/badge/grid styling -- nothing here is shared with any other
       page, so it can't regress anything outside this file. */
    .sd-card-header-flex{display:flex;align-items:center;gap:12px}
    .sd-back-btn{gap:6px;flex-shrink:0;white-space:nowrap}
    .sd-card-icon{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:rgba(139,92,246,0.12);color:var(--primary,#8b5cf6);flex-shrink:0}
    .sd-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
    .sd-search-wrap{position:relative;flex:1;min-width:260px}
    .sd-search-wrap .sd-leading-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;display:flex;pointer-events:none}
    .sd-search-wrap input[type="text"]{width:100%;box-sizing:border-box;padding:8px 10px 8px 34px;border:1px solid #ddd;border-radius:4px}
    .sd-toolbar select{padding:8px;border:1px solid #ddd;border-radius:4px}
    .sd-badge{padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;white-space:nowrap}
    .sd-badge-active{background:#d1fae5;color:#065f46}
    .sd-badge-inactive{background:#fee2e2;color:#991b1b}
    .sd-actions-cell{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .sd-row-num{color:#6b7280;font-weight:500}
    .sd-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:600px){.sd-grid-2{grid-template-columns:1fr}}
    .sd-field label{display:block;margin-bottom:4px;font-size:12px;font-weight:600;color:#374151}
    .sd-field{margin-bottom:10px}
    .sd-field textarea{resize:vertical}
    .sd-field-icon{position:relative}
    .sd-field-icon .sd-leading-icon{position:absolute;left:10px;top:34px;color:#9ca3af;display:flex;pointer-events:none}
    .sd-field-icon input,.sd-field-icon textarea{padding-left:34px}
    .sd-field-icon textarea ~ .sd-leading-icon{top:30px}

    /* 2026-10-27 -- second redesign pass: Add Supplier form reshaped to match
       a newer user-supplied reference screenshot (pill-shaped fields, a
       dropdown-style Status picker with a checkmark on the selected option).
       That image was rendered in dark theme; per the user's explicit answer
       to a clarifying question ("Light theme pero same shapes/wording") this
       reproduces its shapes/wording only -- every color below stays on this
       app's light-theme-only palette (data-theme-resolved is pinned by a
       test; nothing here touches it). */
    .sd-field input,.sd-field select{border-radius:999px}
    .sd-field textarea{border-radius:16px}

    /* Status dropdown -- replaces the two-button toggle from the first
       redesign pass. #status stays a hidden input and the single source of
       truth every existing read/write of its .value (resetForm(), the edit
       handler, the submit payload) keeps working untouched;
       setSupplierStatus() now just repaints this dropdown's label and
       checkmark instead of toggle-button colors. */
    .sd-status-dropdown{position:relative;width:100%;max-width:190px}
    .sd-status-dropdown-btn{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;box-sizing:border-box;padding:8px 14px;border:1px solid #ddd;border-radius:999px;background:#fff;color:#111827;font-size:14px;font-weight:600;cursor:pointer}
    .sd-status-dropdown-btn:hover,.sd-status-dropdown-btn:focus-visible{border-color:var(--primary,#8b5cf6);outline:none}
    .sd-status-dropdown-btn .sd-status-chevron{display:flex;color:#9ca3af;transition:transform .15s ease}
    .sd-status-dropdown.open .sd-status-chevron{transform:rotate(180deg)}
    .sd-status-dropdown-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;padding:.35rem;border-radius:12px;border:1px solid #ddd;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,0.16);z-index:20}
    .sd-status-dropdown.open .sd-status-dropdown-menu{display:flex;flex-direction:column;gap:.15rem}
    .sd-status-dropdown-item{display:flex;align-items:center;gap:8px;width:100%;padding:.5rem .6rem;border:none;border-radius:8px;background:transparent;color:#111827;font-size:14px;font-weight:600;text-align:left;cursor:pointer}
    .sd-status-dropdown-item:hover,.sd-status-dropdown-item:focus-visible{background:rgba(139,92,246,0.12);outline:none}
    .sd-status-check{display:flex;color:var(--primary,#8b5cf6);visibility:hidden;flex-shrink:0}
    .sd-status-dropdown-item.selected .sd-status-check{visibility:visible}

    /* Pill-shaped action buttons on this form only, same reference image. */
    #submit-btn,#clear{border-radius:999px;padding:9px 20px}

    /* Per-row overflow ("kebab") menu. Mirrors buildings-overview.php's
       .room-card-menu-* pattern (same trigger/dropdown/item shape) but kept as
       its own sd-prefixed copy since that CSS lives in buildings-redesign.css,
       a stylesheet this page doesn't load. */
    .sd-kebab-wrap{position:relative}
    .sd-kebab-btn{display:grid;place-items:center;width:30px;height:30px;border-radius:8px;border:1px solid transparent;background:transparent;color:#6b7280;font-size:1.1rem;line-height:1;cursor:pointer}
    .sd-kebab-btn:hover,.sd-kebab-btn:focus-visible{background:rgba(139,92,246,0.12);border-color:rgba(139,92,246,0.28);color:var(--primary,#8b5cf6);outline:none}
    .sd-kebab-dropdown{display:none;position:absolute;top:calc(100% + 4px);right:0;min-width:160px;padding:.35rem;border-radius:10px;border:1px solid #ddd;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,0.16);z-index:20}
    .sd-kebab-wrap.open .sd-kebab-dropdown{display:flex;flex-direction:column;gap:.15rem}
    .sd-kebab-item{display:block;width:100%;padding:.45rem .6rem;border:none;border-radius:6px;background:transparent;color:#111827;font-size:.85rem;font-weight:600;text-align:left;cursor:pointer}
    .sd-kebab-item:hover,.sd-kebab-item:focus-visible{background:rgba(139,92,246,0.12);outline:none}
    .sd-kebab-item-danger{color:#dc2626}
    .sd-kebab-item-danger:hover{background:rgba(220,38,38,0.1)}
</style>

<main class="container" style="margin-top:16px;">
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div class="sd-card-header-flex">
                <span class="sd-card-icon" aria-hidden="true"><?php echo ui_icon('truck', ['size' => 20]); ?></span>
                <div>
                    <h2>Supplier Directory</h2>
                    <p class="text-muted mb-0">Manage registered suppliers</p>
                </div>
            </div>
            <!-- 2026-10-27: "Back" to Purchase Receipts -- this page is
                 normally reached from purchase-receipts.php's "Manage
                 Suppliers" link; per user request this gives a direct way
                 back there instead of relying on browser history. A plain
                 link (not JS history.back()) so it always lands on
                 Purchase Receipts specifically, even on a fresh tab/reload. -->
            <a href="<?php echo htmlspecialchars(public_url('/purchase-receipts')); ?>" class="btn btn-secondary sd-back-btn">
                <?php echo ui_icon('arrow-left', ['size' => 16]); ?>
                <span>Back</span>
            </a>
        </div>
        <div class="card-body">
            <div class="sd-toolbar">
                <div class="sd-search-wrap">
                    <span class="sd-leading-icon" aria-hidden="true"><?php echo ui_icon('search', ['size' => 16]); ?></span>
                    <input type="text" id="search" class="form-control" placeholder="Search suppliers...">
                </div>
                <select id="status-filter" class="form-control">
                    <option value="">All statuses</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
                <button type="button" class="btn btn-primary" id="search-btn">Search</button>
                <button type="button" class="btn btn-secondary" id="reset-btn">Reset</button>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="list">
                        <tr><td colspan="7">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3 id="form-title">Add New Supplier</h3>
                <p class="text-muted mb-0" id="form-subtitle">Supplier details</p>
            </div>
        </div>
        <div class="card-body">
            <form id="form">
                <input type="hidden" id="supplier_id">

                <!-- maxlength/minlength here mirror SupplierController's
                     server-side validation rules exactly (the authoritative
                     check -- these are just an early, friendlier stop before
                     the request even goes out). Name intentionally allows any
                     character: legitimate business names use "&", commas,
                     periods ("ABC Hardware & Supply", "J&J Trading",
                     "ABC Co., Ltd."). -->
                <!-- 2026-10-27: Contact Person moved alongside Supplier Name
                     per user feedback on the live redesigned page ("yung
                     Contact Person i tapat mo doon sa Supplier Name *") --
                     Phone/Contact Email now pair together in the next row,
                     and Status (no longer sharing a row) gets its own
                     full-width row below them. -->
                <div class="sd-grid-2">
                    <div class="sd-field">
                        <label for="name">Supplier Name *</label>
                        <input id="name" class="form-control" required minlength="3" maxlength="100" placeholder="e.g. ABC Hardware Supply">
                    </div>
                    <div class="sd-field sd-field-icon">
                        <label for="contact_person">Contact Person</label>
                        <span class="sd-leading-icon" aria-hidden="true"><?php echo ui_icon('user', ['size' => 15]); ?></span>
                        <input id="contact_person" class="form-control" placeholder="Full name" maxlength="255">
                    </div>
                </div>

                <div class="sd-grid-2">
                    <div class="sd-field sd-field-icon">
                        <label for="phone">Phone</label>
                        <span class="sd-leading-icon" aria-hidden="true"><?php echo ui_icon('phone', ['size' => 15]); ?></span>
                        <input id="phone" class="form-control" placeholder="09XX XXX XXXX" maxlength="50">
                    </div>
                    <div class="sd-field sd-field-icon">
                        <label for="contact_email">Contact Email</label>
                        <span class="sd-leading-icon" aria-hidden="true"><?php echo ui_icon('mail', ['size' => 15]); ?></span>
                        <input id="contact_email" type="email" class="form-control" placeholder="supplier@example.com" maxlength="255">
                    </div>
                </div>

                <!-- 2026-10-27: Status narrowed (it was stretching the full
                     row width) and paired with Address in the same row, per
                     user feedback on the live redesigned page ("yung line ng
                     active status is liitan mo lang sya kasi masyadong
                     mahaba then yung Address is i tapat mo doon sa Status").
                     Column order swapped (Address left, Status right) per a
                     follow-up request ("pwede bang pag palitin mo ng pwesto
                     yung Address tas status") -- same two fields, same row,
                     just reversed left/right. -->
                <div class="sd-grid-2">
                    <div class="sd-field sd-field-icon">
                        <label for="address">Address</label>
                        <span class="sd-leading-icon" aria-hidden="true"><?php echo ui_icon('map-pin', ['size' => 15]); ?></span>
                        <textarea id="address" class="form-control" rows="2" placeholder="Address" maxlength="255"></textarea>
                    </div>

                    <div class="sd-field">
                        <!-- Feedback item A: "Magdagdag ng Active at Inactive
                             status. Kapag inactive ang supplier, hindi na ito
                             puwedeng piliin para sa bagong receipt, pero
                             mananatili ang historical records nito." The New
                             Receipt supplier dropdown already filters
                             ?status=active (purchase-receipts.php's
                             prBuildSupplierSelect()) and SupplierController
                             never deletes historical rows, so toggling this
                             value is the only missing piece.

                             2026-10-27 second redesign pass: a pill-shaped
                             dropdown button with a checkmark next to the
                             selected option, matching a user-supplied
                             reference image (shown there in dark theme; per
                             the user's explicit confirmation this reproduces
                             its shapes/wording only, staying on this app's
                             light theme). #status stays a hidden input so
                             every existing read/write of its .value
                             (resetForm(), the edit-click handler, the submit
                             payload below) keeps working completely
                             unchanged -- setSupplierStatus() just repaints
                             this dropdown's label + checkmark to match. -->
                        <label>Status</label>
                        <input type="hidden" id="status" value="active">
                        <div class="sd-status-dropdown" id="status-dropdown">
                            <button type="button" class="sd-status-dropdown-btn" id="status-dropdown-btn" aria-haspopup="listbox" aria-expanded="false">
                                <span id="status-dropdown-label">Active</span>
                                <span class="sd-status-chevron" aria-hidden="true"><?php echo ui_icon('chevron-down', ['size' => 14]); ?></span>
                            </button>
                            <div class="sd-status-dropdown-menu" role="listbox">
                                <button type="button" class="sd-status-dropdown-item selected" data-status="active" role="option" aria-selected="true">
                                    <span class="sd-status-check" aria-hidden="true"><?php echo ui_icon('check', ['size' => 14]); ?></span>
                                    <span>Active</span>
                                </button>
                                <button type="button" class="sd-status-dropdown-item" data-status="inactive" role="option" aria-selected="false">
                                    <span class="sd-status-check" aria-hidden="true"><?php echo ui_icon('check', ['size' => 14]); ?></span>
                                    <span>Inactive</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sd-field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" class="form-control" rows="2" placeholder="Notes" maxlength="500"></textarea>
                </div>

                <div style="margin-top:8px;display:flex;gap:10px;">
                    <button type="submit" class="btn btn-primary" id="submit-btn">Create Supplier</button>
                    <button type="button" class="btn btn-secondary" id="clear">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Modal: View Purchase History
     Reuses the same SupplierController::history() endpoint and response
     shape (purchase_receipts: or_number, receipt_date, status, item_count)
     that purchase-receipts.php's "View Supplier History" modal already
     renders -- same markup/JS pattern, duplicated here since this page has
     its own separate inline <script> rather than sharing purchase-receipts.php's.
     Receipts created before purchase_receipts.supplier_id existed
     (pre-2026-10-27) only ever had a free-text supplier name and won't
     appear here -- a known, already-documented data-availability gap, not a
     bug in this modal. -->
<div id="history-modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" style="display:none;">
    <div class="modal-content" style="max-width:640px;width:100%;padding:0;background:var(--card-color);color:var(--text-primary);border:1px solid var(--border);border-radius:12px;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 24px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <div>
                <h2 style="margin:0;font-size:16px;font-weight:700;line-height:1.3;">Purchase History</h2>
                <p id="hist-supplier-name" style="margin:2px 0 0;font-size:12px;opacity:0.55;line-height:1.4;">—</p>
            </div>
            <button type="button" id="closeHistoryModal" aria-label="Close" style="background:none;border:none;cursor:pointer;padding:6px;opacity:0.5;color:inherit;font-size:22px;line-height:1;border-radius:6px;margin-left:12px;">×</button>
        </div>
        <div style="padding:20px 24px;overflow-y:auto;flex:1;">
            <p style="margin:0 0 12px;font-size:12px;opacity:0.65;">Past purchase receipts recorded for this supplier.</p>
            <div id="hist-receipts-container" class="table-responsive">
                <div class="ui-empty-state"><strong>Loading...</strong></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:flex-end;padding:14px 24px;border-top:1px solid var(--border);flex-shrink:0;">
            <button type="button" class="btn btn-secondary" id="closeHistoryModalFooter">Close</button>
        </div>
    </div>
</div>

<!-- utils.js / components.js (Components.toast/alert/confirm, window.UI) are no
     longer loaded here directly -- now that this page includes header.php /
     footer.php like every other page, footer.php already loads both, in the
     same order this page used to load them in. Every Components.* call below
     only runs inside an event handler or after an `await`, i.e. after the
     page has finished loading, so it's safe that those <script> tags render
     physically after this block (footer.php is included at the bottom of
     this file) -- this is the exact same pattern every other page on
     header.php/footer.php already relies on. -->
<script>
function smEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
function smFormatDate(d) {
    if (!d) return '—';
    try { return new Date(d + 'T00:00:00').toLocaleDateString(); } catch(e) { return String(d); }
}
function smStatusBadge(status) {
    const cls = status === 'inactive' ? 'sd-badge-inactive' : 'sd-badge-active';
    const label = status === 'inactive' ? 'Inactive' : 'Active';
    return `<span class="sd-badge ${cls}">${label}</span>`;
}
function smReceiptStatusBadge(status) {
    if (status === 'posted') {
        return '<span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;">Posted</span>';
    }
    return '<span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;">Draft</span>';
}
const apiPrefix = <?php echo json_encode(public_url('/api/suppliers')); ?>;
let originalSupplierEmail = '';

function supplierEmailAllowed(email, isEditing, originalEmail) {
    if (!email) return true;
    const input = document.getElementById('contact_email');
    if (email.length > 255 || !input.checkValidity()) return false;
    return (isEditing && email === originalEmail) || /^[^\s@]+@(?:gmail\.com|yahoo\.com)$/i.test(email);
}
// Reorder Previous Receipt: this page has no New Receipt modal of its own,
// so a Reorder click here redirects to purchase-receipts.php, which detects
// the `reorder` param on load and starts the flow there.
const SD_PURCHASE_RECEIPTS_PAGE = <?php echo json_encode(public_url('/purchase-receipts')); ?>;

async function fetchSuppliers(){
    const q = document.getElementById('search').value;
    const status = document.getElementById('status-filter').value;
    let url = apiPrefix + '?search=' + encodeURIComponent(q);
    if (status) url += '&status=' + encodeURIComponent(status);

    const list = document.getElementById('list');
    list.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';

    try {
        const res = await fetch(url, {credentials:'same-origin'});
        const json = await res.json();
        if(!json.success){ Components.alert(json.message || 'Failed to load suppliers', 'danger'); return }
        const data = json.data.data;

        if (!data.length) {
            list.innerHTML = '<tr><td colspan="7">No suppliers found.</td></tr>';
            return;
        }

        list.innerHTML = '';
        // 2026-10-27 redesign: "#" is a plain 1-based display sequence within
        // the current filtered/paginated result set, not the supplier's
        // database id -- purely cosmetic row numbering, matches the
        // reference image.
        data.forEach((s, idx)=>{
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="sd-row-num">${idx + 1}</td>
                <td><strong>${smEscapeHtml(s.name)}</strong></td>
                <td>${smEscapeHtml(s.contact_person || '—')}</td>
                <td>${smEscapeHtml(s.phone || '—')}</td>
                <td>${smEscapeHtml(s.contact_email || '—')}</td>
                <td>${smStatusBadge(s.status)}</td>
                <td class="sd-actions-cell">
                    <button type="button" class="btn btn-secondary edit" data-id="${smEscapeHtml(s.id)}">Edit</button>
                    <button type="button" class="btn btn-secondary history" data-id="${smEscapeHtml(s.id)}" data-name="${smEscapeHtml(s.name)}">History</button>
                    <div class="sd-kebab-wrap">
                        <button type="button" class="sd-kebab-btn kebab-toggle" aria-haspopup="menu" aria-expanded="false" aria-label="More actions for ${smEscapeHtml(s.name)}" title="More actions">&#8942;</button>
                        <div class="sd-kebab-dropdown" role="menu">
                            <button type="button" class="sd-kebab-item sd-kebab-item-danger delete" role="menuitem" data-id="${smEscapeHtml(s.id)}" data-name="${smEscapeHtml(s.name)}">Delete Supplier</button>
                        </div>
                    </div>
                </td>
            `;
            list.appendChild(tr);
        });
    } catch (err) {
        list.innerHTML = '<tr><td colspan="7">Failed to load suppliers.</td></tr>';
    }
}

document.getElementById('search-btn').addEventListener('click', fetchSuppliers);
document.getElementById('search').addEventListener('keydown',e=>{if(e.key==='Enter'){fetchSuppliers()}});
document.getElementById('status-filter').addEventListener('change', fetchSuppliers);

// Reset: clears the search box and status filter back to defaults and
// reloads the unfiltered list -- new in the 2026-10-27 redesign, same
// toolbar the Search button already lives in.
document.getElementById('reset-btn').addEventListener('click', () => {
    document.getElementById('search').value = '';
    document.getElementById('status-filter').value = '';
    fetchSuppliers();
});

// Only one row's kebab dropdown is ever open at a time (mirrors
// buildings-overview.php's openRoomCardMenuWrap pattern).
let sdOpenKebabWrap = null;
function sdCloseKebabMenu() {
    if (sdOpenKebabWrap) {
        sdOpenKebabWrap.classList.remove('open');
        sdOpenKebabWrap = null;
    }
}
document.addEventListener('click', sdCloseKebabMenu);

document.getElementById('list').addEventListener('click', async (e) => {
    const editBtn = e.target.closest('.edit');
    const historyBtn = e.target.closest('.history');
    const kebabToggle = e.target.closest('.kebab-toggle');
    const deleteBtn = e.target.closest('.delete');

    if (kebabToggle) {
        e.stopPropagation();
        const wrap = kebabToggle.closest('.sd-kebab-wrap');
        const wasOpen = wrap.classList.contains('open');
        sdCloseKebabMenu();
        if (!wasOpen) {
            wrap.classList.add('open');
            sdOpenKebabWrap = wrap;
        }
        return;
    }

    if (deleteBtn) {
        e.stopPropagation();
        sdCloseKebabMenu();
        const id = deleteBtn.dataset.id;
        // UI.systemConfirm() renders its message via innerHTML (utils.js),
        // so the supplier name (free-text, attacker-controllable by any
        // maintenance_admin/staff who can create suppliers) must be escaped
        // here -- same as every other place this page interpolates a
        // supplier name into markup.
        const name = smEscapeHtml(deleteBtn.dataset.name || 'this supplier');
        const confirmed = await UI.systemConfirm(
            `Are you sure you want to delete "${name}"? This cannot be undone.`,
            'Delete', 'Cancel', 'danger'
        );
        if (!confirmed) return;

        try {
            const res = await fetch(apiPrefix + '/' + encodeURIComponent(id), {method:'DELETE', credentials:'same-origin'});
            const json = await res.json();
            if (!json.success) { Components.alert(json.message || 'Failed to delete supplier', 'danger'); return }
            Components.toast(json.message || 'Supplier deleted', 'success');
            fetchSuppliers();
        } catch (err) {
            Components.alert('An error occurred while deleting. See console.', 'danger');
        }
        return;
    }

    if (editBtn) {
        const id = editBtn.dataset.id;
        const res = await fetch(apiPrefix + '/' + id, {credentials:'same-origin'});
        const json = await res.json(); if(!json.success){ Components.alert(json.message || 'Failed to load supplier', 'danger'); return }
        const s = json.data.supplier;
        document.getElementById('form-title').textContent = 'Edit Supplier';
        document.getElementById('form-subtitle').textContent = 'Supplier details';
        document.getElementById('submit-btn').textContent = 'Update Supplier';
        document.getElementById('supplier_id').value = s.id;
        document.getElementById('name').value = s.name || '';
        document.getElementById('contact_person').value = s.contact_person || '';
        document.getElementById('contact_email').value = s.contact_email || '';
        originalSupplierEmail = s.contact_email || '';
        document.getElementById('phone').value = s.phone || '';
        document.getElementById('address').value = s.address || '';
        document.getElementById('notes').value = s.notes || '';
        setSupplierStatus(s.status || 'active');
        document.getElementById('form').scrollIntoView({behavior:'smooth', block:'start'});
        return;
    }

    if (historyBtn) {
        openHistoryModal(historyBtn.dataset.id, historyBtn.dataset.name);
    }
});

async function openHistoryModal(supplierId, supplierName) {
    document.getElementById('hist-supplier-name').textContent = supplierName || '—';
    const container = document.getElementById('hist-receipts-container');
    container.innerHTML = '<div class="ui-empty-state"><strong>Loading...</strong></div>';
    const modal = document.getElementById('history-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');

    try {
        const res = await fetch(apiPrefix + '/' + encodeURIComponent(supplierId) + '/history', {credentials:'same-origin'});
        const json = await res.json();
        if (!res.ok || !json.success) throw new Error(json.message || 'Failed to load supplier history');

        const receipts = Array.isArray(json.data?.purchase_receipts) ? json.data.purchase_receipts : [];

        if (receipts.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No purchase receipts yet for this supplier.</strong></div>';
            return;
        }

        const rows = receipts.map((r) => `
            <tr>
                <td>${smEscapeHtml(r.or_number)}</td>
                <td>${smFormatDate(r.receipt_date)}</td>
                <td>${smReceiptStatusBadge(r.status)}</td>
                <td style="text-align:center;">${Number(r.item_count) || 0}</td>
                <td><a class="btn btn-sm btn-secondary" href="${smEscapeHtml(SD_PURCHASE_RECEIPTS_PAGE)}?reorder=${Number(r.id)}">Reorder</a></td>
            </tr>
        `).join('');

        container.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>OR Number</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align:center;">Items</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    } catch (err) {
        container.innerHTML = `<div class="ui-empty-state"><strong>${smEscapeHtml(err.message || 'Failed to load history.')}</strong></div>`;
    }
}

function closeHistoryModal() {
    const modal = document.getElementById('history-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}
document.getElementById('closeHistoryModal').addEventListener('click', closeHistoryModal);
document.getElementById('closeHistoryModalFooter').addEventListener('click', closeHistoryModal);
document.getElementById('history-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeHistoryModal();
});

// Status dropdown: open/close + outside-click close. Same open/close shape
// as the per-row kebab menu above (sdOpenKebabWrap/sdCloseKebabMenu), kept as
// its own pair since this is a single dropdown on the form rather than one
// per table row.
const sdStatusDropdown = document.getElementById('status-dropdown');
const sdStatusDropdownBtn = document.getElementById('status-dropdown-btn');
function sdCloseStatusDropdown() {
    sdStatusDropdown.classList.remove('open');
    sdStatusDropdownBtn.setAttribute('aria-expanded', 'false');
}
sdStatusDropdownBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const wasOpen = sdStatusDropdown.classList.contains('open');
    sdCloseKebabMenu();
    sdCloseStatusDropdown();
    if (!wasOpen) {
        sdStatusDropdown.classList.add('open');
        sdStatusDropdownBtn.setAttribute('aria-expanded', 'true');
    }
});
sdStatusDropdown.querySelectorAll('.sd-status-dropdown-item').forEach((item) => {
    item.addEventListener('click', (e) => {
        e.stopPropagation();
        setSupplierStatus(item.dataset.status);
        sdCloseStatusDropdown();
    });
});
document.addEventListener('click', sdCloseStatusDropdown);

// Keeps the hidden #status input, the dropdown button's visible label, and
// the checkmark on the selected menu item in sync. The hidden input stays
// the single source of truth every other piece of code (resetForm, the edit
// handler, the submit payload) already reads/writes.
function setSupplierStatus(status) {
    const normalized = status === 'inactive' ? 'inactive' : 'active';
    document.getElementById('status').value = normalized;
    document.getElementById('status-dropdown-label').textContent = normalized === 'inactive' ? 'Inactive' : 'Active';
    document.querySelectorAll('#status-dropdown .sd-status-dropdown-item').forEach((item) => {
        const isSelected = item.dataset.status === normalized;
        item.classList.toggle('selected', isSelected);
        item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    });
}

function resetForm() {
    originalSupplierEmail = '';
    document.getElementById('form').reset();
    document.getElementById('supplier_id').value = '';
    setSupplierStatus('active');
    document.getElementById('form-title').textContent = 'Add New Supplier';
    document.getElementById('form-subtitle').textContent = 'Supplier details';
    document.getElementById('submit-btn').textContent = 'Create Supplier';
}

document.getElementById('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('supplier_id').value;
    if (!supplierEmailAllowed(document.getElementById('contact_email').value, Boolean(id), originalSupplierEmail)) {
        Components.alert('Enter a valid contact email using gmail.com or yahoo.com (maximum 255 characters).', 'danger');
        return;
    }
    const payload = {
        name: document.getElementById('name').value,
        contact_person: document.getElementById('contact_person').value,
        contact_email: document.getElementById('contact_email').value,
        phone: document.getElementById('phone').value,
        address: document.getElementById('address').value,
        notes: document.getElementById('notes').value,
        status: document.getElementById('status').value,
    };
    let url = apiPrefix; let method = 'POST'; if(id){url = apiPrefix + '/' + id; method='PATCH'}
    const res = await fetch(url,{method,credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const json = await res.json(); if(!json.success){ Components.alert(json.errors?.contact_email?.[0] || json.message || 'Failed to save supplier', 'danger'); return }
    Components.toast(json.message || 'Supplier saved', 'success');
    resetForm();
    fetchSuppliers();
});

document.getElementById('clear').addEventListener('click', resetForm);

// Paint the Status dropdown's initial label/checkmark to match the hidden
// #status input's default value="active" in the markup.
setSupplierStatus('active');

fetchSuppliers();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
