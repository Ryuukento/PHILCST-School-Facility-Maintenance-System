<?php
/**
 * User Management Page
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

if (!isset($_SESSION['user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$user = $_SESSION['user'];
$userRole = $user['role'] ?? 'user';

if (!in_array($userRole, ['super_admin'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

$pageTitle = 'User Management - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<main class="container users-page-container">
    <section class="users-page-shell">
        <div class="users-page-hero">
            <div class="users-page-hero-copy">
                <span class="users-page-kicker">User Management</span>
                <h2>All Users</h2>
                <p>View every account as a profile card, with avatar previews, roles, and quick account actions.</p>
            </div>
        </div>

        <div class="users-toolbar" style="border-left:3px solid var(--primary-color);padding-left:12px;">
            <div class="users-search-wrap">
                <input type="search" id="search-input" name="user-search" class="users-search-input" placeholder="Search by name, email, or role..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="search">
                <div class="users-role-filters" id="users-role-filters" aria-label="Filter users by role">
                    <button type="button" class="users-role-filter is-active" data-role-filter="all">All</button>
                    <!-- TASK 79: super_admin accounts are permanently excluded from GET
                         /api/users (UserController::index()) and are not part of
                         setRoleFilter()'s allowlist -- an "Administrator" filter button
                         here could never surface a match and silently fell back to
                         "All" when clicked. Removed rather than left as a dead control. -->
                    <button type="button" class="users-role-filter" data-role-filter="maintenance_admin">Head</button>
                    <button type="button" class="users-role-filter" data-role-filter="maintenance_staff">Staff</button>
                </div>
            </div>
            <div class="users-toolbar-actions">
                <button type="button" id="add-new-user-btn" class="users-page-btn users-page-btn-primary">
                    <span class="users-page-btn-icon">+</span>
                    <span>Add New User</span>
                </button>
                <div id="users-summary" class="users-summary"></div>
            </div>
        </div>

        <div id="alert-container"></div>

        <div id="users-container">
            <div class="users-grid users-loading-grid ui-fade-in">
                <div class="users-card users-skeleton-card"></div>
                <div class="users-card users-skeleton-card"></div>
                <div class="users-card users-skeleton-card"></div>
                <div class="users-card users-skeleton-card"></div>
            </div>
        </div>

        <div id="users-pagination" class="users-pagination"></div>
    </section>
</main>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/users.inline.css?v=20260926-1">

<script>
window.API = window.API || {};
const currentUserRole = <?php echo json_encode($userRole); ?>;

const usersState = {
    allUsers: [],
    filteredUsers: [],
    currentPage: 1,
    pageSize: 8,
    searchTerm: '',
    roleFilter: 'all'
};

/**
 * TASK 37 — roles whose accounts are meaningless without a department.
 * Mirrors UserController::DEPARTMENT_REQUIRED_ROLES. The backend copy is the
 * one that enforces; this one only decides what the register modal shows,
 * because hiding a field in JavaScript proves nothing about what a direct
 * POST to /api/users can do.
 */
const DEPARTMENT_REQUIRED_ROLES = ['maintenance_admin', 'maintenance_staff'];

function roleRequiresDepartment(role) {
    return DEPARTMENT_REQUIRED_ROLES.includes(String(role || '').toLowerCase().trim());
}

/**
 * TASK 37 — set by openRegisterUserModal() so loadDepartmentsForModal() can
 * re-apply the role-dependent state after it replaces the <select>'s options.
 * That replacement wipes the placeholder text and any current selection, so
 * without this the field would come back reading "No specific department"
 * even for a role that requires one.
 */
let syncRegisterRoleFields = null;

async function loadDepartmentsForModal() {
    const select = document.getElementById('register-department');
    if (!select) return;
    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/departments'), { credentials: 'include' });
        const data = await res.json();
        const depts = data.departments || (data.data && data.data.departments) || [];
        select.innerHTML = '<option value="">No specific department</option>';
        depts.forEach((d) => {
            const opt = document.createElement('option');
            opt.value = d.department_id;
            opt.textContent = d.name;
            select.appendChild(opt);
        });
    } catch (e) {
        select.innerHTML = '<option value="">Could not load departments</option>';
    }
    syncRegisterRoleFields?.();
}

document.addEventListener('DOMContentLoaded', () => {
    // TASK 17 — Notification Deep Linking: a 'user' notification (password
    // reset request) lands here via ?highlight=<user_id>. There is no
    // GET /api/users/{id} endpoint to preflight against (only the
    // super_admin-only list endpoint), so notification.js already gated
    // this link client-side on role; here we just need to find and
    // highlight the card once the grid has actually rendered.
    loadUsers().then(() => highlightUserFromQuery());
    document.getElementById('search-input')?.addEventListener('input', searchUsers);
    document.getElementById('add-new-user-btn')?.addEventListener('click', () => { openRegisterUserModal(); setTimeout(loadDepartmentsForModal, 50); });

    document.querySelectorAll('[data-role-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            setRoleFilter(button.getAttribute('data-role-filter') || 'all');
        });
    });
});

function highlightUserFromQuery() {
    const params = new URLSearchParams(window.location.search);
    const highlightId = Number(params.get('highlight') || 0);
    if (!highlightId) return;

    const card = document.querySelector(`.users-card[data-user-id="${highlightId}"]`);
    if (!card) return;

    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.classList.add('users-card-highlight');
    setTimeout(() => card.classList.remove('users-card-highlight'), 3000);
}

async function loadUsers() {
    const container = document.getElementById('users-container');
    container.innerHTML = '<div class="users-grid users-loading-grid ui-fade-in"><div class="users-card users-skeleton-card"></div><div class="users-card users-skeleton-card"></div><div class="users-card users-skeleton-card"></div><div class="users-card users-skeleton-card"></div></div>';

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/users'), {
            method: 'GET',
            credentials: 'include'
        });

        const data = await response.json();

        if (data.success && data.data && data.data.users) {
            usersState.allUsers = data.data.users;
            usersState.searchTerm = document.getElementById('search-input')?.value.trim().toLowerCase() || '';
            usersState.currentPage = 1;
            applyUserFilters();
        } else {
            container.innerHTML = '<div class="ui-empty-state ui-fade-in users-empty-state"><strong>No users found.</strong><span>User records will appear here once accounts are created.</span></div>';
            document.getElementById('users-pagination').innerHTML = '';
            document.getElementById('users-summary').textContent = '';
        }
    } catch (error) {
        console.error('Error loading users:', error);
        container.innerHTML = '<div class="text-center users-error">Error loading users: ' + error.message + '</div>';
        document.getElementById('users-pagination').innerHTML = '';
        document.getElementById('users-summary').textContent = '';
    }
}

function searchUsers() {
    usersState.searchTerm = document.getElementById('search-input').value.trim().toLowerCase();
    usersState.currentPage = 1;
    applyUserFilters();
}

function setRoleFilter(nextRoleFilter) {
    const allowed = new Set(['all', 'maintenance_staff', 'maintenance_admin']);
    const normalized = String(nextRoleFilter || 'all').toLowerCase();
    usersState.roleFilter = allowed.has(normalized) ? normalized : 'all';
    usersState.currentPage = 1;
    renderRoleFilterState();
    applyUserFilters();
}

