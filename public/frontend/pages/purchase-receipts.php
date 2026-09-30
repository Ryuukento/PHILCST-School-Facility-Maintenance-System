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

if (!isset($_SESSION['user'])) {
    header('Location: ' . public_url('/frontend/pages/index.php'));
    exit;
}

$user     = $_SESSION['user'];
$userRole = $user['role'] ?? '';

$allowedRoles = ['super_admin', 'maintenance_admin', 'maintenance_staff'];
if (!in_array($userRole, $allowedRoles, true)) {
    header('Location: ' . public_url('/frontend/pages/dashboard.php'));
    exit;
}

$canPost   = in_array($userRole, ['super_admin', 'maintenance_admin', 'maintenance_staff'], true);
$receiptId = isset($_GET['id']) && (int)$_GET['id'] > 0 ? (int)$_GET['id'] : 0;
$isDetail  = $receiptId > 0;

$pageTitle = $isDetail ? 'Purchase Receipt Detail - SFMS' : 'Purchase Receipts - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<?php if (!$isDetail): ?>
<!-- ================================================================
     LIST VIEW
     ================================================================ -->
<main class="container" style="margin-top:16px;">
    <div class="card" style="border-left:3px solid var(--primary-color);">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2>Purchase Receipts</h2>
                <p class="text-muted mb-0">Record incoming stock with OR number and supplier</p>
            </div>
            <div class="d-flex gap-sm">
                <button type="button" class="btn btn-secondary" id="printReceiptListBtn" disabled><?php echo ui_icon('file-text'); ?> Print Receipts</button>
                <?php if ($canPost): ?>
                <button type="button" class="btn btn-primary" id="newReceiptBtn">+ New Receipt</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="receipts-container" class="table-responsive">
                <div class="ui-empty-state"><strong>Loading receipts...</strong></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal: New Receipt -->
<div id="new-receipt-modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" style="display:none;">
    <div class="modal-content" style="max-width:600px;width:100%;padding:0;background:var(--card-color);color:var(--text-primary);border:1px solid var(--border);border-radius:12px;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 24px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(139,92,246,0.15);border:1px solid rgba(139,92,246,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 5H7C5.9 5 5 5.9 5 7V19C5 20.1 5.9 21 7 21H17C18.1 21 19 20.1 19 19V7C19 5.9 18.1 5 17 5H15"/>
                        <rect x="9" y="3" width="6" height="4" rx="1"/>
                        <line x1="9" y1="12" x2="15" y2="12"/>
                        <line x1="9" y1="16" x2="13" y2="16"/>
                    </svg>
                </div>
                <div>
                    <h2 style="margin:0;font-size:16px;font-weight:700;line-height:1.3;">New Purchase Receipt</h2>
                    <p style="margin:2px 0 0;font-size:12px;opacity:0.55;line-height:1.4;">Record incoming stock with OR number and supplier</p>
                </div>
            </div>
            <button type="button" id="closeNewReceiptModal" aria-label="Close" style="background:none;border:none;cursor:pointer;padding:6px;opacity:0.5;color:inherit;font-size:22px;line-height:1;border-radius:6px;margin-left:12px;">×</button>
        </div>

        <!-- Form body -->
        <form id="new-receipt-form" style="overflow-y:auto;flex:1;">
            <div style="padding:24px;display:flex;flex-direction:column;gap:18px;">

                <!-- Row 1: OR Number + Receipt Date -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div>
                        <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">OR Number <span style="color:#ef4444;">*</span></label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;opacity:0.45;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                    <line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/>
                                    <line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>
                                </svg>
                            </span>
                            <input type="text" id="nr-or-number" class="form-control" placeholder="e.g. OR-2026-001" autocomplete="off" style="padding-left:32px;">
                        </div>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Receipt Date <span style="color:#ef4444;">*</span></label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;opacity:0.45;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                                    <path d="M16 2v4M8 2v4M3 10h18"/>
                                </svg>
                            </span>
                            <input type="date" id="nr-receipt-date" class="form-control" style="padding-left:32px;" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Row 2: Supplier Name (full width) -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Supplier Name <span style="color:#ef4444;">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;opacity:0.45;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 9h18v10a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                <path d="M3 9l2.45-4.9A2 2 0 017.24 3h9.52a2 2 0 011.79 1.1L21 9"/>
                                <line x1="12" y1="9" x2="12" y2="21"/>
                            </svg>
                        </span>
                        <input type="text" id="nr-supplier-name" class="form-control" placeholder="Supplier or vendor name" autocomplete="off" style="padding-left:32px;">
                    </div>
                </div>

                <!-- Row 3: Department + Received By -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div>
                        <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Department</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;opacity:0.45;z-index:1;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                                    <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                                </svg>
                            </span>
                            <select id="nr-department-id" class="form-control" style="padding-left:32px;">
                                <option value="">— Select department —</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Received By</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;line-height:0;opacity:0.45;z-index:1;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <select id="nr-received-by" class="form-control" style="padding-left:32px;">
                                <option value="">Loading users...</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Remarks (full width) -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Remarks</label>
                    <textarea id="nr-remarks" class="form-control" rows="3" placeholder="Optional notes about this receipt"></textarea>
                </div>

                <p id="new-receipt-error" style="color:#ef4444;margin:0;font-size:13px;display:none;"></p>
            </div>

            <!-- Footer -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 24px;border-top:1px solid var(--border);flex-shrink:0;">
                <span style="font-size:12px;opacity:0.45;">* Required fields</span>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="button" class="btn btn-secondary" id="cancelNewReceiptModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitNewReceiptBtn" style="display:inline-flex;align-items:center;gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="16"/>
                            <line x1="8" y1="12" x2="16" y2="12"/>
                        </svg>
                        Create Receipt
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ================================================================
     DETAIL VIEW
     ================================================================ -->
<main class="container" style="margin-top:16px;">

    <!-- Receipt header card -->
    <div class="card" style="border-left:3px solid var(--primary-color);">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2 id="receipt-title">Purchase Receipt</h2>
                <p class="text-muted mb-0" id="receipt-subtitle">Loading...</p>
            </div>
            <div class="d-flex gap-sm">
                <button type="button" class="btn btn-primary" id="printReceiptBtn" disabled><?php echo ui_icon('file-text'); ?> Print Receipt</button>
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/purchase-receipts.php')); ?>" class="btn btn-secondary"><?php echo ui_icon('arrow-left'); ?> Back to List</a>
            </div>
        </div>
        <div class="card-body" id="receipt-header-container">
            <div class="ui-empty-state"><strong>Loading receipt details...</strong></div>
        </div>
    </div>

    <!-- Line items card -->
    <div class="card" style="margin-top:14px;border-left:3px solid var(--primary-color);">
        <div class="card-header d-flex justify-between align-center">
            <h3>Line Items</h3>
            <div class="d-flex gap-sm" id="draft-actions" style="display:none;">
                <button type="button" class="btn btn-secondary" id="addItemBtn">+ Add Items</button>
                <?php if ($canPost): ?>
                <button type="button" class="btn btn-primary" id="postReceiptBtn">Post Receipt</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="items-container" class="table-responsive">
                <div class="ui-empty-state"><strong>Loading items...</strong></div>
            </div>
        </div>
    </div>

    <!-- Proof of Receipt card
         Suggested by the school's IT reviewer: let whoever records a purchase
         receipt attach a photo of the physical OR/receipt as documentation.
         Optional and independent of draft/posted status — this is
         documentation, not a stock-affecting field, so it can be attached or
         replaced at any time. -->
    <div class="card" style="margin-top:14px;border-left:3px solid var(--primary-color);">
        <div class="card-header">
            <h3>Proof of Receipt</h3>
        </div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">Optional. Attach a photo of the physical OR/receipt as documentation.</p>
            <div id="proof-image-preview" style="margin-bottom:12px;"></div>
            <div class="d-flex gap-sm align-center" style="flex-wrap:wrap;">
                <input type="file" id="proof-image-input" accept="image/jpeg,image/png,image/webp,image/gif" capture="environment">
                <button type="button" class="btn btn-secondary" id="uploadProofBtn"><?php echo ui_icon('file-text'); ?> Upload Photo</button>
            </div>
        </div>
    </div>

