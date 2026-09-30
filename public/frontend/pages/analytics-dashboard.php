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

$_anUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? [];
$_anRole = strtolower(trim((string)($_anUser['role'] ?? '')));
if ($_anRole !== 'super_admin' && $_anRole !== 'maintenance_admin' && $_anRole !== 'maintenance_staff') {
    $_anRole = 'maintenance_staff';
}

$pageTitle = 'Analytics - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<style media="print">
    aside, nav, .sidebar, [class*="sidebar"], header { display: none !important; }
    .an-tabs, .an-filter-row, #an-print-btn          { display: none !important; }
    .an-tab-content                                  { display: block !important; page-break-inside: avoid; margin-bottom: 24px; }
    main, .container                                 { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
    .card                                            { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
</style>

<main class="container" style="margin-top:16px;">
    <div class="card" id="an-analytics-card" style="border-left:3px solid var(--primary-color);margin-top:0;">
        <div class="card-header d-flex justify-between align-center" style="border-bottom:0.5px solid var(--border);padding:20px 24px 16px;">
            <div>
                <h2 style="font-size:18px;font-weight:500;margin:0 0 2px;">Analytics</h2>
                <p class="text-muted mb-0" style="font-size:13px;">View maintenance, damage, inventory, semester, and dispatch analytics.</p>
            </div>
        </div>
        <div class="card-body" style="padding:18px 24px 26px;">

            <!-- Tab buttons — one cohesive nav for the four analytics views.
                 Active/inactive state is applied via the .active class in JS
                 (anSwitchTab()) rather than inline styles; see the CSS block
                 below (#an-analytics-card .an-tab-btn) for the visual spec. -->
            <div class="an-tabs">
                <button type="button" class="an-tab-btn active" data-tab="an-tab1">
                    Overview
                </button>
                <button type="button" class="an-tab-btn" data-tab="an-tab2">
                    Damage Analytics
                </button>
                <button type="button" class="an-tab-btn" data-tab="an-tab3">
                    Semester Comparison
                </button>
                <button type="button" class="an-tab-btn" data-tab="an-tab4">
                    <?php /* TASK 13 PHASE 8 — was "Dispatch & Repair". The
                             Repair Report table this tab also carried was
                             removed with the retired /api/analytics/repair-report
                             endpoint; the tab itself stays because its Dispatch
                             Report and Inventory Activity chart are unaffected. */ ?>
                    Dispatch
                </button>
            </div>

            <!-- ─── TAB 1: Overview ──────────────────────────────────────── -->
            <div id="an-tab1" class="an-tab-content">

                <!-- Key Analytics Summary — reuses /api/dashboard/stats (the
                     same unrestricted endpoint the main Dashboard reads; see
                     anLoadSummary()). No new query/endpoint. total_reports/
                     pending/in_progress/completed come back null when no
                     semester is active — shown as "—" plus the note below
                     rather than guessed at. -->
                <p class="an-section-title">Key Analytics Summary</p>
                <div class="an-metric-grid">

                    <!-- Total Reports — purple -->
                    <div class="an-metric-card" style="border:1px solid rgba(109,40,217,0.2);background:linear-gradient(135deg,rgba(109,40,217,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(109,40,217,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6d28d9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Total Reports</div>
                            <div id="an-sum-total" style="font-size:32px;font-weight:700;color:#6d28d9;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">This semester</div>
                        </div>
                    </div>

                    <!-- Pending/Open — amber -->
                    <div class="an-metric-card" style="border:1px solid rgba(217,119,6,0.22);background:linear-gradient(135deg,rgba(217,119,6,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(217,119,6,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Pending / Open</div>
                            <div id="an-sum-pending" style="font-size:32px;font-weight:700;color:#d97706;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Awaiting action</div>
                        </div>
                    </div>

                    <!-- In Progress — blue -->
                    <div class="an-metric-card" style="border:1px solid rgba(29,78,216,0.2);background:linear-gradient(135deg,rgba(29,78,216,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(29,78,216,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">In Progress</div>
                            <div id="an-sum-inprogress" style="font-size:32px;font-weight:700;color:#1d4ed8;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Being worked on</div>
                        </div>
                    </div>

                    <!-- Completed — green -->
                    <div class="an-metric-card" style="border:1px solid rgba(4,120,87,0.2);background:linear-gradient(135deg,rgba(4,120,87,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(4,120,87,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Completed</div>
                            <div id="an-sum-completed" style="font-size:32px;font-weight:700;color:#047857;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Resolved</div>
                        </div>
                    </div>

                </div>
                <p id="an-sum-note" style="font-size:12px;color:var(--muted-text);margin:-18px 0 26px;"></p>

                <p class="an-section-title">Inventory Health</p>
                <div class="an-metric-grid">

                    <!-- Total Items — blue -->
                    <div class="an-metric-card" style="border:1px solid rgba(37,99,235,0.2);background:linear-gradient(135deg,rgba(37,99,235,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(37,99,235,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Total Items</div>
                            <div id="an-h-total" style="font-size:32px;font-weight:700;color:#1d4ed8;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">In inventory</div>
                        </div>
                    </div>

                    <!-- Low Stock — orange -->
                    <div class="an-metric-card" style="border:1px solid rgba(245,158,11,0.25);background:linear-gradient(135deg,rgba(245,158,11,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Low Stock</div>
                            <div id="an-h-lowstock" style="font-size:32px;font-weight:700;color:#d97706;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Need restocking</div>
                        </div>
                    </div>

                    <!-- Out of Stock — red -->
                    <div class="an-metric-card" style="border:1px solid rgba(239,68,68,0.22);background:linear-gradient(135deg,rgba(239,68,68,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(239,68,68,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Out of Stock</div>
                            <div id="an-h-outofstock" style="font-size:32px;font-weight:700;color:#dc2626;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Depleted items</div>
                        </div>
                    </div>

                    <!-- Low Stock % — purple -->
                    <div class="an-metric-card" style="border:1px solid rgba(139,92,246,0.22);background:linear-gradient(135deg,rgba(139,92,246,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(139,92,246,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="19" y1="5" x2="5" y2="19"/>
                                <circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Low Stock %</div>
                            <div id="an-h-pct" style="font-size:32px;font-weight:700;color:#7c3aed;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Stock health</div>
                        </div>
                    </div>

                </div>

                <div class="an-panel">
                    <p class="an-section-title">Items Needing Attention</p>
                    <div id="an-lowstock-container" class="table-responsive">
                        <div class="ui-empty-state"><strong>Loading...</strong></div>
                    </div>
                </div>

            </div>

            <!-- ─── TAB 2: Damage Analytics ──────────────────────────────── -->
            <div id="an-tab2" class="an-tab-content" style="display:none;">

                <div class="an-filter-row"
                     style="display:grid;grid-template-columns:1fr 160px 160px auto;gap:10px;margin-bottom:16px;align-items:end;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">Department</label>
                        <select id="an-dept-filter" class="form-control">
                            <option value="">All Departments</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">Date From</label>
                        <input type="date" id="an-dmg-from" class="form-control">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">Date To</label>
                        <input type="date" id="an-dmg-to" class="form-control">
                    </div>
                    <div>
                        <button type="button" id="an-dmg-apply" class="btn btn-primary" style="width:100%;">Apply</button>
                    </div>
                </div>

                <!-- Damage Summary — pure client-side aggregation of the same
                     `most_damaged` rows rendered in the chart/table below;
                     no additional fetch (see anLoadDamagedItems()). -->
                <p class="an-section-title">Damage Summary</p>
                <div class="an-metric-grid an-metric-grid--compact">

                    <!-- Damaged Item Types — orange -->
                    <div class="an-metric-card" style="border:1px solid rgba(217,119,6,0.22);background:linear-gradient(135deg,rgba(217,119,6,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(217,119,6,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Damaged Item Types</div>
                            <div id="an-dmg-sum-types" style="font-size:32px;font-weight:700;color:#d97706;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Distinct items</div>
                        </div>
                    </div>

                    <!-- Total Damage Events — red -->
                    <div class="an-metric-card" style="border:1px solid rgba(220,38,38,0.22);background:linear-gradient(135deg,rgba(220,38,38,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(220,38,38,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Total Damage Events</div>
                            <div id="an-dmg-sum-count" style="font-size:32px;font-weight:700;color:#dc2626;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">All recorded incidents</div>
                        </div>
                    </div>

                </div>

                <div class="an-panel">
                    <p class="an-section-title">Most Damaged Items</p>
                    <div style="height:280px;margin-bottom:20px;position:relative;">
                        <canvas id="chart-damaged" style="width:100%;height:100%;"></canvas>
                    </div>
                    <div id="an-damaged-container" class="table-responsive">
                        <div class="ui-empty-state"><strong>Loading...</strong></div>
                    </div>
                </div>

                <div class="an-panel">
                    <p class="an-section-title">Inventory Movement by Department</p>
                    <div id="an-deptusage-container" class="table-responsive">
                        <div class="ui-empty-state"><strong>Loading...</strong></div>
                    </div>
                </div>

            </div>

            <!-- ─── TAB 3: Semester Comparison ───────────────────────────── -->
            <div id="an-tab3" class="an-tab-content" style="display:none;">

                <!-- Year selector -->
                <div class="an-sem-toolbar">
                    <label style="font-size:12px;color:var(--muted-text);margin:0;white-space:nowrap;">Academic Year</label>
                    <select id="an-sem-year" class="form-control an-sem-year-select">
                        <!-- Populated by JS -->
                    </select>
                    <button type="button" id="an-sem-apply" class="btn an-sem-apply-btn">Apply</button>
                    <span id="an-sem-year-label" class="an-sem-year-label"></span>
                </div>

                <!-- Comparison Period — built entirely from the semester-detail
                     response already fetched below (school year + both semester
                     date ranges); no additional API calls. -->
                <div id="an-sem-period" class="an-sem-period-card" style="display:none;">
                    <!-- Populated by JS -->
                </div>

                <!-- Summary cards: 4 metrics, each showing S1 vs S2 -->
                <p class="an-sem-section-title">Semester Summary</p>
                <div id="an-sem-cards" class="an-sem-cards-grid">
                    <div class="ui-empty-state" style="grid-column:span 4;"><strong>Loading…</strong></div>
                </div>

                <!-- Semester grouped bar chart -->
                <div class="an-sem-panel">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                        <div>
                            <p style="font-size:14px;font-weight:500;color:var(--text-light);margin:0 0 3px;">Semester Overview Chart</p>
                            <p id="an-sem-chart-subtitle" style="font-size:12px;color:var(--muted-text);margin:0;">Loading semester schedule…</p>
                        </div>
                        <div style="display:flex;gap:14px;align-items:center;flex-shrink:0;padding-top:2px;">
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--muted-text);">
                                <span style="width:8px;height:8px;border-radius:2px;background:#185FA5;display:inline-block;flex-shrink:0;"></span>
                                1st Semester
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--muted-text);">
                                <span style="width:8px;height:8px;border-radius:2px;background:#1D9E75;display:inline-block;flex-shrink:0;"></span>
                                2nd Semester
                            </span>
                        </div>
                    </div>
                    <div style="height:280px;position:relative;">
                        <canvas id="chart-semester" style="width:100%;height:100%;"></canvas>
                    </div>
                </div>

                <!-- Metric comparison table -->
                <div class="an-sem-panel">
                    <p style="font-size:14px;font-weight:500;margin:0 0 2px;">Metric Comparison</p>
                    <p id="an-sem-table-subtitle" style="font-size:12px;color:var(--muted-text);margin:0 0 14px;">Loading semester schedule…</p>
                    <div id="an-sem-table" class="table-responsive">
                        <div class="ui-empty-state"><strong>Loading…</strong></div>
                    </div>
                </div>

                <!-- Department breakdown -->
                <div class="an-sem-panel">
                    <p style="font-size:14px;font-weight:500;margin:0 0 2px;">Department Activity</p>
                    <p style="font-size:12px;color:var(--muted-text);margin:0 0 14px;">Damage reports and dispatches per department, split by semester. Top 20 by total activity.</p>
                    <div id="an-sem-dept" class="table-responsive">
                        <div class="ui-empty-state"><strong>Loading…</strong></div>
                    </div>
                </div>

            </div>

            <!-- ─── TAB 4: Dispatch Reports ──────────────────────────────── -->
            <div id="an-tab4" class="an-tab-content" style="display:none;">

                <div class="an-filter-row"
                     style="display:grid;grid-template-columns:160px 160px auto 1fr;gap:10px;margin-bottom:16px;align-items:end;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">Date From</label>
                        <input type="date" id="an-rpt-from" class="form-control">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">Date To</label>
                        <input type="date" id="an-rpt-to" class="form-control">
                    </div>
                    <div>
                        <button type="button" id="an-rpt-apply" class="btn btn-primary">Apply</button>
                    </div>
                    <div></div>
                </div>

                <!-- TASK 5 — the Date From / Date To filter above applies to
                     the Dispatch Report table below (TASK 13 PHASE 8: the
                     Repair Report table it also used to feed is retired), not
                     to this chart: anLoadMonthlyChart() calls
                     /api/analytics/monthly-comparison with no date params, and
                     the endpoint's window is a fixed rolling 12 months. Rather
                     than rename the title to hide that, the fixed window is
                     stated outright so the filter's scope is unambiguous. The
                     series is now zero-filled server-side, so every one of the
                     12 months is plotted (months with no activity previously
                     vanished from the axis instead of showing 0). -->
                <!-- Dispatch Summary — pure client-side aggregation of the
                     same `dispatches` rows rendered in the table below; no
                     additional fetch (see anLoadDispatchReport()). -->
                <p class="an-section-title">Dispatch Summary</p>
                <div class="an-metric-grid an-metric-grid--compact">

                    <!-- Total Dispatches — purple -->
                    <div class="an-metric-card" style="border:1px solid rgba(109,40,217,0.2);background:linear-gradient(135deg,rgba(109,40,217,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(109,40,217,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6d28d9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Total Dispatches</div>
                            <div id="an-rpt-sum-count" style="font-size:32px;font-weight:700;color:#6d28d9;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">In selected range</div>
                        </div>
                    </div>

                    <!-- Total Items Dispatched — blue -->
                    <div class="an-metric-card" style="border:1px solid rgba(24,95,165,0.22);background:linear-gradient(135deg,rgba(24,95,165,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(24,95,165,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#185FA5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Items Dispatched</div>
                            <div id="an-rpt-sum-items" style="font-size:32px;font-weight:700;color:#185FA5;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Distinct line items</div>
                        </div>
                    </div>

                    <!-- Total Quantity Dispatched — green -->
                    <div class="an-metric-card" style="border:1px solid rgba(4,120,87,0.2);background:linear-gradient(135deg,rgba(4,120,87,0.06) 0%,transparent 60%);">
                        <div style="width:48px;height:48px;border-radius:12px;background:rgba(4,120,87,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-text);text-transform:uppercase;letter-spacing:.6px;font-weight:500;margin-bottom:4px;">Total Quantity</div>
                            <div id="an-rpt-sum-qty" style="font-size:32px;font-weight:700;color:#047857;line-height:1;">—</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">Units dispatched</div>
                        </div>
                    </div>

                </div>

                <div class="an-panel">
                    <p class="an-section-title" style="margin:0 0 4px;">Inventory Activity — Last 12 Months</p>
                    <p style="font-size:12px;color:var(--muted-text);margin:0 0 14px;">Rolling 12-month window — not affected by the date filter above. Units of stock moved per month.</p>
                    <div style="height:280px;position:relative;">
                        <canvas id="chart-monthly" style="width:100%;height:100%;"></canvas>
                    </div>
                </div>

                <!-- TASK 13 PHASE 8 — the "Repair Report" heading and its
                     #an-repair-container table were removed from below the
                     Dispatch Report. They were fed by
                     GET /api/analytics/repair-report, which this task retires.
                     The Dispatch Report is now the only table in this tab. -->
                <div class="an-panel">
                    <p class="an-section-title">Dispatch Report</p>
                    <div id="an-dispatch-container" class="table-responsive">
                        <div class="ui-empty-state"><strong>Loading...</strong></div>
                    </div>
                </div>

            </div>

        </div><!-- /.card-body -->
    </div><!-- /.card -->
</main>

<style>
/* ============================================================
   Analytics Dashboard — UI polish (scoped to #an-analytics-card)
   Spacing / alignment / consistency only. No layout structure,
   routing, permissions, or data/logic changes.
   ============================================================ */

/* ── Tab nav — shared across all four views. Active state is applied
   via the .active class in anSwitchTab() (classList.toggle), not
   inline styles. Purple accent per the PHILCST theme (--primary-color),
   replacing the old one-off blue (#185FA5). overflow-x lets the row
   scroll horizontally on narrow screens instead of wrapping/clipping. */
.an-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 20px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.an-tab-btn {
    appearance: none;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 16px;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--muted-text);
    white-space: nowrap;
    cursor: pointer;
    border-radius: 8px 8px 0 0;
    transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}
