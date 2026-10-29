<?php
// Clean URLs: public_url() is used below, before header.php loads settings.
require_once __DIR__ . '/../../backend/config/settings.php';
require_once __DIR__ . '/../includes/avatar.php';
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
if (!isset($_SESSION['user']) && !isset($_SESSION['auth_user']) && !isset($user)) {
    header('Location: ' . public_url('/login'));
    exit;
}

$pageTitle = 'Account - SFMS';
$currentUser = $user ?? ($_SESSION['user'] ?? []);
$forceProfileUpdate = !empty($currentUser['force_profile_update']);
include __DIR__ . '/../includes/header.php';

// BUG FIX (2026-10-13): header.php sets $user from $_SESSION['user'], which
// is only refreshed at login. UserController::updateProfile() (the Save
// Changes handler for this very page) updates the database correctly but
// only writes the result back into Laravel's own session store, never into
// this native $_SESSION — so after a save, this page kept rendering
// whatever phone/username/avatar/verified_email/force_profile_update the
// session had at the LAST LOGIN, making a just-saved Phone Number (and the
// Verified Email badge) appear blank again the moment you navigate away and
// back. Re-reading the row fresh from the database here, every load, makes
// this page always show what's actually saved regardless of session age.
if (!empty($user['user_id'])) {
    require_once __DIR__ . '/../../backend/config/database.php';
    $pdo = getDBConnection();
    $stmt = $pdo->prepare(
        // Authorization was refreshed before page checks by settings.php.
        // This later lookup updates profile display fields only.
        'SELECT user_id, email, full_name, username, phone, avatar, verified_email, force_profile_update FROM users WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([(int) $user['user_id']]);
    $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($freshUser) {
        $user = array_merge($user, $freshUser);
        $_SESSION['user'] = $user;
        if (isset($_SESSION['auth_user'])) {
            $_SESSION['auth_user'] = array_merge((array) $_SESSION['auth_user'], $freshUser);
        }
    }
}
$currentUser = $user;
$forceProfileUpdate = !empty($currentUser['force_profile_update']);
?>

<style>
.settings-page .settings-form-group { min-width: 0; }
.settings-page .contact-field-error { color: #b91c1c; font-size: 12px; line-height: 1.5; margin-top: 6px; overflow-wrap: anywhere; }
.settings-page .contact-field-error:not([hidden]) { display: block; }
.settings-page .contact-field-error[hidden], .settings-page #send-otp-btn[hidden] { display: none !important; }
.settings-page .settings-input[aria-invalid="true"] { border-color: #dc2626 !important; box-shadow: inset 0 0 0 1px #dc2626; }
.settings-page .settings-phone-control.has-contact-error { border-color: #dc2626 !important; }
.settings-page #phone, .settings-page #new-email { min-width: 0; }
.settings-page #email-form { flex-wrap: wrap; }
.settings-page #new-email { flex: 1 1 180px !important; max-width: 100%; }
</style>
<main class="container settings-page <?php echo $forceProfileUpdate ? 'force-profile-setup' : ''; ?>">
    <div class="settings-shell">
        <section class="settings-content-column">
            <div class="settings-section-pane active">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-title">Account Settings</h3>
                        <p class="settings-description"><?php echo $forceProfileUpdate ? 'Complete your profile setup before continuing.' : 'Update your profile information and personal details.'; ?></p>
                    </div>

                    <div class="settings-card-body">
                        <form id="account-settings-form">
                            <div class="settings-form-grid">
                                <div class="settings-form-group">
                                    <label for="full_name" class="settings-label">Full Name</label>
                                    <input type="text" id="full_name" name="full_name" class="form-control settings-input" placeholder="Enter your full name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                                </div>

                                <div class="settings-form-group">
                                    <label for="username" class="settings-label">Username</label>
                                    <input type="text" id="username" name="username" class="form-control settings-input" placeholder="e.g. juan_dela_cruz" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" autocomplete="off" autocapitalize="off">
                                    <small style="color:#64748b;font-size:12px;">3-50 characters, lowercase letters, numbers, underscores only.</small>
                                </div>

                                <div class="settings-form-group">
                                    <label for="current_password" class="settings-label">Current Password</label>
                                    <div class="password-input-wrap">
                                        <input type="password" id="current_password" name="current_password" class="form-control settings-input" placeholder="Enter current password">
                                        <button type="button" class="toggle-password-btn" data-target="current_password" aria-label="Show current password" aria-pressed="false" title="Show password">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;pointer-events:none;"><path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="settings-form-group">
                                    <label for="new_password" class="settings-label">New Password</label>
                                    <div class="password-input-wrap">
                                        <input type="password" id="new_password" name="new_password" class="form-control settings-input" placeholder="Enter new password (leave blank to keep current)">
                                        <button type="button" class="toggle-password-btn" data-target="new_password" aria-label="Show new password" aria-pressed="false" title="Show password">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;pointer-events:none;"><path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Phone Number (2026-10-26) — the Send Code/OTP verification step
                                     was removed as redundant: it was requested, but the user decided
                                     a plain editable field (saved directly on "Save Changes", like
                                     Full Name/Username) is enough here. The SMS-OTP flow this used to
                                     require is still registered for Email Address below; it was
                                     never part of that decision and is untouched.
                                     2026-10-26 (later) — moved below Current/New Password (was above
                                     Current Password) per user request, so New Password sits directly
                                     across from Current Password instead of Phone Number. -->
                                <div class="settings-form-group">
                                    <label for="phone" class="settings-label" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                        Phone Number
                                        <?php if ($forceProfileUpdate): ?><span style="color:#64748b;font-weight:600;font-size:12px;">(Phone or Verified Email required)</span><?php endif; ?>
                                    </label>
                                    <!-- 2026-10-26 — PH-only validation, international-notation style:
                                         the "+63" badge replaces the leading "0" (not just a decorative
                                         label anymore), so the user types only the 10 digits after it.
                                         The submit handler below rebuilds "0" + these 10 digits into the
                                         full 09XXXXXXXXX (11-digit) format BEFORE sending — the exact
                                         format already proven to work with IPROG SMS (see
                                         SmsService::send() and the two live users.phone rows) — so
                                         UserController::updateProfile and IPROG see no format change at
                                         all; only this input's display changed. -->
                                    <div id="phone-control" class="settings-phone-control" style="display:flex; align-items:stretch; border:1px solid #d7c6f2; border-radius:10px; background:#fff; overflow:hidden;">
                                        <span style="display:flex; align-items:center; padding:0 12px; background:#f3effc; color:#6d28d9; font-weight:700; font-size:14px; border-right:1px solid #d7c6f2; white-space:nowrap;">+63</span>
                                        <?php
                                        // 2026-10-26 (later) — the "+63" badge now stands in for the
                                        // leading "0" too (international notation drops it: +63 917...
                                        // not +63 0917...), so the user only types the 10 digits after
                                        // it. users.phone is still stored/sent as the full 09XXXXXXXXX
                                        // (11 digits) — see the submit handler below, which rebuilds
                                        // that before the request ever reaches UserController — so this
                                        // is display-only and does NOT change the saved format.
                                        $phoneTenDigitDisplay = (preg_match('/^0(\d{10})$/', (string)($user['phone'] ?? ''), $phoneMatch)) ? $phoneMatch[1] : '';
                                        ?>
                                        <input type="text" id="phone" name="phone" class="form-control settings-input" placeholder="9171234713" value="<?php echo htmlspecialchars($phoneTenDigitDisplay); ?>" inputmode="numeric" autocomplete="tel" maxlength="10" aria-invalid="false" aria-describedby="phone-help phone-error" style="border:none; border-radius:0; flex:1; min-height:42px; background:transparent;">
                                    </div>
                                    <small id="phone-error" class="contact-field-error" role="alert" aria-live="polite" aria-atomic="true" hidden></small>
                                    <small id="phone-help" style="color:#64748b;font-size:12px;">For SMS alerts. Enter a 10-digit Philippine mobile number.</small>
                                </div>

                                <!-- Email Verification Section — placed beside Phone Number to use
                                     the empty grid cell instead of its own full-width row below. -->
                                <div class="settings-form-group">
                                    <label for="new-email" class="settings-label" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px;"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 6l10 7 10-7"/></svg>
                                        Email Address
                                        <?php if ($forceProfileUpdate): ?><span style="color:#64748b;font-weight:600;font-size:12px;">(Phone or Verified Email required)</span><?php endif; ?>
                                    </label>

                                    <!-- Card wrapper so this (multi-control) group reads as one cohesive
                                         block beside the Phone Number input, instead of a loose
                                         stack of mismatched-height elements. -->
                                    <div style="border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; padding: 14px;">
                                        <div id="email-display" style="margin-bottom: 12px;">
                                            <p style="font-size: 13px; color: #64748b; margin: 0;">
                                                Verified Email: <strong id="verified-email-display" style="color: #1e293b;"><?php echo !empty($user['verified_email']) ? htmlspecialchars($user['verified_email']) : 'Not set'; ?></strong>
                                            </p>
                                        </div>

                                        <!-- Email Input and Send OTP Button -->
                                        <div id="email-form" style="display: flex; gap: 8px;">
                                            <input type="email" id="new-email" class="form-control settings-input" placeholder="Enter your email address" maxlength="255" aria-invalid="false" aria-describedby="email-error" style="flex: 1; background: #fff;">
                                            <button type="button" id="send-otp-btn" class="btn btn-secondary" style="white-space: nowrap;">Send Code</button>
                                        </div>
                                        <small id="email-error" class="contact-field-error" role="alert" aria-live="polite" aria-atomic="true" hidden></small>

                                        <!-- OTP Verification Form (Hidden initially) -->
                                        <div id="otp-form" style="display: none; background: #fff; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 12px;">
                                            <p style="font-size: 13px; color: #64748b; margin: 0 0 12px 0;">Enter the 6-digit code sent to <strong id="otp-email-display"></strong></p>
                                            <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                                                <input type="text" id="otp-code" class="form-control settings-input" placeholder="000000" maxlength="6" inputmode="numeric" style="flex: 1; letter-spacing: 4px; font-size: 18px; text-align: center;">
                                                <button type="button" id="verify-otp-btn" class="btn btn-primary">Verify</button>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <p id="otp-timer" style="font-size: 12px; color: #64748b; margin: 0;"></p>
                                                <button type="button" id="resend-otp-btn" class="btn btn-link" style="font-size: 12px; padding: 0; display: none;">Resend Code</button>
                                                <button type="button" id="cancel-otp-btn" class="btn btn-link" style="font-size: 12px; padding: 0; color: #ef4444;">Cancel</button>
                                            </div>
                                        </div>

                                        <!-- Status Messages -->
                                        <div id="email-status" class="alert" style="display: none; margin-top: 12px;"></div>
                                    </div>
                                </div>

                                <div class="settings-form-group settings-span-2">
                                    <label for="profile_picture" class="settings-label">Profile Picture</label>
                                    <input type="file" id="profile_picture" name="profile_picture" class="form-control settings-input" accept="image/*">
                                    <div class="settings-avatar-row">
                                        <?php $settingsAvatarUrl = sfms_avatar_url($user['avatar'] ?? null); ?>
                                        <img <?php if ($settingsAvatarUrl !== ''): ?>src="<?php echo htmlspecialchars($settingsAvatarUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"<?php endif; ?> alt="Profile Preview" id="settings-avatar-preview" style="display:<?php echo $settingsAvatarUrl !== '' ? 'block' : 'none'; ?>; width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #d7c0f2;" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div id="settings-avatar-preview-fallback" style="width: 56px; height: 56px; border-radius: 50%; display:<?php echo $settingsAvatarUrl !== '' ? 'none' : 'flex'; ?>; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: #8A2BE2;"><?php echo htmlspecialchars(sfms_avatar_initials($user['full_name'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                        <span class="settings-help-text">Upload JPG, PNG, WEBP, or GIF (max 3MB)</span>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary settings-primary-btn">Save Changes</button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<?php if ($forceProfileUpdate): ?>
<div class="force-setup-popup" id="force-setup-popup" role="dialog" aria-modal="true" aria-labelledby="force-setup-title">
    <div class="force-setup-popup-card">
        <h2 id="force-setup-title">Profile Setup Required</h2>
        <p>Change your temporary password and provide at least one contact method: Phone Number or Verified Email.</p>
        <button type="button" id="force-setup-continue" class="btn btn-primary settings-primary-btn">Update Now</button>
    </div>
</div>
<?php endif; ?>

<div id="settings-toast" class="settings-toast" aria-live="polite"></div>

<link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/settings.inline.css?v=20260921-2')); ?>">

<script>
window.API = window.API || {
    async logout() {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/auth/logout'), {
            method: 'POST',
            credentials: 'include'
        });
        const data = await response.json();
        return data;
    },
    async updateProfile(formData) {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/users/profile'), {
            method: 'PATCH',
            body: formData,
            credentials: 'include'
        });
        const data = await response.json();
        return data;
    }
};

window.Session = window.Session || {
    get(key) {
        const v = localStorage.getItem(key);
        return v ? JSON.parse(v) : null;
    },
    set(key, value) { localStorage.setItem(key, JSON.stringify(value)); },
    clear() { localStorage.clear(); }
};

const forceProfileSetup = <?php echo $forceProfileUpdate ? 'true' : 'false'; ?>;
const scannedDispatchReloginPath = <?php echo json_encode(\App\Support\ScannedDispatchDestination::carry('/login?password_changed=1', $_GET['next_dispatch'] ?? null), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

let settingsToastTimer;
function showSettingsToast(message, type = 'success') {
    const toast = document.getElementById('settings-toast');
    if (!toast) return;

    toast.textContent = message;
    toast.classList.remove('success', 'error', 'show');
    toast.classList.add(type);

    clearTimeout(settingsToastTimer);
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    settingsToastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 2400);
}

document.querySelectorAll('.toggle-password-btn').forEach((button) => {
    button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (!input) return;

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        button.setAttribute('aria-label', (isHidden ? 'Hide' : 'Show') + ' password');
        button.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
        button.innerHTML = isHidden
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;pointer-events:none;"><path d="M3 3L21 21" stroke-linecap="round"/><path d="M10.6 10.7C10.2 11.1 10 11.5 10 12C10 13.1 10.9 14 12 14C12.5 14 12.9 13.8 13.3 13.4" stroke-linecap="round"/><path d="M9.9 5.2C10.6 5.1 11.3 5 12 5C16.5 5 20.2 7.9 22 12C21.3 13.6 20.2 15 18.8 16.1" stroke-linecap="round"/><path d="M6.3 6.3C4.6 7.5 3.2 9.4 2 12C3.8 16.1 7.5 19 12 19C13.7 19 15.3 18.6 16.6 17.9" stroke-linecap="round"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;pointer-events:none;"><path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
    });
});

function normalizeAccountAvatarUrl(avatar) {
    const value = String(avatar || '').trim();
    if (!value || /^(?:https?:)?\/\//i.test(value) || /^blob:/i.test(value)) return value;
    if (/^(?:javascript|data|vbscript):/i.test(value)) return '';
    const match = value.match(/^\/?(?:School_Facility_Maintenance_System\/)?(?:public\/)?(frontend\/assets\/uploads\/avatars\/[^/?#]+)([?#].*)?$/);
    if (!match) return value;
    return window.SFMS_PUBLIC_URL('/' + match[1]) + (match[2] || '');
}

function renderAccountAvatar(imageId, fallbackId, userData) {
    const image = document.getElementById(imageId);
    const fallback = document.getElementById(fallbackId);
    if (!image || !fallback) return;
    fallback.textContent = String(userData.full_name || 'U').trim().split(/\s+/).slice(0, 2)
        .map(part => Array.from(part)[0] || '').join('').toUpperCase() || 'U';
    const showFallback = () => {
        image.style.display = 'none';
        fallback.style.display = 'flex';
    };
    image.onerror = showFallback;
    const url = normalizeAccountAvatarUrl(userData.avatar);
    if (!url) {
        image.removeAttribute('src');
        showFallback();
        return;
    }
    image.style.display = 'block';
    fallback.style.display = 'none';
    image.src = url;
}

function renderHeaderAvatar(userData) {
    const headerName = document.getElementById('header-user-name');
    if (headerName && userData.full_name) {
        headerName.textContent = userData.full_name;
    }

    const profileRoot = document.querySelector('.user-profile');
    if (profileRoot && userData.full_name) {
        profileRoot.setAttribute('title', userData.full_name);
    }

    const profileContainer = document.querySelector('.user-avatar-wrap');
    if (!profileContainer) return;

    renderAccountAvatar('header-user-avatar', 'header-user-avatar-fallback', userData);
}

function renderSettingsAvatar(userData) {
    renderAccountAvatar('settings-avatar-preview', 'settings-avatar-preview-fallback', userData);
}

document.getElementById('profile_picture').addEventListener('change', function(e) {
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const previewUrl = URL.createObjectURL(file);
    renderSettingsAvatar({
        full_name: document.getElementById('full_name').value || 'U',
        avatar: previewUrl
    });
});

// 2026-10-26 — PH mobile numbers only: strip anything non-digit as the user
// types and hard-cap at 10 characters (the "+63" badge now stands for the
// leading "0" too, international-notation style, so the user only types the
// 9XXXXXXXXX part), so it is physically impossible to type letters,
// symbols, or an 11th digit into this field. The server (UserController::
// updateProfile) still only ever receives the full 09XXXXXXXXX (11-digit)
// value — see "0" + phoneTyped below, right before the fetch — so IPROG's
// proven format and UserController's /^09\d{9}$/ check are UNCHANGED.
const phoneInputEl = document.getElementById('phone');
if (phoneInputEl) {
    phoneInputEl.addEventListener('input', () => {
        phoneInputEl.value = phoneInputEl.value.replace(/\D/g, '').slice(0, 10);
        clearAccountContactError('phone');
    });
}

// Matches the 10-digit value actually typed into #phone (no leading "0" —
// that's implied by the "+63" badge). Every valid PH 09-format number is
// "09" + 9 digits, so stripping that leading "0" always leaves something
// starting with "9" — hence the fixed leading 9 here, not \d.
const PHONE_PATTERN = /^9\d{9}$/;
// Initially rendered from the database; updated only after successful OTP verification.
const initialVerifiedEmail = document.getElementById('verified-email-display')?.textContent.trim() || '';
let accountEmailVerified = initialVerifiedEmail !== '' && initialVerifiedEmail !== 'Not set';

function clearAccountContactError(field) {
    const input = document.getElementById(field === 'phone' ? 'phone' : 'new-email');
    const error = document.getElementById(field + '-error');
    input.setAttribute('aria-invalid', 'false');
    error.textContent = '';
    error.hidden = true;
    if (field === 'phone') document.getElementById('phone-control').classList.remove('has-contact-error');
}

function focusAccountInvalidField(input) {
    input.focus({ preventScroll: true });
    input.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
}

function showAccountContactErrors(payload, focusFirst = false) {
    const source = payload?.payload || payload || {};
    const errors = source.errors || source.data?.errors || {};
    const message = source.message || '';
    const firstMessage = value => (Array.isArray(value) ? value : [value]).find(value => typeof value === 'string' && value.trim());
    const messages = {
        phone: firstMessage(errors.phone),
        email: firstMessage(errors.email) || firstMessage(errors.verified_email),
    };
    if (message === 'This phone number is already registered.') messages.phone = message;
    if (message === 'This email address is already registered.') messages.email = message;
    let firstInput = null;
    for (const field of ['phone', 'email']) {
        if (!messages[field]) continue;
        const input = document.getElementById(field === 'phone' ? 'phone' : 'new-email');
        const error = document.getElementById(field + '-error');
        error.textContent = messages[field];
        error.hidden = false;
        input.setAttribute('aria-invalid', 'true');
        if (field === 'phone') document.getElementById('phone-control').classList.add('has-contact-error');
        if (field === 'email' && document.getElementById('email-form').style.display === 'none') {
            // Keep the pending OTP and its controls intact; Cancel still permits changing email.
            document.getElementById('email-form').style.display = 'flex';
            input.readOnly = true;
            document.getElementById('send-otp-btn').hidden = true;
        }
        firstInput = firstInput || input;
    }
    if (firstInput && focusFirst) {
        focusAccountInvalidField(firstInput);
    }
    return Boolean(firstInput);
}

async function updateAccountProfile(formData) {
    // Same multipart POST/PATCH contract as API.updateProfile, retaining validation details locally.
    formData.append('_method', 'PATCH');
    const response = await fetch(window.SFMS_PUBLIC_URL('/api/users/profile'), {
        method: 'POST', credentials: 'include', body: formData,
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        const error = new Error(data.message || 'Failed to save account settings');
        error.payload = data;
        throw error;
    }
    return data;
}

const originalAccountFullName = document.getElementById('full_name').value;

function normalizeAccountNameWhitespace(value) {
    return String(value).normalize('NFC').replace(/[\p{Z}\s]+/gu, ' ').trim();
}

function accountNameValidationError(value, originalValue) {
    value = normalizeAccountNameWhitespace(value);
    if (value === normalizeAccountNameWhitespace(originalValue)) return null;
    value = value.replace(/ *, */g, ', ')
        .replace(/ (jr\.?|sr\.?|ii|iii|iv|v)$/iu, (_, suffix) => {
            const key = suffix.toLowerCase();
            return ' ' + (key.replace('.', '') === 'jr' ? 'Jr.' : key.replace('.', '') === 'sr' ? 'Sr.' : key.toUpperCase());
        });
    const length = Array.from(value).length;
    if (length < 2 || length > 255 || !/^(?=.*\p{L})[\p{L}\p{M} .,'’\-]+$/u.test(value)) {
        return 'Full name must be 2–255 characters using letters and supported name punctuation (no numbers).';
    }
    return null;
}

document.getElementById('account-settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fullName = document.getElementById('full_name').value.trim();
    const username = document.getElementById('username')?.value.trim().toLowerCase() || '';
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value.trim();

    if (!fullName) {
        showSettingsToast('Please enter your full name', 'error');
        focusAccountInvalidField(document.getElementById('full_name'));
        return;
    }
    const nameError = accountNameValidationError(fullName, originalAccountFullName);
    if (nameError) {
        showSettingsToast(nameError, 'error');
        focusAccountInvalidField(document.getElementById('full_name'));
        return;
    }

    if (username && !/^[a-z0-9_]{3,50}$/.test(username)) {
        showSettingsToast('Username must be 3-50 characters: lowercase letters, numbers, underscores only.', 'error');
        focusAccountInvalidField(document.getElementById('username'));
        return;
    }

    if (forceProfileSetup) {
        if (!currentPassword || !newPassword) {
            showSettingsToast('For first-time setup, current and new password are required', 'error');
            focusAccountInvalidField(document.getElementById(!currentPassword ? 'current_password' : 'new_password'));
            return;
        }

        if (document.getElementById('new_password').value === currentPassword) {
            showSettingsToast('Choose a new password different from your temporary password', 'error');
            focusAccountInvalidField(document.getElementById('new_password'));
            return;
        }
        const phoneValue = document.getElementById('phone')?.value.trim() || '';
        if (phoneValue && !PHONE_PATTERN.test(phoneValue)) {
            showSettingsToast('Phone number must be 10 digits (e.g. 9171234713 for +63 917 123 4713)', 'error');
            focusAccountInvalidField(document.getElementById('phone'));
            return;
        }

        if (!phoneValue && !accountEmailVerified) {
            showSettingsToast('Provide at least one contact method: a valid Phone Number or Verified Email.', 'error');
            focusAccountInvalidField(document.getElementById('phone'));
            return;
        }
    }

    // 2026-10-26 — Outside forced setup, phone is optional (nullable in DB),
    // but IF the user typed something, it must match the PH mobile format —
    // otherwise a half-typed/garbled number would silently save and break
    // the SMS alert feature later with no feedback at save time.
    if (!forceProfileSetup) {
        const phoneValueOptional = document.getElementById('phone')?.value.trim() || '';
        if (phoneValueOptional && !PHONE_PATTERN.test(phoneValueOptional)) {
            showSettingsToast('Phone number must be 10 digits (e.g. 9171234713 for +63 917 123 4713)', 'error');
            focusAccountInvalidField(document.getElementById('phone'));
            return;
        }
    }

    const form = document.getElementById('account-settings-form');
    const submitButton = form.querySelector('button[type="submit"]');
    const originalLabel = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = 'Saving...';

    try {
        const formData = new FormData(form);

        // 2026-10-26 (later) — #phone now only collects the 10 digits after
        // the "+63" badge (no leading "0"), but UserController::
        // updateProfile — and, downstream of it, SmsService/IPROG — still
        // expect and validate the original, proven 09XXXXXXXXX (11-digit)
        // format. Rebuild that exact format here, right before it's sent,
        // so the server-side contract and the live SMS integration need
        // ZERO changes. Already validated above as empty or /^9\d{9}$/, so
        // this is always either '' or exactly "0" + 10 digits = 11 chars.
        const phoneTyped = document.getElementById('phone')?.value.trim() || '';
        formData.set('phone', phoneTyped ? ('0' + phoneTyped) : '');

        const response = await updateAccountProfile(formData);

        if (!response.success) {
            throw new Error(response.message || 'Failed to save account settings');
        }
        clearAccountContactError('phone');

        const updatedUser = response.data?.user || { full_name: fullName };
        renderHeaderAvatar(updatedUser);
        renderSettingsAvatar(updatedUser);

        if (window.Session && typeof Session.get === 'function' && typeof Session.set === 'function') {
            const sessionUser = Session.get('user') || {};
            Session.set('user', { ...sessionUser, ...updatedUser });
        }

        // ALWAYS remove the force-profile-setup overlay on success to unblock sidebar
        // This class creates position: fixed; inset: 0; z-index: 1500 which blocks the entire page
        const settingsPage = document.querySelector('main.settings-page');
        if (settingsPage && settingsPage.classList.contains('force-profile-setup')) {
            settingsPage.classList.remove('force-profile-setup');
        }
        
        // Also hide the popup modal
        const popup = document.getElementById('force-setup-popup');
        if (popup) {
            popup.classList.add('is-hidden');
        }

        if (forceProfileSetup || response.data?.requires_reauthentication) {
            // TASK 55 — a forced first-time (or admin-reset) password change
            // must not just continue into the same session: the whole point
            // of forcing it is to prove the account holder now knows the new
            // password, not the one an Administrator set/generated for them.
            // Log the session out server-side and native $_SESSION alike,
            // then send them to the login form to re-authenticate with it.
            showSettingsToast('Password changed. Logging you out...');
            // Session invalidation has already committed on the server.
            setTimeout(() => {
                window.location.href = window.SFMS_PUBLIC_URL(typeof scannedDispatchReloginPath === 'string' ? scannedDispatchReloginPath : '/login?password_changed=1');
            }, 1200);
            return;
        }

        showSettingsToast('Account settings saved successfully!');
    } catch (error) {
        console.error('Save account settings error:', error);
        const errors = error.payload?.errors || error.payload?.data?.errors || {};
        const firstInvalid = ['full_name', 'username', 'current_password', 'new_password', 'phone', 'email', 'verified_email', 'profile_picture']
            .find(field => errors[field]);
        const contactErrorShown = showAccountContactErrors(error, !firstInvalid);
        if (firstInvalid) {
            focusAccountInvalidField(document.getElementById(['email', 'verified_email'].includes(firstInvalid) ? 'new-email' : firstInvalid));
        }
        const otherError = Object.entries(errors)
            .filter(([field]) => !['phone', 'email', 'verified_email'].includes(field))
            .flatMap(([, messages]) => Array.isArray(messages) ? messages : [messages])
            .find(message => typeof message === 'string' && message.trim());
        if (!contactErrorShown || otherError) {
            showSettingsToast(otherError || error.message || 'Failed to save account settings', 'error');
        }
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
    }
});

if (<?php echo $forceProfileUpdate ? 'true' : 'false'; ?>) {
    window.addEventListener('load', () => {
        const popup = document.getElementById('force-setup-popup');
        const continueBtn = document.getElementById('force-setup-continue');
        const firstField = document.getElementById('full_name');

        if (continueBtn) {
            continueBtn.addEventListener('click', () => {
                popup?.classList.add('is-hidden');
                firstField?.focus();
            });
        }

        if (firstField) {
            firstField.focus();
        }
    });
}

// ═════════════════════════════════════════════════════════════════════════════
// Email Verification Flow
// ═════════════════════════════════════════════════════════════════════════════

let otpTimer = null;
let otpExpiresAt = null;

const emailForm = document.getElementById('email-form');
const otpForm = document.getElementById('otp-form');
const sendOtpBtn = document.getElementById('send-otp-btn');
const verifyOtpBtn = document.getElementById('verify-otp-btn');
const resendOtpBtn = document.getElementById('resend-otp-btn');
const cancelOtpBtn = document.getElementById('cancel-otp-btn');
const newEmailInput = document.getElementById('new-email');
const otpCodeInput = document.getElementById('otp-code');
const emailStatusDiv = document.getElementById('email-status');
const otpTimerDiv = document.getElementById('otp-timer');
const otpEmailDisplay = document.getElementById('otp-email-display');
const verifiedEmailDisplay = document.getElementById('verified-email-display');
newEmailInput.addEventListener('input', () => clearAccountContactError('email'));

// NOTE (2026-10-13): the current verified email used to be loaded here via
// fetch('/api/users/profile'), but no GET route for that URI has ever been
// registered (routes/web.php only registers PATCH users/profile) — so this
// request always 404ed and silently left the "Not set" placeholder on
// screen even when an email was verified. The PHP markup above now renders
// $user['verified_email'] directly from a fresh database read on every page
// load, so no client-side fetch is needed for the initial display.

// Show status message
function showEmailStatus(message, isError = false, payload = null) {
    if (isError && showAccountContactErrors(payload || { message }, true)) {
        emailStatusDiv.style.display = 'none';
        return;
    }
    emailStatusDiv.textContent = message;
    emailStatusDiv.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
    emailStatusDiv.style.display = 'block';
}

// Hide status message
function hideEmailStatus() {
    emailStatusDiv.style.display = 'none';
    clearAccountContactError('email');
}

// Start OTP timer countdown
function startOtpTimer(expiresAt) {
    otpExpiresAt = expiresAt;
    clearInterval(otpTimer);

    function updateTimer() {
        const now = Date.now();
        const remaining = Math.max(0, expiresAt - now);
        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);

        if (remaining <= 0) {
            otpTimerDiv.textContent = 'Code expired';
            resendOtpBtn.style.display = 'block';
            verifyOtpBtn.disabled = true;
            clearInterval(otpTimer);
        } else {
            otpTimerDiv.textContent = `Expires in ${minutes}:${seconds.toString().padStart(2, '0')}`;
            verifyOtpBtn.disabled = false;
        }
    }

    updateTimer();
    otpTimer = setInterval(updateTimer, 1000);
}

// Send OTP
sendOtpBtn.addEventListener('click', async () => {
    const email = newEmailInput.value.trim();

    if (!email) {
        showEmailStatus('Please enter an email address', true, { errors: { email: ['Please enter an email address'] } });
        return;
    }

    if (email.length > 255 || !newEmailInput.checkValidity() || !/^[^\s@]+@(?:gmail\.com|yahoo\.com)$/i.test(email)) {
        showEmailStatus('Enter a valid email using gmail.com or yahoo.com (maximum 255 characters).', true, {
            errors: { email: ['Enter a valid email using gmail.com or yahoo.com (maximum 255 characters).'] },
        });
        return;
    }

    sendOtpBtn.disabled = true;
    sendOtpBtn.textContent = 'Sending...';

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/email-verification/initiate'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ email })
        });

        const data = await response.json();

        if (response.ok) {
            hideEmailStatus();
            emailForm.style.display = 'none';
            otpForm.style.display = 'block';
            otpEmailDisplay.textContent = email;
            otpCodeInput.focus();

            // Start timer: 5 minutes
            const expiresAt = Date.now() + (5 * 60 * 1000);
            startOtpTimer(expiresAt);
        } else {
            showEmailStatus(data.message || 'Failed to send OTP', true, data);
        }
    } catch (err) {
        console.error('OTP send error:', err);
        showEmailStatus('Failed to send OTP. Please try again.', true);
    } finally {
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = 'Send Code';
    }
});

// Verify OTP
verifyOtpBtn.addEventListener('click', async () => {
    const email = newEmailInput.value.trim();
    const otp = otpCodeInput.value.trim();

    if (!otp || otp.length !== 6) {
        showEmailStatus('Please enter a 6-digit code', true);
        return;
    }

    verifyOtpBtn.disabled = true;
    verifyOtpBtn.textContent = 'Verifying...';

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/email-verification/verify-otp'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ email, otp })
        });

        const data = await response.json();

        if (response.ok) {
            hideEmailStatus();
            otpForm.style.display = 'none';
            emailForm.style.display = 'flex';
            newEmailInput.readOnly = false;
            sendOtpBtn.hidden = false;
            newEmailInput.value = '';
            otpCodeInput.value = '';
            verifiedEmailDisplay.textContent = email;
            accountEmailVerified = true;

            showSettingsToast('Email verified successfully!', 'success');
            clearInterval(otpTimer);
        } else {
            showEmailStatus(data.message || 'Verification failed', true, data);
        }
    } catch (err) {
        console.error('OTP verification error:', err);
        showEmailStatus('Verification failed. Please try again.', true);
    } finally {
        verifyOtpBtn.disabled = false;
        verifyOtpBtn.textContent = 'Verify';
    }
});

