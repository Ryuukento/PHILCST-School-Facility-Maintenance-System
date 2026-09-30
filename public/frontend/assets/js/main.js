/**
 * Page initialization scripts
 */

/**
 * LIGHT IS THE ONLY THEME.
 *
 * This used to be a full light/dark manager: it read a saved preference from
 * three localStorage keys, resolved an 'auto' mode against
 * prefers-color-scheme, subscribed to OS theme changes, rendered a sun/moon
 * icon and bound the header toggle. All of that is gone, because a theme the
 * user cannot choose does not need a chooser.
 *
 * What is deliberately NOT done here:
 *
 *  - Saved preferences are not read. A user who once chose dark has that value
 *    still sitting in localStorage; ignoring it is precisely what makes light
 *    unconditional for them.
 *  - Saved preferences are not deleted either. Clearing them would be a write
 *    on every page load to no visible end, and keeping them costs nothing now
 *    that nothing reads them.
 *  - prefers-color-scheme is not consulted, so an OS or browser set to dark no
 *    longer influences the page.
 *
 * applyMode() and init() are kept as a tiny shim rather than deleted outright
 * because main.js is loaded on every legacy page and window.ThemeManager is a
 * global; any straggling caller now gets a harmless no-op that re-asserts
 * light instead of a TypeError.
 */
const ThemeManager = {
    mode: 'light',

    applyMode() {
        const root = document.documentElement;

        root.setAttribute('data-theme-mode', 'light');
        root.setAttribute('data-theme-resolved', 'light');
        root.setAttribute('data-theme', 'light');
        root.style.colorScheme = 'light';
    },

    init() {
        this.applyMode();
    }
};

window.ThemeManager = window.ThemeManager || ThemeManager;

const AccessibilityManager = {
    storageKey: 'sfms_settings_font_size',

    getSavedFontSize() {
        const size = localStorage.getItem(this.storageKey);
        return ['small', 'medium', 'large'].includes(size) ? size : 'medium';
    },

    getScale(size) {
        const scaleMap = {
            small: 0.92,
            medium: 1,
            large: 1.12
        };

        return Object.prototype.hasOwnProperty.call(scaleMap, size) ? scaleMap[size] : scaleMap.medium;
    },

    applyFontSize(size, options = {}) {
        const { persist = true } = options;
        const safeSize = ['small', 'medium', 'large'].includes(size) ? size : 'medium';
        const scale = this.getScale(safeSize);
        const root = document.documentElement;

        root.setAttribute('data-font-size-mode', safeSize);
        root.style.setProperty('--ui-font-scale', String(scale));
        root.style.setProperty('--ui-zoom', '1');

        if (persist) {
            localStorage.setItem(this.storageKey, safeSize);
        }
    },

    init() {
        this.applyFontSize(this.getSavedFontSize(), { persist: false });
    }
};

window.AccessibilityManager = window.AccessibilityManager || AccessibilityManager;

function updateGlobalHeaderKicker() {
    const greetingEl = document.getElementById('headerProfileGreeting');
    const subtextEl = document.getElementById('headerProfileSubtext');
    const legacyKickerEl = document.getElementById('headerDashboardKicker');
    if (!greetingEl && !subtextEl && !legacyKickerEl) return;

    const now = new Date();
    const hour = now.getHours();
    const user = Session.get('user');
    const userName = (user && (user.full_name || user.username)) ? String(user.full_name || user.username) : 'User';

    let greeting = 'Good evening';
    if (hour < 12) {
        greeting = 'Good morning';
    } else if (hour < 18) {
        greeting = 'Good afternoon';
    }

    const dateText = new Intl.DateTimeFormat('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric'
    }).format(now);

    const timeText = new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit'
    }).format(now);

    if (greetingEl) {
        greetingEl.textContent = `${greeting}, ${userName}`;
    }

    if (subtextEl) {
        subtextEl.textContent = `${dateText} · ${timeText}`;
    }

    if (legacyKickerEl) {
        legacyKickerEl.textContent = `${dateText} · ${timeText} ${greeting}`;
        legacyKickerEl.classList.add('is-visible');
    }
}

window.updateGlobalHeaderKicker = window.updateGlobalHeaderKicker || updateGlobalHeaderKicker;

document.addEventListener('DOMContentLoaded', function() {
    AccessibilityManager.init();
    ThemeManager.init();
    initializeNavigation();
    initializeModals();
    initializeDropdowns();
    updateGlobalHeaderKicker();
    setInterval(updateGlobalHeaderKicker, 60000);
});