.an-tab-btn:hover {
    color: var(--primary-color);
    background: var(--muted-card);
}
.an-tab-btn.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
    font-weight: 600;
}

/* Metric card grids — shared across all four tabs (Overview's Key
   Analytics Summary + Inventory Health, Damage Analytics' Damage
   Summary, Dispatch's Dispatch Summary). auto-fit + minmax makes the
   grid self-responsive without manual breakpoints: cards wrap onto new
   rows once they'd drop below ~200px, and never overflow the card.
   The --compact modifier is for smaller 2-3-card summary rows so they
   don't stretch full-width on wide (1920px) screens. Per-card colors
   stay inline. */
#an-analytics-card .an-metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}
#an-analytics-card .an-metric-grid--compact {
    grid-template-columns: repeat(auto-fit, minmax(200px, 240px));
}
#an-analytics-card .an-metric-card {
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}

/* Section titles — shared heading style for grouped sections within a
   tab (e.g. "Key Analytics Summary", "Damage Summary"). */
#an-analytics-card .an-section-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-light);
    margin: 0 0 10px;
}

/* Shared chart/table panel wrapper — visually matches Tab 3's existing
   .an-sem-panel (kept separate/untouched there) so all four tabs read
   as one consistent card system. Metric-card grids stay unwrapped by
   design (matches the existing Tab 3 convention); only chart/table
   sections get this bordered panel. */
