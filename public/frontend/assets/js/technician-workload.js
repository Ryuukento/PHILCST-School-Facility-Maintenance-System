/* ===================================================================
   TASK — Technician Workload card.

   ONE renderer, shared by the two dashboards that host the card
   (dashboard.php = Administrator, maintenance-dashboard.php = Head
   Maintenance). Written as a standalone file rather than pasted into
   both pages' inline <script> blocks on purpose: the existing
   "Inventory Status" widget was duplicated across two dashboards and is
   still carried as tech debt, and there is no reason to create a second
   instance of that same problem while the code is being written fresh.

   READ-ONLY. It fetches counts and draws bars. It contains no
   assignment control, posts nothing, and asks for no permission — the
   endpoint it reads is gated by the server's existing centralized
   EnsureRole middleware (super_admin, maintenance_admin), which is the
   only place the rule lives.

   No value rendered here is hard-coded. Names, both counts, and the bar
   width all come from /api/dashboard/technician-workload.
   =================================================================== */
(function () {
    'use strict';

    var ENDPOINT = '/api/dashboard/technician-workload';

    // The exact empty-state wording the brief specifies.
    var EMPTY_MESSAGE = 'No maintenance personnel available.';
    var ERROR_MESSAGE = 'Unable to load technician workload right now.';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function publicUrl(path) {
        return typeof window.SFMS_PUBLIC_URL === 'function'
            ? window.SFMS_PUBLIC_URL(path)
            : path;
    }

    function buildState(message) {
        return ''
            + '<div class="tw-empty">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
            + '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>'
            + '<circle cx="9" cy="7" r="4"></circle>'
            + '<path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>'
            + '<path d="M16 3.13a4 4 0 0 1 0 7.75"></path>'
            + '</svg>'
            + '<p>' + escapeHtml(message) + '</p>'
            + '</div>';
    }

    /**
     * The avatar, using the SAME source the sidebar already uses: the
     * stored users.avatar URL when there is one, otherwise the first
     * letter of the person's name in a circle. No avatar is generated,
     * fetched from a third party, or assigned a colour by identity —
     * absent simply means absent, and falls back to the initial.
     */
    function buildAvatar(name, avatarUrl) {
        var url = String(avatarUrl === null || avatarUrl === undefined ? '' : avatarUrl).trim();

        if (url) {
            return '<span class="tw-avatar">'
                + '<img class="tw-avatar-img" src="' + escapeHtml(url) + '" alt="" aria-hidden="true">'
                + '</span>';
        }

        var initial = String(name || '').trim().charAt(0).toUpperCase() || '?';

        return '<span class="tw-avatar tw-avatar-initial" aria-hidden="true">' + escapeHtml(initial) + '</span>';
    }

    /**
     * The designation line under the name, e.g. "Head • Computer".
     *
     * The role label ALWAYS leads, and is never dropped, because the list
     * now mixes the Head in with the technicians — a row showing only a
     * department would leave a reader unable to tell which of the two
     * they are looking at. Both halves are stored values returned by the
     * server (users.role, then departments.name or users.designation);
     * nothing here is guessed from the name.
     */
    function buildMeta(technician) {
        var parts = [];
        var roleLabel = String((technician && technician.role_label) || '').trim();
        var detail = String((technician && (technician.department_name || technician.designation)) || '').trim();

        if (roleLabel) {
            parts.push(roleLabel);
        }
        if (detail) {
            parts.push(detail);
        }

        return parts.join(' • ');
    }

    /**
     * One row per person.
     *
     * Everyone the server returned is drawn, including those with an
     * active count of 0 — the brief forbids hiding them, because "who has
     * spare capacity" is half of what the card answers. A zero row shows
     * "0 assigned" and an empty (but present) bar track, so it reads as a
     * real zero rather than as missing data. That applies to the Head
     * exactly as it does to a technician: a Head with nothing assigned is
     * a real 0, not an omission.
     *
     * The completed count is only rendered when the server actually
     * supplied one; the brief asks for it "if available from existing
     * data", and inventing a 0 where the field is absent would be
     * fabricating a statistic. There is deliberately no "average days"
     * figure — the system stores no such aggregate, so the card shows the
     * two counts it can actually evidence instead of a made-up duration.
     *
     * The bar width is the server-computed relative percentage (busiest
     * person = 100%). It is applied as an inline style because it is
     * per-row data, not styling.
     */
    function renderRows(technicians) {
        return technicians.map(function (technician) {
            var name = technician && technician.full_name ? technician.full_name : 'Unnamed personnel';
            var active = Number(technician && technician.active_count) || 0;
            var percent = Math.max(0, Math.min(100, Number(technician && technician.workload_percent) || 0));
            var hasCompleted = technician
                && technician.completed_count !== null
                && technician.completed_count !== undefined;
            var completed = hasCompleted ? (Number(technician.completed_count) || 0) : null;
            var meta = buildMeta(technician);
            var designation = String((technician && technician.designation) || '').trim();

            var counts = '<span class="tw-count-active">' + active + ' assigned</span>';
            if (hasCompleted) {
                counts += '<span class="tw-count-sep" aria-hidden="true">&bull;</span>'
                    + '<span class="tw-count-completed">' + completed + ' completed</span>';
            }

            // The name's tooltip carries the stored designation when there
            // is one, so that detail stays reachable without crowding the
            // row (the visible meta line prefers the department).
            var nameTitle = designation && designation !== meta ? name + ' — ' + designation : name;

            var label = name + (meta ? ' (' + meta + ')' : '')
                + ': ' + active + ' active assigned report' + (active === 1 ? '' : 's')
                + (hasCompleted ? ', ' + completed + ' completed' : '');

            return ''
                + '<div class="tw-row' + (active === 0 ? ' tw-row-idle' : '') + '">'
                + buildAvatar(name, technician && technician.avatar)
                + '<div class="tw-main">'
                + '<div class="tw-row-head">'
                + '<span class="tw-identity">'
                + '<span class="tw-name" title="' + escapeHtml(nameTitle) + '">' + escapeHtml(name) + '</span>'
                + (meta ? '<span class="tw-meta">' + escapeHtml(meta) + '</span>' : '')
                + '</span>'
                + '<span class="tw-counts">' + counts + '</span>'
                + '</div>'
                + '<div class="tw-bar" role="img" aria-label="' + escapeHtml(label) + '">'
                + '<span class="tw-bar-fill" style="width: ' + percent + '%;"></span>'
                + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function render(container, technicians) {
        if (!container) {
            return;
        }

        if (!Array.isArray(technicians) || technicians.length === 0) {
            container.innerHTML = buildState(EMPTY_MESSAGE);
            return;
        }

        container.innerHTML = '<div class="tw-list">' + renderRows(technicians) + '</div>';
    }

    /**
     * Exactly one request, for the whole roster — never one per
     * technician. The per-technician active/completed counts are
     * aggregated server-side in a single grouped query.
     */
    async function load(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        try {
            var response = await fetch(publicUrl(ENDPOINT), {
                credentials: 'include',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var payload = await response.json();
            render(container, payload && payload.technicians);
        } catch (error) {
            console.error('[TechnicianWorkload] load failed:', error);
            container.innerHTML = buildState(ERROR_MESSAGE);
        }
    }

    window.TechnicianWorkload = {
        ENDPOINT: ENDPOINT,
        EMPTY_MESSAGE: EMPTY_MESSAGE,
        escapeHtml: escapeHtml,
        buildAvatar: buildAvatar,
        buildMeta: buildMeta,
        renderRows: renderRows,
        render: render,
        load: load
    };
})();
