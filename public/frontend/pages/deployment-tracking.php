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

$_dtUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? [];
$_dtRole = strtolower(trim((string)($_dtUser['role'] ?? '')));

if (!in_array($_dtRole, ['super_admin', 'maintenance_admin'], true)) {
    header('Location: ' . public_url('/dashboard'));
    exit;
}

$pageTitle = 'Deployment Tracking - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container deployment-tracking-page" style="margin-top:16px;">
    <section class="deployment-tracking-page__header">
        <div class="deployment-tracking-page__title-group">
            <p class="deployment-tracking-page__eyebrow">Released Inventory Visibility</p>
            <h1 class="deployment-tracking-page__title">Deployment Tracking</h1>
            <p class="deployment-tracking-page__description">Every item in a room — released through Dispatch, or recorded as existing equipment from before the system — with its source purchase receipt where available.</p>
        </div>
    </section>

    <section class="deployment-tracking-panel">
        <div class="deployment-tracking-panel__header">
            <div>
                <h2 class="deployment-tracking-panel__title">All Deployed Items</h2>
                <p class="deployment-tracking-panel__description">Track deployed inventory by room, department, dispatch, and purchase source.</p>
            </div>
            <div class="deployment-tracking-panel__header-actions">
                <p id="dt-result-count" class="deployment-tracking-panel__count text-muted" role="status" aria-live="polite">Loading results...</p>
                <button type="button" class="btn deployment-tracking-page__primary-action" id="dt-scan-qr-btn">Scan QR</button>
            </div>
        </div>

        <div class="deployment-tracking-toolbar">
            <div class="deployment-tracking-toolbar__search">
                <label class="deployment-tracking-toolbar__label" for="dt-search">Search</label>
                <input type="search" id="dt-search" class="form-control deployment-tracking-toolbar__control" placeholder="Search deployed items...">
            </div>
            <div class="deployment-tracking-toolbar__filter">
                <label class="deployment-tracking-toolbar__label" for="dt-room">Room</label>
                <select id="dt-room" class="form-control deployment-tracking-toolbar__control">
                    <option value="">All Rooms</option>
                </select>
            </div>
            <div class="deployment-tracking-toolbar__filter">
                <label class="deployment-tracking-toolbar__label" for="dt-dept">Department</label>
                <select id="dt-dept" class="form-control deployment-tracking-toolbar__control">
                    <option value="">All Departments</option>
                </select>
            </div>
            <div class="deployment-tracking-toolbar__action">
                <button type="button" class="btn deployment-tracking-page__ghost-action" id="dt-filter-clear">Clear Filters</button>
            </div>
        </div>

        <div id="dt-deployed-container" class="deployment-tracking-table-shell">
            <div class="ui-empty-state ui-fade-in" aria-live="polite">
                <strong>Loading deployed items...</strong>
                <div class="ui-skeleton-list" style="margin-top: 12px;">
                    <div class="ui-skeleton-row w-90"></div>
                    <div class="ui-skeleton-row w-75"></div>
                    <div class="ui-skeleton-row w-55"></div>
                </div>
            </div>
        </div>
    </section>

    <section class="deployment-tracking-trace-panel">
        <div class="deployment-tracking-trace-panel__header">
            <div>
                <h2 class="deployment-tracking-trace-panel__title">Trace by OR Number</h2>
                <p class="deployment-tracking-trace-panel__description">Search receipts directly to see where received items were ultimately deployed.</p>
            </div>
        </div>

        <div class="deployment-tracking-trace-toolbar">
            <div class="deployment-tracking-trace-toolbar__search">
                <label class="deployment-tracking-toolbar__label" for="dt-or-input">OR Number</label>
                <input type="text" id="dt-or-input" class="form-control deployment-tracking-toolbar__control" placeholder="Enter OR number (e.g. OR-2026-001)">
            </div>
            <div class="deployment-tracking-trace-toolbar__action-group">
                <button type="button" class="btn deployment-tracking-page__primary-action" id="dt-or-search-btn">Search</button>
                <button type="button" class="btn deployment-tracking-page__ghost-action" id="dt-or-clear-btn">Clear</button>
            </div>
        </div>

        <p class="deployment-tracking-trace-panel__hint">Partial matches are supported. Searches by OR number only.</p>

        <div id="dt-or-results" class="deployment-tracking-trace-results">
            <div class="ui-empty-state">
                <strong>Enter an OR number above to trace items.</strong>
                <span>Partial matches are supported.</span>
            </div>
        </div>
    </section>
</main>

<div id="dt-scan-modal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="dt-scan-modal-title" aria-modal="true">
    <div class="modal-content dt-scan-modal-content">
        <div class="modal-header">
            <h2 id="dt-scan-modal-title" class="modal-title">Scan Item QR Code</h2>
            <button class="modal-close" type="button" aria-label="Close scanner">&times;</button>
        </div>
        <div class="modal-body dt-scan-modal-body">
            <div id="dt-scan-video-shell" class="dt-scan-video-shell">
                <video id="dt-scan-video" playsinline muted></video>
            </div>
            <p id="dt-scan-status" class="dt-scan-status" role="status" aria-live="polite">Point the camera at the item's QR code.</p>
            <!--
                Lookup result — populated by dtRenderScanResult() after a
                successful scan. Stays hidden (and the camera feed stays
                visible) until a dispatch is actually found; per the user's
                explicit choice this replaces the old auto-verify/auto-close
                flow with a stay-on-the-modal info display, so the item's
                details can actually be read instead of the modal closing the
                instant a code is recognized.
            -->
            <div id="dt-scan-result" class="dt-scan-result" hidden>
                <div class="dt-scan-result__header">
                    <span class="dt-scan-result__dispatch-code" id="dt-scan-result-code"></span>
                    <span id="dt-scan-result-status"></span>
                </div>
                <dl class="dt-scan-result__meta">
                    <div><dt>Room / Lab</dt><dd id="dt-scan-result-room"></dd></div>
                    <div><dt>Department</dt><dd id="dt-scan-result-dept"></dd></div>
                    <div><dt>Deployed</dt><dd id="dt-scan-result-date"></dd></div>
                </dl>
                <ul class="dt-scan-result__items" id="dt-scan-result-items"></ul>
                <button type="button" class="btn deployment-tracking-page__ghost-action" id="dt-scan-again-btn">Scan Another Item</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/vendor/jsQR.js?v=1.4.0')); ?>"></script>

<script>
const DT_API = '/api/deployment-tracking';
let dtFilterOptionsLoaded = false;

function dtEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function dtMutedDash() {
    return '<span class="deployment-tracking-muted-cell">&mdash;</span>';
}

/**
 * Loading-state markup. Mirrors getDispatchLoadingMarkup() in dispatches.php
 * exactly (same .ui-empty-state/.ui-fade-in wrapper, same .ui-skeleton-list
 * row widths) — reusing the shared skeleton components already defined in
 * styles.css and already consumed by Dispatches and Inventory, rather than
 * the plain "Loading..." text this page previously showed on every re-fetch.
 * `message` is parameterised (Dispatches hardcodes its own) so this one
 * helper can serve both the deployed-items table and the OR-number search,
 * keeping their wording — and the initial PHP-rendered state above — in sync
 * instead of drifting into different phrasing per call site.
 */
function dtLoadingMarkup(message) {
    return `
        <div class="ui-empty-state ui-fade-in" aria-live="polite">
            <strong>${dtEscapeHtml(message)}</strong>
            <div class="ui-skeleton-list" style="margin-top: 12px;">
                <div class="ui-skeleton-row w-90"></div>
                <div class="ui-skeleton-row w-75"></div>
                <div class="ui-skeleton-row w-55"></div>
            </div>
        </div>
    `;
}

/**
 * Text cell renderer.
 *
 * `variant` drives the Section 4 hierarchy — previously every emphasised cell
 * used one 700-weight treatment, so Item Name, Source OR and Dispatch Code all
 * shouted equally and nothing read as primary:
 *   'item'   — the row's subject. Largest, heaviest, full text colour.
 *   'code'   — identifiers (OR / dispatch code). Accent-coloured, 600.
 *   'muted'  — supporting detail (supplier, department). Faint.
 *   default  — plain body text.
 * `strong: true` is kept as an alias for 'item' so existing calls stay valid.
 */
function dtTextCell(value, options = {}) {
    const variant = options.variant || (options.strong ? 'item' : '');
    const fallback = options.fallback || dtMutedDash();

    if (value === null || value === undefined || value === '') {
        return fallback;
    }

    const content = dtEscapeHtml(value);
    // Kept as <strong> rather than <span>: this is how the cell was already
    // marked up, and dropping it would quietly change the semantics exposed to
    // assistive tech in a pass that is supposed to be visual only.
    if (variant === 'item') return `<strong class="deployment-tracking-item-cell">${content}</strong>`;
    if (variant === 'code') return `<span class="deployment-tracking-code-cell">${content}</span>`;
    if (variant === 'muted') return `<span class="deployment-tracking-muted-cell">${content}</span>`;
    // 'primary' — a populated value that isn't the row subject or an
    // identifier, but still shouldn't read as unstyled filler text (e.g.
    // Room/Lab). Mirrors .dispatches-cell-primary, which Dispatches applies
    // to every one of its non-subject cells (Department, Room, Approver...)
    // so nothing in that table ever renders as bare text. Deployment
    // Tracking's Room/Lab column previously fell through to the `default`
    // branch below with zero class — the only cell in the row with no
    // weight/size treatment at all, which broke the row's visual rhythm.
    if (variant === 'primary') return `<span class="deployment-tracking-cell-primary">${content}</span>`;
    return content;
}

// Finding #2 (Task 26.1) — was a bare `toLocaleDateString()` with no options,
// which renders as a raw locale-numeric date ("8/5/2026") — ambiguous
// internationally (M/D/Y vs D/M/Y) and visibly different from every date on
// the Dispatches page. Matched to dspFormatDateParts()'s exact date options
// in dispatches.php so both pages render "Aug 05, 2026" identically.
function dtFormatDate(d) {
    if (!d) return dtMutedDash();
    try {
        return dtEscapeHtml(new Date(d).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }));
    } catch (e) {
        return dtEscapeHtml(String(d));
    }
}

/**
 * Dispatch status badge.
 *
 * These are dispatch workflow statuses — the same domain values Dispatches
 * renders — so they now use the shared `.badge badge-{status}` classes from
 * color-scheme.css instead of the palette this page used to hardcode. That
 * hardcoded set disagreed with Dispatches (it painted "released" blue where
 * Dispatches paints it green), which is exactly the inconsistency this pass
 * exists to remove. Markup mirrors dspStatusBadge() in dispatches.php.
 */
function dtStatusBadge(status) {
    const normalized = String(status || '').toLowerCase().replace(/[^a-z0-9-]/g, '');
    const label = String(status || '').replace(/\b\w/g, (c) => c.toUpperCase());
    return `<span class="badge badge-${dtEscapeHtml(normalized)} deployment-status-badge" data-status="${dtEscapeHtml(normalized)}">`
        + '<span class="deployment-status-dot" aria-hidden="true"></span>'
        + `${dtEscapeHtml(label || 'Unknown')}</span>`;
}

/**
 * Receipt posted/draft badge. Purchase Receipts renders this pair with inline
 * styles rather than a reusable class, so there is no class there to reuse —
 * mapping to the shared success/warning variants keeps the same green/amber
 * semantics while staying theme-aware.
 */
function dtReceiptBadge(status) {
    const posted = status === 'posted';
    const variant = posted ? 'badge-success' : 'badge-warning';
    return `<span class="badge ${variant} deployment-status-badge" data-status="${posted ? 'posted' : 'draft'}">`
        + '<span class="deployment-status-dot" aria-hidden="true"></span>'
        + `${posted ? 'Posted' : 'Draft'}</span>`;
}

/**
 * Deployment-source badge (TASK 36 PHASE 2). Distinguishes the two
 * legitimate-but-separate deployment workflows on sight: dispatch-sourced
 * rows (`deployment_source === 'dispatch'`) versus room_asset rows created
 * by the Deploy-to-Room flow (`deployment_source === 'direct'`). Deliberately
 * never labels a 'direct' row with a dispatch code or vice versa — see
 * DeploymentTrackingController::index() for why a direct deployment never
 * has a real dispatch_code to show. Uses the same shared `.badge` variants
 * as dtStatusBadge()/dtReceiptBadge() above rather than introducing new
 * one-off styling.
 */
function dtDeploymentSourceBadge(source) {
    const isDirect = source === 'direct';
    const variant = isDirect ? 'badge-info' : 'badge-primary';
    // Items placed in a room without a dispatch are the ones recorded with
    // Buildings Overview "Record Existing Item" (equipment that was already
    // in the room before the system was used).
    const label = isDirect ? 'Existing (Pre-system)' : 'Dispatch';
    return `<span class="badge ${variant} deployment-status-badge" data-source="${dtEscapeHtml(source || 'dispatch')}">`
        + '<span class="deployment-status-dot" aria-hidden="true"></span>'
        + `${label}</span>`;
}

