<?php
/**
 * TASK 25 — Semester Settings (dedicated System Settings page, Option A).
 *
 * Replaces the old "Change Semester" modal on the Administrator Dashboard.
 * The Administrator configures the semester SCHEDULE here only ONCE:
 * School Year + the four semester dates. The system then determines the
 * current semester automatically from today's date (see
 * App\Models\SchoolSetting::syncAutomatic()) — no manual "Change Semester"
 * action exists anywhere anymore.
 *
 * super_admin only, same guard pattern as users.php.
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

if (!isset($_SESSION['user']) && !isset($_SESSION['auth_user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$user = $_SESSION['user'] ?? $_SESSION['auth_user'];
$userRole = $user['role'] ?? 'user';

if (!in_array($userRole, ['super_admin'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

// TASK 21 — Stale Session After User Deletion guard (same as other
// admin-only standalone/settings pages).
require_once __DIR__ . '/../includes/session-guard.php';
sfms_reject_stale_session();

$pageTitle = 'Semester Settings - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container settings-page">
    <div class="settings-shell">
        <section class="settings-content-column">
            <div class="settings-section-pane active">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-title">Semester Settings</h3>
                        <p class="settings-description">Configure the School Year and semester date ranges once. The current semester is then determined automatically from today's date — no manual switching required.</p>
                    </div>

                    <div class="settings-card-body">
                        <div id="semester-settings-alert"></div>

                        <div class="settings-form-group settings-span-2" style="margin-bottom:16px;background:var(--bg-secondary,#f8fafc);border:1px solid var(--border-color,#e5e7eb);border-radius:8px;padding:12px 16px;">
                            <span class="settings-label" style="display:block;margin-bottom:4px;">Currently Active</span>
                            <div id="semester-settings-current-summary" style="font-size:0.95rem;color:var(--text-secondary,#475569);">Loading…</div>
                        </div>

                        <form id="semester-settings-form">
                            <div class="settings-form-grid">
                                <div class="settings-form-group settings-span-2">
                                    <label for="school-year" class="settings-label">School Year *</label>
                                    <input type="text" id="school-year" name="school_year" class="form-control settings-input" placeholder="e.g. 2026-2027" required>
                                </div>

                                <div class="settings-form-group">
                                    <label for="first-sem-start" class="settings-label">First Semester Start Date *</label>
                                    <input type="date" id="first-sem-start" name="first_sem_start" class="form-control settings-input" required>
                                </div>

                                <div class="settings-form-group">
                                    <label for="first-sem-end" class="settings-label">First Semester End Date *</label>
                                    <input type="date" id="first-sem-end" name="first_sem_end" class="form-control settings-input" required>
                                </div>

                                <div class="settings-form-group">
                                    <label for="second-sem-start" class="settings-label">Second Semester Start Date *</label>
                                    <input type="date" id="second-sem-start" name="second_sem_start" class="form-control settings-input" required>
                                </div>

                                <div class="settings-form-group">
                                    <label for="second-sem-end" class="settings-label">Second Semester End Date *</label>
                                    <input type="date" id="second-sem-end" name="second_sem_end" class="form-control settings-input" required>
                                </div>
                            </div>

                            <p class="settings-help-text" style="margin-top:8px;">The current semester (and semester-scoped dashboard statistics) update automatically based on these dates. No report is deleted, archived, or modified, and historical reports remain fully visible on the Reports page regardless of semester changes.</p>

                            <button type="submit" class="btn btn-primary settings-primary-btn" id="semester-settings-save-btn">Save Settings</button>
                            <a href="<?php echo public_url('/frontend/pages/dashboard.php'); ?>" class="btn btn-secondary" style="margin-left:8px;">Back to Dashboard</a>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/settings.inline.css?v=20260921-2">

<script>
function formatSemesterDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

// TASK 25.2 — Enterprise Semester Lifecycle. `current_semester` is `null`
// whenever no semester is literally running today — this never guesses a
// semester, and instead surfaces exactly WHY nothing is active right now
// (Upcoming First Semester / Semester Break / School Year Completed) via
// `semester_status`, mirroring dashboard.php's renderSchoolSettingsLabel().
function renderCurrentSummary(data) {
    const el = document.getElementById('semester-settings-current-summary');
    if (!el) return;

    const isSecond = data.current_semester === 'Second Semester';
    const durationStart = isSecond ? data.second_sem_start : data.first_sem_start;
    const durationEnd = isSecond ? data.second_sem_end : data.first_sem_end;

    let html =
        '<strong>Current Semester:</strong> ' + (data.current_semester || 'No Active Semester') +
        ' &nbsp;|&nbsp; <strong>Status:</strong> ' + (data.semester_status || '—');

    if (data.semester_status === 'Upcoming First Semester' && Number.isInteger(data.semester_days_until_start)) {
        html += ' (Starts in ' + data.semester_days_until_start + ' day' + (data.semester_days_until_start === 1 ? '' : 's') + ')';
    }

    html += ' &nbsp;|&nbsp; <strong>School Year:</strong> ' + (data.school_year || '—');

    if (data.current_semester) {
        html += ' &nbsp;|&nbsp; <strong>Semester Duration:</strong> ' + formatSemesterDate(durationStart) + ' – ' + formatSemesterDate(durationEnd);
    }

    el.innerHTML = html;
}

function showSemesterAlert(message, type) {
    const alertEl = document.getElementById('semester-settings-alert');
    if (!alertEl) return;
    const cls = type === 'error' ? 'alert-danger' : 'alert-success';
    alertEl.innerHTML = '<div class="alert ' + cls + '" style="padding:10px 14px;border-radius:6px;margin-bottom:14px;' +
        (type === 'error' ? 'background:#fee2e2;color:#991b1b;' : 'background:#dcfce7;color:#166534;') + '">' +
        message.replace(/</g, '&lt;') + '</div>';
}

async function loadSemesterSettings() {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/school-settings'), { credentials: 'include' });
        const result = await response.json();
        if (!result.success) throw new Error(result.message || 'Failed to load semester settings');

        const data = result.data;
        document.getElementById('school-year').value = data.school_year || '';
        document.getElementById('first-sem-start').value = data.first_sem_start || '';
        document.getElementById('first-sem-end').value = data.first_sem_end || '';
        document.getElementById('second-sem-start').value = data.second_sem_start || '';
        document.getElementById('second-sem-end').value = data.second_sem_end || '';

        renderCurrentSummary(data);
    } catch (error) {
        console.error('Load semester settings error:', error);
        showSemesterAlert(error.message || 'Failed to load semester settings', 'error');
    }
}

document.getElementById('semester-settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    const payload = {
        school_year: document.getElementById('school-year').value.trim(),
        first_sem_start: document.getElementById('first-sem-start').value,
        first_sem_end: document.getElementById('first-sem-end').value,
        second_sem_start: document.getElementById('second-sem-start').value,
        second_sem_end: document.getElementById('second-sem-end').value,
    };

    const saveBtn = document.getElementById('semester-settings-save-btn');
    const originalLabel = saveBtn.textContent;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/school-settings'), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (!result.success) {
            const firstError = result.errors ? Object.values(result.errors)[0] : null;
            throw new Error((firstError && firstError[0]) || result.message || 'Failed to save semester settings');
        }

        renderCurrentSummary(result.data);
        showSemesterAlert('Semester settings saved successfully.', 'success');
    } catch (error) {
        console.error('Save semester settings error:', error);
        showSemesterAlert(error.message || 'Failed to save semester settings', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = originalLabel;
    }
});

loadSemesterSettings();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
