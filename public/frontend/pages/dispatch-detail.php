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

$_ddUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? [];
$_ddRole = strtolower(trim((string)($_ddUser['role'] ?? '')));

// TASK 13 — Dispatch Release Assignment Workflow. The single $canAct flag is
// replaced by three capabilities, because the three actions on this page now
// belong to three different roles:
//   $canApprove — Administrator decides approve/reject
//   $canAssign  — Head Maintenance chooses/changes the Release Personnel
//   $canRelease — Maintenance Staff performs the release, but ONLY for the
//                 dispatch assigned to them. Role alone cannot express that,
//                 so the identity check is completed in JS once the dispatch
//                 payload arrives — and re-checked server-side, which is the
//                 check that actually enforces it.
$canApprove = ($_ddRole === 'super_admin');
$canAssign  = ($_ddRole === 'maintenance_admin');
$canRelease = ($_ddRole === 'maintenance_staff');
// Cancel keeps its pre-existing audience (Administrator + Head Maintenance).
$canCancel  = in_array($_ddRole, ['super_admin', 'maintenance_admin'], true);
// Any action section at all — used only to decide whether to render the
// container that holds the buttons.
$canAct     = $canApprove || $canAssign || $canRelease || $canCancel;
$dispatchId = isset($_GET['id']) && (int)$_GET['id'] > 0 ? (int)$_GET['id'] : 0;
$pageTitle  = 'Dispatch Detail - SFMS';
$pageStylesheets = [
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1',
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-workflow.css?v=20260726-1',
];
include __DIR__ . '/../includes/header.php';
?>

<!-- TASK 13.1 — page-scoped styles for the Release Assignment block.
     Deliberately NOT added to enterprise-workflow.css: that file is shared by
     nine other pages, and these classes are used by exactly one. This mirrors
     the page-local <style> pattern dispatches.php already uses.

     Every colour, radius and spacing value below resolves to an existing
     design token (--border / --card-color / --radius-lg / --radius-md /
     --text-muted / --text-light / --primary / --primary-bg), so dark mode is
     inherited automatically and no new palette or gradient is introduced. The
     avatar reuses the shared .user-avatar-wrap / .avatar-circle / .avatar-img
     trio from styles.css rather than defining a second avatar component. -->
<style>
/* TASK 13.2 §2 — visual hierarchy. Person and facts used to sit side by side
   and compete; the person is now a full-width identity block and the facts sit
   below a divider as clearly subordinate detail. Spacing only — same tokens,
   same typography, same badges. */
