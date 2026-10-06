<?php
/**
 * Clean URLs — the single /dashboard URL for every role.
 *
 * .htaccess rewrites /dashboard to this file, which then runs the dashboard
 * page for the signed-in user's role in place, so the address bar stays
 * /dashboard instead of showing a role-specific .php file.
 *
 * The choice mirrors exactly what the sidebar's Dashboard link has always
 * used (includes/sidebar.php $dashLink):
 *   maintenance_admin (Head Maintenance) -> maintenance-dashboard.php
 *   maintenance_staff                    -> staff-dashboard.php
 *   everyone else (Administrator)        -> dashboard.php
 *
 * Each dashboard still performs its own login and role checks. Those checks
 * redirect back to /dashboard on a mismatch, and because this choice matches
 * what each page accepts, that can never loop: dashboard.php accepts any
 * signed-in user, maintenance-dashboard.php accepts all three roles, and
 * staff-dashboard.php (the only strict one) refreshes the role from the
 * database into the session before redirecting, so the next pass here picks
 * the page that role belongs to.
 */
require_once __DIR__ . '/../../backend/config/settings.php';

$sfmsDashboardUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? null;
if (empty($sfmsDashboardUser)) {
    header('Location: ' . public_url('/login'));
    exit;
}

$sfmsDashboardRole = (string) ($sfmsDashboardUser['role'] ?? '');
if ($sfmsDashboardRole === 'maintenance_admin') {
    $sfmsDashboardPage = 'maintenance-dashboard.php';
} elseif ($sfmsDashboardRole === 'maintenance_staff') {
    $sfmsDashboardPage = 'staff-dashboard.php';
} else {
    $sfmsDashboardPage = 'dashboard.php';
}

// The sidebar marks the active link from basename($_SERVER['PHP_SELF']);
// present the chosen page's own name so it behaves exactly as if that file
// had been requested directly.
$_SERVER['PHP_SELF'] = dirname((string) ($_SERVER['PHP_SELF'] ?? '')) . '/' . $sfmsDashboardPage;
$_SERVER['SCRIPT_NAME'] = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . '/' . $sfmsDashboardPage;

unset($sfmsDashboardUser, $sfmsDashboardRole);
require __DIR__ . '/' . $sfmsDashboardPage;
