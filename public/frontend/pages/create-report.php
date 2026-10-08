<?php
// Clean URLs: public_url() is used below, before header.php loads settings.
require_once __DIR__ . '/../../backend/config/settings.php';
/**
 * Create New Maintenance Report
 */
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

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: ' . public_url('/login'));
    exit;
}

// RBAC POLICY UPDATE — Administrator (super_admin) reviews/assigns/monitors
// reports but does not submit them, so direct URL access to Create Report is
// blocked for that role. Head Maintenance (maintenance_admin) and
// Maintenance Staff are the report submitters.
$_crRole = $_SESSION['user']['role'] ?? '';
if (!in_array($_crRole, ['maintenance_admin', 'maintenance_staff'], true)) {
    header('Location: ' . public_url('/reports'));
    exit;
}

require_once __DIR__ . '/../../backend/config/database.php';

// Establish database connection
$pdo = getDBConnection();

// TASK 19 — Target Maintenance Department: the reporter's own department and
// the department responsible for fixing the issue are not the same thing, so
// the reporter must explicitly pick who the report is for. Reuses the exact
// active-departments query already used by reports.php's filter dropdown and
// maintenance-create-report.php's Department field (no new API/query).
$deptStmt = $pdo->query("SELECT department_id, name FROM departments WHERE status = 'active' ORDER BY name");
$departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Problem Type — read from the SAME config file that ReportController's
 * validation rule reads, so the categories this page offers and the ones the
 * backend accepts cannot drift apart.
 *
 * `require`d directly rather than fetched from an API: this page is plain PHP
 * served by Apache and never boots the Laravel container, so config() is not
 * available here. A plain `return [...]` array file works in both worlds —
 * which is why config/maintenance_reports.php deliberately contains no env()
 * calls. Server-rendering the grid also means the cards exist in the initial
 * HTML: there is no round-trip that can fail and leave the required field
 * unfillable.
 */
$problemTypeConfig = require __DIR__ . '/../../../config/maintenance_reports.php';
$problemTypes = $problemTypeConfig['problem_types'] ?? [];
$problemTypeOtherValue = $problemTypeConfig['problem_type_other_value'] ?? 'Other';
$problemTypeOtherMax = (int) ($problemTypeConfig['problem_type_other_max'] ?? 100);