.dispatch-detail-page .dd-assignment {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.dispatch-detail-page .dd-assignment-person {
    display: flex;
    align-items: center;
    gap: 16px;
    min-width: 0;
}

/* .avatar-circle (the initial) sits in normal flow and defines the box; the
   photo is layered over it so that if the image 404s its onerror simply
   removes it and the initial is already there underneath — no broken-image
   frame, no second code path. */
.dispatch-detail-page .dd-avatar {
    position: relative;
    flex-shrink: 0;
}

.dispatch-detail-page .dd-avatar-photo {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

/* The Release Assignment card's own avatar — larger, because on that card the
   person IS the subject. Everywhere else the shared 40px default applies. */
.dispatch-detail-page .dd-avatar-lg {
    width: 52px;
    height: 52px;
    font-size: 19px;
}

.dispatch-detail-page .dd-assignment-person-text {
    min-width: 0;
}

.dispatch-detail-page .dd-assignment-eyebrow {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 3px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--text-muted, #6b7280);
}

/* §3 — "✓ Assigned". Existing .badge-success, just sized down to sit inline
   with the eyebrow instead of outweighing it. */
.dispatch-detail-page .dd-assignment-check {
    font-size: 11px;
    letter-spacing: 0.02em;
    text-transform: none;
}

.dispatch-detail-page .dd-assignment-name {
    margin: 0;
    font-size: 19px;
    font-weight: 700;
    line-height: 1.25;
    color: var(--text-light, #111827);
    word-break: break-word;
}

.dispatch-detail-page .dd-assignment-department {
    margin: 2px 0 0;
    font-size: 13px;
    color: var(--text-muted, #6b7280);
    word-break: break-word;
}

/* A <dl>, because these genuinely are term/definition pairs. The border-top is
   the divider from the §2 layout: it separates "who" from "the paperwork about
   who" without adding a heading or a second card. auto-fit keeps the three
   facts on one aligned row on desktop and stacks them on a phone, with no
   media query and no fixed column count to maintain. */
.dispatch-detail-page .dd-assignment-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px 28px;
    margin: 0;
    padding-top: 16px;
    border-top: 1px solid var(--border, #e5e7eb);
}

.dispatch-detail-page .dd-assignment-fact {
    min-width: 0;
}

.dispatch-detail-page .dd-assignment-fact dt {
    margin: 0 0 4px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--text-muted, #6b7280);
}

.dispatch-detail-page .dd-assignment-fact dd {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-light, #111827);
    word-break: break-word;
}

.dispatch-detail-page .dd-assignment-hint {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--text-muted, #6b7280);
}

/* Approve dialog (§3) — the same block, given a quiet surround so it reads as
   context the Administrator must acknowledge rather than a field they can
   change. It contains no input, and the approve endpoint accepts no personnel
   field, so it is read-only by construction as well as by appearance. */
.dispatch-detail-page .dd-approve-assignment:not(:empty) {
    border: 1px solid var(--border, #e5e7eb);
    border-left: 3px solid var(--primary, #6d28d9);
    border-radius: var(--radius-md, 12px);
    background: var(--primary-bg, #f5f3ff);
    padding: 14px 16px;
    margin-bottom: 14px;
}

/* ====================================================================
   TASK 13.2 §5 — dispatch identity strip. Occupies the existing subtitle
   line under the <h2>; wraps instead of growing on narrow screens.
   ==================================================================== */
.dispatch-detail-page .dd-header-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 18px;
    margin-top: 6px;
}

.dispatch-detail-page .dd-header-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    line-height: 1.4;
    color: var(--text-muted, #6b7280);
}

/* The person's name is the one thing in the strip allowed to be emphatic. */
.dispatch-detail-page .dd-header-meta-item strong {
    font-weight: 700;
    color: var(--text-light, #111827);
}

.dispatch-detail-page .dd-header-meta-icon {
    font-size: 13px;
    line-height: 1;
    opacity: 0.8;
}

/* ====================================================================
   TASK 13.2 §6 — timeline polish. Structure and states are unchanged
   (.report-timeline* from enterprise-reports.css); this only aligns the
   timestamp opposite the step label and quiets the secondary text, so
   the stepper reads WHAT / WHEN across and WHO underneath.
   ==================================================================== */
.dispatch-detail-page .dd-step-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 4px 14px;
}

.dispatch-detail-page .dd-step-head strong {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.dispatch-detail-page .dd-step-icon {
    font-size: 14px;
    line-height: 1;
    opacity: 0.9;
}

.dispatch-detail-page .dd-step-time {
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    color: var(--text-muted, #6b7280);
}

.dispatch-detail-page .dd-step-meta {
    font-size: 13px;
    line-height: 1.5;
    color: var(--text-muted, #6b7280);
}

.dispatch-detail-page .report-timeline-item {
    padding-bottom: 18px;
}

.dispatch-detail-page .report-timeline-item:last-child {
    padding-bottom: 0;
}

/* A pending step's timestamp/meta must stay muted-but-legible rather than
   inheriting the dimmed state colour twice over. */
.dispatch-detail-page .report-timeline-item-pending .dd-step-time,
.dispatch-detail-page .report-timeline-item-pending .dd-step-meta {
    color: var(--text-muted, #9ca3af);
}

/* ====================================================================
   TASK 13.2 §9 — focus visibility. The polish above changes layout, so
   this restates (rather than replaces) a visible focus ring on the
   page's interactive elements, in the existing --primary colour.
   ==================================================================== */
.dispatch-detail-page a:focus-visible,
.dispatch-detail-page button:focus-visible {
    outline: 2px solid var(--primary, #6d28d9);
    outline-offset: 2px;
}

@media (max-width: 640px) {
    .dispatch-detail-page .dd-assignment {
        gap: 14px;
    }

    .dispatch-detail-page .dd-assignment-facts {
        gap: 14px 20px;
        padding-top: 14px;
    }

    .dispatch-detail-page .dd-avatar-lg {
        width: 44px;
        height: 44px;
        font-size: 17px;
    }
}

/* ============================================================
   TASK — light-theme conversion for the "Change Release
   Personnel" searchable dropdown (Components.SearchableSelect,
   assets/js/components.js). That shared component paints its
   dropdown panel via an inline `style.cssText` (dark navy #1a1230
   background, pale lavender #f0eaff text) left over from the app's
   old dark theme, and styles.css ships one matching dark-theme
   rule, `.search-item:hover`. Neither components.js nor styles.css
   is touched here — this page-scoped block only repaints the
   dropdown on THIS page, mirroring the identical fix already
   applied to create-report.php (see create-report.inline.css).
   `!important` is required to beat the component's own inline
   style; the `.dispatch-detail-page` ancestor prefix keeps every
   other consumer of the same shared component (dispatch-create.php,
   preventive-maintenance.php, damage-report-update.php) untouched. */
.dispatch-detail-page .search-dropdown {
    background: #ffffff !important;
    border: 1px solid var(--border, #e5e7eb) !important;
    color: var(--text-primary, #111827) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10) !important;
    scrollbar-width: thin;
    scrollbar-color: var(--border, #e5e7eb) #ffffff;
}

.dispatch-detail-page .search-dropdown::-webkit-scrollbar {
    width: 8px;
}

.dispatch-detail-page .search-dropdown::-webkit-scrollbar-track {
    background: #ffffff;
}

.dispatch-detail-page .search-dropdown::-webkit-scrollbar-thumb {
    background: var(--border, #e5e7eb);
    border-radius: 8px;
}

.dispatch-detail-page .search-item {
    color: var(--text-primary, #111827);
}

/* Overrides styles.css's shared `.search-item:hover` for this page
   only (see block comment above). */
.dispatch-detail-page .search-item:hover {
    background: #f3e8ff !important;
    color: var(--text-primary, #111827) !important;
}
</style>

<main class="container dispatch-detail-page" style="margin-top:16px;">

    <!-- Section 1: Dispatch Header Card -->
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <div>
                <h2 id="dd-title">Dispatch</h2>
                <p class="text-muted mb-0" id="dd-subtitle">Loading...</p>
            </div>
            <div class="dd-header-actions">
                <?php /* TASK 7 — the 📄 and ← glyphs become registry icons. The
                         label text is unchanged, and both controls keep their
                         visible text, so the icons stay decorative. */ ?>
                <?php /* Dispatch Code stickers for the deployed items; shown by
                         loadDispatchDetail() only for approved/released
                         dispatches. */ ?>
                <button type="button" class="btn btn-primary" id="dd-print-labels-btn" style="display:none;"><?php echo ui_icon('file-text'); ?> Print Labels</button>
                <a href="<?php echo htmlspecialchars(public_url('/dispatches')); ?>" class="btn btn-secondary"><?php echo ui_icon('arrow-left'); ?> Back to Dispatches</a>
            </div>
        </div>
        <div class="card-body">
            <div id="dd-header-container">
                <div class="ui-empty-state"><strong>Loading dispatch details...</strong></div>
            </div>
        </div>
    </div>

    <!-- Section 1b: Release Assignment — TASK 13.1.
         Task 13 surfaced the assignment as three separate cards inside the
         generic header grid (Assigned Release Personnel / Assigned By /
         Assigned Date), where they read as three unrelated facts. They are one
         fact — "this person is responsible for releasing this dispatch" — so
         they are consolidated here into a single dedicated card and REMOVED
         from the header grid rather than duplicated. Presentation only: every
         value below already existed in the GET /api/dispatches/{id} payload. -->
    <div class="card dd-assignment-card" id="dd-assignment-card" style="margin-top:14px;display:none;">
        <div class="card-header">
            <h3 style="margin:0;">Release Assignment</h3>
            <p class="text-muted mb-0" style="font-size:13px;margin-top:2px;">
                Who is responsible for physically releasing this dispatch's inventory, and which Head Maintenance assigned them.
            </p>
        </div>
        <div class="card-body">
            <div id="dd-assignment-body"></div>
        </div>
    </div>

    <!-- Section 2: Timeline — only rendered when the loaded dispatch has enough
         real, already-returned data (status + who) to represent progress;
         otherwise this card is left hidden by JS (see dd-timeline-card below). -->
    <div class="card" id="dd-timeline-card" style="margin-top:14px;display:none;">
        <div class="card-header">
            <h3 style="margin:0;">Dispatch Timeline</h3>
            <p class="text-muted mb-0" style="font-size:13px;margin-top:2px;">
                This dispatch record's own release workflow — separate from any earlier
                Need Change / Replacement Request approval on the originating report.
            </p>
        </div>
        <div class="card-body">
            <ol class="report-timeline" id="dd-timeline-list"></ol>
        </div>
    </div>

    <!-- Section 3: Items Table -->
    <div class="card" style="margin-top:14px;">
        <div class="card-header">
            <h3 style="margin:0;">Line Items</h3>
        </div>
        <div class="card-body">
            <div id="dd-items-container" class="table-responsive">
                <div class="ui-empty-state"><strong>Loading items...</strong></div>
            </div>
        </div>
    </div>

    <!-- Section 4: Action Buttons — each button is PHP role-gated so it is not
         even present in the DOM for a role that may not perform it; JS then
         gates on dispatch status (and, for Release, on the assignment). -->
    <?php if ($canAct): ?>
    <div id="dd-action-section" class="card" style="margin-top:14px;display:none;">
        <div class="card-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <?php if ($canApprove): ?>
            <button type="button" id="dd-approve-btn"
                    class="btn btn-primary"
                    style="display:none;background:#16a34a;border-color:#16a34a;">
                Approve Dispatch
            </button>
            <button type="button" id="dd-reject-btn"
                    class="btn btn-danger"
                    style="display:none;background:#dc2626;border-color:#dc2626;">
                Reject Dispatch
            </button>
            <?php endif; ?>
            <?php if ($canAssign): ?>
            <button type="button" id="dd-assign-btn"
                    class="btn btn-secondary"
                    style="display:none;">
                Change Release Personnel
            </button>
            <?php endif; ?>
            <?php if ($canRelease): ?>
            <button type="button" id="dd-release-btn"
                    class="btn btn-primary"
                    style="display:none;background:#2563eb;border-color:#2563eb;">
                Release / Deploy Items
            </button>
            <?php endif; ?>
            <?php if ($canCancel): ?>
            <button type="button" id="dd-cancel-btn"
                    class="btn btn-danger"
                    style="display:none;background:#dc2626;border-color:#dc2626;">
                Cancel Dispatch
            </button>
            <?php endif; ?>
            <!-- TASK 13 — the shared inline error slot that used to live here
                 was only ever written to by the old modal-less Release action.
                 Every action now runs through its own dialog and reports
                 failures inside that dialog, next to the field that caused
                 them, so this slot is gone rather than left dead. -->
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canCancel): ?>
    <!-- Cancel Dispatch Modal -->
    <div id="dd-cancel-modal" class="modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="dd-cancel-modal-title">
        <div class="modal-content" style="max-width:480px;">
            <div class="modal-header">
                <h3 class="modal-title" id="dd-cancel-modal-title">Cancel Dispatch</h3>
                <button type="button" class="modal-close" id="dd-cancel-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:12px;color:#374151;">Please provide a reason for cancelling this dispatch. This action cannot be undone.</p>
                <label for="dd-cancel-reason" style="font-weight:600;display:block;margin-bottom:6px;">Reason <span style="color:#ef4444;">*</span></label>
                <textarea id="dd-cancel-reason" rows="3" class="form-control" placeholder="Enter cancellation reason..." style="width:100%;resize:vertical;"></textarea>
                <p id="dd-cancel-error" style="color:#ef4444;font-size:13px;margin-top:6px;display:none;"></p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="dd-cancel-modal-dismiss">Keep Dispatch</button>
                <button type="button" class="btn btn-danger" id="dd-cancel-confirm-btn" style="background:#dc2626;border-color:#dc2626;">Confirm Cancel</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canApprove): ?>
    <!-- TASK 13 — Approval Review Dialog.
         The Release Personnel selector that TASK 4 put here has been REMOVED:
         the personnel is chosen by Head Maintenance during dispatch creation,
         and an Administrator is explicitly not permitted to assign it. The
         Administrator now only reviews the dispatch, department, items and
         assigned personnel, then approves. Approval no longer releases
         inventory — the assigned staff member does that later. -->
    <div id="dd-approve-modal" class="modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="dd-approve-modal-title">
        <div class="modal-content" style="max-width:560px;">
            <div class="modal-header">
                <h3 class="modal-title" id="dd-approve-modal-title">Approve Dispatch</h3>
                <button type="button" class="modal-close" id="dd-approve-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <!-- TASK 13.1 §3 — the Administrator must clearly see who will
                     release the inventory, and must NOT be able to edit it.
                     This slot renders the SAME read-only assignment block used
                     by the Release Assignment card above (one implementation,
                     no duplicated markup); it contains no input of any kind,
                     and the endpoint this dialog posts to accepts no personnel
                     field, so there is nothing to edit here even in principle. -->
                <div id="dd-approve-assignment" class="dd-approve-assignment"></div>
                <div id="dd-approve-summary" class="report-detail-grid" style="margin-bottom:16px;"></div>
                <p style="font-size:12px;color:#6b7280;margin:0;">Approving does not move any stock. The assigned release personnel will be notified and will perform the physical release.</p>
                <p id="dd-approve-error" style="color:#ef4444;font-size:13px;margin-top:10px;display:none;"></p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="dd-approve-modal-dismiss">Cancel</button>
                <button type="button" class="btn btn-primary" id="dd-approve-confirm-btn" style="background:#16a34a;border-color:#16a34a;">Approve Dispatch</button>
            </div>
        </div>
    </div>

    <!-- TASK 13 — Reject Dialog (Administrator). -->
    <div id="dd-reject-modal" class="modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="dd-reject-modal-title">
        <div class="modal-content" style="max-width:480px;">
            <div class="modal-header">
                <h3 class="modal-title" id="dd-reject-modal-title">Reject Dispatch</h3>
                <button type="button" class="modal-close" id="dd-reject-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:12px;color:#374151;">Please provide a reason for rejecting this dispatch. This action cannot be undone.</p>
                <label for="dd-reject-reason" style="font-weight:600;display:block;margin-bottom:6px;">Reason <span style="color:#ef4444;">*</span></label>
                <textarea id="dd-reject-reason" rows="3" class="form-control" placeholder="Enter rejection reason..." style="width:100%;resize:vertical;"></textarea>
                <p id="dd-reject-error" style="color:#ef4444;font-size:13px;margin-top:6px;display:none;"></p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="dd-reject-modal-dismiss">Keep Dispatch</button>
                <button type="button" class="btn btn-danger" id="dd-reject-confirm-btn" style="background:#dc2626;border-color:#dc2626;">Confirm Reject</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canAssign): ?>
    <!-- TASK 13 — Reassign Release Personnel (Head Maintenance).
         Reuses the same Components.SearchableSelect widget as the create page.
         Department-based scoping of candidates was removed (see
         DispatchAuthorizationService) — any active Maintenance Staff member
         is a candidate, regardless of department. -->
    <div id="dd-assign-modal" class="modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="dd-assign-modal-title">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3 class="modal-title" id="dd-assign-modal-title">Change Release Personnel</h3>
                <button type="button" class="modal-close" id="dd-assign-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <label for="dd-assign-personnel-search" style="font-weight:600;display:block;margin-bottom:6px;">Release Personnel <span style="color:#ef4444;">*</span></label>
                <input type="text" id="dd-assign-personnel-search" class="form-control" placeholder="Search maintenance staff..." style="width:100%;margin-bottom:4px;" autocomplete="off">
                <input type="hidden" id="dd-assign-personnel-id">
                <p style="font-size:12px;color:#6b7280;margin:0;">Any active Maintenance Staff member may be selected, regardless of department. Reassignment is no longer possible once the dispatch has been released.</p>
                <p id="dd-assign-error" style="color:#ef4444;font-size:13px;margin-top:10px;display:none;"></p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="dd-assign-modal-dismiss">Cancel</button>
                <button type="button" class="btn btn-primary" id="dd-assign-confirm-btn">Save Assignment</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canRelease): ?>
    <!-- TASK 13 — Release Confirmation (assigned Maintenance Staff).
         Replaces the previous UI.systemConfirm() prompt so the releasing staff
         member can attach remarks about the physical hand-off — the remarks
         field TASK 4 placed in the Administrator's approve dialog now lives
         here, where the person who was actually present fills it in. -->
    <div id="dd-release-modal" class="modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="dd-release-modal-title">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3 class="modal-title" id="dd-release-modal-title">Release Dispatch Items</h3>
                <button type="button" class="modal-close" id="dd-release-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:12px;color:#374151;">This will deduct the listed items from inventory stock. This action cannot be undone.</p>
                <label for="dd-release-remarks" style="font-weight:600;display:block;margin-bottom:6px;">Release Remarks <span style="color:#6b7280;font-weight:400;">(optional)</span></label>
                <textarea id="dd-release-remarks" rows="3" class="form-control" placeholder="e.g. Handed over at the maintenance office front desk." style="width:100%;resize:vertical;"></textarea>
                <p id="dd-release-error" style="color:#ef4444;font-size:13px;margin-top:10px;display:none;"></p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="dd-release-modal-dismiss">Cancel</button>
                <button type="button" class="btn btn-primary" id="dd-release-confirm-btn" style="background:#2563eb;border-color:#2563eb;">Confirm Release</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/vendor/qrcode-generator.js?v=1.4.4')); ?>"></script>
<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/dispatch-labels.js?v=20260928')); ?>"></script>
<script>
const DSP_ID      = <?php echo $dispatchId; ?>;
const DSP_CAN_ACT = <?php echo $canAct ? 'true' : 'false'; ?>;
// TASK 13 — per-capability flags mirroring the PHP gates above.
const DSP_CAN_APPROVE = <?php echo $canApprove ? 'true' : 'false'; ?>;
const DSP_CAN_ASSIGN  = <?php echo $canAssign ? 'true' : 'false'; ?>;
const DSP_CAN_RELEASE = <?php echo $canRelease ? 'true' : 'false'; ?>;
const DSP_CAN_CANCEL  = <?php echo $canCancel ? 'true' : 'false'; ?>;
const DSP_USER_ID = <?php echo (int)($_ddUser['user_id'] ?? $_ddUser['id'] ?? 0); ?>;

// TASK 4 — Approve & Release Workflow: holds the most recently loaded
// dispatch payload so the action dialogs can read from it.
let DD_CURRENT_DISPATCH = null;
// TASK 13 — the reassignment dialog's searchable select (the approve dialog no
// longer has one).
let DD_ASSIGN_PERSONNEL_SELECT = null;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ddEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function ddNotify(message, type = 'danger') {
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

function ddFormatDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleString(); } catch (e) { return String(d); }
}

// TASK 13.1 — the timeline (§7) must show a date AND a time per step, and the
// Release Assignment card (§1) shows a long-form date ("August 3, 2026"). Both
// come from the same split so the two never drift apart. Mirrors the
// dspFormatDateParts() helper dispatches.php already uses for its Date column.
function ddDateTimeParts(value) {
    if (!value) return { date: null, time: null };
    try {
        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) return { date: String(value), time: null };
        return {
            date: parsed.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }),
            time: parsed.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
        };
    } catch (e) {
        return { date: String(value), time: null };
    }
}

function ddFormatDateOnly(value) {
    return ddDateTimeParts(value).date || '—';
}

function ddDispatchBadge(status) {
    // Reuses the existing "Dispatch workflow status badges" classes already
    // defined in color-scheme.css (.badge-pending/.badge-approved/.badge-released/.badge-cancelled).
    const knownStatuses = ['pending', 'approved', 'released', 'cancelled'];
    const safeStatus = String(status || '').toLowerCase();
    const badgeClass = knownStatuses.includes(safeStatus) ? `badge-${safeStatus}` : 'badge-info';
    const label = String(status || '').replace(/\b\w/g, (s) => s.toUpperCase());
    return `<span class="badge ${badgeClass}">${ddEscapeHtml(label)}</span>`;
}

function ddItemStatusBadge(status) {
    // Reuses the new .badge-available/.badge-low-stock/.badge-out-of-stock
    // classes added to enterprise-workflow.css for item stock-status semantics.
    const knownStatuses = { available: 'badge-available', low_stock: 'badge-low-stock', out_of_stock: 'badge-out-of-stock' };
    const safeStatus = String(status || '').toLowerCase();
    const badgeClass = knownStatuses[safeStatus] || 'badge-info';
    const label = String(status || '').replace(/_/g, ' ').replace(/\b\w/g, (s) => s.toUpperCase());
    return `<span class="badge ${badgeClass}">${ddEscapeHtml(label)}</span>`;
}

// Reuses the existing .report-detail-grid / per-field "information card"
// visual language (same tokens as .report-info-card / the dashboards'
// .stat-icon-chip pattern) — a small additive component, not a new theme.
// TASK 7 — `icon` is now a name from the shared icon registry (see
// includes/icon-paths.php) rather than an emoji literal. ddIcon() turns it
// into the same inline SVG the server renders, so the whole page speaks one
// icon language. The wrapper is already aria-hidden; the label beside it
// carries the meaning.
function ddIcon(name, extraClass, size) {
    if (!window.UIIcons) {
        return '';
    }
    return window.UIIcons.svg(name, { size: size || 18, className: extraClass || '' });
}

function ddInfoCard(icon, label, valueHtml, wide) {
    return `
        <div class="dispatch-info-card${wide ? ' dispatch-info-card-wide' : ''}">
            <div class="dispatch-info-icon" aria-hidden="true">${ddIcon(icon)}</div>
            <div class="dispatch-info-body">
                <p class="dispatch-info-label">${ddEscapeHtml(label)}</p>
                <div class="dispatch-info-value">${valueHtml}</div>
            </div>
        </div>`;
}

function ddUnassignedValue(text) {
    return `<span class="report-unassigned">${ddEscapeHtml(text)}</span>`;
}

/**
 * TASK 13.2 §5 — the dispatch identity strip.
 *
 * §5 asks the header to surface Dispatch Number, Status, Release Personnel,
 * Department, Requested By and Created Date "without making the page taller",
 * focusing on alignment rather than new sections. So this ADDS NO SECTION: it
 * replaces the one-line "Status: <badge>" subtitle that was already there with
 * a single wrapping meta row, and the five fields it now carries are DELETED
 * from the information grid below rather than repeated. (The dispatch number
 * is the <h2> itself, which never needed a card of its own.)
 *
 * Net effect on height: five info cards removed, one existing line reused —
 * the header gets materially shorter, not taller, and the six facts that
 * identify a dispatch now read as one aligned group instead of being scattered
 * through an eleven-card grid.
 *
 * Every value is already on the payload. Icons are aria-hidden decoration; the
 * adjacent text carries the meaning.
 */
function ddHeaderMetaItem(icon, html, extraClass) {
    // TASK 7 — `icon` is an icon-registry name now, not an emoji literal.
    return `<span class="dd-header-meta-item${extraClass ? ' ' + extraClass : ''}"><span class="dd-header-meta-icon" aria-hidden="true">${ddIcon(icon, '', 14)}</span>${html}</span>`;
}

function ddHeaderMeta(dispatch) {
    let html = `<span class="dd-header-meta">`;
    html += `<span class="dd-header-meta-item">${ddDispatchBadge(dispatch.status)}</span>`;

    html += ddHeaderMetaItem('building', dispatch.department_name
        ? ddEscapeHtml(dispatch.department_name)
        : ddUnassignedValue('No department'));

    // The single most important fact this workflow added — who will physically
    // release the inventory — is stated in the header, at the top of the page,
    // before the reader scrolls anywhere.
    html += ddHeaderMetaItem('user', dispatch.release_assigned_to_name
        ? `Release by <strong>${ddEscapeHtml(dispatch.release_assigned_to_name)}</strong>`
        : ddUnassignedValue('No release personnel assigned'));

    html += ddHeaderMetaItem('user-check', dispatch.requested_by_name
        ? `Requested by ${ddEscapeHtml(dispatch.requested_by_name)}`
        : ddUnassignedValue('Unknown requester'));

    html += ddHeaderMetaItem('calendar', dispatch.created_at
        ? ddEscapeHtml(ddFormatDateOnly(dispatch.created_at))
        : ddUnassignedValue('No date'));

    html += '</span>';
    return html;
}

// ---------------------------------------------------------------------------
// TASK 13.1 — Release Assignment block.
//
// ONE implementation, rendered in two places: the "Release Assignment" card on
// this page, and the Administrator's approval dialog (§3), where it is purely
// informational. Everything it renders is already present in the dispatch
// payload — release_assigned_to_name / release_assigned_by_name /
// release_assigned_at, plus the eager-loaded release_assigned_to_user relation
// for the avatar. No new endpoint, no new field, no second request.
// ---------------------------------------------------------------------------

function ddInitial(name) {
    const trimmed = String(name || '').trim();
    return trimmed ? trimmed.charAt(0).toUpperCase() : '?';
}

// Reuses the existing avatar markup from styles.css (.user-avatar-wrap /
// .avatar-img / .avatar-circle) — the same trio the sidebar renders — rather
// than introducing a second avatar component. The initial-circle is always
// present underneath; the photo is layered over it and simply removed if it
// fails to load, so there is no broken-image state and no nested-quote
// onerror handler.
//
// TASK 13.2 §9 — the photo carries the person's name as alt text. The
// initials circle beneath it is marked aria-hidden: it is a typographic
// stand-in for a missing photo, not information, and the name is always
// rendered as real text immediately beside it. So a screen reader hears the
// name once from the visible text, plus once from the photo's alt when a
// photo exists — never a bare "image" and never an unlabelled letter.
//
// `size` is presentational only ('lg' on the Release Assignment card, default
// elsewhere) so one avatar implementation serves every surface.
function ddAvatar(name, avatarUrl, size) {
    const sizeClass = size === 'lg' ? ' dd-avatar-lg' : '';
    const circle = `<span class="avatar-circle" aria-hidden="true">${ddEscapeHtml(ddInitial(name))}</span>`;
    const photo  = avatarUrl
        ? `<img src="${ddEscapeHtml(avatarUrl)}" alt="${ddEscapeHtml(name || 'Profile photo')}" class="avatar-img dd-avatar-photo" onerror="this.remove();">`
        : '';
    return `<span class="user-avatar-wrap dd-avatar${sizeClass}">${circle}${photo}</span>`;
}

/**
 * Historically captioned the Release Personnel card with the assignee's
 * department, inferred by comparing the DISPATCH's department_id against the
 * assignee's department_id and reusing the dispatch's department NAME when
 * they matched (the payload never carried the assignee's own department name).
 *
 * That inference relied on department-based release-personnel restriction:
 * "the two departments are the same by construction" (any assignable staff
 * member necessarily shared the dispatch's department). That restriction was
 * REMOVED — see DispatchAuthorizationService's class doc comment — so a match
 * here is now coincidence, not identity, and would misrepresent an unrelated
 * reporting tag as the person's department. Always returns null so the caption
 * stays hidden; kept as a named function (rather than deleted outright) so the
 * call site below documents why nothing renders here.
 */
function ddAssigneeDepartment(dispatch) {
    return null;
}

// Maps dispatch status to the assignment's own plain-language state, using the
// existing .badge-pending/.badge-approved/.badge-released/.badge-cancelled
// classes from color-scheme.css. This is a RELABEL of dispatches.status for
// the assignee's point of view — no new status exists or is implied.
function ddAssignmentStatus(dispatch) {
    const status = String(dispatch.status || '').toLowerCase();

    if (!dispatch.release_assigned_to) {
        return { label: 'Not Assigned', tone: 'pending' };
    }
    if (status === 'cancelled') {
        // Same marker-based distinction the timeline uses: rejection and
        // cancellation both land on 'cancelled' and are told apart by which
        // marker the service wrote to notes.
        return /Rejected:/i.test(String(dispatch.notes || ''))
            ? { label: 'Rejected', tone: 'cancelled' }
            : { label: 'Cancelled', tone: 'cancelled' };
    }
    if (status === 'released') return { label: 'Released',           tone: 'released' };
    if (status === 'approved') return { label: 'Ready for Release',  tone: 'approved' };
    return { label: 'Waiting for Approval', tone: 'pending' };
}

function ddAssignmentFact(label, valueHtml) {
    return `
        <div class="dd-assignment-fact">
            <dt>${ddEscapeHtml(label)}</dt>
            <dd>${valueHtml}</dd>
        </div>`;
}

function ddAssignmentBlock(dispatch, options) {
    const opts = options || {};
    const showAssigner = opts.showAssigner !== false;

    const assignee   = dispatch.release_assigned_to_user || null;
    const name       = dispatch.release_assigned_to_name || '';
    const department = ddAssigneeDepartment(dispatch);
    const state      = ddAssignmentStatus(dispatch);

    if (!name) {
        return `
            <div class="ui-empty-state">
                <strong>No release personnel assigned.</strong>
                <span>Head Maintenance must assign a Maintenance Staff member before this dispatch can be approved.</span>
            </div>`;
    }

    // TASK 13.2 §3 — an explicit "✓ Assigned" confirmation chip for the
    // Administrator's approval dialog. It states a fact the Administrator
    // needs before authorising (someone IS assigned) at a glance, using the
    // existing .badge-success class — no new colour. It is a static label,
    // not a control: there is nothing to click and nothing to change.
    const assignedChip = opts.showAssignedIndicator
        // TASK 7 — the bare ✓ glyph becomes the registry check icon. The word
        // "Assigned" stays, so meaning never rests on the icon or on colour.
        ? '<span class="badge badge-success dd-assignment-check">' + ddIcon('check', '', 13) + ' Assigned</span>'
        : '';

    // TASK 13.2 §2 — visual hierarchy: the person is now a full-width identity
    // block at the top (larger avatar, name as the dominant line, department
    // beneath it), separated by a rule from the supporting facts below. Before
    // this, person and facts sat side by side and competed for attention.
    let html = '<div class="dd-assignment">';
    html += `
        <div class="dd-assignment-person">
            ${ddAvatar(name, assignee && assignee.avatar, 'lg')}
            <div class="dd-assignment-person-text">
                <p class="dd-assignment-eyebrow">
                    <span>Release Personnel</span>
                    ${assignedChip}
                </p>
                <p class="dd-assignment-name">${ddEscapeHtml(name)}</p>
                ${department ? `<p class="dd-assignment-department">${ddEscapeHtml(department)}</p>` : ''}
            </div>
        </div>`;

    html += '<dl class="dd-assignment-facts">';
    if (showAssigner) {
        html += ddAssignmentFact(
            'Assigned By',
            dispatch.release_assigned_by_name ? ddEscapeHtml(dispatch.release_assigned_by_name) : ddUnassignedValue('Not recorded')
        );
        html += ddAssignmentFact(
            'Assigned Date',
            dispatch.release_assigned_at ? ddEscapeHtml(ddFormatDateOnly(dispatch.release_assigned_at)) : ddUnassignedValue('Not recorded')
        );
    }
    // TASK 78 — once released, this "Status" fact and the page header's
    // ddDispatchBadge(dispatch.status) both say the literal word "Released"
    // a few inches apart. Every other tone still tells the reader something
    // the header doesn't (who can act next), so only 'released' is hidden.
    if (state.tone !== 'released') {
        html += ddAssignmentFact('Status', `<span class="badge badge-${state.tone}">${ddEscapeHtml(state.label)}</span>`);
    }
    html += '</dl>';

    if (opts.hint) {
        html += `<p class="dd-assignment-hint">${ddEscapeHtml(opts.hint)}</p>`;
    }

    html += '</div>';
    return html;
}

function renderReleaseAssignment(dispatch) {
    const card = document.getElementById('dd-assignment-card');
    const body = document.getElementById('dd-assignment-body');
    if (!card || !body) return;

    body.innerHTML = ddAssignmentBlock(dispatch, { showAssigner: true });
    card.style.display = 'block';
}

// ---------------------------------------------------------------------------
// Timeline — built entirely from fields already present in the dispatch
// payload returned by GET /api/dispatches/{id} (status, approved_by_name,
// released_by_name, receiver_name, created_at, notes). No new API calls or
// fields are introduced; this only presents existing data as a stepper.
// Reuses the .report-timeline component already defined for report-detail
// pages. Only real timestamps (created_at) are shown against a step; steps
// with no discrete "happened at" column in the schema show "by <name>"
// instead of inventing a date.
//
// TASK 13 — the stepper gains a "Release Personnel Assigned" stage between
// Created and Approved. That stage is NOT a `dispatches.status` value: the
// enum still holds only pending/approved/released/cancelled, and no
// 'assigned' status was introduced (it would have required an enum migration
// and would have silently fallen outside cancelDispatch()'s
// ['pending','approved'] whitelist). The stage is derived from whether
// release_assigned_to is populated, which is the fact it actually represents.
// In the normal flow it is already satisfied at creation time, because Head
// Maintenance picks the personnel on the create form — it renders as a
// completed step immediately, and only shows as pending for legacy rows
// created before this task.
//
// The ordering the stepper draws is enforced by the service layer, not
// assumed here: createDispatch() always writes status='pending';
// approveDispatch() rejects anything not 'pending' AND refuses to approve a
// dispatch with no release_assigned_to; releaseDispatch() rejects anything
// not 'approved'. So Assigned → Approved → Released is the only reachable
// order. This remains distinct from any upstream Need Change / Replacement
// Request approval on the originating report — hence the explicit "Dispatch
// Approved" wording (see card subtitle above).
// ---------------------------------------------------------------------------

// TASK 13.1 §7 — `detail` now accepts an array so a step can show the person
// on one line and the date/time on the next, instead of one run-on sentence.
// A plain string still works, so nothing that called this before changes
// meaning. .report-timeline-content is already a flex column, so the extra
// lines stack with no CSS change.
// TASK 13.2 §6 — `detail` now also accepts { lines, time } so the timestamp
// can be pulled out of the stacked secondary text and aligned to the right of
// the step label, the way an enterprise audit trail reads: WHAT happened on
// the left, WHEN on the right, WHO underneath. A plain string or array still
// works, so the cancelled/rejected branches below are unchanged.
//
// `icon` is optional and purely decorative — it is aria-hidden, and the step
// label immediately beside it carries the meaning. State is still conveyed by
// the existing dot colour AND the text, never by the icon alone.
function ddTimelineStep(label, state, detail, icon) {
    // state: 'done' | 'current' | 'pending' | 'cancelled'
    const stateClass = state === 'done' ? ' report-timeline-item-done'
        : state === 'cancelled' ? ' report-timeline-item-cancelled'
        : state === 'pending' ? ' report-timeline-item-pending'
        : '';

    const parts = (detail && typeof detail === 'object' && !Array.isArray(detail))
        ? detail
        : { lines: Array.isArray(detail) ? detail : [detail], time: '' };
    const lines = (parts.lines || []).filter(Boolean);

    return `
        <li class="report-timeline-item${stateClass}">
            <span class="report-timeline-dot"></span>
            <div class="report-timeline-content">
                <div class="dd-step-head">
                    <strong>${icon ? `<span class="dd-step-icon" aria-hidden="true">${ddIcon(icon, '', 15)}</span>` : ''}${ddEscapeHtml(label)}</strong>
                    ${parts.time ? `<span class="dd-step-time">${ddEscapeHtml(parts.time)}</span>` : ''}
                </div>
                ${lines.map((line) => `<span class="dd-step-meta">${ddEscapeHtml(line)}</span>`).join('')}
            </div>
        </li>`;
}

// TASK 13.1 §7 — "Each step should display User / Date / Time".
// TASK 13.2 §6 — the person stays as a secondary line under the label; the
// "Month D, YYYY · h:mm AM" stamp is returned separately so ddTimelineStep can
// align it with the label row. Either is omitted when the underlying value
// genuinely does not exist, rather than printing a placeholder date.
function ddStepMeta(userLine, timestamp) {
    const lines = [];
    if (userLine) lines.push(userLine);

    let time = '';
    if (timestamp) {
        const parts = ddDateTimeParts(timestamp);
        if (parts.date) time = parts.time ? `${parts.date} · ${parts.time}` : parts.date;
    }

    return { lines, time };
}

// TASK 41 — tells apart the two ways a dispatch can legitimately be sitting in
// the 'approved' (releasable) state:
//
//   1. Head Maintenance created it → status 'pending' → an Administrator
//      pressed Approve → approved_by / approved_at are filled in.
//   2. An Administrator created it → it is written straight to 'approved'
//      because no approval step applies to their own dispatches. No approval
//      ever happened, so approved_by / approved_at stay NULL — the service
//      deliberately does NOT forge an approver record.
//
// So "the dispatch has moved past the approval stage, but nobody approved it"
// is exactly the signature of case 2, and it is unambiguous: every dispatch
// that was actually approved carries the approver who did it. This reads only
// fields already present in the existing GET /api/dispatches/{id} payload — no
// new column, no new API field, no new status value.
function ddApprovalWasNotRequired(dispatch) {
    const status = String(dispatch.status || '').toLowerCase();
    if (status !== 'approved' && status !== 'released') return false;
    return !dispatch.approved_by_name;
}

function renderDispatchTimeline(dispatch) {
    const card = document.getElementById('dd-timeline-card');
    const list = document.getElementById('dd-timeline-list');
    const status = String(dispatch.status || '').toLowerCase();

    if (!status) {
        card.style.display = 'none';
        return;
    }

    let html = '';
    html += ddTimelineStep(
        'Dispatch Created',
        'done',
        ddStepMeta(
            dispatch.requested_by_name ? `by ${dispatch.requested_by_name}` : '',
            dispatch.created_at
        ),
        'file-text'
    );

    // TASK 13 — assignment step, derived from release_assigned_to.
    const assignedName = dispatch.release_assigned_to_name || '';
    html += ddTimelineStep(
        'Release Personnel Assigned',
        assignedName ? 'done' : 'pending',
        assignedName
            ? ddStepMeta(
                `${assignedName}${dispatch.release_assigned_by_name ? ` · assigned by ${dispatch.release_assigned_by_name}` : ''}`,
                dispatch.release_assigned_at
            )
            : 'Awaiting assignment',
        'user'
    );

    if (status === 'cancelled') {
        // A dispatch can only be cancelled/rejected from 'pending' or
        // 'approved' (DispatchService::cancelDispatch / rejectDispatch), so an
        // approval may or may not have happened first — reflect that using the
        // data actually present.
        if (dispatch.approved_by_name) {
            html += ddTimelineStep('Dispatch Approved', 'done', ddStepMeta(`by ${dispatch.approved_by_name}`, dispatch.approved_at), 'check-circle');
        }
        // TASK 13 — 'cancelled' is now reachable two ways: the pre-existing
        // Cancel action (notes get " | CANCELLED: <reason>") and the new
        // Administrator Reject action (notes get "\nRejected: <reason>"). No
        // separate 'rejected' enum value was added, so the two are told apart
        // by which marker the service wrote — the terminal step is labelled
        // accordingly rather than calling every rejection a cancellation.
        const rejectMatch = /Rejected:\s*(.+)$/i.exec(String(dispatch.notes || ''));
        const cancelMatch = /CANCELLED:\s*(.+)$/i.exec(String(dispatch.notes || ''));
        if (rejectMatch) {
            html += ddTimelineStep('Dispatch Rejected', 'cancelled', `Reason: ${rejectMatch[1]}`, 'slash');
        } else {
            html += ddTimelineStep('Dispatch Cancelled', 'cancelled', cancelMatch ? `Reason: ${cancelMatch[1]}` : 'Dispatch was cancelled', 'slash');
        }
    } else if (ddApprovalWasNotRequired(dispatch)) {
        // TASK 41 — an Administrator-created dispatch has no approval step at
        // all. Showing "Awaiting approval" here would be wrong (nothing is
        // waiting), and showing "Approved by —" would imply an approval that
        // never happened. It is reported as a completed step that states the
        // truth: approval did not apply.
        html += ddTimelineStep(
            'Approval Not Required',
            'done',
            'Created by an Administrator — no approval step applies',
            'check-circle'
        );
    } else {
        html += ddTimelineStep(
            'Dispatch Approved',
            dispatch.approved_by_name ? 'done' : 'pending',
            dispatch.approved_by_name
                ? ddStepMeta(`by ${dispatch.approved_by_name}`, dispatch.approved_at)
                : 'Awaiting approval',
            'check-circle'
        );
        // NOTE — this step deliberately carries no date/time. Approval and
        // assignment each have a real column (approved_at,
        // release_assigned_at); release does NOT — there is no released_at
        // anywhere in the schema, and adding one is a schema change this task
        // excludes. dispatches.updated_at happens to hold the release moment
        // today, but only because nothing may touch a released dispatch
        // afterwards — printing it as "Released at" would be an inference
        // dressed up as a record, so the person is shown and the timestamp is
        // honestly left out.
        html += ddTimelineStep(
            'Items Released',
            status === 'released' ? 'done' : 'pending',
            status === 'released'
                ? [
                    `by ${dispatch.released_by_name || '—'}`,
                    dispatch.receiver_name ? `Received by ${dispatch.receiver_name}` : '',
                ]
                : 'Awaiting release',
            'package'
        );
        // TASK 13.1 §7 — the requested terminal "Completed" node. This is a
        // presentation-only marker, not a status: 'released' IS terminal in
        // this schema (the enum is pending/approved/released/cancelled) and no
        // separate completion event or timestamp exists. It closes the stepper
        // visually and says nothing the data does not support.
        html += ddTimelineStep(
            'Completed',
            status === 'released' ? 'done' : 'pending',
            status === 'released' ? 'Dispatch closed — no further action required' : 'Not yet completed',
            'flag'
        );
    }

    list.innerHTML = html;
    card.style.display = 'block';
}

// ---------------------------------------------------------------------------
// Action-button visibility — TASK 13
//
// Mirrors DispatchAuthorizationService exactly:
//   Approve / Reject  → Administrator, while status === 'pending'
//   Change Personnel  → Head Maintenance, while status is 'pending' or
//                       'approved' (never once released — the requirement is
//                       "reassignment allowed after approval ONLY IF
//                       status != released")
//   Release           → Maintenance Staff, while status === 'approved', AND
//                       only when this user IS the assignee. The identity
//                       check is the point of the whole task, so it is done
//                       here as well as server-side; the server check in
//                       DispatchAuthorizationService::canReleaseDispatch() is
//                       the one that actually enforces it.
//   Cancel            → Administrator / Head Maintenance, pending or approved
// ---------------------------------------------------------------------------

function ddShowButton(id, visible) {
    const btn = document.getElementById(id);
    if (!btn) return false;
    btn.style.display = visible ? 'inline-flex' : 'none';
    return visible;
}

function ddRenderActionButtons(dispatch) {
    const status     = String(dispatch.status || '').toLowerCase();
    const assignedTo = dispatch.release_assigned_to !== null && dispatch.release_assigned_to !== undefined
        ? Number(dispatch.release_assigned_to)
        : null;
    const isAssignee = assignedTo !== null && DSP_USER_ID > 0 && assignedTo === DSP_USER_ID;

    let anyVisible = false;
    anyVisible = ddShowButton('dd-approve-btn', DSP_CAN_APPROVE && status === 'pending') || anyVisible;
    anyVisible = ddShowButton('dd-reject-btn',  DSP_CAN_APPROVE && status === 'pending') || anyVisible;
    anyVisible = ddShowButton('dd-assign-btn',  DSP_CAN_ASSIGN  && (status === 'pending' || status === 'approved')) || anyVisible;
    anyVisible = ddShowButton('dd-release-btn', DSP_CAN_RELEASE && status === 'approved' && isAssignee) || anyVisible;
    anyVisible = ddShowButton('dd-cancel-btn',  DSP_CAN_CANCEL  && (status === 'pending' || status === 'approved')) || anyVisible;

    const section = document.getElementById('dd-action-section');
    if (section) {
        section.style.display = anyVisible ? 'block' : 'none';
    }
}

// ---------------------------------------------------------------------------
// Load dispatch detail
// ---------------------------------------------------------------------------

async function loadDispatchDetail() {
    if (DSP_ID <= 0) {
        document.getElementById('dd-header-container').innerHTML =
            '<div class="ui-empty-state"><strong>Dispatch not found.</strong><span>No valid ID was provided in the URL.</span></div>';
        document.getElementById('dd-items-container').innerHTML = '';
        document.getElementById('dd-timeline-card').style.display = 'none';
        document.getElementById('dd-assignment-card').style.display = 'none';
        return;
    }

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/dispatches/${DSP_ID}`),
            { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
        );
        const payload = await response.json();

        if (response.status === 404 || (payload && !payload.success && /not found/i.test(payload.message || ''))) {
            document.getElementById('dd-header-container').innerHTML =
                '<div class="ui-empty-state"><strong>Dispatch not found.</strong><span>This dispatch does not exist or has been removed.</span></div>';
            document.getElementById('dd-items-container').innerHTML = '';
            document.getElementById('dd-timeline-card').style.display = 'none';
        document.getElementById('dd-assignment-card').style.display = 'none';
            document.getElementById('dd-title').textContent = 'Dispatch Not Found';
            document.getElementById('dd-subtitle').textContent = '';
            return;
        }

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load dispatch');
        }

        const dispatch = payload.data?.dispatch;
        const items    = Array.isArray(dispatch?.items) ? dispatch.items : [];

        if (!dispatch) {
            throw new Error('Invalid response: missing dispatch data');
        }

        // TASK 4 — Approve & Release Workflow: keep the last-loaded dispatch
        // around so the Approve & Release dialog can read its Dispatch
        // Number / Maintenance Report / Requested By / items without a
        // second API call.
        DD_CURRENT_DISPATCH = dispatch;

        const labelsBtn = document.getElementById('dd-print-labels-btn');
        if (labelsBtn) {
            labelsBtn.style.display = window.DispatchLabels && DispatchLabels.isPrintable(dispatch.status) ? '' : 'none';
        }

        // --- Header card: per-field information cards in a responsive 2-col grid ---
        document.getElementById('dd-title').textContent   = dispatch.dispatch_code || 'Dispatch';
        // TASK 13.2 §5 — the identity strip replaces the old "Status: <badge>"
        // subtitle in place. See ddHeaderMeta().
        document.getElementById('dd-subtitle').innerHTML  = ddHeaderMeta(dispatch);

        const itemCount = items.length;
        let headerCardsHtml = '<div class="report-detail-grid">';
        // TASK 13.2 §5 — "Dispatch Code", "Status", "Department", "Created" and
        // "Requested By" used to be five cards here. All five are now in the
        // identity strip above (and the code is the page's <h2>), so they are
        // REMOVED rather than shown twice. What remains in this grid is exactly
        // the secondary detail the strip does not carry.
        headerCardsHtml += ddInfoCard('door', 'Room / Lab', dispatch.room_name ? ddEscapeHtml(dispatch.room_name) : ddUnassignedValue('Not specified'));
        // Free-text supplement to Room / Lab, for whatever the registered
        // room alone doesn't say (building/floor, or a destination not yet
        // registered as a room at all) — see the room_note migration's doc
        // comment. Required on every dispatch created after this field
        // shipped, so an older dispatch with none is the only case that
        // legitimately shows nothing here.
        if (dispatch.room_note) {
            headerCardsHtml += ddInfoCard('pin', 'Location Details', ddEscapeHtml(dispatch.room_note), true);
        }
        headerCardsHtml += ddInfoCard('package', 'Items', `${itemCount} Item${itemCount === 1 ? '' : 's'}`);
        // TASK 3 — Dispatch Personnel Audit Trail: Requested By / Approved By /
        // Released By are three distinct responsibilities (the requesting
        // Maintenance Staff, the authorizing Administrator, and whoever
        // actually released/received the item) and must never be conflated —
        // see TASK3_DISPATCH_AUDIT_TRAIL.md. All three still render from their
        // own field; released_by is never assumed to equal approved_by.
        // (Requested By now lives in the identity strip; the other two below.)
        //
        // TASK 13.1 — "Assigned Release Personnel", "Assigned By" and
        // "Assigned Date" used to be three separate cards in this grid. They
        // are one fact and now live together in the dedicated Release
        // Assignment card below; they are removed from here rather than shown
        // twice.
        // TASK 41 — "Not yet approved" is only true while an approval is still
        // outstanding. For an Administrator-created dispatch no approval is
        // coming, so the placeholder is reworded to say so rather than
        // implying the dispatch is stuck waiting on someone.
        const ddNoApprovalNeeded = ddApprovalWasNotRequired(dispatch);
        headerCardsHtml += ddInfoCard('check-circle', 'Approved By', dispatch.approved_by_name ? ddEscapeHtml(dispatch.approved_by_name) : ddUnassignedValue(ddNoApprovalNeeded ? 'Approval not required' : 'Not yet approved'));
        headerCardsHtml += ddInfoCard('truck', 'Released By', dispatch.released_by_name ? ddEscapeHtml(dispatch.released_by_name) : ddUnassignedValue(dispatch.status === 'released' ? 'Not recorded' : 'Not yet released'));
        // TASK 13 — this card used to be labelled "Release Date" and rendered
        // approved_at, which was correct only while Task 4's approve-and-release
        // was a single atomic instant. Approval and release are now separate
        // events, and the schema has no released_at column, so showing
        // approved_at under a "Release Date" label would be a lie. The card is
        // relabelled to what the value actually is. (Assignment has its own
        // real timestamp, release_assigned_at, shown beside it.)
        headerCardsHtml += ddInfoCard('calendar', 'Approved Date', dispatch.approved_at ? ddEscapeHtml(ddFormatDate(dispatch.approved_at)) : ddUnassignedValue(ddNoApprovalNeeded ? 'Approval not required' : 'Not yet approved'));
        if (dispatch.release_remarks) {
            headerCardsHtml += ddInfoCard('message-square', 'Release Remarks', ddEscapeHtml(dispatch.release_remarks), true);
        }
        if (dispatch.notes) {
            headerCardsHtml += ddInfoCard('edit', 'Notes', ddEscapeHtml(dispatch.notes), true);
        }
        headerCardsHtml += '</div>';

        document.getElementById('dd-header-container').innerHTML = headerCardsHtml;

        // TASK 13.1 §1 — dedicated Release Assignment card.
        renderReleaseAssignment(dispatch);

        renderDispatchTimeline(dispatch);

        // --- Items table ---
        const itemsContainer = document.getElementById('dd-items-container');
        if (items.length === 0) {
            itemsContainer.innerHTML =
                '<div class="ui-empty-state"><strong>No items in this dispatch.</strong></div>';
        } else {
            let html = '<table class="table dd-items-table"><thead><tr>'
                + '<th scope="col">Item Name</th><th scope="col" class="text-right">Quantity</th>'
                + '<th scope="col">Item Status</th><th scope="col" class="text-right">Available Stock</th>'
                + '</tr></thead><tbody>';
            items.forEach((item) => {
                html += '<tr>';
                html += `<td><strong>${ddEscapeHtml(item.item?.name ?? '—')}</strong></td>`;
                html += `<td class="text-right">${ddEscapeHtml(item.quantity)}</td>`;
                html += `<td>${ddItemStatusBadge(item.item?.status ?? '')}</td>`;
                html += `<td class="text-right">${ddEscapeHtml(item.item?.quantity ?? '—')}</td>`;
                html += '</tr>';
            });
            html += '</tbody></table>';
            itemsContainer.innerHTML = html;
        }

        // --- Action buttons ---------------------------------------------
        // TASK 13 — each button was already PHP role-gated (a role that may
        // not perform an action has no such button in the DOM at all), so this
        // only decides visibility by dispatch state. None of this is a
        // security control: every branch below is re-checked server-side by
        // DispatchAuthorizationService, and hiding a button is presentation
        // only.
        if (DSP_CAN_ACT) {
            ddRenderActionButtons(dispatch);
        }
    } catch (err) {
        document.getElementById('dd-header-container').innerHTML =
            '<div class="ui-empty-state"><strong>Failed to load dispatch.</strong></div>';
        document.getElementById('dd-timeline-card').style.display = 'none';
        document.getElementById('dd-assignment-card').style.display = 'none';
        ddNotify(err.message || 'Unable to load dispatch details.');
    }
}

// ---------------------------------------------------------------------------
// Modal primitives
// ---------------------------------------------------------------------------

function ddOpenModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'flex';
    el.removeAttribute('aria-hidden');
}

function ddCloseModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
}

// Shared submit helper — every action dialog below does the same thing
// (disable the confirm button, POST JSON, reload on success, show the server's
// message inline on failure), so it lives in one place rather than being
// copy-pasted four times.
async function ddSubmitAction(path, body, confirmBtnId, errorElId, busyLabel) {
    const confirmBtn = document.getElementById(confirmBtnId);
    const errEl      = document.getElementById(errorElId);
    const origText   = confirmBtn ? confirmBtn.textContent : '';

    if (confirmBtn) {
        confirmBtn.disabled    = true;
        confirmBtn.textContent = busyLabel;
    }
    if (errEl) errEl.style.display = 'none';

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/dispatches/${DSP_ID}${path}`),
            {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body:        JSON.stringify(body),
            }
        );
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            // Server-side validation errors (e.g. inactive/wrong-role personnel,
            // insufficient stock, "not the assigned releaser") arrive here and
            // are shown verbatim — the server is the authority on all of them.
            const firstError = payload && payload.errors
                ? Object.values(payload.errors).flat()[0]
                : null;
            throw new Error(firstError || payload.message || 'The request could not be completed.');
        }

        window.location.reload();
    } catch (err) {
        if (errEl) {
            errEl.textContent   = err.message || 'An error occurred.';
            errEl.style.display = 'block';
        } else {
            ddNotify(err.message || 'An error occurred.');
        }
        if (confirmBtn) {
            confirmBtn.disabled    = false;
            confirmBtn.textContent = origText;
        }
    }
}

// ---------------------------------------------------------------------------
// Approve — TASK 13 (Administrator)
//
// This dialog is now purely a review step. The Release Personnel selector,
// Release Date and Release Remarks fields that TASK 4 put here are gone: the
// personnel is chosen by Head Maintenance at creation time, and the remarks
// belong to whoever physically performs the hand-off. Approving moves no
// stock — DispatchService::approveDispatch() no longer touches inventory.
// ---------------------------------------------------------------------------

function ddOpenApproveModal() {
    const dispatch = DD_CURRENT_DISPATCH;
    if (!dispatch) return;

    const items = Array.isArray(dispatch.items) ? dispatch.items : [];
    const itemsSummary = items.length
        ? items.map((it) => `${ddEscapeHtml(it.item?.name ?? 'Unknown')} (Qty: ${ddEscapeHtml(it.quantity)})`).join('<br>')
        : '—';

    // The four things the Administrator is asked to review: the dispatch
    // itself, the department it belongs to, the items, and who will release.
    let summaryHtml = '';
    summaryHtml += ddInfoCard('file-text', 'Dispatch Number', ddEscapeHtml(dispatch.dispatch_code));
    summaryHtml += ddInfoCard('building', 'Department', dispatch.department_name ? ddEscapeHtml(dispatch.department_name) : ddUnassignedValue('Not specified'));
    summaryHtml += ddInfoCard('door', 'Room / Lab', dispatch.room_name ? ddEscapeHtml(dispatch.room_name) : ddUnassignedValue('Not specified'));
    if (dispatch.room_note) {
        summaryHtml += ddInfoCard('pin', 'Location Details', ddEscapeHtml(dispatch.room_note), true);
    }
    summaryHtml += ddInfoCard('archive', 'Maintenance Report', dispatch.report_id ? `#${ddEscapeHtml(dispatch.report_id)}` : ddUnassignedValue('Not linked'));
    summaryHtml += ddInfoCard('user-check', 'Requested By', dispatch.requested_by_name ? ddEscapeHtml(dispatch.requested_by_name) : ddUnassignedValue('Unknown requester'));
    summaryHtml += ddInfoCard('package', 'Inventory Item(s) / Quantity', itemsSummary, true);
    if (dispatch.notes) {
        summaryHtml += ddInfoCard('edit', 'Remarks / Notes', ddEscapeHtml(dispatch.notes), true);
    }

    document.getElementById('dd-approve-summary').innerHTML = summaryHtml;

    // TASK 13.1 §3 — the assignment is promoted out of the summary grid (where
    // it was one small card among six) into the same prominent, read-only
    // block used on the page itself, so the Administrator cannot miss who will
    // physically release the inventory. Identical markup, one code path.
    // TASK 13.2 §3 — plus the "✓ Assigned" confirmation chip, so the
    // Administrator can see at a glance that the prerequisite is satisfied
    // rather than having to read the name and infer it.
    document.getElementById('dd-approve-assignment').innerHTML = ddAssignmentBlock(dispatch, {
        showAssigner: true,
        showAssignedIndicator: true,
        hint: 'Assigned by Head Maintenance. This cannot be changed from the approval dialog.',
    });

    const errEl      = document.getElementById('dd-approve-error');
    const confirmBtn = document.getElementById('dd-approve-confirm-btn');
    errEl.style.display = 'none';

    // A dispatch with no assigned personnel cannot be approved — the server
    // rejects it outright (approveDispatch() throws on a null
    // release_assigned_to), so say so here instead of letting the click fail.
    // Only legacy rows created before this task can be in that state.
    if (!dispatch.release_assigned_to) {
        errEl.textContent   = 'This dispatch has no assigned release personnel. Head Maintenance must assign one before it can be approved.';
        errEl.style.display = 'block';
        confirmBtn.disabled = true;
    } else {
        confirmBtn.disabled = false;
    }

    ddOpenModal('dd-approve-modal');
}

function ddCloseApproveModal() {
    ddCloseModal('dd-approve-modal');
}

async function doApprove() {
    // approved_by is sent for parity with the existing endpoint contract; the
    // controller re-derives the acting user from the session and the route is
    // gated to super_admin, so this value is not trusted for authorisation.
    await ddSubmitAction(
        '/approve',
        { approved_by: DSP_USER_ID },
        'dd-approve-confirm-btn',
        'dd-approve-error',
        'Approving...'
    );
}

// ---------------------------------------------------------------------------
// Reject — TASK 13 (Administrator)
// ---------------------------------------------------------------------------

function ddOpenRejectModal() {
    document.getElementById('dd-reject-reason').value = '';
    document.getElementById('dd-reject-error').style.display = 'none';
    ddOpenModal('dd-reject-modal');
    setTimeout(() => document.getElementById('dd-reject-reason').focus(), 50);
}

function ddCloseRejectModal() {
    ddCloseModal('dd-reject-modal');
}

async function doReject() {
    const reason = document.getElementById('dd-reject-reason').value.trim();
    const errEl  = document.getElementById('dd-reject-error');

    // Mirrors the controller's min:3 rule so the obvious case doesn't need a
    // round trip; the server rule is still the one that decides.
    if (reason.length < 3) {
        errEl.textContent   = 'Please enter a reason (at least 3 characters).';
        errEl.style.display = 'block';
        return;
    }

    await ddSubmitAction(
        '/reject',
        { reason },
        'dd-reject-confirm-btn',
        'dd-reject-error',
        'Rejecting...'
    );
}

// ---------------------------------------------------------------------------
// Change Release Personnel — TASK 13 (Head Maintenance)
//
// Allowed while pending and while approved-but-not-released. The candidate
// list is every active Maintenance Staff member — department-based scoping
// was REMOVED (see DispatchAuthorizationService's class doc comment); the
// endpoint takes no department parameter. Eligibility (active status,
// Maintenance Staff role, dispatch still pending/approved) is re-validated
// on submit by DispatchAuthorizationService::assertAssignableReleasePersonnel()
// and ::canAssignReleasePersonnel(). This widget only makes the choice
// convenient — the server is the authority.
// ---------------------------------------------------------------------------

function ddOpenAssignModal() {
    const dispatch = DD_CURRENT_DISPATCH;
    if (!dispatch) return;

    const searchInput = document.getElementById('dd-assign-personnel-search');
    const hiddenInput = document.getElementById('dd-assign-personnel-id');

    // Pre-fill with the current assignee so the dialog opens showing what it
    // is about to change, not an empty box.
    searchInput.value = dispatch.release_assigned_to_name || '';
    hiddenInput.value = dispatch.release_assigned_to || '';

    if (!DD_ASSIGN_PERSONNEL_SELECT) {
        // Same Components.SearchableSelect used by the dispatch create form —
        // no new widget, no new interaction pattern.
        //
        // TASK 75 — SearchableSelect's default hidden-value fallback chain
        // checks it.id then it.department_id before it.user_id — a release
        // personnel row (RepairService::searchTechnicians()) has no `id` but
        // DOES have `department_id`, so without this override the hidden
        // field ended up holding a department id instead of the selected
        // user's id. Same fix as dispatch-create.php and
        // repair-assignment.php's technician select.
        DD_ASSIGN_PERSONNEL_SELECT = new Components.SearchableSelect({
            inputId:  'dd-assign-personnel-search',
            hiddenId: 'dd-assign-personnel-id',
            endpoint: window.SFMS_PUBLIC_URL('/api/dispatches/support/release-personnel'),
            displayKey: 'full_name',
            onSelect: (it) => { document.getElementById('dd-assign-personnel-id').value = it.user_id || ''; },
        });
    }

    document.getElementById('dd-assign-error').style.display = 'none';
    ddOpenModal('dd-assign-modal');
}

function ddCloseAssignModal() {
    ddCloseModal('dd-assign-modal');
}

async function doAssign() {
    const releaseAssignedTo = Number(document.getElementById('dd-assign-personnel-id').value || 0);
    const errEl = document.getElementById('dd-assign-error');

    if (!releaseAssignedTo) {
        errEl.textContent   = 'Please select the Release Personnel.';
        errEl.style.display = 'block';
        return;
    }

    await ddSubmitAction(
        '/assign-personnel',
        { release_assigned_to: releaseAssignedTo },
        'dd-assign-confirm-btn',
        'dd-assign-error',
        'Saving...'
    );
}

// ---------------------------------------------------------------------------
// Release — TASK 13 (assigned Maintenance Staff only)
//
// This is the ONLY point at which inventory is deducted. The request body
// deliberately does NOT carry released_by: the controller uses the session
// user, and DispatchAuthorizationService::canReleaseDispatch() requires that
// user to equal dispatches.release_assigned_to. A staff member who reaches
// this endpoint for someone else's dispatch gets a 403 regardless of what the
// browser sends.
// ---------------------------------------------------------------------------

function ddOpenReleaseModal() {
    document.getElementById('dd-release-remarks').value = '';
    document.getElementById('dd-release-error').style.display = 'none';
    ddOpenModal('dd-release-modal');
    setTimeout(() => document.getElementById('dd-release-remarks').focus(), 50);
}

function ddCloseReleaseModal() {
    ddCloseModal('dd-release-modal');
}

async function doRelease() {
    const remarks = document.getElementById('dd-release-remarks').value.trim();

    await ddSubmitAction(
        '/release',
        { release_remarks: remarks || null },
        'dd-release-confirm-btn',
        'dd-release-error',
        'Releasing...'
    );
}

// ---------------------------------------------------------------------------
// Cancel
// ---------------------------------------------------------------------------

function ddOpenCancelModal() {
    document.getElementById('dd-cancel-reason').value = '';
    document.getElementById('dd-cancel-error').style.display = 'none';
    ddOpenModal('dd-cancel-modal');
    setTimeout(() => document.getElementById('dd-cancel-reason').focus(), 50);
}

function ddCloseCancelModal() {
    ddCloseModal('dd-cancel-modal');
}

async function doCancel() {
    const reason  = document.getElementById('dd-cancel-reason').value.trim();
    const errEl   = document.getElementById('dd-cancel-error');

    if (reason.length < 3) {
        errEl.textContent   = 'Please enter a reason (at least 3 characters).';
        errEl.style.display = 'block';
        return;
    }

    const confirmBtn      = document.getElementById('dd-cancel-confirm-btn');
    const origText        = confirmBtn.textContent;
    confirmBtn.disabled   = true;
    confirmBtn.textContent = 'Cancelling...';
    errEl.style.display   = 'none';

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/dispatches/${DSP_ID}/cancel`),
            {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body:        JSON.stringify({ reason }),
            }
        );
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to cancel dispatch');
        }

        ddCloseCancelModal();
        loadDispatchDetail();
    } catch (err) {
        errEl.textContent       = err.message || 'An error occurred while cancelling.';
        errEl.style.display     = 'block';
        confirmBtn.disabled     = false;
        confirmBtn.textContent  = origText;
    }
}

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------

function ddBind(id, event, handler) {
    const el = document.getElementById(id);
    if (el) el.addEventListener(event, handler);
}

// Wires the three ways every dialog closes (× button, dismiss button, backdrop
// click) plus its confirm action. Silently does nothing when the modal isn't
// in the DOM for this role.
function ddWireModal(modalId, closeFn, confirmBtnId, confirmFn) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    ddBind(`${modalId}-close`, 'click', closeFn);
    ddBind(`${modalId}-dismiss`, 'click', closeFn);
    ddBind(confirmBtnId, 'click', confirmFn);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeFn();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    loadDispatchDetail();

    document.getElementById('dd-print-labels-btn').addEventListener('click', () => {
        if (DD_CURRENT_DISPATCH && window.DispatchLabels) DispatchLabels.print(DD_CURRENT_DISPATCH);
    });

    // TASK 13 — buttons and modals are now conditionally rendered per role, so
    // every binding is null-guarded. A previously-unconditional
    // getElementById(...).addEventListener() would throw for any role that
    // doesn't get that particular control, killing the rest of this handler
    // (including the timeline and items rendering).
    ddBind('dd-approve-btn', 'click', ddOpenApproveModal);
    ddBind('dd-reject-btn',  'click', ddOpenRejectModal);
    ddBind('dd-assign-btn',  'click', ddOpenAssignModal);
    ddBind('dd-release-btn', 'click', ddOpenReleaseModal);
    ddBind('dd-cancel-btn',  'click', ddOpenCancelModal);

    ddWireModal('dd-approve-modal', ddCloseApproveModal, 'dd-approve-confirm-btn', doApprove);
    ddWireModal('dd-reject-modal',  ddCloseRejectModal,  'dd-reject-confirm-btn',  doReject);
    ddWireModal('dd-assign-modal',  ddCloseAssignModal,  'dd-assign-confirm-btn',  doAssign);
    ddWireModal('dd-release-modal', ddCloseReleaseModal, 'dd-release-confirm-btn', doRelease);
    ddWireModal('dd-cancel-modal',  ddCloseCancelModal,  'dd-cancel-confirm-btn',  doCancel);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