#an-analytics-card .an-panel {
    background: var(--card-color);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 20px;
}
#an-analytics-card .an-panel:last-child { margin-bottom: 0; }

/* Filter rows (Damage Analytics + Dispatch tabs — the latter was the
   "Dispatch & Repair" tab before TASK 13 PHASE 8): outside of
   .form-group, .form-control has no height/padding of its own, so the
   date inputs / department select previously rendered at browser-default
   height while the Apply button used the design system's button height.
   Giving every field the same explicit height fixes the misalignment. */
#an-analytics-card .an-filter-row .form-control,
#an-analytics-card .an-filter-row .btn {
    height: 40px;
    box-sizing: border-box;
}
#an-analytics-card .an-filter-row .form-control {
    width: 100%;
    padding: 0 12px;
    border-radius: 10px;
}

/* ── Tab row hover ───────────────────────────────────────────────────── */
.an-tab-content .table tbody tr { transition: background 0.12s; }
.an-tab-content .table tbody tr:hover { background: var(--muted-card); }
/* ── No border on last table row ─────────────────────────────────────── */
#an-sem-table .table tbody tr:last-child td,
#an-sem-dept  .table tbody tr:last-child td  { border-bottom: none; }

/* ── Responsive: keep filter rows from overflowing on tablet/mobile
   (the grid uses a fixed inline column track). Metric grids above are
   already self-responsive via auto-fit and need no breakpoint here. ── */
@media (max-width: 900px) {
    #an-analytics-card .an-filter-row { grid-template-columns: 1fr 1fr !important; }
}
@media (max-width: 560px) {
    #an-analytics-card .an-filter-row { grid-template-columns: 1fr !important; }
}

/* ============================================================
   Semester Comparison (Tab 3) — visual polish only, scoped to
   #an-tab3. No selector below is referenced by JS for behavior;
   anLoadSemesterDetail() only reads/writes element IDs (unchanged:
   an-sem-year, an-sem-apply, an-sem-year-label, an-sem-period,
   an-sem-cards, chart-semester, an-sem-chart-subtitle,
   an-sem-table-subtitle, an-sem-table, an-sem-dept) and now also
   assigns the class names introduced here to build each card/pill,
   which is purely presentational.
   ============================================================ */
#an-tab3 .an-sem-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
#an-tab3 .an-sem-year-select {
    width: 150px;
    font-size: 13px;
    border-radius: 8px;
    border: 1px solid var(--border);
    padding: 6px 10px;
}
#an-tab3 .an-sem-apply-btn {
    background: var(--primary-color);
    color: #fff;
    border: none;
    font-size: 13px;
    font-weight: 500;
    border-radius: 8px;
    padding: 7px 16px;
    cursor: pointer;
    transition: background 0.15s ease;
}
#an-tab3 .an-sem-apply-btn:hover { background: var(--primary-dark); }
#an-tab3 .an-sem-year-label { font-size: 13px; color: var(--muted-text); }

/* Comparison Period — real config data (school year + both semester
   ranges), assembled client-side from the same payload the cards/
   chart/table below already use. No new data or endpoints. */
#an-tab3 .an-sem-period-card {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 32px;
    background: var(--muted-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 20px;
}
#an-tab3 .an-sem-period-item { display: flex; flex-direction: column; gap: 2px; }
#an-tab3 .an-sem-period-label {
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted-text);
}
#an-tab3 .an-sem-period-value { font-size: 13px; font-weight: 500; color: var(--text-light); }