$user = $_SESSION['user'];
$pageTitle = 'Report a Problem - SFMS';
include __DIR__ . '/../includes/header.php';
?>
<main class="container create-report-page" style="margin-top: 20px;">
    <div class="card create-report-card">
        <div class="card-header">
            <h2>Report a Problem</h2>
            <p class="text-muted mb-0">Submit a facility concern for maintenance assistance.</p>
        </div>
        
        <div class="card-body">
            <div id="alert-container"></div>
            
            <form id="report-form">
                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">1</span>
                        <div>
                            <h3 class="form-section-title">Report Information</h3>
                            <p class="form-section-subtitle">What's wrong and where is it?</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <!--
                            Problem Type — the category of maintenance concern,
                            so the nature of an issue is structured data rather
                            than something a reader has to infer from the title
                            and description.

                            PLACED FIRST, INSIDE THE EXISTING SECTION 1, rather
                            than as a new numbered section. The requested field
                            order (Problem Type -> Title -> Location ->
                            Priority) is satisfied either way, but adding a
                            section would renumber all four existing ones for a
                            field that is plainly "Report Information" — and
                            this section's own subtitle, "What's wrong and where
                            is it?", already describes it exactly. No existing
                            field is moved, renamed or removed.

                            Radios, not divs-with-handlers: arrow-key
                            navigation, Space to select, and one accessible
                            name per option all come free, and the value
                            submits from a real form control. The visible
                            grouping is a <fieldset>/<legend> so assistive tech
                            announces "What kind of problem?" before the
                            options instead of reading eight unrelated radios.

                            No `required` attribute on the radios. It would be
                            correct in principle, but the inputs are clipped
                            for the card design, and Chrome cannot scroll a
                            non-rendered control into view — it aborts with
                            "An invalid form control with name='problem_type'
                            is not focusable" in the console and silently
                            refuses to submit. The check below is the
                            client-side enforcement instead, and
                            ReportController's `required` rule is the real one.
                        -->
                        <fieldset class="form-group problem-type-fieldset">
                            <legend class="problem-type-legend">What kind of problem? *</legend>
                            <div class="problem-type-grid" id="problem-type-grid">
                                <?php foreach ($problemTypes as $problemType): ?>
                                    <?php
                                        // One id per option so each label's
                                        // `for` points at its own input.
                                        // Non-alphanumerics are stripped
                                        // because values like "HVAC / Aircon"
                                        // contain spaces and a slash.
                                        $ptValue = (string) ($problemType['value'] ?? '');
                                        $ptSlug  = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $ptValue));
                                        $ptId    = 'problem-type-' . trim($ptSlug, '-');
                                    ?>
                                    <input
                                        type="radio"
                                        class="problem-type-input"
                                        name="problem_type"
                                        id="<?php echo htmlspecialchars($ptId, ENT_QUOTES); ?>"
                                        value="<?php echo htmlspecialchars($ptValue, ENT_QUOTES); ?>">
                                    <label class="problem-type-card" for="<?php echo htmlspecialchars($ptId, ENT_QUOTES); ?>">
                                        <?php echo ui_icon((string) ($problemType['icon'] ?? ''), ['size' => 22]); ?>
                                        <span class="problem-type-card-label"><?php echo htmlspecialchars($ptValue); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Mirrors ReportController's own
                                 'problem_type.required' message verbatim, so a
                                 user sees the same sentence whether the local
                                 check or the server caught it. -->
                            <span class="problem-type-error" id="problem-type-error" hidden>Please select a problem type.</span>

                            <!-- Revealed only for the free-text category.
                                 `hidden` (not a CSS class) so the input leaves
                                 the tab order and the accessibility tree while
                                 it does not apply. -->
                            <div class="problem-type-other-group" id="problem-type-other-group" hidden>
                                <label for="problem-type-other-value">Please specify the problem type *</label>
                                <input
                                    type="text"
                                    <?php /* NOT "problem-type-other": the radio for the
                                             "Other" category already slugs to that id, and a
                                             duplicate id makes getElementById() return the
                                             radio, so the custom text would never be read. */ ?>
                                    id="problem-type-other-value"
                                    name="problem_type_other"
                                    class="form-control"
                                    maxlength="<?php echo $problemTypeOtherMax; ?>"
                                    placeholder="e.g., Pest control">
                                <span class="problem-type-error" id="problem-type-other-error" hidden>Please specify the problem type.</span>
                            </div>
                        </fieldset>

                        <div class="form-group">
                            <label for="title">Report Title *</label>
                            <input
                                type="text"
                                id="title"
                                name="title"
                                placeholder="e.g., Broken Air Conditioning Unit"
                                required>
                        </div>

                        <!--
                            TASK 38 — Location was a free-text input
                            ("e.g., Building A - Room 101"), so a report could
                            be filed against a room that does not exist. It is
                            now a Building -> Floor -> Room chain fed by the
                            existing Buildings Overview endpoints
                            (/api/buildings, /api/buildings/{id}/floors,
                            /api/rooms), so only rooms that are actually
                            registered can be chosen. The backend re-verifies
                            the same triple against the same tables — this
                            markup is convenience, not the enforcement.
                        -->
                        <div class="form-group">
                            <label for="location-building">Location *</label>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <select id="location-building" name="location_building_id" required>
                                        <option value="">Select building...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <select id="location-floor" name="location_floor_id" required disabled>
                                        <option value="">Select building first...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <select id="location-room" name="location_room_id" required disabled>
                                        <option value="">Select floor first...</option>
                                    </select>
                                </div>
                            </div>
                            <small class="text-muted d-block" id="location-hint">Pick the exact room from Buildings Overview. If a room is missing here, ask an administrator to register it first.</small>
                        </div>

                        <!--
                            2026-10-08 — Asset Code (Optional), by explicit user
                            decision. NOT a revival of the removed "Report
                            Against a Specific Asset" sub-form (see the comment
                            further below where that sub-form used to be): this
                            is a single optional lookup, offered for the case
                            where the reporter is re-reporting a specific
                            already-registered unit. Selecting one only sets
                            deployed_asset_id as plain metadata on this report
                            — it does not create or link a damage_reports row,
                            and does not change anything else about how the
                            report is created. Validated server-side against
                            the deployed_assets registry (exists:deployed_assets,id)
                            — a code typed here that is not actually registered
                            is rejected at submit, same as any other field.
                        -->
                        <div class="form-group asset-code-group">
                            <div class="asset-code-panel">
                                <label for="asset-code-search" class="asset-code-item-label">Asset Code <span class="form-section-optional">(Optional)</span></label>
                                <input type="text" id="asset-code-search" class="form-control asset-code-search" placeholder="Type asset code, equipment, or room to search..." autocomplete="off">
                                <input type="hidden" id="asset-code-id" value="">
                                <div id="asset-code-selected" class="asset-code-selected" style="display:none;"></div>
                                <div id="asset-code-results" class="asset-code-results" style="display:none;"></div>
                                <small class="text-muted d-block asset-code-note">Only fill this in if you are re-reporting a specific registered unit (e.g. SFMS-2026-000123). Leave blank if unsure.</small>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="priority">Priority *</label>
                                <select id="priority" name="priority" required>
                                    <option value="low">Low - Can wait</option>
                                    <option value="medium" selected>Medium - Normal</option>
                                    <option value="high">High - Important</option>
                                    <option value="critical">Critical - Immediate</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="department">Target Maintenance Department *</label>
                                <select id="department" name="department_id" required>
                                    <option value="">Select department...</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['department_id']; ?>">
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block">Which department is responsible for fixing this issue.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">2</span>
                        <div>
                            <h3 class="form-section-title">Description</h3>
                            <p class="form-section-subtitle">Give maintenance staff the details they need.</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group">
                            <label for="description">Description *</label>
                            <textarea
                                id="description"
                                name="description"
                                placeholder="Please provide detailed description of the issue..."
                                required></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">3</span>
                        <div>
                            <h3 class="form-section-title">Replacement Item <span class="form-section-optional">(Optional)</span></h3>
                            <p class="form-section-subtitle">Only needed if the issue requires an inventory replacement.</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group need-change-group">
                            <div class="need-change-panel">
                                <label for="need-change-toggle" class="need-change-toggle-label">
                                    <input type="checkbox" id="need-change-toggle">
                                    <span class="need-change-toggle-text">
                                        <strong>Needs Replacement Item</strong>
                                        <small>Enable this only if the issue requires inventory replacement.</small>
                                    </span>
                                </label>
                                <div id="need-change-wrap" class="need-change-wrap" style="display:none;">
                                    <label for="need-change-search" class="need-change-item-label">Search Replacement Item</label>
                                    <input type="text" id="need-change-search" class="form-control need-change-search" placeholder="Type item name to search..." autocomplete="off">
                                    <input type="hidden" id="need-change-item" value="">
                                    <div id="need-change-selected" class="need-change-selected" style="display:none;"></div>
                                    <div id="need-change-results" class="need-change-results" style="display:none;"></div>
                                    <small class="text-muted d-block need-change-note">Stock will only be deducted after Administrator approval.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php /* 2026-10-08 — the "Report Against a Specific Asset"
                         sub-form (asset-toggle / asset-search / asset-severity /
                         asset-image / asset-repair-notes) was removed here, by
                         explicit user decision: Damage Reports must come from
                         exactly ONE trigger — a Need Change request (the
                         section above) — not from picking an asset on Create
                         Report. ReportController::store() no longer has a fork
                         for item_id/room_id/deployed_asset_id/severity_level/
                         damage_image/repair_notes, so these fields have no
                         backend to receive them any more.

                         Nothing it pointed at was deleted: the Deployed Asset
                         registry, DamageReportService::createReport() and the
                         standalone POST /api/damage-reports endpoint are all
                         still there, just no longer reachable from this
                         page. */ ?>

                <div class="create-report-actions d-flex gap-sm">
                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        Submit Report
                    </button>
                    <a href="<?php echo public_url('/reports'); ?>" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    </div>
