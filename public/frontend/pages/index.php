<?php
/**
 * Login Page
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

require_once __DIR__ . '/../../backend/config/settings.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['auth_user']) || isset($_SESSION['user'])) {
    header('Location: ' . public_url('/frontend/pages/dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - School Facility Maintenance System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/styles.css?v=20260921-2')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/color-scheme.css?v=20260921-2')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/login.css?v=20260927-8')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/ios-safari-fixes.css?v=20260926-1')); ?>">
</head>
<body>

<main class="login-container">
    <div class="card">
        <section class="form-panel">
            <div class="form-panel-inner">
                <div id="alert-container">
                    <?php if (isset($_GET['session_expired'])): ?>
                    <div class="alert alert-danger">
                        Your session has expired or your account no longer exists. Please sign in again.
                    </div>
                    <?php endif; ?>
                    <?php if (isset($_GET['password_changed'])): ?>
                    <div class="alert alert-success">
                        Password changed. Please log in again.
                    </div>
                    <?php endif; ?>
                </div>

                <form id="login-form" autocomplete="off">
                    <span class="signin-accent-line" aria-hidden="true"></span>
                    <h2 class="auth-title">Sign In</h2>
                    <p class="auth-subtitle">Access your account to continue</p>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-icon-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                <path d="M12 12C14.4853 12 16.5 9.98528 16.5 7.5C16.5 5.01472 14.4853 3 12 3C9.51472 3 7.5 5.01472 7.5 7.5C7.5 9.98528 9.51472 12 12 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M4 20.4C4.8 17 8.1 14.5 12 14.5C15.9 14.5 19.2 17 20 20.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                autocomplete="off"
                                value=""
                                required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-field-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                <rect x="5" y="10.5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>
                                <path d="M8 10.5V7.5C8 5.29086 9.79086 3.5 12 3.5C14.2091 3.5 16 5.29086 16 7.5V10.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="new-password"
                                value=""
                                required>
                            <button type="button" id="toggle-login-password" class="password-toggle-btn" aria-label="Show password" title="Show password">
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                    <path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="login-options-row">
                        <label class="remember-me-control" for="remember_me">
                            <input type="checkbox" id="remember_me" name="remember_me">
                            <span>Remember me</span>
                        </label>
                        <button type="button" class="forgot-password-link" id="show-forgot-password-btn">Forgot password?</button>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="login-btn">
                        <svg class="btn-arrow-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                            <path d="M5 12H19" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M13 6L19 12L13 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Sign In</span>
                    </button>
                </form>

                <form id="forgot-password-form" class="form-hidden" autocomplete="off">
                    <h2 class="auth-title">Forgot Password</h2>
                    <p class="auth-subtitle">Enter your username to request a password reset</p>

                    <div class="form-group">
                        <label for="forgot_email">Username</label>
                        <input
                            type="text"
                            id="forgot_email"
                            name="forgot_email"
                            placeholder="Enter your username"
                            autocomplete="off"
                            value=""
                            required>
                    </div>

                    <div class="alert alert-info" style="margin-bottom: 16px;">
                        Your Administrator will be notified to reset your password for you.
                    </div>

                    <button type="button" class="btn btn-primary btn-block" id="notify-super-admin-btn">Submit Request</button>
                    <button type="button" class="btn btn-secondary btn-block" id="forgot-back-login-btn">Back to Sign In</button>
                </form>

                <form id="register-form" class="form-hidden" autocomplete="off">
                    <h1 class="auth-title">Create Account</h1>
                    <p class="auth-subtitle">Register and wait for Administrator approval</p>

                    <div class="form-group" id="register-name-group">
                        <label for="register_full_name">Name</label>
                        <input
                            type="text"
                            id="register_full_name"
                            name="register_full_name"
                            placeholder="Name"
                            autocomplete="off"
                            readonly
                            value=""
                            required>
                        <div class="field-error-text">Name must contain only letters, spaces, and hyphens (no numbers)</div>
                    </div>

                    <div class="form-group" id="register-username-group">
                        <label for="register_username">Username</label>
                        <input
                            type="text"
                            id="register_username"
                            name="register_username"
                            placeholder="e.g. juan_dela_cruz"
                            autocomplete="off"
                            autocapitalize="off"
                            autocorrect="off"
                            spellcheck="false"
                            readonly
                            value=""
                            required>
                        <!-- TASK 81 Part 1 — this is the identifier login() authenticates
                             against; same required/min:3/max:50/unique rule as the
                             Administrator-facing Create User modal (users.php). -->
                        <div class="field-error-text">Username must be 3-50 characters: letters, numbers, and underscores only</div>
                    </div>

                    <div class="form-group" id="register-email-group">
                        <label for="register_email">Email</label>
                        <input
                            type="email"
                            id="register_email"
                            name="register_email"
                            placeholder="Email"
                            autocomplete="off"
                            readonly
                            value=""
                            required>
                        <div class="field-error-text">Please enter a valid email address</div>
                    </div>

                    <div class="form-group" id="register-password-group">
                        <label for="register_password">Password</label>
                        <input
                            type="password"
                            id="register_password"
                            name="register_password"
                            placeholder="Minimum 8 characters"
                            autocomplete="new-password"
                            readonly
                            value=""
                            required>
                        <div class="password-strength-meter" id="register-password-meter">
                            <div class="password-strength-bar" id="register-password-bar"></div>
                        </div>
                        <div class="password-strength-text" id="register-password-text"></div>
                        <div class="field-error-text">Password must be at least 8 characters with uppercase, lowercase, and numbers</div>
                    </div>

                    <div class="form-group" id="register-confirm-password-group">
                        <label for="register_confirm_password">Confirm Password</label>
                        <input
                            type="password"
                            id="register_confirm_password"
                            name="register_confirm_password"
                            placeholder="Re-enter password"
                            autocomplete="new-password"
                            readonly
                            value=""
                            required>
                        <div class="field-error-text">Passwords do not match</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="register-btn">Sign Up</button>
                    <button type="button" class="btn btn-secondary btn-block" id="show-login-btn">Back to Sign In</button>

                </form>

                <!-- TASK F (login redesign v2) — "Secure Access" divider
                     row, per spec section 11. Sits outside all three forms
                     (like the footer below it) so it's always visible
                     regardless of which one is active. The flanking lines
                     are purely decorative (aria-hidden); "Secure Access"
                     itself is left readable to assistive tech since it's
                     a real trust/status message, not decoration. -->
                <div class="secure-access-row">
                    <span class="secure-access-line" aria-hidden="true"></span>
                    <span class="secure-access-text">Secure Access</span>
                    <span class="secure-access-line" aria-hidden="true"></span>
                </div>

                <!-- The "© 2026 … All rights reserved." footer that sat here
                     was removed from the sign-in page on request. -->
            </div>
        </section>

        <aside class="pitch-panel">
            <div class="pitch-content">
                <!-- TASK F (login redesign v2) — top tagline and bottom
                     campus-life text now flank .pitch-brand-group as its
                     siblings. .pitch-brand-group centers itself vertically
                     via its own `margin: auto 0` (see login.css), which
                     absorbs all of .pitch-content's free space and
                     naturally pins these two new siblings to the panel's
                     top and bottom edges -- no extra positioning needed. -->
                <p class="pitch-tagline">SERVICE &bull; FACILITIES &bull; A BETTER CAMPUS</p>

                <!-- Branding refinement: the logo, school name/subtitle, and
                     system title now live together in one grouped, centered
                     composition (.pitch-brand-group) instead of being split
                     to the top and bottom of the panel via the old
                     space-between layout. The system title is no longer
                     wrapped in its own bordered/boxed container -- it's
                     plain text under a thin divider, integrated with the
                     rest of the branding. See login.css's "LEFT PANEL
                     BRANDING REFINEMENT" block for the styling and for why
                     .pitch-image-wrap's old rules were left in place
                     (inert) elsewhere in that file. -->
                <div class="pitch-brand-group">
                    <div class="form-brand">
                        <img src="<?php echo htmlspecialchars(public_url('/frontend/assets/images/logo-seal.svg')); ?>" alt="School Logo">
                        <div class="form-brand-text">
                            <div class="form-brand-name">Philippine College of Science &amp; Technology</div>
                            <div class="form-brand-subtitle">Institutional Facility Management System</div>
                        </div>
                    </div>
                    <span class="pitch-divider" aria-hidden="true"></span>
                    <p class="pitch-system-title">PHILCST CENTRALIZED SCHOOL FACILITY MAINTENANCE REPORT MANAGEMENT SYSTEM</p>
                </div>

                <!-- The "A Better Learning Environment" script line that sat
                     here was removed from the sign-in page on request. -->
            </div>
        </aside>

        </div>
    </div>
</main>

<script>
    // TASK 98.2 fix: index.php is the one page that doesn't include
    // header.php, so window.SFMS_PUBLIC_URL was never defined here — api.js
    // relies on it (see api.js's baseURL) to resolve the app's subfolder
    // base path correctly. Definition mirrors header.php's exact
    // implementation so there is only one URL-resolution pattern in the app.
    window.SFMS_BASE_PATH = <?php echo json_encode(function_exists('sfms_public_base_path') ? sfms_public_base_path() : (defined('APP_PUBLIC_PATH') ? APP_PUBLIC_PATH : '')); ?>;
    window.SFMS_PUBLIC_URL = window.SFMS_PUBLIC_URL || function (path) {
        const basePath = String(window.SFMS_BASE_PATH || '').replace(/\/$/, '');
        const normalizedPath = '/' + String(path || '').replace(/^\/+/, '');
        return `${basePath}${normalizedPath}`;
    };
</script>
<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/utils.js?v=20260816')); ?>"></script>
<script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/api.js?v=20260927')); ?>"></script>

<script>
// Disable zoom and scroll jumping on all devices
document.documentElement.style.zoom = '';
document.body.style.zoom = '';

// Prevent pinch zoom on mobile
document.addEventListener('wheel', (event) => {
    if (event.ctrlKey) {
        event.preventDefault();
    }
}, { passive: false });

// Prevent keyboard zoom shortcuts
document.addEventListener('keydown', (event) => {
    if (!(event.ctrlKey || event.metaKey)) {
        return;
    }

    const blockedKeys = ['+', '=', '-', '_', '0'];
    if (blockedKeys.includes(event.key)) {
        event.preventDefault();
    }
});

// Prevent browser autofill from populating login fields
document.addEventListener('DOMContentLoaded', () => {
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const rememberMeInput = document.getElementById('remember_me');
    const registerInputIds = [
        'register_full_name',
        'register_username',
        'register_email',
        'register_password',
        'register_confirm_password',
        'forgot_email'
    ];
    if (usernameInput) {
        usernameInput.value = '';
        usernameInput.readOnly = true;
        usernameInput.addEventListener('focus', () => { usernameInput.readOnly = false; }, { once: true });
    }
    if (passwordInput) {
        passwordInput.value = '';
        passwordInput.readOnly = true;
        passwordInput.addEventListener('focus', () => { passwordInput.readOnly = false; }, { once: true });
    }

    if (usernameInput && rememberMeInput) {
        const rememberedUsername = localStorage.getItem('sfms_remembered_username');
        if (rememberedUsername) {
            usernameInput.value = rememberedUsername;
            rememberMeInput.checked = true;
        }

        rememberMeInput.addEventListener('change', () => {
            if (rememberMeInput.checked && usernameInput.value.trim()) {
                localStorage.setItem('sfms_remembered_username', usernameInput.value.trim());
            } else if (!rememberMeInput.checked) {
                localStorage.removeItem('sfms_remembered_username');
            }
        });

        usernameInput.addEventListener('input', () => {
            if (rememberMeInput.checked) {
                localStorage.setItem('sfms_remembered_username', usernameInput.value.trim());
            }
        });
    }

    registerInputIds.forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;

        input.value = '';
        input.readOnly = true;
        input.addEventListener('focus', () => { input.readOnly = false; }, { once: true });
    });

});

// FORGOT PASSWORD FIX: this used to be `window.API = window.API || { ... }`.
// api.js is loaded above (line ~254) and, since TASK 98.2 added
// `window.API = API;` at the end of that file, window.API was ALWAYS already
// truthy by the time this ran. The `||` therefore short-circuited and this
// entire object literal was silently discarded — including register() and
// forgotPasswordRequest(), which exist ONLY here and not in api.js.
// Result: clicking "Submit Request" threw
// `window.API.forgotPasswordRequest is not a function`, the handler's catch
// showed the generic "Could not submit request." message, and no HTTP request
// was ever sent (hence zero forgot_password_request entries in laravel.log).
// Merge instead of replace: whatever api.js already exposes still wins, so
// login() and every shared method behave exactly as before; only the methods
// that are genuinely missing get filled in. See the Object.assign() below.
const AUTH_PAGE_API = {
    baseURL: '<?php echo htmlspecialchars(rtrim(public_url('/api'), '/')); ?>',
    async login(username, password) {
        const response = await fetch(`${this.baseURL}/auth/login`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });

        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error('Server returned an invalid response. Please check API path/config.');
        }

        if (!data.success) {
            const error = new Error(data.message || 'Login failed');
            error.status = response.status;
            error.data = data.data || {};
            throw error;
        }
        return data;
    },
    async register(payload) {
        const response = await fetch(`${this.baseURL}/auth/register`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error('Server returned an invalid response. Please try again.');
        }
        if (!data.success) throw new Error(data.message);
        return data;
    },
    async forgotPasswordRequest(username) {
        const response = await fetch(`${this.baseURL}/auth/forgot_password_request`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: username })
        });
        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error('Server returned an invalid response. Please verify API routes and try again.');
        }
        if (!data.success) {
            const error = new Error(data.message || 'Failed to notify Admin');
            error.status = response.status;
            error.data = data.data || {};
            throw error;
        }
        return data;
    }
};

// Existing window.API members (from api.js) take precedence, so nothing that
// currently works changes. Only the methods api.js does not define —
// register() and forgotPasswordRequest() — are added from AUTH_PAGE_API.
// Both baseURL values resolve to the identical string
// ('/School_Facility_Maintenance_System/api'), so `this.baseURL` inside the
// restored methods is unaffected by api.js's copy winning.
window.API = Object.assign(AUTH_PAGE_API, window.API || {});

// Ensure Session is defined globally
window.Session = window.Session || {
    get(key) { 
        const v = localStorage.getItem(key);
        return v ? JSON.parse(v) : null;
    },
    set(key, value) { localStorage.setItem(key, JSON.stringify(value)); },
    clear() { localStorage.clear(); }
};


const loginForm = document.getElementById('login-form');
const registerForm = document.getElementById('register-form');
const forgotPasswordForm = document.getElementById('forgot-password-form');
const showRegisterBtn = document.getElementById('show-register-btn');
const showLoginBtn = document.getElementById('show-login-btn');
const showForgotPasswordBtn = document.getElementById('show-forgot-password-btn');
const forgotBackLoginBtn = document.getElementById('forgot-back-login-btn');
const notifySuperAdminBtn = document.getElementById('notify-super-admin-btn');
const showRegisterBtnPanel = document.getElementById('show-register-btn-panel');
const showLoginBtnPanel = document.getElementById('show-login-btn-panel');
const alertContainer = document.getElementById('alert-container');
const authCard = document.querySelector('.login-container .card');
const loginPasswordInput = document.getElementById('password');
const toggleLoginPasswordBtn = document.getElementById('toggle-login-password');
const loginUsernameInput = document.getElementById('username');
const loginBtn = document.getElementById('login-btn');
const rememberMeInput = document.getElementById('remember_me');

const LOGIN_LOCK_STORAGE_KEY_LEGACY = 'sfms_login_lock_until_ms';
const LOGIN_LOCK_DURATION_KEY_LEGACY = 'sfms_login_lock_duration_seconds';
const LOGIN_LOCK_STORAGE_KEY_PREFIX = 'sfms_login_lock_until_ms:';
const LOGIN_LOCK_DURATION_KEY_PREFIX = 'sfms_login_lock_duration_seconds:';
const LOGIN_DEFAULT_BUTTON_TEXT = loginBtn ? loginBtn.innerHTML : 'Sign In';
let loginLockInterval = null;

function normalizeLoginLockUsername(username) {
    return String(username || '').trim().toLowerCase();
}

function getLoginLockStorageKey(username) {
    return LOGIN_LOCK_STORAGE_KEY_PREFIX + normalizeLoginLockUsername(username);
}

function getLoginLockDurationKey(username) {
    return LOGIN_LOCK_DURATION_KEY_PREFIX + normalizeLoginLockUsername(username);
}

function formatCountdown(totalSeconds) {
    const safeSeconds = Math.max(0, Math.floor(totalSeconds));
    const minutes = String(Math.floor(safeSeconds / 60)).padStart(2, '0');
    const seconds = String(safeSeconds % 60).padStart(2, '0');
    return `${minutes}:${seconds}`;
}

function setLoginControlsDisabled(disabled) {
    if (loginUsernameInput) {
        loginUsernameInput.disabled = disabled;
        loginUsernameInput.readOnly = disabled;
    }

    if (loginPasswordInput) {
        loginPasswordInput.disabled = disabled;
        loginPasswordInput.readOnly = disabled;
    }

    if (rememberMeInput) {
        rememberMeInput.disabled = disabled;
    }

    if (toggleLoginPasswordBtn) {
        toggleLoginPasswordBtn.disabled = disabled;
    }

    if (loginBtn) {
        loginBtn.disabled = disabled;
    }
}

function clearLoginLockState(username = '') {
    const normalizedUsername = normalizeLoginLockUsername(username);
    if (normalizedUsername) {
        localStorage.removeItem(getLoginLockStorageKey(normalizedUsername));
        localStorage.removeItem(getLoginLockDurationKey(normalizedUsername));
    }

    // Cleanup legacy keys from older builds where lockout was global.
    localStorage.removeItem(LOGIN_LOCK_STORAGE_KEY_LEGACY);
    localStorage.removeItem(LOGIN_LOCK_DURATION_KEY_LEGACY);

    if (loginLockInterval) {
        window.clearInterval(loginLockInterval);
        loginLockInterval = null;
    }

    setLoginControlsDisabled(false);

    if (loginBtn) {
        loginBtn.innerHTML = LOGIN_DEFAULT_BUTTON_TEXT;
    }
}

function renderLoginLockAlert(countdown, progressPercent) {
    alertContainer.innerHTML = `
        <div class="alert alert-danger login-lock-alert" role="status" aria-live="polite">
            <div class="login-lock-header">
                <span class="login-lock-title">Login temporarily locked</span>
                <span class="login-lock-timer">${countdown}</span>
            </div>
            <div class="login-lock-message">Too many failed sign-in attempts. For security, sign-in is paused — you can try again when the countdown ends.</div>
            <div class="login-lock-progress" aria-hidden="true">
                <span class="login-lock-progress-fill" style="width: ${progressPercent}%;"></span>
            </div>
        </div>
    `;
}

function startLoginLockTimer(retryAfterSeconds, durationSeconds = null, username = '') {
    const normalizedUsername = normalizeLoginLockUsername(username);
    const parsedRetry = Math.max(1, Number(retryAfterSeconds) || 300);
    const parsedDuration = Math.max(1, Number(durationSeconds) || parsedRetry);
    const lockUntilMs = Date.now() + (parsedRetry * 1000);
    if (normalizedUsername) {
        localStorage.setItem(getLoginLockStorageKey(normalizedUsername), String(lockUntilMs));
        localStorage.setItem(getLoginLockDurationKey(normalizedUsername), String(parsedDuration));
    }

    if (loginLockInterval) {
        window.clearInterval(loginLockInterval);
        loginLockInterval = null;
    }

    const tick = () => {
        const remainingSeconds = Math.ceil((lockUntilMs - Date.now()) / 1000);

        if (remainingSeconds <= 0) {
            clearLoginLockState(normalizedUsername);
            alertContainer.innerHTML = '<div class="alert alert-success">Login lock has ended. You can try again now.</div>';
            return;
        }

        setLoginControlsDisabled(true);
        const countdown = formatCountdown(remainingSeconds);
        const progressPercent = Math.max(0, Math.min(100, (remainingSeconds / parsedDuration) * 100));
        if (loginBtn) {
            loginBtn.innerHTML = `Locked (${countdown})`;
        }

        renderLoginLockAlert(countdown, progressPercent);
    };

    tick();
    loginLockInterval = window.setInterval(tick, 1000);
}

function restoreLoginLockTimer() {
    const username = normalizeLoginLockUsername(loginUsernameInput ? loginUsernameInput.value : '');

    // Remove legacy global lock keys from older builds.
    localStorage.removeItem(LOGIN_LOCK_STORAGE_KEY_LEGACY);
    localStorage.removeItem(LOGIN_LOCK_DURATION_KEY_LEGACY);

    if (!username) {
        return;
    }

    const lockUntilMs = Number(localStorage.getItem(getLoginLockStorageKey(username)) || 0);
    if (!lockUntilMs) {
        return;
    }

    const remainingSeconds = Math.ceil((lockUntilMs - Date.now()) / 1000);
    const durationSeconds = Number(localStorage.getItem(getLoginLockDurationKey(username)) || remainingSeconds);
    if (remainingSeconds <= 0) {
        clearLoginLockState(username);
        return;
    }

    startLoginLockTimer(remainingSeconds, durationSeconds, username);
}

function updatePasswordToggleIcon(toggleButton, passwordInput) {
    if (!toggleButton || !passwordInput) return;

    const isVisible = passwordInput.type === 'text';
    toggleButton.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
    toggleButton.setAttribute('title', isVisible ? 'Hide password' : 'Show password');

    toggleButton.innerHTML = isVisible
        ? '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M3 3L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10.6 10.7C10.2 11.1 10 11.5 10 12C10 13.1 10.9 14 12 14C12.5 14 12.9 13.8 13.3 13.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 5.2C10.6 5.1 11.3 5 12 5C16.5 5 20.2 7.9 22 12C21.3 13.6 20.2 15 18.8 16.1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.3 6.3C4.6 7.5 3.2 9.4 2 12C3.8 16.1 7.5 19 12 19C13.7 19 15.3 18.6 16.6 17.9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>';
}

if (toggleLoginPasswordBtn && loginPasswordInput) {
    toggleLoginPasswordBtn.addEventListener('click', () => {
        loginPasswordInput.type = loginPasswordInput.type === 'password' ? 'text' : 'password';
        updatePasswordToggleIcon(toggleLoginPasswordBtn, loginPasswordInput);
    });

    updatePasswordToggleIcon(toggleLoginPasswordBtn, loginPasswordInput);
}

function showLoginForm() {
    registerForm.classList.add('form-hidden');
    forgotPasswordForm.classList.add('form-hidden');
    loginForm.classList.remove('form-hidden');
    alertContainer.innerHTML = '';
    if (authCard) {
        authCard.classList.remove('register-mode');
    }
}

function showRegisterForm() {
    loginForm.classList.add('form-hidden');
    forgotPasswordForm.classList.add('form-hidden');
    registerForm.classList.remove('form-hidden');
    alertContainer.innerHTML = '';
    if (authCard) {
        authCard.classList.add('register-mode');
    }

    registerForm.reset();

    ['register_full_name', 'register_username', 'register_email', 'register_password', 'register_confirm_password'].forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;

        input.value = '';
        input.readOnly = true;
        input.addEventListener('focus', () => { input.readOnly = false; }, { once: true });
    });

}

function showForgotPasswordForm() {
    loginForm.classList.add('form-hidden');
    registerForm.classList.add('form-hidden');
    forgotPasswordForm.classList.remove('form-hidden');
    alertContainer.innerHTML = '';

    forgotPasswordForm.reset();

    ['forgot_email'].forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;

        input.value = '';
        input.readOnly = true;
        input.addEventListener('focus', () => { input.readOnly = false; }, { once: true });
    });
}

if (showRegisterBtn) {
    showRegisterBtn.addEventListener('click', showRegisterForm);
}
if (showForgotPasswordBtn) {
    showForgotPasswordBtn.addEventListener('click', showForgotPasswordForm);
}
showLoginBtn.addEventListener('click', showLoginForm);
if (forgotBackLoginBtn) {
    forgotBackLoginBtn.addEventListener('click', showLoginForm);
}
if (showRegisterBtnPanel) {
    showRegisterBtnPanel.addEventListener('click', showRegisterForm);
}
if (showLoginBtnPanel) {
    showLoginBtnPanel.addEventListener('click', showLoginForm);
}
showLoginForm();

document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const username = document.getElementById('username').value.trim();
    const normalizedUsername = normalizeLoginLockUsername(username);
    const password = document.getElementById('password').value;
    const rememberMe = document.getElementById('remember_me')?.checked;
    const lockUntilMs = Number(localStorage.getItem(getLoginLockStorageKey(normalizedUsername)) || 0);
    if (lockUntilMs > Date.now()) {
        const remainingSeconds = Math.ceil((lockUntilMs - Date.now()) / 1000);
        const durationSeconds = Number(localStorage.getItem(getLoginLockDurationKey(normalizedUsername)) || remainingSeconds);
        startLoginLockTimer(remainingSeconds, durationSeconds, normalizedUsername);
        return;
    }

    alertContainer.innerHTML = '';

    if (!username || !password) {
        alertContainer.innerHTML = '<div class="alert alert-danger">Username and password are required</div>';
        return;
    }

    const originalText = loginBtn ? loginBtn.innerHTML : LOGIN_DEFAULT_BUTTON_TEXT;
    loginBtn.innerHTML = 'Logging in...';
    loginBtn.disabled = true;

    try {
        const response = await window.API.login(username, password);

        if (response.success) {
            clearLoginLockState(normalizedUsername);

            if (rememberMe) {
                localStorage.setItem('sfms_remembered_username', username);
            } else {
                localStorage.removeItem('sfms_remembered_username');
            }

            window.Session.set('user', response.data.user);
            alertContainer.innerHTML = '<div class="alert alert-success">Login successful! Redirecting...</div>';
            setTimeout(() => {
                const role = response.data.user.role;
                if (role === 'super_admin') {
                    window.location.href = '<?php echo htmlspecialchars(public_url('/frontend/pages/dashboard.php')); ?>';
                } else if (role === 'maintenance_admin') {
                    window.location.href = '<?php echo htmlspecialchars(public_url('/frontend/pages/maintenance-dashboard.php')); ?>';
                } else if (role === 'maintenance_staff') {
                    window.location.href = '<?php echo htmlspecialchars(public_url('/frontend/pages/staff-dashboard.php')); ?>';
                } else {
                    window.location.href = '<?php echo htmlspecialchars(public_url('/frontend/pages/dashboard.php')); ?>';
                }
            }, 500);
        } else {
            throw new Error(response.message || 'Login failed');
        }
    } catch (error) {
        console.error('Login error:', error);

        if (Number(error?.status) === 429) {
            const retryAfterSeconds = Number(error?.data?.retry_after_seconds || 60);
            // lockout_seconds is the full length of a lock that just started
            // (drives the progress bar); a lock already running only reports
            // the time left.
            const lockoutSeconds = Number(error?.data?.lockout_seconds || retryAfterSeconds);
            startLoginLockTimer(retryAfterSeconds, lockoutSeconds, normalizedUsername);
            return;
        }

        const safeMessage = String(error?.message || 'Login failed')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        // Spam protection warning: how many tries remain before the lock.
        const attemptsRemaining = Number(error?.data?.attempts_remaining);
        let attemptsWarning = '';
        if (Number.isFinite(attemptsRemaining) && attemptsRemaining > 0 && attemptsRemaining <= 3) {
            const nextLock = Number(error?.data?.next_lockout_seconds || 60);
            const lockText = nextLock % 60 === 0 ? `${nextLock / 60} minute${nextLock === 60 ? '' : 's'}` : `${nextLock} seconds`;
            attemptsWarning = `<div class="login-attempts-warning">${attemptsRemaining} attempt${attemptsRemaining === 1 ? '' : 's'} left before sign-in is locked for ${lockText}.</div>`;
        }
        alertContainer.innerHTML = `<div class="alert alert-danger">${safeMessage}${attemptsWarning}</div>`;
        if (loginBtn) {
            loginBtn.innerHTML = originalText;
            loginBtn.disabled = false;
        }
    }
});

restoreLoginLockTimer();

// ── Phones: keep the focused field visible above the on-screen keyboard ──
// login.css already has a "keyboard open" mode (body.keyboard-open collapses
// the logo panel and pads the form by --kb-offset), but nothing turned it
// on, so the keyboard covered the password field. While a field in any of
// the auth forms has focus (phone/tablet widths only), this switches that
// mode on and scrolls the field into the part of the screen the keyboard
// leaves visible. visualViewport gives the visible height on both Android
// and iPhone (iOS keeps the layout viewport full height under the keyboard).
(function keepFocusedFieldAboveKeyboard() {
    const isPhoneLayout = () => window.matchMedia('(max-width: 900px)').matches;
    const vv = window.visualViewport;
    const FIELD_SELECTOR = '#login-form input, #forgot-password-form input, #register-form input, #register-form select';
    let activeField = null;
    let blurTimer = null;

    function keyboardHeight() {
        if (!vv) return 0;
        // Part of the layout viewport currently hidden behind the keyboard.
        return Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    }

    function revealField() {
        if (!activeField || !isPhoneLayout()) return;
        document.body.style.setProperty('--kb-offset', keyboardHeight() + 'px');

        const visibleTop = vv ? vv.offsetTop : 0;
        const visibleHeight = vv ? vv.height : window.innerHeight;
        const rect = activeField.getBoundingClientRect();
        // Aim to place the field about a quarter of the way down the visible
        // area, so the fields below it and the Sign In button show too.
        const target = visibleTop + visibleHeight * 0.25;
        const outOfView = rect.top < visibleTop + 12 || rect.bottom > visibleTop + visibleHeight - 12;
        if (outOfView || rect.top > target + 40) {
            window.scrollBy({ top: rect.top - target, behavior: 'smooth' });
        }
    }

    document.addEventListener('focusin', (event) => {
        const field = event.target.closest && event.target.closest(FIELD_SELECTOR);
        if (!field || !isPhoneLayout()) return;
        clearTimeout(blurTimer);
        activeField = field;
        document.body.classList.add('keyboard-open');
        // Wait for the keyboard animation and the panel collapse to settle.
        setTimeout(revealField, 320);
    });

    document.addEventListener('focusout', (event) => {
        if (!event.target.closest || !event.target.closest(FIELD_SELECTOR)) return;
        // Moving between fields fires focusout then focusin; only leave the
        // mode when focus has really left the forms.
        blurTimer = setTimeout(() => {
            if (document.activeElement && document.activeElement.closest && document.activeElement.closest(FIELD_SELECTOR)) return;
            activeField = null;
            document.body.classList.remove('keyboard-open');
            document.body.style.removeProperty('--kb-offset');
        }, 150);
    });

    // The keyboard can finish opening (or change height) after focus.
    if (vv) {
        vv.addEventListener('resize', () => { if (activeField) revealField(); });
    }
})();

// Validation helper functions
function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function isValidUsername(username) {
    return /^[a-z][a-z_]{2,49}$/.test(username);
}

function getPasswordStrength(password) {
    if (!password) return null;
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;

    if (strength <= 2) return 'weak';
    if (strength <= 4) return 'fair';
    return 'strong';
}

function updateFieldValidation(fieldId, isValid) {
    const groupMap = {
        register_full_name: 'register-name-group',
        register_username: 'register-username-group',
        register_email: 'register-email-group',
        register_password: 'register-password-group',
        register_confirm_password: 'register-confirm-password-group'
    };
    const group = document.getElementById(groupMap[fieldId] || (fieldId + '-group'));
    if (!group) return;

    if (isValid === true) {
        group.classList.remove('form-field-error');
        group.classList.add('form-field-valid');
    } else if (isValid === false) {
        group.classList.remove('form-field-valid');
        group.classList.add('form-field-error');
    } else {
        group.classList.remove('form-field-error', 'form-field-valid');
    }
}

function isValidFullName(name) {
    // Same rule as backend: letters (with accents), spaces, apostrophes, hyphens, periods
    return /^(?=.*\p{L})[\p{L} .'-]+$/u.test(name);
}

function updatePasswordStrength() {
    const password = document.getElementById('register_password').value;
    const meter = document.getElementById('register-password-meter');
    const bar = document.getElementById('register-password-bar');
    const text = document.getElementById('register-password-text');

    if (!password) {
        meter.classList.remove('active');
        bar.className = 'password-strength-bar';
        text.textContent = '';
        updateFieldValidation('register_password', null);
        return;
    }

    const strength = getPasswordStrength(password);
    meter.classList.add('active');
    bar.className = 'password-strength-bar ' + (strength || '');
    text.className = 'password-strength-text ' + (strength || '');
    text.textContent = strength ? 'Password Strength: ' + strength.charAt(0).toUpperCase() + strength.slice(1) : '';

    const isValid = password.length >= 8 && strength !== 'weak';
    updateFieldValidation('register_password', isValid);
}

function validateRegisterField(fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return;

    let isValid = null;

    if (fieldId === 'register_full_name') {
        if (field.value && field.value.trim().length > 0) {
            const name = field.value.trim();
            isValid = name.length >= 2 && isValidFullName(name);
        }
    } else if (fieldId === 'register_username') {
        if (field.value) {
            isValid = isValidUsername(field.value.trim().toLowerCase());
        }
    } else if (fieldId === 'register_email') {
        if (field.value) {
            isValid = isValidEmail(field.value.trim());
        }
    } else if (fieldId === 'register_confirm_password') {
        const password = document.getElementById('register_password').value;
        if (field.value) {
            isValid = field.value === password && password.length >= 8;
        }
    }

    updateFieldValidation(fieldId, isValid);
}

// Add real-time validation listeners to register form
document.getElementById('register_full_name').addEventListener('blur', () => validateRegisterField('register_full_name'));
document.getElementById('register_full_name').addEventListener('change', () => validateRegisterField('register_full_name'));

document.getElementById('register_username').addEventListener('blur', () => validateRegisterField('register_username'));
document.getElementById('register_username').addEventListener('change', () => validateRegisterField('register_username'));

document.getElementById('register_email').addEventListener('blur', () => validateRegisterField('register_email'));
document.getElementById('register_email').addEventListener('change', () => validateRegisterField('register_email'));

document.getElementById('register_password').addEventListener('input', updatePasswordStrength);
document.getElementById('register_password').addEventListener('blur', () => validateRegisterField('register_password'));

document.getElementById('register_confirm_password').addEventListener('input', () => validateRegisterField('register_confirm_password'));
document.getElementById('register_confirm_password').addEventListener('blur', () => validateRegisterField('register_confirm_password'));

document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    const fullName = document.getElementById('register_full_name').value.trim();
    const username = document.getElementById('register_username').value.trim().toLowerCase();
    const email = document.getElementById('register_email').value.trim();
    const password = document.getElementById('register_password').value;
    const confirmPassword = document.getElementById('register_confirm_password').value;
    const registerBtn = document.getElementById('register-btn');

    alertContainer.innerHTML = '';

    // Validate all fields
    let hasErrors = false;
    const errorMessages = [];

    if (!fullName || fullName.length < 2 || !isValidFullName(fullName)) {
        updateFieldValidation('register_full_name', false);
        hasErrors = true;
        errorMessages.push('Name must contain only letters, spaces, apostrophes, and hyphens (no numbers).');
    } else {
        updateFieldValidation('register_full_name', true);
    }

    if (!isValidUsername(username)) {
        updateFieldValidation('register_username', false);
        hasErrors = true;
        errorMessages.push('Username must be 3-50 characters: letters and underscores only (e.g. juan_dela_cruz).');
    } else {
        updateFieldValidation('register_username', true);
    }

    if (!isValidEmail(email)) {
        updateFieldValidation('register_email', false);
        hasErrors = true;
        errorMessages.push('Please enter a valid email address.');
    } else {
        updateFieldValidation('register_email', true);
    }

    const strength = getPasswordStrength(password);
    if (password.length < 8 || strength === 'weak') {
        updateFieldValidation('register_password', false);
        hasErrors = true;
        errorMessages.push('Password must be at least 8 characters and not weak.');
    } else {
        updateFieldValidation('register_password', true);
    }

    if (password !== confirmPassword) {
        updateFieldValidation('register_confirm_password', false);
        hasErrors = true;
        errorMessages.push('Passwords do not match.');
    } else {
        updateFieldValidation('register_confirm_password', true);
    }

    if (hasErrors) {
        alertContainer.innerHTML = `<div class="alert alert-danger">${errorMessages[0] || 'Please fix the errors above'}</div>`;
        return;
    }

    const originalText = registerBtn.innerHTML;
    registerBtn.innerHTML = 'Creating account...';
    registerBtn.disabled = true;

    try {
        await window.API.register({
            full_name: fullName,
            username,
            email,
            password
        });

        alertContainer.innerHTML = '<div class="alert alert-success">Registration submitted. Please wait for Administrator approval.</div>';
        registerForm.reset();
        document.getElementById('register-name-group').classList.remove('form-field-valid', 'form-field-error');
        document.getElementById('register-username-group').classList.remove('form-field-valid', 'form-field-error');
        document.getElementById('register-email-group').classList.remove('form-field-valid', 'form-field-error');
        document.getElementById('register-password-group').classList.remove('form-field-valid', 'form-field-error');
        document.getElementById('register-confirm-password-group').classList.remove('form-field-valid', 'form-field-error');
        showLoginForm();
    } catch (error) {
        console.error('Register error:', error);
        alertContainer.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
    } finally {
        registerBtn.innerHTML = originalText;
        registerBtn.disabled = false;
    }
});

if (notifySuperAdminBtn) {
    notifySuperAdminBtn.addEventListener('click', async () => {
        const forgotUsernameInput = document.getElementById('forgot_email');
        const identifier = forgotUsernameInput ? forgotUsernameInput.value.trim() : '';

        if (!identifier || identifier.length < 3) {
            alertContainer.innerHTML = '<div class="alert alert-danger">Please enter your username.</div>';
            return;
        }

        const originalText = notifySuperAdminBtn.innerHTML;
        notifySuperAdminBtn.disabled = true;
        notifySuperAdminBtn.innerHTML = 'Submitting...';

        try {
            const response = await window.API.forgotPasswordRequest(identifier);
            alertContainer.innerHTML = `<div class="alert alert-success" style="text-align:center;padding:16px;">
                <strong>&#10003; Request Submitted Successfully</strong><br>
                <span>${response.message || 'Your Administrator will review and reset your password shortly.'}</span>
            </div>`;
            setTimeout(() => { showLoginForm(); }, 4000);
        } catch (error) {
            alertContainer.innerHTML = '<div class="alert alert-danger">Could not submit request. Please try again or contact your Administrator directly.</div>';
        } finally {
            notifySuperAdminBtn.disabled = false;
            notifySuperAdminBtn.innerHTML = originalText;
        }
    });
}
</script>

</body>
</html>
