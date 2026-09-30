<?php
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
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

// RBAC POLICY UPDATE — Administrator (super_admin) reviews/assigns/monitors
// reports but does not submit them, so direct URL access to Create Report is
// blocked for that role. Head Maintenance (maintenance_admin) and
// Maintenance Staff are the report submitters.
$_crRole = $_SESSION['user']['role'] ?? '';
if (!in_array($_crRole, ['maintenance_admin', 'maintenance_staff'], true)) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/reports.php');
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

                <div class="form-section">
                    <div class="form-section-header">
                        <span class="form-section-index">4</span>
                        <div>
                            <h3 class="form-section-title">Report Against a Specific Asset <span class="form-section-optional">(Optional)</span></h3>
                            <p class="form-section-subtitle">Only needed if this report concerns a specific deployed item — enables duplicate-report detection.</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <div class="form-group need-change-group">
                            <div class="need-change-panel">
                                <label for="asset-toggle" class="need-change-toggle-label">
                                    <input type="checkbox" id="asset-toggle">
                                    <span class="need-change-toggle-text">
                                        <strong>Link to a Deployed Asset</strong>
                                        <small>Enable this if you're reporting damage on a specific tracked item.</small>
                                    </span>
                                </label>
                                <div id="asset-wrap" class="need-change-wrap" style="display:none;">
                                    <div class="asset-select-grid">
                                        <div class="form-group">
                                            <label for="asset-building-search" class="need-change-item-label">Building</label>
                                            <input type="text" id="asset-building-search" class="form-control need-change-search" placeholder="Search building..." autocomplete="off">
                                            <input type="hidden" id="asset-building-id" value="">
                                        </div>
                                        <div class="form-group">
                                            <label for="asset-room-search" class="need-change-item-label">Room</label>
                                            <input type="text" id="asset-room-search" class="form-control need-change-search" placeholder="Search room..." autocomplete="off" disabled>
                                            <input type="hidden" id="asset-room-id" value="">
                                        </div>
                                        <div class="form-group">
                                            <label for="asset-item-search" class="need-change-item-label">Equipment</label>
                                            <input type="text" id="asset-item-search" class="form-control need-change-search" placeholder="Search equipment..." autocomplete="off" disabled>
                                            <input type="hidden" id="asset-item-id" value="">
                                        </div>
                                    </div>
                                    <div id="asset-selected" class="need-change-selected" style="display:none;"></div>

                                    <!-- TASK 33 PHASE 7 — Severity Level + Image Upload, ported from
                                         damage-report-create.php's own asset-damage form so the unified
                                         Create Report workflow can carry the same capabilities. Same
                                         exact severity vocabulary (low/medium/high/critical) already used
                                         by DamageReport::severity_level and by this page's own Priority
                                         field. Only meaningful (and only sent) when "Link to a Deployed
                                         Asset" is enabled, since severity_level/damage_image are damage-
                                         report-specific fields consumed by
                                         ReportController::storeWithAssetDetails() ->
                                         DamageReportService::createReport(), not by the general report
                                         path. No new backend field, no new API endpoint, no new DB column.
                                    -->
                                    <div class="asset-select-grid" style="margin-top:12px;">
                                        <div class="form-group">
                                            <label for="asset-severity" class="need-change-item-label">Severity Level *</label>
                                            <select id="asset-severity" class="form-control">
                                                <option value="">Select severity</option>
                                                <option value="low">Low</option>
                                                <option value="medium">Medium</option>
                                                <option value="high">High</option>
                                                <option value="critical">Critical</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="asset-image" class="need-change-item-label">Image Upload</label>
                                            <input type="file" id="asset-image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                                            <small class="text-muted">Allowed: JPG, PNG, WEBP, GIF (max 5MB)</small>
                                        </div>
                                    </div>
                                    <div id="asset-image-preview" style="margin-top:10px;"></div>

                                    <!-- TASK 33 PHASE 9 — Repair Notes, ported verbatim from
                                         damage-report-create.php's own #damage-repair-notes field
                                         (same label, placeholder, optional behavior, and repair_notes
                                         API parameter name) so the unified Create Report workflow closes
                                         the last page-level capability gap identified in Phase 8's
                                         equivalence verification. Already accepted and persisted by
                                         ReportController::store()/storeWithAssetDetails() ->
                                         DamageReportService::createReport() — no backend change needed.
                                    -->
                                    <div class="form-group" style="margin-top:12px;">
                                        <label for="asset-repair-notes" class="need-change-item-label">Repair Notes (optional)</label>
                                        <textarea id="asset-repair-notes" class="form-control" rows="3" placeholder="Initial notes or findings..."></textarea>
                                    </div>

                                    <small class="text-muted d-block need-change-note">Picking an asset here checks for a matching report already open on that item before you submit.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="create-report-actions d-flex gap-sm">
                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        Submit Report
                    </button>
                    <a href="/School_Facility_Maintenance_System/frontend/pages/reports.php" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
    </div>