</main>

<link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/create-report.inline.css?v=20261008-1')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/enterprise-reports.css?v=20260726-1')); ?>">
<!-- Problem Type card selector. Its own stylesheet rather than an addition to
     create-report.inline.css, because the identical grid is also rendered by
     the Edit Report modal in reports.php — one file, two consumers. -->
<link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/problem-type-selector.css?v=20260920-2')); ?>">

<script>
/* ===================================================================
   Problem Type selector behaviour.

   Four small helpers plus one change listener. They are declared here,
   ahead of the submit handler further down, because that handler reads
   them while building its payload.

   The "Other" value is injected from the same config file the markup
   above was rendered from, so the string is never spelled out twice in
   this page — if the config ever renames that category, this comparison
   follows it automatically.
   =================================================================== */
const PROBLEM_TYPE_OTHER = <?php echo json_encode($problemTypeOtherValue); ?>;

function problemTypeInputs() {
    return Array.from(document.querySelectorAll('.problem-type-input[name="problem_type"]'));
}

// The selected category, or null when nothing has been chosen yet. Null is
// deliberate rather than '': JSON.stringify() drops null keys, so an
// unselected value never reaches the API as an empty string that the
// backend would have to special-case.
function selectedProblemType() {
    const checked = problemTypeInputs().find((input) => input.checked);
    return checked ? checked.value : null;
}

// The free-text value, but ONLY while "Other" is the selection. This is the
// client-side half of ReportService::resolveProblemTypeOther() — if the user
// types a custom value, then changes their mind and picks Electrical, the
// stale text must not be sent. The server discards it too, so this is
// defence in depth rather than the only guard.
function problemTypeOtherValue() {
    if (selectedProblemType() !== PROBLEM_TYPE_OTHER) return null;
    const value = document.getElementById('problem-type-other-value')?.value.trim() || '';
    return value === '' ? null : value;
}

// Which of the two messages applies right now, or '' when the field is
// valid. Kept separate from validateProblemType() so the submit handler can
// reuse the exact sentence in its alert banner without duplicating the
// branching.
function problemTypeErrorText() {
    if (!selectedProblemType()) return 'Please select a problem type.';
    if (selectedProblemType() === PROBLEM_TYPE_OTHER && !problemTypeOtherValue()) {
        return 'Please specify the problem type.';
    }
    return '';
}