/**
 * Initialize navigation
 */
function initializeNavigation() {
    // Check if user is logged in
    const user = Session.get('user');
    
    if (!user && window.location.pathname !== '/index.php' && !window.location.pathname.includes('login')) {
        window.location.href = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/index.php') : '/frontend/pages/index.php';
        return;
    }
    
    // Set up logout buttons (with confirmation modal)
    const logoutButtons = document.querySelectorAll('[data-logout]');
    const logoutModalId = 'global-logout-modal';
    const logoutConfirmBtn = document.getElementById('global-logout-confirm');
    const logoutCancelBtn = document.getElementById('global-logout-cancel');

    async function performLogout() {
        try {
            if (window.API && typeof API.logout === 'function') {
                await API.logout();
            } else if (window.api && typeof api.logout === 'function') {
                await api.logout();
            }
        } catch (error) {
            // Ignore API failures and fall back to server-side logout.
        } finally {
            Session.clear();
            window.location.href = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/logout.php') : '/frontend/pages/logout.php';
        }
    }

    logoutButtons.forEach((logoutBtn) => {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (document.getElementById(logoutModalId)) {
                UI.toggleModal(logoutModalId, true);
                return;
            }
            performLogout();
        });
    });

    if (logoutConfirmBtn) {
        logoutConfirmBtn.addEventListener('click', async () => {
            UI.toggleModal(logoutModalId, false);
            await performLogout();
        });
    }

    if (logoutCancelBtn) {
        logoutCancelBtn.addEventListener('click', () => {
            UI.toggleModal(logoutModalId, false);
        });
    }
    
    // Update user display
    if (user) {
        const userNameEl = document.querySelector('[data-user-name]');
        const userAvatarEl = document.querySelector('[data-user-avatar]');
        
        if (userNameEl) userNameEl.textContent = user.full_name;
        if (userAvatarEl) userAvatarEl.textContent = user.full_name.charAt(0).toUpperCase();
    }
}

/**
 * Initialize modals
 */
function initializeModals() {
    // Close modal when clicking close button
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modal = e.target.closest('.modal');
            UI.toggleModal(modal.id, false);
        });
    });
    
    // Close modal when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                UI.toggleModal(modal.id, false);
            }
        });
    });

    // Escape closes the topmost open modal (system alert/confirm or a .modal.show),
    // and Tab is trapped inside it while it's open (DESIGN_SYSTEM.md §11/§18).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const systemModal = document.querySelector('.system-modal-overlay');
            if (systemModal) {
                systemModal.click();
                return;
            }

            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                UI.toggleModal(openModal.id, false);
            }
            return;
        }

        if (e.key === 'Tab') {
            const activeModal = document.querySelector('.modal.show, .system-modal-overlay');
            if (!activeModal) return;

            const focusableEls = activeModal.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusableEls.length) return;

            const first = focusableEls[0];
            const last = focusableEls[focusableEls.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
}

/**
 * Initialize dropdowns
 */
function initializeDropdowns() {
    document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            e.preventDefault();
            const dropdown = e.target.closest('.dropdown');
            const menu = dropdown.querySelector('.dropdown-menu');
            
            // Close other dropdowns
            document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
            
            menu.classList.toggle('show');
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                m.classList.remove('show');
            });
        }
    });
}

/**
 * Add CSS for toast notifications
 */
const toastStyles = `
<style>
    .toast {
        background-color: white;
        padding: 1rem 1.5rem;
        border-radius: var(--radius-md, 10px);
        box-shadow: var(--shadow-lg, 0 12px 28px rgba(15, 23, 42, 0.12));
        margin-bottom: 0.5rem;
        border-left: 4px solid;
        animation: slideIn var(--duration-base, 220ms) var(--motion-ease, ease);
    }
    
    .toast-success {
        background-color: #ecfdf5;
        color: #065f46;
        border-color: #22c55e;
    }
    
    .toast-danger {
        background-color: #fef2f2;
        color: #7f1d1d;
        border-color: #ef4444;
    }
    
    .toast-warning {
        background-color: #fffbeb;
        color: #78350f;
        border-color: #f59e0b;
    }
    
    .toast-info {
        background-color: #eff6ff;
        color: #0c2340;
        border-color: #3b82f6;
    }
    
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: inherit;
    }
</style>
`;

document.head.insertAdjacentHTML('beforeend', toastStyles);
