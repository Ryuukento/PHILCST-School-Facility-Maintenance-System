<?php
/**
 * ============================================================================
 * TOP APP BAR — the one shared header for every page in this shell
 * ----------------------------------------------------------------------------
 * HISTORY (read this before changing anything here)
 * The original <nav class="navbar navbar-dark"> was removed: it carried a large
 * PHILCST logo, the system name, the school name, a theme toggle and a user
 * profile chip, and it ate ~76px of every viewport. None of that came back and
 * none of it may be added here — the logo lives in the sidebar, Account and
 * Log Out live in the sidebar footer.
 *
 * What this file became is a COMPACT bar, not that header: a two-line page
 * identity on the left (breadcrumb + title), and a right-hand control cluster
 * (semester, notifications). It is still deliberately kept out of header.php,
 * because header.php is the file the "did the top bar come back?" regression
 * test reads, and keeping this markup out of it keeps that guarantee literal
 * and checkable.
 *
 * The global header search field and the "New report" / "Report a Problem"
 * shortcut button were removed from this bar per product direction — they
 * duplicated search/report-creation controls that already exist on the pages
 * that need them. Nothing about report creation or page-level search was
 * removed; only this bar's duplicate entry points were.
 *
 * NOTHING BELOW IMPLEMENTS A FEATURE. Every control is an entry point into
 * something that already exists:
 *
 *   Breadcrumb / title  derived from $pageTitle, which every page already sets
 *                       before including header.php. No new page metadata.
 *   Semester            a READ of the stored school_settings row. The authority
 *                       on which semester is active is still
 *                       App\Models\SchoolSetting::syncAutomatic(); nothing here
 *                       derives, guesses or writes semester state.
 *   Notifications       the bell block is byte-for-byte the one that was here
 *                       before. Its entire contract with assets/js/notification.js
 *                       (NotificationManager, loaded by footer.php) is the set of
 *                       ids and classes below:
 *
 *                           #notificationBell        toggles the dropdown
 *                           #notificationCount       unread badge; JS sets
 *                                                    textContent + display
 *                           #notificationDropdown    gets the .show class
 *                           #notificationList        innerHTML target
 *                           #clearNotifications      "Mark all read"
 *                           .notification-tab[data-filter]  All / Unread filter
 *
 *                       Those are the same ids the old header used, so there is
 *                       still exactly one implementation of notification
 *                       fetching, unread counting, deep linking and read-marking
 *                       in the project.
 *
 * Role scoping is untouched. The notification rows still come from
 * GET /api/notifications, which returns only the signed-in user's own rows.
 * ========================================================================= */

// Rendered only for a signed-in user. header.php already includes this inside
// its `if ($user)` branch; the guard is here too so the partial is safe to
// include from anywhere.
if (empty($user)) {
    return;
}

require_once __DIR__ . '/icons.php';

/* ---------------------------------------------------------------------------
 * PAGE IDENTITY
 * $pageTitle is the document title every page already assigns ("All Reports -
 * SFMS", "Inventory - SFMS", "Maintenance Dashboard - School Facility
 * Maintenance System"). Stripping the product suffix off it gives the bar its
 * heading without adding a second per-page variable that pages would have to
 * remember to set — and without editing a single page file.
 * ------------------------------------------------------------------------ */
$utilityBarTitle = trim((string) preg_replace(
    '/\s*[-\x{2013}]\s*(SFMS|School Facility Maintenance System)\s*$/iu',
    '',
    (string) ($pageTitle ?? '')
));
if ($utilityBarTitle === '') {
    $utilityBarTitle = 'Dashboard';
}

/* "Home" points at the role's own landing dashboard. $brandLink is the value
   header.php already computed for exactly that purpose (dashboard.php /
   maintenance-dashboard.php / staff-dashboard.php), so the crumb can never
   disagree with the sidebar about where a given role's home is. */
$utilityBarHomeUrl   = $brandLink ?? public_url('/frontend/pages/dashboard.php');

/* ---------------------------------------------------------------------------
 * SEMESTER
 * A READ-ONLY render of the single school_settings row. `current_semester` is
 * the column SchoolSetting::syncAutomatic() maintains; this file displays it
 * and nothing else. It does NOT re-derive the active semester from the four
 * date columns, because that logic already exists once, in the model, and a
 * second copy here could disagree with the Dashboard's Academic Session card.
 *
 * `current_semester` is legitimately NULL between/outside the configured
 * ranges ("no active semester" — upcoming, on break, or school year over), and
 * that case is rendered as an explicit amber "No active semester" rather than
 * being defaulted to a semester the system is not actually in.
 * ------------------------------------------------------------------------ */
$utilityBarSemester       = null;   // '1st Semester' | '2nd Semester' | null
$utilityBarSchoolYear     = null;   // '2026-2027'
$utilityBarSemesterActive = false;