// Shows/hides the inline messages and returns whether the field passes.
// Both <span>s are toggled every call (not just the failing one) so a
// second submit after a fix clears the previous message.
function validateProblemType() {
    const message = problemTypeErrorText();
    const grid = document.getElementById('problem-type-grid');
    const selectError = document.getElementById('problem-type-error');
    const otherError = document.getElementById('problem-type-other-error');

    const missingSelection = !selectedProblemType();
    const missingOther = !missingSelection && message !== '';

    if (selectError) selectError.hidden = !missingSelection;
    if (otherError) otherError.hidden = !missingOther;
    grid?.classList.toggle('is-invalid', missingSelection);

    return message === '';
}

document.addEventListener('DOMContentLoaded', () => {
    const otherGroup = document.getElementById('problem-type-other-group');
    const otherInput = document.getElementById('problem-type-other-value');

    // One listener on the grid rather than eight on the inputs — the radios
    // are replaced by nothing dynamically, but a single delegated handler is
    // still less to keep in sync.
    document.getElementById('problem-type-grid')?.addEventListener('change', () => {
        const isOther = selectedProblemType() === PROBLEM_TYPE_OTHER;
        if (otherGroup) otherGroup.hidden = !isOther;

        // Clear the custom value when leaving "Other" so a hidden field can
        // never hold text the user can no longer see or edit.
        if (!isOther && otherInput) otherInput.value = '';

        // Re-run validation only to CLEAR a stale message. It cannot newly
        // fail here in a way the user has not caused, and showing a red
        // message the moment someone picks "Other" (before they have had a
        // chance to type) would be hostile.
        const selectError = document.getElementById('problem-type-error');
        if (selectError) selectError.hidden = true;
        document.getElementById('problem-type-grid')?.classList.remove('is-invalid');
        if (isOther) otherInput?.focus();
    });

    // Typing a value clears its own message immediately rather than waiting
    // for the next submit attempt.
    otherInput?.addEventListener('input', () => {
        const otherError = document.getElementById('problem-type-other-error');
        if (otherError && otherInput.value.trim() !== '') otherError.hidden = true;
    });
});
</script>

<script>
// Ensure API and Session are defined globally
window.API = window.API || {
    async createReport(data) {
        // 2026-10-08 — this used to branch on data.damage_image (a File
        // object can't travel inside a JSON body) and multipart-encode the
        // request via buildReportFormData() when an image was attached.
        // That was TASK 33 PHASE 7's upload for the asset-picker sub-form's
        // #asset-image field. The sub-form is gone and /api/reports no
        // longer accepts an image at all, so every submission is plain JSON
        // now.
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/reports'), {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const raw = await response.text();
        let result;

        try {
            result = JSON.parse(raw);
        } catch (parseError) {
            const preview = raw.slice(0, 180).replace(/\s+/g, ' ').trim();
            throw new Error(`Server returned invalid response. ${preview || 'No response body received.'}`);
        }

        if (!result.success) throw new Error(result.message || 'Failed to create report');
        return result;
    },
    // 2026-10-08 — General Report Duplicate Detection (user decision). See
    // ReportService::findPotentialDuplicate()'s doc comment for the match
    // rule. Informational only: the caller decides whether to still submit.
    async checkReportDuplicate(data) {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/reports/check-duplicate'), {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const raw = await response.text();
        let result;

        try {
            result = JSON.parse(raw);
        } catch (parseError) {
            // Fails open: a broken duplicate check must never block
            // submission, so the caller treats a parse failure the same as
            // "no duplicate found" rather than surfacing an error here.
            return { success: false, data: { has_duplicate: false } };
        }

        return result;
    },
    async logout() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/auth/logout'), {
            method: 'POST',
            credentials: 'include'
        });
        const data = await response.json();
        return data;
    }
};

// 2026-10-08 — buildReportFormData() used to live here: a FormData builder
// for the asset-linked sub-form's multipart image upload. The sub-form (and
// createReport()'s multipart branch that called this) are both gone, so
// every /api/reports submission is plain JSON.stringify() now and this
// helper has no remaining caller.

let needChangeItemsCache = [];
let selectedNeedChangeItem = null;

window.Session = window.Session || {
    get(key) { 
        const v = localStorage.getItem(key);
        return v ? JSON.parse(v) : null;
    },
    set(key, value) { localStorage.setItem(key, JSON.stringify(value)); },
    clear() { localStorage.clear(); }
};

