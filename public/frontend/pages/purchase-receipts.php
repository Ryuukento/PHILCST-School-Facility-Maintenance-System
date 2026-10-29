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
    header('Location: ' . public_url('/login'));
    exit;
}

$user     = $_SESSION['user'];
$userRole = $user['role'] ?? '';

$allowedRoles = ['super_admin', 'maintenance_admin', 'maintenance_staff'];
if (!in_array($userRole, $allowedRoles, true)) {
    header('Location: ' . public_url('/dashboard'));
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
                <!-- 2026-10-27 feedback item D: the Supplier Directory
                     (suppliers-manage.php) had no entry point anywhere in the
                     app besides typing its URL directly -- linking it here so
                     it's actually reachable from the page that most needs it. -->
                <a href="<?php echo htmlspecialchars(public_url('/suppliers/manage')); ?>" class="btn btn-secondary">Manage Suppliers</a>
                <button type="button" class="btn btn-secondary" id="printReceiptListBtn" disabled><?php echo ui_icon('file-text'); ?> Print Receipts</button>
                <?php if ($canPost): ?>
                <button type="button" class="btn btn-primary" id="newReceiptBtn">+ New Receipt</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- Controls: Date Range Filter -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <label for="prDateFrom" style="font-size:13px;font-weight:500;">From:</label>
                    <input type="date" id="prDateFrom" class="form-control" style="padding:6px 10px;font-size:13px;width:140px;">
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <label for="prDateTo" style="font-size:13px;font-weight:500;">To:</label>
                    <input type="date" id="prDateTo" class="form-control" style="padding:6px 10px;font-size:13px;width:140px;">
                </div>
                <button type="button" id="prResetDateFilter" class="btn btn-sm btn-secondary" style="padding:6px 12px;font-size:13px;">Reset</button>
                <small class="text-muted" id="prDateRangeInfo">Showing last 12 months</small>
            </div>

            <!-- Receipts Table -->
            <div id="receipts-container" class="table-responsive">
                <div class="ui-empty-state"><strong>Loading receipts...</strong></div>
            </div>

            <!-- Pagination Controls -->
            <div id="pr-pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid var(--border);">
                <!-- Pagination will be inserted here -->
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
                <div class="pr-new-receipt-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
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

                <!-- Row 2: Supplier (full width)
                     Type-to-filter against /api/suppliers?q= via the same
                     Components.SearchableSelect widget dispatch-create.php
                     already uses for Department/Room/Release Personnel --
                     plain text input + hidden id input, no icon wrapper
                     (matching that convention exactly: the widget's own
                     position:relative container replaces the input's
                     original parent slot, which would fight an icon span
                     sibling expecting to anchor to it). -->
                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Supplier <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="nr-supplier-search" class="form-control" placeholder="Search supplier by name…" autocomplete="off">
                    <input type="hidden" id="nr-supplier-id">
                    <div style="margin-top:8px;display:flex;gap:16px;align-items:center;">
                        <button type="button" id="nrAddSupplierBtn" style="background:none;border:none;padding:0;cursor:pointer;font-size:12px;font-weight:600;color:var(--primary-color);text-decoration:underline;">+ Add New Supplier</button>
                        <!-- Disabled until a supplier is actually selected (prSupplierSelect's
                             onSelect, or the Add New Supplier success path, enables it) --
                             there's nothing to show a history of before then. -->
                        <button type="button" id="nrViewSupplierHistoryBtn" disabled style="background:none;border:none;padding:0;cursor:not-allowed;font-size:12px;font-weight:600;color:var(--primary-color);text-decoration:underline;opacity:0.4;">View Supplier History</button>
                    </div>
                </div>

                <!-- Row 3: Department + Received By -->
                <div class="pr-new-receipt-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
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

<style>
/* The OR Number/Receipt Date and Department/Received By rows above are a
   hardcoded 2-column grid with no responsive override, so on a phone-width
   viewport the right-hand field (and its label) got pushed off-screen. */
@media (max-width: 560px) {
    .pr-new-receipt-row {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Modal: Add New Supplier
     Opened from the New Receipt form's "+ Add New Supplier" button, next to
     its searchable supplier field (#nr-supplier-search / #nr-supplier-id --
     see prBuildSupplierSelect()). Submitting this creates a real row in the
     suppliers table (via POST /api/suppliers/quick-create -- the same
     SupplierController::store() the main Supplier Management page uses,
     just reached through a second route that also permits
     maintenance_staff; see routes/web.php), so a supplier created this way
     is properly linked by supplier_id rather than a disconnected free-text
     name. On success the new supplier's name/id are written straight into
     #nr-supplier-search/#nr-supplier-id, and this modal closes back to the
     New Receipt modal underneath it. -->
<div id="add-supplier-modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" style="display:none;">
    <div class="modal-content" style="max-width:520px;width:100%;padding:0;background:var(--card-color);color:var(--text-primary);border:1px solid var(--border);border-radius:12px;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 24px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9h18v10a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                        <path d="M3 9l2.45-4.9A2 2 0 017.24 3h9.52a2 2 0 011.79 1.1L21 9"/>
                        <line x1="12" y1="13" x2="12" y2="17"/>
                        <line x1="10" y1="15" x2="14" y2="15"/>
                    </svg>
                </div>
                <div>
                    <h2 style="margin:0;font-size:16px;font-weight:700;line-height:1.3;">Add New Supplier</h2>
                    <p style="margin:2px 0 0;font-size:12px;opacity:0.55;line-height:1.4;">Registers a new supplier you can reuse on future receipts</p>
                </div>
            </div>
            <button type="button" id="closeAddSupplierModal" aria-label="Close" style="background:none;border:none;cursor:pointer;padding:6px;opacity:0.5;color:inherit;font-size:22px;line-height:1;border-radius:6px;margin-left:12px;">×</button>
        </div>

        <!-- Form body -->
        <form id="add-supplier-form" style="overflow-y:auto;flex:1;">
            <div style="padding:24px;display:flex;flex-direction:column;gap:18px;">

                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Supplier Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="as-name" class="form-control" placeholder="e.g. ABC Hardware Supply" autocomplete="off" minlength="3" maxlength="100">
                </div>

                <!-- maxlength/minlength here mirror SupplierController's server-side
                     validation rules exactly (the authoritative check -- these are
                     just an early, friendlier stop before the request even goes
                     out). Name intentionally allows any character: legitimate
                     business names use "&", commas, periods ("ABC Hardware & Supply",
                     "J&J Trading", "ABC Co., Ltd."). -->
                <div class="pr-new-receipt-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div>
                        <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Contact Person</label>
                        <input type="text" id="as-contact-person" class="form-control" placeholder="Optional" autocomplete="off" maxlength="255">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Phone</label>
                        <input type="text" id="as-phone" class="form-control" placeholder="e.g. 0917-123-4567" autocomplete="off" maxlength="50">
                    </div>
                </div>

                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Email</label>
                    <input type="email" id="as-email" class="form-control" placeholder="Optional" autocomplete="off" maxlength="255">
                </div>

                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Address</label>
                    <textarea id="as-address" class="form-control" rows="2" placeholder="Optional" maxlength="255"></textarea>
                </div>

                <div>
                    <label style="display:block;margin-bottom:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:0.65;">Notes</label>
                    <textarea id="as-notes" class="form-control" rows="2" placeholder="Optional" maxlength="500"></textarea>
                </div>

                <p id="add-supplier-error" style="color:#ef4444;margin:0;font-size:13px;display:none;"></p>
            </div>

            <!-- Footer -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 24px;border-top:1px solid var(--border);flex-shrink:0;">
                <span style="font-size:12px;opacity:0.45;">* Required fields</span>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="button" class="btn btn-secondary" id="cancelAddSupplierModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitAddSupplierBtn">Create Supplier</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: View Supplier History
     Opened from the New Receipt form's "View Supplier History" link, next to
     "+ Add New Supplier". Read-only: fetches GET /api/suppliers/{id}/history
     (SupplierController::history()) and lists that supplier's past purchase
     receipts (OR#, date, status, item count) so whoever is recording a new
     receipt can see what's been bought from this supplier before. Receipts
     created before purchase_receipts.supplier_id existed (pre-2026-10-27)
     only ever had a free-text supplier name and won't appear here -- that's
     a data-availability gap, not a bug in this modal. -->
<div id="supplier-history-modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" style="display:none;">
    <div class="modal-content" style="max-width:640px;width:100%;padding:0;background:var(--card-color);color:var(--text-primary);border:1px solid var(--border);border-radius:12px;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 24px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(59,130,246,0.15);border:1px solid rgba(59,130,246,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 3v5h5"/>
                        <path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/>
                        <path d="M12 7v5l4 2"/>
                    </svg>
                </div>
                <div>
                    <h2 style="margin:0;font-size:16px;font-weight:700;line-height:1.3;">Supplier History</h2>
                    <p id="sh-supplier-name" style="margin:2px 0 0;font-size:12px;opacity:0.55;line-height:1.4;">—</p>
                </div>
            </div>
            <button type="button" id="closeSupplierHistoryModal" aria-label="Close" style="background:none;border:none;cursor:pointer;padding:6px;opacity:0.5;color:inherit;font-size:22px;line-height:1;border-radius:6px;margin-left:12px;">×</button>
        </div>

        <!-- Body -->
        <div style="padding:20px 24px;overflow-y:auto;flex:1;">
            <p style="margin:0 0 12px;font-size:12px;opacity:0.65;">Past purchase receipts recorded for this supplier.</p>
            <div id="sh-receipts-container" class="table-responsive">
                <div class="ui-empty-state"><strong>Loading...</strong></div>
            </div>
        </div>

        <!-- Footer -->
        <div style="display:flex;align-items:center;justify-content:flex-end;padding:14px 24px;border-top:1px solid var(--border);flex-shrink:0;">
            <button type="button" class="btn btn-secondary" id="closeSupplierHistoryModalFooter">Close</button>
        </div>
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
                <?php if ($canPost): ?>
                <a href="<?php echo htmlspecialchars(public_url('/purchase-receipts') . '?reorder=' . $receiptId); ?>" class="btn btn-secondary">Reorder</a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(public_url('/purchase-receipts')); ?>" class="btn btn-secondary"><?php echo ui_icon('arrow-left'); ?> Back to List</a>
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
                <button type="button" class="btn btn-primary" id="postReceiptBtn" disabled title="Add at least one item before posting this receipt.">Post Receipt</button>
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

<div id="remove-line-modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="remove-line-title" aria-describedby="remove-line-message" tabindex="-1" style="display:none;">
    <div class="modal-content pr-remove-modal">
        <div class="modal-header">
            <h2 class="modal-title" id="remove-line-title">Remove Item?</h2>
        </div>
        <div class="modal-body">
            <p id="remove-line-message"></p>
            <p id="remove-line-error" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelRemoveLine">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmRemoveLine">Yes, Remove</button>
        </div>
    </div>
</div>

<!-- Shared, fixed-position suggestion list for the searchable Item field.
     Lives outside the scrolling rows container so it is never clipped. -->
<div id="pr-item-suggest" class="pr-suggest" role="listbox" hidden></div>

<style>
.pr-remove-modal { width: min(92vw, 480px); }
.pr-remove-modal .modal-title { color: var(--primary-color); }
.pr-remove-modal .modal-body { overflow-wrap: anywhere; }
.pr-remove-modal .modal-footer { flex-wrap: wrap; }
#remove-line-error { color: #b91c1c; margin-bottom: 0; }

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
    max-height: 92dvh;
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
    overflow: hidden;
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
    max-height: 52vh;
    max-height: 52dvh;
    overscroll-behavior: contain;
    min-height: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.pr-bulk-row {
    flex-shrink: 0;
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

.pr-item-name-error {
    margin: 5px 0 0;
    color: #b91c1c;
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}
.pr-item-name-error:empty { display: none; }
.pr-item-input[aria-invalid="true"] { border-color: #ef4444; }

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
    ? window.SFMS_PUBLIC_URL('/purchase-receipts')
    : '/purchase-receipts';

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

// --- Modal stacking / accessibility (2026-10-27 feedback item 3) ---------
// Some modals open on top of another already-open modal -- "+ Add New
// Supplier" and "View History" both launch from inside the New Receipt
// modal. Before this, prOpenModal/prCloseModal just toggled display:flex
// with no regard for what else was open: the modal underneath relied on
// DOM order for stacking (fragile), stayed fully focusable/clickable
// through Tab, and Escape wasn't wired to close anything. This tracks a
// stack of open modal ids so only the most-recently-opened one is "live":
// it always renders above every modal still open beneath it, is the only
// one Tab can reach or Escape can close, the one(s) underneath are made
// `inert` (blocks their focus AND clicks/taps) until it closes, and
// closing it hands focus back to whatever triggered it -- typically the
// button inside the modal underneath, restoring that modal to full
// interactivity without losing any of its own input.
const prModalStack = [];
const PR_MODAL_BASE_Z = 2000;

function prFocusableElements(container) {
    return Array.from(container.querySelectorAll(
        'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((el) => el.offsetParent !== null);
}

function prOpenModal(id) {
    const el = document.getElementById(id);
    if (!el) return;

    // Remember what had focus so closing this modal can hand it back.
    el.__prPreviousFocus = document.activeElement;

    // Whatever was the active modal is now covered -- disable it.
    const previousTopId = prModalStack[prModalStack.length - 1];
    if (previousTopId) {
        const previousTopEl = document.getElementById(previousTopId);
        if (previousTopEl) previousTopEl.setAttribute('inert', '');
    }

    el.style.display = 'flex';
    el.style.zIndex = String(PR_MODAL_BASE_Z + prModalStack.length * 10);
    el.removeAttribute('aria-hidden');
    el.removeAttribute('inert');

    prModalStack.push(id);

    const focusables = prFocusableElements(el);
    (focusables[0] || el).focus();
}

function prCloseModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
    el.style.removeProperty('z-index');

    const idx = prModalStack.indexOf(id);
    if (idx !== -1) prModalStack.splice(idx, 1);

    // Whatever's now on top (if anything) regains interactivity.
    const newTopId = prModalStack[prModalStack.length - 1];
    if (newTopId) {
        const newTopEl = document.getElementById(newTopId);
        if (newTopEl) newTopEl.removeAttribute('inert');
    }

    if (el.__prPreviousFocus && typeof el.__prPreviousFocus.focus === 'function') {
        el.__prPreviousFocus.focus();
    }
    el.__prPreviousFocus = null;
}

// Escape closes only the active (topmost) modal; Tab/Shift+Tab stay inside
// it instead of wrapping into whatever's behind it.
document.addEventListener('keydown', (e) => {
    if (prModalStack.length === 0) return;
    const topId = prModalStack[prModalStack.length - 1];
    const topEl = document.getElementById(topId);
    if (!topEl) return;

    if (e.key === 'Escape') {
        if (e.defaultPrevented) return; // e.g. the item-suggest dropdown already handled it
        e.preventDefault();
        prCloseTopModal(topId);
        return;
    }

    if (e.key === 'Tab') {
        const focusables = prFocusableElements(topEl);
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        } else if (!topEl.contains(document.activeElement)) {
            // Focus somehow ended up outside the active modal -- pull it
            // back in rather than letting Tab continue from wherever it was.
            e.preventDefault();
            first.focus();
        }
    }
});

// Each modal resets its own form/error state on close (see
// prCloseAddSupplierModal etc. below), so Escape has to go through the
// *same* close path as that modal's own Cancel/x button rather than the
// bare prCloseModal().
//
// Corrected 2026-10-27: these four are assigned (not `function`-declared)
// here at the shared top-level scope, then REASSIGNED (not redeclared) by
// initListView()/initDetailView() once those per-modal close wrappers are
// actually defined. A plain `function prCloseAddSupplierModal() {...}`
// written inside initListView() is scoped to initListView() alone --
// invisible from this switch, which sits outside it -- function-declaration
// hoisting only reaches within the SAME enclosing function, not across
// function boundaries. That mismatch meant Escape silently threw a
// ReferenceError (caught by the browser's own event dispatch, so nothing
// crashed, but the keypress did nothing) for every modal except
// new-receipt-modal's `default` case; declaring the bindings here, at the
// same scope prCloseTopModal itself lives in, is what actually makes them
// reachable.
let prCloseAddSupplierModal     = () => prCloseModal('add-supplier-modal');
let prCloseSupplierHistoryModal = () => prCloseModal('supplier-history-modal');
let prCloseAddItems             = () => prCloseModal('add-items-modal');
let prCloseNewReceiptModal      = () => prCloseModal('new-receipt-modal');

function prCloseTopModal(id) {
    switch (id) {
        case 'add-supplier-modal': prCloseAddSupplierModal(); break;
        case 'supplier-history-modal': prCloseSupplierHistoryModal(); break;
        case 'add-items-modal': prCloseAddItems(); break;
        case 'remove-line-modal': prCloseRemoveLineModal(); break;
        case 'new-receipt-modal': prCloseNewReceiptModal(); break;
        default: prCloseModal(id);
    }
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
    maintenance_staff: 'EELab Staff',
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

// Reorder Previous Receipt: sessionStorage key shared across the hard
// navigation from list-view (create) to detail-view (consume) -- must live
// here, before the list/detail script split, since both branches need it.
function prReorderSessionKey(receiptId) {
    return 'pr_reorder_items_' + receiptId;
}

// ---------------------------------------------------------------------------
// LIST VIEW
// ---------------------------------------------------------------------------
<?php if (!$isDetail): ?>

let prListReceipts = [];

// Reorder Previous Receipt: items copied from a prior receipt, staged here
// after the New Receipt modal is pre-filled with the supplier, then handed
// off via sessionStorage once the new draft's id is known (see the
// new-receipt-form submit handler below).
let prPendingReorderItems = null;

function prClearReorderState() {
    prPendingReorderItems = null;
}

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

// ---------------------------------------------------------------------------
// Purchase Receipts: Pagination & Date Filtering
// ---------------------------------------------------------------------------

let prCurrentPage = 1;
let prTotalPages = 1;
let prPaginationData = null;
let prFilterDateFrom = null;
let prFilterDateTo = null;

function prSetDefaultDateRange() {
    const today = new Date();
    const oneYearAgo = new Date(today.getFullYear() - 1, today.getMonth(), today.getDate());

    prFilterDateFrom = oneYearAgo.toISOString().split('T')[0];
    prFilterDateTo = today.toISOString().split('T')[0];

    document.getElementById('prDateFrom').value = prFilterDateFrom;
    document.getElementById('prDateTo').value = prFilterDateTo;
}

function prUpdateDateFilter() {
    prFilterDateFrom = document.getElementById('prDateFrom').value || null;
    prFilterDateTo = document.getElementById('prDateTo').value || null;
    prCurrentPage = 1; // Reset to first page

    const info = document.getElementById('prDateRangeInfo');
    if (prFilterDateFrom && prFilterDateTo) {
        info.textContent = `From ${prFilterDateFrom} to ${prFilterDateTo}`;
    } else if (prFilterDateFrom) {
        info.textContent = `From ${prFilterDateFrom}`;
    } else if (prFilterDateTo) {
        info.textContent = `To ${prFilterDateTo}`;
    } else {
        info.textContent = 'All dates';
    }

    loadReceipts();
}

function prResetDateFilter() {
    prSetDefaultDateRange();
    prUpdateDateFilter();
}

function prRenderPagination(paginationData) {
    const container = document.getElementById('pr-pagination');
    if (!paginationData || paginationData.last_page <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';

    // Previous button
    if (prCurrentPage > 1) {
        html += `<button type="button" class="btn btn-sm btn-secondary" onclick="prLoadPage(${prCurrentPage - 1})">← Previous</button>`;
    }

    // Page numbers
    const startPage = Math.max(1, prCurrentPage - 2);
    const endPage = Math.min(paginationData.last_page, prCurrentPage + 2);

    if (startPage > 1) {
        html += `<button type="button" class="btn btn-sm btn-secondary" onclick="prLoadPage(1)">1</button>`;
        if (startPage > 2) html += '<span style="padding:0 4px;">…</span>';
    }

    for (let i = startPage; i <= endPage; i++) {
        if (i === prCurrentPage) {
            html += `<button type="button" class="btn btn-sm btn-primary" disabled>${i}</button>`;
        } else {
            html += `<button type="button" class="btn btn-sm btn-secondary" onclick="prLoadPage(${i})">${i}</button>`;
        }
    }

    if (endPage < paginationData.last_page) {
        if (endPage < paginationData.last_page - 1) html += '<span style="padding:0 4px;">…</span>';
        html += `<button type="button" class="btn btn-sm btn-secondary" onclick="prLoadPage(${paginationData.last_page})">${paginationData.last_page}</button>`;
    }

    // Next button
    if (prCurrentPage < paginationData.last_page) {
        html += `<button type="button" class="btn btn-sm btn-secondary" onclick="prLoadPage(${prCurrentPage + 1})">Next →</button>`;
    }

    // Info text
    html += `<span style="margin-left:12px;font-size:13px;color:var(--text-muted);">Page ${prCurrentPage} of ${paginationData.last_page}</span>`;

    container.innerHTML = html;
}

function prLoadPage(pageNum) {
    prCurrentPage = pageNum;
    loadReceipts();
}

async function loadReceipts() {
    try {
        const params = new URLSearchParams();
        params.append('page', prCurrentPage);
        params.append('per_page', 10);
        if (prFilterDateFrom) {
            params.append('date_from', prFilterDateFrom);
        }
        if (prFilterDateTo) {
            params.append('date_to', prFilterDateTo);
        }

        const { response, data: payload } = await prFetch(
            PURCHASE_API + '?' + params.toString(),
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
                + (PR_CAN_POST ? `<button type="button" class="btn btn-sm btn-secondary" data-reorder-receipt="${Number(row.id)}">Reorder</button>` : '')
                + '</div></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;
        prListReceipts = receipts;
        const listPrintBtn = document.getElementById('printReceiptListBtn');
        if (listPrintBtn) listPrintBtn.disabled = false;

        // Render pagination
        prPaginationData = payload.data?.pagination;
        if (prPaginationData) {
            prTotalPages = prPaginationData.last_page || 1;
            prRenderPagination(prPaginationData);
        }
    } catch (err) {
        document.getElementById('receipts-container').innerHTML =
            '<div class="ui-empty-state"><strong>Failed to load receipts.</strong></div>';
        document.getElementById('pr-pagination').innerHTML = '';
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

// Type-to-filter supplier picker on the New Receipt form. Same
// Components.SearchableSelect widget dispatch-create.php uses for
// Department/Room/Release Personnel; status=active keeps the New Receipt
// form from surfacing a retired supplier. Built once and reused across
// every modal open (destroying/rebuilding it per-open isn't needed since
// this field, unlike dispatch-create's multi-add department picker, has no
// per-open state to reset beyond the input/hidden values themselves).
let prSupplierSelect = null;

function prBuildSupplierSelect() {
    if (!(window.Components && typeof Components.SearchableSelect === 'function')) return;

    prSupplierSelect = new Components.SearchableSelect({
        inputId:    'nr-supplier-search',
        hiddenId:   'nr-supplier-id',
        endpoint:   '/api/suppliers?status=active',
        displayKey: 'name',
        onSelect:   () => prSetViewHistoryEnabled(true),
    });
}

// "View Supplier History" only makes sense once a real supplier is picked --
// kept disabled otherwise (see the reset in newReceiptBtn's click handler
// and the enable call here / in the Add New Supplier success path below).
function prSetViewHistoryEnabled(enabled) {
    const btn = document.getElementById('nrViewSupplierHistoryBtn');
    if (!btn) return;
    btn.disabled = !enabled;
    btn.style.cursor = enabled ? 'pointer' : 'not-allowed';
    btn.style.opacity = enabled ? '1' : '0.4';
}

// Reorder Previous Receipt: pre-fills the New Receipt modal with ONLY the
// source receipt's supplier (OR Number/Date/Department/Remarks are left
// blank -- this is a new receipt, not a copy of the old one's paperwork),
// stages its line items in prPendingReorderItems for the detail page to
// pick up after creation, then opens the modal. Reachable from: a receipt
// row's Reorder button, a Reorder button inside the supplier-history-modal
// table, or a `?reorder=<id>` query param (used by cross-page entry points
// on dispatch-detail.php-style pages that don't have this modal's HTML).
async function prStartReorder(sourceReceiptId) {
    try {
        const { receipt, items } = await prFetchReceipt(sourceReceiptId);

        document.getElementById('new-receipt-form').reset();
        const receivedBySel = document.getElementById('nr-received-by');
        if (receivedBySel && PR_SESSION_USER_ID) receivedBySel.value = PR_SESSION_USER_ID;

        document.getElementById('nr-supplier-search').value = '';
        document.getElementById('nr-supplier-id').value = '';
        if (prSupplierSelect) prSupplierSelect.clearSelection();
        prSetViewHistoryEnabled(false);

        if (receipt.supplier_id && receipt.supplier_name) {
            document.getElementById('nr-supplier-search').value = receipt.supplier_name;
            document.getElementById('nr-supplier-id').value = String(receipt.supplier_id);
            prSetViewHistoryEnabled(true);
        } else {
            // Receipts created before purchase_receipts.supplier_id existed
            // only have the old free-text supplier_name column -- nothing
            // to pre-fill from, so let the user pick the supplier manually.
            prNotify('This receipt has no linked supplier record (an older receipt) — please choose the supplier manually.', 'warning');
        }

        prPendingReorderItems = items.map((it) => ({
            item_name:         it.item_name,
            item_id:           it.item_id ?? null,
            category_id:       it.category_id ?? null,
            quantity_received: it.quantity_received,
            unit:              it.unit,
        }));

        // supplier-history-modal is only ever opened from inside
        // new-receipt-modal (via "View Supplier History"), so it's the one
        // nested case worth handling explicitly: close it rather than
        // stacking another new-receipt-modal on top of the one already open.
        if (prModalStack.includes('supplier-history-modal')) {
            prCloseSupplierHistoryModal();
        }
        if (!prModalStack.includes('new-receipt-modal')) {
            prOpenModal('new-receipt-modal');
        }
    } catch (err) {
        prNotify(err.message || 'Unable to load that receipt for reordering.');
    }
}

// Fetches SupplierController::history() and renders its purchase_receipts
// list into the View Supplier History modal. histories/stock_entries are
// also returned by that endpoint but aren't shown here -- this modal is
// scoped to what a receipt-entry clerk actually needs: what's been bought
// from this supplier before.
async function prOpenSupplierHistory(supplierId, supplierName) {
    document.getElementById('sh-supplier-name').textContent = supplierName || '—';
    const container = document.getElementById('sh-receipts-container');
    container.innerHTML = '<div class="ui-empty-state"><strong>Loading...</strong></div>';
    prOpenModal('supplier-history-modal');

    try {
        const { response, data: payload } = await prFetch(
            window.SFMS_PUBLIC_URL('/api/suppliers/' + encodeURIComponent(supplierId) + '/history'),
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed to load supplier history');

        const receipts = Array.isArray(payload.data?.purchase_receipts) ? payload.data.purchase_receipts : [];

        if (receipts.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No purchase receipts yet for this supplier.</strong></div>';
            return;
        }

        const rows = receipts.map((r) => `
            <tr>
                <td>${prEscapeHtml(r.or_number)}</td>
                <td>${prFormatDate(r.receipt_date)}</td>
                <td>${prStatusBadge(r.status)}</td>
                <td style="text-align:center;">${Number(r.item_count) || 0}</td>
                ${PR_CAN_POST ? `<td><button type="button" class="btn btn-sm btn-secondary" data-reorder-receipt="${Number(r.id)}">Reorder</button></td>` : ''}
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
                        ${PR_CAN_POST ? '<th>Action</th>' : ''}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    } catch (err) {
        container.innerHTML = `<div class="ui-empty-state"><strong>${prEscapeHtml(err.message || 'Failed to load history.')}</strong></div>`;
    }
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
    // Set default date range (last 12 months)
    prSetDefaultDateRange();

    loadReceipts();
    loadDepartmentsIntoSelect('nr-department-id');
    loadUsersIntoReceivedBySelect();
    prBuildSupplierSelect();

    // Setup date filter inputs
    const dateFromInput = document.getElementById('prDateFrom');
    const dateToInput = document.getElementById('prDateTo');
    const resetBtn = document.getElementById('prResetDateFilter');

    if (dateFromInput) {
        dateFromInput.addEventListener('change', prUpdateDateFilter);
    }
    if (dateToInput) {
        dateToInput.addEventListener('change', prUpdateDateFilter);
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', (e) => {
            e.preventDefault();
            prResetDateFilter();
        });
    }

    const printListBtn = document.getElementById('printReceiptListBtn');
    if (printListBtn) printListBtn.addEventListener('click', prPrintReceiptList);

    // Row-level Print/Reorder buttons are re-rendered on every load, so delegate.
    document.getElementById('receipts-container').addEventListener('click', (e) => {
        const printBtn = e.target.closest('[data-print-receipt]');
        if (printBtn) prPrintReceiptById(Number(printBtn.dataset.printReceipt));

        const reorderBtn = e.target.closest('[data-reorder-receipt]');
        if (reorderBtn) prStartReorder(Number(reorderBtn.dataset.reorderReceipt));
    });

    <?php if ($canPost): ?>
    const newReceiptBtn = document.getElementById('newReceiptBtn');
    if (newReceiptBtn) {
        newReceiptBtn.addEventListener('click', () => {
            // A fresh "+ New Receipt" click is not a reorder -- discard any
            // items staged by a previous Reorder attempt that was abandoned.
            prClearReorderState();

            // Reset receiver to the logged-in user each time the modal opens
            const receivedBySel = document.getElementById('nr-received-by');
            if (receivedBySel && PR_SESSION_USER_ID) receivedBySel.value = PR_SESSION_USER_ID;

            // Reset the supplier picker back to its default, unselected state
            // each time the modal opens -- otherwise a supplier picked for a
            // previous receipt would leak into the next one.
            document.getElementById('nr-supplier-search').value = '';
            document.getElementById('nr-supplier-id').value = '';
            if (prSupplierSelect) prSupplierSelect.clearSelection();
            prSetViewHistoryEnabled(false);

            prOpenModal('new-receipt-modal');
        });
    }

    // "+ Add New Supplier" opens a dedicated modal rather than being baked
    // into the search dropdown itself -- every supplier, new or existing,
    // ends up as a real suppliers-table row referenced by supplier_id.
    const nrAddSupplierBtn = document.getElementById('nrAddSupplierBtn');
    if (nrAddSupplierBtn) {
        nrAddSupplierBtn.addEventListener('click', () => {
            prOpenModal('add-supplier-modal');
        });
    }

    // Reassigns the shared outer binding (see prCloseTopModal near the top
    // of this script) so Escape also discards any staged reorder items --
    // otherwise closing a Reorder-prefilled modal without submitting would
    // leave prPendingReorderItems stale for the next New Receipt attempt.
    prCloseNewReceiptModal = function () {
        prClearReorderState();
        prCloseModal('new-receipt-modal');
    };

    document.getElementById('closeNewReceiptModal').addEventListener('click',  prCloseNewReceiptModal);
    document.getElementById('cancelNewReceiptModal').addEventListener('click', prCloseNewReceiptModal);

    document.getElementById('new-receipt-modal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) prCloseNewReceiptModal();
    });

    // --- Add New Supplier modal --------------------------------------------
    // Cancelling leaves the New Receipt form's own supplier field exactly as
    // it was before "+ Add New Supplier" was clicked (unlike the old
    // text-input fallback, nothing on the main form needs to be cleared).
    prCloseAddSupplierModal = function () {
        prCloseModal('add-supplier-modal');
        document.getElementById('add-supplier-form').reset();
        document.getElementById('add-supplier-error').style.display = 'none';
    };

    document.getElementById('closeAddSupplierModal').addEventListener('click', prCloseAddSupplierModal);
    document.getElementById('cancelAddSupplierModal').addEventListener('click', prCloseAddSupplierModal);
    document.getElementById('add-supplier-modal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) prCloseAddSupplierModal();
    });

    document.getElementById('add-supplier-form').addEventListener('submit', async (e) => {
        e.preventDefault();

        const name          = document.getElementById('as-name').value.trim();
        const contactPerson = document.getElementById('as-contact-person').value.trim();
        const email         = document.getElementById('as-email').value.trim();
        const phone         = document.getElementById('as-phone').value.trim();
        const address       = document.getElementById('as-address').value.trim();
        const notes         = document.getElementById('as-notes').value.trim();
        const errEl      = document.getElementById('add-supplier-error');
        const submitBtn  = document.getElementById('submitAddSupplierBtn');

        errEl.style.display = 'none';

        if (!name) {
            errEl.textContent = 'Supplier name is required.';
            errEl.style.display = 'block';
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';

        try {
            const { response, data: payload } = await prFetch(
                window.SFMS_PUBLIC_URL('/api/suppliers/quick-create'),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        name:            name,
                        contact_person:  contactPerson || null,
                        contact_email:   email || null,
                        phone:           phone || null,
                        address:         address || null,
                        notes:           notes || null,
                    }),
                }
            );

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to create supplier');
            }

            const newId = payload.data?.supplier_id;

            // Drop the freshly created supplier straight into the New
            // Receipt form's search field/hidden id, exactly as if the user
            // had typed its name and picked it from the dropdown.
            if (newId) {
                document.getElementById('nr-supplier-search').value = name;
                document.getElementById('nr-supplier-id').value = String(newId);
                if (prSupplierSelect) prSupplierSelect.clearSelection();
                prSetViewHistoryEnabled(true);
            }

            document.getElementById('add-supplier-form').reset();
            prCloseModal('add-supplier-modal');
        } catch (err) {
            errEl.textContent = err.message || 'An error occurred.';
            errEl.style.display = 'block';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Supplier';
        }
    });

    // --- View Supplier History modal ---------------------------------------
    const nrViewSupplierHistoryBtn = document.getElementById('nrViewSupplierHistoryBtn');
    if (nrViewSupplierHistoryBtn) {
        nrViewSupplierHistoryBtn.addEventListener('click', () => {
            const supplierId = document.getElementById('nr-supplier-id').value;
            const supplierName = document.getElementById('nr-supplier-search').value;
            if (!supplierId) return; // disabled until a supplier is picked, but guard anyway
            prOpenSupplierHistory(supplierId, supplierName);
        });
    }

    prCloseSupplierHistoryModal = function () {
        prCloseModal('supplier-history-modal');
    };

    document.getElementById('closeSupplierHistoryModal').addEventListener('click', prCloseSupplierHistoryModal);
    document.getElementById('closeSupplierHistoryModalFooter').addEventListener('click', prCloseSupplierHistoryModal);
    document.getElementById('supplier-history-modal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) prCloseSupplierHistoryModal();
    });

    // Row-level Reorder buttons inside the history table are re-rendered on
    // every open, so delegate once here rather than rebinding per row.
    document.getElementById('sh-receipts-container').addEventListener('click', (e) => {
        const reorderBtn = e.target.closest('[data-reorder-receipt]');
        if (reorderBtn) prStartReorder(Number(reorderBtn.dataset.reorderReceipt));
    });

    document.getElementById('new-receipt-form').addEventListener('submit', async (e) => {
        e.preventDefault();

        const orNumber       = document.getElementById('nr-or-number').value.trim();
        const receiptDate    = document.getElementById('nr-receipt-date').value.trim();
        const supplierRaw    = document.getElementById('nr-supplier-id').value;
        const supplierId     = supplierRaw ? parseInt(supplierRaw, 10) : null;
        const departmentId   = document.getElementById('nr-department-id').value;
        const remarks        = document.getElementById('nr-remarks').value.trim();
        const receivedByRaw  = document.getElementById('nr-received-by').value;
        const receivedById   = receivedByRaw ? parseInt(receivedByRaw, 10) : null;
        const errEl        = document.getElementById('new-receipt-error');
        const submitBtn    = document.getElementById('submitNewReceiptBtn');

        errEl.style.display = 'none';

        if (!orNumber || !receiptDate || !supplierId) {
            errEl.textContent = 'OR number, receipt date, and supplier are required.';
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
                        // Every supplier -- existing or just-created via the
                        // Add New Supplier modal -- is a real suppliers-table
                        // row by this point, so supplier_id is always sent.
                        // PurchaseReceiptController::store() still accepts a
                        // bare supplier_name too (back-compat), just unused
                        // by this form now.
                        supplier_id:           supplierId,
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

            // Reorder Previous Receipt: hand the staged items off to the
            // detail page across this hard navigation via sessionStorage,
            // keyed by the new receipt's id so it's consumed exactly once.
            if (prPendingReorderItems && prPendingReorderItems.length && newId) {
                try {
                    sessionStorage.setItem(prReorderSessionKey(newId), JSON.stringify(prPendingReorderItems));
                } catch (_) {
                    // sessionStorage unavailable (e.g. private browsing) --
                    // the receipt was already created successfully, so
                    // just proceed without the item carry-over rather than
                    // blocking navigation.
                }
            }
            prClearReorderState();

            window.location.href = `${PURCHASE_PAGE}?id=${newId}`;
        } catch (err) {
            errEl.textContent = err.message || 'An error occurred.';
            errEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Receipt';
        }
    });

    // Cross-page Reorder entry points (receipt detail page, Supplier
    // Directory's history modal) can't open new-receipt-modal directly --
    // it doesn't exist on their pages -- so they redirect here instead.
    // Strip the param so a page refresh/back-navigation doesn't re-trigger it.
    const prReorderParam = new URLSearchParams(window.location.search).get('reorder');
    if (prReorderParam) {
        const prReorderSourceId = parseInt(prReorderParam, 10);
        if (prReorderSourceId) {
            const prReorderUrl = new URL(window.location.href);
            prReorderUrl.searchParams.delete('reorder');
            window.history.replaceState({}, '', prReorderUrl.toString());
            prStartReorder(prReorderSourceId);
        }
    }
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

// 2026-10-27 — a Draft receipt with zero line items could still be posted:
// the backend already rejected it (PurchaseReceiptPostingService::postReceipt()
// throws "Cannot post a receipt with no items"), but the button stayed
// enabled and the "This will add stock to inventory..." confirm dialog still
// appeared, only to fail afterward. This disables Post Receipt up front
// whenever prCurrentItems is empty, called every time loadReceiptItems()
// refreshes the line-item list (initial load, after Add Items, after Remove).
function prSyncPostButtonState() {
    const postBtn = document.getElementById('postReceiptBtn');
    if (!postBtn) return;

    const hasItems = Array.isArray(prCurrentItems) && prCurrentItems.length > 0;
    postBtn.disabled = !hasItems;
    postBtn.title = hasItems ? '' : 'Add at least one item before posting this receipt.';
}

// Removes a mistaken line while the receipt is still a draft (a draft line has
// not added any stock yet, so this has no inventory effect).
let prPendingRemoveLine = null;
let prRemovingLine = false;

function prRemoveLine(lineId, itemName) {
    if (prRemovingLine || prPendingRemoveLine || prReceiptStatus !== 'draft') return;
    prPendingRemoveLine = lineId;
    document.getElementById('remove-line-message').textContent = `Are you sure you want to remove "${itemName}" from this receipt?`;
    const error = document.getElementById('remove-line-error');
    error.textContent = '';
    error.hidden = true;
    prOpenModal('remove-line-modal');
}

function prCloseRemoveLineModal() {
    if (prRemovingLine) return;
    prPendingRemoveLine = null;
    prCloseModal('remove-line-modal');
}

async function prConfirmRemoveLine() {
    if (prRemovingLine || !prPendingRemoveLine) return;
    const lineId = prPendingRemoveLine;
    const modal = document.getElementById('remove-line-modal');
    const confirmButton = document.getElementById('confirmRemoveLine');
    const cancelButton = document.getElementById('cancelRemoveLine');
    const error = document.getElementById('remove-line-error');
    prRemovingLine = true;
    confirmButton.disabled = true;
    cancelButton.disabled = true;
    confirmButton.textContent = 'Removing...';
    modal.setAttribute('aria-busy', 'true');
    modal.focus();
    error.textContent = '';
    error.hidden = true;
    try {
        const { response, data: payload } = await prFetch(
            `${PURCHASE_API}/${PR_RECEIPT_ID}/items/${lineId}`,
            { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to remove item');
        }
        prPendingRemoveLine = null;
        prCloseModal('remove-line-modal');
        prNotify(payload.message || 'Item removed from receipt.', 'success');
        await loadReceiptItems();
        // The removed row's button no longer exists after the refresh.
        document.getElementById('addItemBtn')?.focus();
    } catch (err) {
        error.textContent = err.message || 'Unable to remove item. Please try again.';
        error.hidden = false;
    } finally {
        prRemovingLine = false;
        confirmButton.disabled = false;
        cancelButton.disabled = false;
        confirmButton.textContent = 'Yes, Remove';
        modal.removeAttribute('aria-busy');
        if (prPendingRemoveLine) cancelButton.focus();
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
            <div class="detail-grid-2col">
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
        prSyncPostButtonState();

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
                   aria-describedby="pr-item-name-error-${rowId}" aria-invalid="false"
                   value="${prEscapeHtml(data.item_name || '')}">
            <p class="pr-item-name-error" id="pr-item-name-error-${rowId}" role="alert"></p>
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
    if (data.item_id) {
        itemInput.dataset.itemId = String(data.item_id);
        itemInput.dataset.selectedItemName = prNormalizeItemName(data.item_name || '');
    }

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

function prNormalizeItemName(name) {
    return String(name).replace(/[\s\p{Z}]+/gu, ' ').trim();
}

function prItemNameError(name) {
    const length = Array.from(name).length;
    if (!name) return 'Item name is required.';
    if (length < 2 || length > 100) return 'Item name must be between 2 and 100 characters.';
    if (!/\p{L}/u.test(name)) return 'Item name must contain at least one letter.';
    if (!/^[\p{L}\p{M}\p{Nd} .,/()\-]+$/u.test(name)) {
        return 'Item name may contain only letters, numbers, spaces, hyphens, periods, slashes, commas, and parentheses.';
    }
    return null;
}

function prSetItemNameError(row, message) {
    row.querySelector('.pr-item-name-error').textContent = message || '';
    row.querySelector('.pr-item-input').setAttribute('aria-invalid', message ? 'true' : 'false');
}

function prReadRow(row) {
    const itemInput = row.querySelector('.pr-item-input');
    const rawItemId = itemInput.dataset.itemId;
    const qtyRaw = row.querySelector('.pr-qty-input').value.trim();

    return {
        item_name: prNormalizeItemName(itemInput.value),
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
        const input = row.querySelector('.pr-item-input');
        const unchangedSelection = data.item_id && input.dataset.selectedItemName === data.item_name;
        const nameError = unchangedSelection ? null : prItemNameError(data.item_name);

        if (nameError) {
            if (!blank || showBlanks) message = nameError;
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

        prSetRowNote(row, nameError ? null : message, kind);
        prSetItemNameError(row, nameError && message ? message : null);
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

// Reorder Previous Receipt: picks up the items staged by prStartReorder()
// (list-view page) via sessionStorage, keyed by this receipt's own id so
// it's only ever consumed once -- a page refresh afterwards won't re-apply
// it. Must run AFTER loadReceiptDetail() has populated prReceiptStatus and
// the dropdown-dependent prCategoryOptions()/prUnitOptions() data, since
// prAddRow() needs both.
async function prConsumePendingReorderItems() {
    let raw = null;
    try {
        raw = sessionStorage.getItem(prReorderSessionKey(PR_RECEIPT_ID));
        if (raw) sessionStorage.removeItem(prReorderSessionKey(PR_RECEIPT_ID));
    } catch (_) {
        return; // sessionStorage unavailable -- nothing to consume
    }
    if (!raw) return;

    let items;
    try {
        items = JSON.parse(raw);
    } catch (_) {
        return;
    }
    if (!Array.isArray(items) || items.length === 0) return;

    // This receipt is always a fresh draft when Reorder creates it, but
    // guard anyway in case the staged data outlives that (e.g. the user
    // reordered, then posted the receipt before this ever ran).
    if (prReceiptStatus !== 'draft') return;

    await loadAddItemDropdowns();

    // Build the rows directly rather than calling prResetRows() -- that
    // helper always leaves one empty row behind, which would show up here
    // as an unwanted blank line ahead of the copied items.
    const container = document.getElementById('pr-bulk-rows');
    if (!container) return;
    container.innerHTML = '';
    prRowSeq = 0;
    prSetBulkError('');
    items.forEach((item) => prAddRow(item, false));
    if (prRowElements().length === 0) prAddRow(null, false);

    prOpenModal('add-items-modal');
    const firstInput = document.querySelector('#pr-bulk-rows .pr-item-input');
    if (firstInput) firstInput.focus();

    prNotify(`Copied ${items.length} item${items.length === 1 ? '' : 's'} from the previous receipt — review and adjust quantities before posting.`, 'info');
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
    input.dataset.selectedItemName = prNormalizeItemName(item.name);
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
    document.getElementById('cancelRemoveLine').addEventListener('click', prCloseRemoveLineModal);
    document.getElementById('confirmRemoveLine').addEventListener('click', prConfirmRemoveLine);
    document.getElementById('remove-line-modal').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) prCloseRemoveLineModal();
    });
    document.getElementById('remove-line-modal').addEventListener('keydown', (e) => {
        // Both buttons are disabled while waiting; keep Tab inside the dialog.
        if (prRemovingLine && e.key === 'Tab') e.preventDefault();
    });

    // Reorder's sessionStorage hand-off can only be consumed once
    // loadReceiptDetail() has populated prReceiptStatus (draft-only guard
    // above) -- sequence them instead of the previous fire-and-forget call.
    (async () => {
        await loadReceiptDetail();
        await prConsumePendingReorderItems();
    })();

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

    prCloseAddItems = function () {
        prHideSuggest();
        prCloseModal('add-items-modal');
    };

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
            delete e.target.dataset.selectedItemName;

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
        if (submitBtn.dataset.submitting === '1') return;
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
            if (firstBad) {
                firstBad.scrollIntoView({ block: 'nearest' });
                firstBad.querySelector(firstBad.querySelector('.pr-item-input').getAttribute('aria-invalid') === 'true'
                    ? '.pr-item-input' : '.pr-qty-input').focus();
            }
            return;
        }

        if (ready.length === 0) {
            prSetBulkError('Add at least one item before saving.');
            return;
        }

        const originalLabel = submitBtn.textContent;
        let hasServerRowErrors = false;
        submitBtn.dataset.submitting = '1';
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
                const rowErrors = payload.data?.errors || [];
                hasServerRowErrors = rowErrors.length > 0;
                rowErrors.forEach((rowErr) => {
                    const entry = ready[rowErr.index];
                    if (entry && entry.row) {
                        if (rowErr.field === 'item_name') prSetItemNameError(entry.row, rowErr.message);
                        else prSetRowNote(entry.row, rowErr.message, 'error');
                    }
                });
                const firstBad = ready[rowErrors[0]?.index]?.row;
                if (firstBad) {
                    firstBad.scrollIntoView({ block: 'nearest' });
                    firstBad.querySelector(rowErrors[0].field === 'item_name' ? '.pr-item-input' : '.pr-qty-input').focus();
                    return;
                }
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
            delete submitBtn.dataset.submitting;
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
            if (!hasServerRowErrors) prRefreshRowState();
        }
    });

    // Post Receipt button (only rendered in DOM when $canPost is true)
    <?php if ($canPost): ?>
    const postBtn = document.getElementById('postReceiptBtn');
    if (postBtn) {
        postBtn.addEventListener('click', async () => {
            // Belt-and-suspenders: the button is disabled whenever
            // prCurrentItems is empty (see prSyncPostButtonState()), so this
            // normally can't be reached with zero items — but guard the
            // handler itself too, so a stale/disabled-state race never lets
            // the "are you sure" dialog appear for a receipt with nothing on
            // it. The server has the authoritative rejection either way.
            if (!Array.isArray(prCurrentItems) || prCurrentItems.length === 0) {
                prNotify('Cannot post a receipt with no items. Add at least one item first.');
                return;
            }

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