#an-tab3 .an-sem-section-title { font-size: 14px; font-weight: 500; margin: 0 0 10px; }

/* TASK 13 PHASE 8 (Repair retirement) — this grid was repeat(4, 1fr) for
   the four semester metric cards. AnalyticsService::semesterDetail() no
   longer returns a `repairs` key, so the Repair Requests card is gone and
   the grid is now 3-up. This is the "smallest safe adjustment" the brief
   calls for: without it the three surviving cards would stretch across a
   4-column track and leave a dead cell. */
#an-tab3 .an-sem-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}

/* Per-metric color identity (matches Metric Comparison table + chart
   legend semantics): Maintenance Reports = purple, Dispatches = blue,
   Damage Reports = orange. Accent border/icon only — the S1/S2 values,
   arrow, and Year Total are unchanged. */
#an-tab3 .an-sem-card {
    border-radius: 12px;
    padding: 1.25rem 1.1rem;
    border: 1px solid var(--border);
    border-top: 3px solid var(--border);
    background: var(--card-color);
    transition: box-shadow 0.15s ease, transform 0.15s ease;
}
#an-tab3 .an-sem-card:hover { box-shadow: 0 10px 24px rgba(15, 23, 42, 0.10); transform: translateY(-1px); }
#an-tab3 .an-sem-card--maintenance { border-top-color: #8b5cf6; }
#an-tab3 .an-sem-card--dispatches  { border-top-color: #185FA5; }
#an-tab3 .an-sem-card--damage      { border-top-color: #d97706; }
/* TASK 13 PHASE 8 — the .an-sem-card--repairs accent rule was removed here
   (and its matching icon-background rule below). The three surviving
   modifiers are untouched. */

#an-tab3 .an-sem-card-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}
#an-tab3 .an-sem-card--maintenance .an-sem-card-icon { background: rgba(139, 92, 246, 0.14); }
#an-tab3 .an-sem-card--dispatches  .an-sem-card-icon { background: rgba(24, 95, 165, 0.14); }
#an-tab3 .an-sem-card--damage      .an-sem-card-icon { background: rgba(217, 119, 6, 0.14); }

#an-tab3 .an-sem-card-label {
    font-size: 11px;
    color: var(--muted-text);
    text-transform: uppercase;
    letter-spacing: .6px;
    font-weight: 500;
    margin-bottom: 12px;
}
#an-tab3 .an-sem-card-body {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin-bottom: 10px;
}
#an-tab3 .an-sem-card-foot {
    text-align: center;
    font-size: 12px;
    color: var(--muted-text);
    border-top: 1px solid var(--border);
    padding-top: 8px;
}

#an-tab3 .an-sem-panel {
    background: var(--card-color);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 20px;
}
#an-tab3 .an-sem-panel:last-child { margin-bottom: 0; }

/* Department Activity numeric pills — token/rgba-based so they render
   correctly in dark theme too (previously hardcoded light-only hexes
   #E6F1FB/#0C447C, which read as a near-invisible pale wash on the
   dark navy card background). */
#an-tab3 .an-num-pill {
    background: rgba(24, 95, 165, 0.14);
    color: #185FA5;
    border-radius: 99px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 500;
}
:root[data-theme-resolved='dark'] #an-tab3 .an-num-pill {
    background: rgba(96, 165, 250, 0.18);
    color: #93c5fd;
}

@media (max-width: 900px) {
    #an-tab3 .an-sem-cards-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 560px) {
    #an-tab3 .an-sem-cards-grid { grid-template-columns: 1fr 1fr; }
    #an-tab3 .an-sem-period-card { flex-direction: column; align-items: flex-start; gap: 10px; }
    #an-tab3 .an-sem-toolbar { gap: 8px; }
}
</style>

<script>
const AN_API = window.SFMS_PUBLIC_URL
    ? window.SFMS_PUBLIC_URL('/api/analytics')
    : '/api/analytics';

const anLoadedTabs = new Set();

// Chart instances — destroyed and recreated on each data reload
let anChartDamaged  = null;
let anChartSemester = null;
let anChartMonthly  = null;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function anEsc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function anFetch(url, options) {
    const fetcher = window.Components && typeof Components.fetchJson === 'function'
        ? Components.fetchJson
        : async (u, o) => { const r = await fetch(u, o); return { response: r, data: await r.json() }; };
    return fetcher(url, options);
}

function anStockBadge(qty, threshold) {
    qty = Number(qty); threshold = Number(threshold);
    if (qty <= 0)
        return '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:500;">Out of Stock</span>';
    if (qty <= threshold)
        return '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:500;">Low Stock</span>';
    return '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:500;">Available</span>';
}

// TASK 13 PHASE 8 — anRepairBadge() was removed here. It rendered the
// repair_requests.repair_status vocabulary for the Repair Report table's
// Status column, and that table is gone. It had no other caller.

// ---------------------------------------------------------------------------
// Tab switching (lazy-loads each tab on first activation)
// ---------------------------------------------------------------------------