function renderRoleFilterState() {
    document.querySelectorAll('[data-role-filter]').forEach((button) => {
        const buttonRole = String(button.getAttribute('data-role-filter') || 'all').toLowerCase();
        button.classList.toggle('is-active', buttonRole === usersState.roleFilter);
    });
}

function applyUserFilters() {
    const term = usersState.searchTerm;
    const roleFilter = usersState.roleFilter;
    usersState.filteredUsers = usersState.allUsers.filter((targetUser) => {
        const roleValue = String(targetUser.role || '').toLowerCase();
        if (roleFilter !== 'all' && roleValue !== roleFilter) {
            return false;
        }

        const haystack = [
            targetUser.full_name,
            targetUser.email,
            targetUser.role,
            targetUser.status,
            targetUser.employee_id,
            targetUser.user_id
        ]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return term === '' || haystack.includes(term);
    });

    renderUsersSummary();
    renderUsersGrid();
    renderUsersPagination();
}

function renderUsersSummary() {
    const summary = document.getElementById('users-summary');
    if (!summary) return;

    const total = usersState.allUsers.length;
    const matched = usersState.filteredUsers.length;
    summary.textContent = total === matched
        ? `${total} account${total === 1 ? '' : 's'} shown`
        : `${matched} of ${total} accounts matched`;
}

function renderUsersGrid() {
    const container = document.getElementById('users-container');
    const users = usersState.filteredUsers;
    const start = (usersState.currentPage - 1) * usersState.pageSize;
    const pageUsers = users.slice(start, start + usersState.pageSize);

    if (!users || users.length === 0) {
        container.innerHTML = '<div class="ui-empty-state ui-fade-in users-empty-state"><strong>No users found.</strong><span>User records will appear here once accounts are created.</span></div>';
        return;
    }

    const cards = pageUsers.map((targetUser) => renderUserCard(targetUser)).join('');
    container.innerHTML = `<div class="users-grid ui-fade-in">${cards}</div>`;
}

function renderUsersPagination() {
    const pagination = document.getElementById('users-pagination');
    const totalPages = Math.ceil(usersState.filteredUsers.length / usersState.pageSize);

    if (!pagination || totalPages <= 1) {
        if (pagination) pagination.innerHTML = '';
        return;
    }

    const items = [];
    items.push(`<button type="button" class="users-page-nav ${usersState.currentPage === 1 ? 'is-disabled' : ''}" ${usersState.currentPage === 1 ? 'disabled' : ''} data-page="prev">&laquo;</button>`);

    for (let page = 1; page <= totalPages; page++) {
        items.push(`<button type="button" class="users-page-nav ${page === usersState.currentPage ? 'is-active' : ''}" data-page="${page}">${page}</button>`);
    }

    items.push(`<button type="button" class="users-page-nav ${usersState.currentPage === totalPages ? 'is-disabled' : ''}" ${usersState.currentPage === totalPages ? 'disabled' : ''} data-page="next">&raquo;</button>`);

    pagination.innerHTML = items.join('');
    pagination.querySelectorAll('[data-page]').forEach((button) => {
        button.addEventListener('click', () => {
            const pageValue = button.getAttribute('data-page');

            if (pageValue === 'prev') {
                usersState.currentPage = Math.max(1, usersState.currentPage - 1);
            } else if (pageValue === 'next') {
                usersState.currentPage = Math.min(totalPages, usersState.currentPage + 1);
            } else {
                usersState.currentPage = Number(pageValue);
            }

            renderUsersGrid();
            renderUsersPagination();
        });
    });
}

