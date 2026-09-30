<?php
require_once __DIR__ . '/../../backend/config/settings.php';
/* TASK 7 — ui_icon(). Required here, before any markup, so the header, the
   sidebar (included further down) and every page body that includes this file
   can all render icons. icons.php is guarded by function_exists, so a page
   that also requires it directly is harmless. */
require_once __DIR__ . '/icons.php';

$publicBasePath = function_exists('sfms_public_base_path') ? sfms_public_base_path() : APP_PUBLIC_PATH;

if (!function_exists('public_url')) {
    function public_url($path) {
        global $publicBasePath;
        return ($publicBasePath !== '' ? $publicBasePath : '') . '/' . ltrim((string)$path, '/');
    }
}

if (!defined('SFMS_URL_REWRITE_ACTIVE')) {
    define('SFMS_URL_REWRITE_ACTIVE', true);
    $legacyPrefix = '/School_Facility_Maintenance_System';
    ob_start(function ($buffer) use ($publicBasePath, $legacyPrefix) {
        if ($publicBasePath === $legacyPrefix) {
            return $buffer;
        }

        return str_replace($legacyPrefix, $publicBasePath, $buffer);
    });
}

// Check if user is logged in (except for login page)
$currentPage = basename($_SERVER['PHP_SELF']);
$publicPages = ['index.php', 'login.php'];

if (!in_array($currentPage, $publicPages) && !isset($_SESSION['user'])) {
    header('Location: ' . public_url('/frontend/pages/index.php'));
    exit;
}

// TASK 21 — Stale Session After User Deletion: a browser session can keep
// pointing at a user_id whose row has since been deleted (e.g. by an
// Administrator) while the tab stays open. Continuing to trust
// $_SESSION['user'] in that case is what let a deleted user's session reach
// as far as a database insert and leak a raw SQLSTATE foreign-key error.
// The actual check lives in session-guard.php so it is implemented exactly
// once and shared with the pages that render their own standalone layout
// instead of including this file (edit-report.php, suppliers-manage.php).
if (!in_array($currentPage, $publicPages)) {
    require_once __DIR__ . '/session-guard.php';
    sfms_reject_stale_session();
    sfms_enforce_idle_timeout();
}

$pageTitle = $pageTitle ?? 'SFMS';
// Optional per-page stylesheets: a page may set $pageStylesheets = ['/path/to/file.css', ...]
// before including this file. They are emitted before the shared framework stylesheets
// below (sidebar/styles/layout/color-scheme/header-redesign) so that the cascade order —
// and therefore which rules win for any selector a page-specific file also defines — matches
// what previously resulted from each page's own (now-removed) duplicate <head> block.
$pageStylesheets = (isset($pageStylesheets) && is_array($pageStylesheets)) ? $pageStylesheets : [];
$user = $_SESSION['user'] ?? null;
$isForceProfileUpdate = !empty($user['force_profile_update']);

if ($user && $isForceProfileUpdate && !in_array($currentPage, ['account.php', 'logout.php'], true)) {
    header('Location: ' . public_url('/frontend/pages/account.php?setup=1'));
    exit;
}

$roleTitleMap = [
    'super_admin'           => 'Administrator',
    'maintenance_admin'     => 'Head',
    'maintenance_staff'     => 'Maintenance Staff',
    ''                      => 'Maintenance Staff',
    'user'                  => 'User',
];

// maintenance_admin label is department-aware
if (($user['role'] ?? '') === 'maintenance_admin') {
    $_dept = strtolower(trim((string)($user['department_name'] ?? '')));
    if (strpos($_dept, 'computer') !== false) {
        $userTitle = 'Head Computer';
    } elseif (strpos($_dept, 'electrical') !== false) {
        $userTitle = 'Head Electrical';
    } elseif (strpos($_dept, 'chemical') !== false || strpos($_dept, 'chemistry') !== false) {
        $userTitle = 'Head Chemistry';
    } elseif (strpos($_dept, 'laboratory') !== false || strpos($_dept, 'lab') !== false) {
        $userTitle = 'Head Laboratory';
    } else {
        $userTitle = 'Head';
    }
    unset($_dept);
} else {
    $userTitle = $roleTitleMap[$user['role'] ?? ''] ?? 'User';
}

