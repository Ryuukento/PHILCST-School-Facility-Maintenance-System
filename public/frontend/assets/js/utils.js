/**
 * UI Helper Functions
 */

class UI {

        /**
         * Escape a value for safe interpolation into innerHTML template strings.
         */
        static escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        /**
         * TASK 7.1 — render a registry icon for the shared system modals.
         *
         * systemConfirm() and systemAlert() previously inlined entity-encoded
         * emoji. This routes them through the same UIIcons registry the rest of
         * the UI uses, at the size .system-modal-icon was already drawing its
         * glyph at (48px chip, 22px font).
         *
         * If ui-icons.js has not loaded, this returns '' rather than falling
         * back to an emoji: the modal's own message carries the meaning, so an
         * empty decorative chip is the safe degradation. It must never throw —
         * these two modals are the app-wide replacement for window.confirm()
         * and window.alert().
         */
        static _modalIcon(name) {
            if (!name || !window.UIIcons || typeof window.UIIcons.svg !== 'function') {
                return '';
            }
            return window.UIIcons.svg(name, { size: 26 });
        }

        /**
         * Show a system modal confirmation (async)
         *
         * UI_BROWSER_DIALOG_REPLACEMENT — this is the application's single
         * reusable replacement for window.confirm(). `variant` selects both
         * the card's accent color and the confirm ("Yes") button's color, so
         * callers can match the existing button-color convention (Approve
         * dispatch/Confirm = green, Delete = red, Warning-only = yellow).
         * Defaults to 'danger' to preserve the exact visual behavior every
         * existing call site had before `variant` was introduced.
         */
        static systemConfirm(message, yesLabel = 'Yes', noLabel = 'No', variant = 'danger') {
            return new Promise((resolve) => {
                // Remove existing modal if any
                const existing = document.getElementById('system-confirm-modal');
                if (existing) existing.remove();

                const previouslyFocused = document.activeElement;

                const knownVariants = ['danger', 'success', 'warning', 'primary'];
                const safeVariant = knownVariants.includes(variant) ? variant : 'danger';
                const yesBtnClass = { danger: 'btn-danger', success: 'btn-success', warning: 'btn-warning', primary: 'btn-primary' }[safeVariant];
                // TASK 7.1 — was entity-encoded emoji ('&#9888;' ⚠, '&#10003;' ✓,
                // '&#10068;' ❔). Same variant->glyph mapping, same four keys, same
                // fallback behaviour; only the glyph source changes. The icon is
                // decorative (the modal message states the meaning) and its wrapper
                // keeps aria-hidden below, so screen-reader output is unchanged.
                const icon = UI._modalIcon({ danger: 'alert-triangle', success: 'check', warning: 'alert-triangle', primary: 'help-circle' }[safeVariant]);

                const modal = document.createElement('div');
                modal.id = 'system-confirm-modal';
                modal.className = 'system-modal-overlay';
                modal.setAttribute('role', 'alertdialog');
                modal.setAttribute('aria-modal', 'true');
                modal.innerHTML = `
                    <div class="system-modal-card system-modal-${safeVariant}">
                        <div class="system-modal-icon" aria-hidden="true">${icon}</div>
                        <div class="system-modal-message">${message}</div>
                        <div class="system-modal-actions">
                            <button id="systemConfirmNo" class="btn btn-secondary">${noLabel}</button>
                            <button id="systemConfirmYes" class="btn ${yesBtnClass}">${yesLabel}</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);

                const finish = (result) => {
                    modal.remove();
                    if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                        previouslyFocused.focus();
                    }
                    resolve(result);
                };

                document.getElementById('systemConfirmYes').onclick = () => finish(true);
                document.getElementById('systemConfirmNo').onclick = () => finish(false);
                modal.addEventListener('click', (e) => { if (e.target === modal) finish(false); });
                document.getElementById('systemConfirmYes').focus();
            });
        }

        /**
         * Show a system modal alert (async)
         *
         * UI_BROWSER_DIALOG_REPLACEMENT — this is the application's single
         * reusable replacement for window.alert(). `type` covers the four
         * remaining dialog categories (info/success/warning/danger=error).
         */
        static systemAlert(message, type = 'info', okLabel = 'OK') {
            return new Promise((resolve) => {
                // Remove existing modal if any
                const existing = document.getElementById('system-alert-modal');
                if (existing) existing.remove();

                const previouslyFocused = document.activeElement;

                const knownTypes = ['info', 'success', 'warning', 'danger'];
                const safeType = knownTypes.includes(type) ? type : 'info';
                // TASK 7.1 — was entity-encoded ('&#8505;' ℹ, '&#10003;' ✓,
                // '&#9888;' ⚠, '&#10007;' ✗). Mapping and keys unchanged.
                const icon = UI._modalIcon({ info: 'info', success: 'check', warning: 'alert-triangle', danger: 'x' }[safeType]);

                const modal = document.createElement('div');
                modal.id = 'system-alert-modal';
                modal.className = 'system-modal-overlay';
                modal.setAttribute('role', 'alertdialog');
                modal.setAttribute('aria-modal', 'true');
                modal.innerHTML = `
                    <div class="system-modal-card system-modal-${safeType}">
                        <div class="system-modal-icon" aria-hidden="true">${icon}</div>
                        <div class="system-modal-message">${message}</div>
                        <div class="system-modal-actions">
                            <button id="systemAlertOk" class="btn btn-primary">${okLabel}</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);

                const finish = () => {
                    modal.remove();
                    if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                        previouslyFocused.focus();
                    }
                    resolve();
                };

                document.getElementById('systemAlertOk').onclick = finish;
                modal.addEventListener('click', (e) => { if (e.target === modal) finish(); });
                document.getElementById('systemAlertOk').focus();
            });
        }
    /**
     * Show a toast notification
     */
    static toast(message, type = 'info', duration) {
        const toastContainer = document.getElementById('toast-container') || this.createToastContainer();

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        toastContainer.appendChild(toast);

        // DESIGN_SYSTEM.md §25: success/info auto-dismiss at --duration-toast-visible (4000ms);
        // warning/danger persist longer (8000ms) since they need more attention.
        const autoDismissMs = duration ?? ((type === 'danger' || type === 'warning') ? 8000 : 4000);
        setTimeout(() => {
            toast.remove();
        }, autoDismissMs);
    }

    /**
     * Create toast container if it doesn't exist
     */
    static createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: var(--z-toast, 500);
            max-width: 400px;
        `;
        document.body.appendChild(container);
        return container;
    }

    /**
     * Show/hide a modal
     */
    static toggleModal(modalId, show = true) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        if (show) {
            this._lastFocused = document.activeElement;
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            const focusable = modal.querySelector(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable) focusable.focus();
        } else {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            if (this._lastFocused && typeof this._lastFocused.focus === 'function') {
                this._lastFocused.focus();
            }
            this._lastFocused = null;
        }
    }
    
    /**
     * Show alert
     */
    static alert(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = `
            <div class="flex-between">
                <span>${message}</span>
                <button class="close-btn" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        
        const container = document.querySelector('.alert-container') || document.body;
        container.insertBefore(alertDiv, container.firstChild);
    }
    
    /**
     * Show loading state
     */
    static showLoading(selector) {
        const element = document.querySelector(selector);
        if (element) {
            element.innerHTML = '<div class="spinner"></div>';
        }
    }
    
    /**
     * Format date for display
     */
    static formatDate(date, format = 'short') {
        const options = {
            short: { year: 'numeric', month: 'short', day: 'numeric' },
            long: { year: 'numeric', month: 'long', day: 'numeric' },
            full: { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }
        };
        
        return new Date(date).toLocaleDateString('en-US', options[format]);
    }
    
    /**
     * Get priority badge HTML
     */
    static getPriorityBadge(priority) {
        // IT-expert priority-color correction: Critical=Red, High=Orange,
        // Medium=Yellow, Low=Blue (standardized app-wide). Uses dedicated
        // badge-<priority> classes (see color-scheme.css) instead of the
        // generic badge-info/warning/danger classes shared with other
        // unrelated statuses.
        const colors = {
            'low': 'badge-low',
            'medium': 'badge-medium',
            'high': 'badge-high',
            'critical': 'badge-critical',
            'urgent': 'badge-urgent'
        };

        return `<span class="badge ${colors[priority] || 'badge-low'}">${priority.toUpperCase()}</span>`;
    }
    
    /**
     * Get status badge HTML
     */
    static getStatusBadge(status) {
        const colors = {
            'submitted': 'badge-submitted',
            'assigned': 'badge-assigned',
            'in_progress': 'badge-in-progress',
            'completed': 'badge-completed',
            'cancelled': 'badge-report-cancelled',
            'closed': 'badge-report-closed',
            'draft': 'badge-submitted'
        };

        const labels = {
            'submitted': 'Submitted',
            'assigned': 'Assigned',
            'in_progress': 'In Progress',
            'completed': 'Completed',
            'cancelled': 'Cancelled',
            'closed': 'Closed',
            'draft': 'Draft'
        };

        return `<span class="badge ${colors[status] || 'badge-submitted'}">${labels[status] || status}</span>`;
    }
}