</main>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/create-report.inline.css?v=20260922-4">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/enterprise-reports.css?v=20260726-1">
<!-- Problem Type card selector. Its own stylesheet rather than an addition to
     create-report.inline.css, because the identical grid is also rendered by
     the Edit Report modal in reports.php — one file, two consumers. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/problem-type-selector.css?v=20260920-2">

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
// deliberate rather than '': buildReportFormData() and the JSON path both
// drop null keys, so an unselected value never reaches the API as an empty
// string that the backend would have to special-case.
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
        // TASK 33 PHASE 7 — damage_image (when present) is a File, which
        // cannot travel inside a JSON body. Every other report submission
        // (no asset linked, or asset linked without an image) is completely
        // unaffected and still POSTs the exact same JSON body as before.
        // ReportController::store() already validates/accepts both content
        // types via $request->validate(), so no backend change was needed.
        const hasImage = data.damage_image instanceof File;
        const requestInit = hasImage
            ? {
                method: 'POST',
                credentials: 'include',
                headers: { 'Accept': 'application/json' },
                body: buildReportFormData(data)
            }
            : {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            };

        const response = await fetch(window.SFMS_PUBLIC_URL('/api/reports'), requestInit);

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
    async logout() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/auth/logout'), {
            method: 'POST',
            credentials: 'include'
        });
        const data = await response.json();
        return data;
    }
};