function anSwitchTab(tabId) {
    document.querySelectorAll('.an-tab-content').forEach((el) => { el.style.display = 'none'; });
    document.getElementById(tabId).style.display = 'block';

    document.querySelectorAll('.an-tab-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });

    if (!anLoadedTabs.has(tabId)) {
        anLoadedTabs.add(tabId);
        switch (tabId) {
            case 'an-tab1': anLoadSummary(); anLoadHealth(); anLoadLowStock(); break;
            case 'an-tab2': anLoadTab2Data(); break;
            case 'an-tab3': anLoadSemesterDetail(); break;
            case 'an-tab4': anLoadTab4Data(); break;
        }
    }
}

// ---------------------------------------------------------------------------
// TAB 1: Overview
// ---------------------------------------------------------------------------

async function anLoadHealth() {
    try {
        const { response, data: payload } = await anFetch(
            `${AN_API}/inventory-health`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');
        const d = payload.data;
        document.getElementById('an-h-total').textContent      = d.total_items        ?? '—';
        document.getElementById('an-h-lowstock').textContent   = d.low_stock_count    ?? '—';
        document.getElementById('an-h-outofstock').textContent = d.out_of_stock_count ?? '—';
        document.getElementById('an-h-pct').textContent =
            (d.low_stock_percent != null) ? d.low_stock_percent + '%' : '—';
    } catch (_) {
        // Cards stay as '—' — non-fatal; low-stock table will show its own error
    }
}

async function anLoadLowStock() {
    const container = document.getElementById('an-lowstock-container');
    try {
        const { response, data: payload } = await anFetch(
            `${AN_API}/low-stock`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');
        const items = Array.isArray(payload.data?.items) ? payload.data.items : [];

        if (items.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No low-stock items.</strong><span>All items are at healthy stock levels.</span></div>';
            return;
        }

        let html = '<table class="table"><thead><tr>'
            + '<th>Item Name</th><th>Quantity</th><th>Threshold</th><th>Status</th>'
            + '</tr></thead><tbody>';
        items.forEach((item) => {
            html += '<tr>';
            html += `<td><strong>${anEsc(item.name)}</strong></td>`;
            html += `<td>${anEsc(item.quantity)}</td>`;
            html += `<td>${anEsc(item.threshold)}</td>`;
            html += `<td>${anStockBadge(item.quantity, item.threshold)}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="ui-empty-state"><strong>Failed to load low-stock data.</strong></div>';
    }
}

// Key Analytics Summary — reuses the existing, unrestricted
// /api/dashboard/stats endpoint (same one the main Dashboard page calls;
// no new endpoint/query). total_reports/pending/in_progress/completed
// come back `null` when no semester is currently active — that is shown
// as an explicit note rather than guessed at or left blank/zeroed.
async function anLoadSummary() {
    const noteEl = document.getElementById('an-sum-note');
    try {
        const url = window.SFMS_PUBLIC_URL
            ? window.SFMS_PUBLIC_URL('/api/dashboard/stats')
            : '/api/dashboard/stats';
        const { response, data: payload } = await anFetch(
            url,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');
        const d = payload.data;

        document.getElementById('an-sum-total').textContent      = d.total_reports ?? '—';
        document.getElementById('an-sum-pending').textContent    = d.pending       ?? '—';
        document.getElementById('an-sum-inprogress').textContent = d.in_progress   ?? '—';
        document.getElementById('an-sum-completed').textContent  = d.completed     ?? '—';

        if (noteEl) {
            noteEl.textContent = d.semester_active
                ? ''
                : 'No active semester — report counts will resume once the next semester starts.';
        }
    } catch (_) {
        // Cards stay as '—' — non-fatal; other Overview widgets load independently
        if (noteEl) noteEl.textContent = '';
    }
}

// ---------------------------------------------------------------------------
// Options — populates Tab 2 department dropdown (called once at init)
// ---------------------------------------------------------------------------

async function anLoadOptions() {
    try {
        const { response, data: payload } = await anFetch(
            `${AN_API}/options`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) return;
        const departments = Array.isArray(payload.data?.departments) ? payload.data.departments : [];
        const sel = document.getElementById('an-dept-filter');
        departments.forEach((dept) => {
            const opt = document.createElement('option');
            opt.value       = dept.department_id;
            opt.textContent = dept.name;
            sel.appendChild(opt);
        });
    } catch (_) {
        // Fail silently — dropdown stays at "All Departments"
    }
}

// ---------------------------------------------------------------------------
// TAB 2: Damage Analytics
// ---------------------------------------------------------------------------

function anLoadTab2Data() {
    anLoadDamagedItems();
    anLoadDeptUsage();
}

async function anLoadDamagedItems() {
    const container = document.getElementById('an-damaged-container');
    container.innerHTML = '<div class="ui-empty-state"><strong>Loading...</strong></div>';
    try {
        const params = new URLSearchParams();
        const deptId = document.getElementById('an-dept-filter').value;
        const from   = document.getElementById('an-dmg-from').value;
        const to     = document.getElementById('an-dmg-to').value;
        if (deptId) params.set('department_id', deptId);
        if (from)   params.set('date_from', from);
        if (to)     params.set('date_to', to);

        const { response, data: payload } = await anFetch(
            `${AN_API}/damaged-items?${params.toString()}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');
        const rows = Array.isArray(payload.data?.most_damaged) ? payload.data.most_damaged : [];

        // Damage Summary cards — pure client-side aggregation of the same
        // `rows` rendered into the table below; no additional fetch.
        const sumTypes = document.getElementById('an-dmg-sum-types');
        const sumCount = document.getElementById('an-dmg-sum-count');
        if (sumTypes) sumTypes.textContent = rows.length;
        if (sumCount) sumCount.textContent = rows.reduce((t, r) => t + (Number(r.damage_count) || 0), 0);

        // ── Horizontal bar chart — top 10, reversed so highest bar is at top ──
        if (anChartDamaged) { anChartDamaged.destroy(); anChartDamaged = null; }
        const top10 = rows.slice(0, 10).slice().reverse();
        const cvsDmg = document.getElementById('chart-damaged');
        if (cvsDmg && top10.length > 0) {
            anChartDamaged = new Chart(cvsDmg, {
                type: 'bar',
                data: {
                    labels:   top10.map((r) => r.name),
                    datasets: [{
                        data:            top10.map((r) => Number(r.damage_count)),
                        backgroundColor: 'rgba(239,68,68,0.72)',
                        borderRadius:    6,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    // READABILITY TASK: axis tick color was '#b8aacc', a pale
                    // lavender-gray that reads as near-invisible on this
                    // chart's white card (≈1.8:1 contrast) — the y-axis here
                    // is the item NAME for each bar, not decoration, so it
                    // needs to be readable, not just muted. No data/scale
                    // values changed, only the tick label color.
                    scales: {
                        y: { ticks: { color: '#4b5563' } },
                        x: { grid:  { color: 'rgba(168,139,250,0.1)' }, ticks: { color: '#4b5563' } },
                    },
                },
            });
        }

        if (rows.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No damage records found.</strong><span>Try adjusting the filters.</span></div>';
            return;
        }

        let html = '<table class="table"><thead><tr>'
            + '<th>Item Name</th><th>Damage Count</th>'
            + '</tr></thead><tbody>';
        rows.forEach((row) => {
            html += '<tr>';
            html += `<td><strong>${anEsc(row.name)}</strong></td>`;
            html += `<td>${anEsc(row.damage_count)}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="ui-empty-state"><strong>Failed to load damage data.</strong></div>';
    }
}

async function anLoadDeptUsage() {
    const container = document.getElementById('an-deptusage-container');
    container.innerHTML = '<div class="ui-empty-state"><strong>Loading...</strong></div>';
    try {
        const params = new URLSearchParams();
        const from = document.getElementById('an-dmg-from').value;
        const to   = document.getElementById('an-dmg-to').value;
        if (from) params.set('date_from', from);
        if (to)   params.set('date_to', to);

        const { response, data: payload } = await anFetch(
            `${AN_API}/department-usage?${params.toString()}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');
        const rows = Array.isArray(payload.data?.department_usage) ? payload.data.department_usage : [];

        if (rows.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No department usage data found.</strong></div>';
            return;
        }

        let html = '<table class="table"><thead><tr>'
            + '<th>Department</th><th>Total Inventory Moved</th>'
            + '</tr></thead><tbody>';
        rows.forEach((row) => {
            html += '<tr>';
            html += `<td><strong>${anEsc(row.department_name || '—')}</strong></td>`;
            html += `<td>${anEsc(row.total_moved)}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="ui-empty-state"><strong>Failed to load department usage data.</strong></div>';
    }
}

// ---------------------------------------------------------------------------
// TAB 3: Semester Detail
// ---------------------------------------------------------------------------

// The academic period is configured once (School Year + 4 semester dates)
// in School Settings — the SAME source the Dashboard's "Current Academic
// Session" card reads. There is no per-year history, so there is nothing
// to "select" here; anSemSchoolYear is populated from the API response
// purely for display in the (unchanged) selector control.
let anSemSchoolYear = null;

// Mirrors dashboard.php's date formatting so the two pages read identically.
function anFormatSemDateShort(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}
function anFormatSemDateRange(startStr, endStr) {
    if (!startStr || !endStr) return '—';
    return anFormatSemDateShort(startStr) + ' – ' + anFormatSemDateShort(endStr);
}
function anFormatSemSchoolYear(schoolYear) {
    if (!schoolYear) return null;
    return /^\d{4}-\d{4}$/.test(schoolYear) ? schoolYear.replace('-', '–') : schoolYear;
}

async function anLoadSemesterDetail() {
    const errHtml = (msg) => `<div class="ui-empty-state"><strong>${anEsc(msg)}</strong></div>`;

    document.getElementById('an-sem-cards').innerHTML =
        '<div class="ui-empty-state" style="grid-column:span 4;"><strong>Loading…</strong></div>';
    document.getElementById('an-sem-table').innerHTML = errHtml('Loading…');
    document.getElementById('an-sem-dept').innerHTML  = errHtml('Loading…');

    try {
        const { response, data: payload } = await anFetch(
            `${AN_API}/semester-detail`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');

        const d = payload.data;

        // Update the school-year label/selector from the SAME configured
        // source the Dashboard's "Current Academic Session" card uses.
        anSemSchoolYear = d.school_year || null;
        const lbl = document.getElementById('an-sem-year-label');
        const _yearSelEl = document.getElementById('an-sem-year');
        if (_yearSelEl) {
            _yearSelEl.innerHTML = '';
            const opt = document.createElement('option');
            opt.value = anSemSchoolYear || '';
            opt.textContent = anFormatSemSchoolYear(anSemSchoolYear) || 'Not configured';
            opt.selected = true;
            _yearSelEl.appendChild(opt);
        }

        if (d.configured === false) {
            if (lbl) lbl.textContent = 'Semester schedule not configured';
            const notConfiguredMsg = 'Semester schedule has not been configured yet. Set the School Year and semester dates in School Settings to see this comparison.';
            const emptyHtml = `<div class="ui-empty-state"><strong>${anEsc(notConfiguredMsg)}</strong></div>`;
            const periodCardEmpty = document.getElementById('an-sem-period');
            if (periodCardEmpty) { periodCardEmpty.style.display = 'none'; periodCardEmpty.innerHTML = ''; }
            document.getElementById('an-sem-cards').innerHTML =
                `<div class="ui-empty-state" style="grid-column:span 4;"><strong>${anEsc(notConfiguredMsg)}</strong></div>`;
            document.getElementById('an-sem-table').innerHTML = emptyHtml;
            document.getElementById('an-sem-dept').innerHTML  = emptyHtml;
            const chartSub = document.getElementById('an-sem-chart-subtitle');
            const tableSub = document.getElementById('an-sem-table-subtitle');
            if (chartSub) chartSub.textContent = 'Not configured';
            if (tableSub) tableSub.textContent = 'Not configured';
            if (anChartSemester) { anChartSemester.destroy(); anChartSemester = null; }
            return;
        }

        const s1 = d.semesters?.s1 || {};
        const s2 = d.semesters?.s2 || {};
        const s1Label = s1.label || '1st Semester';
        const s2Label = s2.label || '2nd Semester';
        const s1Range = anFormatSemDateRange(s1.start, s1.end);
        const s2Range = anFormatSemDateRange(s2.start, s2.end);

        if (lbl) lbl.textContent = anFormatSemSchoolYear(anSemSchoolYear) ? `School Year ${anFormatSemSchoolYear(anSemSchoolYear)}` : '';

        const chartSub = document.getElementById('an-sem-chart-subtitle');
        if (chartSub) chartSub.textContent = `${s1Label} (${s1Range}) vs ${s2Label} (${s2Range})`;
        const tableSub = document.getElementById('an-sem-table-subtitle');
        if (tableSub) tableSub.textContent = `Compares ${s1Label} (${s1Range}) vs ${s2Label} (${s2Range}). Trend shows change from S1 → S2.`;

        // ── Comparison Period card — assembled from the same s1/s2 data
        // above; no additional fetch. ────────────────────────────────────
        const periodCard = document.getElementById('an-sem-period');
        if (periodCard) {
            periodCard.innerHTML = `
                <div class="an-sem-period-item">
                    <span class="an-sem-period-label">School Year</span>
                    <span class="an-sem-period-value">${anEsc(anFormatSemSchoolYear(anSemSchoolYear) || '—')}</span>
                </div>
                <div class="an-sem-period-item">
                    <span class="an-sem-period-label">${anEsc(s1Label)}</span>
                    <span class="an-sem-period-value">${anEsc(s1Range)}</span>
                </div>
                <div class="an-sem-period-item">
                    <span class="an-sem-period-label">${anEsc(s2Label)}</span>
                    <span class="an-sem-period-value">${anEsc(s2Range)}</span>
                </div>`;
            periodCard.style.display = 'flex';
        }

        // Per-metric color identity (purple/blue/orange) + matching outline
        // icons, reused by both the summary cards below and the Metric
        // Comparison table further down.
        //
        // TASK 13 PHASE 8 (Repair retirement) — the `repairs` entry (green
        // #1D9E75, gear/settings glyph) was removed from this map, from
        // _metricAccent below, and from the `metrics` array. Green is still
        // used on this tab for the S2 chart series and the positive-trend
        // arrow; only the per-metric Repair identity is gone.
        const _metricIconPaths = {
            maintenance_reports: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            dispatches:          '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            damage_reports:      '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        };
        const _metricAccent = {
            maintenance_reports: '#8b5cf6',
            dispatches:          '#185FA5',
            damage_reports:      '#d97706',
        };
        const anSemIcon = (key, size) =>
            `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${_metricAccent[key]}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;">${_metricIconPaths[key]}</svg>`;

        const metrics = [
            { key: 'maintenance_reports', label: 'Maintenance Reports', slug: 'maintenance' },
            { key: 'dispatches',          label: 'Dispatches',          slug: 'dispatches'  },
            { key: 'damage_reports',      label: 'Damage Reports',      slug: 'damage'      },
            // TASK 13 PHASE 8 — { key: 'repairs', label: 'Repair Requests',
            // slug: 'repairs' } was removed. This array drives BOTH the
            // summary cards (4 → 3) and the Metric Comparison table below
            // (4 rows → 3), so the single deletion covers both surfaces.
            // AnalyticsService::semesterDetail() no longer returns a
            // `repairs` key, so leaving it would have rendered a card and a
            // table row permanently reading 0.
        ];

        // ── Summary cards ──────────────────────────────────────────────────
        let cardsHtml = '';
        metrics.forEach((m) => {
            const v1         = s1[m.key] ?? 0;
            const v2         = s2[m.key] ?? 0;
            const total      = v1 + v2;
            const arrowColor = v2 > v1 ? '#1D9E75' : v2 < v1 ? '#A32D2D' : 'var(--muted-text)';
            // TASK 7 — were ↑ / ↓ / — text glyphs. These are NOT decorative:
            // the arrow is the only thing stating the direction of the trend,
            // so each gets a real accessible name via the registry's `label`
            // option (which emits role="img" + aria-label) instead of
            // aria-hidden. Direction is therefore never carried by colour
            // alone, which is all arrowColor above was doing.
            const arrowName  = v2 > v1 ? 'arrow-up' : v2 < v1 ? 'arrow-down' : 'minus';
            const arrowLabel = v2 > v1 ? 'Increased' : v2 < v1 ? 'Decreased' : 'No change';
            const arrow      = window.UIIcons
                ? window.UIIcons.svg(arrowName, { size: 20, label: arrowLabel })
                : '';
            cardsHtml += `
                <div class="an-sem-card an-sem-card--${m.slug}">
                    <div class="an-sem-card-icon">${anSemIcon(m.key, 18)}</div>
                    <div class="an-sem-card-label">${anEsc(m.label)}</div>
                    <div class="an-sem-card-body">
                        <div style="text-align:center;">
                            <div style="font-size:28px;font-weight:500;color:#185FA5;line-height:1;">${v1}</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">1st Sem</div>
                        </div>
                        <div style="font-size:20px;color:${arrowColor};font-weight:500;line-height:1;">${arrow}</div>
                        <div style="text-align:center;">
                            <div style="font-size:22px;font-weight:500;color:var(--muted-text);line-height:1;">${v2}</div>
                            <div style="font-size:11px;color:var(--muted-text);margin-top:4px;">2nd Sem</div>
                        </div>
                    </div>
                    <div class="an-sem-card-foot">
                        Year Total: <strong style="color:var(--text-light);">${total}</strong>
                    </div>
                </div>`;
        });
        document.getElementById('an-sem-cards').innerHTML = cardsHtml;

        // ── Alternating grouped bar chart (S1 blue, S2 green) ─────────────
        if (anChartSemester) { anChartSemester.destroy(); anChartSemester = null; }
        const cvsSem = document.getElementById('chart-semester');
        if (cvsSem) {
            const C_S1 = '#185FA5';
            const C_S2 = '#1D9E75';
            // Read the page's own theme tokens so gridlines/ticks stay legible
            // in both light and dark mode (was hardcoded to a fixed gray).
            const _rootStyle  = getComputedStyle(document.documentElement);
            const _tickColor  = (_rootStyle.getPropertyValue('--muted-text') || '').trim() || '#9ca3af';
            const _gridColor  = (_rootStyle.getPropertyValue('--border') || '').trim() || 'rgba(24,95,165,0.08)';
            anChartSemester = new Chart(cvsSem, {
                type: 'bar',
                data: {
                    // TASK 13 PHASE 8 — 'Repair S1' / 'Repair S2' were the
                    // last pair of bars. Removing them takes this chart from
                    // 8 bars to 6; the labels, data and backgroundColor
                    // arrays are all index-aligned, so all three were
                    // trimmed by exactly two entries. The alternating
                    // S1-blue/S2-green pattern is preserved, and the three
                    // surviving metric pairs keep their original order.
                    labels: ['Maint S1', 'Maint S2', 'Dispatch S1', 'Dispatch S2', 'Damage S1', 'Damage S2'],
                    datasets: [{
                        data: [
                            s1.maintenance_reports ?? 0, s2.maintenance_reports ?? 0,
                            s1.dispatches          ?? 0, s2.dispatches          ?? 0,
                            s1.damage_reports      ?? 0, s2.damage_reports      ?? 0,
                        ],
                        backgroundColor: [C_S1, C_S2, C_S1, C_S2, C_S1, C_S2],
                        borderRadius: 4,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { grid: { color: _gridColor }, ticks: { color: _tickColor } },
                        x: { grid: { display: false },    ticks: { color: _tickColor } },
                    },
                },
            });
        }

        // ── Metric comparison table (reuses the color-coded icons above) ──
        // READABILITY TASK: header text was color:var(--muted-text) (~#6b7280),
        // legible but far lighter than the task's dark-heading requirement for
        // table headers. Switched to --text-heading (#111827, styles.css) so
        // "Metric / 1st Sem / 2nd Sem / Change / Trend" read as strong dark
        // headings; the lighter date-range sub-label inside each <th> keeps
        // var(--muted-text) on purpose (it is genuinely secondary support text).
        const _thS = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-heading,#1f2937);padding-bottom:8px;border-bottom:0.5px solid var(--border);';
        let tableHtml = '<table class="table" style="width:100%;"><thead><tr>'
            + `<th style="${_thS}">Metric</th>`
            + `<th style="${_thS}text-align:center;">1st Sem<br><span style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted-text);">${anEsc(s1Range)}</span></th>`
            + `<th style="${_thS}text-align:center;">2nd Sem<br><span style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted-text);">${anEsc(s2Range)}</span></th>`
            + `<th style="${_thS}text-align:center;">Change</th>`
            + `<th style="${_thS}text-align:center;">Trend</th>`
            + '</tr></thead><tbody>';

        metrics.forEach((m) => {
            const v1        = s1[m.key] ?? 0;
            const v2        = s2[m.key] ?? 0;
            const diff      = v2 - v1;
            const diffStr   = diff === 0
                ? '<span style="color:var(--muted-text);">—</span>'
                : diff > 0
                    ? `<span style="color:#1D9E75;font-weight:500;">+${diff}</span>`
                    : `<span style="color:#A32D2D;font-weight:500;">${diff}</span>`;
            const trendIcon = diff > 0
                ? '<span style="color:#1D9E75;display:inline-flex;align-items:center;" title="Increase in 2nd semester"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg></span>'
                : diff < 0
                    ? '<span style="color:#A32D2D;display:inline-flex;align-items:center;" title="Decrease in 2nd semester"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>'
                    : '<span style="color:var(--muted-text);" title="No change">—</span>';

            tableHtml += `<tr>
                <td>${anSemIcon(m.key, 14)}&nbsp;<strong style="font-weight:500;">${anEsc(m.label)}</strong></td>
                <td style="text-align:center;">${v1}</td>
                <td style="text-align:center;">${v2}</td>
                <td style="text-align:center;">${diffStr}</td>
                <td style="text-align:center;">${trendIcon}</td>
            </tr>`;
        });
        tableHtml += '</tbody></table>';
        document.getElementById('an-sem-table').innerHTML = tableHtml;

        // ── Department breakdown ───────────────────────────────────────────
        const depts = Array.isArray(d.department_breakdown) ? d.department_breakdown : [];
        if (depts.length === 0) {
            document.getElementById('an-sem-dept').innerHTML =
                '<div class="ui-empty-state"><strong>No department data for the configured semesters.</strong></div>';
        } else {
            // READABILITY TASK: same header-darkening fix as _thS above, applied
            // to "Department / S1 Damage / S2 Damage / S1 Dispatches / S2
            // Dispatches / Total Activity".
            const _thD = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-heading,#1f2937);padding-bottom:8px;border-bottom:0.5px solid var(--border);';
            let deptHtml = '<table class="table" style="width:100%;"><thead><tr>'
                + `<th style="${_thD}">Department</th>`
                + `<th style="${_thD}text-align:center;">S1 Damage</th>`
                + `<th style="${_thD}text-align:center;">S2 Damage</th>`
                + `<th style="${_thD}text-align:center;">S1 Dispatches</th>`
                + `<th style="${_thD}text-align:center;">S2 Dispatches</th>`
                + `<th style="${_thD}text-align:center;">Total Activity</th>`
                + '</tr></thead><tbody>';

            const _numPill = (n) => `<span class="an-num-pill">${n}</span>`;
            depts.forEach((dept) => {
                const total   = Number(dept.s1_damage || 0) + Number(dept.s2_damage || 0)
                              + Number(dept.s1_dispatches || 0) + Number(dept.s2_dispatches || 0);
                const _dName  = dept.department_name;
                const _isNone = !_dName || String(_dName).toLowerCase() === 'no department';
                const _dCell  = _isNone
                    ? `<td><em style="color:var(--muted-text);">${anEsc(_dName || 'No department')}</em></td>`
                    : `<td><strong style="font-weight:500;">${anEsc(_dName)}</strong></td>`;
                deptHtml += `<tr>
                    ${_dCell}
                    <td style="text-align:center;">${_numPill(dept.s1_damage     || 0)}</td>
                    <td style="text-align:center;">${_numPill(dept.s2_damage     || 0)}</td>
                    <td style="text-align:center;">${_numPill(dept.s1_dispatches || 0)}</td>
                    <td style="text-align:center;">${_numPill(dept.s2_dispatches || 0)}</td>
                    <td style="text-align:center;">${_numPill(total)}</td>
                </tr>`;
            });
            deptHtml += '</tbody></table>';
            document.getElementById('an-sem-dept').innerHTML = deptHtml;
        }

    } catch (err) {
        const html = `<div class="ui-empty-state"><strong>Failed to load semester data.</strong></div>`;
        const periodCardErr = document.getElementById('an-sem-period');
        if (periodCardErr) { periodCardErr.style.display = 'none'; periodCardErr.innerHTML = ''; }
        document.getElementById('an-sem-cards').innerHTML =
            `<div class="ui-empty-state" style="grid-column:span 4;"><strong>${anEsc(err.message || 'Error loading data')}</strong></div>`;
        document.getElementById('an-sem-table').innerHTML = html;
        document.getElementById('an-sem-dept').innerHTML  = html;
    }
}

// ---------------------------------------------------------------------------
// TAB 4: Dispatch Reports
// (TASK 13 PHASE 8 — was "Dispatch & Repair Reports".)
// ---------------------------------------------------------------------------

async function anLoadMonthlyChart() {
    if (anChartMonthly) { anChartMonthly.destroy(); anChartMonthly = null; }
    try {
        const { response, data: payload } = await anFetch(
            `${AN_API}/monthly-comparison`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) return;

        const months = Array.isArray(payload.data?.last_12_months) ? payload.data.last_12_months : [];
        if (!months.length) return;

        const MONTH_ABB = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const labels = months.map((m) => {
            const parts = String(m.ym || '').split('-');
            const abbr  = MONTH_ABB[parseInt(parts[1], 10) - 1] || parts[1];
            return `${abbr} '${String(parts[0] || '').slice(2)}`;
        });
        const values = months.map((m) => Number(m.val) || 0);

        const cvsMon = document.getElementById('chart-monthly');
        if (!cvsMon) return;

        anChartMonthly = new Chart(cvsMon, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    data:            values,
                    backgroundColor: 'rgba(139,92,246,0.72)',
                    borderRadius:    6,
                }],
            },
            options: {
                // READABILITY TASK: same '#b8aacc' near-invisible tick fix as
                // the Most Damaged Items chart above — these ticks are the
                // month labels and dispatch counts on the Dispatch Reports
                // tab's monthly chart.
                scales: {
                    y: { grid:  { color: 'rgba(168,139,250,0.1)' }, ticks: { color: '#4b5563' } },
                    x: { ticks: { color: '#4b5563' } },
                },
            },
        });
    } catch (_) {
        // fail silently — chart is decorative
    }
}

function anLoadTab4Data() {
    // TASK 13 PHASE 8 — the anLoadRepairReport() call was removed here with
    // the function itself. The tab's other two loaders are unaffected.
    anLoadMonthlyChart();
    anLoadDispatchReport();
}

async function anLoadDispatchReport() {
    const container = document.getElementById('an-dispatch-container');
    container.innerHTML = '<div class="ui-empty-state"><strong>Loading...</strong></div>';
    try {
        const params = new URLSearchParams();
        const from = document.getElementById('an-rpt-from').value;
        const to   = document.getElementById('an-rpt-to').value;
        if (from) params.set('date_from', from);
        if (to)   params.set('date_to', to);

        const { response, data: payload } = await anFetch(
            `${AN_API}/dispatch-report?${params.toString()}`,
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        );
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Failed');
        const rows = Array.isArray(payload.data?.dispatches) ? payload.data.dispatches : [];

        // Dispatch Summary cards — pure client-side aggregation of the same
        // `rows` rendered into the table below; no additional fetch.
        const sumCount = document.getElementById('an-rpt-sum-count');
        const sumItems = document.getElementById('an-rpt-sum-items');
        const sumQty   = document.getElementById('an-rpt-sum-qty');
        if (sumCount) sumCount.textContent = rows.length;
        if (sumItems) sumItems.textContent = rows.reduce((t, r) => t + (Number(r.items_count) || 0), 0);
        if (sumQty)   sumQty.textContent   = rows.reduce((t, r) => t + (Number(r.total_quantity) || 0), 0);

        if (rows.length === 0) {
            container.innerHTML = '<div class="ui-empty-state"><strong>No dispatch records found.</strong></div>';
            return;
        }

        let html = '<table class="table"><thead><tr>'
            + '<th>Dispatch Code</th><th>Items Count</th><th>Total Quantity</th>'
            + '</tr></thead><tbody>';
        rows.forEach((row) => {
            html += '<tr>';
            html += `<td><strong>${anEsc(row.dispatch_code)}</strong></td>`;
            html += `<td>${anEsc(row.items_count)}</td>`;
            html += `<td>${anEsc(row.total_quantity)}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="ui-empty-state"><strong>Failed to load dispatch report.</strong></div>';
    }
}

// TASK 13 PHASE 8 — anLoadRepairReport() was removed here. It was the only
// caller of GET /api/analytics/repair-report, which this task retires, and it
// rendered into the #an-repair-container table that went with it.
// anLoadDispatchReport() above is the Dispatch equivalent and is untouched.

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
    // Default dates: start of current year → today
    const today     = new Date();
    const yearStart = today.getFullYear() + '-01-01';
    const todayStr  = today.toISOString().slice(0, 10);
    document.getElementById('an-dmg-from').value = yearStart;
    document.getElementById('an-dmg-to').value   = todayStr;
    document.getElementById('an-rpt-from').value = yearStart;
    document.getElementById('an-rpt-to').value   = todayStr;

    // Tab buttons
    document.querySelectorAll('.an-tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => anSwitchTab(btn.dataset.tab));
    });

    // The Academic Year selector shows the single configured school_year
    // (populated once anLoadSemesterDetail() reads it from School Settings —
    // the same source as the Dashboard's "Current Academic Session" card).
    // There is no per-year history to pick between, so a placeholder is
    // shown until that data arrives.
    const _yearSel = document.getElementById('an-sem-year');
    if (_yearSel) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Loading…';
        _yearSel.appendChild(opt);
    }

    // Apply buttons
    document.getElementById('an-dmg-apply').addEventListener('click', anLoadTab2Data);
    document.getElementById('an-rpt-apply').addEventListener('click', anLoadTab4Data);
    document.getElementById('an-sem-apply').addEventListener('click', () => {
        anLoadSemesterDetail();
    });

    // Print button
    const anPrintBtn = document.getElementById('an-print-btn');
    if (anPrintBtn) {
        anPrintBtn.addEventListener('click', () => window.print());
    }

    // Preload department dropdown (non-blocking; Tab 2 may not be visited)
    anLoadOptions();

    // Activate first tab
    anSwitchTab('an-tab1');
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