function renderUserCard(targetUser) {
    const safeId        = Number(targetUser.user_id || 0);
    const safeName      = escapeHtml(targetUser.full_name || 'N/A');
    const safeEmail     = escapeHtml(targetUser.email || 'N/A');
    const safeDept      = escapeHtml(targetUser.department_name || 'No department');
    // Show @username when available, fall back to email, then a neutral placeholder
    const safeHandle    = targetUser.username
                            ? escapeHtml('@' + targetUser.username)
                            : targetUser.email
                                ? escapeHtml(targetUser.email)
                                : '—';
    const joinedDate    = targetUser.created_at
                            ? new Date(targetUser.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                            : '—';
    const normalizedRole = String(targetUser.role || 'user').toLowerCase();
    const normalizedStatus = String(targetUser.status || '').toLowerCase();
    const hasPendingPasswordResetRequest = Boolean(Number(targetUser.has_pending_password_reset_request || 0)) || targetUser.has_pending_password_reset_request === true;
    const avatarMarkup = buildAvatarMarkup(targetUser);
    const mediaMarkup = buildCardMediaMarkup(targetUser);
    const roleLabel = getRoleLabel(normalizedRole);
    const safeDesignation = escapeHtml(targetUser.designation || '');
    const statusLabel = getStatusLabel(normalizedStatus);
    const statusClass = getStatusClass(normalizedStatus);

    let actionButtons = '';
    if (normalizedStatus === 'pending') {
        if (currentUserRole === 'super_admin') {
            actionButtons += `<button type="button" onclick="openApproveUserModal(${safeId}, '${escapeJsString(targetUser.full_name || '')}')" class="users-action-btn users-action-approve">Approve</button>`;
        }

        actionButtons += `<button type="button" onclick="openRejectUserModal(${safeId}, '${escapeJsString(targetUser.full_name || '')}')" class="users-action-btn users-action-reject">Reject</button>`;
    } else if (normalizedStatus === 'inactive') {
        actionButtons = `
            <button type="button" onclick="openEditUserModal(${safeId})" class="users-action-btn users-action-edit" title="Edit this user's role, department, or designation">${usersIcon('edit')} Edit</button>
            <button type="button" onclick="openActivateUserModal(${safeId}, '${escapeJsString(targetUser.full_name || '')}')" class="users-action-btn users-action-inactive" title="Set this user to active">Set Active</button>
        `;
    } else {
        // Active status - show Reset Password only if pending, otherwise just Set Inactive
        const editButton = `<button type="button" onclick="openEditUserModal(${safeId})" class="users-action-btn users-action-edit" title="Edit this user's role, department, or designation">${usersIcon('edit')} Edit</button>`;
        if (hasPendingPasswordResetRequest) {
            actionButtons = `
                ${editButton}
                <button type="button" onclick="openResetPasswordModal(${safeId}, '${escapeJsString(targetUser.full_name || '')}', '${escapeJsString(targetUser.email || '')}')" class="users-action-btn users-action-approve users-action-reset-pending" title="Reset this user's password">${usersIcon('bell')} Reset Password</button>
                <button type="button" onclick="openInactiveUserModal(${safeId}, '${escapeJsString(targetUser.full_name || '')}')" class="users-action-btn users-action-activate" title="Set this user to inactive">Set Inactive</button>
            `;
        } else {
            actionButtons = `
                ${editButton}
                <button type="button" onclick="openInactiveUserModal(${safeId}, '${escapeJsString(targetUser.full_name || '')}')" class="users-action-btn users-action-activate" title="Set this user to inactive">Set Inactive</button>
            `;
        }
    }

    return `
        <article class="users-card" data-user-id="${safeId}">
            <div class="users-card-media">
                ${mediaMarkup}
                <div class="users-card-badges">
                    <span class="users-role-pill users-role-${normalizedRole}">${escapeHtml(roleLabel)}</span>
                    <span class="users-status-pill ${statusClass}">${escapeHtml(statusLabel)}</span>
                </div>
            </div>
            <div class="users-card-body">
                <div class="users-card-headline">
                    <div class="users-card-name-row">
                        <h3>${safeName}</h3>
                        <div class="users-mini-avatar">${getInitials(targetUser.full_name || 'U')}</div>
                    </div>
                </div>
                <div class="users-card-meta">
                    ${safeDesignation ? `<div class="users-card-row users-card-row-designation">
                        <span class="users-card-icon">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="7" width="20" height="14" rx="2"/>
                                <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                            </svg>
                        </span>
                        <span class="users-card-row-text users-card-designation-text">${safeDesignation}</span>
                    </div>` : ''}
                    <!--
                        2026-09-29 -- the department row (home icon +
                        safeDept) was removed from this card per
                        Administrator request: when a designation is also
                        set, the designation text often already names the
                        department (e.g. "Head Computer" above "Computer"),
                        making the department row read as duplicated
                        information. Removed for every card, not only ones
                        where the overlap is literal, per the Administrator's
                        own "ganon din sa iba" instruction. safeDept is left
                        computed above in case this row needs to come back.
                    -->
                    <!--
                        2026-09-29 -- the at-sign username/email row was
                        removed from this card per Administrator request:
                        several existing accounts have a stored username
                        value that does not match how that person actually
                        registered (placeholder/auto-derived values from
                        earlier test data), and showing it with an at-sign
                        prefix read as an email to the Administrator.
                        Removed here rather than fixed in display logic,
                        since the display was already showing the literal
                        username column correctly -- the underlying data is
                        what needs correcting, which is a separate decision
                        (the Edit User modal deliberately excludes username,
                        per this Administrator's own earlier instruction).
                        safeHandle and safeEmail are left computed above in
                        case this row needs to come back once the underlying
                        data question is resolved.
                    -->
                    <div class="users-card-row">
                        <span class="users-card-icon">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                        </span>
                        <span class="users-card-row-text">Joined ${joinedDate}</span>
                    </div>
                </div>
                <div class="users-card-actions">${actionButtons}</div>
            </div>
        </article>`;
}

function buildAvatarMarkup(targetUser) {
    const avatarUrl = normalizeAvatarUrl(targetUser.avatar);
    const initials = getInitials(targetUser.full_name || 'U');

    if (avatarUrl) {
        return `
            <div class="users-avatar-wrap">
                <img src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(targetUser.full_name || 'User')} profile" class="users-avatar-image" loading="lazy" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                <div class="users-avatar-fallback users-avatar-fallback-hidden">${escapeHtml(initials)}</div>
            </div>`;
    }

    return `<div class="users-avatar-fallback">${escapeHtml(initials)}</div>`;
}

function buildCardMediaMarkup(targetUser) {
    const avatarUrl = normalizeAvatarUrl(targetUser.avatar);
    const initials = getInitials(targetUser.full_name || 'U');

    if (avatarUrl) {
        return `
            <div class="users-card-media-avatar-wrap">
                <img src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(targetUser.full_name || 'User')} profile" class="users-card-media-image" loading="lazy" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                <div class="users-card-media-fallback users-card-media-fallback-hidden">
                    <div class="users-card-media-orbit"></div>
                    <div class="users-card-media-initials">${escapeHtml(initials)}</div>
                </div>
            </div>`;
    }

    return `
        <div class="users-card-media-fallback">
            <div class="users-card-media-orbit"></div>
            <div class="users-card-media-initials">${escapeHtml(initials)}</div>
        </div>`;
}

function normalizeAvatarUrl(avatar) {
    const value = String(avatar || '').trim();
    if (!value) return '';
    if (/^https?:\/\//i.test(value) || value.startsWith('/')) return value;
    if (window.SFMS_PUBLIC_URL) {
        return window.SFMS_PUBLIC_URL('/' + value.replace(/^\/?/, ''));
    }
    return '/' + value.replace(/^\/?/, '');
}

function getInitials(name) {
    return String(name || 'U')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || 'U';
}

function getRoleLabel(role) {
    const labels = {
        super_admin:       'Administrator',
        maintenance_admin: 'Head',
        maintenance_staff: 'Staff',
        user:              'Data Entry',
        client:            'Client',
    };
    if (labels[role]) return labels[role];
    return role.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function getStatusLabel(status) {
    const labels = {
        active: 'Active',
        pending: 'Pending',
        inactive: 'Inactive',
        suspended: 'Suspended'
    };

    return labels[status] || 'Inactive';
}

function getStatusClass(status) {
    if (status === 'active') return 'is-active';
    if (status === 'pending') return 'is-pending';
    return 'is-inactive';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeJsString(value) {
    return String(value ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/\r?\n/g, ' ');
}

// TASK 7 — renders an icon from the shared registry (includes/icon-paths.php,
// serialised into the page by header.php). Used by the row action buttons,
// which previously carried emoji glyphs. Each button keeps its visible text
// label and its title attribute, so these icons stay decorative.
function usersIcon(name) {
    return window.UIIcons ? window.UIIcons.svg(name, { size: 13 }) : '';
}

function openRegisterUserModal() {
    if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
    }

    const existingModal = document.getElementById('register-user-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'register-user-modal';
    modal.className = 'users-modal-overlay';

    modal.innerHTML = `
        <div class="users-modal-card users-register-modal-card">
            <h3 class="users-modal-title">Register User</h3>
            <p class="users-modal-desc">Create a new active account. Employee ID is auto-generated based on today\'s date (example: 20260409). The user will be prompted to update their profile on first open.</p>
            <div id="register-form-error" class="users-modal-field-error" style="margin-bottom:0.85rem;"></div>

            <div class="users-modal-section-title">Personal Information</div>
            <!-- COMPACT FORM LAYOUT — the eight register fields are paired into
                 four .users-modal-inline-fields rows (Last/First, Middle/Suffix,
                 Username/Password, Role/Department) purely to shorten the modal.
                 Field order, ids, names, placeholders, validation hooks and the
                 submit payload are untouched; the row wrapper only supplies the
                 two-column layout, which collapses to one column on narrow
                 viewports via the existing max-width:640px rule. -->
            <div class="users-modal-inline-fields">
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-last-name" class="users-modal-label">Last Name</label>
                    <input id="register-last-name" class="users-modal-input" type="text" placeholder="Enter last name" autocomplete="off" autocapitalize="words" autocorrect="off" spellcheck="false">
                    <div id="register-last-name-error" class="users-modal-field-error"></div>
                </div>
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-first-name" class="users-modal-label">First Name</label>
                    <input id="register-first-name" class="users-modal-input" type="text" placeholder="Enter first name" autocomplete="off" autocapitalize="words" autocorrect="off" spellcheck="false">
                    <div id="register-first-name-error" class="users-modal-field-error"></div>
                </div>
            </div>
            <div class="users-modal-inline-fields">
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-middle-initial" class="users-modal-label">Middle Initial</label>
                    <input id="register-middle-initial" class="users-modal-input" type="text" placeholder="Optional (e.g., D)" maxlength="1" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false">
                    <div id="register-middle-initial-error" class="users-modal-field-error"></div>
                </div>
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-suffix" class="users-modal-label">Suffix</label>
                    <input id="register-suffix" class="users-modal-input" type="text" placeholder="Optional (e.g., Jr., Sr., III)" autocomplete="off" autocapitalize="words" autocorrect="off" spellcheck="false">
                    <div id="register-suffix-error" class="users-modal-field-error"></div>
                </div>
            </div>

            <div class="users-modal-section-title">Account Information</div>
            <div class="users-modal-inline-fields">
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-username" class="users-modal-label">Username *</label>
                    <input id="register-username" class="users-modal-input" type="text" placeholder="e.g. juan_dela_cruz" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <div id="register-username-error" class="users-modal-field-error"></div>
                </div>
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-password" class="users-modal-label">Password</label>
                    <div class="users-password-wrap">
                        <input id="register-password" class="users-modal-input" type="password" placeholder="Minimum 8 characters" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false">
                        <button
                            type="button"
                            id="toggle-register-password"
                            class="users-password-toggle"
                            aria-label="Show password"
                            aria-pressed="false"
                            title="Show password"
                        >
                            <svg id="register-password-eye" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                    <div id="register-password-error" class="users-modal-field-error"></div>
                </div>
            </div>

            <!-- COMPACT FORM LAYOUT — Role and Department now share one row, so
                 the former "Additional Information" heading (which existed only
                 to separate Department from Role) was folded into this one.
                 No field was added, removed or reordered. -->
            <div class="users-modal-section-title">Role Information</div>
            <div class="users-modal-inline-fields">
                <div class="users-modal-field users-modal-field-inline">
                    <label for="register-role" class="users-modal-label">Role</label>
                    <select id="register-role" class="users-modal-select">
                        <option value="super_admin">Administrator</option>
                        <option value="maintenance_admin">Head</option>
                        <option value="maintenance_staff" selected>Staff</option>
                    </select>
                </div>

                <!-- TASK 37: shown/hidden and required/optional by role via
                     syncRoleDependentFields(). Administrator is system-wide and
                     has no department, so this whole group is hidden for it. -->
                <div class="users-modal-field users-modal-field-inline" id="register-department-group">
                    <label for="register-department" class="users-modal-label" id="register-department-label">Department</label>
                    <select id="register-department" class="users-modal-select">
                        <option value="">No specific department</option>
                    </select>
                    <div id="register-department-error" class="users-modal-field-error"></div>
                </div>
            </div>
            <div class="users-modal-field" id="register-designation-group" style="display:none;">
                <label for="register-designation" class="users-modal-label">Designation</label>
                <input id="register-designation" class="users-modal-input" type="text" placeholder="e.g. Head Electrical, Computer Technician" autocomplete="off">
                <small style="color:rgba(148,163,184,0.7);font-size:12px;">Specify the department or specialization (e.g. Head Electrical, Head Computer, Electrical Technician)</small>
            </div>
            <div class="users-modal-actions">
                <button type="button" id="cancel-register-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-register-btn" class="users-modal-btn users-modal-btn-primary">Create</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('register-user-modal');
        if (el) el.remove();
        // TASK 37 — drop the reference so an in-flight loadDepartmentsForModal()
        // cannot call back into a sync bound to a removed modal.
        syncRegisterRoleFields = null;
    };

    document.getElementById('cancel-register-btn')?.addEventListener('click', closeModal);

    const registerFormError = document.getElementById('register-form-error');
    const setRegisterModalError = (message) => {
        if (!registerFormError) return;
        const safeMessage = String(message || '').trim();
        registerFormError.textContent = safeMessage;
        registerFormError.classList.toggle('is-visible', safeMessage.length > 0);
    };

    // Add real-time validation
    const lastNameInput = document.getElementById('register-last-name');
    const firstNameInput = document.getElementById('register-first-name');
    const middleInitialInput = document.getElementById('register-middle-initial');
    const suffixInput = document.getElementById('register-suffix');
    const usernameInput = document.getElementById('register-username');
    const passwordInput = document.getElementById('register-password');

    lastNameInput?.addEventListener('blur', () => validateRequiredNameField(lastNameInput, 'Last name'));
    firstNameInput?.addEventListener('blur', () => validateRequiredNameField(firstNameInput, 'First name'));
    middleInitialInput?.addEventListener('blur', () => validateMiddleInitialField(middleInitialInput));
    suffixInput?.addEventListener('blur', () => validateSuffixField(suffixInput));
    usernameInput?.addEventListener('blur', () => validateUsernameField(usernameInput));
    passwordInput?.addEventListener('blur', () => validatePasswordField(passwordInput));

    const roleSelect = document.getElementById('register-role');
    const designationGroup = document.getElementById('register-designation-group');
    const departmentGroup = document.getElementById('register-department-group');
    const departmentSelect = document.getElementById('register-department');
    const departmentLabel = document.getElementById('register-department-label');

    /**
     * TASK 37 — everything that depends on the selected role, applied in one
     * place so a role change can never leave half the form describing the
     * previous role.
     */
    function syncRoleDependentFields() {
        const role = roleSelect ? roleSelect.value : '';
        if (designationGroup) designationGroup.style.display = (role === 'maintenance_admin' || role === 'maintenance_staff') ? 'block' : 'none';

        const needsDepartment = roleRequiresDepartment(role);
        if (departmentGroup) departmentGroup.style.display = needsDepartment ? 'block' : 'none';
        if (departmentLabel) departmentLabel.textContent = needsDepartment ? 'Department *' : 'Department';
        if (departmentSelect) {
            departmentSelect.required = needsDepartment;

            const placeholder = departmentSelect.querySelector('option[value=""]');
            if (placeholder) {
                placeholder.textContent = needsDepartment ? 'Select a department' : 'No specific department';
            }

            if (!needsDepartment) {
                // Switching to Administrator must not leave a department
                // selected behind a hidden field — it would still be read at
                // submit time — nor leave the previous role's "Department is
                // required" error on screen blocking a now-valid form.
                departmentSelect.value = '';
                setFieldValidationState(departmentSelect, '');
            }
        }
    }
    roleSelect?.addEventListener('change', syncRoleDependentFields);
    syncRegisterRoleFields = syncRoleDependentFields;
    syncRoleDependentFields();

        // Password eye icon toggle
        const toggleRegisterPasswordBtn = document.getElementById('toggle-register-password');
        const registerPasswordInput = document.getElementById('register-password');
        const registerPasswordEye = document.getElementById('register-password-eye');
        if (toggleRegisterPasswordBtn && registerPasswordInput && registerPasswordEye) {
            toggleRegisterPasswordBtn.addEventListener('click', () => {
                const isVisible = registerPasswordInput.type === 'text';
                registerPasswordInput.type = isVisible ? 'password' : 'text';
                toggleRegisterPasswordBtn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
                toggleRegisterPasswordBtn.setAttribute('aria-pressed', String(!isVisible));
                toggleRegisterPasswordBtn.setAttribute('title', isVisible ? 'Show password' : 'Hide password');
                registerPasswordEye.innerHTML = isVisible
                    ? '<path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>'
                    : '<path d="M3 3L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10.6 10.7C10.2 11.1 10 11.5 10 12C10 13.1 10.9 14 12 14C12.5 14 12.9 13.8 13.3 13.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 5.2C10.6 5.1 11.3 5 12 5C16.5 5 20.2 7.9 22 12C21.3 13.6 20.2 15 18.8 16.1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.3 6.3C4.6 7.5 3.2 9.4 2 12C3.8 16.1 7.5 19 12 19C13.7 19 15.3 18.6 16.6 17.9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
            });
        }
    
    document.getElementById('confirm-register-btn')?.addEventListener('click', async () => {
        setRegisterModalError('');

        const lastName = lastNameInput.value.trim();
        const firstName = firstNameInput.value.trim();
        const middleInitial = middleInitialInput.value.trim();
        const suffix = suffixInput.value.trim();
        const fullName = buildFullName(lastName, firstName, middleInitial);
        const username = usernameInput.value.trim().toLowerCase();
        const password = passwordInput.value;
        const role = document.getElementById('register-role').value;

        // Validate all fields
        const lastNameError = getRequiredNameValidationError(lastName, 'Last name');
        const firstNameError = getRequiredNameValidationError(firstName, 'First name');
        const middleInitialError = getMiddleInitialValidationError(middleInitial);
        const suffixError = getSuffixValidationError(suffix);
        const usernameError = getUsernameValidationError(username);
        const passwordError = getPasswordValidationError(password);

        setFieldValidationState(lastNameInput, lastNameError);
        setFieldValidationState(firstNameInput, firstNameError);
        setFieldValidationState(middleInitialInput, middleInitialError);
        setFieldValidationState(suffixInput, suffixError);
        setFieldValidationState(usernameInput, usernameError);
        setFieldValidationState(passwordInput, passwordError);

        if (lastNameError || firstNameError || middleInitialError || suffixError || usernameError || passwordError) {
            return;
        }

        if (!role) {
            setRegisterModalError('Please select a role.');
            return;
        }

        // TASK 37 — mirrors the backend rule so the Administrator can submit
        // with no department while Head/Maintenance Staff cannot.
        const needsDepartment = roleRequiresDepartment(role);
        const selectedDepartmentId = departmentSelect?.value || '';
        if (needsDepartment && selectedDepartmentId === '') {
            setFieldValidationState(departmentSelect, 'Department is required for this role');
            departmentSelect?.focus();
            return;
        }
        setFieldValidationState(departmentSelect, '');

        try {
            const response = await fetch(window.SFMS_PUBLIC_URL('/api/users'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    last_name: lastName,
                    first_name: firstName,
                    middle_initial: middleInitial,
                    suffix: suffix,
                    full_name: fullName,
                    username: username,
                    password,
                    role,
                    designation: document.getElementById('register-designation')?.value.trim() || '',
                    // TASK 37 — never send a department for a role that has
                    // none. syncRoleDependentFields() already clears the
                    // select, but deriving the payload from the role too means
                    // a stale value cannot reach the API by any path.
                    department_id: needsDepartment ? (selectedDepartmentId || null) : null
                })
            });

            const data = await response.json();

            if (data.success) {
                const generatedEmployeeId = data?.data?.employee_id || data?.employee_id || '';
                const successMessage = generatedEmployeeId
                    ? `User created successfully. Generated ID: ${generatedEmployeeId}`
                    : 'User created successfully and marked for profile setup.';

                showPageAlert(successMessage, 'success');
                closeModal();
                loadUsers();
            } else {
                const apiMessage = String(data.message || '').trim();
                // Laravel's $request->validate() failure response looks like
                // {"message": "The given data was invalid.", "errors": {"username": [...], "email": [...]}}
                // — the specific "already taken" reason lives under `errors`,
                // NOT in the generic top-level `message`. Check both: `errors`
                // first (covers the store() validate() path), falling back to
                // scanning `message` (covers any handler that returns a plain
                // fail() string instead, e.g. updateProfile()).
                const fieldErrors = data.errors || {};
                const usernameErrors = [].concat(fieldErrors.username || []).join(' ').toLowerCase();
                const normalized = apiMessage.toLowerCase();
                const takenWords = (text) => text.includes('taken') || text.includes('exist') || text.includes('duplicate') || text.includes('already');

                const isUsernameTaken = takenWords(usernameErrors)
                    || (normalized.includes('username') && takenWords(normalized));
                // Backend's duplicateNameExists() guard (UserController::store())
                // returns a plain fail() message mentioning "first and last name",
                // not a per-field `errors` entry — matched by text the same way
                // the username-taken case above is.
                const isNameTaken = normalized.includes('first and last name') && takenWords(normalized);

                if (isUsernameTaken) {
                    setFieldValidationState(usernameInput, 'User name is already taken');
                    setRegisterModalError('');
                    usernameInput?.focus();
                } else if (isNameTaken) {
                    setFieldValidationState(lastNameInput, 'An account with this name already exists');
                    setFieldValidationState(firstNameInput, 'An account with this name already exists');
                    setRegisterModalError('');
                    lastNameInput?.focus();
                } else {
                    setRegisterModalError(apiMessage || 'Failed to create user');
                }
            }
        } catch (error) {
            console.error('Register error:', error);
            setRegisterModalError(error?.message ? `Failed to create user: ${error.message}` : 'Failed to create user');
        }
    });

}

function openApproveUserModal(userId, userName) {
    const existingModal = document.getElementById('approve-user-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'approve-user-modal';
    modal.className = 'users-modal-overlay';

    modal.innerHTML = `
        <div class="users-modal-card">
            <h3 class="users-modal-title">Approve Pending User</h3>
            <p class="users-modal-desc">Approve <strong>${escapeHtml(userName || 'N/A')}</strong> and assign role.</p>
            <div class="users-modal-field">
                <label for="approve-user-role" class="users-modal-label">Assign role</label>
                <select id="approve-user-role" class="users-modal-select">
                    <option value="maintenance_staff" selected>Staff</option>
                    <option value="maintenance_admin">Head</option>
                </select>
            </div>
            <div class="users-modal-field">
                <label for="approve-user-department" class="users-modal-label">Department</label>
                <select id="approve-user-department" class="users-modal-select">
                    <option value="">Loading departments...</option>
                </select>
                <div id="approve-user-department-error" class="users-modal-field-error"></div>
            </div>
            <div class="users-modal-actions">
                <button type="button" id="cancel-approve-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-approve-btn" class="users-modal-btn users-modal-btn-primary">Approve</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('approve-user-modal');
        if (el) el.remove();
    };

    // Department list for the approval (report and dispatch permissions are
    // department-based, so the Administrator picks it here).
    (async () => {
        const select = document.getElementById('approve-user-department');
        try {
            const res = await fetch(window.SFMS_PUBLIC_URL('/api/departments'), { credentials: 'include' });
            const data = await res.json();
            const depts = data.departments || (data.data && data.data.departments) || [];
            select.innerHTML = '<option value="">Select department</option>'
                + depts.map((d) => `<option value="${escapeHtml(String(d.department_id))}">${escapeHtml(d.name)}</option>`).join('');
        } catch (e) {
            select.innerHTML = '<option value="">Could not load departments</option>';
        }
    })();

    document.getElementById('cancel-approve-btn')?.addEventListener('click', closeModal);
    document.getElementById('confirm-approve-btn')?.addEventListener('click', async () => {
        const role = document.getElementById('approve-user-role').value;
        const departmentId = document.getElementById('approve-user-department').value;
        const errorEl = document.getElementById('approve-user-department-error');
        if (!departmentId) {
            errorEl.textContent = 'Please choose the department this user belongs to.';
            return;
        }
        errorEl.textContent = '';
        const approved = await approveUser(userId, role, departmentId);
        if (approved) closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
}

async function approveUser(userId, role, departmentId) {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/users/${userId}/approve`), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ role, department_id: departmentId ? Number(departmentId) : null })
        });

        const data = await response.json();

        if (data.success) {
            showPageAlert('User approved and role assigned successfully!', 'success');
            loadUsers();
            return true;
        }
        const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
        showPageAlert('Error: ' + (firstError || data.message || 'Failed to approve user'), 'error');
        return false;
    } catch (error) {
        console.error('Approve error:', error);
        showPageAlert('Failed to approve user: ' + error.message, 'error');
        return false;
    }
}

/**
 * Edit an already-approved account's Full Name, Role, Department and
 * Designation — the fix for "what if a Staff gets promoted to Head?" (there
 * was previously no way to change a role after approve() assigned it once).
 *
 * Username and password are deliberately not editable here: username stays
 * read-only (shown for reference only) and password keeps its own dedicated
 * Reset Password action, mirroring UserController::update()'s doc comment.
 */
function openEditUserModal(userId) {
    const targetUser = usersState.allUsers.find((u) => Number(u.user_id) === Number(userId));
    if (!targetUser) {
        showPageAlert('Could not find that user. Try refreshing the page.', 'error');
        return;
    }

    const existingModal = document.getElementById('edit-user-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'edit-user-modal';
    modal.className = 'users-modal-overlay';

    const currentRole = String(targetUser.role || 'maintenance_staff').toLowerCase();

    modal.innerHTML = `
        <div class="users-modal-card">
            <h3 class="users-modal-title">Edit User</h3>
            <p class="users-modal-desc">Update <strong>${escapeHtml(targetUser.full_name || 'N/A')}</strong>'s profile, role, and department.</p>
            <div id="edit-user-form-error" class="users-modal-field-error" style="margin-bottom:0.85rem;"></div>

            <div class="users-modal-field">
                <label for="edit-user-full-name" class="users-modal-label">Full Name</label>
                <input id="edit-user-full-name" class="users-modal-input" type="text" value="${escapeHtml(targetUser.full_name || '')}" autocomplete="off">
                <div id="edit-user-full-name-error" class="users-modal-field-error"></div>
            </div>

            <div class="users-modal-field">
                <label class="users-modal-label">Username</label>
                <input class="users-modal-input" type="text" value="${escapeHtml(targetUser.username || '')}" disabled title="Username cannot be changed here">
                <small style="color:rgba(148,163,184,0.7);font-size:12px;">Username can't be changed. Need a password reset instead? Use the Reset Password action.</small>
            </div>

            <div class="users-modal-field">
                <label for="edit-user-role" class="users-modal-label">Role</label>
                <select id="edit-user-role" class="users-modal-select">
                    <option value="maintenance_staff"${currentRole === 'maintenance_staff' ? ' selected' : ''}>Staff</option>
                    <option value="maintenance_admin"${currentRole === 'maintenance_admin' ? ' selected' : ''}>Head</option>
                </select>
            </div>

            <div class="users-modal-field" id="edit-user-department-group">
                <label for="edit-user-department" class="users-modal-label" id="edit-user-department-label">Department *</label>
                <select id="edit-user-department" class="users-modal-select">
                    <option value="">Loading departments...</option>
                </select>
                <div id="edit-user-department-error" class="users-modal-field-error"></div>
            </div>

            <div class="users-modal-field">
                <label for="edit-user-designation" class="users-modal-label">Designation</label>
                <input id="edit-user-designation" class="users-modal-input" type="text" placeholder="e.g. Head Electrical, Computer Technician" value="${escapeHtml(targetUser.designation || '')}" autocomplete="off">
            </div>

            <div class="users-modal-actions">
                <button type="button" id="cancel-edit-user-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-edit-user-btn" class="users-modal-btn users-modal-btn-primary">Save Changes</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('edit-user-modal');
        if (el) el.remove();
    };

    const setEditModalError = (message) => {
        const el = document.getElementById('edit-user-form-error');
        if (!el) return;
        const safeMessage = String(message || '').trim();
        el.textContent = safeMessage;
        el.classList.toggle('is-visible', safeMessage.length > 0);
    };

    const roleSelect = document.getElementById('edit-user-role');
    const departmentSelect = document.getElementById('edit-user-department');
    const departmentLabel = document.getElementById('edit-user-department-label');

    // Both roles offered here (Staff, Head) require a department (see
    // DEPARTMENT_REQUIRED_ROLES), so unlike the Register modal there is no
    // "hide the department field" case — only the label/required-ness would
    // ever change if that ever stopped being true for both roles at once.
    (async () => {
        try {
            const res = await fetch(window.SFMS_PUBLIC_URL('/api/departments'), { credentials: 'include' });
            const data = await res.json();
            const depts = data.departments || (data.data && data.data.departments) || [];
            const currentDeptId = targetUser.department_id ? String(targetUser.department_id) : '';
            departmentSelect.innerHTML = '<option value="">Select a department</option>'
                + depts.map((d) => `<option value="${escapeHtml(String(d.department_id))}"${String(d.department_id) === currentDeptId ? ' selected' : ''}>${escapeHtml(d.name)}</option>`).join('');
        } catch (e) {
            departmentSelect.innerHTML = '<option value="">Could not load departments</option>';
        }
    })();

    document.getElementById('cancel-edit-user-btn')?.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });

    document.getElementById('confirm-edit-user-btn')?.addEventListener('click', async () => {
        setEditModalError('');

        const fullNameInput = document.getElementById('edit-user-full-name');
        const fullName = fullNameInput.value.trim();
        const fullNameError = getRequiredNameValidationError(fullName, 'Full name');
        setFieldValidationState(fullNameInput, fullNameError);
        if (fullNameError) return;

        const role = roleSelect.value;
        const departmentId = departmentSelect.value;
        if (!departmentId) {
            setFieldValidationState(departmentSelect, 'Department is required for this role');
            departmentSelect.focus();
            return;
        }
        setFieldValidationState(departmentSelect, '');

        const designation = document.getElementById('edit-user-designation').value.trim();

        try {
            const response = await fetch(window.SFMS_PUBLIC_URL(`/api/users/${userId}`), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    full_name: fullName,
                    role,
                    department_id: Number(departmentId),
                    designation: designation || null
                })
            });

            const data = await response.json();

            if (data.success) {
                showPageAlert('User updated successfully!', 'success');
                closeModal();
                loadUsers();
            } else {
                const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
                const apiMessage = String(data.message || '').trim();
                // Backend's duplicateNameExists() guard (UserController::update())
                // returns a plain fail() message rather than a per-field `errors`
                // entry, since this modal only has one combined full-name input.
                if (!firstError && apiMessage.toLowerCase().includes('first and last name')) {
                    setFieldValidationState(fullNameInput, 'An account with this name already exists');
                }
                setEditModalError(firstError || apiMessage || 'Failed to update user');
            }
        } catch (error) {
            console.error('Edit user error:', error);
            setEditModalError(error?.message ? `Failed to update user: ${error.message}` : 'Failed to update user');
        }
    });
}