// TASK 33 PHASE 7 — builds the multipart body for the one case that needs
// it (an asset-linked report with an image attached). Skips null/undefined/
// empty-string values so optional fields the general path already omits
// (need_change_item_id, item_id, room_id, severity_level, damage_image)
// aren't sent as literal "null" strings; File values append normally.
function buildReportFormData(data) {
    const fd = new FormData();
    Object.keys(data).forEach((key) => {
        const value = data[key];
        if (value === null || value === undefined || value === '') return;
        fd.append(key, value);
    });
    return fd;
}

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
        // buildReportFormData()/the JSON path both drop null keys, so a report
        // in any other category posts exactly the payload it always did.
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
        item_id: null,
        room_id: null,
        override_duplicate: false,
        // TASK 33 PHASE 7 — only populated when "Link to a Deployed Asset" is
        // checked (see assetToggle block below). Mirrors damage-report-create.php's
        // own field names/values exactly; both are consumed server-side by
        // the same storeWithAssetDetails() -> DamageReportService::createReport()
        // path that already existed for item_id/room_id.
        severity_level: null,
        damage_image: null,
        // TASK 33 PHASE 9 — only populated when "Link to a Deployed Asset" is
        // checked (see assetToggle block below), same optional behavior as
        // damage-report-create.php's #damage-repair-notes (only sent if
        // non-empty). Already accepted by the backend (repair_notes is
        // 'nullable' on ReportController::store() and is passed through to
        // DamageReportService::createReport() by storeWithAssetDetails()).
        repair_notes: null
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

    const assetToggle = document.getElementById('asset-toggle');
    const assetItemId = document.getElementById('asset-item-id');
    const assetRoomId = document.getElementById('asset-room-id');

    if (assetToggle?.checked) {
        if (!assetItemId?.value || !assetRoomId?.value) {
            alertContainer.innerHTML = '<div class="alert alert-danger">Please select a Building, Room, and Equipment to link this report to an asset.</div>';
            return;
        }

        formData.item_id = Number(assetItemId.value);
        formData.room_id = Number(assetRoomId.value);

        // TASK 33 PHASE 7 — Severity Level is required whenever an asset is
        // linked, same as it is on damage-report-create.php's form. Not an
        // HTML5 "required" attribute (the field is inside a hidden, opt-in
        // panel until the toggle is checked, same pattern already used for
        // item_id/room_id above), so it's enforced here instead.
        const assetSeverity = document.getElementById('asset-severity')?.value || '';
        if (!assetSeverity) {
            alertContainer.innerHTML = '<div class="alert alert-danger">Please select a Severity Level for the linked asset.</div>';
            return;
        }
        formData.severity_level = assetSeverity;

        // Image is optional, matching damage-report-create.php (no required
        // attribute there either).
        const assetImageFile = document.getElementById('asset-image')?.files?.[0] || null;
        if (assetImageFile) {
            formData.damage_image = assetImageFile;
        }

        // TASK 33 PHASE 9 — Repair Notes is optional, matching
        // damage-report-create.php's own submit handler (only appended when
        // non-empty after trim()). buildReportFormData() already skips
        // null/undefined/empty-string values, so leaving this null when
        // blank keeps the multipart path's payload identical to the legacy
        // page's, and the JSON path simply omits the key the same way every
        // other unset optional field already does.
        const assetRepairNotes = document.getElementById('asset-repair-notes')?.value.trim() || '';
        if (assetRepairNotes) {
            formData.repair_notes = assetRepairNotes;
        }
    }

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

    if (formData.item_id && formData.room_id) {
        const duplicateAction = await checkAssetDuplicate(formData);
        if (duplicateAction === 'cancel') return;
        if (duplicateAction === 'override') formData.override_duplicate = true;
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
                let url = '/School_Facility_Maintenance_System/frontend/pages/reports.php';
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

let assetSelectsInitialized = false;
let assetBuildingSelect = null;
let assetRoomSelect = null;
let assetItemSelect = null;

// TASK 44 — UX-only: captures the full API row (not just the id/name the
// hidden inputs already store) for the currently selected room and asset, so
// the duplicate-warning modal can show Building/Floor/Room/Equipment/Asset
// Code context without any new endpoint or backend field. These mirror
// exactly what the user just picked, so they are guaranteed to describe the
// same physical unit the duplicate check ran against.
let selectedAssetRoom = null;
let selectedAssetItem = null;

function initAssetSelects() {
    if (assetSelectsInitialized) return;
    assetSelectsInitialized = true;

    assetBuildingSelect = new Components.SearchableSelect({
        inputId: 'asset-building-search',
        hiddenId: 'asset-building-id',
        endpoint: '/api/buildings',
        displayKey: 'name',
        onSelect: () => {
            resetAssetSelection('room');
            const roomInput = document.getElementById('asset-room-search');
            if (roomInput) roomInput.disabled = false;
            const buildingId = document.getElementById('asset-building-id').value;
            assetRoomSelect.endpoint = '/api/rooms?building_id=' + encodeURIComponent(buildingId);
        }
    });

    assetRoomSelect = new Components.SearchableSelect({
        inputId: 'asset-room-search',
        hiddenId: 'asset-room-id',
        endpoint: '/api/rooms',
        displayKey: 'name',
        onSelect: (room) => {
            resetAssetSelection('item');
            selectedAssetRoom = room;
            const itemInput = document.getElementById('asset-item-search');
            if (itemInput) itemInput.disabled = false;
            const roomId = document.getElementById('asset-room-id').value;
            assetItemSelect.endpoint = '/api/items?item_type=room_asset&room_id=' + encodeURIComponent(roomId);
        }
    });

    assetItemSelect = new Components.SearchableSelect({
        inputId: 'asset-item-search',
        hiddenId: 'asset-item-id',
        endpoint: '/api/items?item_type=room_asset',
        displayKey: 'name',
        onSelect: (item) => {
            selectedAssetItem = item;
            const selectedDisplay = document.getElementById('asset-selected');
            if (!selectedDisplay) return;
            selectedDisplay.style.display = 'block';
            selectedDisplay.textContent = `Selected: ${item.name}${item.asset_code ? ' (Asset Code: ' + item.asset_code + ')' : ''}`;
        }
    });
}

function resetAssetSelection(from) {
    if (from === 'room' || from === 'item') {
        const roomSearch = document.getElementById('asset-room-search');
        const roomId = document.getElementById('asset-room-id');
        if (from === 'room') {
            if (roomSearch) { roomSearch.value = ''; roomSearch.disabled = true; }
            if (roomId) roomId.value = '';
            selectedAssetRoom = null;
        }
    }
    const itemSearch = document.getElementById('asset-item-search');
    const itemId = document.getElementById('asset-item-id');
    if (itemSearch) { itemSearch.value = ''; itemSearch.disabled = true; }
    if (itemId) itemId.value = '';
    selectedAssetItem = null;

    const selectedDisplay = document.getElementById('asset-selected');
    if (selectedDisplay) { selectedDisplay.style.display = 'none'; selectedDisplay.textContent = ''; }
}

document.getElementById('asset-toggle')?.addEventListener('change', (event) => {
    const wrap = document.getElementById('asset-wrap');
    if (wrap) {
        wrap.style.display = event.target.checked ? 'block' : 'none';
        if (event.target.checked) initAssetSelects();
    }
});

// TASK 33 PHASE 7 — same 5MB client-side guard and FileReader preview
// pattern as damage-report-create.php's setupImagePreview(), scoped to this
// page's own #asset-image field instead of duplicating a shared component.
function setupAssetImagePreview() {
    const input = document.getElementById('asset-image');
    const preview = document.getElementById('asset-image-preview');
    if (!input || !preview) return;

    input.addEventListener('change', () => {
        preview.innerHTML = '';
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) return;

        const maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) {
            const alertContainer = document.getElementById('alert-container');
            if (alertContainer) alertContainer.innerHTML = '<div class="alert alert-danger">Image must be 5MB or smaller.</div>';
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            preview.innerHTML = `<img src="${reader.result}" alt="Asset damage preview" style="max-width:280px;border:1px solid rgba(148,163,184,.3);border-radius:8px;">`;
        };
        reader.readAsDataURL(file);
    });
}
setupAssetImagePreview();

