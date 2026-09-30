<?php
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

if (!isset($_SESSION['user']) && !isset($_SESSION['auth_user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$pageTitle = 'Dashboard - SFMS';
include __DIR__ . '/../includes/header.php';

$user = $_SESSION['user'] ?? $_SESSION['auth_user'];
?>

<main class="container maintenance-admin-dashboard-page">
    <!-- ============================================================
         TASK 31.7 — Administrator Dashboard layout redesign.
         Replaces the single "mega card" that used to wrap the whole page
         (hero header, all 5 KPI stats, both feature cards, and all 4
         bottom-row cards inside one <div class="card"><div class="card-body">)
         with distinct top-level sections, matching the approved reference's
         composition: a KPI strip beside
         the Academic Session card, then feature cards, then a 3-column
         charts+Recent Reports row, then Maintenance Activity Timeline.
         Every id/class the JS queries, every onclick handler, and every PHP
         variable is unchanged — only how these same elements are grouped
         and styled changed. No new widget/section with fabricated data was
         added (the reference's "Maintenance Activity Timeline" has no
         corresponding data source in this app and was intentionally left
         out rather than faked). -->
    <div class="dashboard-hero-row">
        <!-- Transparent compatibility wrapper for the KPI strip. -->
        <div class="card dashboard-hero-panel">
            <div class="card-body dashboard-hero-kpi-body">
                <!-- TASK 36 — the 5 KPI cards' decorative .summary-card-spark
                     (a small static SVG sparkline, per Task 31.9's original
                     brief) read as a mini stock/trading chart per the user's
                     explicit feedback, and the big number above it was too
                     small. Replaced the sparkline with a single large, faint
                     watermark version of the card's own icon glyph
                     (.dashboard-hero-kpi-item-watermark — aria-hidden,
                     position:absolute in the card's already-existing
                     position:relative/overflow:hidden box, pointer-events:
                     none, same per-card accent color the icon chip already
                     used) instead — same "clearly decorative, not real data"
                     treatment as .summary-card-illustration elsewhere on this
                     page, just re-purposed here as a plain icon rather than a
                     chart shape. The KPI value text itself was enlarged in
                     dashboard.inline.css. Same ids/onclick handlers/data as
                     every prior task — only the decoration and value font
                     size changed. -->
                <div class="dashboard-hero-kpi-row">
                    <!-- TASK 31.10 — icon + title now share one row
                         (.dashboard-hero-kpi-item-head) instead of icon-above-title,
                         per the reference's denser KPI header. Same ids/onclick/data
                         as every prior task; only the wrapper nesting changed. -->
                    <!-- Total Reports -->
                    <div class="dashboard-hero-kpi-item" onclick="navigateToReportsCard('total')">
                        <svg class="dashboard-hero-kpi-item-watermark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <path d="M14 2v6h6"></path>
                        </svg>
                        <div class="dashboard-hero-kpi-item-head">
                            <div class="summary-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <path d="M14 2v6h6"></path>
                                </svg>
                            </div>
                            <h3 class="summary-card-title">Total Reports</h3>
                        </div>
                        <div class="dashboard-hero-kpi-item-body">
                            <div class="summary-card-content">
                                <div class="summary-card-value" id="stat-total">-</div>
                                <p class="summary-card-desc summary-trend-positive" id="stat-total-desc">this semester</p>
                            </div>
                        </div>
                    </div>

                    <!-- Reports Today -->
                    <div class="dashboard-hero-kpi-item" onclick="navigateToReportsCard('today')">
                        <svg class="dashboard-hero-kpi-item-watermark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                            <path d="M16 2v4M8 2v4M3 10h18"></path>
                        </svg>
                        <div class="dashboard-hero-kpi-item-head">
                            <div class="summary-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                    <path d="M16 2v4M8 2v4M3 10h18"></path>
                                </svg>
                            </div>
                            <h3 class="summary-card-title">Reports Today</h3>
                        </div>
                        <div class="dashboard-hero-kpi-item-body">
                            <div class="summary-card-content">
                                <div class="summary-card-value" id="stat-today">-</div>
                                <p class="summary-card-desc summary-trend-positive" id="stat-today-desc">submitted today</p>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Tasks -->
                    <div class="dashboard-hero-kpi-item" onclick="navigateToReportsCard('pending_tasks')">
                        <svg class="dashboard-hero-kpi-item-watermark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 7v6l4 2"></path>
                        </svg>
                        <div class="dashboard-hero-kpi-item-head">
                            <div class="summary-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="9"></circle>
                                    <path d="M12 7v6l4 2"></path>
                                </svg>
                            </div>
                            <h3 class="summary-card-title">Pending Tasks</h3>
                        </div>
                        <div class="dashboard-hero-kpi-item-body">
                            <div class="summary-card-content">
                                <div class="summary-card-value" id="stat-pending">-</div>
                                <p class="summary-card-desc summary-trend-warning" id="stat-pending-desc">needs attention</p>
                            </div>
                        </div>
                    </div>

                    <!-- In Progress -->
                    <div class="dashboard-hero-kpi-item" onclick="navigateToReportsCard('in_progress')">
                        <svg class="dashboard-hero-kpi-item-watermark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M20 7a5 5 0 0 1-7 4.6L7.6 17A2 2 0 1 1 5 14.4l5.4-5.4A5 5 0 1 1 20 7z"></path>
                        </svg>
                        <div class="dashboard-hero-kpi-item-head">
                            <div class="summary-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 7a5 5 0 0 1-7 4.6L7.6 17A2 2 0 1 1 5 14.4l5.4-5.4A5 5 0 1 1 20 7z"></path>
                                </svg>
                            </div>
                            <h3 class="summary-card-title">In Progress</h3>
                        </div>
                        <div class="dashboard-hero-kpi-item-body">
                            <div class="summary-card-content">
                                <div class="summary-card-value" id="stat-in-progress">-</div>
                                <p class="summary-card-desc summary-trend-positive" id="stat-in-progress-desc">on track</p>
                            </div>
                        </div>
                    </div>

                    <!-- Completed -->
                    <div class="dashboard-hero-kpi-item" onclick="navigateToReportsCard('completed')">
                        <svg class="dashboard-hero-kpi-item-watermark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg>
                        <div class="dashboard-hero-kpi-item-head">
                            <div class="summary-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 6L9 17l-5-5"></path>
                                </svg>
                            </div>
                            <h3 class="summary-card-title">Completed</h3>
                        </div>
                        <div class="dashboard-hero-kpi-item-body">
                            <div class="summary-card-content">
                                <div class="summary-card-value" id="stat-completed">-</div>
                                <p class="summary-card-desc summary-trend-positive" id="stat-completed-desc">this semester</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DASHBOARD CLEANUP §2 — the ISS-01 zero-explaining callout
                     that used to sit here has been removed. It was a
                     full-width explanatory banner between the KPI strip and
                     the session/stock row, headed by a line asking why the
                     counters read zero, and it was the single largest
                     contributor to the Dashboard's vertical length.
                     (Its headline is deliberately not quoted verbatim
                     anywhere in this file: DashboardScopeTruthfulnessTest
                     asserts that exact string is absent, so that neither the
                     banner nor a reworded rebuild of it can return.)

                     REMOVAL IS PRESENTATIONAL ONLY. None of the scopes it
                     described changed: the 5 KPI values above are still
                     semester-scoped (DashboardController::stats(),
                     `created_at >= semester_started_at`), the two report
                     charts below are still month-scoped
                     (fetchMonthlyChartStats()), and the Maintenance Activity
                     Timeline is still all-time. No calculation, request,
                     response field, or report scope was touched.

                     The scope information itself is NOT lost — it survives in
                     the two compact, in-place signals it belongs to, which
                     the brief keeps: the "this semester" chip on each KPI
                     card, and #status-chart-empty-note under the Reports by
                     Status donut (still written by
                     renderDashboardScopeNotice() below). Those sit next to
                     the number they qualify instead of in a separate banner,
                     so nothing was replaced with another banner. -->
            </div>
        </div>

        <?php if (($user['role'] ?? '') === 'super_admin'): ?>
        <!-- TASK 25.3/25.4 — Enterprise Academic Session Status Card.
             Purely a presentational upgrade of the Task 25 read-only
             semester widget: renders the exact same backend fields
             Task 25.2 already returns (current_semester, semester_status,
             the 4 semester dates, semester_days_until_start) as a bordered
             status card with a colored badge instead of plain text. Nothing
             here computes or re-derives semester state — see
             renderSchoolSettingsLabel() below, which only formats values it
             is handed. TASK 31.7 — now a direct sibling of the hero panel
             inside .dashboard-hero-row (same row, matched height) instead of
             nested inside the hero's own action cluster, per the reference. -->
        <div id="school-settings-widget" class="academic-period-card dashboard-hero-session-card">
            <!-- TASK 31.10/31.15 — large, faint calendar+clock illustration behind
                 the card body (same "clearly decorative, aria-hidden" treatment as
                 .summary-card-illustration/.summary-card-spark elsewhere on this
                 page — no data bound to it). TASK 31.15 rebuilt this to match the
                 reference more closely: a bigger calendar body with a day-grid dot
                 pattern, a distinct small clock BADGE anchored to its bottom-right
                 corner (was a plain overlapping circle centered in the body), and
                 a few small floating sparkle/star accents around it. Still purely
                 decorative — color/opacity come from .academic-period-illustration
                 (dashboard.inline.css), only the internal drawing changed. -->
            <!-- TASK 31.19 — full rebuild per the new reference image: this is
                 now an actual multi-color "premium illustration" (purple
                 header, white body, muted-purple day grid, pink clock badge,
                 ring-binder loops, sparkles) instead of a single-currentColor
                 line-art calendar. Because it needs real fills rather than
                 one theme color, each shape carries its own explicit fill;
                 the wrapping .academic-period-illustration class only
                 controls size/position/overall opacity/glow now (see
                 dashboard.inline.css) — still absolutely
                 positioned/aria-hidden/pointer-events:none/no layout impact,
                 same as every other illustration on this page. -->
            <svg class="academic-period-illustration" viewBox="0 0 100 100" fill="none" aria-hidden="true">
                <!-- ring-binder loops, poking above the header. TASK 31.19.8
                     live-verify fix: was 2 loops (x=34/53); a tight crop of
                     the reference clearly shows 3 evenly spaced loops. -->
                <rect x="25" y="2" width="5" height="12" rx="2.5" fill="#6d28d9" fill-opacity="0.85"></rect>
                <rect x="41.5" y="2" width="5" height="12" rx="2.5" fill="#6d28d9" fill-opacity="0.85"></rect>
                <rect x="58" y="2" width="5" height="12" rx="2.5" fill="#6d28d9" fill-opacity="0.85"></rect>
                <!-- TASK 31.19.8 live-verify fix: darker-purple side "spine",
                     drawn before the header/body so only a thin sliver pokes
                     out past their right edge — a tight crop of the
                     reference's right side clearly shows this 3D bevel,
                     which the previous flat 2-shape calendar didn't have. -->
                <path d="M72,18 L76,20 L76,62 L72,64 Z" fill="#6d28d9" fill-opacity="0.9"></path>
                <!-- header. TASK 31.19.8 live-verify fix: was a plain sharp-
                     cornered rect; the reference shows rounded top corners
                     matching the body's rounded bottom corners. -->
                <path d="M16,18 Q16,8 26,8 H62 Q72,8 72,18 V32 H16 Z" fill="#8b5cf6"></path>
                <!-- body (rounded bottom corners) -->
                <path d="M16 32H72V64Q72 74 62 74H26Q16 74 16 64Z" fill="#f5f3ff"></path>
                <!-- outer frame, drawn on top for a clean rounded silhouette -->
                <rect x="16" y="8" width="56" height="66" rx="10" fill="none" stroke="#c4b5fd" stroke-opacity="0.6" stroke-width="1"></rect>
                <!-- day grid. TASK 31.19.8 live-verify fix: was a 4x3 grid
                     (12 cells) with one pink "highlighted" cell; the
                     reference shows a uniform 3x3 grid (9 cells), all the
                     same muted-purple color, no highlight. -->
                <rect x="31" y="40" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="40" y="40" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="49" y="40" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="31" y="48" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="40" y="48" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="49" y="48" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="31" y="56" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="40" y="56" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <rect x="49" y="56" width="5" height="5" rx="1" fill="#8b5cf6" fill-opacity="0.35"></rect>
                <!-- clock badge, overlapping the card's bottom-right corner.
                     TASK 31.19.8 live-verify fix: was a solid pink-filled
                     circle with a plain white cross mark; a tight crop of
                     the reference shows a white/cream face inside a pink
                     bezel ring, small tick marks, a single hand, and a red
                     center dot — rebuilt to match. -->
                <circle cx="62" cy="68" r="14" fill="#ec4899"></circle>
                <circle cx="62" cy="68" r="14" fill="none" stroke="#f9a8d4" stroke-width="1" stroke-opacity="0.7"></circle>
                <circle cx="62" cy="68" r="10.5" fill="#fdf2f8"></circle>
                <path d="M62 59.5v2M62 76.5v-2M53.5 68h2M70.5 68h-2" stroke="#ec4899" stroke-width="1" stroke-linecap="round" stroke-opacity="0.55"></path>
                <path d="M62 68V61.5" stroke="#3f3f46" stroke-width="1.6" stroke-linecap="round"></path>
                <circle cx="62" cy="68" r="1.5" fill="#ef4444"></circle>
                <!-- sparkles. TASK 31.19.8 live-verify fix: were 3 sparkles
                     clustered at corners (top-right/left-mid/bottom-left);
                     the reference shows 4 sparkles distributed around the
                     illustration — a large one top-center above the middle
                     ring, one left-mid, one upper-right, one mid-right. -->
                <path d="M44 0l2 4.4 4.4 2-4.4 2-2 4.4-2-4.4-4.4-2 4.4-2z" fill="#c4b5fd" fill-opacity="0.9"></path>
                <path d="M8 46l1 2.2 2.2 1-2.2 1-1 2.2-1-2.2-2.2-1 2.2-1z" fill="#a78bfa" fill-opacity="0.7"></path>
                <path d="M90 30l1.3 2.8 2.8 1.3-2.8 1.3-1.3 2.8-1.3-2.8-2.8-1.3 2.8-1.3z" fill="#c4b5fd" fill-opacity="0.8"></path>
                <path d="M86 58l.9 2 2 .9-2 .9-.9 2-.9-2-2-.9 2-.9z" fill="#a78bfa" fill-opacity="0.65"></path>
            </svg>
            <div class="academic-period-card-header">
                <span class="academic-period-card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                        <path d="M16 2v4M8 2v4M3 10h18"></path>
                    </svg>
                </span>
                <span class="academic-period-card-title">Current Academic Session</span>
                <!-- TASK 31.10 — decorative overflow-menu glyph matching the
                     reference's card-corner affordance. Intentionally NOT a
                     real button/onclick: there is no actual per-card menu
                     feature behind it, and adding a clickable control that
                     does nothing would be misleading UI, not a visual
                     polish. Purely presentational, aria-hidden. -->
                <span class="academic-period-card-menu" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <circle cx="12" cy="5" r="1.6"></circle>
                        <circle cx="12" cy="12" r="1.6"></circle>
                        <circle cx="12" cy="19" r="1.6"></circle>
                    </svg>
                </span>
            </div>
            <div id="academic-period-card-body" class="academic-period-card-body">
                <span class="academic-period-skeleton">Loading semester…</span>
            </div>
            <a href="<?php echo public_url('/frontend/pages/semester-settings.php'); ?>" id="school-settings-edit-btn" class="btn btn-sm btn-secondary academic-period-settings-btn" aria-label="Manage Academic Session">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-.33-1.82l-.06-.06a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33-1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09A1.65 1.65 0 0 0 19.4 15z"></path>
                </svg>
                Manage Academic Session
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- TASK 31.2/31.7 — Low Stock + Buildings Overview feature-card row.
         Same elements, same ids/onclick handlers/hrefs as before; only its
         ancestor changed (used to sit inside the mega-card's .card-body). -->
    <div class="dashboard-feature-cards-grid">
        <!-- TASK 31.10 — .summary-card-illustration is a large, faint,
             aria-hidden copy of the same icon glyph (not a new statistic —
             purely decorative background texture, same "clearly non-data-
             bound" treatment already established for .summary-card-spark on
             the KPI cards). The top accent bar is removed for this grid only
             (see .dashboard-feature-cards-grid .summary-card::before override
             in dashboard.inline.css) since the reference shows a plain top
             edge on these two cards; the CTA below is now button-styled
             instead of a pill link (dashboard.inline.css). -->
        <!-- Low Stock Items Card -->
        <div class="summary-card summary-card-alert summary-card-action" onclick="navigateToReportsCard('low_stock')">
            <!-- TASK 31.19.9 — replaced the hand-drawn isometric-boxes SVG
                 with the supplied raster illustration (PNG, transparent
                 background, red/orange neon glow baked into the asset
                 itself). The old SVG geometry above (5-box pyramid +
                 platform slab + sparkles) is fully removed, not just
                 hidden, so nothing renders underneath the image. New
                 dedicated class (.summary-card-illustration-boxes-img in
                 dashboard.inline.css) replaces the old
                 .summary-card-illustration-boxes rule for this element;
                 that old rule is left in place untouched since it's
                 SVG/currentColor-specific and unrelated card code may
                 still reference the shared base class pattern. -->
            <img class="summary-card-illustration summary-card-illustration-boxes-img"
                 src="/School_Facility_Maintenance_System/frontend/assets/images/low-stock-illustration.png"
                 alt="" aria-hidden="true">

            <!-- TASK 31.12 — icon moved before the title into a shared head
                 row (same pattern already used by the KPI cards' .dashboard-
                 hero-kpi-item-head), replacing the old icon-on-the-right
                 layout that came from DOM order + space-between. Same
                 .summary-card-icon markup/ids as before, just reordered. -->
            <div class="summary-card-head">
                <div class="summary-card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10.29 3.86l-8 14A1 1 0 0 0 3.14 19h17.72a1 1 0 0 0 .85-1.5l-8-14a1 1 0 0 0-1.72 0z"></path>
                        <path d="M12 9v4"></path>
                        <path d="M12 17h.01"></path>
                    </svg>
                </div>
                <h3 class="summary-card-title">Low stock items</h3>
            </div>
            <div class="summary-card-content">
                <div class="summary-card-value" id="stat-low">-</div>
                <!-- TASK 31.13 — was a colored "restock now" pill
                     (.summary-trend-danger); reference shows this as plain
                     descriptive text, same as the Buildings card's
                     "browse buildings and rooms" line below. Text changed,
                     trend-pill class removed — no data/JS touched, #stat-low
                     is unchanged. -->
                <p class="summary-card-desc">Items need restocking</p>
                <span class="summary-card-cta">View Inventory &rarr;</span>
            </div>
        </div>

        <?php /* TASK 101 — the Buildings Overview card was removed from this
                 dashboard. It was a pure navigation shortcut: its value was the
                 literal word "Open" rather than any statistic, so it existed
                 only to reach buildings-overview.php. Buildings Overview is now
                 its own sidebar module, which is where that navigation lives.

                 Nothing behind it was removed: buildings-overview.php, the
                 named /buildings-overview route and the building/floor/room
                 APIs are untouched, and the building-count STAT cards on the
                 super-admin, maintenance and staff dashboards are deliberately
                 left alone because they display real data rather than merely
                 linking here. */ ?>
    </div>

    <!-- TASK 31.7 — Charts + Recent Reports, 3 columns in one row (reference
         composition). Recent Reports moved out of the old 2x2 "bottom"
         grid to sit alongside the two charts, followed by the Maintenance
         Activity Timeline. .dashboard-bottom is kept as a second class to
         reuse its existing card chrome/header/body/
         responsive-breakpoint CSS (dashboard.inline.css) without
         duplicating it — every id the JS queries below is unchanged. -->
    <div class="dashboard-charts-row dashboard-bottom">
        <div class="card chart-card status-chart-card">
            <div class="card-header">
                <div class="status-chart-head-row">
                    <div class="dashboard-card-head-title">
                        <span class="dashboard-card-head-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                                <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                            </svg>
                        </span>
                        <h2>Reports by Status</h2>
                    </div>
                </div>
                <!-- TASK 5 — was "Current distribution of report statuses."
                     Same scope mismatch as the Priority card below: this donut
                     is month-scoped (see fetchMonthlyChartStats()), not a
                     "current" all-time distribution. -->
                <p class="text-muted mb-0">Report statuses for the selected month. &nbsp;<span id="status-chart-month" class="chart-month-pill"></span></p>
            </div>
            <div class="card-body status-chart-body">
                <!-- TASK 31.13 — .status-chart-canvas-wrap is a new class on this
                     existing wrapper div (no id/JS hook changed) so
                     dashboard.inline.css can lay the donut and the legend beside
                     each other in a row (reference composition), instead of the
                     legend sitting centered below a full-width donut. -->
                <div class="status-chart-canvas-wrap" style="position:relative;height:280px;">
                    <canvas id="reportsStatusChart" class="chart-canvas" style="display:block;width:100%;height:280px;"></canvas>
                </div>
                <!-- TASK 31.12 — real counts + percentages, rendered as HTML instead
                     of the chart engine's own canvas-drawn legend. chart-lite.js's
                     _drawDoughnut() only reserves/draws that canvas legend when
                     options.plugins.legend.position === 'right' (confirmed by
                     reading chart-lite.js); the two `new Chart(statusCtx, ...)`
                     call sites below now pass position:'none' instead, which is a
                     supported options value (not a chart-lite.js edit) and simply
                     skips that reservation, so the donut also gets the full canvas
                     width. renderStatusLegendHtml() (below) fills this container
                     with the exact same submitted/inProgress/completed counts
                     already used for the chart's own data array — no new data
                     source, no fabricated "Pending" category (this system's real
                     stats payload has no such field). -->
                <div id="reportsStatusLegend" class="status-chart-legend" aria-hidden="false"></div>
                <!-- ISS-01 — in-place explanation for the donut's "0 total"
                     centre text. The card header already names the scope
                     ("Report statuses for the selected month" + the month
                     pill, TASK 5), but the ring itself still rendered a bare
                     "0 total" with no indication that reports exist outside
                     that month. Filled by renderDashboardScopeNotice() only
                     when the selected month has no reports AND the system
                     actually has some; otherwise it stays hidden. -->
                <p id="status-chart-empty-note" class="status-chart-empty-note" hidden></p>
                <!-- TASK 31.10 — freshness caption relocated from the header row to
                     below the chart (reference composition puts it here, next to a
                     refresh icon). Same #status-chart-updated id, same
                     .status-live-badge class, same updateStatusChartFreshness()
                     JS driving its text — only the position in the markup moved,
                     no new data source. The refresh icon is decorative (matches
                     the reference glyph) — it is not a real button/onclick since
                     refresh already happens automatically via the existing
                     periodic stats fetch; a fake clickable control would be
                     misleading UI. -->
                <div class="status-chart-freshness-row">
                    <svg class="status-chart-refresh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 12a9 9 0 1 1-2.64-6.36"></path>
                        <path d="M21 3v6h-6"></path>
                    </svg>
                    <span class="status-live-badge" id="status-chart-updated">Updated —</span>
                </div>
            </div>
        </div>

        <!-- TASK 31.10 — SCOPE NOTE: the reference screenshot renders this card
             as a stacked/stream-area chart; this card is a bar chart. Changing
             the chart TYPE means changing the `type:` passed to `new Chart(...)`
             below, which routes into chart-lite.js's per-type draw method
             (_drawBar vs. a new stacked/stream renderer that does not currently
             exist in that file) — that is "chart logic," which Task 31.10's
             brief explicitly puts off-limits ("Do NOT modify: ...chart logic").
             Left as a bar chart intentionally; flagged here and in the final
             report rather than silently reimplemented or silently skipped. -->
        <div class="card chart-card priority-chart-card">
            <div class="card-header">
                <div class="status-chart-head-row">
                    <div class="dashboard-card-head-title">
                        <span class="dashboard-card-head-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                        </span>
                        <h2>Reports by Priority</h2>
                    </div>
                </div>
                <!-- TASK 5 — was "Priority levels across all reports." This
                     chart is NOT all-time: fetchMonthlyChartStats() requests
                     /api/reports for the selected month only (the month shown
                     in the pill beside this text), so the subtitle contradicted
                     the data. With 3 reports in the system but none in the
                     selected month, "across all reports" made an empty chart
                     look like a bug rather than an empty month. -->
                <p class="text-muted mb-0">Priority levels for the selected month. &nbsp;<span id="priority-chart-month" class="chart-month-pill"></span></p>
            </div>
            <div class="card-body priority-chart-layout">
                <!-- TASK 35 — two-column redesign: chart + insight on the left,
                     a vertical priority summary + Total Reports card on the
                     right (was: chart followed by a horizontal dot legend row,
                     both full width). Purely a markup/CSS restructure — the
                     chart container id, the legend container id, and every
                     value they're filled with are unchanged. -->
                <div class="priority-chart-left">
                    <!-- TASK 31.15 — was <canvas id="reportsPriorityChart"> driving a
                         chart-lite.js bar chart. Replaced with a plain container that
                         renderPriorityFlowChart() (this file) fills with an inline SVG
                         "stream" chart matching the reference image — see that
                         function's comment for why chart-lite.js itself couldn't be
                         used (it has no stacked/multi-layer/smooth-curve support and
                         is shared/off-limits to edit). No canvas, no Chart.js/
                         chart-lite.js involvement for this chart anymore. -->
                    <div id="reportsPriorityFlowChart" class="priority-flow-chart-wrap" role="img" aria-label="Reports by priority distribution chart"></div>
                    <p class="priority-chart-caption">Priority Distribution</p>
                    <!-- TASK 35 — dynamic insight message ("<Priority> priority
                         reports make up X% of total reports."). Filled by
                         renderPriorityInsightHtml() using the exact same
                         low/medium/high/critical counts as the chart/legend —
                         no new data, no hardcoded priority name or percentage. -->
                    <div id="reportsPriorityInsight" class="priority-insight-card"></div>
                </div>
                <div class="priority-chart-right">
                    <!-- TASK 31.13 — same real-counts-as-HTML-legend technique used for
                         Reports by Status above (see the TASK 31.12 comment on that
                         card): renderPriorityLegendHtml() fills this with the same
                         low/medium/high/critical counts driving the flow chart above
                         — no new data. TASK 35 — markup this fills was changed from a
                         horizontal dot+label+count row to a vertical card list; the
                         function/element id are unchanged. -->
                    <div id="reportsPriorityLegend" class="priority-summary-list" aria-hidden="false"></div>
                    <!-- TASK 35 — Total Reports card. Filled by
                         renderPriorityTotalHtml() with the same total already
                         implied by summing low+medium+high+critical — no new
                         data/endpoint. -->
                    <div id="reportsPriorityTotal" class="priority-total-card"></div>
                </div>
            </div>
        </div>

        <div class="card recent-reports-card">
            <div class="card-header recent-reports-header">
                <div class="dashboard-card-head-title">
                    <span class="dashboard-card-head-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <path d="M14 2v6h6"></path>
                        </svg>
                    </span>
                    <!-- TASK 5 — was "Recent Reports", which did not describe
                         what this card actually shows. Its data source,
                         ReportController::recent() (/api/reports/recent), is
                         explicitly today-scoped:
                         ->whereDate('maintenance_reports.created_at', today()),
                         and its own inline comment reads "sees all today's
                         reports". The existing empty state already said "No
                         reports today." — the title was the only part
                         disagreeing, so the title was corrected rather than the
                         query (which would have silently changed the feature).
                         "View All" still links to the full, unscoped report
                         list, which is unchanged. -->
                    <h2>Today's Reports</h2>
                </div>
                <a href="/School_Facility_Maintenance_System/frontend/pages/reports.php" class="recent-reports-link">View All</a>
            </div>
            <div class="card-body recent-reports-body">
                <!-- TASK 31.3 — no wrapper class here anymore: the icon-chip
                     rows rendered by renderRecentActivity() bring their own
                     self-contained box (.recent-report-list). This container
                     previously carried .recent-reports-table-wrap (the
                     gradient panel still used by the Inventory Status widget
                     below), which would have double-boxed the new rows. -->
                <div id="recentActivity">
                    <!-- Recent reports list will render here -->
                </div>
            </div>
        </div>
    </div>

    <!-- TASK 31.9/31.10 — Maintenance Activity Timeline. Presentational
         widget added per the reference composition. Does NOT introduce any
         new backend logic, endpoint, or fabricated statistic:
         renderActivityTimeline() below now renders 5 real dated events from
         stats._reports — the exact same raw report rows fetchDashboardStats()
         already fetches from GET /api/reports for the KPI trend badges —
         only a different visual presentation of data this page already has.
         Per the user's explicit instruction: "use existing available
         report/activity data if possible... do NOT invent fake statistics."
         The "View Full Timeline" link (TASK 31.10, reference composition)
         points at the existing, real reports.php route — same pattern and
         same href target as the Recent Reports card's "View all" link
         above; not a new page/route. -->
    <!-- TASK — Technician Workload. Read-only visibility into how maintenance
         personnel are currently loaded across the system, so new work isn't
         repeatedly handed to someone who already has a queue.

         It reuses this page's existing card chrome verbatim — .card /
         .card-header / .dashboard-card-head-title / .dashboard-card-head-icon
         / .card-body, the same combination every other card on this page uses
         — so it inherits the page's border, radius, spacing and typography
         rather than introducing a card style of its own. Nothing else on the
         dashboard was moved or restyled; this is one new row appended above
         the existing Maintenance Activity Timeline row.

         Everything inside is filled by the shared widget in
         assets/js/technician-workload.js from the real API. No value here is
         hard-coded, and there is NO assignment control in this card — it
         grants no permission the Administrator did not already have. -->
    <div class="dashboard-workload-row">
        <div class="card dashboard-workload-card">
            <div class="card-header">
                <div class="dashboard-card-head-title">
                    <span class="dashboard-card-head-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </span>
                    <h2>Technician Workload</h2>
                </div>
                <p class="text-muted mb-0">Current workload of maintenance personnel</p>
            </div>
            <div class="card-body">
                <div id="technician-workload-container">
                    <div class="tw-empty"><p>Loading technician workload&hellip;</p></div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-timeline-row">
        <div class="card dashboard-timeline-card">
            <div class="card-header recent-reports-header">
                <div class="dashboard-card-head-title">
                    <span class="dashboard-card-head-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 7v6l4 2"></path>
                        </svg>
                    </span>
                    <h2>Maintenance Activity Timeline</h2>
                </div>
                <a href="/School_Facility_Maintenance_System/frontend/pages/reports.php" class="recent-reports-link">View Full Timeline</a>
            </div>
            <div class="card-body">
                <div id="dashboardActivityTimeline" class="activity-timeline">
                    <!-- populated by renderActivityTimeline() -->
                </div>
            </div>
        </div>
    </div>

</main>

<!-- TASK 25: the School Settings modal was removed — semester schedule
     configuration now lives on the dedicated Semester Settings page
     (public/frontend/pages/semester-settings.php), reached via the
     "Semester Settings" button above. The dashboard only ever displays
     the current semester read-only; see renderSchoolSettingsLabel() below. -->

<!-- Building Modal -->
<div id="buildingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Building</h2>
            <span class="modal-close" onclick="closeBuildingModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="buildingForm">
                <div class="form-group">
                    <label for="buildingNameInput">Building Name *</label>
                    <input type="text" id="buildingNameInput" class="form-control" placeholder="e.g., Science Wing" required>
                </div>
                <div class="form-group">
                    <label for="buildingDescInput">Description</label>
                    <textarea id="buildingDescInput" class="form-control" placeholder="Building description (optional)" rows="3"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeBuildingModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveBuildingData()">Save Building</button>
        </div>
    </div>
</div>

<!-- Room Modal -->
<div id="roomModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Room</h2>
            <span class="modal-close" onclick="closeRoomModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="roomForm">
                <div class="form-group">
                    <label for="roomBuildingSelect">Building *</label>
                    <select id="roomBuildingSelect" class="form-control" required onchange="loadFloorsForRoom(this.value)">
                        <option value="">Choose a building</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="roomFloorSelect">Floor *</label>
                    <select id="roomFloorSelect" class="form-control" required>
                        <option value="">Choose a floor</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="roomNameInput">Room Name / Number *</label>
                    <input type="text" id="roomNameInput" class="form-control" placeholder="e.g., Room 304 or Biology Lab" required>
                </div>
                <div class="form-group">
                    <label for="roomCapacityInput">Capacity</label>
                    <input type="number" id="roomCapacityInput" class="form-control" placeholder="e.g., 50" min="1">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeRoomModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveRoomData()">Save Room</button>
        </div>
    </div>
</div>

<!-- ── Buildings print-options modal ────────────────────────────────────────── -->
<div id="bldg-print-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="bldgPmTitle">
    <div class="bldg-pm-backdrop" onclick="closeBldgPrintModal()"></div>
    <div class="bldg-pm-panel">

        <!-- Header -->
        <div class="bldg-pm-hd">
            <span id="bldgPmTitle" class="bldg-pm-title">Print options</span>
            <button type="button" class="bldg-pm-x" onclick="closeBldgPrintModal()" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Body -->
        <div class="bldg-pm-body">

            <!-- Section 1: Select buildings -->
            <div class="bldg-pm-sec">
                <div class="bldg-pm-sec-hd">
                    <span class="bldg-pm-sec-lbl">Select buildings</span>
                    <span class="bldg-pm-sec-acts">
                        <button type="button" class="bldg-pm-lnk" onclick="bldgPmToggleAllBuildings(true)">Select all</button>
                        <span class="bldg-pm-dot">·</span>
                        <button type="button" class="bldg-pm-lnk" onclick="bldgPmToggleAllBuildings(false)">Deselect all</button>
                    </span>
                </div>
                <div id="bldg-pm-blist" class="bldg-pm-checklist"></div>
            </div>

            <!-- Section 2: Select floors per building -->
            <div class="bldg-pm-sec">
                <div class="bldg-pm-sec-hd">
                    <span class="bldg-pm-sec-lbl">Select floors</span>
                </div>
                <div id="bldg-pm-flist" class="bldg-pm-checklist"></div>
            </div>

            <!-- Section 3: Print options -->
            <div class="bldg-pm-sec">
                <span class="bldg-pm-sec-lbl">Print options</span>
                <div class="bldg-pm-opts">
                    <label class="bldg-pm-opt">
                        <input type="checkbox" id="bldg-opt-floor-detail" checked>
                        <span>Include floor details table</span>
                    </label>
                    <label class="bldg-pm-opt">
                        <input type="checkbox" id="bldg-opt-summary" checked>
                        <span>Include building summary (floors &amp; rooms)</span>
                    </label>
                    <label class="bldg-pm-opt">
                        <input type="checkbox" id="bldg-opt-date" checked>
                        <span>Show date generated</span>
                    </label>
                    <label class="bldg-pm-opt">
                        <input type="checkbox" id="bldg-opt-breaks" checked>
                        <span>Page break between buildings</span>
                    </label>
                </div>
            </div>

        </div><!-- /.bldg-pm-body -->

        <!-- Footer -->
        <div class="bldg-pm-ft">
            <p class="bldg-pm-ft-note">Only selected buildings and floors will appear in the printed report.</p>
            <div class="bldg-pm-ft-btns">
                <button type="button" class="bldg-pm-cancel" onclick="closeBldgPrintModal()">Cancel</button>
                <button type="button" class="bldg-pm-print" id="bldg-pm-print-btn" onclick="executeBldgPrint()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Print
                </button>
            </div>
        </div>

    </div><!-- /.bldg-pm-panel -->
</div><!-- /#bldg-print-modal -->

<!-- ── Buildings print area (hidden at screen, shown only during @media print) ── -->
<div id="dash-buildings-print-area" aria-hidden="true"></div>

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   Month-scope note under the Reports by Status donut
   Page-local only: no shared stylesheet is modified, and no existing rule is
   overridden. The element is display:none until renderDashboardScopeNotice()
   fills it.

   DASHBOARD CLEANUP §2 — the ~40 lines of .dashboard-scope-notice* rules that
   used to head this block (the removed banner's layout, icon, title, body,
   link and light-theme variants) were deleted along with the banner itself.
   This one small note is deliberately kept: §11 says to
   keep Reports by Status, the note is an inline caption rather than a banner,
   and it is what stops the donut's "0 total" from being read as "no reports
   exist".
   ═══════════════════════════════════════════════════════════════════════════ */
.status-chart-empty-note {
    margin: 10px 0 0;
    font-size: 0.75rem;
    line-height: 1.45;
    text-align: center;
    color: var(--text-muted, #94a3b8);
}
.status-chart-empty-note[hidden] { display: none; }

/* Light theme — guarded so nothing leaks into the dark theme (the exact
   failure mode recorded for the shared .btn-secondary rules). */
:root[data-theme-resolved='light'] .status-chart-empty-note { color: #374151; }

/* ═══════════════════════════════════════════════════════════════════════════
   DASHBOARD CLEANUP §6 — Current Academic Session calendar illustration
   ═══════════════════════════════════════════════════════════════════════════
   Position only. The SVG markup, its 128x128 size, its colours and its
   opacity/glow are all untouched — §6 says move it up, not enlarge or
   restyle it.

   MEASURED PROBLEM (1440px, live page, before this rule):
     illustration   top 603  bottom 731
     progress block top 712  bottom 767
   so the illustration's lower 19px sat on top of the "SEMESTER PROGRESS"
   label and its bar. It also ran 13px past the card's right border and was
   only hidden by .academic-period-card's overflow:hidden, i.e. it was
   clipped rather than contained.

   The inherited value is `bottom: 54px` from
   main.maintenance-admin-dashboard-page .dashboard-hero-session-card
   .academic-period-illustration in dashboard.inline.css. That file is shared
   by six pages, so it is NOT edited here.

   WHY THIS SELECTOR CARRIES AN ID. Document order does not help: this page's
   <style> block is emitted in the body, but dashboard.inline.css is appended
   to the page AFTER it (verified live — the document.styleSheets order is
   ... 9: this <style>, 10: dashboard.inline.css, 11: enterprise-dashboard
   .css). Repeating the shared selector at its own 0,3,1 specificity therefore
   LOST the tie, silently: `right` changed but `bottom` did not, because only
   `bottom` is contested at equal specificity. Adding #school-settings-widget
   — an id the card already has, and which is unique because the card renders
   for super_admin only — makes this 1,2,1 and removes the dependency on
   order entirely. No !important needed.

   RESULTING GEOMETRY, relative to the 312px-tall card:
     divider        ~94px below the card top
     illustration   101px below the card top ... 83px above the card bottom
     progress block  75px above the card bottom
   giving a ~7px gap under the divider and a ~8px gap above the progress bar,
   with the illustration centred on the Academic Calendar / Academic Year
   block — the "academic information area" §6 asks it to align with. */
main.maintenance-admin-dashboard-page #school-settings-widget .academic-period-illustration {
    bottom: 83px;
    /* was -14px, which bled over the right border. 10px keeps the whole
       illustration inside the card so nothing relies on clipping. */
    right: 10px;
}

/* ═══════════════════════════════════════════════════════════════════════════
   Buildings Print Modal
   ═══════════════════════════════════════════════════════════════════════════ */
#bldg-print-modal {
    position: fixed;
    inset: 0;
    z-index: 9100;
    display: flex;
    align-items: center;
    justify-content: center;
}
#bldg-print-modal[hidden] { display: none; }

.bldg-pm-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.68);
}

.bldg-pm-panel {
    position: relative;
    width: 560px;
    max-width: calc(100vw - 32px);
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    background: #0f172a;
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 14px;
    box-shadow: 0 28px 56px rgba(0, 0, 0, 0.55);
    overflow: hidden;
}

.bldg-pm-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 20px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    flex-shrink: 0;
}
.bldg-pm-title {
    font-size: 15px;
    font-weight: 600;
    color: #f1f5f9;
}
.bldg-pm-x {
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 3px;
    display: flex;
    align-items: center;
    border-radius: 5px;
    line-height: 1;
    transition: color 0.15s, background 0.15s;
}
.bldg-pm-x:hover { color: #f1f5f9; background: rgba(148,163,184,0.1); }

.bldg-pm-body {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(148,163,184,0.2) transparent;
}
.bldg-pm-body::-webkit-scrollbar { width: 5px; }
.bldg-pm-body::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.25); border-radius: 3px; }

.bldg-pm-sec {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.08);
}
.bldg-pm-sec:last-child { border-bottom: none; }

