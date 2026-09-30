// ===================================
// NOTIFICATION SYSTEM
// ===================================

const NotificationManager = {
    isOpen: false,
    refreshInterval: null,
    currentFilter: 'all',
    lastNotifications: [],

    /**
     * TASK 17 — Notification Deep Linking: centralized routing map.
     *
     * Every notification type that can carry entity_type/entity_id (see
     * migration 2026_08_04_000200_add_entity_columns_to_notifications_table)
     * is routed through exactly this table — no notification type builds a
     * URL anywhere else in this file. `check` hits the same single-record
     * GET endpoint the rest of the app already uses for that entity (so
     * "respect Task 9 authorization" means literally reusing the API's own
     * per-record 403, not re-implementing a permission rule client-side).
     * `build` returns the existing detail page URL for that entity — again
     * no new pages, just the ones report-detail.php / dispatch-detail.php /
     * damage-report-detail.php / users.php already are.
     *
     * 'user' has no GET /api/users/{id} endpoint (only the super_admin-only
     * list endpoint exists), so it is marked clientOnly and checked via the
     * same super_admin role gate the API enforces on that list — this
     * notification type is only ever sent to super_admins in the first
     * place (see AuthController::forgotPasswordRequest()).
     */
    ENTITY_ROUTES: {
        report: {
            check: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL(`/api/reports/${id}`) : `/api/reports/${id}`),
            // Reuses the existing role-aware report URL builder below
            // unchanged — new entity-linked rows and legacy text-extracted
            // rows land on exactly the same detail page logic.
            build: (id) => NotificationManager.getNotificationTargetUrl(id),
        },
        dispatch: {
            check: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL(`/api/dispatches/${id}`) : `/api/dispatches/${id}`),
            build: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/dispatch-detail.php') : '/frontend/pages/dispatch-detail.php') + `?id=${id}`,
        },
        // TASK 12 — the 'repair_request' entity route was removed here. It
        // built a deep link to repair-detail.php, which this task deletes, so
        // keeping it would have sent a user from the notification bell to a
        // page that no longer exists.
        //
        // Removal degrades gracefully rather than throwing: resolveNotification-
        // Target() looks the type up with ENTITY_ROUTES[entityType], so an
        // unmapped type simply yields undefined and falls through to the same
        // Task 13.1 text-extraction path that every pre-entity-column
        // notification already uses. No notification rows carry this type
        // today, so nothing in the current data set changes behaviour.
        damage_report: {
            check: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL(`/api/damage-reports/${id}`) : `/api/damage-reports/${id}`),
            build: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/damage-report-detail.php') : '/frontend/pages/damage-report-detail.php') + `?id=${id}`,
        },
        user: {
            clientOnly: true,
            build: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/users.php') : '/frontend/pages/users.php') + `?highlight=${id}`,
        },
        // TASK 18 — extends the Task 17 ENTITY_ROUTES map (not a redesign) for
        // the three new notification events that need deep links.
        purchase_receipt: {
            // purchase-receipts.php already supports a ?id= detail view
            // (see $receiptId/$isDetail), so this follows the same
            // server-checked report/dispatch pattern below.
            check: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL(`/api/purchase-receipts/${id}`) : `/api/purchase-receipts/${id}`),
            build: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/purchase-receipts.php') : '/frontend/pages/purchase-receipts.php') + `?id=${id}`,
        },
        inventory: {
            // GET /api/items/{id} has no extra role gate beyond authentication,
            // matching who can view the Inventory page itself.
            check: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL(`/api/items/${id}`) : `/api/items/${id}`),
            build: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/inventory.php') : '/frontend/pages/inventory.php') + `?highlight=${id}`,
        },
        building: {
            // No GET /api/buildings/{id} show endpoint exists, so — like
            // 'user' — this is clientOnly. Unlike 'user' it must allow both
            // super_admin and maintenance_admin (the two roles the Building
            // Updated notification is sent to), via the allowedRoles override.
            clientOnly: true,
            allowedRoles: ['super_admin', 'maintenance_admin'],
            build: (id) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/buildings-overview.php') : '/frontend/pages/buildings-overview.php') + `?highlight=${id}`,
        },
    },

    init() {
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');

        if (!bell || !dropdown) return;
        
        bell.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleDropdown();
        });
        
        document.addEventListener('click', () => {
            if (this.isOpen) {
                this.closeDropdown();
            }
        });
        
        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });
        
        this.loadNotifications();
        
        // Poll every 10 seconds for near-real-time bell updates
        this.refreshInterval = setInterval(() => {
            this.loadNotifications();
        }, 10000);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.loadNotifications();
            }
        });

        window.addEventListener('focus', () => {
            this.loadNotifications();
        });
        
        const clearBtn = document.getElementById('clearNotifications');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.markAllAsRead();
            });
        }

        const tabs = document.querySelectorAll('.notification-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                this.currentFilter = tab.dataset.filter || 'all';
                this.renderNotifications(this.lastNotifications);
            });
        });
    },
    
    toggleDropdown() {
        const dropdown = document.getElementById('notificationDropdown');
        this.isOpen ? this.closeDropdown() : this.openDropdown();
    },
    
    openDropdown() {
        document.getElementById('notificationDropdown').classList.add('show');
        this.isOpen = true;
        this.loadNotifications();
    },
    
    closeDropdown() {
        document.getElementById('notificationDropdown').classList.remove('show');
        this.isOpen = false;
    },
    
    async loadNotifications() {
        try {
            let notifications = [];
            let count = 0;

            if (typeof API !== 'undefined' && typeof API.getNotifications === 'function' && typeof API.getNotificationCount === 'function') {
                const response = await API.getNotifications(10);
                notifications = (response && response.data && Array.isArray(response.data.notifications)) ? response.data.notifications : [];

                const countResult = await API.getNotificationCount();
                count = (countResult && countResult.data && typeof countResult.data.count !== 'undefined')
                    ? Number(countResult.data.count)
                    : notifications.length;
            } else {
                const listRes = await fetch(
                    window.SFMS_PUBLIC_URL('/api/notifications/unread') + '?limit=10',
                    { credentials: 'include' }
                );
                const listData = await listRes.json();
                notifications = (listData && listData.data && Array.isArray(listData.data.notifications)) ? listData.data.notifications : [];
                count = (listData && listData.data && typeof listData.data.count !== 'undefined')
                    ? Number(listData.data.count)
                    : notifications.length;
            }
            
            const badge = document.getElementById('notificationCount');
            if (count > 0) {
                badge.textContent = count > 9 ? '9+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
            
            this.lastNotifications = notifications;
            this.renderNotifications(notifications);
        } catch (error) {
            console.error('Failed to load notifications:', error);
        }
    },
    
    renderNotifications(notifications) {
        const list = document.getElementById('notificationList');
        const filtered = this.currentFilter === 'unread'
            ? notifications.filter(n => Number(n.is_read) === 0)
            : notifications;
        
        if (filtered.length === 0) {
            list.innerHTML = '<div class="notification-empty">No notifications</div>';
            return;
        }
        
        let html = '';
        filtered.forEach(notif => {
            const isUnread = notif.is_read === 0 ? 'unread' : '';
            const icon = this.getIcon(notif.type);
            const timeAgo = this.getTimeAgo(notif.created_at);
            const notificationId = Number(notif.notification_id) || 0;
            const reportId = Number(notif.report_id) || 0;
            // TASK 17 — Notification Deep Linking: entity_type is a short,
            // fixed enum written only by our own backend (see
            // NotificationService::notify()), so it is whitelisted rather
            // than HTML-escaped before being inlined into the onclick
            // attribute's JS string literal.
            const rawEntityType = String(notif.entity_type || '');
            const entityType = /^[a-z_]+$/.test(rawEntityType) ? rawEntityType : '';
            const entityTypeJs = entityType ? `'${entityType}'` : 'null';
            const entityId = Number(notif.entity_id) || 0;

            html += `
                <div class="notification-item ${isUnread}" data-notification-id="${notificationId}" data-report-id="${reportId}" data-entity-type="${UI.escapeHtml(entityType)}" data-entity-id="${entityId}" onclick="NotificationManager.handleNotificationClick(${notificationId}, ${reportId}, ${entityTypeJs}, ${entityId}, this)">
                    <div class="notification-icon">${icon}</div>
                    <div class="notification-content">
                        <div class="notification-title">${UI.escapeHtml(notif.title)}</div>
                        <div class="notification-message">${UI.escapeHtml(notif.message)}</div>
                        <div class="notification-time">${UI.escapeHtml(timeAgo)}</div>
                    </div>
                    ${isUnread ? '<span class="notification-dot"></span>' : ''}
                </div>
            `;
        });
        
        list.innerHTML = html;
    },
    
    async handleNotificationClick(notificationId, reportId, entityType = null, entityId = 0, itemEl = null) {
        const notif = this.lastNotifications.find(n => Number(n.notification_id) === Number(notificationId));

        // Never block navigation because of mark-as-read errors.
        try {
            if (notificationId) {
                if (notif && Number(notif.is_read) === 0) {
                    notif.is_read = 1;
                    this.updateBadgeCount(-1);
                    if (itemEl) {
                        itemEl.classList.remove('unread');
                        const dot = itemEl.querySelector('.notification-dot');
                        if (dot) dot.remove();
                    }
                }
                await this.markNotificationAsRead(notificationId);
            }
        } catch (error) {
            console.warn('Unable to mark notification as read:', error);
        }

        // TASK 17 — Browser Behavior: mark as read -> close dropdown -> navigate.
        this.closeDropdown();

        // entityType/entityId come from the caller's own onclick args (works
        // for header.php's server-rendered items, which never appear in
        // lastNotifications) but fall back to the looked-up notif object when
        // available, so a stale/omitted argument still resolves correctly.
        const resolvedEntityType = entityType || (notif && notif.entity_type) || null;
        const resolvedEntityId = Number(entityId || (notif && notif.entity_id) || 0) || 0;

        const result = await this.resolveNotificationTarget({
            entity_type: resolvedEntityType,
            entity_id: resolvedEntityId,
            report_id: reportId,
            title: notif ? notif.title : '',
            message: notif ? notif.message : '',
        });

        if (result && result.url) {
            window.location.href = result.url;
            return;
        }

        if (result && result.error === 'permission') {
            Components.alert('You do not have permission to access this item.', 'danger');
            return;
        }

        if (result && result.error) {
            Components.alert('Unable to open this notification right now. Please try again.', 'danger');
            return;
        }

        // Nothing identifiable to navigate to — same silent-refresh fallback
        // this file has always used.
        this.loadNotifications();
    },

    /**
     * TASK 17 — Notification Deep Linking: single routing entry point every
     * notification type goes through. If the notification carries a known
     * entity_type/entity_id (see migration
     * 2026_08_04_000200_add_entity_columns_to_notifications_table and
     * NotificationService::notify()), it is preflight-checked against the
     * SAME single-record API endpoint the rest of the app already uses for
     * that entity, so an unauthorized user is never handed a detail URL the
     * API itself would 403 on ("respect Task 9 authorization... do not
     * redirect anyway").
     *
     * Rows with no entity_type (every notification created before this task
     * shipped) fall through unchanged to the Task 13.1 text-extraction path
     * — that logic is reused verbatim, not rewritten.
     *
     * @returns {Promise<{url:string}|{error:'permission'|'failed'}|null>}
     *   null means "nothing identifiable" (pre-existing silent-refresh case).
     */
    async resolveNotificationTarget(notif) {
        const entityType = notif && notif.entity_type;
        const entityId = Number(notif && notif.entity_id) || 0;
        const route = entityType ? this.ENTITY_ROUTES[entityType] : null;

        if (route && entityId) {
            if (route.clientOnly) {
                // TASK 18 — allowedRoles defaults to ['super_admin'] so
                // 'user's pre-existing super_admin-only behavior is
                // unchanged; 'building' overrides it to also allow
                // maintenance_admin.
                const allowedRoles = route.allowedRoles || ['super_admin'];
                const role = (document.body && document.body.dataset && document.body.dataset.userRole) || '';
                if (!allowedRoles.includes(String(role).toLowerCase())) {
                    return { error: 'permission' };
                }
                return { url: route.build(entityId) };
            }

            try {
                const response = await fetch(route.check(entityId), {
                    credentials: 'include',
                    headers: { 'Accept': 'application/json' },
                });

                if (response.status === 403) {
                    return { error: 'permission' };
                }

                if (!response.ok) {
                    return { error: 'failed' };
                }

                const payload = await response.json().catch(() => null);
                if (payload && payload.success) {
                    return { url: route.build(entityId) };
                }

                return { error: 'failed' };
            } catch (error) {
                console.warn('Unable to resolve notification target:', error);
                return { error: 'failed' };
            }
        }

        // TASK 13.1 §5/§6 — dispatch notifications are resolved FIRST, because
        // for a dispatch notification a report id is either absent or points
        // at the originating maintenance report, which is not what the message
        // is about. extractDispatchCode() returns '' for every non-dispatch
        // notification, so this yields null and the pre-existing report path
        // below runs exactly as it always has.
        let targetUrl = await this.resolveDispatchTargetUrl(notif);

        if (!targetUrl) {
            const resolvedReportId = this.resolveNotificationReportId(notif && notif.report_id, notif);
            targetUrl = this.getNotificationTargetUrl(resolvedReportId);
        }

        return targetUrl ? { url: targetUrl } : null;
    },

    updateBadgeCount(delta) {
        const badge = document.getElementById('notificationCount');
        if (!badge) return;
        const current = badge.textContent === '9+' ? 9 : Number(badge.textContent || 0);
        const next = Math.max(0, current + delta);
        if (next > 0) {
            badge.textContent = next > 9 ? '9+' : String(next);
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    },

    async markNotificationAsRead(notificationId) {
        if (typeof API !== 'undefined' && API && typeof API.markNotificationAsRead === 'function') {
            return API.markNotificationAsRead(notificationId);
        }

        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/notifications/${notificationId}/read`),
            { method: 'POST', credentials: 'include', headers: { 'Accept': 'application/json' } }
        );

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Failed to mark notification as read');
        }

        return result;
    },

    resolveNotificationReportId(reportId, notif) {
        const directId = Number(reportId || 0);
        if (directId > 0) {
            return directId;
        }

        if (!notif) {
            return 0;
        }

        const fallbackId = Number(notif.report_id || 0);
        if (fallbackId > 0) {
            return fallbackId;
        }

        const sourceText = `${notif.title || ''} ${notif.message || ''} ${notif.report_title || ''}`;
        const reportIdMatch = sourceText.match(/(?:report\s*#|#)(\d+)/i);
        if (reportIdMatch && reportIdMatch[1]) {
            return Number(reportIdMatch[1]) || 0;
        }

        return 0;
    },

    /**
     * TASK 13.1 §5 — make a dispatch notification open the Dispatch Detail
     * page it is talking about.
     *
     * The notifications table has no link/url column, and adding one would be
     * a schema change this task excludes. So the dispatch is identified the
     * same way resolveNotificationReportId() above already identifies a report
     * — by reading the identifier out of the notification text. Every dispatch
     * notification DispatchService emits embeds the dispatch_code verbatim
     * ("Dispatch DSP-XXXX has been approved..."), so the code is reliably
     * present; the id is not, which is why a lookup is needed at all.
     *
     * This is additive and fully guarded: a notification with no DSP- code in
     * it returns null immediately and falls through to the existing report
     * behaviour untouched.
     */
    extractDispatchCode(notif) {
        if (!notif) return '';

        const sourceText = `${notif.title || ''} ${notif.message || ''}`;
        const match = sourceText.match(/\bDSP-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*/);
        // Trailing sentence punctuation is not part of the code.
        return match ? match[0].replace(/[.,;:]+$/, '') : '';
    },

    async resolveDispatchTargetUrl(notif) {
        const code = this.extractDispatchCode(notif);
        if (!code) {
            return null;
        }

        const url = (path) => (window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL(path) : path);

        // Fallback: the list page pre-filtered to this code. Used when the
        // lookup fails OR when the dispatch is not visible to this user —
        // the API decides that, not the client, so an unauthorised user is
        // never handed a detail URL they cannot open.
        const listUrl = `${url('/frontend/pages/dispatches.php')}?search=${encodeURIComponent(code)}`;

        try {
            const response = await fetch(
                `${url('/api/dispatches')}?search=${encodeURIComponent(code)}&per_page=1`,
                { credentials: 'include', headers: { 'Accept': 'application/json' } }
            );
            const payload = await response.json();

            if (response.ok && payload.success) {
                const rows = Array.isArray(payload.data && payload.data.data) ? payload.data.data : [];
                const id = Number(rows[0] && rows[0].id) || 0;
                if (id > 0) {
                    return `${url('/frontend/pages/dispatch-detail.php')}?id=${id}`;
                }
            }
        } catch (error) {
            console.warn('Unable to resolve dispatch from notification:', error);
        }

        return listUrl;
    },

    getNotificationTargetUrl(reportId) {
        const safeReportId = Number(reportId) || 0;
        if (!safeReportId) {
            return null;
        }

        const role = (document.body && document.body.dataset && document.body.dataset.userRole)
            ? document.body.dataset.userRole
            : '';

        const normalizedRole = String(role || '').toLowerCase();
        const allReportsContextRoles = ['super_admin', 'maintenance_admin'];
        const maintenanceDetailRoles = ['maintenance_staff'];

        if (allReportsContextRoles.includes(normalizedRole)) {
            return `${window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/maintenance-report-detail.php') : '/frontend/pages/maintenance-report-detail.php'}?id=${safeReportId}&back=all_reports`;
        }

        if (maintenanceDetailRoles.includes(normalizedRole)) {
            return `${window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/maintenance-report-detail.php') : '/frontend/pages/maintenance-report-detail.php'}?id=${safeReportId}`;
        }

        return `${window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/frontend/pages/report-detail.php') : '/frontend/pages/report-detail.php'}?id=${safeReportId}`;
    },

    
    async markAllAsRead() {
        try {
            await fetch(
                window.SFMS_PUBLIC_URL('/api/notifications/read-all'),
                { method: 'POST', credentials: 'include' }
            );
            this.loadNotifications();
        } catch (error) {
            console.error('Error marking all as read:', error);
        }
    },
    
    // TASK 7 — these five were emoji ('📋', '⚠️', '✅', '📌', 'ℹ️', falling back
    // to '🔔'). They are now names from the shared icon registry, rendered as
    // inline SVG by UIIcons so they match the bell and the server-rendered
    // notification rows in header.php exactly. The notification's title and
    // message sit right beside the icon, so it stays decorative.
    //
    // The mapping is unchanged in meaning: only the glyph is different. The
    // status/type strings themselves are untouched.
    getIcon(type) {
        const icons = {
            'report': 'clipboard-list',
            'urgent': 'alert-triangle',
            'completed': 'check-circle',
            'assigned': 'pin',
            'low': 'info'
        };
        const name = icons[type] || 'bell';
        const tone = {
            'urgent': ' ui-icon-warning',
            'completed': ' ui-icon-success',
            'low': ' ui-icon-info'
        }[type] || '';

        // Defensive: if ui-icons.js somehow has not loaded, render nothing
        // rather than reintroducing an emoji.
        if (!window.UIIcons) {
            return '';
        }

        return window.UIIcons.svg(name, { size: 18, className: tone.trim() });
    },
    
    getTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
        return `${Math.floor(seconds / 86400)}d ago`;
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        NotificationManager.init();
    });
} else {
    NotificationManager.init();
}
