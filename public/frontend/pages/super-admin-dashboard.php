<?php
// Admin Dashboard
// System-wide overview, statistics, and management

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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Check if user is super_admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'System Administration Dashboard';
$pageStylesheets = [
    '../assets/css/maintenance-dashboard.css',
    '/School_Facility_Maintenance_System/frontend/assets/css/super-admin-dashboard.inline.css?v=20260921-2',
    '/School_Facility_Maintenance_System/frontend/assets/css/enterprise-dashboard.css?v=20260726-1',
];
// chart-lite.js loaded by header.php — do not load again here
?>
<?php
    // Include Header/Navigation
    include '../includes/header.php';
?>
    
    <div class="system-dashboard">
        <div class="dashboard-page-header">
            <div>
                <h1 class="dashboard-page-header-title">System Administration Dashboard</h1>
                <p class="dashboard-page-header-subtitle">System-wide overview and statistics</p>
            </div>
            <div class="dashboard-page-header-actions">
                <label for="dashboard-month-picker" style="font-size:13px;margin-right:6px;">Browse by Month:</label>
                <select id="dashboard-month-picker" style="padding:6px 10px;border-radius:6px;">
                    <!-- Month options will be populated by JS -->
                </select>
                <select id="dashboard-year-picker" style="padding:6px 10px;border-radius:6px;">
                    <!-- Year options will be populated by JS -->
                </select>
                <a href="/School_Facility_Maintenance_System/frontend/pages/reports.php?last_month=1" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: 500; white-space: nowrap;">
                    <?php echo ui_icon('calendar'); ?> Last Month Reports
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-grid" id="statsContainer">
            <div class="loading">Loading statistics...</div>
        </div>

        <!-- Charts Section -->
        <div class="charts-section">
            <div class="chart-container chart-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/reports.php" aria-label="Open reports by status">
                <div class="chart-title">Reports by Status</div>
                <div class="chart-canvas">
                    <canvas id="statusChart"></canvas>
                </div>
                <div id="statusChartSummary" class="status-chart-summary">
                    <div class="loading">Loading status summary...</div>
                </div>
            </div>
            <div class="chart-container priority-chart-card chart-card-clickable" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/reports.php" aria-label="Open reports by priority">
                <div class="chart-title">Reports by Priority</div>
                <div class="chart-canvas">
                    <canvas id="priorityChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <div class="chart-title">Department Report Volume</div>
                <div class="chart-canvas">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <div class="chart-title">6-Month Trend</div>
                <div class="chart-canvas">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Reports Overview Section -->
        <div class="overview-section" style="margin-bottom: 30px;">
            <div class="overview-card" style="grid-column: 1 / -1;">
                <div class="overview-title"><?php echo ui_icon('bar-chart'); ?> Reports Overview by Status</div>
                <div id="reportsOverviewContainer" style="display: flex; flex-wrap: wrap; gap: 12px; padding-top: 5px;">
                    <div class="loading">Loading reports overview...</div>
                </div>
            </div>
        </div>

        <!-- Overview Section -->
        <div class="overview-section">
            <div class="overview-card">
                <div class="overview-title">Users by Department</div>
                <ul class="overview-list" id="departmentUsersList">
                    <li><div class="loading">Loading...</div></li>
                </ul>
            </div>
            <div class="overview-card">
                <div class="overview-title">Most Active Staff</div>
                <ul class="overview-list" id="activeStaffList">
                    <li><div class="loading">Loading...</div></li>
                </ul>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent-activity-section">
            <div class="recent-activity-title">Recent System Activity</div>
            <ul class="activity-list" id="activityList">
                <li><div class="loading">Loading activity...</div></li>
            </ul>
        </div>

        <!-- Last Month Reports Section -->
        <div class="recent-activity-section">
            <div class="recent-activity-title"><?php echo ui_icon('calendar'); ?> Last Month Reports</div>
            
            <!-- Filter Options -->
            <div class="last-month-filters">
                <select id="last-month-status-filter" class="form-control last-month-filter-control">
                    <option value="">All Status</option>
                    <option value="submitted">Submitted</option>
                    <option value="assigned">Assigned</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="closed">Closed</option>
                </select>
                
                <select id="last-month-priority-filter" class="form-control last-month-filter-control">
                    <option value="">All Priority</option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>

                <input type="date" id="last-month-date-from" class="form-control last-month-filter-control">

                <input type="date" id="last-month-date-to" class="form-control last-month-filter-control">

                <input type="text" id="last-month-search" placeholder="Search title..." class="form-control last-month-filter-control">
                
                <button id="last-month-clear-filters" class="btn btn-secondary last-month-clear-btn">Clear Filters</button>
            </div>

            <!-- Reports Table -->
            <div id="lastMonthReportsContainer" style="overflow-x: auto;">
                <div class="loading">Loading last month reports...</div>
            </div>
        </div>
    </div>

    <script>
        // ========== MONTH/YEAR PICKER LOGIC ==========
        // ========== MONTH/YEAR PICKER LOGIC ==========
        const DASHBOARD_MONTH_STORAGE_KEY = 'sfms:dashboardMonthSelection';

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

        function populateMonthYearPickers() {
            const monthPicker = document.getElementById('dashboard-month-picker');
            const yearPicker = document.getElementById('dashboard-year-picker');
            if (!monthPicker || !yearPicker) return;
            const months = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            monthPicker.innerHTML = months.map((m, i) => `<option value="${i+1}">${m}</option>`).join('');
            const currentYear = new Date().getFullYear();
            let years = [];
            for (let y = currentYear; y >= currentYear - 5; y--) years.push(y);
            yearPicker.innerHTML = years.map(y => `<option value="${y}">${y}</option>`).join('');
            monthPicker.value = String(selectedMonth);
            yearPicker.value = String(selectedYear);
        }

        let selectedMonth = (new Date().getMonth() + 1);
        let selectedYear = (new Date().getFullYear());

        function onMonthYearChange() {
            const monthPicker = document.getElementById('dashboard-month-picker');
            const yearPicker = document.getElementById('dashboard-year-picker');
            selectedMonth = parseInt(monthPicker.value, 10);
            selectedYear = parseInt(yearPicker.value, 10);
            saveDashboardMonthSelection(selectedYear, selectedMonth);
            loadDashboardData();
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadDashboardMonthSelection();
            populateMonthYearPickers();
            document.getElementById('dashboard-month-picker').addEventListener('change', onMonthYearChange);
            document.getElementById('dashboard-year-picker').addEventListener('change', onMonthYearChange);

            window.addEventListener('sfms:monthSelected', function(event) {
                const detail = event.detail || {};
                const year = Number(detail.year);
                const month = Number(detail.month);

                if (!year || !month) {
                    return;
                }

                saveDashboardMonthSelection(year, month);

                const monthPicker = document.getElementById('dashboard-month-picker');
                const yearPicker = document.getElementById('dashboard-year-picker');
                if (monthPicker) {
                    monthPicker.value = String(month);
                }
                if (yearPicker) {
                    yearPicker.value = String(year);
                }

                loadDashboardData();
            });

            window.addEventListener('storage', function(event) {
                if (event.key !== DASHBOARD_MONTH_STORAGE_KEY || !event.newValue) {
                    return;
                }

                try {
                    const parsedValue = JSON.parse(event.newValue);
                    const normalized = normalizeMonthSelection(parsedValue.year, parsedValue.month);
                    if (!normalized) {
                        return;
                    }

                    selectedYear = normalized.year;
                    selectedMonth = normalized.month;

                    const monthPicker = document.getElementById('dashboard-month-picker');
                    const yearPicker = document.getElementById('dashboard-year-picker');
                    if (monthPicker) {
                        monthPicker.value = String(normalized.month);
                    }
                    if (yearPicker) {
                        yearPicker.value = String(normalized.year);
                    }

                    loadDashboardData();
                } catch (error) {
                    console.warn('Unable to sync dashboard month selection from storage', error);
                }
            });

            loadDashboardData();
            initializeClickableCards();
        });
        let statusChart, priorityChart, departmentChart, trendChart;
        let latestChartData = null;
        const APP_BASE = '/School_Facility_Maintenance_System';

        // The system is light-only. This used to fall back to a saved theme
        // preference and then to prefers-color-scheme; both are gone, so the
        // chart palette is simply the light palette.
        function getResolvedTheme() {
            return 'light';
        }

        function getChartPalette() {
            const isLightMode = getResolvedTheme() === 'light';
            return {
                primaryText: isLightMode ? '#111827' : '#f8fafc',
                mutedText: isLightMode ? '#374151' : '#94a3b8',
                tooltipBg: isLightMode ? 'rgba(17, 24, 39, 0.92)' : 'rgba(15, 23, 42, 0.94)',
                tooltipText: '#f8fafc',
                gridY: isLightMode ? 'rgba(17, 24, 39, 0.12)' : 'rgba(148, 163, 184, 0.35)',
                gridX: isLightMode ? 'rgba(17, 24, 39, 0.08)' : 'rgba(148, 163, 184, 0.25)',
                doughnutBorder: isLightMode ? '#ffffff' : '#0f172a'
            };
        }

        function getContrastingTextColor(color) {
            if (typeof color !== 'string') {
                return '#ffffff';
            }

            const normalized = color.trim();
            let r;
            let g;
            let b;

            if (normalized.startsWith('#')) {
                let hex = normalized.slice(1);
                if (hex.length === 3) {
                    hex = hex.split('').map((char) => char + char).join('');
                }
                if (hex.length !== 6) {
                    return '#ffffff';
                }

                r = parseInt(hex.slice(0, 2), 16);
                g = parseInt(hex.slice(2, 4), 16);
                b = parseInt(hex.slice(4, 6), 16);
            } else {
                const parts = normalized.match(/\d+(\.\d+)?/g);
                if (!parts || parts.length < 3) {
                    return '#ffffff';
                }

                r = Number(parts[0]);
                g = Number(parts[1]);
                b = Number(parts[2]);
            }

            const brightness = ((r * 299) + (g * 587) + (b * 114)) / 1000;
            return brightness > 160 ? '#111827' : '#f8fafc';
        }

        function destroyExistingCharts() {
            [statusChart, priorityChart, departmentChart, trendChart].forEach((chartInstance) => {
                if (chartInstance) {
                    chartInstance.destroy();
                }
            });

            statusChart = null;
            priorityChart = null;
            departmentChart = null;
            trendChart = null;
        }

        function refreshSuperAdminChartsForTheme() {
            const palette = getChartPalette();

            if (statusChart) {
                const dataset = statusChart.data?.datasets?.[0];
                if (dataset) {
                    dataset.borderColor = palette.doughnutBorder;
                }

                if (statusChart.options?.plugins?.legend?.labels) {
                    statusChart.options.plugins.legend.labels.color = palette.mutedText;
                }

                if (statusChart.options?.plugins?.tooltip) {
                    statusChart.options.plugins.tooltip.backgroundColor = palette.tooltipBg;
                    statusChart.options.plugins.tooltip.titleColor = palette.tooltipText;
                    statusChart.options.plugins.tooltip.bodyColor = palette.tooltipText;
                }

                statusChart.update('none');
                requestAnimationFrame(() => {
                    if (!statusChart) return;
                    statusChart.render();
                    statusChart.update('none');
                });
            }

            if (priorityChart) {
                if (priorityChart.options?.scales?.y?.ticks) {
                    priorityChart.options.scales.y.ticks.color = palette.mutedText;
                }
                if (priorityChart.options?.scales?.y?.grid) {
                    priorityChart.options.scales.y.grid.color = palette.gridY;
                }
                if (priorityChart.options?.scales?.x?.ticks) {
                    priorityChart.options.scales.x.ticks.color = palette.mutedText;
                }
                if (priorityChart.options?.scales?.x?.grid) {
                    priorityChart.options.scales.x.grid.color = palette.gridX;
                }
                if (priorityChart.options?.plugins?.tooltip) {
                    priorityChart.options.plugins.tooltip.backgroundColor = palette.tooltipBg;
                    priorityChart.options.plugins.tooltip.titleColor = palette.tooltipText;
                    priorityChart.options.plugins.tooltip.bodyColor = palette.tooltipText;
                }
                priorityChart.update('none');
            }

            if (departmentChart) {
                if (departmentChart.options?.plugins?.legend?.labels) {
                    departmentChart.options.plugins.legend.labels.color = palette.mutedText;
                }
                if (departmentChart.options?.scales?.x?.ticks) {
                    departmentChart.options.scales.x.ticks.color = palette.mutedText;
                }
                if (departmentChart.options?.scales?.x?.grid) {
                    departmentChart.options.scales.x.grid.color = palette.gridX;
                }
                if (departmentChart.options?.scales?.y?.ticks) {
                    departmentChart.options.scales.y.ticks.color = palette.mutedText;
                }
                if (departmentChart.options?.scales?.y?.grid) {
                    departmentChart.options.scales.y.grid.color = palette.gridY;
                }
                departmentChart.update('none');
            }

            if (trendChart) {
                if (trendChart.options?.plugins?.legend?.labels) {
                    trendChart.options.plugins.legend.labels.color = palette.mutedText;
                }
                if (trendChart.options?.scales?.y?.ticks) {
                    trendChart.options.scales.y.ticks.color = palette.mutedText;
                }
                if (trendChart.options?.scales?.y?.grid) {
                    trendChart.options.scales.y.grid.color = palette.gridY;
                }
                if (trendChart.options?.scales?.x?.ticks) {
                    trendChart.options.scales.x.ticks.color = palette.mutedText;
                }
                if (trendChart.options?.scales?.x?.grid) {
                    trendChart.options.scales.x.grid.color = palette.gridX;
                }
                trendChart.update('none');
            }
        }

        function initializeClickableCards() {
            document.querySelectorAll('.stat-card-clickable, .chart-card-clickable').forEach((card) => {
                if (card.dataset.sfmsClickableBound === 'true') {
                    return;
                }

                card.dataset.sfmsClickableBound = 'true';

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

        // Load all dashboard data
        async function loadDashboardData() {
            try {
                // Laging may month/year sa API calls
                const statsUrl = window.SFMS_PUBLIC_URL(`/api/dashboard/super-admin/stats?year=${selectedYear}&month=${selectedMonth}`);
                const chartUrl = window.SFMS_PUBLIC_URL(`/api/dashboard/super-admin/charts?year=${selectedYear}&month=${selectedMonth}`);

                const statsResponse = await fetch(statsUrl, { cache: 'no-store' });
                const statsData = await statsResponse.json();
                renderStatistics(statsData);

                const chartResponse = await fetch(chartUrl, { cache: 'no-store' });
                const chartData = await chartResponse.json();
                renderCharts(chartData);

                // System overview and activity are not month-filtered
                const overviewResponse = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/super-admin/overview'));
                const overviewData = await overviewResponse.json();
                renderOverview(overviewData);

                const activityResponse = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/super-admin/activity'));
                const activityData = await activityResponse.json();
                renderActivity(activityData);

            } catch (error) {
                console.error('Error loading dashboard data:', error);
            }
        }

        // TASK 7.1 — the stat chips below were entity-encoded emoji
        // ('&#128101;' 👥, '&#128196;' 📄, '&#127970;' 🏢, '&#9203;' ⏳,
        // '&#128295;' 🔧, '&#9888;' ⚠). They are decorative: every chip sits
        // beside its own .stat-label text, so they stay aria-hidden.
        function saIcon(name) {
            return window.UIIcons ? window.UIIcons.svg(name, { size: 24 }) : '';
        }

        function renderStatistics(data) {
            if (!data.success) {
                document.getElementById('statsContainer').innerHTML = '<div style="color: #ef4444; padding: 20px;">Failed to load statistics. Please refresh the page.</div>';
                return;
            }

            const container = document.getElementById('statsContainer');
            container.innerHTML = `
                <div class="stat-card users">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('users')}</span>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-value">${data.totalUsers}</div>
                    <div class="stat-detail">
                        Administrator: ${data.roleBreakdown.super_admin || 0}<br>
                        Head: ${data.roleBreakdown.maintenance_admin || 0}<br>
                        Maintenance Staff: ${data.roleBreakdown.maintenance_staff || 0}
                    </div>
                </div>

                <div class="stat-card reports">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('file-text')}</span>
                        <div class="stat-label">Total Reports</div>
                    </div>
                    <div class="stat-value">${data.totalReports}</div>
                    <div class="stat-detail">
                        System-wide report volume
                    </div>
                </div>

                <div class="stat-card departments">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('building')}</span>
                        <div class="stat-label">Departments</div>
                    </div>
                    <div class="stat-value">${data.totalDepartments}</div>
                    <div class="stat-detail">
                        Active maintenance departments
                    </div>
                </div>

                <div class="stat-card buildings stat-card-clickable stat-card-action" role="button" tabindex="0" data-href="/School_Facility_Maintenance_System/frontend/pages/buildings-overview.php" aria-label="Open buildings overview">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('building')}</span>
                        <div class="stat-label">Buildings Overview</div>
                    </div>
                    <div class="stat-value">${data.buildingsOverview || 0}</div>
                    <div class="stat-detail">
                        Browse buildings and rooms
                    </div>
                </div>

                <div class="stat-card pending">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('clock')}</span>
                        <div class="stat-label">Pending Tasks</div>
                    </div>
                    <div class="stat-value">${data.pendingReports}</div>
                    <div class="stat-detail">
                        Submitted + assigned
                    </div>
                </div>

                <div class="stat-card in-progress">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('wrench')}</span>
                        <div class="stat-label">In Progress</div>
                    </div>
                    <div class="stat-value">${data.inProgressReports || 0}</div>
                    <div class="stat-detail">
                        Currently being worked on
                    </div>
                </div>

                <div class="stat-card overdue">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('alert-triangle')}</span>
                        <div class="stat-label">Overdue</div>
                    </div>
                    <div class="stat-value">${data.overdueReports}</div>
                    <div class="stat-detail">
                        Past due date, not yet completed
                    </div>
                </div>

                <div class="stat-card completed">
                    <div class="stat-head">
                        <span class="stat-icon-chip">${saIcon('check-circle')}</span>
                        <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-value">${data.completedThisMonth}</div>
                    <div class="stat-detail">
                        This month
                    </div>
                </div>
            `;

            // Render reports overview badges
            renderReportsOverview(data);
            initializeClickableCards();
        }

        function renderReportsOverview(data) {
            const container = document.getElementById('reportsOverviewContainer');
            if (!container) return;

            const statusConfig = [
                { key: 'submitted',   label: 'Submitted',   color: '#3b82f6', icon: 'clipboard-list' },
                { key: 'assigned',    label: 'Assigned',    color: '#f59e0b', icon: 'user' },
                { key: 'in_progress', label: 'In Progress', color: '#8b5cf6', icon: 'wrench' },
                { key: 'completed',   label: 'Completed',   color: '#10b981', icon: 'check-circle' },
                { key: 'closed',      label: 'Closed',      color: '#6b7280', icon: 'lock' },
                { key: 'cancelled',   label: 'Cancelled',   color: '#ef4444', icon: 'x-circle' }
            ];

            const totalReports = data.totalReports || 0;

            container.innerHTML = statusConfig.map(s => {
                const count = data.statusBreakdown[s.key] || 0;
                const pct = totalReports > 0 ? Math.round((count / totalReports) * 100) : 0;
                return `
                    <div style="
                        background: white;
                        border: 1.5px solid ${s.color}33;
                        border-left: 4px solid ${s.color};
                        border-radius: 8px;
                        padding: 14px 20px;
                        min-width: 150px;
                        flex: 1;
                        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
                    ">
                        <div style="font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                            ${saIcon(s.icon)} ${s.label}
                        </div>
                        <div style="font-size: 28px; font-weight: 700; color: ${s.color}; line-height: 1;">${count}</div>
                        <div style="font-size: 12px; color: #9ca3af; margin-top: 5px;">${pct}% of total</div>
                        <div style="margin-top: 8px; background: #e5e7eb; border-radius: 99px; height: 5px; overflow: hidden;">
                            <div style="background: ${s.color}; width: ${pct}%; height: 100%; border-radius: 99px; transition: width 0.6s ease;"></div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderStatusChartSummary(statusChartData) {
            const container = document.getElementById('statusChartSummary');
            if (!container) {
                return;
            }

            const labels = Array.isArray(statusChartData?.labels) ? statusChartData.labels : [];
            const values = Array.isArray(statusChartData?.data) ? statusChartData.data : [];
            const colors = Array.isArray(statusChartData?.colors) ? statusChartData.colors : [];
            const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

            if (!labels.length) {
                container.innerHTML = '<div class="status-summary-empty">No status data available.</div>';
                return;
            }

            container.innerHTML = labels.map((label, index) => {
                const value = Number(values[index] || 0);
                const color = colors[index] || '#6b7280';
                const percent = total > 0 ? Math.round((value / total) * 100) : 0;

                return `
                    <div class="status-summary-item" style="--status-color: ${color};">
                        <span class="status-summary-label">${label}</span>
                        <span class="status-summary-value">${value}</span>
                        <span class="status-summary-percent">${percent}%</span>
                    </div>
                `;
            }).join('');
        }

        function renderCharts(data) {
            if (!data.success) return;
            latestChartData = data;
            destroyExistingCharts();
            renderStatusChartSummary(data.statusChart);

            const palette = getChartPalette();
            const statusValues = Array.isArray(data.statusChart?.data) ? data.statusChart.data : [];
            const statusTotal = statusValues.reduce((sum, value) => sum + Number(value || 0), 0);

            const statusValueLabelsPlugin = {
                id: 'statusValueLabelsPlugin',
                afterDatasetsDraw(chart) {
                    const currentPalette = getChartPalette();
                    const meta = chart.getDatasetMeta(0);
                    const dataset = chart.data?.datasets?.[0];
                    if (!meta || !meta.data || !dataset) {
                        return;
                    }

                    const ctx = chart.ctx;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.font = '700 12px "Segoe UI", sans-serif';

                    meta.data.forEach((arc, index) => {
                        const value = Number(dataset.data[index] || 0);
                        if (value <= 0) {
                            return;
                        }

                        const angle = (arc.startAngle + arc.endAngle) / 2;
                        const radius = arc.innerRadius + ((arc.outerRadius - arc.innerRadius) * 0.58);
                        const x = arc.x + Math.cos(angle) * radius;
                        const y = arc.y + Math.sin(angle) * radius;
                        const bgColor = Array.isArray(dataset.backgroundColor)
                            ? dataset.backgroundColor[index]
                            : dataset.backgroundColor;

                        ctx.lineWidth = 3;
                        ctx.strokeStyle = currentPalette.doughnutBorder;
                        ctx.strokeText(String(value), x, y);
                        ctx.fillStyle = getContrastingTextColor(bgColor);
                        ctx.fillText(String(value), x, y);
                    });

                    ctx.restore();
                }
            };

            const statusCenterTextPlugin = {
                id: 'statusCenterTextPlugin',
                afterDatasetsDraw(chart) {
                    const currentPalette = getChartPalette();
                    const meta = chart.getDatasetMeta(0);
                    if (!meta || !meta.data || !meta.data.length) {
                        return;
                    }

                    const point = meta.data[0];
                    const ctx = chart.ctx;

                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.font = '700 22px "Segoe UI", sans-serif';
                    ctx.fillStyle = currentPalette.primaryText;
                    ctx.fillText(String(statusTotal), point.x, point.y - 4);
                    ctx.font = '500 11px "Segoe UI", sans-serif';
                    ctx.fillStyle = currentPalette.mutedText;
                    ctx.fillText('total', point.x, point.y + 14);
                    ctx.restore();
                }
            };

            const ctx1 = document.getElementById('statusChart').getContext('2d');
            statusChart = new Chart(ctx1, {
                type: 'doughnut',
                plugins: [statusValueLabelsPlugin, statusCenterTextPlugin],
                data: {
                    labels: data.statusChart.labels,
                    datasets: [{
                        data: data.statusChart.data,
                        backgroundColor: data.statusChart.colors,
                        borderColor: palette.doughnutBorder,
                        borderWidth: 2,
                        hoverOffset: 3,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: palette.mutedText,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                                boxHeight: 8,
                                padding: 14
                            }
                        },
                        tooltip: {
                            backgroundColor: palette.tooltipBg,
                            titleColor: palette.tooltipText,
                            bodyColor: palette.tooltipText,
                            callbacks: {
                                label: (context) => ` ${context.formattedValue} report${Number(context.formattedValue) !== 1 ? 's' : ''}`
                            }
                        }
                    }
                }
            });

            const ctx2 = document.getElementById('priorityChart').getContext('2d');
            priorityChart = new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: data.priorityChart.labels,
                    datasets: [{
                        label: 'Reports',
                        data: data.priorityChart.data,
                        // TASK 53 — this was a hardcoded 5-slot positional array
                        // that ignored the backend's own data.priorityChart.colors
                        // (which correctly maps each label to its semantic color
                        // by key, not by position). Since the backend's label
                        // order depends on which priorities actually occurred
                        // that month (via ORDER BY FIELD(...)), a positional
                        // array silently mis-colored bars whenever the present
                        // priorities/order didn't match what this array assumed.
                        backgroundColor: data.priorityChart.colors,
                        borderColor: '#ffffff',
                        borderWidth: 1,
                        barThickness: 70,
                        maxBarThickness: 76
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (!elements || !elements.length) {
                            // Not on a bar — let the click keep bubbling to the
                            // enclosing .chart-card-clickable wrapper, which
                            // opens the unfiltered reports list. That is the
                            // intended fallback for "clicked the card, not a bar".
                            return;
                        }

                        // TASK 53 — this canvas sits INSIDE a
                        // .chart-card-clickable wrapper (see the markup above)
                        // whose own bubbling click handler assigns
                        // window.location.href from data-href — the UNFILTERED
                        // reports.php. Without this stopPropagation that
                        // wrapper ran immediately after this handler and
                        // overwrote the filtered URL assigned below (last
                        // assignment wins), so every bar click silently landed
                        // on the unfiltered list and the per-priority
                        // navigation never actually took effect.
                        if (event && event.native && event.native.stopPropagation) {
                            event.native.stopPropagation();
                        }

                        // TASK 53 — this previously used a hardcoded
                        // ['low','medium','high','critical','urgent'] array
                        // indexed by bar position, which never actually matched
                        // the backend's real label order (ORDER BY
                        // FIELD(priority,'critical','urgent','high','medium','low')),
                        // so clicking a bar could navigate to a completely
                        // different priority than the one clicked. Reading the
                        // priority directly from this bar's own label is
                        // correct regardless of ordering.
                        const idx = elements[0].index;
                        const priority = (data.priorityChart.labels?.[idx] || '').toLowerCase();
                        if (!priority) {
                            return;
                        }

                        window.location.href = `/School_Facility_Maintenance_System/frontend/pages/reports.php?priority=${encodeURIComponent(priority)}`;
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: palette.tooltipBg,
                            titleColor: palette.tooltipText,
                            bodyColor: palette.tooltipText
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 2,
                                color: palette.mutedText
                            },
                            grid: {
                                color: palette.gridY,
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: palette.mutedText
                            },
                            grid: {
                                color: palette.gridX,
                                drawBorder: false
                            }
                        }
                    }
                }
            });

            const ctx3 = document.getElementById('departmentChart').getContext('2d');
            departmentChart = new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: data.departmentChart.labels,
                    datasets: [{
                        label: 'Number of Reports',
                        data: data.departmentChart.data,
                        backgroundColor: '#8F00CC',
                        borderColor: '#8F00CC',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                color: palette.mutedText
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                color: palette.mutedText
                            },
                            grid: {
                                color: palette.gridX,
                                drawBorder: false
                            }
                        },
                        y: {
                            ticks: {
                                color: palette.mutedText
                            },
                            grid: {
                                color: palette.gridY,
                                drawBorder: false
                            }
                        }
                    }
                }
            });

            const ctx4 = document.getElementById('trendChart').getContext('2d');
            trendChart = new Chart(ctx4, {
                type: 'line',
                data: {
                    labels: data.trendChart.labels,
                    datasets: [{
                        label: 'Reports Created',
                        data: data.trendChart.data,
                        borderColor: '#8F00CC',
                        backgroundColor: 'rgba(143, 0, 204, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#8F00CC',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                color: palette.mutedText
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: palette.mutedText
                            },
                            grid: {
                                color: palette.gridY,
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: palette.mutedText
                            },
                            grid: {
                                color: palette.gridX,
                                drawBorder: false
                            }
                        }
                    }
                }
            });
        }

        function renderOverview(data) {
            if (!data.success) return;

            // Department users
            const deptList = document.getElementById('departmentUsersList');
            if (data.departmentUsers && data.departmentUsers.length > 0) {
                deptList.innerHTML = data.departmentUsers
                    .map(dept => `
                        <li>
                            <span class="name">${UI.escapeHtml(dept.name)}</span>
                            <span class="count">${dept.count || 0} user${dept.count != 1 ? 's' : ''}</span>
                        </li>
                    `)
                    .join('');
            } else {
                deptList.innerHTML = '<li><span style="color: #9ca3af;">No department data available</span></li>';
            }

            // Active staff
            const staffList = document.getElementById('activeStaffList');
            if (data.activeStaff && data.activeStaff.length > 0) {
                staffList.innerHTML = data.activeStaff
                    .map(staff => `
                        <li>
                            <span class="name">${UI.escapeHtml(staff.full_name)}</span>
                            <span class="count">${staff.assigned_count} assigned</span>
                        </li>
                    `)
                    .join('');
            } else {
                staffList.innerHTML = '<li><span style="color: #9ca3af;">No staff data available</span></li>';
            }
        }

        function renderActivity(data) {
            if (!data.success) return;

            const activityList = document.getElementById('activityList');
            
            if (data.activities.length === 0) {
                activityList.innerHTML = '<li><span style="color: #9ca3af;">No recent activity</span></li>';
                return;
            }

            activityList.innerHTML = data.activities
                .map(activity => {
                    const date = new Date(activity.created_at);
                    const timeAgo = getTimeAgo(date);
                    
                    return `
                        <li class="activity-item">
                            <span class="activity-badge">${UI.escapeHtml(activity.action)}</span>
                            <div class="activity-content">
                                <div class="activity-user">${UI.escapeHtml(activity.full_name) || 'System'}</div>
                                <div class="activity-detail">${UI.escapeHtml(activity.details)}</div>
                                <div class="activity-time">${UI.escapeHtml(timeAgo)}</div>
                            </div>
                        </li>
                    `;
                })
                .join('');
        }

        function getTimeAgo(date) {
            const seconds = Math.floor((new Date() - date) / 1000);
            let interval = seconds / 31536000;

            if (interval > 1) return Math.floor(interval) + " years ago";
            interval = seconds / 2592000;
            if (interval > 1) return Math.floor(interval) + " months ago";
            interval = seconds / 86400;
            if (interval > 1) return Math.floor(interval) + " days ago";
            interval = seconds / 3600;
            if (interval > 1) return Math.floor(interval) + " hours ago";
            interval = seconds / 60;
            if (interval > 1) return Math.floor(interval) + " minutes ago";
            return Math.floor(seconds) + " seconds ago";
        }

        // Load data when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const defaultRange = getLastMonthDateRange();
            const fromInput = document.getElementById('last-month-date-from');
            const toInput = document.getElementById('last-month-date-to');
            if (fromInput && toInput) {
                fromInput.value = defaultRange.start;
                toInput.value = defaultRange.end;
                lastMonthFilters.date_from = defaultRange.start;
                lastMonthFilters.date_to = defaultRange.end;
            }

            loadDashboardData();
            loadLastMonthReports();

            const root = document.documentElement;
            const themeObserver = new MutationObserver((mutations) => {
                const changedTheme = mutations.some((mutation) => mutation.type === 'attributes' && mutation.attributeName === 'data-theme-resolved');
                if (!changedTheme) {
                    return;
                }

                if (statusChart || priorityChart || departmentChart || trendChart) {
                    refreshSuperAdminChartsForTheme();
                    setTimeout(() => {
                        refreshSuperAdminChartsForTheme();
                    }, 60);
                } else if (latestChartData) {
                    renderCharts(latestChartData);
                    setTimeout(() => renderCharts(latestChartData), 60);
                }

                loadLastMonthReports();
            });
            themeObserver.observe(root, { attributes: true, attributeFilter: ['data-theme-resolved'] });
        });

        // Refresh every 5 minutes
        setInterval(loadDashboardData, 5 * 60 * 1000);
        setInterval(loadLastMonthReports, 5 * 60 * 1000);

        // ========== LAST MONTH REPORTS SECTION ==========
        let lastMonthFilters = {};

        function formatLocalDate(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        // Helper function to get last month date range
        function getLastMonthDateRange() {
            const today = new Date();
            const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0); // Last day of previous month
            const lastMonthStart = new Date(lastMonthEnd.getFullYear(), lastMonthEnd.getMonth(), 1); // First day of previous month
            
            return {
                start: formatLocalDate(lastMonthStart),
                end: formatLocalDate(lastMonthEnd)
            };
        }

        // Load last month reports
        async function loadLastMonthReports() {
            try {
                const defaultRange = getLastMonthDateRange();
                const dateFrom = lastMonthFilters.date_from || defaultRange.start;
                const dateTo = lastMonthFilters.date_to || defaultRange.end;
                
                const params = new URLSearchParams({
                    per_page: 100,
                    date_from: dateFrom,
                    date_to: dateTo,
                    ...lastMonthFilters
                });

                const response = await fetch(window.SFMS_PUBLIC_URL('/api/reports') + '?' + params.toString(), { credentials: 'include' });
                const data = await response.json();

                if (data.success && data.data.reports) {
                    displayLastMonthReports(data.data.reports);
                } else {
                    document.getElementById('lastMonthReportsContainer').innerHTML = '<p class="text-muted text-center">No reports found for last month</p>';
                }
            } catch (error) {
                console.error('Error loading last month reports:', error);
                document.getElementById('lastMonthReportsContainer').innerHTML = '<p class="text-danger text-center">Failed to load reports</p>';
            }
        }

        // Display last month reports in table format
        function displayLastMonthReports(reports) {
            const container = document.getElementById('lastMonthReportsContainer');
            
            if (reports.length === 0) {
                container.innerHTML = '<p class="text-muted text-center" style="padding: 20px;">No reports found for last month</p>';
                return;
            }

            // Helper functions for badges
            // IT-expert priority-color correction: Critical=Red, High=Orange,
            // Medium=Yellow, Low=Blue (standardized app-wide).
            function getPriorityColor(priority) {
                const colors = {
                    'low': '#3b82f6',
                    'medium': '#eab308',
                    'high': '#f97316',
                    'urgent': '#dc2626',
                    'critical': '#991b1b'
                };
                return colors[priority] || '#6b7280';
            }

            function getStatusColor(status) {
                const colors = {
                    'submitted': '#3b82f6',
                    'assigned': '#8b5cf6',
                    'in_progress': '#f59e0b',
                    'completed': '#10b981',
                    'cancelled': '#ef4444',
                    'closed': '#6b7280',
                    'draft': '#3b82f6'
                };
                return colors[status] || '#6b7280';
            }

            function formatDate(date) {
                return new Date(date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }

            const isDark = getResolvedTheme() === 'dark';
            const tableBg = isDark ? '#111827' : '#ffffff';
            const borderColor = isDark ? '#374151' : '#e5e7eb';
            const headerBg = isDark ? '#1f2937' : '#f3f4f6';
            const headerText = isDark ? '#f3f4f6' : '#8F00CC';
            const rowOdd = isDark ? '#111827' : '#ffffff';
            const rowEven = isDark ? '#0f172a' : '#f9fafb';
            const textPrimary = isDark ? '#f3f4f6' : '#8F00CC';
            const textMuted = isDark ? '#cbd5e1' : '#6b7280';
            const viewText = isDark ? '#e5e7eb' : '#8F00CC';
            const viewBg = isDark ? 'rgba(138, 43, 226, 0.14)' : 'transparent';

            let html = `<table class="last-month-table" style="width: 100%; border-collapse: collapse; font-size: 14px; background: ${tableBg}; border: 1px solid ${borderColor};">`;
            html += '<thead>';
            html += `<tr style="background: ${headerBg}; border-bottom: 2px solid ${borderColor};">`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">ID</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Title</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Location</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Priority</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Status</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Assigned To</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Created</th>`;
            html += `<th style="padding: 12px; text-align: left; font-weight: 600; color: ${headerText}; border-color: ${borderColor};">Action</th>`;
            html += '</tr>';
            html += '</thead><tbody>';

            reports.forEach((report, index) => {
                const priorityColor = getPriorityColor(report.priority);
                const statusColor = getStatusColor(report.status);
                const createdDate = formatDate(report.created_at);
                const rowBg = index % 2 === 0 ? rowOdd : rowEven;

                html += `<tr style="background: ${rowBg}; border-bottom: 1px solid ${borderColor};">`;
                html += `<td class="cell-emphasis" style="padding: 12px; color: ${textPrimary}; border-color: ${borderColor}; font-weight: 600;">#${report.report_id}</td>`;
                html += `<td class="cell-emphasis" style="padding: 12px; color: ${textPrimary}; border-color: ${borderColor}; font-weight: 600;">${UI.escapeHtml(report.title)}</td>`;
                html += `<td class="cell-muted" style="padding: 12px; color: ${textMuted}; border-color: ${borderColor};">${UI.escapeHtml(report.location)}</td>`;
                html += `<td style="padding: 12px; border-color: ${borderColor};"><span style="background: ${priorityColor}; color: white; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">${UI.escapeHtml(report.priority.toUpperCase())}</span></td>`;
                html += `<td style="padding: 12px; border-color: ${borderColor};"><span style="background: ${statusColor}; color: white; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">${UI.escapeHtml(report.status.replace('_', ' ').toUpperCase())}</span></td>`;
                html += `<td class="cell-muted" style="padding: 12px; color: ${textMuted}; border-color: ${borderColor};">${UI.escapeHtml(report.assigned_name) || 'Unassigned'}</td>`;
                html += `<td class="cell-muted" style="padding: 12px; color: ${textMuted}; border-color: ${borderColor};">${createdDate}</td>`;
                html += `<td style="padding: 12px; border-color: ${borderColor};"><a href="/School_Facility_Maintenance_System/frontend/pages/maintenance-report-detail.php?id=${report.report_id}" class="last-month-view-link" style="color: ${viewText}; text-decoration: none; font-weight: 600; border: 1px solid #8A2BE2; padding: 6px 14px; border-radius: 6px; display: inline-block; background: ${viewBg};">View</a></td>`;
                html += '</tr>';
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        // Apply last month filters
        function applyLastMonthFilters() {
            lastMonthFilters = {
                status: document.getElementById('last-month-status-filter').value,
                priority: document.getElementById('last-month-priority-filter').value,
                search: document.getElementById('last-month-search').value,
                date_from: document.getElementById('last-month-date-from').value,
                date_to: document.getElementById('last-month-date-to').value
            };

            // Remove empty filters
            Object.keys(lastMonthFilters).forEach(key => {
                if (!lastMonthFilters[key]) delete lastMonthFilters[key];
            });

            loadLastMonthReports();
        }

        // Event listeners for last month filters
        document.getElementById('last-month-status-filter')?.addEventListener('change', applyLastMonthFilters);
        document.getElementById('last-month-priority-filter')?.addEventListener('change', applyLastMonthFilters);
        document.getElementById('last-month-date-from')?.addEventListener('change', applyLastMonthFilters);
        document.getElementById('last-month-date-to')?.addEventListener('change', applyLastMonthFilters);
        document.getElementById('last-month-search')?.addEventListener('keyup', applyLastMonthFilters);

        document.getElementById('last-month-clear-filters')?.addEventListener('click', () => {
            document.getElementById('last-month-status-filter').value = '';
            document.getElementById('last-month-priority-filter').value = '';
            document.getElementById('last-month-search').value = '';
            const dateRange = getLastMonthDateRange();
            document.getElementById('last-month-date-from').value = dateRange.start;
            document.getElementById('last-month-date-to').value = dateRange.end;
            lastMonthFilters = {
                date_from: dateRange.start,
                date_to: dateRange.end
            };
            loadLastMonthReports();
        });
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