/**
 * Pre-submit duplicate check against TASK 37.3's check-duplicate endpoint.
 * Returns 'submit' (no duplicate, or user confirmed it's a different issue),
 * 'override' (same as 'submit' but flags override_duplicate=true so the
 * backend's own re-check at submit time doesn't re-block it), or 'cancel'.
 */
async function checkAssetDuplicate(formData) {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/damage-reports/check-duplicate'), {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                item_id: formData.item_id,
                room_id: formData.room_id,
                department_id: formData.department_id,
                damage_description: formData.description
            })
        });
        const result = await response.json();
        if (!result.success || !result.data?.has_duplicate) return 'submit';

        // TASK 44 — UX-only context so the warning modal can show which asset the
        // match is against (two identical units in the same room, e.g. AI-R101-01
        // vs AI-R101-02, must never look ambiguous). Built entirely from data the
        // user already selected on this page — no new API call, no new field.
        const buildingSearch = document.getElementById('asset-building-search');
        const roomSearch = document.getElementById('asset-room-search');
        const context = {
            buildingName: buildingSearch ? buildingSearch.value : '',
            floorName: selectedAssetRoom?.floor_name || '',
            roomName: selectedAssetRoom?.name || (roomSearch ? roomSearch.value : ''),
            equipmentName: selectedAssetItem?.name || '',
            assetCode: selectedAssetItem?.asset_code || ''
        };

        return await showDuplicateWarningModal(result.data.duplicate, context);
    } catch (error) {
        console.error('Duplicate check failed:', error);
        return 'submit';
    }
}