function openInactiveUserModal(userId, userName) {
    const existingModal = document.getElementById('inactive-user-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'inactive-user-modal';
    modal.className = 'users-modal-overlay';

    modal.innerHTML = `
        <div class="users-modal-card">
            <h3 class="users-modal-title">Set User Inactive</h3>
            <p class="users-modal-desc">Set <strong>${escapeHtml(userName || 'N/A')}</strong> to inactive? This account will no longer be able to log in.</p>
            <div class="users-modal-actions">
                <button type="button" id="cancel-inactive-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-inactive-btn" class="users-modal-btn users-modal-btn-warning">Inactive</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('inactive-user-modal');
        if (el) el.remove();
    };

    document.getElementById('cancel-inactive-btn')?.addEventListener('click', closeModal);
    document.getElementById('confirm-inactive-btn')?.addEventListener('click', async () => {
        await deactivateUser(userId);
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
}

function openResetPasswordModal(userId, userName, userEmail) {
    const existingModal = document.getElementById('reset-password-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'reset-password-modal';
    modal.className = 'users-modal-overlay';

    modal.innerHTML = `
        <div class="users-modal-card">
            <h3 class="users-modal-title">Reset User Password</h3>
            <p class="users-modal-desc">Set a temporary password for <strong>${escapeHtml(userName || 'N/A')}</strong>${userEmail ? ` (${escapeHtml(userEmail)})` : ''}. The user will be required to update their password after signing in.</p>
            <div class="users-modal-field">
                <label for="reset-password-input" class="users-modal-label">Temporary Password</label>
                <div class="users-password-wrap">
                    <input id="reset-password-input" class="users-modal-input" type="password" placeholder="Enter temporary password" autocomplete="new-password">
                    <button
                        type="button"
                        id="toggle-reset-password"
                        class="users-password-toggle"
                        aria-label="Show password"
                        aria-pressed="false"
                        title="Show password"
                    >
                        <svg id="reset-password-eye" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>
                </div>
                <div id="reset-password-error" class="users-modal-field-error"></div>
            </div>
            <div class="users-modal-actions">
                <button type="button" id="cancel-reset-password-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-reset-password-btn" class="users-modal-btn users-modal-btn-primary">Reset Password</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('reset-password-modal');
        if (el) el.remove();
    };

    document.getElementById('cancel-reset-password-btn')?.addEventListener('click', closeModal);
    
    // Password eye icon toggle for reset password
    const toggleResetPasswordBtn = document.getElementById('toggle-reset-password');
    const resetPasswordInput = document.getElementById('reset-password-input');
    const resetPasswordEye = document.getElementById('reset-password-eye');
    if (toggleResetPasswordBtn && resetPasswordInput && resetPasswordEye) {
        toggleResetPasswordBtn.addEventListener('click', () => {
            const isVisible = resetPasswordInput.type === 'text';
            resetPasswordInput.type = isVisible ? 'password' : 'text';
            toggleResetPasswordBtn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            toggleResetPasswordBtn.setAttribute('aria-pressed', String(!isVisible));
            toggleResetPasswordBtn.setAttribute('title', isVisible ? 'Show password' : 'Hide password');
            resetPasswordEye.innerHTML = isVisible
                ? '<path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>'
                : '<path d="M3 3L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10.6 10.7C10.2 11.1 10 11.5 10 12C10 13.1 10.9 14 12 14C12.5 14 12.9 13.8 13.3 13.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 5.2C10.6 5.1 11.3 5 12 5C16.5 5 20.2 7.9 22 12C21.3 13.6 20.2 15 18.8 16.1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.3 6.3C4.6 7.5 3.2 9.4 2 12C3.8 16.1 7.5 19 12 19C13.7 19 15.3 18.6 16.6 17.9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        });
    }
    
    document.getElementById('confirm-reset-password-btn')?.addEventListener('click', async () => {
        const passwordInput = document.getElementById('reset-password-input');
        const errorEl = document.getElementById('reset-password-error');
        const newPassword = String(passwordInput?.value || '');

        if (newPassword.length < 8) {
            if (errorEl) {
                errorEl.textContent = 'Temporary password must be at least 8 characters.';
            }
            return;
        }

        await resetUserPassword(userId, newPassword);
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
}

async function resetUserPassword(userId, newPassword) {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/users/${userId}/reset-password`), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ new_password: newPassword })
        });

        const data = await response.json();

        if (data.success) {
            showPageAlert('Password reset successfully. Share the temporary password with the user securely.', 'success');
            loadUsers();
        } else {
            showPageAlert('Error: ' + (data.message || 'Failed to reset password'), 'error');
        }
    } catch (error) {
        console.error('Reset password error:', error);
        showPageAlert('Failed to reset password: ' + error.message, 'error');
    }
}

async function deactivateUser(userId) {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/users/${userId}/deactivate`), {
            method: 'PATCH',
            credentials: 'include'
        });

        const data = await response.json();

        if (data.success) {
            showPageAlert('User set to inactive successfully!', 'success');
            loadUsers();
        } else {
            showPageAlert('Error: ' + (data.message || 'Failed to set user inactive'), 'error');
        }
    } catch (error) {
        console.error('Deactivate user error:', error);
        showPageAlert('Failed to set user inactive: ' + error.message, 'error');
    }
}

function openRejectUserModal(userId, userName) {
    const existingModal = document.getElementById('reject-user-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'reject-user-modal';
    modal.className = 'users-modal-overlay';

    modal.innerHTML = `
        <div class="users-modal-card">
            <h3 class="users-modal-title">Reject Pending User</h3>
            <p class="users-modal-desc">Reject <strong>${escapeHtml(userName || 'N/A')}</strong>? This will permanently delete the pending account.</p>
            <div class="users-modal-actions">
                <button type="button" id="cancel-reject-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-reject-btn" class="users-modal-btn users-modal-btn-danger">Reject</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('reject-user-modal');
        if (el) el.remove();
    };

    document.getElementById('cancel-reject-btn')?.addEventListener('click', closeModal);
    document.getElementById('confirm-reject-btn')?.addEventListener('click', async () => {
        await rejectUser(userId);
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
}

async function rejectUser(userId) {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/users/${userId}/reject`), {
            method: 'DELETE',
            credentials: 'include'
        });

        const data = await response.json();

        if (data.success) {
            showPageAlert('Pending user rejected and account deleted successfully!', 'success');
            loadUsers();
        } else {
            showPageAlert('Error: ' + (data.message || 'Failed to reject user'), 'error');
        }
    } catch (error) {
        console.error('Reject user error:', error);
        showPageAlert('Failed to reject user: ' + error.message, 'error');
    }
}