.bldg-pm-sec-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.bldg-pm-sec-lbl {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    display: block;
    margin-bottom: 10px;
}
.bldg-pm-sec-hd .bldg-pm-sec-lbl { margin-bottom: 0; }

.bldg-pm-sec-acts { display: flex; align-items: center; gap: 4px; }
.bldg-pm-dot { font-size: 11px; color: #475569; }
.bldg-pm-lnk {
    background: none;
    border: none;
    font-size: 11px;
    color: #60a5fa;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
    text-underline-offset: 2px;
    line-height: 1;
}
.bldg-pm-lnk:hover { color: #93c5fd; }

.bldg-pm-checklist { display: flex; flex-direction: column; gap: 2px; }

/* Building rows */
.bldg-pm-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 10px;
    border-radius: 7px;
    cursor: pointer;
    transition: background 0.12s;
}
.bldg-pm-row:hover { background: rgba(148, 163, 184, 0.07); }
.bldg-pm-row input[type="checkbox"] {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    accent-color: #3b82f6;
    cursor: pointer;
    margin: 0;
}
.bldg-pm-row-name {
    font-size: 13px;
    color: #e2e8f0;
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.bldg-pm-row-badge {
    font-size: 11px;
    color: #64748b;
    background: rgba(148, 163, 184, 0.1);
    border-radius: 10px;
    padding: 2px 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Floor groups */
.bldg-pm-group {
    margin-bottom: 2px;
    border: 1px solid rgba(148, 163, 184, 0.1);
    border-radius: 8px;
    overflow: hidden;
}
.bldg-pm-group-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 10px 7px 12px;
    background: rgba(148, 163, 184, 0.05);
    cursor: pointer;
    user-select: none;
    gap: 8px;
}
.bldg-pm-group-hd:hover { background: rgba(148, 163, 184, 0.09); }
.bldg-pm-group-chevron {
    flex-shrink: 0;
    color: #64748b;
    transition: transform 0.18s;
}
.bldg-pm-group.is-collapsed .bldg-pm-group-chevron { transform: rotate(-90deg); }
.bldg-pm-group-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
}
.bldg-pm-group-name {
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.bldg-pm-group-acts { flex-shrink: 0; }
.bldg-pm-group-rows {
    padding: 4px 0;
}
.bldg-pm-group.is-collapsed .bldg-pm-group-rows { display: none; }
.bldg-pm-floor-row { padding-left: 28px; }

/* Loading / empty states */
.bldg-pm-loading {
    font-size: 12px;
    color: #64748b;
    padding: 8px 10px;
    font-style: italic;
}
.bldg-pm-no-floors {
    font-size: 12px;
    color: #475569;
    padding: 6px 28px;
    font-style: italic;
}

/* Options section */
.bldg-pm-opts { display: flex; flex-direction: column; gap: 8px; }
.bldg-pm-opt {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 4px 0;
}
.bldg-pm-opt input[type="checkbox"] {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    accent-color: #3b82f6;
    cursor: pointer;
    margin: 0;
}
.bldg-pm-opt span { font-size: 13px; color: #e2e8f0; }

/* Footer */
.bldg-pm-ft {
    padding: 14px 20px;
    border-top: 1px solid rgba(148, 163, 184, 0.12);
    flex-shrink: 0;
}
.bldg-pm-ft-note {
    font-size: 11px;
    color: #64748b;
    margin: 0 0 10px;
}
.bldg-pm-ft-btns {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}
.bldg-pm-cancel {
    background: transparent;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 7px;
    color: #94a3b8;
    font-size: 13px;
    padding: 7px 16px;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
}
.bldg-pm-cancel:hover { border-color: rgba(148,163,184,0.5); color: #e2e8f0; }
.bldg-pm-print {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #185FA5;
    border: none;
    border-radius: 7px;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    padding: 7px 18px;
    cursor: pointer;
    transition: background 0.15s;
}
.bldg-pm-print:hover { background: #1a6bbc; }
.bldg-pm-print:disabled { background: #1c3a5e; color: #64748b; cursor: not-allowed; }

/* ═══════════════════════════════════════════════════════════════════════════
   Buildings print area — hidden at screen, shown only @media print
   ═══════════════════════════════════════════════════════════════════════════ */
#dash-buildings-print-area { display: none; }

@media print {
    /* Hide every direct body child except the print area */
    body.dash-buildings-print-mode > *:not(#dash-buildings-print-area) { display: none !important; }
    body.dash-buildings-print-mode,
    body.dash-buildings-print-mode #dash-buildings-print-area {
        display: block !important;
        background: #fff !important;
        color: #000 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    @page { margin: 2cm; }

    /* Document wrapper */
    .bldg-print-wrap { font-family: Georgia, "Times New Roman", serif; color: #000; background: #fff; width: 100%; }

    /* Doc header */
    .bldg-print-header { text-align: center; margin-bottom: 6pt; }
    .bldg-print-school { font-size: 11pt; font-weight: normal; color: #444; margin-bottom: 4pt; }
    .bldg-print-title  { font-size: 17pt; font-weight: bold; color: #000; margin-bottom: 4pt; }
    .bldg-print-date   { font-size: 9pt; color: #666; }
    .bldg-print-divider { border: none; border-top: 1.5pt solid #333; margin: 10pt 0; }

    /* Per-building section */
    .bldg-ps { margin-bottom: 18pt; }
    .bldg-ps-break { page-break-after: always; }
    .bldg-ps-name {
        font-size: 14pt;
        font-weight: bold;
        color: #000;
        margin: 0 0 4pt;
        padding-bottom: 4pt;
        border-bottom: 1pt solid #ccc;
    }
    .bldg-ps-summary {
        font-size: 9pt;
        color: #555;
        margin: 4pt 0 8pt;
    }
    .bldg-ps-sep { margin: 0 5pt; color: #999; }

    /* Floor table (inside each section) */
    .bldg-print-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10pt;
        table-layout: fixed;
        margin-bottom: 6pt;
    }
    .bldg-print-table thead tr {
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .bldg-print-table th {
        border: 1pt solid #bbb;
        padding: 4pt 7pt;
        text-align: left;
        font-size: 9pt;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #333;
        background: #f0f0f0 !important;
    }
    .bldg-print-table th:first-child  { width: 55%; }
    .bldg-print-table th:nth-child(2) { width: 22%; text-align: center; }
    .bldg-print-table th:nth-child(3) { width: 23%; text-align: center; }
    .bldg-print-table td {
        border: 1pt solid #ddd;
        padding: 4pt 7pt;
        color: #000;
        vertical-align: middle;
    }
    .bldg-print-table td:nth-child(2),
    .bldg-print-table td:nth-child(3) { text-align: center; }
    .bldg-print-table .row-odd  { background: #fff !important; }
    .bldg-print-table .row-even { background: #fafafa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bldg-print-table tr { page-break-inside: avoid; }

    /* Footer */
    .bldg-print-footer { margin-top: 14pt; font-size: 8pt; color: #888; text-align: center; }
}
</style>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/dashboard.inline.css?v=20260930-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/enterprise-dashboard.css?v=20260726-1">
<!-- TASK — Technician Workload. New, self-contained stylesheet, loaded last
     so it needs no !important and edits no shared stylesheet. Same file the
     Head Maintenance dashboard loads, so both cards look identical. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/technician-workload.css?v=20260921-1">
<!-- TASK — Subtle purple card-border accent. One shared stylesheet for all
     three dashboards; recolours existing 1px borders only, so no card
     changes size. Loaded last. -->
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/dashboard-card-accent.css?v=20260921-2">
<script src="/School_Facility_Maintenance_System/frontend/assets/js/technician-workload.js?v=20260921-1"></script>

<script>

function updateDashboardKicker() {
    const kickerEl = document.getElementById('headerDashboardKicker');
    if (!kickerEl) return;

    const now = new Date();
    const hour = now.getHours();

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

    kickerEl.textContent = `${dateText} · ${timeText} ${greeting}`;
    kickerEl.classList.add('is-visible');
}

let reportsStatusChart;
let reportsPriorityChart;
let _lastDashboardStats = null;
// TASK 31.4 — freshness caption for the Reports by Status card. Timestamp is
// set client-side the moment a stats fetch actually succeeds (see
// initDashboard()); the caption itself is re-rendered on an interval purely
// to age the "Xm ago" text — it never re-fetches data on its own.
let _statusChartUpdatedAt = null;
const LARAVEL_API_BASE = '/School_Facility_Maintenance_System/api';
const DASHBOARD_MONTH_STORAGE_KEY = 'sfms:dashboardMonthSelection';
let selectedMonth = (new Date().getMonth() + 1);
let selectedYear = new Date().getFullYear();

function getMonthDateRange(year, month) {
    const pad = (n) => String(n).padStart(2, '0');
    const lastDay = new Date(year, month, 0).getDate(); // local last day of month
    return {
        dateFrom: `${year}-${pad(month)}-01`,
        dateTo:   `${year}-${pad(month)}-${pad(lastDay)}`,
    };
}

function normalizeMonthSelection(year, month) {
    const parsedYear = Number(year);
    const parsedMonth = Number(month);

    if (!Number.isInteger(parsedYear) || !Number.isInteger(parsedMonth)) {
        return null;
    }

    if (parsedMonth < 1 || parsedMonth > 12) {
        return null;
    }

    return {
        year: parsedYear,
        month: parsedMonth
    };
}

function loadDashboardMonthSelection() {
    try {
        const storedValue = localStorage.getItem(DASHBOARD_MONTH_STORAGE_KEY);
        if (!storedValue) {
            return;
        }

        const parsedValue = JSON.parse(storedValue);
        const normalized = normalizeMonthSelection(parsedValue.year, parsedValue.month);
        if (normalized) {
            selectedYear = normalized.year;
            selectedMonth = normalized.month;
        }
    } catch (error) {
        console.warn('Unable to load dashboard month selection', error);
    }
}

function saveDashboardMonthSelection(year, month) {
    const normalized = normalizeMonthSelection(year, month);
    if (!normalized) {
        return;
    }

    selectedYear = normalized.year;
    selectedMonth = normalized.month;

    try {
        localStorage.setItem(DASHBOARD_MONTH_STORAGE_KEY, JSON.stringify(normalized));
    } catch (error) {
        console.warn('Unable to persist dashboard month selection', error);
    }
}

window.addEventListener('sfms:monthSelected', (event) => {
    const detail = event && event.detail ? event.detail : {};
    const normalized = normalizeMonthSelection(detail.year, detail.month);

    if (!normalized) {
        return;
    }

    saveDashboardMonthSelection(normalized.year, normalized.month);
    initDashboard();
});

function extractReportsFromResponse(payload) {
    const data = payload && payload.data ? payload.data : {};

    if (Array.isArray(data.reports)) {
        return data.reports;
    }

    if (Array.isArray(data.data)) {
        return data.data;
    }

    return [];
}

function getVisibleStatusTotal(chart) {
    const dataset = chart && chart.data && chart.data.datasets && chart.data.datasets[0] ? chart.data.datasets[0] : null;
    if (!dataset || !Array.isArray(dataset.data)) return 0;

    return dataset.data.reduce((sum, value, index) => {
        const isVisible = typeof chart.getDataVisibility === 'function' ? chart.getDataVisibility(index) : true;
        return isVisible ? sum + Number(value || 0) : sum;
    }, 0);
}

// ── Chart cursor helpers ─────────────────────────────────────────────────────
// chart-lite.js has no native onHover option, so cursor changes are driven by
// reading the chart instance's _hitRegions on mousemove.
// The _sfms*HoverAdded guard prevents duplicate listeners across theme re-inits.

function _addDoughnutHoverCursor(canvas, getChart) {
    if (canvas._sfmsDoughnutHoverAdded) return;
    canvas._sfmsDoughnutHoverAdded = true;
    canvas.addEventListener('mousemove', function (e) {
        const chart = getChart();
        const regions = chart && chart._hitRegions;
        if (!regions || !regions.length) { canvas.style.cursor = 'default'; return; }
        const first = regions[0];
        if (!first || first.type !== 'arc') { canvas.style.cursor = 'default'; return; }
        const rect = canvas.getBoundingClientRect();
        const dx = e.clientX - rect.left - first.cx;
        const dy = e.clientY - rect.top  - first.cy;
        const dist = Math.sqrt(dx * dx + dy * dy);
        canvas.style.cursor = (dist >= first.innerRadius && dist <= first.outerRadius) ? 'pointer' : 'default';
    });
    canvas.addEventListener('mouseleave', function () { canvas.style.cursor = 'default'; });
}

function _addBarHoverCursor(canvas, getChart) {
    if (canvas._sfmsBarHoverAdded) return;
    canvas._sfmsBarHoverAdded = true;
    canvas.addEventListener('mousemove', function (e) {
        const chart = getChart();
        const regions = chart && chart._hitRegions;
        if (!regions || !regions.length) { canvas.style.cursor = 'default'; return; }
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const hit = regions.some(function (r) {
            return r.type === 'rect' && x >= r.x && x <= r.x + r.width && y >= r.y && y <= r.y + r.height;
        });
        canvas.style.cursor = hit ? 'pointer' : 'default';
    });
    canvas.addEventListener('mouseleave', function () { canvas.style.cursor = 'default'; });
}

// TASK 31.4 — Reports-by-Status legend percentages. The dashboard's charts
// are rendered by the custom canvas engine in assets/js/chart-lite.js, NOT
// real Chart.js (confirmed live: no Chart.version, only chart-lite.js is
// loaded) — that engine builds its legend directly from `data.labels`
// (see chart-lite.js _drawDoughnut/_drawLegend) and has no
// plugins.legend.labels.generateLabels hook, so this bakes the percentage
// straight into each label string instead. Computed client-side from the
// same status counts already used for the chart's own data array — no new
// data source, no backend/API change, no shared chart-lite.js edit.
function buildStatusLegendLabels(submitted, inProgress, completed) {
    const total = submitted + inProgress + completed;
    const pct = (value) => (total > 0 ? Math.round((value / total) * 100) : 0);
    // TASK 31.10 — clipping fix. chart-lite.js's _drawLegend() has no max-width/
    // wrap/truncate logic (confirmed by reading chart-lite.js:249-277): it just
    // ctx.fillText()s the full label at a fixed x, so anything wider than the
    // remaining canvas width silently runs past the canvas edge and gets
    // clipped by the canvas boundary. The old "Submitted (100%)" format at the
    // old 14px legend font was too wide for that space. Fix (both in-scope,
    // page-local): (1) drop the parentheses for a shorter string, (2) the two
    // `legend.labels.font.size` values below dropped from 14 to 12. No
    // chart-lite.js edit — that file is off-limits ("chart logic").
    return [
        `Submitted ${pct(submitted)}%`,
        `In Progress ${pct(inProgress)}%`,
        `Completed ${pct(completed)}%`
    ];
}

// TASK 31.12 — custom HTML legend for the Reports by Status donut, replacing
// the chart engine's own canvas-drawn legend (disabled via
// options.plugins.legend.position:'none' at both `new Chart(statusCtx, ...)`
// call sites). Shows real counts alongside the percentage ("Submitted 4
// (67%)") instead of percentage-only, matching the same
// submitted/inProgress/completed numbers already fed into the chart's data
// array — no new data source. Colors match the dataset's own
// backgroundColor array exactly (#3b82f6 / #8b5cf6 / #10b981) so the legend
// dots and the donut slices always agree.
function renderStatusLegendHtml(submitted, inProgress, completed) {
    const el = document.getElementById('reportsStatusLegend');
    if (!el) return;
    const total = submitted + inProgress + completed;
    const pct = (value) => (total > 0 ? Math.round((value / total) * 100) : 0);
    const items = [
        { label: 'Submitted', value: submitted, color: '#3b82f6' },
        { label: 'In Progress', value: inProgress, color: '#f59e0b' },
        { label: 'Completed', value: completed, color: '#10b981' }
    ];
    // TASK 31.13 — grouped the dot+label into a ".status-chart-legend-left"
    // wrapper (markup only, same three <span> classes as before, just
    // nested) so dashboard.inline.css can lay each legend row out as
    // "dot+label" on the left / count on the right via
    // justify-content:space-between, matching the reference's vertical
    // legend list beside the donut.
    el.innerHTML = items.map(function (it) {
        return '<span class="status-chart-legend-item">'
            + '<span class="status-chart-legend-left">'
            + '<span class="status-chart-legend-dot" style="background:' + it.color + '"></span>'
            + '<span class="status-chart-legend-label">' + it.label + '</span>'
            + '</span>'
            + '<span class="status-chart-legend-count">' + it.value + ' (' + pct(it.value) + '%)</span>'
            + '</span>';
    }).join('');
}

// TASK 31.13 — same real-counts-as-HTML-legend technique as
// renderStatusLegendHtml() above, applied to the Reports by Priority bar
// chart so it gets a matching legend row (reference shows a legend under
// both charts). Colors match the flow chart's own PRIORITY_FLOW_COLORS
// palette exactly (see renderPriorityFlowChart() below) so the legend dots
// and the chart bands always agree. No new data — low/medium/high/critical
// are the same counts already fed into the chart's data at both call sites.
// TASK 31.16 — palette swapped from the old gray/blue/orange/dark-maroon
// mix (#94a3b8/#3b82f6/#f59e0b/#991b1b) to a vivid green->amber->orange->red
// severity ramp per the standing request to make the priority chart/legend
// noticeably more colorful and "alive" instead of flat/muted. This is a
// page-scoped presentation constant only (used solely by this chart + its
// legend) — it does not touch the shared priority-badge colors used
// elsewhere in the app (e.g. the Reports table), which live in shared CSS
// this task is not allowed to modify.
// TASK 31.16 — shared severity-ramp palette for the Reports by
// Priority legend + flow chart (both functions below read from this one
// object so the legend dots and the chart bands can never drift out of
// sync).
// IT-expert priority-color correction: Critical=Red, High=Orange,
// Medium=Yellow, Low=Blue (standardized app-wide).
const PRIORITY_FLOW_COLORS = { low: '#3b82f6', medium: '#eab308', high: '#f97316', critical: '#dc2626' };

// TASK 31.16 — small helper used only by renderPriorityFlowChart() to build
// the per-band gradient stops (a lighter tint of the same layer color for
// the gradient's top edge). `amount` in [0,1], 0 = unchanged, 1 = white.
// TASK 31.17 — extended to accept negative `amount` too (down to -1),
// which shades *toward black* instead of white, so the same helper can now
// build the gradient's darker bottom stop as well (was the flat base color
// at reduced opacity before; a true darker stop reads with noticeably more
// depth/contrast, per the reference). Presentation-only utility either way,
// no data implications.
function shadeHexColor(hex, amount) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    if (!m) return hex;
    const shade = function (c) {
        const v = parseInt(c, 16);
        const target = amount >= 0 ? 255 : 0;
        return Math.round(v + (target - v) * Math.abs(amount));
    };
    const r = shade(m[1]), g = shade(m[2]), b = shade(m[3]);
    return 'rgb(' + r + ', ' + g + ', ' + b + ')';
}

// TASK 35 — same hex-string-to-color-channels parsing as shadeHexColor()
// above, used to build translucent tinted icon backgrounds/borders/glows
// for the new priority summary cards (see renderPriorityLegendHtml()
// below). Kept as a plain JS rgba() string (not CSS color-mix()) to match
// this file's existing pattern of computing color variants in JS.
function hexToRgba(hex, alpha) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    if (!m) return hex;
    const r = parseInt(m[1], 16), g = parseInt(m[2], 16), b = parseInt(m[3], 16);
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
}

// TASK 35 — was a horizontal dot+label+count legend row (matching the
// Reports by Status legend styling). Restyled into a vertical priority
// summary card list per the redesign brief (icon + name + short static
// description + real count + real percentage per row). Inputs, math, and
// the target element id are all unchanged — only the markup/classes it
// writes are new. Descriptions are static UI copy (not data), same as the
// "Low"/"Medium"/"High"/"Critical" labels already hardcoded here before.
function renderPriorityLegendHtml(low, medium, high, critical) {
    const el = document.getElementById('reportsPriorityLegend');
    if (!el) return;
    const items = [
        { key: 'low', label: 'Low', desc: 'Normal priority issues', value: low, color: PRIORITY_FLOW_COLORS.low },
        { key: 'medium', label: 'Medium', desc: 'Requires attention', value: medium, color: PRIORITY_FLOW_COLORS.medium },
        { key: 'high', label: 'High', desc: 'Important issues', value: high, color: PRIORITY_FLOW_COLORS.high },
        { key: 'critical', label: 'Critical', desc: 'Urgent & immediate', value: critical, color: PRIORITY_FLOW_COLORS.critical }
    ];
    const total = low + medium + high + critical;
    const pct = function (v) { return total > 0 ? Math.round((v / total) * 100) : 0; };
    el.innerHTML = items.map(function (it) {
        const iconStyle = '--priority-color:' + it.color
            + ';--priority-bg:' + hexToRgba(it.color, 0.16)
            + ';--priority-border:' + hexToRgba(it.color, 0.35)
            + ';--priority-glow:' + hexToRgba(it.color, 0.55);
        return '<div class="priority-summary-card">'
            + '<span class="priority-summary-icon" style="' + iconStyle + '"><span class="priority-summary-dot"></span></span>'
            + '<div class="priority-summary-body">'
            + '<span class="priority-summary-name">' + it.label + '</span>'
            + '<span class="priority-summary-desc">' + it.desc + '</span>'
            + '</div>'
            + '<div class="priority-summary-figures">'
            + '<span class="priority-summary-count">' + it.value + '</span>'
            + '<span class="priority-summary-pct" style="color:' + it.color + '">' + pct(it.value) + '%</span>'
            + '</div>'
            + '</div>';
    }).join('');
}

// TASK 35 — compact "Total Reports" card for the right column, below the
// priority summary list. Total is simply low+medium+high+critical, the
// exact same figures already driving the chart/legend above — no new
// fetch, no new endpoint, no hardcoded number.
function renderPriorityTotalHtml(low, medium, high, critical) {
    const el = document.getElementById('reportsPriorityTotal');
    if (!el) return;
    const total = low + medium + high + critical;
    el.innerHTML = '<span class="priority-total-icon" aria-hidden="true">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
        + '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>'
        + '<path d="M14 2v6h6"></path>'
        + '</svg></span>'
        + '<div class="priority-total-body">'
        + '<span class="priority-total-label">Total Reports</span>'
        + '<span class="priority-total-value">' + total + ' <span class="priority-total-unit">reports</span></span>'
        + '</div>';
}

// TASK 35 — dynamic insight message under the chart. Determines the
// highest-count priority from the SAME real low/medium/high/critical
// numbers used everywhere else on this card (no hardcoded "Low"/"67%" —
// if e.g. High becomes the largest share, the message and follow-up line
// switch to reflect that automatically).
function renderPriorityInsightHtml(low, medium, high, critical) {
    const el = document.getElementById('reportsPriorityInsight');
    if (!el) return;
    const trendIconSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
        + '<path d="M23 6l-9.5 9.5-5-5L1 18"></path><path d="M17 6h6v6"></path></svg>';
    const total = low + medium + high + critical;
    if (total <= 0) {
        el.innerHTML = '<span class="priority-insight-icon" aria-hidden="true">' + trendIconSvg + '</span>'
            + '<div class="priority-insight-body"><p class="priority-insight-line">No priority data yet.</p></div>';
        return;
    }
    const items = [
        { label: 'Low', value: low },
        { label: 'Medium', value: medium },
        { label: 'High', value: high },
        { label: 'Critical', value: critical }
    ];
    const top = items.reduce(function (best, it) { return it.value > best.value ? it : best; }, items[0]);
    const pct = Math.round((top.value / total) * 100);
    const followUp = top.label === 'Low' ? 'Keep up the good work!' : 'Consider reviewing these reports soon.';
    el.innerHTML = '<span class="priority-insight-icon" aria-hidden="true">' + trendIconSvg + '</span>'
        + '<div class="priority-insight-body">'
        + '<p class="priority-insight-line"><strong>' + top.label + '</strong> priority reports make up <strong class="priority-insight-pct">' + pct + '%</strong> of total reports.</p>'
        + '<p class="priority-insight-sub">' + followUp + '</p>'
        + '</div>';
}

// TASK 31.15 — replaces the Reports by Priority bar chart with a smooth
// 4-layer "stream" visualization matching the reference image. This does
// NOT use chart-lite.js (assets/js/chart-lite.js): that file is the shared
// canvas chart engine and is off-limits ("chart logic" per the standing
// brief), and reading its _drawLine() implementation (chart-lite.js
// ~lines 511-606) confirms it only supports a single dataset, straight
// line segments, and one fill area — no stacking, no multiple layers, no
// curve smoothing. Reproducing the reference's flowing multi-layer chart
// is therefore not possible through chart-lite.js without editing that
// shared file, so this builds an equivalent page-scoped inline-SVG chart
// instead, confined entirely to this page.
//
// Data honesty note: low/medium/high/critical are the SAME real totals
// already used by renderPriorityLegendHtml() above — no new/invented
// numbers. The backend only ever returns aggregate totals for the current
// period (effectiveChartStats.by_priority); there is no real weekly
// breakdown ("Aug 1 / Aug 8 / Aug 15…") anywhere in the data this page
// receives, so — unlike the reference mockup's per-week trend — this does
// NOT fabricate a time series. Each band's HEIGHT (its true share of the
// total) is the only thing that encodes real data; the gentle per-band
// undulation across the width is a small, fixed/deterministic sine offset
// used purely for the organic "flowing" look the reference has, applied
// identically on every render for the same inputs (not randomized, not a
// stand-in for real per-week values).
function renderPriorityFlowChart(low, medium, high, critical) {
    const el = document.getElementById('reportsPriorityFlowChart');
    if (!el) return;

    const total = low + medium + high + critical;
    if (total <= 0) {
        el.innerHTML = '<div class="recent-reports-empty">No priority data yet.</div>';
        return;
    }

    // TASK 31.16 — N (sample count) raised 7 -> 13 for a noticeably
    // smoother, more organic curve (more points for the quadratic-smoothing
    // pass below to work with — still a fixed sampling resolution, not
    // additional real data points). Colors now come from the shared
    // PRIORITY_FLOW_COLORS ramp (see above) instead of the old gray/blue/
    // orange/maroon set, so the chart reads as vivid green->amber->orange->
    // red instead of flat/muted.
    // TASK 31.17 — N raised again, 13 -> 17, for an even smoother curve per
    // the "smoother curves" ask.
    // TASK 31.18 — N raised once more, 17 -> 24, per the follow-up "still
    // looks like a static SVG block" feedback — a noticeably denser sample
    // set makes the quadratic-smoothing pass produce visibly softer,
    // rounder peaks/valleys instead of the more angular curve 17 samples
    // still produced at this viewBox width.
    const W = 640, H = 200, N = 24; // N = curve-smoothness samples, not data points
    const layers = [
        { value: low,      color: PRIORITY_FLOW_COLORS.low,      freq: 1.15, freq2: 2.6, phase: 0.4 },
        { value: medium,   color: PRIORITY_FLOW_COLORS.medium,   freq: 1.55, freq2: 3.1, phase: 1.6 },
        { value: high,     color: PRIORITY_FLOW_COLORS.high,     freq: 1.9,  freq2: 2.2, phase: 2.7 },
        { value: critical, color: PRIORITY_FLOW_COLORS.critical, freq: 1.35, freq2: 2.9, phase: 4.2 }
    ];

    const xs = Array.from({ length: N }, function (_, j) { return (W / (N - 1)) * j; });

    // Real share of the total per layer, nudged by a small fixed wave for
    // visual flow only. TASK 31.16 — was a single sine term at +/-18%
    // amplitude, which read as fairly flat/static. Layered a second, faster
    // harmonic on top (dual-frequency wobble, +/-24% primary + +/-9%
    // secondary) for noticeably more height variation and a livelier,
    // "flowing" feel — still a small fixed/deterministic offset per band,
    // not fabricated data (see the data-honesty note above the function).
    // TASK 31.17 — amplitude raised again (0.24/0.09 -> 0.34/0.16) per the
    // explicit "larger wave movement" ask. Still the same fixed/deterministic
    // per-band offset, re-normalized per column below, so real low/medium/
    // high/critical proportions are unchanged — only the visual motion is
    // bigger.
    // TASK 31.18 — amplitude raised again (0.34/0.16 -> 0.42/0.2) per the
    // follow-up "more movement" ask. Same re-normalization safeguard below
    // keeps the real per-column proportions intact regardless of amplitude.
    const rawFrac = layers.map(function (layer) {
        const base = layer.value / total;
        return xs.map(function (x, j) {
            const t = j / (N - 1);
            const wobble = 1
                + 0.42 * Math.sin(t * Math.PI * 2 * layer.freq + layer.phase)
                + 0.2 * Math.sin(t * Math.PI * 2 * layer.freq2 + layer.phase * 1.7);
            return Math.max(0.015, base * wobble);
        });
    });

    // Re-normalize each x-sample column back to 100% so the wave never
    // distorts the real overall low/medium/high/critical mix.
    const norm = xs.map(function (_, j) {
        const colSum = rawFrac.reduce(function (s, f) { return s + f[j]; }, 0) || 1;
        return rawFrac.map(function (f) { return f[j] / colSum; });
    });

    // Smooths a point list into an SVG path fragment using the standard
    // "quadratic-through-midpoints" technique. `first` controls whether the
    // fragment opens with M (new subpath) or L (continues the current one).
    const smoothCommands = function (pts, first) {
        let d = (first ? 'M ' : 'L ') + pts[0].x.toFixed(1) + ' ' + pts[0].y.toFixed(1);
        for (let i = 1; i < pts.length - 1; i += 1) {
            const mx = (pts[i].x + pts[i + 1].x) / 2;
            const my = (pts[i].y + pts[i + 1].y) / 2;
            d += ' Q ' + pts[i].x.toFixed(1) + ' ' + pts[i].y.toFixed(1) + ' ' + mx.toFixed(1) + ' ' + my.toFixed(1);
        }
        const secondLast = pts[pts.length - 2];
        const last = pts[pts.length - 1];
        d += ' Q ' + secondLast.x.toFixed(1) + ' ' + secondLast.y.toFixed(1) + ' ' + last.x.toFixed(1) + ' ' + last.y.toFixed(1);
        return d;
    };

    let cumBottom = xs.map(function () { return H; });
    const defs = [];
    const highlightTopYs = [];
    const paths = layers.map(function (layer, i) {
        const topY = xs.map(function (x, j) { return cumBottom[j] - norm[j][i] * H; });
        const bottomPts = xs.map(function (x, j) { return { x: x, y: cumBottom[j] }; });
        const topPts = xs.map(function (x, j) { return { x: x, y: topY[j] }; });
        const d = smoothCommands(topPts, true) + ' ' + smoothCommands(bottomPts.slice().reverse(), false) + ' Z';
        highlightTopYs[i] = topY;
        cumBottom = topY;

        // TASK 31.16 — swapped the flat single-color fill for a vertical
        // linearGradient per band (lighter/brighter tint at the top edge,
        // fading toward the layer's true color at the bottom) so each wave
        // reads as glassy/premium instead of a flat poster-color fill.
        // Purely a rendering change — same layer.color drives both stops,
        // no data or proportions touched.
        // TASK 31.17 — extended to a 3-stop gradient (light top / true-color
        // mid / darkened bottom, using the newly-negative-capable
        // shadeHexColor) with richer stop-opacities, for noticeably more
        // depth/contrast + "richer gradients, brighter colors, better
        // transparency" per the brief. Still purely a rendering change.
        // TASK 31.18 — pushed to a 4-stop gradient (brighter top / true
        // color / darker lower-mid / darkest bottom) with a wider spread
        // between the lightest and darkest stops, for "stronger contrast /
        // more premium depth" per the follow-up. Still purely cosmetic —
        // same layer.color drives every stop.
        const gradId = 'priorityFlowGrad' + i;
        defs.push(
            '<linearGradient id="' + gradId + '" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="' + shadeHexColor(layer.color, 0.52) + '" stop-opacity="0.98"/>' +
            '<stop offset="32%" stop-color="' + layer.color + '" stop-opacity="0.92"/>' +
            '<stop offset="70%" stop-color="' + shadeHexColor(layer.color, -0.22) + '" stop-opacity="0.8"/>' +
            '<stop offset="100%" stop-color="' + shadeHexColor(layer.color, -0.48) + '" stop-opacity="0.62"/>' +
            '</linearGradient>'
        );

        return '<path d="' + d + '" fill="url(#' + gradId + ')" stroke="' + shadeHexColor(layer.color, 0.1) + '" stroke-width="2.5" stroke-opacity="0.98" stroke-linejoin="round" stroke-linecap="round"></path>';
    });

    // TASK 31.17 — a second, thinner "glossy highlight" stroke traced along
    // just the top edge of each band (topPts only, no fill), in a light tint
    // at low opacity, drawn after the main fill paths so it sits on top of
    // all of them. Purely decorative sheen for a premium/glassy read — does
    // not touch the data-derived geometry, just re-strokes the same top
    // curve already computed above.
    // TASK 31.18 — widened and brightened further (1.4px/0.55 opacity/tint
    // +0.55 -> 1.9px/0.75 opacity/tint +0.68) per the "better highlights"
    // ask; still a pure re-stroke of the same top curve, no new geometry.
    const highlights = layers.map(function (layer, i) {
        const topY = highlightTopYs[i];
        const topPts = xs.map(function (x, j) { return { x: x, y: topY[j] }; });
        const d = smoothCommands(topPts, true);
        return '<path d="' + d + '" fill="none" stroke="' + shadeHexColor(layer.color, 0.68) + '" stroke-width="1.9" stroke-opacity="0.75" stroke-linejoin="round" stroke-linecap="round"></path>';
    });

    el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" class="priority-flow-svg" aria-hidden="true"><defs>' + defs.join('') + '</defs>' + paths.join('') + highlights.join('') + '</svg>';
}

// TASK 31.4 — renders the "Updated Xm ago" text into the #status-chart-updated
// badge (see .status-chart-head-row markup) from _statusChartUpdatedAt. Safe
// to call with no successful fetch yet (badge simply stays at its static
// "Updated —" default). Called once right after a successful stats fetch and
// then re-called on a timer purely to age the displayed text.
function updateStatusChartFreshness() {
    const el = document.getElementById('status-chart-updated');
    if (!el || !_statusChartUpdatedAt) return;
    const diffMs = Date.now() - _statusChartUpdatedAt;
    const minute = 60 * 1000;
    const hour = 60 * minute;
    let label;
    if (diffMs < minute) {
        label = 'Updated just now';
    } else if (diffMs < hour) {
        label = `Updated ${Math.floor(diffMs / minute)}m ago`;
    } else {
        label = `Updated ${Math.floor(diffMs / hour)}h ago`;
    }
    el.textContent = label;
}

function refreshDashboardChartsForTheme() {
    if (!_lastDashboardStats) { initDashboard(); return; }
    const stats = _lastDashboardStats;
    const isLightMode = document.documentElement.getAttribute('data-theme-resolved') === 'light';
    const chartMutedText = isLightMode ? '#374151' : '#94a3b8';
    const chartGridY = isLightMode ? 'rgba(17, 24, 39, 0.12)' : 'rgba(148, 163, 184, 0.35)';
    const chartGridX = isLightMode ? 'rgba(17, 24, 39, 0.08)' : 'rgba(148, 163, 184, 0.25)';
    const doughnutBorder = isLightMode ? '#ffffff' : '#0f172a';
    const submitted  = (stats.submitted || 0) + (stats.assigned || 0);
    const inProgress = stats.in_progress || 0;
    const completed  = stats.completed   || 0;
    const cancelled  = stats.cancelled   || 0;
    const priorityLow      = stats.by_priority?.low      || 0;
    const priorityMedium   = stats.by_priority?.medium   || 0;
    const priorityHigh     = stats.by_priority?.high     || 0;
    const priorityCritical = stats.by_priority?.critical || 0;

    if (reportsStatusChart)  { reportsStatusChart.destroy();  reportsStatusChart  = null; }
    if (reportsPriorityChart){ reportsPriorityChart.destroy(); reportsPriorityChart = null; }

    const statusCanvas = document.getElementById('reportsStatusChart');
    if (statusCanvas && typeof Chart !== 'undefined') {
        const statusCtx = statusCanvas.getContext('2d');
        const centerPlugin = {
            id: 'statusCenterTextPlugin',
            afterDatasetsDraw(chart) {
                const meta = chart.getDatasetMeta(0);
                if (!meta || !meta.data || !meta.data.length) return;
                const pt = meta.data[0];
                const ctx = chart.ctx;
                const light = document.documentElement.getAttribute('data-theme-resolved') === 'light';
                const totalValue = String(getVisibleStatusTotal(chart));
                const numberFontSize = totalValue.length >= 3 ? 24 : 28;
                const labelFontSize = 13;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = `700 ${numberFontSize}px "Segoe UI", sans-serif`;
                ctx.fillStyle = light ? '#111827' : '#ffffff';
                ctx.fillText(totalValue, pt.x, pt.y - 7);
                ctx.font = `500 ${labelFontSize}px "Segoe UI", sans-serif`;
                ctx.fillStyle = light ? '#374151' : '#cbd5e1';
                ctx.fillText('total', pt.x, pt.y + 15);
                ctx.restore();
            }
        };
        const sPlugins = [centerPlugin];
        if (typeof ChartDataLabels !== 'undefined') sPlugins.push(ChartDataLabels);
        reportsStatusChart = new Chart(statusCtx, {
            type: 'doughnut', plugins: sPlugins,
            data: { labels: buildStatusLegendLabels(submitted, inProgress, completed), datasets: [{ data: [submitted, inProgress, completed], backgroundColor: ['#3b82f6', '#f59e0b', '#10b981'], borderColor: doughnutBorder, borderWidth: 2, hoverOffset: 3, spacing: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '68%', radiusScale: 1.15,
                onClick: (e, items) => {
                    if (items.length > 0) {
                        e.stopPropagation();
                        const idx = items[0].index;
                        const statuses = ['submitted', 'in_progress', 'completed'];
                        window.location.href = window.SFMS_PUBLIC_URL('/frontend/pages/reports.php?status=' + statuses[idx]);
                    }
                },
                plugins: {
                datalabels: { display: (c) => Number(c.dataset.data[c.dataIndex] || 0) > 0, formatter: (v) => String(v), color: '#ffffff', font: { weight: '700', size: 13 }, anchor: 'center', align: 'center' },
                legend: {
                    /* TASK 31.12 — was 'right'. chart-lite.js's _drawDoughnut() only
                       reserves/draws its own canvas legend when position === 'right'
                       (confirmed by reading chart-lite.js); 'none' is a supported
                       options value that simply skips that reservation, so the
                       donut uses the full canvas and the new HTML legend (see
                       renderStatusLegendHtml() + #reportsStatusLegend) takes over
                       instead. No chart-lite.js edit. */
                    position: 'none',
                    align: 'center',
                    labels: {
                        color: chartMutedText,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 10,
                        boxHeight: 10,
                        padding: 16,
                        font: {
                            /* TASK 31.10 — 14 -> 12, see buildStatusLegendLabels() comment */
                            size: 12,
                            weight: '600'
                        }
                    }
                },
                tooltip: { callbacks: { label: c => ` ${c.formattedValue} report${Number(c.formattedValue) !== 1 ? 's' : ''}` } }
            }}
        });
        _addDoughnutHoverCursor(statusCanvas, () => reportsStatusChart);
        renderStatusLegendHtml(submitted, inProgress, completed);
    }

    // TASK 31.15 — was a chart-lite.js bar chart on <canvas id="reportsPriorityChart">.
    // Replaced by the page-scoped SVG stream chart (see renderPriorityFlowChart()'s
    // comment above for why chart-lite.js couldn't produce this chart type).
    // Same real priorityLow/Medium/High/Critical counts, no new data.
    renderPriorityFlowChart(priorityLow, priorityMedium, priorityHigh, priorityCritical);
    renderPriorityLegendHtml(priorityLow, priorityMedium, priorityHigh, priorityCritical);
    renderPriorityTotalHtml(priorityLow, priorityMedium, priorityHigh, priorityCritical);
    renderPriorityInsightHtml(priorityLow, priorityMedium, priorityHigh, priorityCritical);
}
// TASK 25 — School Settings / semester widget.
// READ-ONLY display only: current_semester is now determined automatically
// (SchoolSetting::syncAutomatic(), driven by the Administrator-configured
// schedule). There is no manual "Change Semester" action anymore — the
// "Semester Settings" button is a plain link to semester-settings.php.
function formatSemesterDateShort(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return dateStr;
    // TASK 25.4 — abbreviated month ("Jun 1, 2026") per the simplified
    // metadata typography requested; was 'long' ("June 1, 2026") in Task 25.3.
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// TASK 25.5 — single-line "Jul 1, 2026 — Dec 31, 2026" range used by the
// "Academic Calendar" row, replacing the old two-column Start Date / End
// Date grid. Pure formatting only — reuses formatSemesterDateShort() for
// each side, joined with an em dash. Returns '—' if either date is missing.
function formatSemesterDateRange(startStr, endStr) {
    if (!startStr || !endStr) return '—';
    return formatSemesterDateShort(startStr) + ' — ' + formatSemesterDateShort(endStr);
}

// TASK 25.5 — presentation-only formatter for the already-existing
// `school_year` field ("2026-2027" -> "2026–2027", en dash). Never invents
// a school year; if the raw value doesn't match the expected "YYYY-YYYY"
// shape it is shown exactly as returned by the backend.
function formatSchoolYearLabel(schoolYear) {
    if (!schoolYear) return null;
    return /^\d{4}-\d{4}$/.test(schoolYear) ? schoolYear.replace('-', '–') : schoolYear;
}

// TASK 25.4 — pure presentational derivation from the two semester dates
// Task 25.2 already returns (start/end) plus the browser's real current
// date. This never overrides or re-derives which semester is active/status
// — semesterStatus/currentSemester still come exclusively from the backend.
// Returns null (caller skips the indicator entirely) whenever the dates are
// missing or invalid, so nothing is ever fabricated.
function computeSemesterProgress(startStr, endStr) {
    if (!startStr || !endStr) return null;
    const start = new Date(startStr + 'T00:00:00');
    const end = new Date(endStr + 'T00:00:00');
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const pct = Math.round(((today - start) / (end - start)) * 100);
    return Math.max(0, Math.min(100, pct));
}

// TASK 25.3 — Enterprise Academic Period Status Card. Maps each of the
// enumerated `semester_status` values Task 25.2's backend already returns
// (SchoolSetting::semesterStatus()) to a badge color/label. This is a pure
// presentation lookup — it does not decide, compute, or override the
// status itself; an unrecognized/unexpected value safely falls back to the
// "Not Configured" (red) presentation rather than guessing a semester.
const ACADEMIC_PERIOD_META = {
    'Running':                    { badgeClass: 'semester-badge-running',        badgeLabel: 'Running' },
    'Upcoming First Semester':    { badgeClass: 'semester-badge-upcoming',       badgeLabel: 'Upcoming' },
    'Semester Break':             { badgeClass: 'semester-badge-break',          badgeLabel: 'Semester Break' },
    'School Year Completed':      { badgeClass: 'semester-badge-completed',      badgeLabel: 'School Year Completed' },
    'Not Configured':             { badgeClass: 'semester-badge-not-configured', badgeLabel: 'Not Configured' },
};

// TASK 25.2 — Enterprise Semester Lifecycle. `currentSemester` is `null`
// whenever no semester is literally running today (Upcoming First Semester /
// Semester Break / School Year Completed) — the Dashboard must never display
// an incorrect active semester. `semesterStatus` (and, for the Upcoming
// case, `daysUntilStart`) are the exact values the backend already computed;
// nothing here recalculates a countdown or re-derives which state applies.
function renderSchoolSettingsLabel(schoolYear, currentSemester, firstSemStart, firstSemEnd, secondSemStart, secondSemEnd, semesterStatus, daysUntilStart) {
    const bodyEl = document.getElementById('academic-period-card-body');
    if (!bodyEl) return;

    const meta = ACADEMIC_PERIOD_META[semesterStatus] || ACADEMIC_PERIOD_META['Not Configured'];
    const isSecond = currentSemester === 'Second Semester';
    const durationStart = isSecond ? secondSemStart : firstSemStart;
    const durationEnd = isSecond ? secondSemEnd : firstSemEnd;

    let html = '<span class="status-badge ' + meta.badgeClass + '">'
        + '<span class="semester-badge-dot" aria-hidden="true"></span>' + meta.badgeLabel
        + '</span>';

    // TASK 25.6 — single subtle divider separating "Status" (the badge)
    // from "Session Information" (everything below), per the requested
    // enterprise hierarchy pass. Shown for every state so the structure is
    // consistent regardless of which branch below renders. Reuses the
    // shared --border token via .academic-period-divider (CSS) — no new
    // color introduced.
    html += '<div class="academic-period-divider" aria-hidden="true"></div>';

    // TASK 25.5 — metadata rows (Academic Calendar / Academic Year / Next
    // Semester) are collected into one array and rendered as a single
    // .academic-period-dates vertical stack, reusing the exact same
    // label/value classes that already existed for the old Start Date /
    // End Date grid. No new CSS is introduced for this — it is the same
    // component with a different set of items inside it.
    const metaItems = [];

    // TASK 25.4 — layout simplified per the enterprise mockups: the
    // Running/Upcoming states drop their explanatory sentence (the badge +
    // semester name + dates/countdown are already self-explanatory), while
    // Semester Break and Not Configured keep a short line since there is no
    // date/countdown to anchor those states visually.
    if (semesterStatus === 'Running') {
        html += '<div class="academic-period-semester-name">' + (currentSemester || '—') + '</div>';

        // TASK 25.5 — single "Academic Calendar" row (one date range) in
        // place of the old two-column Start Date / End Date grid.
        metaItems.push({ label: 'Academic Calendar', value: formatSemesterDateRange(durationStart, durationEnd) });

        // Optional progress indicator — only rendered when it can be
        // accurately derived from the two dates the backend already
        // returns; never shown otherwise (see computeSemesterProgress()).
        // Rendered further below, after the metadata row, to match the
        // requested "dates, then progress bar" order.
        var runningProgressPct = computeSemesterProgress(durationStart, durationEnd);
    } else if (semesterStatus === 'Upcoming First Semester') {
        html += '<div class="academic-period-semester-name">First Semester</div>';
        if (Number.isInteger(daysUntilStart)) {
            html += '<div class="academic-period-countdown">Starts in ' + daysUntilStart + ' day' + (daysUntilStart === 1 ? '' : 's') + '</div>';
        }
    } else if (semesterStatus === 'Semester Break') {
        html += '<p class="academic-period-desc">No active academic session.</p>';
        // Semester Break, by construction of SchoolSetting::semesterStatus(),
        // only occurs in the gap between First Semester end and Second
        // Semester start — so "next semester" is always second_sem_start,
        // a field the backend already returns. Nothing is invented; if it
        // is somehow missing, the date line is simply skipped.
        if (secondSemStart) {
            metaItems.push({ label: 'Next Semester', value: formatSemesterDateShort(secondSemStart) });
        }
    } else if (semesterStatus === 'School Year Completed') {
        html += '<p class="academic-period-desc">The configured school year has already ended.</p>';
    } else {
        // "Not Configured" (or any unrecognized status) — defensive, never
        // shown once an Administrator has saved a schedule.
        html += '<p class="academic-period-desc">Semester schedule has not been configured.</p>'
            + '<p class="academic-period-hint">Configure the academic calendar using Semester Settings.</p>';
    }

    // TASK 25.5 item 3 / TASK 25.6 item 2 — "If school_year already exists
    // in the dashboard payload, display it inside the card" (it does — see
    // DashboardController::stats() / SchoolSettingsController::present()).
    // Shown across every state, appended to whatever metadata row already
    // exists for that state. Label renamed "School Year" -> "Academic
    // Year" per Task 25.6 (presentation only — the underlying field is
    // still `school_year`; formatSchoolYearLabel() is unchanged).
    const schoolYearLabel = formatSchoolYearLabel(schoolYear);
    if (schoolYearLabel) {
        metaItems.push({ label: 'Academic Year', value: schoolYearLabel });
    }

    if (metaItems.length) {
        html += '<div class="academic-period-dates">'
            + metaItems.map(function (item) {
                return '<div class="academic-period-date-item"><span class="academic-period-date-label">' + item.label + '</span><span class="academic-period-date-value">' + item.value + '</span></div>';
            }).join('')
            + '</div>';
    }

    // TASK 25.6 — the progress bar now sits on its own line, with its
    // percentage caption reusing the identical .academic-period-date-item/
    // -label/-value markup as the Academic Calendar / Academic Year rows
    // above (label "Semester Progress" above value "XX% Complete"), so all
    // three metadata labels share one left-aligned column instead of the
    // old floating "XX% Complete" text beside the bar.
    if (semesterStatus === 'Running' && runningProgressPct !== null && runningProgressPct !== undefined) {
        html += '<div class="academic-period-progress">'
            +   '<div class="academic-period-progress-track"><div class="academic-period-progress-fill" style="width:' + runningProgressPct + '%"></div></div>'
            +   '<div class="academic-period-date-item"><span class="academic-period-date-label">Semester Progress</span><span class="academic-period-date-value">' + runningProgressPct + '% Complete</span></div>'
            + '</div>';
    }

    bodyEl.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', () => {
    // Every role sees the read-only semester summary via the dashboard
    // stats payload (no extra request needed), but if this page loads
    // before that fetch resolves for any reason, fall back to a direct
    // read of /api/school-settings so the card never sticks on
    // "Loading semester…".
    if (document.getElementById('academic-period-card-body')) {
        fetch(window.SFMS_PUBLIC_URL('/api/school-settings'), { credentials: 'include', cache: 'no-store' })
            .then(r => r.json())
            .then(json => {
                if (json.success && json.data) {
                    renderSchoolSettingsLabel(
                        json.data.school_year,
                        json.data.current_semester,
                        json.data.first_sem_start,
                        json.data.first_sem_end,
                        json.data.second_sem_start,
                        json.data.second_sem_end,
                        json.data.semester_status,
                        json.data.semester_days_until_start
                    );
                }
            })
            .catch(e => console.error('[Dashboard] school-settings label fetch error:', e));
    }
});

// Modal Functions
function openBuildingModal() {
    document.getElementById('buildingModal').classList.add('show');
    document.getElementById('buildingNameInput').focus();
}

function closeBuildingModal() {
    document.getElementById('buildingModal').classList.remove('show');
    document.getElementById('buildingForm').reset();
}
function openRoomModal() {
    document.getElementById('roomModal').classList.add('show');
    loadBuildingsInModal();
}

function loadFloorsForRoom(buildingId) {
    const floorSelect = document.getElementById('roomFloorSelect');
    floorSelect.innerHTML = '<option value="">Choose a floor</option>';
    if (!buildingId) return;
    fetch(window.SFMS_PUBLIC_URL(`/api/buildings/${buildingId}/floors`))
        .then(res => res.json())
        .then(data => {
            if (data.success && (data.data?.floors || data.floors)) {
                (data.data?.floors || data.floors).forEach(f => {
                    const opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = f.name;
                    floorSelect.appendChild(opt);
                });
            }
        });
}

function closeRoomModal() {
    document.getElementById('roomModal').classList.remove('show');
    document.getElementById('roomForm').reset();
}

function navigateToReportsCard(cardKey) {
    if (cardKey === 'low_stock') {
        window.location.href = '/School_Facility_Maintenance_System/frontend/pages/inventory.php?status_filter=low_stock';
        return;
    }

    const targetUrl = new URL('/School_Facility_Maintenance_System/frontend/pages/reports.php', window.location.origin);

    if (cardKey === 'today') {
        targetUrl.searchParams.set('date_scope', 'today');
    } else {
        // TASK — Total Reports / Pending Tasks / In Progress / Completed are
        // all computed by DashboardController::stats() scoped to the ACTIVE
        // SEMESTER (created_at >= semester_started_at) — never "current
        // calendar month" and never "all time ever". Without an explicit
        // date_scope here, reports.php's own DOMContentLoaded handler
        // defaults a plain load to the current calendar month (a deliberate,
        // unrelated product decision — see ISS-02 comment there), which was
        // silently narrowing these 4 cards to a DIFFERENT window than the one
        // their own number actually counts. date_scope=semester tells
        // reports.php to pre-fill the same active-semester date range the
        // dashboard used, so the row count on screen matches the KPI number
        // that was just clicked.
        targetUrl.searchParams.set('date_scope', 'semester');

        if (cardKey === 'pending_tasks') {
            targetUrl.searchParams.set('status_group', 'pending_tasks');
        } else if (cardKey === 'in_progress') {
            targetUrl.searchParams.set('status', 'in_progress');
        } else if (cardKey === 'completed') {
            targetUrl.searchParams.set('status', 'completed');
        }
        // cardKey === 'total' → date_scope=semester only, no status filter,
        // matching COUNT(*) with no status WHERE clause in stats().
    }

    window.location.href = targetUrl.toString();
}

// TASK 101 — this dashboard's Buildings Overview navigation handler was removed
// together with the card that was its only caller. Buildings Overview is
// reached from the sidebar now.

// Load buildings in room modal
function loadBuildingsInModal() {
    const buildingSelect = document.getElementById('roomBuildingSelect');
    const floorSelect = document.getElementById('roomFloorSelect');
    buildingSelect.innerHTML = '<option value="">Choose a building</option>';
    floorSelect.innerHTML = '<option value="">Choose a floor</option>';
    
    // Get buildings from API or use mock data
    fetch(window.SFMS_PUBLIC_URL('/api/buildings'))
        .then(res => res.json())
        .then(data => {
            const buildings = data.data?.buildings || data.buildings || [];
            if (data.success && buildings.length > 0) {
                buildings.forEach(building => {
                    const option = document.createElement('option');
                    option.value = building.id;
                    option.textContent = building.name;
                    buildingSelect.appendChild(option);
                });
            } else {
                console.log('No buildings found or API error:', data.message);
                // Fall back to mock data
                loadMockBuildings();
            }
        })
        .catch(err => {
            console.log('Error loading buildings, using mock data:', err);
            loadMockBuildings();
        });
}

function loadMockBuildings() {
    const buildingSelect = document.getElementById('roomBuildingSelect');
    buildingSelect.innerHTML = '<option value="">Choose a building</option><option value="" disabled style="color: #999;">--- Setup database first ---</option>';
    buildingSelect.disabled = true;
    
    // Show setup link
    const roomForm = document.getElementById('roomForm');
    if (roomForm && !roomForm.querySelector('.setup-notice')) {
        const notice = document.createElement('div');
        notice.className = 'setup-notice';
        notice.style.cssText = 'background: #8b2020; color: #ffcccc; padding: 12px; border-radius: 6px; margin-top: 12px; font-size: 12px;';
        notice.innerHTML = (window.UIIcons ? window.UIIcons.svg('alert-triangle', { size: 14 }) : '') + ' No buildings found. <a href="/School_Facility_Maintenance_System/frontend/pages/buildings-overview.php" style="color: #ffb3b3; text-decoration: underline;">Add a building</a> first.';
        roomForm.appendChild(notice);
    }
}

// Save building data
async function saveBuildingData() {
    const buildingName = document.getElementById('buildingNameInput').value.trim();
    const buildingDesc = document.getElementById('buildingDescInput').value.trim();
    
    if (!buildingName) {
        Components.alert('Please enter a building name', 'warning');
        return;
    }

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/buildings'), {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name: buildingName,
                description: buildingDesc
            })
        });

        const result = await response.json();

        if (result.success) {
            Components.alert('Building added successfully!', 'success');
            closeBuildingModal();
            // Refresh buildings list
            loadBuildingsInModal();
        } else {
            const errorMsg = result.message || 'Failed to add building';

            // Check if it's a table not found error
            if (errorMsg.includes('table not found')) {
                Components.alert(errorMsg + '\n\nPlease run database setup first:\nhttp://localhost/School_Facility_Maintenance_System/backend/setup.html', 'danger');
            } else {
                Components.alert(errorMsg, 'danger');
            }
        }
    } catch (error) {
        console.error('Error saving building:', error);
        Components.alert('Error saving building. Please try again.\n\nIf you see this repeatedly, run setup: http://localhost/School_Facility_Maintenance_System/backend/setup.html', 'danger');
    }
}

// Save room data
async function saveRoomData() {
    const buildingId = document.getElementById('roomBuildingSelect').value.trim();
    const floorId = document.getElementById('roomFloorSelect').value.trim();
    const roomName = document.getElementById('roomNameInput').value.trim();
    const roomCapacity = document.getElementById('roomCapacityInput').value.trim();
    
    if (!buildingId) {
        Components.alert('Please select a building', 'warning');
        return;
    }
    if (!floorId) {
        Components.alert('Please select a floor', 'warning');
        return;
    }

    if (!roomName) {
        Components.alert('Please enter a room name/number', 'warning');
        return;
    }
    
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/rooms'), {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                building_id: buildingId,
                floor_id: floorId,
                name: roomName,
                capacity: roomCapacity || null
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            Components.alert('Room added successfully!', 'success');
            closeRoomModal();
        } else {
            const errorMsg = result.message || 'Failed to add room';

            // Check if it's a table not found error
            if (errorMsg.includes('table not found')) {
                Components.alert(errorMsg + '\n\nPlease run database setup first:\nhttp://localhost/School_Facility_Maintenance_System/backend/setup.html', 'danger');
            } else {
                Components.alert(errorMsg, 'danger');
            }
        }
    } catch (error) {
        console.error('Error saving room:', error);
        Components.alert('Error saving room. Please try again.\n\nIf you see this repeatedly, run setup: http://localhost/School_Facility_Maintenance_System/backend/setup.html', 'danger');
    }
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const buildingModal = document.getElementById('buildingModal');
    const roomModal = document.getElementById('roomModal');
    
    if (event.target === buildingModal) {
        closeBuildingModal();
    }
    if (event.target === roomModal) {
        closeRoomModal();
    }
});

</script>

<script>

function buildStatsFromReports(reports) {
    const stats = {
        total: 0,
        reports_today: 0,
        submitted: 0,
        assigned: 0,
        in_progress: 0,
        completed: 0,
        closed: 0,
        cancelled: 0,
        by_priority: {
            low: 0,
            medium: 0,
            high: 0,
            critical: 0
        }
    };

    const now = new Date();
    const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

    reports.forEach((report) => {
        const rawStatus = (report.status || '').toLowerCase().trim();
        const hasAssignee = Number(report.assigned_to || 0) > 0;
        // Normalize legacy rows: once a report has an assignee, treat it as assigned unless actively in progress/completed/closed/cancelled.
        let status = rawStatus;
        if (hasAssignee && (rawStatus === '' || rawStatus === 'submitted')) {
            status = 'assigned';
        }
        const priority = (report.priority || '').toLowerCase();
        const normalizedPriority = priority === 'urgent' ? 'critical' : priority;
        const createdAt = (report.created_at || '').toString().slice(0, 10);

        stats.total += 1;

        if (createdAt === todayKey) {
            stats.reports_today += 1;
        }

        if (Object.prototype.hasOwnProperty.call(stats, status)) {
            stats[status] += 1;
        }

        if (Object.prototype.hasOwnProperty.call(stats.by_priority, normalizedPriority)) {
            stats.by_priority[normalizedPriority] += 1;
        }
    });

    return stats;
}

// TASK 31.10 — real, client-computed KPI trend badges (arrow + %), replacing
// the reference's fabricated-looking "↑18% vs last month" style pills with
// numbers actually derived from this page's already-fetched report rows
// (created_at timestamps, from the same /api/reports?per_page=200 call
// fetchDashboardStats() already makes — see its `_reports` field). Per the
// explicit instruction: "If there is no real trend percentage available...
// do not invent one — simply omit it." countReportsInRange()/computeCountTrend()
// return null whenever there isn't a usable, non-zero baseline to compare
// against, and every call site below falls back to the existing static
// caption text (e.g. "this semester") when that happens — no card is ever
// left blank or shown a guessed number.
function countReportsInRange(reports, matchFn, startMs, endMs) {
    return (reports || []).reduce((count, report) => {
        if (matchFn && !matchFn(report)) return count;
        const t = new Date(report.created_at || '').getTime();
        if (Number.isNaN(t) || t < startMs || t >= endMs) return count;
        return count + 1;
    }, 0);
}

function computeCountTrend(reports, matchFn, windowDays) {
    const now = Date.now();
    const day = 24 * 60 * 60 * 1000;
    const currentStart = now - windowDays * day;
    const previousStart = now - (windowDays * 2) * day;
    const currentCount = countReportsInRange(reports, matchFn, currentStart, now);
    const previousCount = countReportsInRange(reports, matchFn, previousStart, currentStart);
    // No baseline to compare against — omit rather than show a misleading
    // "+100%" off a previous count of 0, or fabricate a number.
    if (!previousCount) return null;
    const pct = Math.round(((currentCount - previousCount) / previousCount) * 100);
    if (pct === 0) return null;
    return { pct: Math.abs(pct), up: pct > 0 };
}

// Renders a real trend badge into an existing .summary-card-desc element,
// reusing its existing summary-trend-positive/-warning/-danger color classes
// (already themed for dark/light — no new tokens). `positiveIsGood` controls
// which direction (up/down) maps to the "good" green vs "bad" red class —
// e.g. more completed reports is good (up=positive), more pending is not.
// Returns false (and leaves the element's existing static caption alone)
// whenever `trend` is null, i.e. there wasn't enough real data to compute one.
function renderKpiTrend(descEl, trend, positiveIsGood, windowLabel) {
    if (!descEl || !trend) return false;
    const isGood = positiveIsGood ? trend.up : !trend.up;
    const variant = isGood ? 'summary-trend-positive' : 'summary-trend-danger';
    // TASK 31.11 — reference shows only the arrow+percentage inside the
    // colored pill, with "vs {window}" as separate plain muted text beside
    // it (was: the whole "arrow+pct% vs window" string pilled as one solid
    // block). descEl itself no longer takes the pill color class; a nested
    // .summary-trend-chip span does, so the trailing window text can render
    // as plain text next to it. .summary-trend-row is a new layout-only
    // class (flex row, no background) — the pre-existing .summary-trend-
    // positive/-warning/-danger pill rules stay unchanged for the static
    // default captions ("this semester", "No Active Semester") that never
    // pass through this function.
    descEl.classList.remove('summary-trend-positive', 'summary-trend-warning', 'summary-trend-danger');
    descEl.classList.add('summary-trend-row');
    // TASK 7.1 — was '&#8593;' / '&#8595;' (↑ / ↓ as entity-encoded text glyphs).
    // Now the same registry arrows the Analytics Dashboard already uses. The
    // direction is still repeated by the adjacent percentage and the variant
    // pill colour, and the wrapping .summary-trend-arrow span keeps its
    // aria-hidden, so this stays decorative. trend.up / trend.pct and the
    // variant logic are untouched — only the glyph changes.
    const arrow = window.UIIcons
        ? window.UIIcons.svg(trend.up ? 'arrow-up' : 'arrow-down', { size: 14 })
        : '';
    descEl.innerHTML = '<span class="summary-trend-chip ' + variant + '">'
        + '<span class="summary-trend-arrow" aria-hidden="true">' + arrow + '</span>' + trend.pct + '%</span>'
        + '<span class="summary-trend-window">vs ' + windowLabel + '</span>';
    return true;
}

function buildChartStatsFromPayload(payload) {
    const statusStats = {
        submitted: 0,
        in_progress: 0,
        completed: 0
    };
    const priorityStats = {
        low: 0,
        medium: 0,
        high: 0,
        critical: 0
    };

    const statusChart = payload && payload.statusChart ? payload.statusChart : null;
    if (statusChart && Array.isArray(statusChart.labels) && Array.isArray(statusChart.data)) {
        statusChart.labels.forEach((label, index) => {
            const key = String(label || '').toLowerCase().replace(/\s+/g, '_');
            if (Object.prototype.hasOwnProperty.call(statusStats, key)) {
                statusStats[key] = Number(statusChart.data[index] || 0);
            }
        });
    }

    const priorityChart = payload && payload.priorityChart ? payload.priorityChart : null;
    if (priorityChart && Array.isArray(priorityChart.labels) && Array.isArray(priorityChart.data)) {
        priorityChart.labels.forEach((label, index) => {
            const key = String(label || '').toLowerCase();
            if (Object.prototype.hasOwnProperty.call(priorityStats, key)) {
                priorityStats[key] = Number(priorityChart.data[index] || 0);
            }
        });
    }

    return {
        submitted: statusStats.submitted,
        in_progress: statusStats.in_progress,
        completed: statusStats.completed,
        total: statusStats.submitted + statusStats.in_progress + statusStats.completed,
        by_priority: priorityStats
    };
}

async function fetchDashboardStats() {
    const zero = {
        total: 0, reports_today: 0, submitted: 0, assigned: 0,
        in_progress: 0, completed: 0, closed: 0, cancelled: 0,
        low_stock: 0,
        by_priority: { low: 0, medium: 0, high: 0, critical: 0 },
        semester_active: true, semester_status: '', semester_days_until_start: null,
        semester_started_at: null,
        _reports: [],
    };

    // Attempt 1: fast totals from the dedicated stats endpoint.
    let apiStats = null;
    try {
        const statsResp = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/stats'), {
            credentials: 'include', cache: 'no-store',
        });
        const statsJson = await statsResp.json();
        console.log('[Dashboard] stats API status:', statsResp.status);
        console.log('[Dashboard] stats API response:', JSON.stringify(statsJson));
        if (statsJson.success && statsJson.data) {
            apiStats = statsJson.data;
        }
    } catch (e) { console.error('[Dashboard] stats API error:', e); }

    // Attempt 2: detailed breakdown from the report list.
    // Needed for assigned / closed / cancelled / by_priority / reports_today.
    let derived = null;
    // TASK 31.10 — the raw report rows (with real created_at timestamps) are
    // kept alongside `derived` and attached to the merged object below as
    // `_reports`, so initDashboard() can compute genuine period-over-period
    // KPI trend percentages (see computeCountTrend()) without a second fetch
    // of an endpoint this function already calls.
    let rawReports = [];
    try {
        const reportsResp = await fetch(window.SFMS_PUBLIC_URL('/api/reports?per_page=200'), {
            credentials: 'include', cache: 'no-store',
        });
        const reportsJson = await reportsResp.json();
        console.log('[Dashboard] reports API status:', reportsResp.status);
        console.log('[Dashboard] reports API response:', JSON.stringify(reportsJson));
        if (reportsJson.success) {
            rawReports = extractReportsFromResponse(reportsJson);
            derived = buildStatsFromReports(rawReports);
        }
    } catch (e) { console.error('[Dashboard] reports API error:', e); }

    if (!apiStats && !derived) {
        console.warn('[Dashboard] both API calls failed — returning zeros');
        return zero;
    }

    // TASK 25.2 — Enterprise Semester Lifecycle: when the backend reports
    // no active semester, `apiStats.total_reports` / `pending` /
    // `in_progress` / `completed` are `null` (never a guessed number). That
    // null-ness must survive the merge (NOT get coerced to 0 via `|| 0`),
    // so initDashboard() below can render "No Active Semester" instead of
    // a misleading count on those 4 semester-scoped KPI cards.
    const semesterActive = apiStats ? !!apiStats.semester_active : true;

    // Merge: API stats for totals/low_stock; derived for fine-grained status split.
    const merged = {
        total:         apiStats ? (apiStats.total_reports === null ? null : Number(apiStats.total_reports || 0)) : (derived?.total        || 0),
        reports_today: derived  ? (derived.reports_today        || 0)  : Number(apiStats?.reports_today || 0),
        submitted:     derived  ? (derived.submitted            || 0)  : Number(apiStats?.pending       || 0),
        assigned:      derived  ? (derived.assigned             || 0)  : 0,
        // TASK 5 — semester-scoped pending, for the "Pending Tasks" KPI card.
        //
        // That card used to render `submitted + assigned`, both of which come
        // from `derived` — i.e. the ALL-TIME /api/reports list — while the
        // Total / In Progress / Completed cards beside it come from apiStats,
        // which DashboardController::stats() scopes to the active semester.
        // One KPI row was therefore showing three semester-scoped numbers and
        // one all-time number, all under the same "this semester" framing:
        // Total Reports 0 but Pending Tasks 2, from the same 3 reports.
        //
        // Buildings Overview's "Pending Requests" card already reads
        // apiStats.pending straight from this endpoint (see
        // loadPendingRequestsCount() there), which is why it showed 0 while
        // this dashboard showed 2 for what is labelled the same metric.
        // Reading apiStats.pending here makes both screens agree by using the
        // one source of truth that already existed, rather than adding a
        // second calculation.
        //
        // Null (no active semester) is preserved exactly as it is for the
        // other three semester-scoped cards, so the "No Active Semester"
        // branch in initDashboard() keeps working.
        // `submitted` / `assigned` above are left untouched — they still feed
        // the all-time status breakdown used elsewhere on the page.
        pending:       apiStats ? (apiStats.pending === null ? null : Number(apiStats.pending || 0)) : ((derived?.submitted || 0) + (derived?.assigned || 0)),
        in_progress:   apiStats ? (apiStats.in_progress === null ? null : Number(apiStats.in_progress || 0)) : (derived?.in_progress  || 0),
        completed:     apiStats ? (apiStats.completed === null ? null : Number(apiStats.completed || 0)) : (derived?.completed    || 0),
        closed:        derived  ? (derived.closed               || 0)  : 0,
        cancelled:     derived  ? (derived.cancelled            || 0)  : 0,
        low_stock:     apiStats ? Number(apiStats.low_stock     || 0)  : 0,
        by_priority:   derived?.by_priority || { low: 0, medium: 0, high: 0, critical: 0 },
        school_year:       apiStats ? (apiStats.school_year       || '') : '',
        current_semester:  apiStats ? (apiStats.current_semester  || '') : '',
        first_sem_start:   apiStats ? (apiStats.first_sem_start   || '') : '',
        first_sem_end:     apiStats ? (apiStats.first_sem_end     || '') : '',
        second_sem_start:  apiStats ? (apiStats.second_sem_start  || '') : '',
        second_sem_end:    apiStats ? (apiStats.second_sem_end    || '') : '',
        semester_active:            semesterActive,
        semester_status:            apiStats ? (apiStats.semester_status || '') : '',
        semester_days_until_start:  apiStats ? apiStats.semester_days_until_start : null,
        // ISS-01 — the exact date the backend used as the lower bound for the
        // 4 semester-scoped KPIs (see DashboardController::stats()). Used only
        // by renderDashboardScopeNotice() to state that boundary verbatim.
        semester_started_at:        apiStats ? (apiStats.semester_started_at || null) : null,
        // TASK 31.10 — real report rows (created_at, status, priority), reused
        // by computeCountTrend() below for real KPI trend badges. Not rendered
        // directly anywhere; purely an internal data handoff.
        _reports: rawReports,
    };
    console.log('[Dashboard] final merged stats:', JSON.stringify(merged));
    return merged;
}

async function fetchMonthlyChartStats(year = selectedYear, month = selectedMonth) {
    const normalized = normalizeMonthSelection(year, month) || {
        year: selectedYear,
        month: selectedMonth
    };

    const zero = {
        submitted: 0, assigned: 0, in_progress: 0, completed: 0, cancelled: 0, total: 0,
        by_priority: { low: 0, medium: 0, high: 0, critical: 0 }
    };

    try {
        const { dateFrom, dateTo } = getMonthDateRange(normalized.year, normalized.month);
        const response = await fetch(
            window.SFMS_PUBLIC_URL(`/api/reports?per_page=200&date_from=${dateFrom}&date_to=${dateTo}`),
            { credentials: 'include', cache: 'no-store' }
        );
        const payload = await response.json();

        if (!payload.success) {
            return zero;
        }

        const stats = buildStatsFromReports(extractReportsFromResponse(payload));
        return {
            submitted:   stats.submitted   || 0,
            assigned:    stats.assigned    || 0,
            in_progress: stats.in_progress || 0,
            completed:   stats.completed   || 0,
            cancelled:   stats.cancelled   || 0,
            total: (stats.submitted || 0) + (stats.assigned || 0) + (stats.in_progress || 0) + (stats.completed || 0),
            by_priority: stats.by_priority || { low: 0, medium: 0, high: 0, critical: 0 }
        };
    } catch (error) {
        console.error('Error loading monthly chart stats:', error);
        return zero;
    }
}

function initializeClickableCards() {
    document.querySelectorAll('.stat-card-clickable, .chart-card-clickable').forEach((card) => {
        const navigate = () => {
            const target = card.getAttribute('data-href');
            if (target) {
                window.location.href = target;
            }
        };

        card.addEventListener('click', navigate);
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                navigate();
            }
        });
    });
}

// ── Buildings Overview ──────────────────────────────────────────────────────
let _dashBuildingsData = [];   // cache for print

async function loadDashboardBuildings() {
    const grid    = document.getElementById('dash-buildings-grid');
    const viewAll = document.getElementById('dash-buildings-viewall');
    if (!grid) return;

    const overviewUrl = window.SFMS_PUBLIC_URL ? window.SFMS_PUBLIC_URL('/buildings-overview') : '/buildings-overview';
    if (viewAll) viewAll.href = overviewUrl;

    try {
        const res  = await fetch(window.SFMS_PUBLIC_URL('/api/buildings?per_page=12'), { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) {
            grid.innerHTML = '<span style="font-size:13px;color:#64748b;">No buildings found.</span>';
            return;
        }

        const buildings = (data.data && Array.isArray(data.data.buildings))
            ? data.data.buildings
            : (Array.isArray(data.buildings) ? data.buildings : []);

        if (buildings.length === 0) {
            grid.innerHTML = '<span style="font-size:13px;color:#64748b;">No buildings yet.</span>';
            return;
        }

        _dashBuildingsData = buildings; // cache for print

        grid.innerHTML = buildings.map((b) => {
            const name     = String(b.name || 'Unnamed').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const floors   = Number(b.floor_count  || 0);
            const rooms    = Number(b.room_count   || 0);
            const floorTxt = floors === 1 ? '1 floor'  : `${floors} floors`;
            const roomTxt  = rooms  === 1 ? '1 room'   : `${rooms} rooms`;
            return `<div
                style="background:rgba(56,189,248,0.07);border:1px solid rgba(56,189,248,0.18);border-radius:10px;padding:10px 12px;cursor:pointer;transition:background 0.15s;"
                onmouseenter="this.style.background='rgba(56,189,248,0.13)'"
                onmouseleave="this.style.background='rgba(56,189,248,0.07)'"
                onclick="window.location.href='${overviewUrl}'">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
                    </svg>
                    <span style="font-size:13px;font-weight:600;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${name}</span>
                </div>
                <div style="font-size:11px;color:#94a3b8;">${floorTxt} · ${roomTxt}</div>
            </div>`;
        }).join('');
    } catch (err) {
        console.warn('Buildings overview load failed:', err);
        grid.innerHTML = '<span style="font-size:13px;color:#64748b;">Could not load buildings.</span>';
    }
}

// ── Buildings Overview Print Modal ──────────────────────────────────────────

const _bldgPm = {
    selectedBuildings: new Set(),
    selectedFloors:    {},   // { [buildingId]: Set<floorId> }
    floorCache:        {},   // { [buildingId]: floor[] }
    loadingSet:        new Set(),
};

function _bldgPmEsc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openBldgPrintModal() {
    if (!_dashBuildingsData.length) return;

    // Reset state — all buildings selected by default
    _bldgPm.selectedBuildings = new Set(_dashBuildingsData.map((b) => b.id));
    _bldgPm.selectedFloors    = {};
    _bldgPm.loadingSet        = new Set();

    _bldgPmRenderBuildings();
    _bldgPmRenderFloors();

    // Lazily fetch floors for each building
    _dashBuildingsData.forEach((b) => _bldgPmFetchFloors(b.id));

    const modal = document.getElementById('bldg-print-modal');
    modal.hidden = false;
    modal.removeAttribute('aria-hidden');
    document.body.style.overflow = 'hidden';
}

function closeBldgPrintModal() {
    const modal = document.getElementById('bldg-print-modal');
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

async function _bldgPmFetchFloors(buildingId) {
    if (_bldgPm.floorCache[buildingId] !== undefined || _bldgPm.loadingSet.has(buildingId)) return;
    _bldgPm.loadingSet.add(buildingId);
    _bldgPmRenderFloors();

    try {
        const res     = await fetch(window.SFMS_PUBLIC_URL(`/api/buildings/${buildingId}/floors`), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        const payload = await res.json();
        const floors  = Array.isArray(payload?.data?.floors) ? payload.data.floors : [];
        _bldgPm.floorCache[buildingId]    = floors;
        _bldgPm.selectedFloors[buildingId] = new Set(floors.map((f) => f.id));
    } catch (_) {
        _bldgPm.floorCache[buildingId] = [];
    }

    _bldgPm.loadingSet.delete(buildingId);
    _bldgPmRenderFloors();
}

function _bldgPmRenderBuildings() {
    const container = document.getElementById('bldg-pm-blist');
    if (!container) return;

    if (!_dashBuildingsData.length) {
        container.innerHTML = '<p style="font-size:13px;color:var(--muted-text);margin:0;">No buildings available.</p>';
        return;
    }

    container.innerHTML = _dashBuildingsData.map((b) => {
        const checked = _bldgPm.selectedBuildings.has(b.id) ? 'checked' : '';
        return `<label class="bldg-pm-check-row">
            <input type="checkbox" ${checked} onchange="bldgPmToggleBuilding(${b.id}, this.checked)">
            <span class="bldg-pm-check-label">${_bldgPmEsc(b.name)}</span>
            <span class="bldg-pm-check-meta">${Number(b.floor_count||0)} floors · ${Number(b.room_count||0)} rooms</span>
        </label>`;
    }).join('');
}

function _bldgPmRenderFloors() {
    const container = document.getElementById('bldg-pm-flist');
    if (!container) return;

    const selectedBldgs = _dashBuildingsData.filter((b) => _bldgPm.selectedBuildings.has(b.id));

    if (selectedBldgs.length === 0) {
        container.innerHTML = '<p style="font-size:13px;color:var(--muted-text);margin:0;font-style:italic;">Select at least one building above to choose floors.</p>';
        return;
    }

    const multiBuilding = selectedBldgs.length > 1;
    let html = '';

    selectedBldgs.forEach((b) => {
        const isLoading = _bldgPm.loadingSet.has(b.id);
        const floors    = _bldgPm.floorCache[b.id];

        if (multiBuilding) {
            const allSelected = floors && floors.length > 0 &&
                floors.every((f) => _bldgPm.selectedFloors[b.id]?.has(f.id));
            const groupChecked = (floors && floors.length > 0 && allSelected) ? 'checked' : '';

            html += `<div class="bldg-pm-group" id="bldg-pm-group-${b.id}">
                <div class="bldg-pm-group-hd" onclick="bldgPmToggleGroup(${b.id})">
                    <svg class="bldg-pm-group-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    <label class="bldg-pm-check-row bldg-pm-group-lbl" onclick="event.stopPropagation()">
                        <input type="checkbox" ${groupChecked} onchange="bldgPmGroupToggleFloors(${b.id}, this.checked)">
                        <span class="bldg-pm-check-label" style="font-weight:600;">${_bldgPmEsc(b.name)}</span>
                    </label>
                </div>
                <div class="bldg-pm-group-rows">`;
        }

        if (isLoading) {
            html += `<p class="${multiBuilding ? 'bldg-pm-floor-row' : ''}" style="font-size:13px;color:var(--muted-text);margin:4px 0;font-style:italic;">Loading floors…</p>`;
        } else if (!floors || floors.length === 0) {
            html += `<p class="${multiBuilding ? 'bldg-pm-floor-row' : ''}" style="font-size:13px;color:var(--muted-text);margin:4px 0;font-style:italic;">No floors found.</p>`;
        } else {
            floors.forEach((f) => {
                const checked = _bldgPm.selectedFloors[b.id]?.has(f.id) ? 'checked' : '';
                html += `<label class="bldg-pm-check-row ${multiBuilding ? 'bldg-pm-floor-row' : ''}">
                    <input type="checkbox" ${checked} onchange="bldgPmToggleFloor(${b.id}, ${f.id}, this.checked)">
                    <span class="bldg-pm-check-label">${_bldgPmEsc(f.name)}</span>
                    <span class="bldg-pm-check-meta">${Number(f.room_count||0)} rooms</span>
                </label>`;
            });
        }

        if (multiBuilding) {
            html += `</div></div>`;
        }
    });

    container.innerHTML = html;
}

function bldgPmToggleBuilding(buildingId, checked) {
    if (checked) {
        _bldgPm.selectedBuildings.add(buildingId);
        _bldgPmFetchFloors(buildingId);
    } else {
        _bldgPm.selectedBuildings.delete(buildingId);
    }
    _bldgPmRenderFloors();
}

function bldgPmToggleFloor(buildingId, floorId, checked) {
    if (!_bldgPm.selectedFloors[buildingId]) {
        _bldgPm.selectedFloors[buildingId] = new Set();
    }
    if (checked) {
        _bldgPm.selectedFloors[buildingId].add(floorId);
    } else {
        _bldgPm.selectedFloors[buildingId].delete(floorId);
    }
}

function bldgPmToggleAllBuildings(checked) {
    if (checked) {
        _dashBuildingsData.forEach((b) => {
            _bldgPm.selectedBuildings.add(b.id);
            _bldgPmFetchFloors(b.id);
        });
    } else {
        _bldgPm.selectedBuildings.clear();
    }
    _bldgPmRenderBuildings();
    _bldgPmRenderFloors();
}

function bldgPmGroupToggleFloors(buildingId, checked) {
    const floors = _bldgPm.floorCache[buildingId] || [];
    if (!_bldgPm.selectedFloors[buildingId]) {
        _bldgPm.selectedFloors[buildingId] = new Set();
    }
    if (checked) {
        floors.forEach((f) => _bldgPm.selectedFloors[buildingId].add(f.id));
    } else {
        _bldgPm.selectedFloors[buildingId].clear();
    }
    _bldgPmRenderFloors();
}

function bldgPmToggleGroup(buildingId) {
    const groupEl = document.getElementById(`bldg-pm-group-${buildingId}`);
    if (groupEl) groupEl.classList.toggle('is-collapsed');
}

function executeBldgPrint() {
    const printArea = document.getElementById('dash-buildings-print-area');
    if (!printArea) return;

    const includeFloorDetail = document.getElementById('bldg-opt-floor-detail')?.checked ?? true;
    const includeSummary     = document.getElementById('bldg-opt-summary')?.checked ?? true;
    const showDate           = document.getElementById('bldg-opt-date')?.checked ?? true;
    const pageBreaks         = document.getElementById('bldg-opt-breaks')?.checked ?? true;

    const dateStr = new Date().toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const esc     = _bldgPmEsc;

    const selectedBldgs = _dashBuildingsData.filter((b) => _bldgPm.selectedBuildings.has(b.id));

    if (selectedBldgs.length === 0) {
        Components.alert('Please select at least one building to print.', 'warning');
        return;
    }

    let sectionsHtml = '';

    selectedBldgs.forEach((b, idx) => {
        const isLast   = idx === selectedBldgs.length - 1;
        const addBreak = pageBreaks && !isLast;
        const floors   = (_bldgPm.floorCache[b.id] || []).filter((f) => _bldgPm.selectedFloors[b.id]?.has(f.id));

        let sec = `<div class="bldg-ps${addBreak ? ' bldg-ps-break' : ''}">`;
        sec += `<div class="bldg-ps-name">${esc(b.name)}</div>`;

        if (includeSummary) {
            const totalFloorCount = b.floor_count || 0;
            const floorLabel = floors.length !== totalFloorCount
                ? `${floors.length} of ${totalFloorCount}` : String(totalFloorCount);
            const totalRooms = floors.reduce((s, f) => s + Number(f.room_count||0), 0);
            sec += `<div class="bldg-ps-summary">${floorLabel} floor${Number(totalFloorCount) !== 1 ? 's' : ''} · ${totalRooms} room${totalRooms !== 1 ? 's' : ''}</div>`;
        }

        if (includeFloorDetail) {
            if (floors.length === 0) {
                sec += `<p class="bldg-ps-no-floors">No floors selected for this building.</p>`;
            } else {
                sec += `<table class="bldg-print-table"><thead><tr><th>Floor</th><th>Rooms</th><th>Items</th></tr></thead><tbody>`;
                floors.forEach((f, fi) => {
                    sec += `<tr class="${fi % 2 === 0 ? 'row-even' : 'row-odd'}">
                        <td>${esc(f.name)}</td>
                        <td style="text-align:center;">${Number(f.room_count||0)}</td>
                        <td style="text-align:center;">${Number(f.item_count||0)}</td>
                    </tr>`;
                });
                sec += `</tbody></table>`;
            }
        }

        sec += `</div>`;
        sectionsHtml += sec;
    });

    const grandFloors = selectedBldgs.reduce((s, b) => {
        return s + (_bldgPm.floorCache[b.id] || []).filter((f) => _bldgPm.selectedFloors[b.id]?.has(f.id)).length;
    }, 0);
    const grandRooms = selectedBldgs.reduce((s, b) => {
        const fl = (_bldgPm.floorCache[b.id] || []).filter((f) => _bldgPm.selectedFloors[b.id]?.has(f.id));
        return s + fl.reduce((fs, f) => fs + Number(f.room_count||0), 0);
    }, 0);

    printArea.innerHTML = `
        <div class="bldg-print-wrap">
            <div class="bldg-print-header">
                <div class="bldg-print-school">PHILCST Centralized School Facility Maintenance Report Management System</div>
                <div class="bldg-print-title">Buildings Overview Report</div>
                ${showDate ? `<div class="bldg-print-date">Generated: ${esc(dateStr)}</div>` : ''}
            </div>
            <hr class="bldg-print-divider">
            <div class="bldg-print-summary">
                Buildings: <strong>${selectedBldgs.length}</strong>
                &nbsp;&nbsp;·&nbsp;&nbsp;
                Floors: <strong>${grandFloors}</strong>
                &nbsp;&nbsp;·&nbsp;&nbsp;
                Rooms: <strong>${grandRooms}</strong>
            </div>
            ${sectionsHtml}
            <div class="bldg-print-footer">SFMS · Buildings Overview · ${esc(dateStr)}</div>
        </div>`;

    closeBldgPrintModal();

    document.body.classList.add('dash-buildings-print-mode');
    const cleanup = () => {
        document.body.classList.remove('dash-buildings-print-mode');
        window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);

    setTimeout(() => window.print(), 50);
}

// Populate stats and chart
async function initDashboard() {
    try {
        loadDashboardMonthSelection();
        const stats      = await fetchDashboardStats();
        const chartStats = await fetchMonthlyChartStats(selectedYear, selectedMonth);

        // Charts always show the selected month only — no all-time fallback.
        // When the month has no reports the chart renders an empty ring,
        // which is correct ("no reports this month").
        // Stat cards are populated from `stats` (all-time) below and are unaffected.
        const effectiveChartStats = chartStats;

        // Update chart month badge labels
        const monthLabel = new Date(selectedYear, selectedMonth - 1, 1)
                .toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const statusMonthEl   = document.getElementById('status-chart-month');
        const priorityMonthEl = document.getElementById('priority-chart-month');
        if (statusMonthEl)   statusMonthEl.textContent   = monthLabel;
        if (priorityMonthEl) priorityMonthEl.textContent = monthLabel;

        // Fill stats cards with real data
        const total      = stats.total       || 0;
        const todayCount = stats.reports_today || 0;
        const submitted  = stats.submitted   || 0;
        const assigned   = stats.assigned    || 0;
        // TASK 5 — semester-scoped pending (see fetchDashboardStats()). Not
        // `|| 0`-coerced: null means "no active semester" and is handled by
        // the semesterActive branch below, same as total/inProgress/completed.
        const pending    = stats.pending;
        const inProgress = stats.in_progress || 0;
        const completed  = stats.completed   || 0;
        const closed     = stats.closed      || 0;
        const cancelled  = stats.cancelled   || 0;
        const priorityLow = stats.by_priority?.low || 0;
        const priorityMedium = stats.by_priority?.medium || 0;
        const priorityHigh = stats.by_priority?.high || 0;
        const priorityCritical = stats.by_priority?.critical || 0;
        const isLightMode = document.documentElement.getAttribute('data-theme-resolved') === 'light';
        const chartPrimaryText = isLightMode ? '#111827' : '#f8fafc';
        const chartMutedText = isLightMode ? '#374151' : '#94a3b8';
        const chartGridY = isLightMode ? 'rgba(17, 24, 39, 0.12)' : 'rgba(148, 163, 184, 0.35)';
        const chartGridX = isLightMode ? 'rgba(17, 24, 39, 0.08)' : 'rgba(148, 163, 184, 0.25)';
        const doughnutBorder = isLightMode ? '#ffffff' : '#0f172a';

        // TASK 25.2 — Enterprise Semester Lifecycle: the 4 semester-scoped
        // KPI cards (Total reports, Pending tasks, In progress, Completed)
        // must never show a number scoped to a semester that isn't actually
        // running. When there is no active semester (Upcoming First
        // Semester / Semester Break / School Year Completed) they show
        // "No Active Semester" instead — "Reports today" and "Low stock"
        // are untouched (never semester-scoped in the first place).
        const semesterActive = stats.semester_active !== false;
        const totalEl       = document.getElementById('stat-total');
        const totalDescEl   = document.getElementById('stat-total-desc');
        const pendingEl     = document.getElementById('stat-pending');
        const pendingDescEl = document.getElementById('stat-pending-desc');
        const inProgressEl     = document.getElementById('stat-in-progress');
        const inProgressDescEl = document.getElementById('stat-in-progress-desc');
        const completedEl     = document.getElementById('stat-completed');
        const completedDescEl = document.getElementById('stat-completed-desc');

        if (semesterActive) {
            totalEl.textContent = total;
            if (totalDescEl) totalDescEl.textContent = 'this semester';
            // TASK 5 — was `submitted + assigned` (all-time, from the report
            // list). Now the semester-scoped `pending` from /api/dashboard/stats,
            // matching the Total / In Progress / Completed cards beside it and
            // Buildings Overview's "Pending Requests". See fetchDashboardStats().
            if (pendingEl) pendingEl.textContent = pending;
            if (pendingDescEl) pendingDescEl.textContent = 'this semester';
            if (inProgressEl) inProgressEl.textContent = inProgress;
            if (inProgressDescEl) inProgressDescEl.textContent = 'on track';
            if (completedEl) completedEl.textContent = completed;
            if (completedDescEl) completedDescEl.textContent = 'this semester';

            // TASK 31.10 — real trend badges (see computeCountTrend()/
            // renderKpiTrend() above), computed from the raw report rows
            // fetchDashboardStats() already fetched (stats._reports). Only
            // applied when there is an active semester (these 4 cards only
            // show a real number in the first place when one is), and only
            // when a non-zero previous-period baseline exists — otherwise
            // the static caption text set just above (e.g. "this semester")
            // is left as-is. Nothing here is fabricated: every trend is a
            // literal count of real reports in two adjacent real date windows.
            const rawReports = stats._reports || [];
            const totalTrend = computeCountTrend(rawReports, null, 30);
            renderKpiTrend(totalDescEl, totalTrend, true, 'last 30 days');

            const pendingTrend = computeCountTrend(rawReports, (r) => {
                const s = (r.status || '').toLowerCase();
                return s === '' || s === 'submitted' || s === 'assigned';
            }, 7);
            // More new pending reports than the prior week is not a "good"
            // trend, so positiveIsGood is false here (unlike the others).
            renderKpiTrend(pendingDescEl, pendingTrend, false, 'last 7 days');

            const inProgressTrend = computeCountTrend(rawReports, (r) =>
                (r.status || '').toLowerCase() === 'in_progress', 7);
            renderKpiTrend(inProgressDescEl, inProgressTrend, true, 'last 7 days');

            const completedTrend = computeCountTrend(rawReports, (r) =>
                (r.status || '').toLowerCase() === 'completed', 30);
            renderKpiTrend(completedDescEl, completedTrend, true, 'last 30 days');
        } else {
            totalEl.textContent = '—';
            if (totalDescEl) totalDescEl.textContent = 'No Active Semester';
            if (pendingEl) pendingEl.textContent = '—';
            if (pendingDescEl) pendingDescEl.textContent = 'No Active Semester';
            if (inProgressEl) inProgressEl.textContent = '—';
            if (inProgressDescEl) inProgressDescEl.textContent = 'No Active Semester';
            if (completedEl) completedEl.textContent = '—';
            if (completedDescEl) completedDescEl.textContent = 'No Active Semester';
        }

        document.getElementById('stat-today').textContent = todayCount;
        const lowEl = document.getElementById('stat-low');
        lowEl.textContent = (stats.low_stock !== undefined) ? stats.low_stock : '—';

        // TASK 31.10 — "Reports today" is never semester-gated (see the
        // existing comment above), so its trend is computed unconditionally:
        // today's report count vs yesterday's, both real day-over-day totals
        // from the same raw report rows.
        const todayDescEl = document.getElementById('stat-today-desc');
        const todayTrend = computeCountTrend(stats._reports || [], null, 1);
        renderKpiTrend(todayDescEl, todayTrend, true, 'yesterday');

        // TASK 31.10 — now passes the raw report rows (stats._reports, the
        // same array already reused for the KPI trend badges above) instead
        // of aggregate stage counts, so the timeline can render 5 real dated
        // events instead of 5 stage totals (see renderActivityTimeline()).
        // Not gated by semesterActive, same reasoning as before: this is an
        // all-time recent-activity view, not a semester-scoped statistic.
        if (typeof renderActivityTimeline === 'function') {
            renderActivityTimeline(stats._reports || []);
        }

        // ISS-01 — run AFTER the KPI cards, the chart stats and the timeline
        // are all resolved, so the notice describes exactly what is on screen.
        // It reads only `stats` / `effectiveChartStats` / `monthLabel` that
        // already exist here and renders nothing unless a zero and real
        // reports genuinely coexist. See renderDashboardScopeNotice().
        if (typeof renderDashboardScopeNotice === 'function') {
            renderDashboardScopeNotice(stats, effectiveChartStats, monthLabel);
        }

        if (typeof renderSchoolSettingsLabel === 'function') {
            renderSchoolSettingsLabel(
                stats.school_year,
                stats.current_semester,
                stats.first_sem_start,
                stats.first_sem_end,
                stats.second_sem_start,
                stats.second_sem_end,
                stats.semester_status,
                stats.semester_days_until_start
            );
        }
        _lastDashboardStats = effectiveChartStats;
        // TASK 31.4 — mark this successful fetch as the freshness baseline and
        // paint the caption immediately (the interval below only keeps it aging).
        _statusChartUpdatedAt = Date.now();
        updateStatusChartFreshness();

        // Render chart separately so card values do not fail if chart has issues.
        console.log('[Dashboard] Chart data:', {
            submitted:   effectiveChartStats.submitted,
            assigned:    effectiveChartStats.assigned,
            in_progress: effectiveChartStats.in_progress,
            completed:   effectiveChartStats.completed,
            total:       effectiveChartStats.total,
            by_priority: effectiveChartStats.by_priority,
        });
        try {
            if (typeof Chart !== 'undefined') {
                const statusCanvas = document.getElementById('reportsStatusChart');
                if (statusCanvas) {
                    const statusCtx = statusCanvas.getContext('2d');
                    if (reportsStatusChart) reportsStatusChart.destroy();

                    const statusCenterTextPlugin = {
                        id: 'statusCenterTextPlugin',
                        afterDatasetsDraw(chart) {
                            const meta = chart.getDatasetMeta(0);
                            if (!meta || !meta.data || !meta.data.length) {
                                return;
                            }
                            const point = meta.data[0];
                            const x = point.x;
                            const y = point.y;
                            const ctx = chart.ctx;
                            // Dynamic color for center text
                            const isLightMode = document.documentElement.getAttribute('data-theme-resolved') === 'light';
                            const centerTextColor = isLightMode ? '#111827' : '#fff';
                            const centerMutedColor = isLightMode ? '#374151' : '#cbd5e1';
                            ctx.save();
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.font = '700 28px "Segoe UI", sans-serif';
                            ctx.fillStyle = centerTextColor;
                            ctx.fillText(String(getVisibleStatusTotal(chart)), x, y - 7);
                            ctx.font = '500 13px "Segoe UI", sans-serif';
                            ctx.fillStyle = centerMutedColor;
                            ctx.fillText('total', x, y + 15);
                            ctx.restore();
                        }
                    };

                    const statusChartPlugins = [statusCenterTextPlugin];
                    if (typeof ChartDataLabels !== 'undefined') {
                        statusChartPlugins.push(ChartDataLabels);
                    }

                    // TASK 31.4 — same submitted/inProgress/completed counts already
                    // being fed into the chart's own data array below, pulled into
                    // named consts so the legend labels can show the identical %s.
                    const chartSubmitted  = (effectiveChartStats.submitted || 0) + (effectiveChartStats.assigned || 0);
                    const chartInProgress = effectiveChartStats.in_progress || 0;
                    const chartCompleted  = effectiveChartStats.completed || 0;

                    reportsStatusChart = new Chart(statusCtx, {
                        type: 'doughnut',
                        plugins: statusChartPlugins,
                        data: {
                            labels: buildStatusLegendLabels(chartSubmitted, chartInProgress, chartCompleted),
                            datasets: [{
                                data: [chartSubmitted, chartInProgress, chartCompleted],
                                backgroundColor: ['#3b82f6', '#f59e0b', '#10b981'],
                                borderColor: doughnutBorder,
                                borderWidth: 2,
                                hoverOffset: 3,
                                spacing: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '68%',
                            radiusScale: 1.15,
                            onClick: (e, items) => {
                                if (items.length > 0) {
                                    e.stopPropagation();
                                    const idx = items[0].index;
                                    const statuses = ['submitted', 'in_progress', 'completed'];
                                    window.location.href = window.SFMS_PUBLIC_URL('/frontend/pages/reports.php?status=' + statuses[idx]);
                                }
                            },
                            plugins: {
                                datalabels: {
                                    display: (context) => Number(context.dataset.data[context.dataIndex] || 0) > 0,
                                    formatter: (value) => String(value),
                                    color: '#ffffff',
                                    font: {
                                        weight: '700',
                                        size: 13
                                    },
                                    anchor: 'center',
                                    align: 'center'
                                },
                                legend: {
                                    /* TASK 31.12 — was 'right'; see the matching comment on the
                                       mock-path chart config above for the full explanation. */
                                    position: 'none',
                                    align: 'center',
                                    labels: {
                                        color: chartMutedText,
                                        usePointStyle: true,
                                        pointStyle: 'circle',
                                        boxWidth: 10,
                                        boxHeight: 10,
                                        padding: 16,
                                        font: {
                                            /* TASK 31.10 — 14 -> 12, see buildStatusLegendLabels() comment */
                                            size: 12,
                                            weight: '600'
                                        }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: context => ` ${context.formattedValue} report${Number(context.formattedValue) !== 1 ? 's' : ''}`
                                    }
                                }
                            }
                        }
                    });
                    _addDoughnutHoverCursor(statusCanvas, () => reportsStatusChart);
                    renderStatusLegendHtml(chartSubmitted, chartInProgress, chartCompleted);
                }

                // TASK 31.15 — was a chart-lite.js bar chart on <canvas id=
                // "reportsPriorityChart">. Replaced by the page-scoped SVG stream
                // chart; see renderPriorityFlowChart()'s comment (above,
                // renderPriorityLegendHtml() definition) for why chart-lite.js
                // itself couldn't produce this chart type. Same real
                // effectiveChartStats.by_priority counts, no new data.
                renderPriorityFlowChart(
                    effectiveChartStats.by_priority?.low      || 0,
                    effectiveChartStats.by_priority?.medium   || 0,
                    effectiveChartStats.by_priority?.high     || 0,
                    effectiveChartStats.by_priority?.critical || 0
                );
                renderPriorityLegendHtml(
                    effectiveChartStats.by_priority?.low      || 0,
                    effectiveChartStats.by_priority?.medium   || 0,
                    effectiveChartStats.by_priority?.high     || 0,
                    effectiveChartStats.by_priority?.critical || 0
                );
                renderPriorityTotalHtml(
                    effectiveChartStats.by_priority?.low      || 0,
                    effectiveChartStats.by_priority?.medium   || 0,
                    effectiveChartStats.by_priority?.high     || 0,
                    effectiveChartStats.by_priority?.critical || 0
                );
                renderPriorityInsightHtml(
                    effectiveChartStats.by_priority?.low      || 0,
                    effectiveChartStats.by_priority?.medium   || 0,
                    effectiveChartStats.by_priority?.high     || 0,
                    effectiveChartStats.by_priority?.critical || 0
                );
            }
            console.log('[Dashboard] Status chart canvas:', document.getElementById('reportsStatusChart'));
            console.log('[Dashboard] Priority flow chart container:', document.getElementById('reportsPriorityFlowChart'));
        } catch (chartErr) {
            console.error('Chart render error:', chartErr);
        }

    } catch (err) {
        console.error('Error initializing dashboard:', err);
        document.getElementById('stat-total').textContent = '0';
        const todayEl = document.getElementById('stat-today');
        if (todayEl) todayEl.textContent = '0';
        // ISS-01 — initDashboard() re-runs on month change, so clear any
        // note from a previous successful run rather than leaving text
        // that describes data this failed run never loaded.
        // DASHBOARD CLEANUP §2 — was a 2-id list; the 'dashboard-scope-notice'
        // banner no longer exists, so only the donut's caption is reset.
        const staleNoteEl = document.getElementById('status-chart-empty-note');
        if (staleNoteEl) { staleNoteEl.hidden = true; staleNoteEl.innerHTML = ''; }
    }

    console.log('[initDashboard] completed — effectiveChartStats:', _lastDashboardStats);

    await Promise.all([
        renderRecentActivity(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ISS-01 — Dashboard scope reconciliation.
//
// ROOT CAUSE this addresses (all three scopes verified, none of them changed):
//
//   • The 5 hero KPI cards read /api/dashboard/stats, which scopes
//     total/pending/in_progress/completed to `created_at >=
//     semester_started_at` (DashboardController::stats(), TASK 16/25.2).
//   • "Reports by Status" / "Reports by Priority" read
//     fetchMonthlyChartStats(), which scopes to the SELECTED MONTH only
//     (TASK 5) — a month restored from localStorage, not necessarily today's.
//   • The Maintenance Activity Timeline is deliberately all-time recent
//     activity (TASK 31.10) and is not scoped at all.
//
// Each component was individually truthful, but when a scope legitimately
// held no reports the page showed "0", "0 total" and "0 reports" directly
// above a timeline listing real reports, with nothing reconciling them.
//
// DASHBOARD CLEANUP §2 — this function used to write TWO things: a full-width
// zero-explaining banner under the KPI strip, and a one-line
// caption under the Reports by Status donut. The banner was removed as part
// of the Dashboard shortening pass; only the caption remains, so the function
// is now much smaller than the essay above it implies. The scope reasoning is
// kept for the next reader because the underlying three-scope situation it
// documents is still exactly true — it is just no longer spelled out on
// screen in banner form. The KPI cards' own "this semester" chips carry the
// semester half of that message in place.
//
// This function does NOT change any metric's meaning, value, or data source.
// It only states, in words, the scope that produced a zero and confirms that
// the reports visible elsewhere on the page are real. It renders nothing at
// all unless a zero and real reports genuinely coexist, so a normally
// populated Dashboard is completely unaffected.
//
// Every number and date it prints comes from data already fetched by
// initDashboard() — no extra request, no re-derived boundary, no estimate.
//
// SCOPE NOTE (per the task brief): this does not touch ISS-05. The Dashboard
// still has no on-page month control and still inherits its month from
// localStorage via loadDashboardMonthSelection() — this notice merely makes
// the consequence of that inherited month visible instead of silent.
function renderDashboardScopeNotice(stats, chartStats, monthLabel) {
    const chartNoteEl = document.getElementById('status-chart-empty-note');

    const hide = (el) => { if (el) { el.hidden = true; el.innerHTML = ''; } };

    const allReports     = (stats && stats._reports) || [];
    const allTimeCount   = allReports.length;
    const semesterActive = !!stats && stats.semester_active !== false;
    // `null` only ever means "no active semester", which the KPI cards
    // already explain with their own "No Active Semester" text — nothing to
    // reconcile in that case.
    const semesterTotal  = (stats && stats.total !== null && stats.total !== undefined)
        ? Number(stats.total) : null;
    const monthTotal     = Number((chartStats && chartStats.total) || 0);

    // Nothing to reconcile when the system genuinely has no reports at all
    // (the timeline shows its own "No recent activity." empty state, so the
    // zeros are not contradicted by anything), or when no semester is running.
    if (!allTimeCount || !semesterActive || semesterTotal === null) {
        hide(chartNoteEl);
        return;
    }

    const semesterEmpty = semesterTotal === 0;
    const monthEmpty    = monthTotal === 0;

    if (!semesterEmpty && !monthEmpty) {
        hide(chartNoteEl);
        return;
    }

    // DASHBOARD CLEANUP §2 — everything that built the "Why these counters
    // read 0" banner was removed here: the `lines` array (semester sentence,
    // chart-scope sentence, "All Reports" closing sentence), the esc() helper
    // and formatIsoDate() that only it used, the reportsUrl it linked to, and
    // the innerHTML block that painted the icon + title + paragraphs.
    //
    // The two guard clauses ABOVE are deliberately left exactly as they were,
    // so this caption still appears in precisely the same circumstances it
    // did before — §11 rules out changing Reports by Status' behaviour, and
    // loosening a condition here would have done that as a side effect.
    // `semesterEmpty` is therefore still read, by that second guard.
    if (chartNoteEl) {
        if (monthEmpty) {
            const plural = allTimeCount === 1 ? 'report' : 'reports';
            chartNoteEl.textContent =
                'No reports were submitted in ' + (monthLabel || 'the selected month')
                + '. The 0 above counts that month only — the system has '
                + allTimeCount + ' ' + plural + ' in total.';
            chartNoteEl.hidden = false;
        } else {
            hide(chartNoteEl);
        }
    }
}

// TASK 31.10 — redesigned from a 5-stage aggregate-count stepper to 5
// individual REAL dated events, per the reference composition. `reports` is
// the exact same raw report-row array already fetched by
// fetchDashboardStats() and reused for the KPI trend badges (stats._reports,
// see fetchDashboardStats()/computeCountTrend() above) — zero new network
// calls, zero fabricated data. Each event shows that report's real title,
// real status, and real created_at timestamp; if there is no report data at
// all, an explicit empty state is shown rather than any placeholder/fake row.
function renderActivityTimeline(reports) {
    const track = document.getElementById('dashboardActivityTimeline');
    if (!track) return;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    // Page-local absolute-time formatter for the timeline ("Mon D, h:mm AM/PM"),
    // matching the reference mockup's timeline timestamps. Unlike
    // renderRecentActivity()'s relative "Xm/h/d ago" copy, the timeline always
    // shows the same absolute format regardless of recency — same underlying
    // report.created_at data, just formatted differently for this component.
    const formatWhen = (isoString) => {
        const then = new Date(isoString || '');
        if (Number.isNaN(then.getTime())) return '';
        return then.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
            + ', '
            + then.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    };

    const statusMeta = {
        submitted:   { label: 'Submitted',   icon: 'file',    cls: 'status-submitted'    },
        assigned:    { label: 'Assigned',    icon: 'user',    cls: 'status-assigned'     },
        in_progress: { label: 'In Progress', icon: 'clock',   cls: 'status-in-progress'  },
        completed:   { label: 'Resolved',    icon: 'check',   cls: 'status-completed'    },
        closed:      { label: 'Closed',      icon: 'archive', cls: 'status-completed'    },
        cancelled:   { label: 'Cancelled',   icon: 'archive', cls: 'status-cancelled'    }
    };

    const icons = {
        file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path>',
        user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        clock: '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v6l4 2"></path>',
        check: '<path d="M20 6L9 17l-5-5"></path>',
        archive: '<rect x="3" y="4" width="18" height="4" rx="1"></rect><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"></path><path d="M10 13h4"></path>'
    };

    const events = (Array.isArray(reports) ? reports.slice() : [])
        .sort((a, b) => new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime())
        .slice(0, 5);

    if (events.length === 0) {
        track.innerHTML = '<div class="activity-timeline-empty">No recent activity.</div>';
        return;
    }

    track.innerHTML = events.map((report, index) => {
        const status = (report.status || 'submitted').toLowerCase();
        const meta = statusMeta[status] || statusMeta.submitted;
        const isLast = index === events.length - 1;
        const title = report.title || 'Untitled report';
        return `
            <div class="activity-timeline-step">
                <div class="activity-timeline-node ${meta.cls}" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${icons[meta.icon]}</svg>
                </div>
                ${isLast ? '' : '<div class="activity-timeline-connector" aria-hidden="true"></div>'}
                <div class="activity-timeline-label">${escapeHtml(meta.label)}</div>
                <div class="activity-timeline-desc" title="${escapeHtml(title)}">${escapeHtml(title)}</div>
                <div class="activity-timeline-time">${escapeHtml(formatWhen(report.created_at))}</div>
            </div>
        `;
    }).join('');
}

async function renderRecentActivity() {
    const list = document.getElementById('recentActivity');
    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/reports/recent?limit=20'), {
            credentials: 'include'
        });
        const data = await response.json();
        
        if (!data.success) {
            // TASK 5 — this is the request-failed branch, not an empty-data
            // branch; saying "No recent reports." reported a failure as a
            // legitimate zero. Wording also aligned to the card's real
            // today-scope (see the <h2> comment above).
            list.innerHTML = '<div class="recent-report-list"><div class="recent-reports-empty">Could not load today\'s reports.</div></div>';
            return;
        }

        const reports = extractReportsFromResponse(data);

        if (reports.length === 0) {
            list.innerHTML = '<div class="recent-report-list"><div class="recent-reports-empty">No reports today.</div></div>';
            return;
        }

        const statusClassMap = {
            submitted: 'status-submitted',
            assigned: 'status-assigned',
            in_progress: 'status-in-progress',
            completed: 'status-completed',
            cancelled: 'status-cancelled',
            closed: 'status-closed'
        };

        const formatLabel = (value) => String(value || '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (char) => char.toUpperCase());

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        // TASK 31.3 — page-local relative-time helper. There is no shared
        // main.js utility for this (formatRelativeTime in
        // maintenance-dashboard.php is itself a page-local patch, not an
        // imported helper), so each dashboard that wants "Xm ago" styling
        // defines its own copy rather than introducing a new shared module.
        const formatRelativeTime = (isoString) => {
            const then = new Date(isoString).getTime();
            if (Number.isNaN(then)) return '';
            const diffMs = Date.now() - then;
            const minute = 60 * 1000;
            const hour = 60 * minute;
            const day = 24 * hour;
            if (diffMs < minute) return 'just now';
            if (diffMs < hour) return `${Math.floor(diffMs / minute)}m ago`;
            if (diffMs < day) return `${Math.floor(diffMs / hour)}h ago`;
            if (diffMs < 7 * day) return `${Math.floor(diffMs / day)}d ago`;
            return new Date(isoString).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        };

        // Single, consistent report icon for every row — icon *color* carries
        // the status signal (via the reused .status-* classes below), so we
        // don't need a different glyph per status just to convey the same
        // information twice.
        const reportIconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <path d="M14 2v6h6"></path>
        </svg>`;

        let rows = '';
        reports.forEach(report => {
            const status = (report.status || 'submitted').toLowerCase();
            const statusClass = statusClassMap[status] || 'status-submitted';
            const reportTitle = report.title || 'Untitled report';
            const statusLabel = formatLabel(status);
            const reportLocation = report.location || 'No location provided';
            const relativeTime = formatRelativeTime(report.created_at);
            // TASK 31.10 — two-line right-aligned time/status column (reference
            // composition). Same relativeTime/statusLabel values as before —
            // relativeTime just moved out of the combined meta string on the
            // left into its own line on the right, stacked above the existing
            // status badge, instead of being appended to the location text.
            rows += `
                <div class="recent-report-item">
                    <div class="recent-report-item-icon ${statusClass}" aria-hidden="true">${reportIconSvg}</div>
                    <div class="recent-report-item-body">
                        <div class="recent-report-item-title">${escapeHtml(reportTitle)}</div>
                        <div class="recent-report-item-meta">${escapeHtml(reportLocation)}</div>
                    </div>
                    <div class="recent-report-item-side">
                        ${relativeTime ? `<span class="recent-report-item-time">${escapeHtml(relativeTime)}</span>` : ''}
                        <span class="status-badge ${statusClass}">${escapeHtml(statusLabel)}</span>
                    </div>
                </div>
            `;
        });
        list.innerHTML = `<div class="recent-report-list">${rows}</div>`;
    } catch (err) {
        console.error('Failed to load recent reports:', err);
        list.innerHTML = '<div class="recent-report-list"><div class="recent-reports-empty">Could not load today\'s reports.</div></div>';
    }
}

// Load buildings for room dropdown
function loadBuildingsForDropdown() {
    const buildingSelect = document.getElementById('buildingSelect');
    if (!buildingSelect) return;
    const mockBuildings = [
        { id: 1, name: 'Science Wing' },
        { id: 2, name: 'Technology Building' },
        { id: 3, name: 'Administration Block' }
    ];
    
    mockBuildings.forEach(building => {
        const option = document.createElement('option');
        option.value = building.id;
        option.textContent = building.name;
        buildingSelect.appendChild(option);
    });
}

// Add new building
async function addBuilding() {
    const buildingName = document.getElementById('buildingName').value.trim();
    
    if (!buildingName) {
        Components.alert('Please enter a building name', 'warning');
        return;
    }

    try {
        // Add API call here for backend
        console.log('Adding building:', buildingName);
        Components.alert('Building added successfully!', 'success');
        document.getElementById('buildingName').value = '';
    } catch (error) {
        console.error('Error adding building:', error);
        Components.alert('Failed to add building', 'danger');
    }
}

// Add new room
async function addRoom() {
    const buildingId = document.getElementById('buildingSelect').value.trim();
    const roomName = document.getElementById('roomName').value.trim();

    if (!buildingId) {
        Components.alert('Please select a building', 'warning');
        return;
    }

    if (!roomName) {
        Components.alert('Please enter a room name/number', 'warning');
        return;
    }

    try {
        // Add API call here for backend
        console.log('Adding room:', { buildingId, roomName });
        Components.alert('Room added successfully!', 'success');
        document.getElementById('roomName').value = '';
        document.getElementById('buildingSelect').value = '';
    } catch (error) {
        console.error('Error adding room:', error);
        Components.alert('Failed to add room', 'danger');
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Initialize dashboard after api.js is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (typeof updateGlobalHeaderKicker === 'function') {
        updateGlobalHeaderKicker();
    } else {
        updateDashboardKicker();
        setInterval(updateDashboardKicker, 60000);
    }
    // Wait for Chart.js to be available before initializing dashboard
    function waitForChartAndInit() {
        if (typeof Chart !== 'undefined') {
            initDashboard();
        } else {
            setTimeout(waitForChartAndInit, 50);
        }
    }
    waitForChartAndInit();
    // TASK 31.4 — ages the "Updated Xm ago" caption between fetches; does not
    // itself fetch anything.
    setInterval(updateStatusChartFreshness, 60000);

    const root = document.documentElement;
    const themeObserver = new MutationObserver((mutations) => {
        const changedTheme = mutations.some((mutation) => mutation.type === 'attributes' && mutation.attributeName === 'data-theme-resolved');
        if (changedTheme) {
            refreshDashboardChartsForTheme();
        }
    });
    themeObserver.observe(root, { attributes: true, attributeFilter: ['data-theme-resolved'] });

    initializeClickableCards();

    // TASK — Technician Workload. One aggregated request, rendered by the
    // shared widget in assets/js/technician-workload.js (the same file the
    // Head Maintenance dashboard uses), so the two dashboards cannot drift
    // apart in how they present the same numbers. Registered here rather
    // than inside initDashboard() so it does not wait on Chart.js — it draws
    // no chart.
    if (window.TechnicianWorkload) {
        window.TechnicianWorkload.load('technician-workload-container');
    }

    // Dismiss buildings print modal on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const modal = document.getElementById('bldg-print-modal');
            if (modal && !modal.hidden) closeBldgPrintModal();
        }
    });
});
</script>


