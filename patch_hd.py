import re, os

path = os.path.join(os.path.dirname(__file__), 'public', 'frontend', 'pages', 'maintenance-dashboard.php')
src = open(path, encoding='utf-8').read()
original_len = len(src)

# 1. Remove PHP $headFirstName block (between header.php include and <main>)
src = re.sub(
    r'\n\<\?php\s*\n// Enterprise redesign.*?\$headFirstName\s*=\s*\$headNameParts\[0\];\s*\}\s*\}\s*\?>\s*\n',
    '\n',
    src, flags=re.DOTALL
)

# 2. Remove Section 1: Greeting (entire dashboard-greeting-section div)
src = re.sub(
    r'\s*<!-- Section 1: Greeting -->.*?</div>\s*\n',
    '\n',
    src, flags=re.DOTALL
)

# 3. Remove Buildings Overview summary card (id="today-buildings-summary-card")
src = re.sub(
    r'\s*<!-- HEAD DASHBOARD FINAL POLISH.*?id="today-buildings-summary-card".*?</div>\s*\n\s*\n',
    '\n\n',
    src, flags=re.DOTALL
)

# 4. Remove Section 4: Maintenance Activity Timeline card
src = re.sub(
    r'\s*<!-- Section 4: Maintenance Activity Timeline -->.*?</div>\s*\n\s*\n    <!-- Section 5:',
    '\n\n    <!-- Section 5:',
    src, flags=re.DOTALL
)

# 5. Remove Quick Actions card (3rd card in ops-panels-grid)
src = re.sub(
    r'\s*<div class="card chart-card">\s*\n\s*<div class="card-header">\s*\n\s*<h3 class="mb-0">Quick Actions</h3>.*?</div>\s*\n\s*</div>\s*\n\s*</div>\s*\n\s*</main>',
    '\n        </div>\n\n</main>',
    src, flags=re.DOTALL
)

# 6. Remove updateGreetingSummary() call block
src = re.sub(
    r'        // UI Polish Sprint.*?updateGreetingSummary\(\);\n',
    '',
    src, flags=re.DOTALL
)

open(path, 'w', encoding='utf-8').write(src)
print(f"DONE. Original: {original_len} bytes -> New: {len(src)} bytes")

removals = {
    'dashboard-greeting-section': 'Greeting section',
    'dashboard-greeting-date':    'Dashboard date',
    'today-buildings-summary-card': 'Buildings Overview card',
    'activity-timeline-card':     'Activity Timeline card',
    'Quick Actions':              'Quick Actions card',
    'updateGreetingSummary()':    'updateGreetingSummary call',
}
for needle, label in removals.items():
    status = 'REMOVED ✓' if needle not in src else 'STILL PRESENT ✗'
    print(f"  {label}: {status}")

kept = {
    'today-summary-grid':         'Today summary grid',
    'today-assigned-reports':     'Assigned Reports card',
    'today-pending-inspection':   'Pending Inspection card',
    'today-completed':            'Completed Today card',
    'charts-section':             'Charts section',
    'low-stock-container':        'Low Stock Alerts',
    'pending-dispatch-container': 'Pending Dispatch Requests',
}
for needle, label in kept.items():
    status = 'KEPT ✓' if needle in src else 'MISSING ✗'
    print(f"  {label}: {status}")
