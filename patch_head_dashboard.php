<?php
$file = __DIR__ . '/public/frontend/pages/maintenance-dashboard.php';
$src  = file_get_contents($file);

// 1. Remove the PHP $headFirstName block (lines between the closing ?> after header.php include and the <main> tag)
$src = preg_replace(
    '/\n\<\?php\s*\n\/\/ Enterprise redesign.*?\$headFirstName\s*=\s*\$headNameParts\[0\];\s*\}\s*\}\s*\?>\s*\n/s',
    "\n",
    $src
);

// 2. Remove Section 1: Greeting (the entire dashboard-greeting-section div)
$src = preg_replace(
    '/\s*<!-- Section 1: Greeting -->.*?<\/div>\s*\n/s',
    "\n",
    $src
);

// 3. Remove the Buildings Overview summary card (3rd card in today-summary-grid)
//    Identified by id="today-buildings-summary-card"
$src = preg_replace(
    '/\s*<!-- HEAD DASHBOARD FINAL POLISH.*?<\/div>\s*\n        <\/div>\s*\n\s*        <div class="summary-card summary-card-completed/s',
    "\n\n        <div class=\"summary-card summary-card-completed",
    $src
);

// 4. Remove Section 4: Maintenance Activity Timeline (the full card div)
$src = preg_replace(
    '/\s*<!-- Section 4: Maintenance Activity Timeline -->.*?<\/div>\s*\n\s*\n    <!-- Section 5:/s',
    "\n\n    <!-- Section 5:",
    $src
);

// 5. Remove the Quick Actions card (3rd card in ops-panels-grid)
$src = preg_replace(
    '/\s*<div class="card chart-card">\s*\n\s*<div class="card-header">\s*\n\s*<h3 class="mb-0">Quick Actions<\/h3>.*?<\/div>\s*\n\s*<\/div>\s*\n\s*<\/div>\s*\n\s*<\/main>/s',
    "\n        </div>\n\n</main>",
    $src
);

// 6. Remove the updateGreetingSummary() call from loadDashboardData
$src = str_replace(
    "        // UI Polish Sprint — Task 4 \"data-driven greeting\". Runs after both\n        // #today-pending-inspection (set above) and window.dashboardPriorityCounts\n        // (set inside initializeCharts) exist, reusing the exact same numbers\n        // already rendered on the summary cards / donut — no new fetch.\n        updateGreetingSummary();\n",
    "",
    $src
);

file_put_contents($file, $src);
echo "DONE\n";
echo "File size: " . strlen($src) . " bytes\n";

// Verify removals
$checks = [
    'dashboard-greeting-section' => 'Greeting section',
    'dashboard-greeting-date'    => 'Dashboard date',
    'today-buildings-summary-card' => 'Buildings Overview card',
    'activity-timeline-card'     => 'Activity Timeline card',
    'Quick Actions'              => 'Quick Actions card',
    'updateGreetingSummary()'    => 'updateGreetingSummary call',
];
foreach ($checks as $needle => $label) {
    echo ($label . ': ' . (strpos($src, $needle) === false ? 'REMOVED ✓' : 'STILL PRESENT ✗') . "\n");
}

// Verify kept items
$kept = [
    'today-summary-grid'         => 'Today summary grid',
    'today-assigned-reports'     => 'Assigned Reports card',
    'today-pending-inspection'   => 'Pending Inspection card',
    'today-completed'            => 'Completed Today card',
    'charts-section'             => 'Charts section (My Reports + Priority)',
    'low-stock-container'        => 'Low Stock Alerts',
    'pending-dispatch-container' => 'Pending Dispatch Requests',
];
foreach ($kept as $needle => $label) {
    echo ($label . ': ' . (strpos($src, $needle) !== false ? 'KEPT ✓' : 'MISSING ✗') . "\n");
}
