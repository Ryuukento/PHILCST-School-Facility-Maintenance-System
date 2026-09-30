/**
 * API Client for SFMS - Laravel Backend
 */

const API = {
    // TASK 98.2 fix: this used to be `window.API_BASE_URL || '/api'`, but
    // window.API_BASE_URL is never actually set anywhere in the codebase, so
    // baseURL always resolved to the domain-root-relative '/api'. In this
    // XAMPP subfolder-hosted environment (app lives under
    // /School_Facility_Maintenance_System, not domain root) that 404s on
    // every call — confirmed live for API.logout(), which silently failed
    // and meant LOGOUT events never reached activity_logs. window.SFMS_PUBLIC_URL
    // (defined in header.php, and now also on index.php) is the existing,
    // already-used-elsewhere-in-this-file (see getNotifications below)
    // helper that correctly resolves the app's subfolder base path, so reuse
    // it here instead of introducing a second URL-resolution mechanism.
    baseURL: typeof window.SFMS_PUBLIC_URL === 'function'
        ? window.SFMS_PUBLIC_URL('/api')
        : (window.API_BASE_URL || '/api'),
    csrfToken: window.CSRF_TOKEN || '',
    
    /**
     * Login user
     */
    async login(username, password) {
        const response = await fetch(`${this.baseURL}/auth/login`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ username, password })
        });

        // Read as text first: if the server ever answers with an empty or
        // non-JSON body (e.g. a crash before the API could respond), show a
        // clear message instead of the browser's "Unexpected end of JSON
        // input".
        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (parseError) {
            const error = new Error(
                'The server could not process the sign-in request (HTTP ' + response.status + '). '
                + 'Please try again in a moment or contact the Administrator.'
            );
            error.status = response.status;
            error.data = {};
            throw error;
        }

        if (!data.success) {
            // The HTTP status and the response's data travel with the error so
            // the sign-in page can react to them: 429 starts the lock countdown
            // (data.retry_after_seconds), and data.attempts_remaining drives the
            // "N attempts left" warning. The message is unchanged.
            const error = new Error(data.message);
            error.status = response.status;
            error.data = data.data || {};
            throw error;
        }

        return data;
    },

    /**
     * Logout user
     */
    async logout() {
        const response = await fetch(`${this.baseURL}/auth/logout`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        return data;
    },

    /**
     * Update the current user's profile, including optional avatar upload
     */
    async updateProfile(formData) {
        // TASK 41B: this endpoint is registered as Route::patch('profile',
        // ...) and this call always sends multipart/form-data (required for
        // the optional profile_picture file field). On PHP < 8.4, PHP's
        // SAPI (and Symfony's Request::createFromGlobals() fallback used
        // for any non-urlencoded content type) only auto-parses
        // multipart/form-data bodies into $_POST/$_FILES for genuine HTTP
        // POST requests -- never for PUT/PATCH/DELETE, even with an
        // identical body. A real PATCH here (this app runs PHP 8.2) meant
        // the entire body -- full_name, username, email, current_password,
        // new_password, the file -- silently arrived empty at the server.
        // UserController::updateProfile() falls back to each field's
        // existing stored value when absent, and its password-update block
        // is skipped entirely by an `if ($newPassword !== '')` guard, so
        // the endpoint still returned HTTP 200 "Profile updated
        // successfully" -- while nothing was actually persisted. That is
        // why Account Settings reported success but the new password could
        // never log in afterward.
        //
        // Fix: send a genuine POST (so PHP/Symfony parse the multipart body
        // correctly) and append Laravel's method-spoofing field
        // (_method=PATCH) so the router still matches
        // Route::patch('profile', ...). Laravel enables
        // enableHttpMethodParameterOverride() by default, so this requires
        // no backend or route changes and leaves every other request path
        // (which are all POST or JSON already) untouched.
        formData.append('_method', 'PATCH');

        const response = await fetch(
            window.SFMS_PUBLIC_URL
                ? window.SFMS_PUBLIC_URL('/api/users/profile')
                : '/api/users/profile',
            {
                method: 'POST',
                credentials: 'include',
                body: formData
            }
        );

        const data = await response.json();

        if (!response.ok && !data.success) {
            throw new Error(data.message || 'Failed to update profile');
        }

        return data;
    },
    
    /**
     * Check session
     */
    async checkSession() {
        const response = await fetch(`${this.baseURL}/auth/check`, {
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        return data;
    },
    
    /**
     * Get all reports
     */
    async getReports() {
        const response = await fetch(`${this.baseURL}/reports`, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Unauthorized: Please log in');
        }
        
        return data;
    },
    
    /**
     * Get single report
     */
    async getReport(id) {
        const response = await fetch(`${this.baseURL}/reports/${id}`, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        return data;
    },
    
    /**
     * Create new report
     */
    async createReport(reportData) {
        const response = await fetch(`${this.baseURL}/reports`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(reportData)
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        return data;
    },
    
    /**
     * Update report
     */
    async updateReport(reportData) {
        const reportId = reportData.report_id || reportData.id;
        const response = await fetch(`${this.baseURL}/reports/${reportId}`, {
            method: 'PATCH',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(reportData)
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        return data;
    },
    
    /**
     * Delete report
     */
    async deleteReport(id) {
        const response = await fetch(`${this.baseURL}/reports/${id}`, {
            method: 'DELETE',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        return data;
    },

    /**
     * Notifications - stored as part of reports (pending implementation)
     */
    async getNotifications(limit = 10) {
        const url = window.SFMS_PUBLIC_URL('/api/notifications/unread') + '?limit=' + encodeURIComponent(limit);
        const response = await fetch(url, {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to load notifications');
        }

        return data;
    },

    async getNotificationCount() {
        // Reuse the unread endpoint — limit=1 is cheap, and count field reflects real total.
        const url = window.SFMS_PUBLIC_URL('/api/notifications/unread') + '?limit=1';
        const response = await fetch(url, {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to load notification count');
        }

        return data;
    },

    async markNotificationAsRead(notificationId) {
        const url = window.SFMS_PUBLIC_URL(`/api/notifications/${notificationId}/read`);
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to mark notification as read');
        }

        return data;
    },
    
    /**
     * Get statistics / Dashboard stats
     */
    async getStats() {
        const response = await fetch(`${this.baseURL}/dashboard/stats`, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        return data;
    },

    /**
     * Show loading state
     */
    showLoading(element) {
        const originalContent = element.innerHTML;
        element.innerHTML = '<span class="loading">Loading...</span>';
        element.disabled = true;
        return () => {
            element.innerHTML = originalContent;
            element.disabled = false;
        };
    },

    /**
     * Format date
     */
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },

    /**
     * Format datetime
     */
    formatDateTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    /**
     * Get priority badge class
     */
    getPriorityBadge(priority) {
        const badges = {
            'low': 'badge-primary',
            'medium': 'badge-warning',
            'high': 'badge-danger',
            'urgent': 'badge-danger'
        };
        return badges[priority] || 'badge-primary';
    },
    
    /**
     * Get status badge class
     */
    getStatusBadge(status) {
        const badges = {
            'draft': 'badge-submitted',
            'submitted': 'badge-submitted',
            'assigned': 'badge-assigned',
            'in_progress': 'badge-in-progress',
            'completed': 'badge-completed',
            'cancelled': 'badge-report-cancelled',
            'closed': 'badge-report-closed'
        };
        return badges[status] || 'badge-submitted';
    }
};

// Expose API on window: top-level `const` does not attach to the global
// object, but main.js's performLogout() (and other callers) check
// `window.API` before using it. Without this, API.logout() is silently
// skipped and the app falls straight through to the server-side
// logout.php redirect, which never records a LOGOUT activity-log entry.
window.API = API;

/**
 * Session helper - using localStorage
 */
if (typeof Session === 'undefined') {
    const Session = {
        get(key) {
            const value = localStorage.getItem(key);
            return value ? JSON.parse(value) : null;
        },
        
        set(key, value) {
            localStorage.setItem(key, JSON.stringify(value));
        },
        
        remove(key) {
            localStorage.removeItem(key);
        },
        
        clear() {
            localStorage.clear();
        },
        
        getUser() {
            return this.get('user');
        }
    };
}