</main>

<!-- =====================================================================
     Modal: Add Items (bulk line-item entry)

     TASK H — replaces the old one-item-at-a-time "Add Line Item" modal. The
     fields per row are the same fields that modal collected (item name,
     category, quantity, unit); only the number of rows you can fill in before
     submitting has changed. The Line Items table below is untouched.
     ===================================================================== -->
<div id="add-items-modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="add-items-title" style="display:none;">
    <div class="modal-content pr-bulk-modal">

        <div class="modal-header pr-bulk-header">
            <div>
                <h2 class="modal-title" id="add-items-title">Add Items</h2>
                <p class="pr-bulk-subtitle">Add as many line items as you need, then save them all at once.</p>
            </div>
            <button class="modal-close" type="button" id="closeAddItemsModal" aria-label="Close">&times;</button>
        </div>

        <form id="add-items-form" class="pr-bulk-form">

            <!-- Column captions (desktop only; each row repeats them on narrow screens) -->
            <div class="pr-bulk-colhead" aria-hidden="true">
                <span>Item <b>*</b></span>
                <span>Category</span>
                <span>Qty <b>*</b></span>
                <span>Unit</span>
                <span></span>
            </div>

            <!-- Scrolls internally so the page never grows, even at 20 rows -->
            <div id="pr-bulk-rows" class="pr-bulk-rows"></div>

            <div class="pr-bulk-addrow">
                <button type="button" class="btn btn-secondary btn-sm" id="prAddAnotherRow">+ Add Another Item</button>
                <span class="pr-bulk-hint">Items that already exist in Inventory will have their quantity updated when this receipt is posted.</span>
            </div>

            <p id="add-items-error" class="pr-bulk-error" role="alert"></p>

            <div class="modal-footer pr-bulk-footer">
                <span class="pr-bulk-count" id="pr-bulk-count"></span>
                <div class="pr-bulk-footer-actions">
                    <button type="button" class="btn btn-secondary" id="cancelAddItemsModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitAddItemsBtn" disabled>Add Items</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Shared, fixed-position suggestion list for the searchable Item field.
     Lives outside the scrolling rows container so it is never clipped. -->
<div id="pr-item-suggest" class="pr-suggest" role="listbox" hidden></div>

<style>
/* ===========================================================================
   TASK H — bulk line-item entry modal.
   Page-local because this component exists only on the Purchase Receipt detail
   view. Colours come from the shared design tokens (purple border accents,
   light surfaces) so it matches the rest of the system; no token is redefined
   here and no shared stylesheet is modified.
   =========================================================================== */