document.getElementById('report-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submit-btn');
    const alertContainer = document.getElementById('alert-container');
    
    // Get form data
    const formData = {
        // Problem Type. `problem_type_other` is only ever sent alongside the
        // "Other" category — problemTypeOtherValue() returns null otherwise, and
        // JSON.stringify() drops null keys, so a report in any other category
        // posts exactly the payload it always did.
        problem_type: selectedProblemType(),
        problem_type_other: problemTypeOtherValue(),
        title: document.getElementById('title').value.trim(),
        // TASK 38 — the human-readable location string is no longer sent from
        // here at all. ReportController::store() derives it from the three IDs
        // below by reading buildings/floors/rooms, so what gets stored can
        // only ever be a real, registered location.
        location_building_id: document.getElementById('location-building').value || null,
        location_floor_id: document.getElementById('location-floor').value || null,
        location_room_id: document.getElementById('location-room').value || null,
        priority: document.getElementById('priority').value,
        description: document.getElementById('description').value.trim(),
        department_id: document.getElementById('department').value || null,
        need_change_item_id: null,
        // 2026-10-08 — item_id/room_id/severity_level/damage_image/
        // repair_notes/override_duplicate were removed from here along with
        // the "Report Against a Specific Asset" sub-form. ReportController::
        // store() no longer accepts any of them.
        //
        // deployed_asset_id is NOT part of that removed sub-form — it is the
        // new, separate, optional Asset Code lookup above. null unless the
        // reporter actually picked a registered unit.
        deployed_asset_id: document.getElementById('asset-code-id')?.value
            ? Number(document.getElementById('asset-code-id').value)
            : null
    };

    const needChangeToggle = document.getElementById('need-change-toggle');
    const needChangeWrap = document.getElementById('need-change-wrap');
    const needChangeSelect = document.getElementById('need-change-item');

    if (needChangeToggle?.checked) {
        if (!needChangeSelect?.value) {
            alertContainer.innerHTML = '<div class="alert alert-danger">Please select a replacement inventory item.</div>';
            return;
        }

        formData.need_change_item_id = Number(needChangeSelect.value);
    }

    // 2026-10-08 — the "Link to a Deployed Asset" toggle block that used to
    // sit here (reading #asset-item-id/#asset-room-id/#asset-deployed-id/
    // #asset-severity/#asset-image/#asset-repair-notes) was removed along
    // with the sub-form itself.

    // Problem Type is checked before the generic "fill in all required fields"
    // block below so the user gets the specific sentence the brief asks for
    // rather than a catch-all. The inline <span>s are shown next to the control
    // itself; the alert banner repeats it for anyone who submitted from the
    // bottom of a long form and cannot see the fieldset.
    if (!validateProblemType()) {
        alertContainer.innerHTML = '<div class="alert alert-danger">' + problemTypeErrorText() + '</div>';
        document.getElementById('problem-type-grid')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    // Validate
    if (!formData.title || !formData.description || !formData.department_id) {
        alertContainer.innerHTML = '<div class="alert alert-danger">Please fill in all required fields</div>';
        return;
    }

    // TASK 38 — a partial Building/Floor/Room selection is rejected here with
    // a specific message rather than being sent and bounced by the backend's
    // equivalent check, which would surface as a generic validation error.
    if (!formData.location_building_id || !formData.location_floor_id || !formData.location_room_id) {
        alertContainer.innerHTML = '<div class="alert alert-danger">Please select the Building, Floor, and Room where the issue is located.</div>';
        return;
    }

    // 2026-10-08 — the OLD pre-submit duplicate check that used to run here
    // (checkAssetDuplicate(), against formData.deployed_asset_id/item_id/
    // room_id) was removed along with the asset-picker sub-form, since those
    // fields no longer exist on formData.
    //
    // General Report Duplicate Detection (user decision) replaces it: same
    // department, same calendar day, same title, same derived location, same
    // Problem Type — see ReportService::findPotentialDuplicate()'s doc
    // comment for the full rule. This is a WARNING, not a gate: the reporter
    // can always choose "Submit Anyway", because two different people really
    // can independently report the same broken thing on the same day. A
    // failed/slow check never blocks submission either (checkReportDuplicate()
    // fails open), so this can only make submission slower, never impossible.
    //
    // 2026-10-08 — Asset Code signal (user decision). deployed_asset_id is
    // sent too, so the backend's asset-linked branch (checked first, any
    // wording, same unit) can catch a duplicate re-report of the SAME
    // registered unit even when the title/location happen to differ.
    try {
        const dupCheck = await window.API.checkReportDuplicate({
            title: formData.title,
            problem_type: formData.problem_type,
            problem_type_other: formData.problem_type_other,
            location_building_id: formData.location_building_id,
            location_floor_id: formData.location_floor_id,
            location_room_id: formData.location_room_id,
            department_id: formData.department_id,
            deployed_asset_id: formData.deployed_asset_id
        });

        if (dupCheck.success && dupCheck.data && dupCheck.data.has_duplicate) {
            const dup = dupCheck.data.duplicate || {};
            const who = dup.reported_by ? ` by ${UI.escapeHtml(dup.reported_by)}` : '';
            const proceed = await UI.systemConfirm(
                `A report with the same title, location, and problem type was already submitted today${who} (Report #${dup.report_id ?? ''}). Submit this one anyway?`,
                'Submit Anyway',
                'Cancel',
                'warning'
            );
            if (!proceed) return;
        }
    } catch (dupError) {
        // Fails open — see the comment above checkReportDuplicate().
        console.error('Duplicate check error:', dupError);
    }

    // Show loading
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Submitting...';
    submitBtn.disabled = true;
    alertContainer.innerHTML = '';

    try {
        const response = await window.API.createReport(formData);
        
            if (response.success) {
            alertContainer.innerHTML = '<div class="alert alert-success">Report submitted successfully! Na-notify na via email ang Administrator. Redirecting...</div>';
            
            // Redirect after 1 second, include new report ID so we can highlight it on the list
            const newId = response.data && response.data.report_id ? response.data.report_id : '';
            setTimeout(() => {
                let url = '<?php echo public_url('/reports'); ?>';
                if (newId) url += '?new_id=' + encodeURIComponent(newId);
                window.location.href = url;
            }, 1000);
        } else {
            throw new Error(response.message || 'Failed to create report');
        }
    } catch (error) {
        console.error('Submit error:', error);
        alertContainer.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

/**
 * TASK 38 — Create Report location picker.
 *
 * Reads the same three Buildings Overview endpoints the rest of the app
 * already uses (/api/buildings, /api/buildings/{id}/floors, /api/rooms). No
 * new endpoint, no client-side copy of the building/room list, and nothing
 * here can create a room — an unregistered room simply never appears as an
 * option. The backend performs the identical check against the identical
 * tables, so this is purely a convenience layer: disabling or editing these
 * selects in devtools does not get an invalid location past
 * ReportController::store().
 *
 * Floor is a genuine step, not decoration: rooms.building_id and
 * rooms.floor_id are independent columns, so "Room 102" can exist on more
 * than one floor of the same building and the floor is what disambiguates it.
 */
const locationBuildingSelect = document.getElementById('location-building');
const locationFloorSelect = document.getElementById('location-floor');
const locationRoomSelect = document.getElementById('location-room');

function setLocationOptions(select, placeholder, rows, enabled) {
    if (!select) return;
    select.innerHTML = '';
    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.textContent = placeholder;
    select.appendChild(placeholderOption);

    (rows || []).forEach((row) => {
        const option = document.createElement('option');
        option.value = row.id;
        // textContent, not innerHTML — building/floor/room names are
        // user-entered in Buildings Overview and must never be parsed as markup.
        option.textContent = row.name;
        select.appendChild(option);
    });

    select.disabled = !enabled;
    select.value = '';
}

async function fetchLocationRows(path, key) {
    const response = await fetch(window.SFMS_PUBLIC_URL(path), {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
    });
    const result = await response.json();
    if (!result.success) throw new Error(result.message || 'Failed to load location data');
    return (result.data && result.data[key]) || [];
}

function reportLocationError(message) {
    const alertContainer = document.getElementById('alert-container');
    if (alertContainer) {
        alertContainer.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
}

async function initLocationPicker() {
    if (!locationBuildingSelect || !locationFloorSelect || !locationRoomSelect) return;

    try {
        const buildings = await fetchLocationRows('/api/buildings?per_page=200', 'buildings');
        setLocationOptions(locationBuildingSelect, 'Select building...', buildings, true);
    } catch (error) {
        console.error('Failed to load buildings:', error);
        reportLocationError('Could not load the building list. Please refresh the page and try again.');
    }

    locationBuildingSelect.addEventListener('change', async () => {
        setLocationOptions(locationFloorSelect, 'Select building first...', [], false);
        setLocationOptions(locationRoomSelect, 'Select floor first...', [], false);

        const buildingId = locationBuildingSelect.value;
        if (!buildingId) return;

        try {
            const floors = await fetchLocationRows(
                '/api/buildings/' + encodeURIComponent(buildingId) + '/floors',
                'floors'
            );
            setLocationOptions(
                locationFloorSelect,
                floors.length ? 'Select floor...' : 'No floors registered',
                floors,
                floors.length > 0
            );
        } catch (error) {
            console.error('Failed to load floors:', error);
            reportLocationError('Could not load the floors for that building. Please try again.');
        }
    });

    locationFloorSelect.addEventListener('change', async () => {
        setLocationOptions(locationRoomSelect, 'Select floor first...', [], false);

        const buildingId = locationBuildingSelect.value;
        const floorId = locationFloorSelect.value;
        if (!buildingId || !floorId) return;

        try {
            // Both filters are sent so the list matches exactly what the
            // backend will accept: a room is only valid when it is registered
            // under this building AND on this floor.
            const rooms = await fetchLocationRows(
                '/api/rooms?per_page=200&building_id=' + encodeURIComponent(buildingId)
                    + '&floor_id=' + encodeURIComponent(floorId),
                'rooms'
            );
            setLocationOptions(
                locationRoomSelect,
                rooms.length ? 'Select room...' : 'No rooms registered on this floor',
                rooms,
                rooms.length > 0
            );
        } catch (error) {
            console.error('Failed to load rooms:', error);
            reportLocationError('Could not load the rooms for that floor. Please try again.');
        }
    });
}
initLocationPicker();

// 2026-10-08 — this is where the asset-picker sub-form's JS used to live:
// assetSelectInitialized/selectedAsset state, initAssetSelects()/
// applyAssetSelection()/resetAssetSelection() (wired to a SearchableSelect
// over GET /api/deployed-assets), the #asset-toggle show/hide handler,
// setupAssetImagePreview() (TASK 33 PHASE 7's 5MB guard + FileReader
// preview for #asset-image), and checkAssetDuplicate()/
// showDuplicateWarningModal() (TASK 37.3/TASK 44's pre-submit duplicate
// check and warning modal). All of it was removed along with the "Report
// Against a Specific Asset" sub-form, by explicit user decision: Damage
// Reports must come from exactly ONE trigger — a Need Change request — not
// from picking an asset on Create Report.
//
// Nothing it called was deleted: GET /api/deployed-assets, POST
// /api/damage-reports/check-duplicate and the Deployed Asset registry are
// all still there and still used elsewhere (e.g. the standalone Damage
// Report creation surface) — this page just no longer calls them.

async function loadNeedChangeItems() {
    const hiddenInput = document.getElementById('need-change-item');
    const results = document.getElementById('need-change-results');
    if (!hiddenInput || !results) return;

    try {
        // TASK 42 — the Replacement Item picker must offer warehouse supply only.
        // Without item_type=inventory_stock this listed room_asset rows too, so
        // equipment already installed in a room appeared as available stock to
        // hand out. Same filter replacement-request.php and
        // damage-report-update.php have always used for this field.
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/items') + '?per_page=200&item_type=inventory_stock', {
            credentials: 'include'
        });
        const result = await response.json();

        // Supports both paginator shape (result.data.data) and legacy shape (result.items / result.data.items)
        const rawItems = result?.data?.data ?? result?.data?.items ?? result?.items ?? [];
        if (!result.success || !Array.isArray(rawItems)) {
            throw new Error(result.message || 'Failed to load inventory items');
        }

        needChangeItemsCache = rawItems.filter((item) => Number(item.quantity || 0) > 0);
        renderNeedChangeOptions();
    } catch (error) {
        results.innerHTML = '<div class="need-change-empty">Unable to load items</div>';
        console.error('Failed to load need change items:', error);
    }
}

function renderNeedChangeOptions() {
    const searchInput = document.getElementById('need-change-search');
    const results = document.getElementById('need-change-results');
    if (!results) return;

    const keyword = String(searchInput?.value || '').trim().toLowerCase();

    // Hide dropdown if nothing typed
    if (!keyword) {
        results.style.display = 'none';
        results.innerHTML = '';
        return;
    }

    const filteredItems = needChangeItemsCache.filter((item) =>
        String(item.name || '').toLowerCase().includes(keyword)
    );

    if (!filteredItems.length) {
        results.innerHTML = '<div class="need-change-empty">No matching inventory items</div>';
        results.style.display = 'block';
        return;
    }

    results.innerHTML = filteredItems.map((item) => {
        const isActive = selectedNeedChangeItem && String(selectedNeedChangeItem.id) === String(item.id);
        return `<button type="button" class="need-change-result-item${isActive ? ' active' : ''}" data-item-id="${item.id}">${item.name} <span>(Stock: ${item.quantity})</span></button>`;
    }).join('');
    results.style.display = 'block';
}

function updateNeedChangeSelection(item) {
    const hiddenInput = document.getElementById('need-change-item');
    const selectedDisplay = document.getElementById('need-change-selected');
    const searchInput = document.getElementById('need-change-search');
    if (!hiddenInput || !selectedDisplay) return;

    selectedNeedChangeItem = item || null;
    hiddenInput.value = item ? String(item.id) : '';

    if (item) {
        selectedDisplay.style.display = 'block';
        selectedDisplay.textContent = `Selected: ${item.name} (Stock: ${item.quantity})`;
        if (searchInput) {
            searchInput.value = item.name || '';
        }
        // Hide dropdown after selection
        const results = document.getElementById('need-change-results');
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    } else {
        selectedDisplay.style.display = 'none';
        selectedDisplay.textContent = '';
    }

    renderNeedChangeOptions();
}

document.getElementById('need-change-toggle')?.addEventListener('change', (event) => {
    const wrap = document.getElementById('need-change-wrap');
    if (wrap) {
        wrap.style.display = event.target.checked ? 'block' : 'none';
        if (event.target.checked) loadNeedChangeItems();
    }
});

document.getElementById('need-change-search')?.addEventListener('input', renderNeedChangeOptions);

document.getElementById('need-change-results')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-item-id]');
    if (!button) return;

    const itemId = String(button.getAttribute('data-item-id') || '');
    const matchedItem = needChangeItemsCache.find((item) => String(item.id) === itemId);
    if (!matchedItem) return;

    updateNeedChangeSelection(matchedItem);
});

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.need-change-wrap')) {
        const results = document.getElementById('need-change-results');
        if (results) results.style.display = 'none';
    }
    if (!e.target.closest('.asset-code-panel')) {
        const assetResults = document.getElementById('asset-code-results');
        if (assetResults) assetResults.style.display = 'none';
    }
});