try {
    require_once __DIR__ . '/../../backend/config/database.php';
    $utilityBarPdo = getDBConnection();
    $utilityBarRow = $utilityBarPdo
        ->query('SELECT school_year, current_semester FROM school_settings LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);

    if ($utilityBarRow) {
        $utilityBarSchoolYear = trim((string) ($utilityBarRow['school_year'] ?? ''));
        $utilityBarRawSem     = trim((string) ($utilityBarRow['current_semester'] ?? ''));

        // Presentation only — "First Semester" is the stored value, "1st
        // Semester" is the compact label the bar has room for. An unexpected
        // value is shown verbatim rather than being coerced into one of the
        // two known ones.
        if ($utilityBarRawSem !== '') {
            $utilityBarSemesterActive = true;
            if (stripos($utilityBarRawSem, 'first') !== false) {
                $utilityBarSemester = '1st Semester';
            } elseif (stripos($utilityBarRawSem, 'second') !== false) {
                $utilityBarSemester = '2nd Semester';
            } else {
                $utilityBarSemester = $utilityBarRawSem;
            }
        }
    }
} catch (Throwable $e) {
    // The bar must never be the reason a page 500s. A missing/unreachable
    // settings row simply renders the inactive state.
    $utilityBarSemester       = null;
    $utilityBarSemesterActive = false;
}

/* "2026-2027" -> "2026–2027" (en dash), the same presentational tweak
   dashboard.php's formatSchoolYear() already makes. The stored value is never
   rewritten. */
if ($utilityBarSchoolYear !== null && preg_match('/^\d{4}-\d{4}$/', $utilityBarSchoolYear)) {
    $utilityBarSchoolYearText = str_replace('-', "\u{2013}", $utilityBarSchoolYear);
} else {
    $utilityBarSchoolYearText = $utilityBarSchoolYear ?: '';
}

/* Seeds the badge server-side so a user with unread notifications does not see
 * an empty bell for the first ~200ms while the first poll is in flight. The
 * value is the one header.php already computes for this purpose — no second
 * query, no second source of truth. The dropdown's LIST is deliberately NOT
 * server-rendered: NotificationManager.init() calls loadNotifications()
 * immediately and overwrites #notificationList wholesale, so server rows would
 * be thrown away milliseconds later, and hand-written rows would have to
 * duplicate renderNotifications()' markup and click signature to stay correct.
 * One renderer, in JS, is the point. */
$utilityBarUnreadCount = isset($initialNotificationCount) ? (int) $initialNotificationCount : 0;
$utilityBarCenterUrl   = public_url('/frontend/pages/notifications-center.php');
?>
<div class="utility-bar">

    <?php /* ---- LEFT: page identity -------------------------------------
             Two compact lines. The breadcrumb is deliberately the smaller,
             dimmer one so the title below it reads as the heading. */ ?>
    <div class="ub-lead">
        <nav class="ub-breadcrumb" aria-label="Breadcrumb">
            <a class="ub-crumb ub-crumb-link" href="<?php echo htmlspecialchars($utilityBarHomeUrl); ?>">Home</a>
            <span class="ub-crumb-sep" aria-hidden="true">/</span>
            <span class="ub-crumb ub-crumb-current" aria-current="page"><?php echo htmlspecialchars($utilityBarTitle); ?></span>
        </nav>
        <?php /* <h1> because this is the page's heading and most pages open
                 with an <h2> card title; using h1 here keeps the document
                 outline sane without touching a single page file. */ ?>
        <h1 class="ub-title"><?php echo htmlspecialchars($utilityBarTitle); ?></h1>
    </div>

    <?php /* ---- RIGHT: controls ----------------------------------------- */ ?>
    <div class="ub-actions">

        <?php /* ---- Semester ---------------------------------------------
                 Read-only status, not a control: the schedule is configured
                 on semester-settings.php and derived by the model. The dot is
                 paired with a text label so the state never depends on colour
                 alone. */ ?>
        <div class="ub-semester<?php echo $utilityBarSemesterActive ? '' : ' ub-semester-idle'; ?>"
             title="<?php echo htmlspecialchars(($utilityBarSemester ?: 'No active semester') . ($utilityBarSchoolYearText !== '' ? ' · ' . $utilityBarSchoolYearText : '')); ?>">
            <span class="ub-semester-dot" aria-hidden="true"></span>
            <span class="ub-semester-text">
                <span class="ub-semester-name"><?php echo htmlspecialchars($utilityBarSemester ?: 'No active semester'); ?></span>
                <?php if ($utilityBarSchoolYearText !== ''): ?>
                <span class="ub-semester-year"><?php echo htmlspecialchars($utilityBarSchoolYearText); ?></span>
                <?php endif; ?>
            </span>
        </div>

        <?php /* ---- Notifications --------------------------------------------
                 Unchanged. Same ids, same classes, same server-seeded badge. */ ?>
        <div class="notification-wrapper">
            <button id="notificationBell" class="notification-bell" type="button" aria-label="Notifications" title="Notifications">
                <?php /* ui_icon(), never an emoji — the bell glyph is explicitly
                         retired project-wide and the registry entry is the same
                         geometry ui-icons.js draws from. */ ?>
                <?php echo ui_icon('bell', ['size' => 20, 'class' => 'notification-bell-icon']); ?>
                <span id="notificationCount"
                      class="notification-count"
                      style="display: <?php echo $utilityBarUnreadCount > 0 ? 'flex' : 'none'; ?>;"><?php
                    echo $utilityBarUnreadCount > 9 ? '9+' : $utilityBarUnreadCount;
                ?></span>
            </button>

            <div id="notificationDropdown" class="notification-dropdown">
                <div class="notification-header">
                    <strong>Notifications</strong>
                    <a href="<?php echo htmlspecialchars($utilityBarCenterUrl); ?>" class="notification-see-all">See all</a>
                </div>
                <div class="notification-tabs">
                    <button class="notification-tab active" type="button" data-filter="all">All</button>
                    <button class="notification-tab" type="button" data-filter="unread">Unread</button>
                    <button id="clearNotifications" class="clear-notifications" type="button">Mark all read</button>
                </div>
                <div id="notificationList" class="notification-list">
                    <div class="notification-empty">Loading notifications&hellip;</div>
                </div>
                <div class="notification-footer">
                    <a href="<?php echo htmlspecialchars($utilityBarCenterUrl); ?>" class="notification-footer-btn">See previous notifications</a>
                </div>
            </div>
        </div>
    </div>
</div>