function openActivateUserModal(userId, userName) {
    const existingModal = document.getElementById('activate-user-modal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'activate-user-modal';
    modal.className = 'users-modal-overlay';

    modal.innerHTML = `
        <div class="users-modal-card">
            <h3 class="users-modal-title">Set User Active</h3>
            <p class="users-modal-desc">Set <strong>${escapeHtml(userName || 'N/A')}</strong> to active? This account will be able to log in again.</p>
            <div class="users-modal-actions">
                <button type="button" id="cancel-activate-btn" class="users-modal-btn users-modal-btn-secondary">Cancel</button>
                <button type="button" id="confirm-activate-btn" class="users-modal-btn users-modal-btn-success">Active</button>
            </div>
        </div>`;

    document.body.appendChild(modal);

    const closeModal = () => {
        const el = document.getElementById('activate-user-modal');
        if (el) el.remove();
    };

    document.getElementById('cancel-activate-btn')?.addEventListener('click', closeModal);
    document.getElementById('confirm-activate-btn')?.addEventListener('click', async () => {
        await activateUser(userId);
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
}

async function activateUser(userId) {
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL(`/api/users/${userId}/activate`), {
            method: 'PATCH',
            credentials: 'include'
        });

        const data = await response.json();

        if (data.success) {
            showPageAlert('User set to active successfully!', 'success');
            loadUsers();
        } else {
            showPageAlert('Error: ' + (data.message || 'Failed to set user active'), 'error');
        }
    } catch (error) {
        console.error('Activate user error:', error);
        showPageAlert('Failed to set user active: ' + error.message, 'error');
    }
}

function showPageAlert(message, type = 'success') {
    const alertContainer = document.getElementById('alert-container');
    if (!alertContainer) return;

    const cls = type === 'success' ? 'users-page-alert users-page-alert-success' : 'users-page-alert users-page-alert-error';
    alertContainer.innerHTML = `<div class="${cls}">${escapeHtml(message)}</div>`;

    setTimeout(() => {
        if (alertContainer) alertContainer.innerHTML = '';
    }, 3000);
}

/**
 * Validation functions for user registration form
 */

// Name-part validation: letters, spaces, apostrophes, and hyphens only.
function getRequiredNameValidationError(value, label) {
    if (!value) {
        return `${label} is required.`;
    }

    // Regex: Only allows A-Z, a-z, spaces, hyphens, and apostrophes.
    const nameRegex = /^[a-zA-Z\s'-]+$/;
    if (!nameRegex.test(value)) {
        return `${label} can only contain letters, spaces, hyphens, and apostrophes.`;
    }

    if (value.length < 2) {
        return `${label} must be at least 2 characters long.`;
    }

    if (value.length > 100) {
        return `${label} must not exceed 100 characters.`;
    }

    return null;
}

function getMiddleInitialValidationError(value) {
    if (!value) {
        return null;
    }

    if (!/^[a-zA-Z]$/.test(value)) {
        return 'Middle Initial must be a single letter (A-Z).';
    }

    return null;
}

function buildFullName(lastName, firstName, middleInitial) {
    const nameParts = [`${lastName},`, firstName];

    if (middleInitial) {
        nameParts.push(`${middleInitial.toUpperCase()}.`);
    }

    return nameParts.join(' ').trim();
}

// Username validation
function getUsernameValidationError(username) {
    if (!username) return 'Username is required.';
    if (!/^[a-z][a-z_]{2,49}$/.test(username)) return 'Username must contain letters and underscores only (e.g. juan_dela_cruz).';
    return null;
}

function validateUsernameField(input) {
    setFieldValidationState(input, getUsernameValidationError(input.value.trim().toLowerCase()));
}

// Email validation: must be a valid Gmail address
function getEmailValidationError(email) {
    if (!email) {
        return 'Email is required.';
    }

    // Accept only Gmail addresses and reject typo domains like @gamial.com.
    const gmailRegex = /^[a-zA-Z0-9._+-]+@gmail\.com$/i;
    if (!gmailRegex.test(email)) {
        return 'Email must be a valid Gmail address ending with @gmail.com.';
    }

    // Check email length
    if (email.length > 254) {
        return 'Email is too long. Maximum 254 characters allowed.';
    }

    return null;
}

// Password validation: Minimum 8 characters
function getPasswordValidationError(password) {
    if (!password) {
        return 'Password is required.';
    }

    if (password.length < 8) {
        return 'Password must be at least 8 characters long.';
    }

    if (password.length > 255) {
        return 'Password is too long.';
    }

    return null;
}

// Real-time validation handlers
function validateRequiredNameField(input, label) {
    const error = getRequiredNameValidationError(input.value.trim(), label);
    setFieldValidationState(input, error);
}

function validateEmailField(input) {
    const error = getEmailValidationError(input.value.trim());
    setFieldValidationState(input, error);
}

function validatePasswordField(input) {
    const error = getPasswordValidationError(input.value);
    setFieldValidationState(input, error);
}

function validateMiddleInitialField(input) {
    const error = getMiddleInitialValidationError(input.value.trim());
    setFieldValidationState(input, error);
}

function getSuffixValidationError(value) {
    if (!value) {
        return null;
    }

    if (value.length > 50) {
        return 'Suffix must be 50 characters or less.';
    }

    // Allow letters, numbers, periods, commas, and hyphens
    if (!/^[a-zA-Z0-9.,\-\s]+$/.test(value)) {
        return 'Suffix can only contain letters, numbers, periods, commas, and hyphens.';
    }

    return null;
}

function validateSuffixField(input) {
    const error = getSuffixValidationError(input.value.trim());
    setFieldValidationState(input, error);
}

// Helper to show/hide validation state on field and display inline errors
// TASK 7 — the validation messages used to be built with a leading "❌ " that
// this function then stripped with .replace(/^❌ /, '') before displaying. The
// emoji was therefore never actually visible: it was added in fifteen places
// and removed in the two places that render. Both the prefix and the strip are
// gone, so the text shown to the user is byte-for-byte what it always was.
function setFieldValidationState(input, error) {
    const fieldId = input.id;
    const errorContainer = document.getElementById(fieldId + '-error');

    if (error) {
        input.classList.add('users-modal-input-error');
        input.title = error;
        // Show error message in modal
        if (errorContainer) {
            errorContainer.textContent = error;
            errorContainer.classList.add('is-visible');
        }
    } else {
        input.classList.remove('users-modal-input-error');
        input.title = '';
        // Hide error message in modal
        if (errorContainer) {
            errorContainer.textContent = '';
            errorContainer.classList.remove('is-visible');
        }
    }
}
</script>



<style>
.users-action-reset-pending {
    background: linear-gradient(135deg, #f59e0b, #d97706) !important;
    border-color: transparent !important;
    animation: pulse-pending 2s infinite;
}
@keyframes pulse-pending {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.4); }
    50% { box-shadow: 0 0 0 6px rgba(245,158,11,0); }
}
.status-legend-list {
  margin-top: 16px;
}
.status-legend-item {
  display: flex;
  align-items: center;
  margin-bottom: 8px;
  color: #cbd5e1;
  font-size: 15px;
}
.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
  margin-right: 8px;
}
.legend-label {
  flex: 1;
}
.legend-count {
  font-weight: bold;
  margin-left: 12px;
  color: #f8fafc;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>