/**
 * 2026-10-08 — Asset Code (Optional) search, by explicit user decision.
 *
 * Mirrors the Replacement Item search pattern above (search box -> results
 * dropdown -> selected display -> hidden input), but queries the server
 * (GET /api/deployed-assets, already role-gated to maintenance_admin /
 * maintenance_staff — the only two roles that can reach this page) instead
 * of filtering a client-side cache, since the registry can be large.
 * Debounced so every keystroke does not fire a request. include_legacy is
 * deliberately NOT passed — see memory on why items.asset_code is not used
 * for per-unit identity; only real deployed_assets rows (which always carry
 * a deployed_asset_id) are offered here.
 */
let assetCodeSearchTimer = null;
let selectedAssetCode = null;
let assetCodeRowsCache = [];

async function searchAssetCodes(keyword) {
    const results = document.getElementById('asset-code-results');
    if (!results) return;

    if (!keyword) {
        results.style.display = 'none';
        results.innerHTML = '';
        return;
    }

    try {
        const response = await fetch(
            window.SFMS_PUBLIC_URL('/api/deployed-assets') + '?status=active&per_page=20&q=' + encodeURIComponent(keyword),
            { credentials: 'include', headers: { 'Accept': 'application/json' } }
        );
        const result = await response.json();
        const rows = result?.data?.items ?? [];

        if (!result.success || !Array.isArray(rows)) {
            throw new Error(result.message || 'Failed to load asset codes');
        }

        assetCodeRowsCache = rows;
        renderAssetCodeOptions(rows);
    } catch (error) {
        results.innerHTML = '<div class="need-change-empty">Unable to load asset codes</div>';
        results.style.display = 'block';
        console.error('Failed to search asset codes:', error);
    }
}