$brandLink = public_url('/frontend/pages/dashboard.php');
if (!empty($user['role']) && $user['role'] === 'maintenance_admin') {
    $brandLink = public_url('/frontend/pages/maintenance-dashboard.php');
} elseif (!empty($user['role']) && $user['role'] === 'maintenance_staff') {
    $brandLink = public_url('/frontend/pages/staff-dashboard.php');
}

$initialNotifications = [];
$initialNotificationCount = 0;

if ($user && !empty($user['user_id'])) {
    try {
        require_once __DIR__ . '/../../backend/config/database.php';
        require_once __DIR__ . '/../../backend/models/Notification.php';

        $headerPdo = getDBConnection();
        $notificationModel = new Notification($headerPdo);
        $initialNotifications = $notificationModel->getUnread((int)$user['user_id'], 10);
        $initialNotificationCount = count($initialNotifications);
    } catch (Throwable $e) {
        $initialNotifications = [];
        $initialNotificationCount = 0;
    }
}

$headerNow = new DateTimeImmutable('now');
$headerHour = (int)$headerNow->format('G');
$headerGreeting = 'Good evening';
if ($headerHour < 12) {
    $headerGreeting = 'Good morning';
} elseif ($headerHour < 18) {
    $headerGreeting = 'Good afternoon';
}