function dtNotify(message, type = 'danger') {
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

async function dtFetch(url, options = {}) {
    const fetcher = window.Components && typeof Components.fetchJson === 'function'
        ? Components.fetchJson
        : async (u, o) => {
            const r = await fetch(u, o);
            return { response: r, data: await r.json() };
        };
    return fetcher(url, options);
}

function dtSetResultCount(text) {
    const target = document.getElementById('dt-result-count');
    if (target) {
        target.textContent = text;
    }
}

async function searchByOr() {
    const q = document.getElementById('dt-or-input').value.trim();
    const resultsEl = document.getElementById('dt-or-results');

    if (!q) {
        resultsEl.innerHTML = '<div class="ui-empty-state"><strong>Enter an OR number above to trace items.</strong>'
            + '<span>Partial matches are supported.</span></div>';
        return;
    }

    resultsEl.innerHTML = dtLoadingMarkup('Searching receipts...');

    try {
        const params = new URLSearchParams({ q });
        const { data: payload } = await dtFetch(`${DT_API}/search?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!payload || !payload.success) {
            throw new Error((payload && payload.message) || 'Failed to search');
        }

        const rows = Array.isArray(payload.data?.rows) ? payload.data.rows : [];
        const receipts = Array.isArray(payload.data?.receipts) ? payload.data.receipts : [];

        if (rows.length === 0) {
            const message = payload.data?.message || `No items found for OR number matching "${q}".`;
            resultsEl.innerHTML = `<div class="ui-empty-state"><strong>${dtEscapeHtml(message)}</strong></div>`;
            return;
        }

        let html = '';

        if (receipts.length > 0) {
            html += '<div class="deployment-tracking-receipt-grid">';
            receipts.forEach((pr) => {
                html += '<article class="deployment-tracking-receipt-card">';
                html += `<div class="deployment-tracking-receipt-card__code">${dtEscapeHtml(pr.or_number)}</div>`;
                html += '<div class="deployment-tracking-receipt-card__meta">';
                html += `<span>${dtEscapeHtml(pr.supplier_name || 'Unknown supplier')}</span>`;
                html += `<span>${dtFormatDate(pr.receipt_date)}</span>`;
                html += dtReceiptBadge(pr.status);
                html += '</div></article>';
            });
            html += '</div>';
        }

        html += '<div class="table-responsive"><table class="table deployment-tracking-table deployment-tracking-table--trace"><thead><tr>'
            + '<th scope="col">OR Number</th>'
            + '<th scope="col">Supplier</th>'
            + '<th scope="col">Date Received</th>'
            + '<th scope="col" class="deployment-tracking-table__item-head">Item Name</th>'
            + '<th scope="col" class="deployment-tracking-table__qty">Qty Received</th>'
            + '<th scope="col">Deployed To</th>'
            + '<th scope="col">Dispatch Code</th>'
            + '<th scope="col">Dispatch Status</th>'
            + '</tr></thead><tbody>';

        rows.forEach((row) => {
            const deployedToSubLabel = row.room_note || row.department_name || '';
            const deployedTo = row.room_name
                ? `<div class="deployment-tracking-location-cell">${dtEscapeHtml(row.room_name)}${deployedToSubLabel ? `<span>${dtEscapeHtml(deployedToSubLabel)}</span>` : ''}</div>`
                : '<span class="deployment-tracking-muted-cell deployment-tracking-muted-cell--italic">In Inventory / Not Deployed</span>';

            html += '<tr>';
            html += `<td>${dtTextCell(row.or_number, { variant: 'code' })}</td>`;
            html += `<td>${dtTextCell(row.supplier_name, { variant: 'muted' })}</td>`;
            html += `<td class="deployment-tracking-table__date-cell">${dtFormatDate(row.receipt_date)}</td>`;
            html += `<td class="deployment-tracking-table__item-cell">${dtTextCell(row.receipt_item_name, { variant: 'item' })}</td>`;
            html += `<td class="deployment-tracking-table__qty">${dtTextCell(row.quantity_received)} ${dtTextCell(row.unit || 'pc', { fallback: 'pc' })}</td>`;
            html += `<td>${deployedTo}</td>`;
            html += `<td>${dtTextCell(row.dispatch_code, { variant: 'code' })}</td>`;
            html += `<td>${row.dispatch_status ? dtStatusBadge(row.dispatch_status) : dtMutedDash()}</td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        resultsEl.innerHTML = html;
    } catch (err) {
        resultsEl.innerHTML = '<div class="ui-empty-state"><strong>Search failed.</strong>'
            + '<span>Check your connection and try the search again.</span></div>';
        dtNotify(err.message || 'Unable to search.');
    }
}

// -----------------------------------------------------------------------
// "Scan QR" — Deployment Tracking's camera-based item LOOKUP.
//
// A QR sticker is physically attached to a dispatched item (see
// dispatch-labels.js, which prints one per unit); it encodes a URL to
// /dispatches/{id}. Scanning it here is a stateless read: fetch that
// dispatch (GET /api/dispatches/{id} — the same endpoint the Dispatches
// page's own detail view already uses) and display its items/room/
// department/date in-modal. There is no "Verified" status and nothing is
// written back to the database — this was previously an automatic
// verify-on-scan mutation (POST .../verify) and was redesigned into a plain
// lookup because that never matched the intended physical workflow (a QR
// sticker identifying where an item was deployed, not a presence check).
// -----------------------------------------------------------------------
let dtScanStream = null;
let dtScanRafId = null;
let dtScanBusy = false;
let dtScanVideo = null;
let dtScanCanvas = null;
let dtScanCtx = null;

function dtStopScan() {
    if (dtScanRafId) {
        cancelAnimationFrame(dtScanRafId);
        dtScanRafId = null;
    }
    if (dtScanStream) {
        dtScanStream.getTracks().forEach((track) => track.stop());
        dtScanStream = null;
    }
    if (dtScanVideo) {
        dtScanVideo.pause();
        dtScanVideo.srcObject = null;
    }
    dtScanBusy = false;
}

function dtScanTick() {
    if (!dtScanVideo || dtScanVideo.readyState !== dtScanVideo.HAVE_ENOUGH_DATA) {
        dtScanRafId = requestAnimationFrame(dtScanTick);
        return;
    }

    dtScanCanvas.width = dtScanVideo.videoWidth;
    dtScanCanvas.height = dtScanVideo.videoHeight;
    dtScanCtx.drawImage(dtScanVideo, 0, 0, dtScanCanvas.width, dtScanCanvas.height);
    const imageData = dtScanCtx.getImageData(0, 0, dtScanCanvas.width, dtScanCanvas.height);
    const code = window.jsQR ? window.jsQR(imageData.data, imageData.width, imageData.height) : null;

    if (code && code.data && !dtScanBusy) {
        const match = code.data.match(/\/dispatches\/(\d+)/);
        if (match) {
            dtScanBusy = true;
            dtLookupScannedDispatch(parseInt(match[1], 10));
            return;
        }
        const statusEl = document.getElementById('dt-scan-status');
        if (statusEl) statusEl.textContent = 'QR code not recognized as an item label. Try again.';
    }

    dtScanRafId = requestAnimationFrame(dtScanTick);
}

/**
 * Renders the looked-up dispatch into #dt-scan-result and reveals it,
 * hiding the camera feed underneath it (the feed is stopped via
 * dtStopScan() by the caller — scanning pauses while the result is shown,
 * resuming only if the user taps "Scan Another Item").
 */
function dtRenderScanResult(dispatch) {
    const items = Array.isArray(dispatch.items) ? dispatch.items : [];

    document.getElementById('dt-scan-result-code').textContent = dispatch.dispatch_code || `Dispatch #${dispatch.id}`;
    document.getElementById('dt-scan-result-status').innerHTML = dtStatusBadge(dispatch.status);
    document.getElementById('dt-scan-result-room').textContent = dispatch.room_name || dispatch.room_note || '—';
    document.getElementById('dt-scan-result-dept').textContent = dispatch.department_name || '—';
    document.getElementById('dt-scan-result-date').textContent = dtFormatDate(dispatch.approved_at || dispatch.created_at).replace(/<[^>]+>/g, '');

    const itemsEl = document.getElementById('dt-scan-result-items');
    itemsEl.innerHTML = items.length
        ? items.map((entry) => {
            const name = entry.item?.item_name || entry.item?.name || 'Item';
            const qty = entry.quantity ?? entry.qty ?? '';
            return `<li><strong>${dtEscapeHtml(name)}</strong>${qty ? ` &times; ${dtEscapeHtml(qty)}` : ''}</li>`;
        }).join('')
        : '<li class="deployment-tracking-muted-cell">No item details available.</li>';

    document.getElementById('dt-scan-video-shell').hidden = true;
    document.getElementById('dt-scan-result').hidden = false;

    const statusEl = document.getElementById('dt-scan-status');
    if (statusEl) statusEl.textContent = 'Item found. Scan another, or close this window.';
}

async function dtLookupScannedDispatch(dispatchId) {
    const statusEl = document.getElementById('dt-scan-status');
    if (statusEl) statusEl.textContent = 'Item found — loading details...';

    try {
        const { response, data: payload } = await dtFetch(`/api/dispatches/${dispatchId}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok || !payload || !payload.success) {
            throw new Error((payload && payload.message) || 'Failed to look up this item.');
        }

        const dispatch = payload.data?.dispatch;
        if (!dispatch) {
            throw new Error('Item details were not found.');
        }

        dtStopScan();
        dtRenderScanResult(dispatch);
    } catch (err) {
        if (statusEl) statusEl.textContent = err.message || 'Unable to look up this item. Point the camera at the code again.';
        dtScanBusy = false;
        dtScanRafId = requestAnimationFrame(dtScanTick);
    }
}

async function dtResumeScan() {
    document.getElementById('dt-scan-result').hidden = true;
    document.getElementById('dt-scan-video-shell').hidden = false;
    await dtStartScanCamera();
}

async function dtStartScanCamera() {
    const statusEl = document.getElementById('dt-scan-status');
    if (statusEl) statusEl.textContent = 'Point the camera at the item\'s QR code.';

    dtScanVideo = document.getElementById('dt-scan-video');
    dtScanCanvas = document.createElement('canvas');
    dtScanCtx = dtScanCanvas.getContext('2d', { willReadFrequently: true });
    dtScanBusy = false;

    try {
        dtScanStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        dtScanVideo.srcObject = dtScanStream;
        await dtScanVideo.play();
        dtScanRafId = requestAnimationFrame(dtScanTick);
    } catch (err) {
        if (statusEl) statusEl.textContent = 'Camera access denied or unavailable. Check browser permissions and try again.';
    }
}

async function dtOpenScanModal() {
    document.getElementById('dt-scan-result').hidden = true;
    document.getElementById('dt-scan-video-shell').hidden = false;
    UI.toggleModal('dt-scan-modal', true);
    await dtStartScanCamera();
}

new MutationObserver((mutations) => {
    mutations.forEach((m) => {
        if (m.attributeName === 'aria-hidden' && document.getElementById('dt-scan-modal').getAttribute('aria-hidden') === 'true') {
            dtStopScan();
        }
    });
}).observe(document.getElementById('dt-scan-modal'), { attributes: true });

async function loadDeployed() {
    const container = document.getElementById('dt-deployed-container');
    container.innerHTML = dtLoadingMarkup('Loading deployed items...');

    const q = document.getElementById('dt-search').value.trim();
    const roomId = document.getElementById('dt-room').value;
    const deptId = document.getElementById('dt-dept').value;

    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (roomId) params.set('room_id', roomId);
    if (deptId) params.set('department_id', deptId);

    try {
        const { data: payload } = await dtFetch(`${DT_API}?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!payload || !payload.success) {
            throw new Error((payload && payload.message) || 'Failed to load');
        }

        const rows = Array.isArray(payload.data?.rows) ? payload.data.rows : [];

        if (!dtFilterOptionsLoaded && payload.data?.filter_options) {
            const opts = payload.data.filter_options;

            const roomSel = document.getElementById('dt-room');
            const currentRoom = roomSel.value;
            while (roomSel.options.length > 1) roomSel.remove(1);
            (opts.rooms || []).forEach((r) => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.name;
                if (String(r.id) === currentRoom) opt.selected = true;
                roomSel.appendChild(opt);
            });

            const deptSel = document.getElementById('dt-dept');
            const currentDept = deptSel.value;
            while (deptSel.options.length > 1) deptSel.remove(1);
            (opts.departments || []).forEach((d) => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.name;
                if (String(d.id) === currentDept) opt.selected = true;
                deptSel.appendChild(opt);
            });

            dtFilterOptionsLoaded = true;
        }

        dtSetResultCount(`${rows.length} result${rows.length === 1 ? '' : 's'}`);

        if (rows.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No deployed items found.</strong><span>Try adjusting the filters.</span></div>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table deployment-tracking-table deployment-tracking-table--deployed"><thead><tr>'
            + '<th scope="col" class="deployment-tracking-table__item-head">Item Name</th>'
            // TASK 36 PHASE 2 — Deployment Type / Asset Code are new,
            // additive columns distinguishing the two deployment workflows
            // now unioned into this response (see DeploymentTrackingController
            // ::index()). Asset Code is only ever populated for 'direct' rows
            // (room_asset items); dispatch rows render the existing muted-dash
            // fallback via dtTextCell(), same as every other empty cell here.
            + '<th scope="col">Deployment Type</th>'
            + '<th scope="col">Asset Code</th>'
            + '<th scope="col" class="deployment-tracking-table__qty">Qty Deployed</th>'
            + '<th scope="col">Source OR</th>'
            + '<th scope="col">Supplier</th>'
            + '<th scope="col">Date Received</th>'
            + '<th scope="col">Room / Lab</th>'
            + '<th scope="col">Department</th>'
            + '<th scope="col">Dispatch Code</th>'
            + '<th scope="col">Dispatch Date</th>'
            // Status intentionally omitted here — loadDeployed() never sends a
            // `status` filter and DeploymentTrackingController::index() defaults
            // it to 'released', so every row in this table would always render
            // the same badge. A column that never varies is pure noise against
            // the "information density / scannability" goals of this pass. The
            // Trace-by-OR table below keeps its own Dispatch Status column
            // (`dtStatusBadge(row.dispatch_status)` in searchByOr()) since that
            // one genuinely varies per traced item.
            + '</tr></thead><tbody>';

        rows.forEach((row) => {
            html += '<tr>';
            html += `<td class="deployment-tracking-table__item-cell">${dtTextCell(row.item_name, { variant: 'item' })}</td>`;
            html += `<td>${dtDeploymentSourceBadge(row.deployment_source)}</td>`;
            html += `<td>${dtTextCell(row.asset_code, { variant: 'code' })}</td>`;
            html += `<td class="deployment-tracking-table__qty">${dtTextCell(row.dispatched_qty)}</td>`;
            html += `<td>${dtTextCell(row.source_or, { variant: 'code' })}</td>`;
            html += `<td>${dtTextCell(row.source_supplier, { variant: 'muted' })}</td>`;
            html += `<td class="deployment-tracking-table__date-cell">${dtFormatDate(row.source_receipt_date)}</td>`;
            const roomCell = row.room_name
                ? `<div class="deployment-tracking-location-cell">${dtEscapeHtml(row.room_name)}${row.room_note ? `<span>${dtEscapeHtml(row.room_note)}</span>` : ''}</div>`
                : dtMutedDash();
            html += `<td>${roomCell}</td>`;
            html += `<td>${dtTextCell(row.department_name, { variant: 'muted' })}</td>`;
            html += `<td>${dtTextCell(row.dispatch_code, { variant: 'code' })}</td>`;
            html += `<td class="deployment-tracking-table__date-cell">${dtFormatDate(row.dispatch_date)}</td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (err) {
        dtSetResultCount('Unable to load results');
        container.innerHTML = '<div class="ui-empty-state"><strong>Failed to load deployed items.</strong>'
            + '<span>Check your connection and try again.</span></div>';
        dtNotify(err.message || 'Unable to load.');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('dt-or-search-btn').addEventListener('click', searchByOr);
    document.getElementById('dt-or-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') searchByOr();
    });
    document.getElementById('dt-or-clear-btn').addEventListener('click', () => {
        document.getElementById('dt-or-input').value = '';
        document.getElementById('dt-or-results').innerHTML =
            '<div class="ui-empty-state"><strong>Enter an OR number above to trace items.</strong>'
            + '<span>Partial matches are supported.</span></div>';
    });

    document.getElementById('dt-search').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') loadDeployed();
    });
    document.getElementById('dt-room').addEventListener('change', loadDeployed);
    document.getElementById('dt-dept').addEventListener('change', loadDeployed);
    document.getElementById('dt-filter-clear').addEventListener('click', () => {
        document.getElementById('dt-search').value = '';
        document.getElementById('dt-room').value = '';
        document.getElementById('dt-dept').value = '';
        loadDeployed();
    });

    document.getElementById('dt-scan-qr-btn').addEventListener('click', dtOpenScanModal);
    document.getElementById('dt-scan-again-btn').addEventListener('click', dtResumeScan);

    loadDeployed();
});
</script>

<style>
.deployment-tracking-page {
    --deployment-surface: #ffffff;
    --deployment-surface-muted: #f8fafc;
    --deployment-surface-header: #f8fafc;
    --deployment-border: #dbe3f0;
    --deployment-border-strong: #cbd5e1;
    --deployment-text: #0f172a;
    --deployment-text-muted: #64748b;
    --deployment-text-faint: #94a3b8;
    --deployment-accent: #7c3aed;
    --deployment-row-odd: #ffffff;
    --deployment-row-even: #f8fafc;
    --deployment-row-hover: #f5f3ff;
    --deployment-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
    color: var(--deployment-text);
}

:root[data-theme-resolved='dark'] .deployment-tracking-page {
    --deployment-surface: #0f172a;
    --deployment-surface-muted: #111c31;
    --deployment-surface-header: #13203a;
    --deployment-border: #23314b;
    --deployment-border-strong: #31415d;
    --deployment-text: #e2e8f0;
    --deployment-text-muted: #94a3b8;
    --deployment-text-faint: #64748b;
    --deployment-accent: #a78bfa;
    --deployment-row-odd: #0f172a;
    --deployment-row-even: #111c31;
    --deployment-row-hover: #16233d;
    --deployment-shadow: 0 22px 48px rgba(2, 8, 23, 0.28);
}

.deployment-tracking-page__header {
    margin-bottom: 20px;
}

.deployment-tracking-page__eyebrow {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #7c3aed;
}

.deployment-tracking-page__title {
    margin: 0;
    font-size: clamp(1.75rem, 2.4vw, 2.35rem);
    line-height: 1.05;
    color: var(--deployment-text);
}

.deployment-tracking-page__description {
    margin: 10px 0 0;
    max-width: 62ch;
    color: var(--deployment-text-muted);
    font-size: 0.98rem;
}

.deployment-tracking-panel,
.deployment-tracking-trace-panel {
    background: var(--deployment-surface);
    border: 1px solid var(--deployment-border);
    border-radius: 16px;
    box-shadow: var(--deployment-shadow);
    overflow: hidden;
}

.deployment-tracking-trace-panel {
    margin-top: 18px;
}

.deployment-tracking-panel__header,
.deployment-tracking-trace-panel__header {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 18px;
    padding: 26px 26px 18px;
    border-bottom: 1px solid var(--deployment-border);
    background: var(--deployment-surface);
}

.deployment-tracking-panel__header-actions {
    display: flex;
    align-items: center;
    gap: 16px;
}

.dt-scan-modal-content {
    max-width: 420px;
}

.dt-scan-modal-body {
    padding: 4px 0 0;
    text-align: center;
}

.dt-scan-video-shell {
    background: #0f172a;
    border-radius: 12px;
    overflow: hidden;
    aspect-ratio: 1 / 1;
}

.dt-scan-video-shell video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.dt-scan-status {
    margin: 14px 0 0;
    color: var(--deployment-text-muted);
    font-size: 0.92rem;
}

/* Scan result — populated by dtRenderScanResult() after a successful
   lookup. Left-aligned (unlike the centered video/status above it) since
   it's now presenting a small data record rather than a camera viewfinder. */
.dt-scan-result {
    text-align: left;
    border: 1px solid var(--deployment-border);
    border-radius: 12px;
    padding: 16px;
    background: var(--deployment-surface-muted);
}

.dt-scan-result__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}

.dt-scan-result__dispatch-code {
    font-size: 14px;
    font-weight: 700;
    color: var(--deployment-accent);
}

.dt-scan-result__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin: 0 0 12px;
}

.dt-scan-result__meta dt {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--deployment-text-muted);
    margin: 0 0 2px;
}

.dt-scan-result__meta dd {
    margin: 0;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--deployment-text);
}

.dt-scan-result__items {
    list-style: none;
    margin: 0 0 14px;
    padding: 0;
    border-top: 1px solid var(--deployment-border);
}

.dt-scan-result__items li {
    padding: 8px 0;
    border-bottom: 1px solid var(--deployment-border);
    font-size: 13.5px;
    color: var(--deployment-text);
}

#dt-scan-again-btn {
    width: 100%;
}

/* font-weight is declared explicitly to match .dispatches-table-panel__title.
   Without it these <h2>s inherit the shared h1-h6 rule in styles.css, which
   pairs Poppins with a different weight than the Dispatches panel headings —
   the two pages' section titles did not read as the same component. */
.deployment-tracking-panel__title,
.deployment-tracking-trace-panel__title {
    margin: 0;
    font-size: 1.08rem;
    font-weight: 700;
    color: var(--deployment-text);
}

.deployment-tracking-panel__description,
.deployment-tracking-trace-panel__description {
    margin: 8px 0 0;
    color: var(--deployment-text-muted);
    font-size: 0.93rem;
}

.deployment-tracking-panel__count {
    margin: 0;
    white-space: nowrap;
    color: var(--deployment-text-muted) !important;
}

.deployment-tracking-toolbar,
.deployment-tracking-trace-toolbar {
    display: grid;
    gap: 14px;
    padding: 22px 26px;
    border-bottom: 1px solid var(--deployment-border);
    background: color-mix(in srgb, var(--deployment-surface) 86%, var(--deployment-surface-muted));
}

.deployment-tracking-toolbar {
    grid-template-columns: minmax(0, 1.7fr) minmax(180px, 0.75fr) minmax(180px, 0.75fr) auto;
}

.deployment-tracking-trace-toolbar {
    grid-template-columns: minmax(0, 1fr) auto;
}

.deployment-tracking-toolbar__label {
    display: block;
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--deployment-text-muted);
}

.deployment-tracking-toolbar__control,
.deployment-tracking-page__primary-action,
.deployment-tracking-page__ghost-action {
    min-height: 44px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

.deployment-tracking-toolbar__control {
    width: 100%;
    padding: 0 14px;
    background: var(--deployment-surface);
    color: var(--deployment-text);
    border: 1px solid var(--deployment-border-strong);
    box-shadow: none;
}

.deployment-tracking-toolbar__control:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.12);
    transform: none;
}

.deployment-tracking-toolbar__action,
.deployment-tracking-trace-toolbar__action-group {
    display: flex;
    align-items: end;
    gap: 10px;
}

.deployment-tracking-page__primary-action {
    padding: 10px 18px;
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
    border: 1px solid rgba(109, 40, 217, 0.38);
    color: #ffffff;
    box-shadow: none;
}

.deployment-tracking-page__primary-action:hover {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
}

.deployment-tracking-page__ghost-action {
    padding: 10px 16px;
    background: var(--deployment-surface);
    color: var(--deployment-text);
    border: 1px solid var(--deployment-border-strong);
    box-shadow: none;
}

.deployment-tracking-page__ghost-action:hover {
    background: var(--deployment-surface-muted);
    border-color: #a78bfa;
}

/* Section 9 — the buttons already matched Dispatches on height, padding, radius
   and hover, but neither page declared a visible keyboard focus ring on them.
   Added here rather than in the shared .btn rule, which is off-limits. */
.deployment-tracking-page__primary-action:focus-visible,
.deployment-tracking-page__ghost-action:focus-visible {
    outline: 2px solid #7c3aed;
    outline-offset: 2px;
}

/* 26px gutters throughout, matching the Dispatches panel rhythm (this page
   was on 24px, which read as a subtly different container width side by side). */
.deployment-tracking-table-shell,
.deployment-tracking-trace-results {
    padding: 0 26px 26px;
}

.deployment-tracking-trace-panel__hint {
    margin: 0 0 4px;
    padding: 16px 26px 0;
    color: var(--deployment-text-muted);
    font-size: 0.88rem;
}

/* Section 5 — empty states.
   Built on the shared .ui-empty-state component (styles.css), which already
   supplies the dashed border, 12px radius and centred text. What is added here
   is the Inventory Reports *proportion*: the tall 60px well, a 14px/600 title
   and a faint supporting line. The .ir-empty rules themselves cannot be reused
   directly — they are scoped under .ir-page inside inventory-reports.php, and
   lifting them into a shared stylesheet is out of bounds for this pass. */
.deployment-tracking-table-shell .ui-empty-state,
.deployment-tracking-trace-results .ui-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 24px;
    padding: 60px 24px;
    background: color-mix(in srgb, var(--deployment-surface-muted) 88%, transparent);
    border-color: var(--deployment-border-strong);
    color: var(--deployment-text-muted);
}

.deployment-tracking-table-shell .ui-empty-state strong,
.deployment-tracking-trace-results .ui-empty-state strong {
    margin-bottom: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--deployment-text);
}

.deployment-tracking-table-shell .ui-empty-state span,
.deployment-tracking-trace-results .ui-empty-state span {
    font-size: 13px;
    color: var(--deployment-text-faint);
}

/* Finding #1 (Task 26.1) follow-up — this page's empty-state override above
   is `display:flex; align-items:center`, so it shrink-wraps its children to
   their own content width. That's invisible for the plain-text empty states
   it was written for, but .ui-skeleton-list (a percentage-width grid, w-90/
   w-75/w-55) needs an actual pixel width to resolve those percentages
   against — inside a shrink-wrapped flex child it has none, so the skeleton
   bars rendered at ~0px. Dispatches never hit this because its equivalent
   .ui-empty-state override doesn't force flex/center layout, so its skeleton
   list is a normal block that stretches full-width by default. Restoring
   that same full-width behavior here, scoped to just the skeleton list.
   Confirmed via live screenshot before and after this fix. */
.deployment-tracking-table-shell .ui-skeleton-list,
.deployment-tracking-trace-results .ui-skeleton-list {
    width: 100%;
}

.deployment-tracking-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    padding-top: 22px;
}

/* min-width recalibrated after removing the redundant Status column from the
   main table (see loadDeployed()) — this table is now 9 columns, the same
   count as .dispatches-table, so it's set to the same 1040px floor rather
   than the 1140px that was sized for the old 10-column layout. */
.deployment-tracking-page .deployment-tracking-table {
    width: 100%;
    min-width: 1040px;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--deployment-surface);
    border: 1px solid var(--deployment-border);
    border-radius: 14px;
    overflow: hidden;
}

/* TASK 36 PHASE 2 — loadDeployed()'s table gained two additive columns
   (Deployment Type, Asset Code) so it now has 11 vs. the base rule's 9-column
   1040px floor. Scoped to the `--deployed` modifier only (applied solely to
   the main deployed-items table, not the Trace-by-OR table below, which is
   unaffected by this task and keeps the 9-column comment above unchanged for
   its own sizing). */
.deployment-tracking-page .deployment-tracking-table--deployed {
    min-width: 1280px;
}

.deployment-tracking-page .deployment-tracking-table thead,
.deployment-tracking-page .deployment-tracking-table thead tr,
.deployment-tracking-page .deployment-tracking-table thead th {
    background: var(--deployment-surface-header);
}

/* BUGFIX — the combined rule above must NOT carry a shared `display` value.
   It previously set `display: table-header-group` on thead, thead tr AND
   thead th together; only `tr` was ever corrected back to `table-row`
   afterward, so every <th> kept `table-header-group`, which is only valid on
   a <thead> itself. The browser then pulled each <th> out of normal table
   flow and stacked all ten headers into one collapsed column instead of a
   row — the actual on-screen bug this page shipped with. Mirrors dispatches.php
   (dispatches-table thead / thead tr / thead th), which sets these three
   display values as three separate rules for exactly this reason. */
.deployment-tracking-page .deployment-tracking-table thead {
    display: table-header-group;
}

.deployment-tracking-page .deployment-tracking-table thead tr {
    display: table-row;
}

.deployment-tracking-page .deployment-tracking-table thead th {
    display: table-cell;
}

/* Header typography matched to .dispatches-table th (dispatches.php) exactly —
   11.5px / 700 / 0.07em / uppercase / muted. The page previously used 13px
   sentence-case at 0.01em, which was the most obvious tell that this table
   belonged to a different design generation than Dispatches. */
.deployment-tracking-page .deployment-tracking-table th {
    padding: 16px 18px;
    border: 0;
    border-bottom: 1px solid var(--deployment-border);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--deployment-text-muted);
    white-space: nowrap;
}

.deployment-tracking-page .deployment-tracking-table tbody tr {
    display: table-row;
    background: var(--deployment-row-odd);
    transition: background-color 160ms ease;
}

.deployment-tracking-page .deployment-tracking-table tbody tr:nth-child(even) {
    background: var(--deployment-row-even);
}

.deployment-tracking-page .deployment-tracking-table tbody tr:nth-child(odd) {
    background: var(--deployment-row-odd);
}

.deployment-tracking-page .deployment-tracking-table tbody tr:hover {
    background: var(--deployment-row-hover);
}

.deployment-tracking-page .deployment-tracking-table td {
    display: table-cell;
    padding: 18px;
    border: 0;
    border-bottom: 1px solid var(--deployment-border);
    background: transparent;
    color: var(--deployment-text);
    vertical-align: middle;
    line-height: 1.35;
}

.deployment-tracking-page .deployment-tracking-table tbody tr:last-child td {
    border-bottom: 0;
}

/* Section 4 — data hierarchy.
   Item Name is the row subject and is the only cell allowed full weight at the
   larger size. Identifiers sit one step below in the accent colour (same role
   .dispatches-code-primary plays in Dispatches), and supporting detail drops to
   the faint tone so the eye lands on the item first. */
.deployment-tracking-item-cell {
    font-size: 14.5px;
    font-weight: 700;
    color: var(--deployment-text);
    letter-spacing: -0.005em;
}

.deployment-tracking-table__item-head,
.deployment-tracking-table__item-cell {
    min-width: 210px;
}

/* Dispatches' equivalent free-text field (the Items column's secondary
   line) is capped with max-width + ellipsis so no single row can widen the
   whole table. Item Name here had no such ceiling: with table-layout:auto,
   one long unbroken value (e.g. a "smoke-item-1782275354" style seed row)
   forces that column — and therefore every row's width — wider globally.
   Ellipsis isn't appropriate here (unlike Dispatches' secondary line, this
   IS the row's primary identifying text, so truncating it would hide the
   one thing a user is scanning for); instead the column is capped and long
   values wrap onto a second line within it, which keeps the table's overall
   width predictable without hiding data. */
.deployment-tracking-table__item-cell {
    max-width: 340px;
}

.deployment-tracking-item-cell {
    overflow-wrap: anywhere;
}

.deployment-tracking-code-cell {
    font-size: 13px;
    font-weight: 600;
    color: var(--deployment-accent);
    letter-spacing: 0.01em;
    white-space: nowrap;
}

/* Baseline treatment for a populated-but-not-emphasised value (currently
   Room/Lab). Sized/weighted to match .dispatches-cell-primary exactly, so
   this is the same visual "floor" every Dispatches cell already sits on —
   no cell in this table should render as plain unstyled text. */
.deployment-tracking-cell-primary {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--deployment-text);
}

.deployment-tracking-table__date-cell {
    color: var(--deployment-text-faint);
    white-space: nowrap;
    min-width: 120px;
}

.deployment-tracking-muted-cell {
    color: var(--deployment-text-muted);
}

.deployment-tracking-muted-cell--italic {
    font-style: italic;
}

/* BUGFIX — was a bare single-class selector (specificity 0,0,1,0), which lost
   to the shared `.table th, .table td { text-align: left; }` rule in
   styles.css (specificity 0,0,1,1). Every other structural rule in this file
   is deliberately prefixed with `.deployment-tracking-page .deployment-tracking-table`
   to outrank the shared table styles — this was the one column where that
   prefix was missing, so Qty Deployed / Qty Received rendered left-aligned
   instead of centered despite the CSS "declaring" center. Confirmed via
   getComputedStyle() against the live page before this fix. */
.deployment-tracking-page .deployment-tracking-table .deployment-tracking-table__qty {
    text-align: center;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

/* Two-line location cell, sized to .dispatches-cell-primary / -secondary so the
   room-over-department stack reads identically in both modules. The secondary
   line moves from 0.82rem to a flat 12px: the rem values elsewhere on this page
   are deliberate (they mirror Dispatches' panel typography), but inside the
   table Dispatches works in px, and mixing the two scales here made the
   sub-label drift against the equivalent Dispatches cell. */
.deployment-tracking-location-cell {
    display: flex;
    flex-direction: column;
    gap: 3px;
    line-height: 1.35;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--deployment-text);
}

.deployment-tracking-location-cell span {
    color: var(--deployment-text-faint);
    font-size: 12px;
    font-weight: 400;
}

/* Status badges.
   Colour now comes entirely from the shared .badge / .badge-{status} classes
   (styles.css + color-scheme.css) — the same source Dispatches draws from — so
   the twelve colour declarations that used to live here are gone. What remains
   is only the pill geometry, mirroring .dispatches-status-badge so the two
   pages produce an identical shape. The !important flags match Dispatches and
   are needed to beat the shared .badge defaults (8px radius, 4px 10px). */
/* Neutral fallback for a status with no shared .badge-{status} class (e.g. a
   null dispatch_status, which renders as "Unknown"). Wrapped in :where() so it
   carries zero specificity — .badge-released and friends are also single-class
   selectors, and this page's <style> block loads after color-scheme.css, so a
   normal rule here would outrank and flatten the shared status colours. */
:where(.deployment-status-badge) {
    background: rgba(100, 116, 139, 0.14);
    color: var(--deployment-text-muted);
    border: 1px solid rgba(100, 116, 139, 0.22);
}

.deployment-status-badge {
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

.deployment-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block;
    margin-right: 6px;
    flex-shrink: 0;
}

.deployment-tracking-receipt-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    padding-top: 24px;
}

.deployment-tracking-receipt-card {
    background: color-mix(in srgb, var(--deployment-surface-muted) 84%, var(--deployment-surface));
    border: 1px solid var(--deployment-border);
    border-radius: 14px;
    padding: 16px;
}

.deployment-tracking-receipt-card__code {
    font-size: 1rem;
    font-weight: 700;
    color: var(--deployment-text);
}

.deployment-tracking-receipt-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 10px;
    margin-top: 8px;
    color: var(--deployment-text-muted);
    font-size: 0.86rem;
}

@media (max-width: 1080px) {
    .deployment-tracking-toolbar {
        grid-template-columns: minmax(0, 1fr) minmax(180px, 0.7fr) minmax(180px, 0.7fr);
    }

    .deployment-tracking-toolbar__action {
        grid-column: 1 / -1;
        justify-content: flex-start;
    }
}

@media (max-width: 768px) {
    .deployment-tracking-panel__header,
    .deployment-tracking-trace-panel__header,
    .deployment-tracking-toolbar,
    .deployment-tracking-trace-toolbar,
    .deployment-tracking-table-shell,
    .deployment-tracking-trace-results,
    .deployment-tracking-trace-panel__hint {
        padding-left: 16px;
        padding-right: 16px;
    }

    .deployment-tracking-panel__header,
    .deployment-tracking-trace-panel__header {
        flex-direction: column;
        align-items: flex-start;
    }

    .deployment-tracking-toolbar,
    .deployment-tracking-trace-toolbar {
        grid-template-columns: 1fr;
    }

    .deployment-tracking-trace-toolbar__action-group {
        align-items: stretch;
        flex-wrap: wrap;
    }
}

/* Tablets only: keep a real, horizontally scrollable table. On phones
   (<= 640px) the table becomes one card per row instead — see
   assets/css/mobile-table-cards.css, which now supplies the data-label
   column names this block's comment below was guarding against. */
@media (min-width: 641px) and (max-width: 768px) {
    .deployment-tracking-page .deployment-tracking-table {
        min-width: 980px;
    }

    /* TASK 36 PHASE 2 — mobile counterpart to the desktop --deployed
       min-width override above. */
    .deployment-tracking-page .deployment-tracking-table--deployed {
        min-width: 1180px;
    }

    .deployment-tracking-page .deployment-tracking-table thead {
        display: table-header-group !important;
    }

    .deployment-tracking-page .deployment-tracking-table tbody tr {
        display: table-row !important;
        margin-bottom: 0;
        border: 0;
        border-radius: 0;
    }

    /* These !important flags counter the global stacked-card table rules in
       color-scheme.css (@media max-width:768px), which convert any .table into
       blocks with padding-left:50% and an attr(data-label) pseudo-element. This
       table emits no data-label attributes, so the stacked mode would render
       blank labels — the overrides keep it a real table that scrolls instead.
       The padding shorthand is restated in full because the shared rule sets
       `padding: 8px 12px`, and overriding padding-left alone left cells with
       mismatched 16px/12px gutters and 8px vertical breathing room. */
    .deployment-tracking-page .deployment-tracking-table th,
    .deployment-tracking-page .deployment-tracking-table td {
        display: table-cell !important;
        text-align: left !important;
        padding: 14px 16px !important;
    }

    /* Re-asserted after the blanket left-align above, which would otherwise
       strip the numeric column's centring on mobile only. */
    .deployment-tracking-page .deployment-tracking-table th.deployment-tracking-table__qty,
    .deployment-tracking-page .deployment-tracking-table td.deployment-tracking-table__qty {
        text-align: center !important;
    }

    .deployment-tracking-page .deployment-tracking-table td::before,
    .deployment-tracking-page .deployment-tracking-table td::after {
        content: none !important;
        display: none !important;
    }
}

@media (max-width: 540px) {
    .deployment-tracking-trace-toolbar__action-group .btn {
        width: 100%;
    }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