function showDuplicateWarningModal(duplicate, context) {
    return new Promise((resolve) => {
        const existing = document.getElementById('asset-duplicate-modal');
        if (existing) existing.remove();

        context = context || {};
        const viewUrl = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/damage-reports/' + duplicate.id) : '/damage-reports/' + duplicate.id;
        const code = duplicate.damage_report_code || ('#' + duplicate.id);
        const statusLabel = String(duplicate.status || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) || 'Unknown';
        const locationParts = [context.buildingName, context.floorName, context.roomName].filter(Boolean);
        const locationText = locationParts.length ? locationParts.join(' / ') : 'Not available';
        const reportedText = duplicate.reported_at ? UI.formatDate(duplicate.reported_at, 'full') : 'Not available';

        // TASK 44 — .report-info-list / .report-info-row is the existing,
        // theme-token-driven definition-list pattern already used on the report
        // detail pages (see report-detail.php); reused here instead of inventing
        // a new layout. This block is purely additional context — it does not
        // change what the duplicate check matched on (item_id + room_id), only
        // what is shown to the user about that match.
        const infoRows = [
            ['Report Code', UI.escapeHtml(code)],
            ['Equipment', context.equipmentName ? UI.escapeHtml(context.equipmentName) : 'Not available'],
            ['Asset Code', context.assetCode ? `<strong>${UI.escapeHtml(context.assetCode)}</strong>` : 'Not available'],
            ['Location', UI.escapeHtml(locationText)],
            ['Existing Issue', duplicate.damage_description ? UI.escapeHtml(duplicate.damage_description) : 'Not available'],
            ['Status', UI.escapeHtml(statusLabel)],
            ['Reported', UI.escapeHtml(reportedText)]
        ].map(([label, value]) => `<div class="report-info-row"><dt>${UI.escapeHtml(label)}</dt><dd>${value}</dd></div>`).join('');

        const modal = document.createElement('div');
        modal.id = 'asset-duplicate-modal';
        modal.className = 'system-modal-overlay';
        modal.setAttribute('role', 'alertdialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = `
            <div class="system-modal-card system-modal-warning">
                <!-- TASK 7.1 — was the entity-encoded '&#9888;' warning emoji. Uses the
                     same registry icon UI.systemConfirm's 'warning' variant now uses, so
                     this page-local modal stays visually identical to the shared one. -->
                <div class="system-modal-icon" aria-hidden="true">${window.UIIcons ? window.UIIcons.svg('alert-triangle', { size: 26 }) : ''}</div>
                <div class="system-modal-message">
                    A similar active report already exists for this asset in this room.
                </div>
                <dl class="report-info-list">${infoRows}</dl>
                <div class="system-modal-actions">
                    <button id="dupCancel" class="btn btn-secondary">Cancel</button>
                    <button id="dupDifferent" class="btn btn-warning">Different Issue</button>
                    <button id="dupView" class="btn btn-primary">View Existing Report</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const finish = (result) => { modal.remove(); resolve(result); };

        document.getElementById('dupView').onclick = () => { window.open(viewUrl, '_blank'); finish('cancel'); };
        document.getElementById('dupDifferent').onclick = () => finish('override');
        document.getElementById('dupCancel').onclick = () => finish('cancel');
        modal.addEventListener('click', (e) => { if (e.target === modal) finish('cancel'); });
    });
}

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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