function renderAssetCodeOptions(rows) {
    const results = document.getElementById('asset-code-results');
    if (!results) return;

    if (!rows.length) {
        results.innerHTML = '<div class="need-change-empty">No matching registered asset</div>';
        results.style.display = 'block';
        return;
    }

    results.innerHTML = rows.map((row) => {
        const isActive = selectedAssetCode && String(selectedAssetCode.deployed_asset_id) === String(row.deployed_asset_id);
        return `<button type="button" class="need-change-result-item${isActive ? ' active' : ''}" data-asset-id="${row.deployed_asset_id}">${UI.escapeHtml(row.asset_code || '')} <span>(${UI.escapeHtml(row.name || '')})</span></button>`;
    }).join('');
    results.style.display = 'block';
}

function updateAssetCodeSelection(row) {
    const hiddenInput = document.getElementById('asset-code-id');
    const selectedDisplay = document.getElementById('asset-code-selected');
    const searchInput = document.getElementById('asset-code-search');
    if (!hiddenInput || !selectedDisplay) return;

    selectedAssetCode = row || null;
    hiddenInput.value = row ? String(row.deployed_asset_id) : '';

    if (row) {
        selectedDisplay.style.display = 'block';
        selectedDisplay.textContent = `Selected: ${row.asset_code} (${row.name || ''})`;
        if (searchInput) searchInput.value = row.asset_code || '';
        const results = document.getElementById('asset-code-results');
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    } else {
        selectedDisplay.style.display = 'none';
        selectedDisplay.textContent = '';
    }
}

document.getElementById('asset-code-search')?.addEventListener('input', (event) => {
    // Typing after a selection means the reporter is changing their mind —
    // clear the stale hidden id so a half-edited search box cannot silently
    // keep submitting the OLD selected asset.
    if (selectedAssetCode && event.target.value !== selectedAssetCode.asset_code) {
        updateAssetCodeSelection(null);
    }

    const keyword = event.target.value.trim();
    clearTimeout(assetCodeSearchTimer);
    assetCodeSearchTimer = setTimeout(() => searchAssetCodes(keyword), 300);
});

document.getElementById('asset-code-results')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-asset-id]');
    if (!button) return;

    const assetId = String(button.getAttribute('data-asset-id') || '');
    const row = assetCodeRowsCache.find((r) => String(r.deployed_asset_id) === assetId);
    if (!row) return;

    updateAssetCodeSelection(row);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
