<?php
/**
 * PHILCST Sidebar Navigation
 * Reusable sidebar component for all pages of the School Facility Maintenance System
 */

// Get current page to set active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<?php /* Relocated verbatim out of the removed top header bar — same id, same
         class, same markup, so sidebar.js binds it without a single JS change.
         It has to exist somewhere: below 768px sidebar.css hides the desktop
         .sidebar-toggle and parks the rail off-canvas, making this the only way
         to open navigation on phone/tablet. It sits BEFORE <aside> so that the
         .sidebar + .sidebar-overlay adjacency selector still matches, and it is
         display:none above 768px, so desktop sees nothing new. */ ?>
<button id="sidebarToggleMobile" class="sidebar-toggle-mobile" type="button" aria-label="Toggle navigation menu" title="Toggle navigation menu">
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <path d="M4 6H20M4 12H20M4 18H20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </svg>
</button>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-shell">
        <?php /* PHILCST crest. What stood here was a placeholder "FacilityFlow /
                 Operations" lockup with a generated purple F tile — product
                 naming that was never this system's branding, introduced during
                 the sidebar redesign.

                 The crest is REFERENCED, not copied: /frontend/assets/images/
                 logo.png is the same file index.php, login.blade.php and the
                 buildings-overview print header already point at, and the same
                 one the removed .navbar-brand used. There is still exactly one
                 logo in the project.

                 Deliberately a <div>, not an <a> — the old header brand was not
                 clickable either, and making it a link would add a navigation
                 target this task has no mandate to introduce. The alt text
                 carries the full system name, so removing the header's two
                 title lines did not cost screen-reader users the branding. */ ?>
        <div class="sidebar-brand">
            <img src="<?php echo htmlspecialchars(public_url('/frontend/assets/images/logo-seal.svg')); ?>"
                 alt="PHILCST Centralized School Facility Maintenance Reporting System"
                 class="sidebar-brand-logo" />
        </div>

    <!-- Sidebar Toggle Button (Mobile) -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true">
        <span class="hamburger-icon">
            <span></span>
            <span></span>
            <span></span>
        </span>
    </button>

    <!-- Main Navigation Menu -->
    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <!-- Dashboard -->
            <?php
                $dashLink = 'dashboard.php';
                if (!empty($user) && ($user['role'] === 'maintenance_admin')) {
                    $dashLink = 'maintenance-dashboard.php';
                } elseif (!empty($user) && ($user['role'] === 'maintenance_staff')) {
                    $dashLink = 'staff-dashboard.php';
                }

                $reportsLink = 'reports.php';
                if (!empty($user) && (($user['role'] ?? '') === 'maintenance_staff')) {
                    $reportsLink = 'reports.php';
                }

                $inventoryLink = 'inventory.php';

                $showInventoryNav = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true);
                $showBuildingsOverview = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true);
                $showUserManagement = !empty($user) && (($user['role'] ?? '') === 'super_admin');
                // 2026-09-30 — Activity Logs is now Administrator-only. Head
                // Maintenance (maintenance_admin) previously had access too;
                // removed at the user's request so only super_admin can see
                // the link, open the pages directly, or call the API (see
                // matching guards in activity-log.php, activity-log-detail.php,
                // and the 'activity-logs' route group in routes/web.php).
                $showAuditLogs = !empty($user) && (($user['role'] ?? '') === 'super_admin');
                // TASK 100 — report creation is reached from inside All Reports
                // now, so the sidebar no longer computes a Create Report flag.
                // The role gate itself did not move or change: reports.php's
                // $canCreateReport applies the identical
                // maintenance_admin/maintenance_staff rule to the "+ Create
                // Report" action it renders, and the authoritative boundaries
                // (EnsureRole on POST /api/reports, plus create-report.php's own
                // redirect guard) are untouched.
                $showNotificationsCenter = !empty($user);
            ?>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/' . $dashLink)); ?>" class="nav-link <?php echo ($current_page === basename($dashLink)) ? 'active' : ''; ?>" data-page="dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                        <path d="M9 11H15V15H9Z"></path>
                        <path d="M3 9H9V15H3Z"></path>
                        <path d="M15 3V9"></path>
                        <path d="M3 15V21"></path>
                    </svg>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>



            <!-- All Reports -->
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/' . $reportsLink)); ?>" class="nav-link <?php echo in_array($current_page, ['reports.php', 'maintenance-report-detail.php'], true) ? 'active' : ''; ?>" data-page="reports">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6C4.9 2 4 2.9 4 4V20C4 21.1 4.9 22 6 22H18C19.1 22 20 21.1 20 20V8L14 2Z"></path>
                        <path d="M14 2V8H20"></path>
                        <line x1="8" y1="11" x2="16" y2="11"></line>
                        <line x1="8" y1="16" x2="16" y2="16"></line>
                    </svg>
                    <span class="nav-text">All Reports</span>
                </a>
            </li>

            <?php /* TASK 100 — Create Report is no longer a standalone sidebar
                     module. Report creation is now reached from inside All
                     Reports, whose header carries the "+ Create Report" action
                     for the same two submitter roles this entry was gated to.

                     Nothing behind it was removed: create-report.php, the
                     /reports/create route and POST /api/reports all still work,
                     and the dashboards' own quick-action links to the page are
                     untouched. */ ?>

            <?php if ($showBuildingsOverview): ?>
            <!-- TASK 101 — Buildings Overview is its own module here instead of
                 a Dashboard shortcut. The gate mirrors the read side of the
                 existing TASK 35 policy (GET /api/buildings|floors|rooms are
                 open to all three roles; every mutating route stays
                 EnsureRole:super_admin). This only decides whether the link is
                 drawn — buildings-overview.php's own canModifyBuildings check
                 and the route middleware remain the authoritative boundaries. -->
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/buildings-overview')); ?>"
                   class="nav-link <?php echo ($current_page === 'buildings-overview.php') ? 'active' : ''; ?>"
                   data-page="buildings-overview">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 21h18"></path>
                        <path d="M5 21V7l7-4 7 4v14"></path>
                        <path d="M9 9h6"></path>
                        <path d="M9 13h6"></path>
                    </svg>
                    <span class="nav-text">Buildings Overview</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($showInventoryNav): ?>
            <?php
                /* TASK 6 — Inventory navigation consolidation.
                 *
                 * Inventory Reports, Purchase Receipts and Deployment Tracking
                 * stop being top-level destinations and become children of
                 * Inventory. This is NAVIGATION ONLY: every href, route,
                 * controller, API and page behind these entries is untouched,
                 * and the modules remain separate features.
                 *
                 * TASK 6A — Dispatches joined the same submenu, and the order
                 * became Inventory, Purchase Receipts, Dispatches, Deployment
                 * Tracking, Inventory Reports. Dispatches moved with its own
                 * href, icon, role gate and three-page active-state list
                 * intact; the dispatch route, controller, service,
                 * authorization service, API and approval/release/deduction
                 * workflow were not touched by that move.
                 *
                 * RBAC is carried over verbatim, not re-derived:
                 *   - Inventory Reports   was in_array(role, [super_admin,
                 *                         maintenance_admin, maintenance_staff])
                 *   - Purchase Receipts   same three roles
                 *   - Dispatches          same three roles
                 *   - Deployment Tracking super_admin + maintenance_admin only
                 * Those exact tests are reproduced below. The children now also
                 * sit under $showInventoryNav, which is the identical
                 * three-role test, so no role gains or loses a destination:
                 * Deployment Tracking in particular stays hidden from
                 * maintenance_staff. The page-level guards and the route
                 * middleware remain the authoritative boundaries — this markup
                 * only decides what is drawn.
                 */
                $showInventoryReportsNav   = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true);
                $showPurchaseReceiptsNav   = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true);
                $showDispatchesNav         = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true);
                $showDeploymentTrackingNav = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin'], true);

                // TASK 6A — Dispatches is the one child with a multi-page
                // active-state list. Its original list is reused verbatim so
                // the section also opens on the dispatch detail and create
                // pages, exactly as the top-level entry used to highlight.
                $dispatchesPages = ['dispatches.php', 'dispatch-detail.php', 'dispatch-create.php'];

                // The parent renders open whenever the user is on one of the
                // child destinations it can actually see. Built from the same
                // flags above so a hidden child can never open the section.
                $inventorySectionPages = [basename($inventoryLink)];
                if ($showPurchaseReceiptsNav) {
                    $inventorySectionPages[] = 'purchase-receipts.php';
                }
                if ($showDispatchesNav) {
                    $inventorySectionPages = array_merge($inventorySectionPages, $dispatchesPages);
                }
                if ($showDeploymentTrackingNav) {
                    $inventorySectionPages[] = 'deployment-tracking.php';
                }
                if ($showInventoryReportsNav) {
                    $inventorySectionPages[] = 'inventory-reports.php';
                }

                $inventorySectionOpen = in_array($current_page, $inventorySectionPages, true);

                // TASK — duplicate-Inventory fix. The section's own landing page
                // is no longer repeated as a child, so the parent row has to be
                // the destination for it. This flag drives the .active state
                // that used to sit on the removed child.
                $inventoryIsCurrent = ($current_page === basename($inventoryLink));
            ?>
            <!-- Inventory (parent) -->
            <li class="nav-item nav-parent<?php echo $inventorySectionOpen ? ' nav-parent-open' : ''; ?>" data-nav-section="inventory">
                <?php /* TASK — duplicate "Inventory" removed from the submenu.
                         The row is now TWO controls side by side, not one:

                           [ Inventory .................. ] [ v ]
                             ^ <a>, navigates                ^ <button>, expands

                         Why the split. Previously the whole row was a <button>
                         expander and the section repeated "Inventory" as its own
                         first child — that duplicate is what this task removes.
                         With the child gone the landing page needs a home, and
                         folding it into the expander would mean one control with
                         two meanings: the user could no longer expand without
                         navigating, or navigate without expanding. Two elements
                         keep the two intents separate and each one keyboard-
                         reachable on its own.

                         The <a> carries .nav-link, so every existing hover,
                         focus, .active and collapsed-rail rule applies to it for
                         free, and sidebar.js's navLinks/arrow-key handling picks
                         it up with no JS change.

                         The caret keeps .nav-parent-toggle — the exact hook
                         sidebar.js already binds handleNavParentToggle to — so
                         the expand/collapse behaviour is the existing one and
                         NOT a new implementation. It deliberately does NOT carry
                         .nav-link: it must not be a rival for the .active class
                         that addActiveHighlight() strips and re-applies. */ ?>
                <div class="nav-parent-row">
                    <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/' . $inventoryLink)); ?>"
                       class="nav-link nav-parent-link<?php echo $inventoryIsCurrent ? ' active' : ($inventorySectionOpen ? ' nav-parent-current' : ''); ?>"
                       data-page="inventory">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 8V19A2 2 0 0 1 19 21H5A2 2 0 0 1 3 19V8"></path>
                            <rect x="2" y="3" width="20" height="5" rx="1"></rect>
                            <line x1="10" y1="12" x2="14" y2="12"></line>
                        </svg>
                        <span class="nav-text">Inventory</span>
                    </a>
                    <?php /* aria-label because the control is icon-only; without
                             it the expander would be an unnamed button. */ ?>
                    <button type="button"
                            class="nav-parent-toggle"
                            data-nav-parent="inventory"
                            aria-expanded="<?php echo $inventorySectionOpen ? 'true' : 'false'; ?>"
                            aria-controls="nav-submenu-inventory"
                            aria-label="Toggle Inventory submenu">
                        <svg class="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                </div>

                <ul class="nav-submenu" id="nav-submenu-inventory">
                    <?php if ($showPurchaseReceiptsNav): ?>
                    <li class="nav-item nav-subitem">
                        <a href="<?php echo htmlspecialchars(public_url('/purchase-receipts')); ?>"
                           class="nav-link nav-sublink <?php echo ($current_page === 'purchase-receipts.php') ? 'active' : ''; ?>"
                           data-page="purchase-receipts">
                            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 5H7C5.9 5 5 5.9 5 7V19C5 20.1 5.9 21 7 21H17C18.1 21 19 20.1 19 19V7C19 5.9 18.1 5 17 5H15"></path>
                                <rect x="9" y="3" width="6" height="4" rx="1"></rect>
                                <line x1="9" y1="12" x2="15" y2="12"></line>
                                <line x1="9" y1="16" x2="13" y2="16"></line>
                            </svg>
                            <span class="nav-text">Purchase Receipts</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($showDispatchesNav): ?>
                    <?php /* TASK 6A — the existing Dispatches entry, relocated
                             from the top level. Same href, same icon, same role
                             gate, same three-page active-state list. Nothing
                             behind it moved: DispatchController,
                             DispatchService, DispatchAuthorizationService, the
                             /dispatches routes, the dispatch APIs and the
                             create → approve → release → inventory-deduction
                             workflow are all untouched by this navigation
                             change. */ ?>
                    <li class="nav-item nav-subitem">
                        <a href="<?php echo htmlspecialchars(public_url('/dispatches')); ?>"
                           class="nav-link nav-sublink <?php echo in_array($current_page, $dispatchesPages, true) ? 'active' : ''; ?>"
                           data-page="dispatches">
                            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12H19"></path>
                                <path d="M12 5L19 12L12 19"></path>
                            </svg>
                            <span class="nav-text">Dispatches</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($showDeploymentTrackingNav): ?>
                    <?php /* TASK 10 SAFETY — this is the existing Deployment
                             Tracking destination (/deployment-tracking). TASK
                             36's Deploy-to-Room retirement is untouched: no
                             deploy route, no room-deployment UI and no retired
                             InventoryStockController deploy method is
                             reintroduced here. */ ?>
                    <li class="nav-item nav-subitem">
                        <a href="<?php echo htmlspecialchars(public_url('/deployment-tracking')); ?>"
                           class="nav-link nav-sublink <?php echo ($current_page === 'deployment-tracking.php') ? 'active' : ''; ?>"
                           data-page="deployment-tracking">
                            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M12 2v3M12 19v3M2 12h3M19 12h3"></path>
                                <path d="M5.64 5.64l2.12 2.12M16.24 16.24l2.12 2.12M5.64 18.36l2.12-2.12M16.24 7.76l2.12-2.12"></path>
                            </svg>
                            <span class="nav-text">Deployment Tracking</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($showInventoryReportsNav): ?>
                    <li class="nav-item nav-subitem">
                        <a href="<?php echo htmlspecialchars(public_url('/inventory-reports')); ?>"
                           class="nav-link nav-sublink <?php echo ($current_page === 'inventory-reports.php') ? 'active' : ''; ?>"
                           data-page="inventory-reports">
                            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                <line x1="8" y1="8" x2="16" y2="8"></line>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                                <line x1="8" y1="16" x2="12" y2="16"></line>
                            </svg>
                            <span class="nav-text">Inventory Reports</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </li>

            <?php /* TASK 99 — Damage Report is a report CLASSIFICATION, not a
                     module, so it has no standalone sidebar destination. TASK
                     45's nav entry, and its active-state list for the damage
                     report detail/update pages, were removed here.

                     Nothing behind it was deleted: the pages, the API and the
                     data model are untouched, the detail page is still reached
                     from notification deep links and from Create Report's
                     duplicate warning, and the cases themselves are now
                     surfaced through All Reports / Export Reports via the
                     Report Type filter. */ ?>

            <?php /* TASK 12 — the Repair Requests nav entry was removed here,
                     along with its five-page active-state list
                     (repair-requests / repair-detail / repair-assignment /
                     repair-update / replacement-request).

                     Unlike the TASK 99 note above, this is a RETIREMENT, not a
                     relocation: the Repair Request module is being withdrawn
                     from the application and those five pages were deleted in
                     this task, so there is no other destination to point at
                     and no replacement workflow is introduced.

                     The primary maintenance workflow is unchanged and remains
                     Create Report -> All Reports -> assign personnel ->
                     monitor/update -> resolve/close.

                     TASK 13 superseded the note that used to sit here saying
                     the backend was "deliberately still alive". It is not:
                     Task 13 deleted RepairController, RepairService and the
                     RepairRequest / RepairHistory models, and removed the
                     /api/repairs endpoints. The repair_* DATABASE TABLES do
                     still exist — Task 13 was application-level retirement
                     only and changed no schema — but nothing in the app
                     reads them any more. This sidebar has no Repair entry to
                     remove; that happened in Task 12. */ ?>

            <?php /* TASK 6 — the standalone top-level Inventory Reports,
                     Purchase Receipts and Deployment Tracking entries were
                     removed from here, and TASK 6A removed the standalone
                     Dispatches entry as well. None of them are gone: all four
                     are now rendered as children of the Inventory parent
                     above, with their original hrefs, icons, labels,
                     active-state rules and role gates carried over
                     unchanged. */ ?>
            <?php endif; ?>

            <?php if (in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true)): ?>
            <li class="nav-section-label"><span>Maintenance</span></li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/preventive-maintenance')); ?>"
                   class="nav-link <?php echo ($current_page === 'preventive-maintenance.php') ? 'active' : ''; ?>"
                   data-page="preventive-maintenance">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                        <path d="M9 16l2 2 4-4"></path>
                    </svg>
                    <span class="nav-text">Preventive Maintenance</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true)): ?>
            <li class="nav-section-label"><span>Analytics</span></li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/analytics')); ?>"
                   class="nav-link <?php echo ($current_page === 'analytics-dashboard.php') ? 'active' : ''; ?>"
                   data-page="analytics">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    <span class="nav-text">Analytics</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($showUserManagement): ?>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/users.php')); ?>" class="nav-link <?php echo ($current_page === 'users.php') ? 'active' : ''; ?>" data-page="users">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21V19C16 16.79 14.21 15 12 15H6C3.79 15 2 16.79 2 19V21"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21V19C22 17.14 20.73 15.56 19 15.13"></path>
                        <path d="M16 3.13C17.73 3.56 19 5.14 19 7C19 8.86 17.73 10.44 16 10.87"></path>
                    </svg>
                    <span class="nav-text">User Management</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($showAuditLogs): ?>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/activity-log.php')); ?>" class="nav-link <?php echo in_array($current_page, ['activity-log.php', 'activity-log-detail.php'], true) ? 'active' : ''; ?>" data-page="activity-logs">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20"></path>
                        <path d="M6.5 2H20V22H6.5A2.5 2.5 0 0 1 4 19.5V4.5A2.5 2.5 0 0 1 6.5 2Z"></path>
                        <path d="M8 7H16"></path>
                        <path d="M8 11H16"></path>
                        <path d="M8 15H13"></path>
                    </svg>
                    <span class="nav-text">Activity Logs</span>
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-section-label"><span>Account</span></li>
            <li class="nav-item">
                <a href="<?php echo htmlspecialchars(public_url('/frontend/pages/account.php')); ?>" class="nav-link <?php echo ($current_page === 'account.php') ? 'active' : ''; ?>" data-page="account">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21V19C20 16.79 18.21 15 16 15H8C5.79 15 4 16.79 4 19V21"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span class="nav-text">Account</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link logout-link" data-logout>
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5A2 2 0 0 1 3 19V5A2 2 0 0 1 5 3H9"></path>
                        <path d="M16 17L21 12L16 7"></path>
                        <path d="M21 12H9"></path>
                    </svg>
                    <span class="nav-text">Log Out</span>
                </a>
            </li>
            
        </ul>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <?php if (!empty($user)): ?>
        <div class="user-profile sidebar-user-profile" title="<?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?>">
            <div class="user-avatar-wrap sidebar-user-avatar-wrap">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="avatar" class="avatar-img" id="header-user-avatar" />
                <?php else: ?>
                    <div class="avatar-circle" data-user-avatar id="header-user-avatar-fallback"><?php echo strtoupper($user['full_name'][0] ?? 'U'); ?></div>
                <?php endif; ?>
            </div>
            <div class="user-profile-meta sidebar-user-meta">
                <div class="user-name" id="header-user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></div>
                <div class="user-title" id="header-user-title"><?php echo htmlspecialchars($userTitle ?? 'User'); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/sidebar.inline.css')); ?>">
        <?php /* TASK 6 — submenu styling. Loaded last so it refines sidebar.css
                 (including that file's mobile rule which hides .nav-caret)
                 without needing !important, and without editing any shared
                 stylesheet. */ ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/sidebar-submenu.css?v=20260920-6')); ?>">
    </div>
</aside>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>