// Resend OTP
resendOtpBtn.addEventListener('click', async () => {
    const email = newEmailInput.value.trim();

    resendOtpBtn.disabled = true;
    resendOtpBtn.textContent = 'Resending...';

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/email-verification/resend-otp'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ email })
        });

        const data = await response.json();

        if (response.ok) {
            hideEmailStatus();
            otpCodeInput.value = '';
            otpCodeInput.focus();
            resendOtpBtn.style.display = 'none';

            const expiresAt = Date.now() + (5 * 60 * 1000);
            startOtpTimer(expiresAt);

            showSettingsToast('New OTP sent', 'success');
        } else {
            showEmailStatus(data.message || 'Failed to resend OTP', true, data);
        }
    } catch (err) {
        console.error('Resend OTP error:', err);
        showEmailStatus('Failed to resend OTP. Please try again.', true);
    } finally {
        resendOtpBtn.disabled = false;
        resendOtpBtn.textContent = 'Resend Code';
    }
});

// Cancel OTP verification
cancelOtpBtn.addEventListener('click', () => {
    otpForm.style.display = 'none';
    emailForm.style.display = 'flex';
    newEmailInput.readOnly = false;
    sendOtpBtn.hidden = false;
    newEmailInput.value = '';
    otpCodeInput.value = '';
    hideEmailStatus();
    clearInterval(otpTimer);
    newEmailInput.focus();
});

// Allow Enter key to submit OTP
otpCodeInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        verifyOtpBtn.click();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