/**
 * Form validation helper
 */
class FormValidator {
    /**
     * Validate form and show errors
     */
    static validate(formId, rules) {
        const form = document.getElementById(formId);
        if (!form) return false;
        
        let isValid = true;
        const errors = {};
        
        // Clear previous errors
        form.querySelectorAll('.form-error').forEach(el => el.remove());
        
        // Validate each field
        for (const [fieldName, fieldRules] of Object.entries(rules)) {
            const field = form.querySelector(`[name="${fieldName}"]`);
            if (!field) continue;
            
            const value = field.value.trim();
            
            for (const rule of fieldRules.split('|')) {
                if (rule === 'required' && !value) {
                    errors[fieldName] = `${fieldName} is required`;
                    isValid = false;
                    break;
                } else if (rule === 'email' && value && !this.isValidEmail(value)) {
                    errors[fieldName] = 'Please enter a valid email';
                    isValid = false;
                    break;
                } else if (rule.startsWith('min:')) {
                    const minLength = parseInt(rule.split(':')[1]);
                    if (value && value.length < minLength) {
                        errors[fieldName] = `${fieldName} must be at least ${minLength} characters`;
                        isValid = false;
                        break;
                    }
                } else if (rule.startsWith('max:')) {
                    const maxLength = parseInt(rule.split(':')[1]);
                    if (value && value.length > maxLength) {
                        errors[fieldName] = `${fieldName} cannot exceed ${maxLength} characters`;
                        isValid = false;
                        break;
                    }
                }
            }
        }
        
        // Show errors
        for (const [fieldName, errorMsg] of Object.entries(errors)) {
            const field = form.querySelector(`[name="${fieldName}"]`);
            if (field) {
                const errorEl = document.createElement('div');
                errorEl.className = 'form-error';
                errorEl.textContent = errorMsg;
                field.parentElement.appendChild(errorEl);
            }
        }
        
        return isValid;
    }
    
    /**
     * Validate email format
     */
    static isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
}

// UI_BROWSER_DIALOG_REPLACEMENT — top-level `class` declarations do not
// auto-attach to `window` in classic scripts, so every pre-existing
// `window.UI && ...` guard across the app was silently false, falling back
// to native window.alert()/window.confirm(). Exposing UI on window here is
// what makes the shared modal system actually engage everywhere it was
// already being referenced.
window.UI = UI;

/**
 * Session management
 */
class Session {
    static get(key) {
        try {
            const data = localStorage.getItem(key);
            return data ? JSON.parse(data) : null;
        } catch {
            return null;
        }
    }
    
    static set(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
    }
    
    static remove(key) {
        localStorage.removeItem(key);
    }
    
    static clear() {
        localStorage.clear();
    }
}