.pr-bulk-modal {
    width: min(96vw, 840px);
    max-width: 840px;
    max-height: 92vh;
    padding: 0;
    display: flex;
    flex-direction: column;
    background: var(--surface, #fff);
    color: var(--text-light, #111827);
    border: 1px solid var(--purple-border, var(--border));
    border-radius: 14px;
    overflow: hidden;
}

.pr-bulk-header {
    margin: 0;
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--purple-border-light, var(--border));
    flex-shrink: 0;
    align-items: flex-start;
}

.pr-bulk-subtitle {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--muted-text, #6b7280);
}

.pr-bulk-form {
    display: flex;
    flex-direction: column;
    min-height: 0;
    flex: 1;
}

/* Column template is declared once and reused by the caption strip and by
   every row, so headings can never drift out of alignment with the fields. */
.pr-bulk-colhead,
.pr-bulk-row {
    display: grid;
    grid-template-columns: minmax(0, 2.4fr) minmax(0, 1.5fr) 88px 96px 36px;
    gap: 10px;
    align-items: start;
}

.pr-bulk-colhead {
    padding: 12px 22px 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--muted-text, #6b7280);
}

.pr-bulk-colhead b { color: #ef4444; font-weight: 700; }

.pr-bulk-rows {
    padding: 0 22px 4px;
    overflow-y: auto;
    min-height: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.pr-bulk-row {
    padding: 10px;
    border: 1px solid var(--purple-border-light, var(--border));
    border-radius: 10px;
    background: var(--surface, #fff);
}

.pr-bulk-row.pr-row-invalid {
    border-color: #ef4444;
    background: #fef2f2;
}

.pr-bulk-field { min-width: 0; position: relative; }

/* Per-field caption: redundant on desktop (the strip above says the same
   thing), essential once rows stack on narrow screens. */
.pr-field-label {
    display: none;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--muted-text, #6b7280);
    margin-bottom: 4px;
}

.pr-bulk-row .form-control {
    width: 100%;
    min-width: 0;
}

.pr-row-remove {
    width: 32px;
    height: 32px;
    margin-top: 2px;
    border-radius: 8px;
    border: 1px solid var(--purple-control, var(--border));
    background: var(--surface-muted, #f9fafb);
    color: #b91c1c;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.pr-row-remove:hover:not(:disabled) { background: #fee2e2; border-color: #ef4444; }
.pr-row-remove:disabled { opacity: 0.35; cursor: not-allowed; }

.pr-row-note {
    grid-column: 1 / -1;
    margin: 0;
    font-size: 12px;
    line-height: 1.4;
}

.pr-row-note.is-error { color: #b91c1c; }
.pr-row-note.is-info { color: var(--primary-purple-dark, #5b21b6); }
.pr-row-note.is-empty { display: none; }

.pr-bulk-addrow {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 22px 0;
    flex-shrink: 0;
}

.pr-bulk-hint {
    font-size: 12px;
    color: var(--muted-text, #6b7280);
    flex: 1;
    min-width: 200px;
}

.pr-bulk-error {
    margin: 10px 22px 0;
    padding: 9px 12px;
    border-radius: 8px;
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 13px;
    display: none;
}

.pr-bulk-error.is-visible { display: block; }

.pr-bulk-footer {
    margin: 0;
    padding: 14px 22px 18px;
    border-top: 1px solid var(--purple-border-light, var(--border));
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    flex-wrap: wrap;
    gap: 10px;
}

.pr-bulk-count { font-size: 12px; color: var(--muted-text, #6b7280); }

.pr-bulk-footer-actions { display: flex; gap: 10px; align-items: center; }

/* Searchable Item field — fixed position so the scrolling rows container
   cannot clip it. Populated from the existing /api/items inventory source. */
.pr-suggest {
    position: fixed;
    z-index: 2200;
    max-height: 220px;
    overflow-y: auto;
    background: var(--surface, #fff);
    border: 1px solid var(--purple-border, var(--border));
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
    padding: 4px;
}

.pr-suggest[hidden] { display: none; }

.pr-suggest-option {
    padding: 7px 10px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
    line-height: 1.35;
}

.pr-suggest-option:hover,
.pr-suggest-option.is-active { background: var(--purple-hover, #ede9fe); }

.pr-suggest-option small { display: block; color: var(--muted-text, #6b7280); font-size: 11px; }

.pr-suggest-empty { padding: 8px 10px; font-size: 12px; color: var(--muted-text, #6b7280); }

/* Briefly marks the rows that were just added, so the user can see the result
   of the bulk operation in the existing Line Items table. */
@keyframes prNewLineFlash {
    from { background: var(--purple-hover, #ede9fe); }
    to   { background: transparent; }
}

.pr-line-new td { animation: prNewLineFlash 2.4s ease-out; }

@media (max-width: 760px) {
    .pr-bulk-colhead { display: none; }

    .pr-bulk-row {
        grid-template-columns: 1fr 1fr;
        gap: 10px 10px;
    }

    .pr-bulk-field--item,
    .pr-bulk-field--category { grid-column: 1 / -1; }

    .pr-row-remove {
        grid-column: 1 / -1;
        width: 100%;
        height: 34px;
    }

    .pr-field-label { display: block; }

    .pr-bulk-header,
    .pr-bulk-rows,
    .pr-bulk-addrow,
    .pr-bulk-footer { padding-left: 14px; padding-right: 14px; }

    .pr-bulk-error { margin-left: 14px; margin-right: 14px; }

    .pr-bulk-footer-actions { width: 100%; }
    .pr-bulk-footer-actions .btn { flex: 1; }
}
</style>
<?php endif; ?>

<!-- Shared branded printout (logo letterhead), same as All Reports / Dispatches. -->
<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/sfms-print.js?v=20260928-1')); ?>"></script>
<script>
// ---------------------------------------------------------------------------
// Shared constants (PHP → JS)
// ---------------------------------------------------------------------------
const PR_RECEIPT_ID        = <?php echo $receiptId; ?>;
const PR_IS_DETAIL         = <?php echo $isDetail ? 'true' : 'false'; ?>;
const PR_CAN_POST          = <?php echo $canPost ? 'true' : 'false'; ?>;
const PR_SESSION_USER_ID   = <?php echo (int)($user['user_id'] ?? 0); ?>;
const PR_SESSION_USER_NAME = <?php echo json_encode($user['full_name'] ?? ''); ?>;

const PURCHASE_API  = window.SFMS_PUBLIC_URL('/api/purchase-receipts');

const PURCHASE_PAGE  = window.SFMS_PUBLIC_URL
    ? window.SFMS_PUBLIC_URL('/frontend/pages/purchase-receipts.php')
    : '/frontend/pages/purchase-receipts.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------
function prEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function prNotify(msg, type = 'danger') {
    // UI_BROWSER_DIALOG_REPLACEMENT — Components/UI are always loaded (see
    // includes/footer.php), so this always goes through the reusable
    // in-app modal; no page-local floating-div fallback.
    Components.alert(msg, type);
}

function prFormatDate(d) {
    if (!d) return '—';
    try { return new Date(d + 'T00:00:00').toLocaleDateString(); } catch(e) { return String(d); }
}

function prStatusBadge(status) {
    if (status === 'posted') {
        return '<span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;">Posted</span>';
    }
    return '<span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;">Draft</span>';
}

function prOpenModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'flex';
    el.removeAttribute('aria-hidden');
}

function prCloseModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
}

async function prFetch(url, options) {
    const fetcher = window.Components && typeof Components.fetchJson === 'function'
        ? Components.fetchJson
        : async (u, o) => { const r = await fetch(u, o); return { response: r, data: await r.json() }; };
    return fetcher(url, options);
}

// ---------------------------------------------------------------------------
// PRINTING
//
// Uses the shared branded SfmsPrint layout (assets/js/sfms-print.js) — the
// same PHILCST letterhead, table styling and sign-off as the All Reports and
// Dispatches printouts, so every printed document in the system matches.
// ---------------------------------------------------------------------------
const PR_PRINT_ROLE_LABELS = {
    super_admin: 'Administrator',
    maintenance_admin: 'Head Maintenance',
    maintenance_staff: 'Maintenance Staff',
};
const PR_PREPARED_ROLE = PR_PRINT_ROLE_LABELS[<?php echo json_encode($userRole); ?>] || '';

function prPrintUnavailable() {
    if (window.SfmsPrint) return false;
    prNotify('The print layout failed to load. Please refresh the page and try again.');
    return true;
}

function prPrintReceipt(receipt, items, openedWin) {
    if (prPrintUnavailable()) return;
    const esc = SfmsPrint.escape;
    const lines = Array.isArray(items) ? items : [];
    const totalQty = lines.reduce((sum, it) => sum + (Number(it.quantity_received) || 0), 0);
    const isPosted = receipt.status === 'posted';

    const rowsHtml = lines.length
        ? lines.map((it, idx) => `
            <tr>
                <td class="c-no">${idx + 1}</td>
                <td class="c-strong">${esc(it.item_name)}</td>
                <td>${esc(it.category_name || '—')}</td>
                <td>${esc(it.quantity_received)}</td>
                <td>${esc(it.unit || '—')}</td>
            </tr>`).join('')
            + `<tr><td></td><td class="c-strong" colspan="2">Total</td><td class="c-strong">${esc(totalQty)}</td><td></td></tr>`
        : '<tr><td colspan="5" class="muted" style="text-align:center;">No line items recorded.</td></tr>';

    const details = [
        { label: 'OR Number', value: receipt.or_number || '—' },
        { label: 'Receipt Date', value: prFormatDate(receipt.receipt_date) },
        { label: 'Supplier', value: receipt.supplier_name || '—' },
        { label: 'Department', value: receipt.department_name || '—' },
        { label: 'Received By', value: receipt.received_by_name || '—' },
        { label: 'Status', value: isPosted ? 'Posted' : 'Draft' },
    ];
    if (receipt.remarks) details.push({ label: 'Remarks', value: receipt.remarks, wide: true });

    const opened = SfmsPrint.open({
        title: 'Purchase Receipt',
        subtitle: `OR No. ${receipt.or_number || '—'}`,
        preparedBy: PR_SESSION_USER_NAME,
        preparedRole: PR_PREPARED_ROLE,
        recordLabel: 'Line Items',
        recordCount: lines.length,
        orientation: 'portrait',
        details,
        columns: [
            { label: 'No.', width: '7%' },
            { label: 'Item Name', width: '43%' },
            { label: 'Category', width: '24%' },
            { label: 'Qty', width: '12%' },
            { label: 'Unit', width: '14%' },
        ],
        rowsHtml,
        targetWindow: openedWin,
    });

    if (!opened) {
        prNotify('Unable to open print preview. Please allow pop-ups for this site.', 'warning');
    }
}

async function prFetchReceipt(id) {
    const { response, data: payload } = await prFetch(
        `${PURCHASE_API}/${id}`,
        { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
    );
    if (!response.ok || !payload.success || !payload.data?.receipt) {
        throw new Error(payload.message || 'Failed to load receipt');
    }
    return {
        receipt: payload.data.receipt,
        items: Array.isArray(payload.data.items) ? payload.data.items : [],
    };
}

// ---------------------------------------------------------------------------
// LIST VIEW
// ---------------------------------------------------------------------------
<?php if (!$isDetail): ?>

let prListReceipts = [];

function prPrintReceiptList() {
    if (prPrintUnavailable()) return;
    if (!prListReceipts.length) {
        prNotify('No purchase receipts to print.', 'warning');
        return;
    }

    const esc = SfmsPrint.escape;
    const posted = prListReceipts.filter((r) => r.status === 'posted').length;
    const totalItems = prListReceipts.reduce((sum, r) => sum + (Number(r.item_count) || 0), 0);

    const rowsHtml = prListReceipts.map((r, idx) => `
        <tr>
            <td class="c-no">${idx + 1}</td>
            <td class="c-key">${esc(r.or_number)}</td>
            <td class="c-nowrap">${esc(prFormatDate(r.receipt_date))}</td>
            <td class="c-strong">${esc(r.supplier_name)}</td>
            <td>${r.department_name ? esc(r.department_name) : '<span class="muted">—</span>'}</td>
            <td>${esc(r.received_by_name || '—')}</td>
            <td>${esc(r.item_count)}</td>
            <td>${r.status === 'posted' ? SfmsPrint.pill('Posted', 'green') : SfmsPrint.pill('Draft', 'amber')}</td>
        </tr>`).join('');

    const opened = SfmsPrint.open({
        title: 'Purchase Receipts Summary',
        subtitle: 'Incoming stock recorded by OR number and supplier',
        preparedBy: PR_SESSION_USER_NAME,
        preparedRole: PR_PREPARED_ROLE,
        recordLabel: 'Receipts',
        recordCount: prListReceipts.length,
        stats: [
            { label: 'Total Receipts', value: prListReceipts.length, tone: 'purple' },
            { label: 'Posted', value: posted, tone: 'green' },
            { label: 'Draft', value: prListReceipts.length - posted, tone: 'amber' },
            { label: 'Line Items', value: totalItems, tone: 'violet' },
        ],
        columns: [
            { label: 'No.', width: '5%' },
            { label: 'OR Number', width: '16%' },
            { label: 'Date', width: '10%' },
            { label: 'Supplier', width: '19%' },
            { label: 'Department', width: '14%' },
            { label: 'Received By', width: '18%' },
            { label: 'Items', width: '7%' },
            { label: 'Status', width: '11%' },
        ],
        rowsHtml,
    });

    if (!opened) {
        prNotify('Unable to open print preview. Please allow pop-ups for this site.', 'warning');
    }
}

async function prPrintReceiptById(id) {
    if (prPrintUnavailable()) return;
    // Open synchronously in the click handler so pop-up blockers allow it.
    const win = window.open('', '_blank', 'width=1000,height=900');
    if (!win) {
        prNotify('Unable to open print preview. Please allow pop-ups for this site.', 'warning');
        return;
    }
    win.document.write('<p style="font-family:Arial,sans-serif;padding:24px;">Preparing receipt…</p>');

    try {
        const { receipt, items } = await prFetchReceipt(id);
        prPrintReceipt(receipt, items, win);
    } catch (err) {
        win.close();
        prNotify(err.message || 'Unable to print receipt.');
    }
}

async function loadReceipts() {
    try {
        const { response, data: payload } = await prFetch(
            PURCHASE_API,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load receipts');
        }

        const receipts = Array.isArray(payload.data?.receipts) ? payload.data.receipts : [];
        const container = document.getElementById('receipts-container');

        if (receipts.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No purchase receipts yet.</strong><span>Create one to start recording incoming stock.</span></div>';
            return;
        }

        let html = '<table class="table purchase-receipts-table"><thead><tr>'
            + '<th>OR Number</th><th>Date</th><th>Supplier</th><th>Department</th>'
            + '<th>Received By</th><th>Items</th><th>Status</th><th>Action</th>'
            + '</tr></thead><tbody>';

        receipts.forEach((row) => {
            html += '<tr>';
            html += `<td><strong>${prEscapeHtml(row.or_number)}</strong></td>`;
            html += `<td>${prEscapeHtml(prFormatDate(row.receipt_date))}</td>`;
            html += `<td>${prEscapeHtml(row.supplier_name)}</td>`;
            html += `<td>${prEscapeHtml(row.department_name || '—')}</td>`;
            html += `<td>${prEscapeHtml(row.received_by_name)}</td>`;
            html += `<td>${prEscapeHtml(row.item_count)}</td>`;
            html += `<td>${prStatusBadge(row.status)}</td>`;
            html += '<td><div class="d-flex gap-sm">'
                + `<a class="btn btn-sm btn-primary" href="${prEscapeHtml(PURCHASE_PAGE)}?id=${row.id}">View</a>`
                + `<button type="button" class="btn btn-sm btn-secondary" data-print-receipt="${Number(row.id)}">Print</button>`
                + '</div></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;
        prListReceipts = receipts;
        const listPrintBtn = document.getElementById('printReceiptListBtn');
        if (listPrintBtn) listPrintBtn.disabled = false;
    } catch (err) {
        document.getElementById('receipts-container').innerHTML =
            '<div class="ui-empty-state"><strong>Failed to load receipts.</strong></div>';
        prNotify(err.message || 'Unable to load receipts.');
    }
}

async function loadDepartmentsIntoSelect(selectId) {
    try {
        const { response, data: payload } = await prFetch(
            window.SFMS_PUBLIC_URL('/api/departments'),
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) return;

        const departments = Array.isArray(payload.data?.departments ?? payload.data)
            ? (payload.data?.departments ?? payload.data)
            : [];

        const sel = document.getElementById(selectId);
        if (!sel) return;

        departments.forEach((dept) => {
            const opt = document.createElement('option');
            opt.value = dept.department_id;
            opt.textContent = dept.name;
            sel.appendChild(opt);
        });
    } catch (_) { /* dropdown is optional — fail silently */ }
}

async function loadUsersIntoReceivedBySelect() {
    const sel = document.getElementById('nr-received-by');
    if (!sel) return;
    try {
        const { response, data: payload } = await prFetch(
            window.SFMS_PUBLIC_URL('/api/users'),
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error();

        const users = Array.isArray(payload.data?.users ?? payload.data)
            ? (payload.data?.users ?? payload.data)
            : [];

        sel.innerHTML = '';
        users.forEach((u) => {
            const opt = document.createElement('option');
            opt.value = u.user_id;
            opt.textContent = u.full_name;
            if (Number(u.user_id) === PR_SESSION_USER_ID) opt.selected = true;
            sel.appendChild(opt);
        });

        // Ensure session user is selectable even if absent from list
        if (PR_SESSION_USER_ID && !sel.value) {
            const opt = document.createElement('option');
            opt.value = PR_SESSION_USER_ID;
            opt.textContent = PR_SESSION_USER_NAME || 'Me';
            opt.selected = true;
            sel.insertBefore(opt, sel.firstChild);
        }
    } catch (_) {
        // Fallback: show only the session user
        sel.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = PR_SESSION_USER_ID;
        opt.textContent = PR_SESSION_USER_NAME || 'Me';
        opt.selected = true;
        sel.appendChild(opt);
    }
}

function initListView() {
    loadReceipts();
    loadDepartmentsIntoSelect('nr-department-id');
    loadUsersIntoReceivedBySelect();

    const printListBtn = document.getElementById('printReceiptListBtn');
    if (printListBtn) printListBtn.addEventListener('click', prPrintReceiptList);

    // Row-level Print buttons are re-rendered on every load, so delegate.
    document.getElementById('receipts-container').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-print-receipt]');
        if (btn) prPrintReceiptById(Number(btn.dataset.printReceipt));
    });

    <?php if ($canPost): ?>
    const newReceiptBtn = document.getElementById('newReceiptBtn');
    if (newReceiptBtn) {
        newReceiptBtn.addEventListener('click', () => {
            // Reset receiver to the logged-in user each time the modal opens
            const receivedBySel = document.getElementById('nr-received-by');
            if (receivedBySel && PR_SESSION_USER_ID) receivedBySel.value = PR_SESSION_USER_ID;
            prOpenModal('new-receipt-modal');
        });
    }

    document.getElementById('closeNewReceiptModal').addEventListener('click',  () => prCloseModal('new-receipt-modal'));
    document.getElementById('cancelNewReceiptModal').addEventListener('click', () => prCloseModal('new-receipt-modal'));

    document.getElementById('new-receipt-modal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) prCloseModal('new-receipt-modal');
    });

    document.getElementById('new-receipt-form').addEventListener('submit', async (e) => {
        e.preventDefault();

        const orNumber       = document.getElementById('nr-or-number').value.trim();
        const receiptDate    = document.getElementById('nr-receipt-date').value.trim();
        const supplierName   = document.getElementById('nr-supplier-name').value.trim();
        const departmentId   = document.getElementById('nr-department-id').value;
        const remarks        = document.getElementById('nr-remarks').value.trim();
        const receivedByRaw  = document.getElementById('nr-received-by').value;
        const receivedById   = receivedByRaw ? parseInt(receivedByRaw, 10) : null;
        const errEl        = document.getElementById('new-receipt-error');
        const submitBtn    = document.getElementById('submitNewReceiptBtn');

        errEl.style.display = 'none';

        if (!orNumber || !receiptDate || !supplierName) {
            errEl.textContent = 'OR number, receipt date, and supplier name are required.';
            errEl.style.display = 'block';
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';

        try {
            const { response, data: payload } = await prFetch(
                PURCHASE_API,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        or_number:             orNumber,
                        receipt_date:          receiptDate,
                        supplier_name:         supplierName,
                        department_id:         departmentId || null,
                        remarks:               remarks || null,
                        received_by_user_id:   receivedById,
                    }),
                }
            );

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to create receipt');
            }

            const newId = payload.data?.id;
            window.location.href = `${PURCHASE_PAGE}?id=${newId}`;
        } catch (err) {
            errEl.textContent = err.message || 'An error occurred.';
            errEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Receipt';
        }
    });
    <?php endif; ?>
}

document.addEventListener('DOMContentLoaded', initListView);

<?php else: ?>
// ---------------------------------------------------------------------------
// DETAIL VIEW
// ---------------------------------------------------------------------------

let prDropdownsLoaded = false;
let prReceiptStatus = null;
// Latest loaded receipt + line items, reused by the Print Receipt button so
// the printout always matches what is on screen.
let prCurrentReceipt = null;
let prCurrentItems = [];

// Removes a mistaken line while the receipt is still a draft (a draft line has
// not added any stock yet, so this has no inventory effect).
async function prRemoveLine(lineId, itemName) {
    if (!window.confirm(`Remove "${itemName}" from this receipt?`)) return;
    try {
        const { response, data: payload } = await prFetch(
            `${PURCHASE_API}/${PR_RECEIPT_ID}/items/${lineId}`,
            { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to remove item');
        }
        prNotify(payload.message || 'Item removed from receipt.', 'success');
        await loadReceiptItems();
    } catch (err) {
        prNotify(err.message || 'Unable to remove item.');
    }
}

async function loadReceiptDetail() {
    try {
        const { response, data: payload } = await prFetch(
            `${PURCHASE_API}/${PR_RECEIPT_ID}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load receipt');
        }

        const receipt = payload.data?.receipt;
        if (!receipt) throw new Error('Invalid receipt response.');
        prCurrentReceipt = receipt;
        const printBtn = document.getElementById('printReceiptBtn');
        if (printBtn) printBtn.disabled = false;

        document.getElementById('receipt-title').textContent = 'OR# ' + (receipt.or_number || '—');
        document.getElementById('receipt-subtitle').textContent =
            'Status: ' + (receipt.status === 'posted' ? 'Posted' : 'Draft') +
            ' • Supplier: ' + (receipt.supplier_name || '—');

        const date       = prFormatDate(receipt.receipt_date);
        const createdAt  = receipt.created_at ? new Date(receipt.created_at).toLocaleString() : '—';

        document.getElementById('receipt-header-container').innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div><strong>OR Number:</strong> ${prEscapeHtml(receipt.or_number)}</div>
                <div><strong>Receipt Date:</strong> ${prEscapeHtml(date)}</div>
                <div><strong>Supplier:</strong> ${prEscapeHtml(receipt.supplier_name)}</div>
                <div><strong>Department:</strong> ${prEscapeHtml(receipt.department_name || '—')}</div>
                <div><strong>Received By:</strong> ${prEscapeHtml(receipt.received_by_name)}</div>
                <div><strong>Status:</strong> ${prStatusBadge(receipt.status)}</div>
                ${receipt.remarks ? `<div style="grid-column:1/-1;"><strong>Remarks:</strong> ${prEscapeHtml(receipt.remarks)}</div>` : ''}
                <div class="text-muted" style="grid-column:1/-1;font-size:12px;">Created: ${prEscapeHtml(createdAt)}</div>
            </div>`;

        prReceiptStatus = receipt.status;

        // Show draft action buttons only when status is draft
        if (receipt.status === 'draft') {
            const draftActions = document.getElementById('draft-actions');
            if (draftActions) draftActions.style.display = 'flex';
        }

        renderProofImage(receipt.proof_image);

        await loadReceiptItems();
    } catch (err) {
        document.getElementById('receipt-header-container').innerHTML =
            '<div class="ui-empty-state"><strong>Failed to load receipt.</strong></div>';
        prNotify(err.message || 'Unable to load receipt details.');
    }
}

// Proof of Receipt — renders the currently-uploaded photo (if any) as a
// click-to-view-full-size thumbnail, mirroring how completion_proof_image is
// displayed on the Maintenance Report detail page.
function renderProofImage(proofImageUrl) {
    const preview = document.getElementById('proof-image-preview');
    if (!preview) return;

    if (proofImageUrl) {
        const safeUrl = prEscapeHtml(String(proofImageUrl));
        preview.innerHTML = `
            <a href="${safeUrl}" target="_blank" rel="noopener">
                <img src="${safeUrl}" alt="Proof of receipt" style="max-width: 280px; width: 100%; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.25);">
            </a>`;
    } else {
        preview.innerHTML = '<div class="text-muted">No proof image uploaded yet.</div>';
    }
}

async function uploadProofImage(file) {
    if (!file) return;

    const btn = document.getElementById('uploadProofBtn');
    if (btn) btn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('proof_image', file);

        const { response, data: payload } = await prFetch(
            `${PURCHASE_API}/${PR_RECEIPT_ID}/proof-image`,
            { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: formData }
        );

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to upload proof image');
        }

        prNotify(payload.message || 'Proof of receipt uploaded.', 'success');
        renderProofImage(payload.data?.proof_image);
        if (prCurrentReceipt) prCurrentReceipt.proof_image = payload.data?.proof_image;
    } catch (err) {
        prNotify(err.message || 'Unable to upload proof image.');
    } finally {
        if (btn) btn.disabled = false;
        const input = document.getElementById('proof-image-input');
        if (input) input.value = '';
    }
}

async function loadReceiptItems(highlightIds) {
    const container = document.getElementById('items-container');
    const highlight = new Set((highlightIds || []).map(Number));

    try {
        const { response, data: payload } = await prFetch(
            `${PURCHASE_API}/${PR_RECEIPT_ID}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load items');
        }

        const items = Array.isArray(payload.data?.items) ? payload.data.items : [];
        prCurrentItems = items;
        if (payload.data?.receipt) prCurrentReceipt = payload.data.receipt;

        if (items.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No items added yet.</strong><span>Use the Add Items button to add line items.</span></div>';
            return;
        }

        // TASK 6B PHASE 2 — the "Bodega Room" column is gone: every posted line
        // now lands in the one centralized Inventory, so the column showed the
        // same value on every row and no longer told the reader anything.
        const isDraft = prReceiptStatus === 'draft';
        let html = '<table class="table purchase-receipts-table"><thead><tr>'
            + '<th>#</th><th>Item Name</th><th>Category</th><th>Qty</th><th>Unit</th>'
            + (isDraft ? '<th>Action</th>' : '')
            + '</tr></thead><tbody>';

        items.forEach((item, idx) => {
            // Rows created by the bulk add get a brief flash so the user can see
            // exactly what the operation produced.
            html += highlight.has(Number(item.id)) ? '<tr class="pr-line-new">' : '<tr>';
            html += `<td>${idx + 1}</td>`;
            html += `<td><strong>${prEscapeHtml(item.item_name)}</strong></td>`;
            html += `<td>${prEscapeHtml(item.category_name || '—')}</td>`;
            html += `<td>${prEscapeHtml(item.quantity_received)}</td>`;
            html += `<td>${prEscapeHtml(item.unit)}</td>`;
            if (isDraft) {
                html += `<td><button type="button" class="btn btn-sm btn-secondary pr-remove-line" data-line-id="${Number(item.id)}" data-item-name="${prEscapeHtml(item.item_name)}">Remove</button></td>`;
            }
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;
        container.querySelectorAll('.pr-remove-line').forEach((btn) => {
            btn.addEventListener('click', () => prRemoveLine(btn.dataset.lineId, btn.dataset.itemName));
        });
    } catch (err) {
        container.innerHTML = '<div class="ui-empty-state"><strong>Failed to load items.</strong></div>';
        prNotify(err.message || 'Unable to load line items.');
    }
}

// ---------------------------------------------------------------------------
// TASK H — BULK LINE-ITEM ENTRY
//
// The user can build up any number of rows and submit them in one request.
// Each row collects exactly what the old single-item modal collected, so the
// payload the server receives per line is unchanged; only the batching is new.
//
// Inventory rooms were removed entirely (this school uses one centralized
// Inventory pool, not per-room stock). The inventory_room_id column/table
// have been dropped from the database, so no row collects it.
// ---------------------------------------------------------------------------

const PR_UNITS = ['pc', 'set', 'box', 'unit'];

let prCategories = [];
let prRowSeq = 0;

async function loadAddItemDropdowns() {
    if (prDropdownsLoaded) return;
    prDropdownsLoaded = true;

    // Categories — same endpoint the single-item modal used.
    try {
        const { response, data: payload } = await prFetch(
            window.SFMS_PUBLIC_URL('/api/inventory-categories'),
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (response.ok && payload.success) {
            const cats = Array.isArray(payload.data?.categories ?? payload.data)
                ? (payload.data?.categories ?? payload.data)
                : [];
            prCategories = cats.map((c) => ({ id: c.id, name: c.name }));
        }
    } catch (_) { /* category is optional — a row without one is still valid */ }
}

function prCategoryOptions(selectedId) {
    let html = '<option value="">— No category —</option>';
    prCategories.forEach((c) => {
        const sel = String(c.id) === String(selectedId ?? '') ? ' selected' : '';
        html += `<option value="${prEscapeHtml(c.id)}"${sel}>${prEscapeHtml(c.name)}</option>`;
    });
    return html;
}

function prUnitOptions(selectedUnit) {
    return PR_UNITS.map((u) =>
        `<option value="${u}"${u === selectedUnit ? ' selected' : ''}>${u}</option>`
    ).join('');
}

// --- rows -------------------------------------------------------------------

function prRowElements() {
    return Array.from(document.querySelectorAll('#pr-bulk-rows .pr-bulk-row'));
}

function prAddRow(prefill, focusIt) {
    const container = document.getElementById('pr-bulk-rows');
    if (!container) return null;

    const data = prefill || {};
    const rowId = ++prRowSeq;
    const row = document.createElement('div');
    row.className = 'pr-bulk-row';
    row.dataset.rowId = String(rowId);

    row.innerHTML = `
        <div class="pr-bulk-field pr-bulk-field--item">
            <span class="pr-field-label">Item *</span>
            <input type="text" class="form-control pr-item-input" autocomplete="off"
                   placeholder="Search or type an item name"
                   aria-label="Item name"
                   value="${prEscapeHtml(data.item_name || '')}">
        </div>
        <div class="pr-bulk-field pr-bulk-field--category">
            <span class="pr-field-label">Category</span>
            <select class="form-control pr-category-select" aria-label="Category">${prCategoryOptions(data.category_id)}</select>
        </div>
        <div class="pr-bulk-field pr-bulk-field--qty">
            <span class="pr-field-label">Qty *</span>
            <input type="number" class="form-control pr-qty-input" min="1" step="1"
                   aria-label="Quantity" value="${prEscapeHtml(data.quantity_received || 1)}">
        </div>
        <div class="pr-bulk-field pr-bulk-field--unit">
            <span class="pr-field-label">Unit</span>
            <select class="form-control pr-unit-select" aria-label="Unit">${prUnitOptions(data.unit || 'pc')}</select>
        </div>
        <button type="button" class="pr-row-remove" aria-label="Remove this item">&times;</button>
        <p class="pr-row-note is-empty"></p>
    `;

    // The selected inventory item id lives on the input, not in the markup, so
    // retyping the name automatically drops the stale association.
    const itemInput = row.querySelector('.pr-item-input');
    if (data.item_id) itemInput.dataset.itemId = String(data.item_id);

    container.appendChild(row);
    prRefreshRowState();

    if (focusIt) {
        itemInput.focus();
        container.scrollTop = container.scrollHeight;
    }

    return row;
}

function prRemoveRow(row) {
    if (!row) return;
    prHideSuggest();
    row.remove();
    if (prRowElements().length === 0) prAddRow(null, false);
    prRefreshRowState();
}

function prReadRow(row) {
    const itemInput = row.querySelector('.pr-item-input');
    const rawItemId = itemInput.dataset.itemId;
    const qtyRaw = row.querySelector('.pr-qty-input').value.trim();

    return {
        item_name: itemInput.value.trim(),
        item_id: rawItemId ? parseInt(rawItemId, 10) : null,
        category_id: row.querySelector('.pr-category-select').value || null,
        quantity_received: qtyRaw === '' ? NaN : Number(qtyRaw),
        unit: row.querySelector('.pr-unit-select').value || 'pc',
    };
}

function prSetRowNote(row, message, kind) {
    const note = row.querySelector('.pr-row-note');
    note.textContent = message || '';
    note.className = 'pr-row-note ' + (message ? ('is-' + kind) : 'is-empty');
    row.classList.toggle('pr-row-invalid', kind === 'error' && !!message);
}

/**
 * Single source of truth for row state: validates every row, flags duplicates,
 * and returns the rows that are ready to submit. Called on every edit so the
 * footer count and the inline messages can never disagree with each other.
 *
 * `showBlanks` is false while typing (an untouched new row should not shout at
 * the user) and true on submit, where §4 requires incomplete rows to block.
 */
function prEvaluateRows(showBlanks) {
    const rows = prRowElements();
    const parsed = rows.map((row) => ({ row, data: prReadRow(row) }));
    const errors = [];

    // Identity matches the server's combineDuplicateLines() exactly: the item
    // name compared case-insensitively, because that is what posting falls back
    // to. Rows carrying DIFFERENT explicit inventory item ids are two distinct
    // stock records and stay separate even under the same name.
    const seen = new Map();

    parsed.forEach((entry, index) => {
        const { row, data } = entry;
        const blank = data.item_name === '' && !row.dataset.touched;
        let message = null;
        let kind = 'error';

        if (data.item_name === '') {
            if (showBlanks || row.dataset.touched) message = 'Item name is required.';
        } else if (!Number.isFinite(data.quantity_received) || !Number.isInteger(data.quantity_received) || data.quantity_received < 1) {
            message = 'Quantity must be a whole number of at least 1.';
        } else if (!PR_UNITS.includes(data.unit)) {
            message = 'Choose a valid unit.';
        } else {
            const identity = 'name:' + data.item_name.toLowerCase();
            const candidate = seen.get(identity);
            const differentStockRows = candidate
                && candidate.data.item_id && data.item_id
                && candidate.data.item_id !== data.item_id;
            const first = differentStockRows ? null : candidate;

            if (!first) {
                if (!candidate) seen.set(identity, { index, data });
            } else if (first.data.unit !== data.unit) {
                message = `Same item as row ${first.index + 1} but a different unit (${first.data.unit} vs ${data.unit}). Use one unit per item.`;
            } else {
                // Safe duplicate: posting adds each line's quantity to the same
                // stock row, so summing them here changes nothing downstream.
                message = `Duplicate of row ${first.index + 1} — quantities will be combined.`;
                kind = 'info';
            }
        }

        prSetRowNote(row, message, kind);
        entry.blank = blank;
        entry.error = kind === 'error' ? message : null;
        if (entry.error) errors.push({ index, message: entry.error });
    });

    const ready = parsed.filter((e) => !e.error && e.data.item_name !== '');

    return { parsed, errors, ready };
}

function prRefreshRowState() {
    const rows = prRowElements();
    const { ready } = prEvaluateRows(false);

    // Never let the user delete the last row — an empty modal has no purpose.
    rows.forEach((row) => {
        row.querySelector('.pr-row-remove').disabled = rows.length === 1;
    });

    // §7 — the action reflects how many valid rows will actually be submitted.
    const btn = document.getElementById('submitAddItemsBtn');
    const count = ready.length;
    btn.textContent = count === 0
        ? 'Add Items'
        : (count === 1 ? 'Add 1 Item' : `Add ${count} Items`);
    btn.disabled = count === 0;

    const counter = document.getElementById('pr-bulk-count');
    counter.textContent = rows.length === 1 ? '1 row' : `${rows.length} rows`;
}

function prResetRows() {
    const container = document.getElementById('pr-bulk-rows');
    container.innerHTML = '';
    prRowSeq = 0;
    prSetBulkError('');
    prAddRow(null, false);
}

function prSetBulkError(message) {
    const el = document.getElementById('add-items-error');
    el.textContent = message || '';
    el.classList.toggle('is-visible', !!message);
}

// --- searchable item field --------------------------------------------------
//
// §3 — reuses the existing Inventory Item source (/api/items, the same endpoint
// the Inventory page reads) filtered to item_type=inventory_stock. No second
// item store is introduced; picking a suggestion simply records that item's id
// on the row so the server can link the line directly.

let prSuggestInput = null;
let prSuggestTimer = null;
let prSuggestResults = [];
let prSuggestActive = -1;

function prSuggestEl() {
    return document.getElementById('pr-item-suggest');
}

function prHideSuggest() {
    const el = prSuggestEl();
    if (el) el.hidden = true;
    prSuggestInput = null;
    prSuggestResults = [];
    prSuggestActive = -1;
}

function prPositionSuggest(input) {
    const el = prSuggestEl();
    const rect = input.getBoundingClientRect();
    el.style.left = `${rect.left}px`;
    el.style.top = `${rect.bottom + 4}px`;
    el.style.width = `${rect.width}px`;
}

function prRenderSuggest(input, items) {
    const el = prSuggestEl();
    prSuggestResults = items;
    prSuggestActive = -1;

    if (items.length === 0) {
        el.innerHTML = '<div class="pr-suggest-empty">No matching inventory item — it will be created when this receipt is posted.</div>';
    } else {
        el.innerHTML = items.map((it, i) => {
            // /api/items returns the raw Item row, so the category arrives as an
            // id — resolve it against the list already loaded for the dropdowns
            // rather than asking the API for a joined name it does not expose.
            const cat = prCategories.find((c) => String(c.id) === String(it.category_id));
            const meta = [
                cat ? prEscapeHtml(cat.name) : null,
                `in stock: ${prEscapeHtml(it.quantity ?? 0)}`,
            ].filter(Boolean).join(' • ');
            return `<div class="pr-suggest-option" role="option" data-index="${i}">`
                 + `${prEscapeHtml(it.name)}<small>${meta}</small></div>`;
        }).join('');
    }

    prSuggestInput = input;
    prPositionSuggest(input);
    el.hidden = false;
}

async function prSearchInventoryItems(input) {
    const term = input.value.trim();
    if (term.length < 1) { prHideSuggest(); return; }

    try {
        const url = window.SFMS_PUBLIC_URL('/api/items')
            + `?item_type=inventory_stock&per_page=8&q=${encodeURIComponent(term)}`;
        const { response, data: payload } = await prFetch(
            url,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) { prHideSuggest(); return; }

        // /api/items returns a paginator, so the rows are under data.data.
        const raw = payload.data;
        const list = Array.isArray(raw) ? raw : (Array.isArray(raw?.data) ? raw.data : []);

        // The field may have moved on while the request was in flight.
        if (document.activeElement !== input) return;
        prRenderSuggest(input, list);
    } catch (_) {
        prHideSuggest();
    }
}

function prApplySuggestion(input, item) {
    const row = input.closest('.pr-bulk-row');
    input.value = item.name;
    input.dataset.itemId = String(item.id);
    row.dataset.touched = '1';

    // Carry the item's own category/unit across so the row describes the real
    // inventory record rather than making the user retype what we already know.
    if (item.category_id) {
        const catSel = row.querySelector('.pr-category-select');
        if (catSel.querySelector(`option[value="${item.category_id}"]`)) {
            catSel.value = String(item.category_id);
        }
    }
    if (item.unit_type && PR_UNITS.includes(item.unit_type)) {
        row.querySelector('.pr-unit-select').value = item.unit_type;
    }

    prHideSuggest();
    prRefreshRowState();
    row.querySelector('.pr-qty-input').focus();
    row.querySelector('.pr-qty-input').select();
}

function prMoveSuggestActive(delta) {
    const el = prSuggestEl();
    const options = Array.from(el.querySelectorAll('.pr-suggest-option'));
    if (options.length === 0) return;

    options.forEach((o) => o.classList.remove('is-active'));
    prSuggestActive = (prSuggestActive + delta + options.length) % options.length;
    options[prSuggestActive].classList.add('is-active');
    options[prSuggestActive].scrollIntoView({ block: 'nearest' });
}

function initDetailView() {
    loadReceiptDetail();

    const printBtn = document.getElementById('printReceiptBtn');
    if (printBtn) {
        printBtn.addEventListener('click', () => {
            if (prCurrentReceipt) prPrintReceipt(prCurrentReceipt, prCurrentItems);
        });
    }

    // --- Proof of Receipt upload ---------------------------------------------

    const uploadProofBtn = document.getElementById('uploadProofBtn');
    const proofImageInput = document.getElementById('proof-image-input');
    if (uploadProofBtn && proofImageInput) {
        uploadProofBtn.addEventListener('click', () => {
            const file = proofImageInput.files[0];
            if (!file) {
                prNotify('Please choose an image first.', 'warning');
                return;
            }
            uploadProofImage(file);
        });
    }

    // --- Add Items modal ---------------------------------------------------

    const addItemBtn = document.getElementById('addItemBtn');
    if (addItemBtn) {
        addItemBtn.addEventListener('click', async () => {
            await loadAddItemDropdowns();
            prResetRows();
            prOpenModal('add-items-modal');
            const firstInput = document.querySelector('#pr-bulk-rows .pr-item-input');
            if (firstInput) firstInput.focus();
        });
    }

    function prCloseAddItems() {
        prHideSuggest();
        prCloseModal('add-items-modal');
    }

    document.getElementById('closeAddItemsModal').addEventListener('click', prCloseAddItems);
    document.getElementById('cancelAddItemsModal').addEventListener('click', prCloseAddItems);

    document.getElementById('add-items-modal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) prCloseAddItems();
    });

    document.getElementById('prAddAnotherRow').addEventListener('click', () => {
        prAddRow(null, true);
    });

    // Delegated row handling — rows are created and destroyed constantly, so
    // binding per row would mean re-binding on every change.
    const rowsContainer = document.getElementById('pr-bulk-rows');

    rowsContainer.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('.pr-row-remove');
        if (removeBtn) prRemoveRow(removeBtn.closest('.pr-bulk-row'));
    });

    rowsContainer.addEventListener('input', (e) => {
        if (e.target.classList.contains('pr-item-input')) {
            e.target.closest('.pr-bulk-row').dataset.touched = '1';
            // Typing invalidates any previously picked inventory item.
            delete e.target.dataset.itemId;

            clearTimeout(prSuggestTimer);
            const input = e.target;
            prSuggestTimer = setTimeout(() => prSearchInventoryItems(input), 220);
        }
        prRefreshRowState();
    });

    rowsContainer.addEventListener('change', () => prRefreshRowState());

    rowsContainer.addEventListener('focusin', (e) => {
        if (!e.target.classList.contains('pr-item-input')) prHideSuggest();
    });

    rowsContainer.addEventListener('keydown', (e) => {
        if (!e.target.classList.contains('pr-item-input')) return;
        const el = prSuggestEl();
        const open = !el.hidden && prSuggestInput === e.target;

        if (e.key === 'ArrowDown' && open) { e.preventDefault(); prMoveSuggestActive(1); }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); prMoveSuggestActive(-1); }
        else if (e.key === 'Escape' && open) { e.preventDefault(); prHideSuggest(); }
        else if (e.key === 'Enter') {
            // Enter must never submit the whole batch from inside a text field.
            e.preventDefault();
            if (open && prSuggestActive >= 0) {
                prApplySuggestion(e.target, prSuggestResults[prSuggestActive]);
            } else {
                prHideSuggest();
            }
        }
    });

    prSuggestEl().addEventListener('mousedown', (e) => {
        // mousedown, not click: blur would tear the list down before click fires.
        const opt = e.target.closest('.pr-suggest-option');
        if (!opt || !prSuggestInput) return;
        e.preventDefault();
        prApplySuggestion(prSuggestInput, prSuggestResults[Number(opt.dataset.index)]);
    });

    rowsContainer.addEventListener('scroll', () => {
        if (prSuggestInput) prPositionSuggest(prSuggestInput);
    });
    window.addEventListener('resize', prHideSuggest);
    document.addEventListener('click', (e) => {
        if (prSuggestInput && !e.target.closest('.pr-bulk-field--item') && !e.target.closest('.pr-suggest')) {
            prHideSuggest();
        }
    });

    document.getElementById('add-items-form').addEventListener('submit', async (e) => {
        e.preventDefault();

        const submitBtn = document.getElementById('submitAddItemsBtn');
        prSetBulkError('');
        prHideSuggest();

        // §4 — an incomplete row blocks the whole submission; the inline notes
        // written by prEvaluateRows() say exactly which row and why.
        const { errors, ready } = prEvaluateRows(true);

        if (errors.length > 0) {
            prSetBulkError(errors.length === 1
                ? `Row ${errors[0].index + 1}: ${errors[0].message}`
                : `${errors.length} rows need attention before these items can be added.`);
            const firstBad = prRowElements()[errors[0].index];
            if (firstBad) firstBad.scrollIntoView({ block: 'nearest' });
            return;
        }

        if (ready.length === 0) {
            prSetBulkError('Add at least one item before saving.');
            return;
        }

        const originalLabel = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Adding...';

        try {
            const { response, data: payload } = await prFetch(
                `${PURCHASE_API}/${PR_RECEIPT_ID}/items/bulk`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        items: ready.map((entry) => ({
                            item_name:         entry.data.item_name,
                            item_id:           entry.data.item_id,
                            category_id:       entry.data.category_id,
                            quantity_received: entry.data.quantity_received,
                            unit:              entry.data.unit,
                        })),
                    }),
                }
            );

            if (!response.ok || !payload.success) {
                // §10 — the request is atomic, so a failure means nothing was
                // written. Keep the modal open with every row the user typed
                // still in place, and point at the offending rows.
                // The server indexes its errors against the array we sent, so
                // ready[i] maps straight back to the row the user typed.
                (payload.data?.errors || []).forEach((rowErr) => {
                    const entry = ready[rowErr.index];
                    if (entry && entry.row) prSetRowNote(entry.row, rowErr.message, 'error');
                });
                throw new Error(payload.message || 'Failed to add items');
            }

            const added = Number(payload.data?.count ?? ready.length);
            const combined = Number(payload.data?.combined ?? 0);

            prCloseAddItems();
            // UI_BROWSER_DIALOG_REPLACEMENT — window.UI is always loaded
            // (see includes/footer.php), so this always goes through the
            // shared toast component.
            UI.toast(
                (added === 1 ? '1 item added to this receipt.' : `${added} items added to this receipt.`)
                + (combined > 0 ? ` ${combined} duplicate ${combined === 1 ? 'row was' : 'rows were'} combined.` : ''),
                'success'
            );

            await loadReceiptItems(payload.data?.ids || []);
        } catch (err) {
            prSetBulkError(err.message || 'An error occurred. Nothing was saved — your rows are still here.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
            prRefreshRowState();
        }
    });

    // Post Receipt button (only rendered in DOM when $canPost is true)
    <?php if ($canPost): ?>
    const postBtn = document.getElementById('postReceiptBtn');
    if (postBtn) {
        postBtn.addEventListener('click', async () => {
            // UI_BROWSER_DIALOG_REPLACEMENT — window.UI is always loaded
            // (see includes/footer.php), so this always goes through the
            // shared modal.
            const confirmed = await UI.systemConfirm('This will add stock to inventory. Are you sure you want to post this receipt?', 'Post Receipt', 'Cancel', 'success');

            if (!confirmed) return;

            postBtn.disabled = true;
            postBtn.textContent = 'Posting...';

            try {
                const { response, data: payload } = await prFetch(
                    `${PURCHASE_API}/${PR_RECEIPT_ID}/post`,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({}),
                    }
                );

                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || 'Failed to post receipt');
                }

                // UI_BROWSER_DIALOG_REPLACEMENT — window.UI is always loaded
                // (see includes/footer.php), so this always goes through the
                // shared toast component.
                UI.toast('Receipt posted. Inventory has been updated.', 'success');

                // Reload so PHP re-renders with current state from API
                window.location.reload();
            } catch (err) {
                prNotify(err.message || 'Failed to post receipt.');
                postBtn.disabled = false;
                postBtn.textContent = 'Post Receipt';
            }
        });
    }
    <?php endif; ?>
}

document.addEventListener('DOMContentLoaded', initDetailView);

<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
