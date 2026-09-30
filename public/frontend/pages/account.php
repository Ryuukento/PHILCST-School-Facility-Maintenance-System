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
if (!isset($_SESSION['user']) && !isset($_SESSION['auth_user']) && !isset($user)) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$pageTitle = 'Account - SFMS';
$currentUser = $user ?? ($_SESSION['user'] ?? []);
$forceProfileUpdate = !empty($currentUser['force_profile_update']);
include __DIR__ . '/../includes/header.php';
?>

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

                                <div class="settings-form-group settings-span-2">
                                    <label for="profile_picture" class="settings-label">Profile Picture</label>
                                    <input type="file" id="profile_picture" name="profile_picture" class="form-control settings-input" accept="image/*">
                                    <div class="settings-avatar-row">
                                        <?php if (!empty($user['avatar'])): ?>
                                            <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="Profile Preview" id="settings-avatar-preview" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #d7c0f2;">
                                        <?php else: ?>
                                            <div id="settings-avatar-preview-fallback" style="width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: #8A2BE2;"><?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?></div>
                                        <?php endif; ?>
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
        <p>This account was created by an Administrator. Before continuing, please update your Full Name and set a new Password.</p>
        <button type="button" id="force-setup-continue" class="btn btn-primary settings-primary-btn">Update Now</button>
    </div>
</div>
<?php endif; ?>

<div id="settings-toast" class="settings-toast" aria-live="polite"></div>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/settings.inline.css?v=20260921-2">

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

    if (userData.avatar) {
        profileContainer.innerHTML = `<img src="${userData.avatar}" alt="avatar" class="avatar-img" id="header-user-avatar" />`;
    } else {
        const initial = (userData.full_name || 'U').trim().charAt(0).toUpperCase();
        profileContainer.innerHTML = `<div class="avatar-circle" data-user-avatar id="header-user-avatar-fallback">${initial}</div>`;
    }
}

function renderSettingsAvatar(userData) {
    const previewImg = document.getElementById('settings-avatar-preview');
    const fallback = document.getElementById('settings-avatar-preview-fallback');

    if (userData.avatar) {
        if (previewImg) {
            previewImg.src = userData.avatar;
        } else if (fallback && fallback.parentNode) {
            fallback.outerHTML = `<img src="${userData.avatar}" alt="Profile Preview" id="settings-avatar-preview" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #d7c0f2;">`;
        }
    } else {
        const initial = (userData.full_name || 'U').trim().charAt(0).toUpperCase();
        if (fallback) {
            fallback.textContent = initial;
        }
    }
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

document.getElementById('account-settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fullName = document.getElementById('full_name').value.trim();
    const username = document.getElementById('username')?.value.trim().toLowerCase() || '';
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value.trim();

    if (!fullName) {
        showSettingsToast('Please enter your full name', 'error');
        return;
    }

    if (username && !/^[a-z0-9_]{3,50}$/.test(username)) {
        showSettingsToast('Username must be 3-50 characters: lowercase letters, numbers, underscores only.', 'error');
        return;
    }

    if (forceProfileSetup && (!currentPassword || !newPassword)) {
        showSettingsToast('For first-time setup, current and new password are required', 'error');
        return;
    }

    const form = document.getElementById('account-settings-form');
    const submitButton = form.querySelector('button[type="submit"]');
    const originalLabel = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = 'Saving...';

    try {
        const formData = new FormData(form);
        const response = await API.updateProfile(formData);

        if (!response.success) {
            throw new Error(response.message || 'Failed to save account settings');
        }

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

        if (forceProfileSetup) {
            // TASK 55 — a forced first-time (or admin-reset) password change
            // must not just continue into the same session: the whole point
            // of forcing it is to prove the account holder now knows the new
            // password, not the one an Administrator set/generated for them.
            // Log the session out server-side and native $_SESSION alike,
            // then send them to the login form to re-authenticate with it.
            showSettingsToast('Password changed. Logging you out...');
            setTimeout(async () => {
                try {
                    await API.logout();
                } catch (error) {
                    console.error('Post-setup logout error:', error);
                }
                window.location.href = window.SFMS_PUBLIC_URL('/frontend/pages/index.php?password_changed=1');
            }, 1200);
            return;
        }

        showSettingsToast('Account settings saved successfully!');
    } catch (error) {
        console.error('Save account settings error:', error);
        showSettingsToast(error.message || 'Failed to save account settings', 'error');
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