$headerDateText = $headerNow->format('l, F j');
$headerTimeText = $headerNow->format('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php /* THEME BOOT — runs before any stylesheet is linked, which is the whole
             point: it paints the <html> element's theme attributes synchronously
             so the first frame is already correct. Do not move it below the
             <link> tags and do not defer it, or the unthemed flash returns.

             LIGHT IS THE ONLY THEME. There is no longer a choice to read, so this
             script no longer consults localStorage for a theme, no longer looks at
             prefers-color-scheme, and no longer has a dark branch. The attributes
             are written unconditionally because every colour token is defined
             under :root[data-theme-resolved='light'] — the element still needs the
             attribute, it just can never hold any value but 'light'.

             A previously saved 'dark' preference may still sit in localStorage
             under sfmsThemeMode / sfms_settings_theme / sfms_theme_mode. It is
             deliberately ignored rather than deleted: ignoring it is what makes
             the light theme unconditional for users who had chosen dark, and
             deleting it would be a pointless write on every page load.

             The font-size preference below is a SEPARATE accessibility feature and
             is still read exactly as before — do not remove it while removing
             theme code. */ ?>
    <script>
        (function () {
            var safeFontSizeMode = 'medium';
            var sizeScaleMap = { small: 0.92, medium: 1, large: 1.12 };

            try {
                var fontSizeMode = localStorage.getItem('sfms_settings_font_size') || 'medium';
                safeFontSizeMode = Object.prototype.hasOwnProperty.call(sizeScaleMap, fontSizeMode) ? fontSizeMode : 'medium';
            } catch (error) {
                // localStorage can throw outright (privacy mode, blocked third-party
                // storage). The theme no longer depends on it at all; only the font
                // scale falls back to its default here.
            }

            var root = document.documentElement;

            root.setAttribute('data-theme-mode', 'light');
            root.setAttribute('data-theme-resolved', 'light');
            root.setAttribute('data-theme', 'light');
            root.setAttribute('data-font-size-mode', safeFontSizeMode);
            root.style.colorScheme = 'light';
            root.style.setProperty('--ui-font-scale', String(sizeScaleMap[safeFontSizeMode]));
            root.style.setProperty('--ui-zoom', '1');
        })();
    </script>
    <script>
        window.SFMS_BASE_PATH = <?php echo json_encode($publicBasePath); ?>;
        window.SFMS_PUBLIC_URL = window.SFMS_PUBLIC_URL || function (path) {
            const basePath = String(window.SFMS_BASE_PATH || '').replace(/\/$/, '');
            const normalizedPath = '/' + String(path || '').replace(/^\/+/, '');
            return `${basePath}${normalizedPath}`;
        };
        window.SFMS_BACKEND_API_BASE = window.SFMS_PUBLIC_URL('/backend/api');
        window.SFMS_FRONTEND_BASE = window.SFMS_PUBLIC_URL('/frontend');
    </script>
    <?php foreach ($pageStylesheets as $pageStylesheetHref): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($pageStylesheetHref); ?>">
    <?php endforeach; ?>
    <?php /* Cache-buster bumped for the dark-purple sidebar redesign. The whole
             --sidebar-* palette changed inside this file; a browser holding the
             20260415-4 copy would keep painting the old white rail with
             near-black nav text while the rest of the redesign (spacing, icon
             sizing, the submenu stylesheet) loaded fresh, which reads as a
             half-broken sidebar rather than a stale cache. */ ?>
    <?php /* -2: contrast fix. Must stay in lockstep with the token used by
             resources/views/layouts/app.blade.php — if the two shells request
             different URLs for this file they get independent cache entries
             and can render different sidebar palettes simultaneously. */ ?>
    <?php /* 20260921-1: the brand plate became a circle. .sidebar-brand lost its
             white background, its border and its 14px radius in the same edit
             that gave .sidebar-brand-logo `border-radius: 50%`, so a browser
             holding the 20260920-5 copy would paint the old white rounded
             rectangle around the new circular logo — precisely the defect this
             change removes. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/sidebar.css?v=20260921-1')); ?>">
    <?php /* Bumped with the sidebar contrast fix: the light-theme blanket text
             rule near the end of styles.css now excludes the sidebar subtree.
             A browser holding the 20260522-2 copy keeps painting slate text on
             the dark rail. */ ?>
    <?php /* Both bumped again for the app-wide purple border accent: the
             --purple-* tokens are declared in color-scheme.css and consumed by
             the shared .card / .card-header / .btn-secondary / input rules in
             styles.css. A browser holding either old copy would show half the
             system purple and half of it grey. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/styles.css?v=20260921-2')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/layout.css?v=20260921-2')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/color-scheme.css?v=20260921-2')); ?>">
    <?php /* header-redesign.css is no longer linked. Every one of its ~35 rules
             selected a node inside the removed top bar — .navbar*, .header-profile*,
             .header-divider, .notification-bell, .bell-icon, .notification-count,
             .theme-toggle-*, .network-signal-indicator — so with the bar gone the
             whole file matched nothing and was a wasted request on every page.
             The file itself is left on disk rather than deleted, so this is a
             one-line revert if the bar is ever wanted back. */ ?>
    <?php /* TASK 7 — icon sizing/alignment. Loaded last so it refines the
             stylesheets above without !important and without editing any of
             them. The icons draw their colour from currentColor, so the
             existing theme variables already handle Light and Dark. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/ui-icons.css?v=20260913-1')); ?>">
    <?php /* The compact top app bar (includes/utility-bar.php): breadcrumb +
             page title on the left, search / semester / New report /
             notifications on the right. Loaded LAST on purpose: it has to
             override the .notification-bell / .notification-count rules in
             styles.css, which were written for the dark navbar the bell used to
             live in, and source order lets it do that without !important.

             -9: the bar gained its left-hand page identity and its three new
             controls, and --utility-bar-height moved 56px -> 64px. That token
             also drives <main>'s top padding, so a browser holding the -8 copy
             would offset the content for a 56px bar and clip the new title
             row against it. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/utility-bar.css?v=20260920-9')); ?>">
    <?php /* The shared component layer for the system UI redesign. Linked
             here, once, so a single <link> covers every page using this
             shell instead of the redesign being pasted into the 40+
             per-page stylesheets (brief section 15: "prefer fixing the
             shared design system... avoid duplicated CSS").

             Last in <head> on purpose, so it refines the sheets above by
             source order. It still cannot out-order the handful of pages
             that link their own stylesheet from the <body>, which is why
             its dashboard rules carry a leading :root for specificity —
             see the file's own header comment. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/redesign-2026.css?v=20260920-2')); ?>">
    <?php /* Phones: wide data tables become one card per row (see mobile-table-cards.js). */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/mobile-table-cards.css?v=20260926-5')); ?>">
    <?php /* iPhone/iPad Safari: no zoom on form fields, visible-height sidebar and pop-ups. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/ios-safari-fixes.css?v=20260926-1')); ?>">
    <script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/chart-lite.js?v=20260504-5')); ?>"></script>
    <?php /* TASK 7 — the icon registry is serialised here so the JS renderer
             draws from the very same geometry as ui_icon(). Emitted before
             ui-icons.js, and both sit in <head>, so any page script that
             paints markup can rely on window.UIIcons being ready. */ ?>
    <script>window.UI_ICON_PATHS = <?php echo ui_icon_paths_json(); ?>;</script>
    <script src="<?php echo htmlspecialchars(public_url('/frontend/assets/js/ui-icons.js?v=20260913-1')); ?>"></script>
</head>
<body data-user-role="<?php echo htmlspecialchars($user['role'] ?? ''); ?>" data-force-profile-setup="<?php echo $isForceProfileUpdate ? '1' : '0'; ?>">
    <?php if ($user): ?>
    <?php /* THE TOP HEADER BAR IS GONE.
             What used to sit here was <nav class="navbar navbar-dark"> holding the
             PHILCST logo and wordmark, the notification bell + dropdown, and the
             user profile chip (avatar / greeting / date-time / chevron). The whole
             node was removed so the sidebar and main content own the full viewport
             height with no bar above them.

             Deliberately NOT removed, because none of it lived in the bar:
               * Notification backend, routes, APIs and the full notifications
                 centre page (frontend/pages/notifications-center.php) are intact.
               * Account and Log Out were already in the sidebar footer, so no
                 replacement UI was invented for them.

             SINCE THEN, one — and only one — of the bar's controls came back:
             the notification bell. Losing it removed a working feature rather
             than chrome, because it was the only entry point to the dropdown.
             It is re-mounted by includes/utility-bar.php (included below) in a
             thin right-aligned strip, NOT by rebuilding any of this markup. The
             logo, system title, school name, theme toggle, date-time and the
             user profile chip are still gone and stay gone. That partial is a
             separate file precisely so this one keeps containing no navbar, no
             brand and no profile chip. $initialNotificationCount, computed
             above, is its only input — it seeds the unread badge so the bell
             does not flash empty before the first poll lands.

             The one thing that HAD to move is #sidebarToggleMobile. Below 768px
             sidebar.css parks the rail at translateX(-100%) and hides the desktop
             .sidebar-toggle, so this button is the only way to open navigation on
             a phone or tablet. It now lives in sidebar.php, immediately before
             <aside class="sidebar">, keeping the same id/class so sidebar.js binds
             it with no JS change and keeping .sidebar + .sidebar-overlay adjacent. */ ?>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <?php /* After the sidebar, so .sidebar + .sidebar-overlay stay adjacent and
             so `#sidebar.collapsed ~ .utility-bar` can track the rail's width
             with no JS. Before <main>, which every page opens next. */ ?>
    <?php include __DIR__ . '/utility-bar.php'; ?>
    <?php endif; ?>
    
    <!-- Logout is handled via server-side logout.php to ensure session is destroyed reliably -->
