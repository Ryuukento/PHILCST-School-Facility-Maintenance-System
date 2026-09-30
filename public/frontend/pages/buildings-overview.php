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

if (!isset($_SESSION['user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$user = $_SESSION['user'];
$allowedRoles = ['super_admin', 'maintenance_admin', 'maintenance_staff'];
if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
    exit;
}

$isMaintenanceContext = in_array($user['role'] ?? '', ['maintenance_admin', 'maintenance_staff'], true);
$defaultBackUrl = $isMaintenanceContext
    ? '/School_Facility_Maintenance_System/frontend/pages/maintenance-dashboard.php'
    : '/School_Facility_Maintenance_System/frontend/pages/dashboard.php';

$pageTitle = 'Buildings Overview - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars(public_url('/frontend/assets/css/buildings-redesign.css?v=20260921-2')); ?>">

<main class="container buildings-page-container">
    <section class="buildings-page-hero">
        <div class="buildings-page-hero-copy">
            <p class="eyebrow">Facility workspace</p>
            <h2>Buildings Overview</h2>
            <p>Explore all campus buildings, floors, rooms, and inventory deployments.</p>
        </div>
        <!-- The Rooms / Deployments / Inventory quick-nav cards were removed
             from this header on request. Inventory and Deployment Tracking
             stay reachable from the sidebar's Inventory menu. -->
    </section>

    <section id="buildingsStatsSection" class="buildings-stats-grid" aria-label="Facility summary statistics" hidden>
        <div class="buildings-stat-card buildings-stat-card--buildings">
            <div class="buildings-stat-icon"><?php echo ui_icon('building'); ?></div>
            <div class="buildings-stat-body">
                <p class="buildings-stat-label">Total Buildings</p>
                <p class="buildings-stat-value" id="statTotalBuildings">&mdash;</p>
                <p class="buildings-stat-desc">Registered buildings</p>
            </div>
        </div>
        <div class="buildings-stat-card buildings-stat-card--floors">
            <div class="buildings-stat-icon"><?php echo ui_icon('folder'); ?></div>
            <div class="buildings-stat-body">
                <p class="buildings-stat-label">Total Floors</p>
                <p class="buildings-stat-value" id="statTotalFloors">&mdash;</p>
                <p class="buildings-stat-desc">Across all buildings</p>
            </div>
        </div>
        <div class="buildings-stat-card buildings-stat-card--rooms">
            <div class="buildings-stat-icon"><?php echo ui_icon('door'); ?></div>
            <div class="buildings-stat-body">
                <p class="buildings-stat-label">Total Rooms</p>
                <p class="buildings-stat-value" id="statTotalRooms">&mdash;</p>
                <p class="buildings-stat-desc">Classrooms, offices, labs</p>
            </div>
        </div>
        <div class="buildings-stat-card buildings-stat-card--items">
            <div class="buildings-stat-icon"><?php echo ui_icon('package'); ?></div>
            <div class="buildings-stat-body">
                <p class="buildings-stat-label">Deployed Items</p>
                <p class="buildings-stat-value" id="statDeployedItems">&mdash;</p>
                <!-- TASK 5 — was "Inventory deployed", which was vague enough
                     that this number looked like it should match Deployment
                     Tracking's row count and Inventory Reports' unit total.
                     UPDATE (legacy-entry feature) — BuildingController::
                     deployedItems() now UNIONs in direct room_asset rows
                     (items entered via Inventory's "Already Deployed In
                     Room" selector, with no dispatch trail) alongside
                     released dispatch_items, same as Deployment Tracking
                     already did. Previously this card only counted
                     dispatch-released units and stayed at 0 for rooms whose
                     equipment was recorded directly, which didn't match what
                     the room actually had. -->
                <p class="buildings-stat-desc">Units deployed (dispatch + existing)</p>
            </div>
        </div>
        <div class="buildings-stat-card buildings-stat-card--pending">
            <div class="buildings-stat-icon"><?php echo ui_icon('alert-triangle'); ?></div>
            <div class="buildings-stat-body">
                <p class="buildings-stat-label">Pending Requests</p>
                <p class="buildings-stat-value" id="statPendingRequests">&mdash;</p>
                <!-- TASK 5 — was "Requires attention". This card reads
                     /api/dashboard/stats `pending`, which is semester-scoped
                     (DashboardController::stats()), so the caption now states
                     that scope. The Dashboard's "Pending Tasks" card now reads
                     the same field, so the two agree. -->
                <p class="buildings-stat-desc">This semester</p>
            </div>
        </div>
    </section>

    <div class="card buildings-overview-card">
        <div class="card-header" id="overviewHeader">
            <div>
                <h2 id="overviewTitle">Buildings</h2>
                <p class="text-muted mb-0" id="overviewSubtitle">Select a building to view floors</p>
            </div>
            <div id="overviewActions" class="header-actions">
                <!-- action buttons inserted by JS -->
            </div>
        </div>
        <div class="card-body">
            <div id="buildingToolbarWrap" class="building-toolbar-wrap" hidden>
                <!-- TASK 101B — persistent cascading filter toolbar. All three
                     controls live here, on the OUTER Buildings Overview view, so
                     the Floor filter never requires opening a building and the
                     Room filter never requires opening a floor. Each control
                     carries a <datalist> that is rebuilt from its parent's
                     server-scoped response, which is what makes "limits the
                     available floors/rooms" literal: a child offers no options
                     and stays disabled until its parent resolves. Layout reuses
                     .building-toolbar / .building-toolbar-search /
                     .building-search-input, so the row inherits the existing
                     dark theme, focus ring and <=768px stacking rule. -->
                <div class="building-toolbar building-cascade-toolbar" id="buildingCascadeToolbar">
                    <div class="building-toolbar-search">
                        <label class="building-sort-label building-cascade-label" for="buildingSearchInput">Search Building</label>
                        <input type="search" id="buildingSearchInput" class="form-control building-search-input" list="buildingFilterOptions" placeholder="Search Building..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" aria-label="Search buildings">
                        <datalist id="buildingFilterOptions"></datalist>
                    </div>
                    <div class="building-toolbar-search">
                        <label class="building-sort-label building-cascade-label" for="floorFilterInput">Search Floor</label>
                        <input type="search" id="floorFilterInput" class="form-control building-search-input" list="floorFilterOptions" placeholder="Search Floor..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" aria-label="Search floors in the selected building" disabled>
                        <datalist id="floorFilterOptions"></datalist>
                    </div>
                    <div class="building-toolbar-search">
                        <label class="building-sort-label building-cascade-label" for="roomFilterInput">Search Room</label>
                        <input type="search" id="roomFilterInput" class="form-control building-search-input" list="roomFilterOptions" placeholder="Search Room..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" aria-label="Search rooms on the selected floor" disabled>
                        <datalist id="roomFilterOptions"></datalist>
                    </div>
                </div>
                <p class="building-cascade-hint" id="buildingCascadeHint">Building &rarr; Floor &rarr; Room. Choose a building to unlock the floor filter, then a floor to unlock the room filter &mdash; without leaving this page.</p>
                <div class="building-toolbar">
                    <div class="building-toolbar-controls">
                        <label class="building-sort-label" for="buildingSortSelect">Sort by</label>
                        <select id="buildingSortSelect" class="form-control building-sort-select">
                            <option value="name_asc">Building Name (A&ndash;Z)</option>
                            <option value="name_desc">Building Name (Z&ndash;A)</option>
                            <option value="rooms_desc">Most Rooms</option>
                            <option value="floors_desc">Most Floors</option>
                            <option value="items_desc">Most Deployed Items</option>
                        </select>
                        <div class="building-view-toggle" role="group" aria-label="Switch layout">
                            <button type="button" id="buildingViewGrid" class="building-view-btn is-active" data-view="grid" aria-pressed="true">Grid</button>
                            <button type="button" id="buildingViewList" class="building-view-btn" data-view="list" aria-pressed="false">List</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TASK 101B — the floor-level-only search box that used to sit here
                 was removed. It could only be reached by first opening a
                 building, which is exactly the drill-down requirement the Dean
                 rejected. The Floor filter now lives permanently in the toolbar
                 above. The room box below is kept because it is the drill-down
                 level's own control and is reused for the item search. -->
            <div id="roomSearchWrap" class="room-search-wrap" hidden>
                <div class="room-search-toolbar">
                    <input type="search" id="roomSearchInput" class="form-control room-search-input" placeholder="Search rooms..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <div class="room-category-filters" id="roomCategoryFilters" aria-label="Filter rooms by category">
                        <button type="button" class="room-category-filter is-active" data-room-category-filter="all">All</button>
                        <button type="button" class="room-category-filter" data-room-category-filter="Office">Office</button>
                        <button type="button" class="room-category-filter" data-room-category-filter="Classroom">Classroom</button>
                        <button type="button" class="room-category-filter" data-room-category-filter="Labs">Labs</button>
                        <button type="button" class="room-category-filter" data-room-category-filter="Utility">Utility</button>
                    </div>
                </div>
            </div>


            <div id="overviewContainer" class="summary-cards-grid">
                <!-- Cards will render here -->
            </div>
        </div>
    </div>
</main>

<!-- Modals for adding floors and items -->
<div id="floorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Floor</h2>
            <span class="modal-close" onclick="closeFloorModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="floorForm">
                <div class="form-group">
                    <label for="floorNameInput">Floor Name *</label>
                    <input type="text" id="floorNameInput" class="form-control" placeholder="e.g., 1st Floor" required>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeFloorModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveFloorData()">Save Floor</button>
        </div>
    </div>
</div>

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
                    <textarea id="buildingDescInput" class="form-control" rows="3" placeholder="Optional description"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeBuildingModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveBuildingData()">Save Building</button>
        </div>
    </div>
</div>

<div id="editBuildingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Building</h2>
            <span class="modal-close" onclick="closeEditBuildingModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="editBuildingForm">
                <div class="form-group">
                    <label for="editBuildingNameInput">Building Name *</label>
                    <input type="text" id="editBuildingNameInput" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editBuildingDescInput">Description</label>
                    <textarea id="editBuildingDescInput" class="form-control" rows="3"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeEditBuildingModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditBuilding()">Save Changes</button>
        </div>
    </div>
</div>

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
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeRoomModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveRoomData()">Save Room</button>
        </div>
    </div>
</div>

<div id="editRoomModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Room</h2>
            <span class="modal-close" onclick="closeEditRoomModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="editRoomForm">
                <div class="form-group">
                    <label for="editRoomNameInput">Room Name / Number *</label>
                    <input type="text" id="editRoomNameInput" class="form-control" required>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeEditRoomModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditRoom()">Save Changes</button>
        </div>
    </div>
</div>

<div id="itemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Record Existing Item</h2>
            <span class="modal-close" onclick="closeItemModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p class="existing-item-note" style="margin:0 0 16px;padding:10px 12px;border-left:3px solid #7c3aed;border-radius:8px;background:rgba(124,58,237,0.08);font-size:13px;line-height:1.5;">
                Use this only for equipment that was already in this room before the system was used.
                New items should be sent through <strong>Dispatch Items Here</strong> so they are taken
                from Inventory and approved.
            </p>
            <form id="itemForm">
                <div class="form-group">
                    <label for="itemNameInput">Item Name *</label>
                    <input type="text" id="itemNameInput" class="form-control" required>
                </div>
                <!-- Status field removed (2026-09-27): an item placed in a room
                     is in use, and the API derives the status from the
                     quantity anyway (ItemController::store()), so the choice
                     was never saved. -->
                <div class="form-group">
                    <label for="itemQuantityInput">Quantity</label>
                    <input type="number" id="itemQuantityInput" class="form-control" min="1" value="1">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeItemModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveItemData()">Record Item</button>
        </div>
    </div>
</div>

    <div id="printFilterModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Print Deployed Items</h2>
                <span class="modal-close" onclick="closePrintFilterModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="printFilterForm">
                    <div class="form-group">
                        <label for="printBuildingSelect">Building</label>
                        <select id="printBuildingSelect" class="form-control" onchange="onPrintBuildingChange()">
                            <option value="">All Buildings</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="printRoomSelect">Room</label>
                        <select id="printRoomSelect" class="form-control">
                            <option value="">All Rooms</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closePrintFilterModal()">Cancel</button>
                <button class="btn btn-primary" onclick="previewPrintItems()">Preview Print</button>
            </div>
        </div>
    </div>

    <div id="printPreviewContainer" class="print-preview-page" hidden>
        <div class="print-preview-sheet">
            <div class="print-preview-header">
                <div class="print-preview-branding">
                    <img src="<?php echo htmlspecialchars(public_url('/frontend/assets/images/logo-seal.svg')); ?>" alt="PHILCST Logo" class="print-preview-logo" onerror="this.style.display='none'" />
                    <div>
                        <div class="print-preview-school">PHILCST Centralized School Facility Maintenance Report Management System</div>
                        <div class="print-preview-title">Deployed Items Inventory Report</div>
                    </div>
                </div>
                <div class="print-preview-meta">
                    <div><strong>Building:</strong> <span id="printBuildingLabel">All Buildings</span></div>
                    <div><strong>Room:</strong> <span id="printRoomLabel">All Rooms</span></div>
                    <div><strong>Date Generated:</strong> <span id="printDateLabel"></span></div>
                </div>
            </div>
            <div id="printPreviewBody" class="print-preview-body"></div>
            <div class="print-preview-footer">
                <div class="print-preview-summary">Total Items: <span id="printTotalItems">0</span></div>
                <div class="print-preview-signatures">
                    <div>Prepared by: _______________</div>
                    <div>Noted by: _______________</div>
                    <div>Date: _______________</div>
                </div>
            </div>
            <div class="print-preview-actions screen-only">
                <button class="btn btn-primary" onclick="window.print()">Print</button>
                <button class="btn btn-secondary" onclick="closePrintPreview()">Back</button>
            </div>
        </div>
    </div>
</div>

<script>
// TASK 35 — Buildings Overview RBAC (frontend UI only). The actual security
// boundary is the existing EnsureRole:'super_admin' middleware already
// enforced server-side on the mutating buildings/floors/rooms routes (see
// routes/web.php) — this flag only controls whether mutation controls are
// rendered/enabled in the UI, mirroring what the backend already rejects.
// Centralized here (instead of scattering per-control role checks) and
// reused by every Add/Edit/Delete control below, including the dynamically
// rendered building/room cards. Reuses the existing role value already
// exposed on <body data-user-role> (includes/header.php) — no new role or
// permission system is introduced.
const userRole = document.body.dataset.userRole || '';
const canModifyBuildings = userRole === 'super_admin';

// TASK 46 — Buildings Overview redesign state. These are ordinary page-level
// vars (like the pre-existing implicit globals currentLevel/buildingsCache
// below) that back the new stat cards + search/sort/view-toggle toolbar at
// the building list level only. Nothing here changes API calls' shape or
// the floor/room/item drill-down data flow.
let buildingsRawCache = [];   // unfiltered/unsorted buildings array from /api/buildings
let buildingItemCounts = {};  // { [buildingId]: totalDeployedItemQuantity }, from deployed-items
let buildingsSearchTerm = '';
let buildingsSortValue = 'name_asc';
let buildingsViewMode = 'grid';

// Drill-down floor cache: filled by loadFloors() from
// /api/buildings/{currentBuildingId}/floors.
let floorsRawCache = [];

// TASK 101B — state for the persistent cascading Building -> Floor -> Room
// filters on the OUTER Buildings Overview view. These are deliberately separate
// from the currentBuildingId/currentFloorId drill-down vars: the filters narrow
// what the outer view shows without navigating into another level, so the two
// concepts must not share state.
//
// filterFloorsCache only ever holds /api/buildings/{filterBuildingId}/floors
// (WHERE f.building_id = ?) and filterRoomsCache only ever holds
// /api/rooms?building_id=..&floor_id=.. (both applied as WHERE clauses by
// RoomController::index). The scoping is therefore the server's, not a client
// filter's — a child list physically cannot contain a row of another parent.
let filterBuildingId = null;
let filterBuildingName = '';
let filterFloorId = null;
let filterFloorName = '';
let filterFloorsCache = [];
let filterRoomsCache = [];
let floorFilterTerm = '';
let roomFilterTerm = '';

function extractList(payload, key) {
    if (!payload || payload.success !== true) {
        return [];
    }

    if (Array.isArray(payload[key])) {
        return payload[key];
    }

    if (payload.data && Array.isArray(payload.data[key])) {
        return payload.data[key];
    }

    // TASK 42A — ItemController::index() (unlike Building/RoomController)
    // returns Laravel's raw paginator shape: { data: { data: [...],
    // current_page, ... } }, not a named "items" key. Without this fallback,
    // loadItems()'s extractList(data, 'items') call always returned [],
    // silently showing "No items found" for every room even when real
    // room_asset rows existed (discovered via a live test deploy). Buildings/
    // Rooms/Floors already return a named key above and never reach here.
    if (payload.data && Array.isArray(payload.data.data)) {
        return payload.data.data;
    }

    return [];
}

// TASK 55 — optional 3rd `icon` param adds a muted icon above the title for
// visual consistency across all 4 empty-state contexts (Buildings/Floors/
// Rooms/Items). New classes (overview-empty-card/-content/-icon/-title/-desc)
// are added ALONGSIDE the existing .summary-card/-content/-title/-desc
// classes rather than replacing them, so this cannot affect Item cards or
// any other consumer of those shared classes (Part 7 protection).
function renderEmptyOverview(title, hint = '', icon = '') {
    const container = document.getElementById('overviewContainer');
    container.innerHTML = '';

    const card = document.createElement('div');
    card.className = 'summary-card overview-empty-card';
    card.style.cursor = 'default';

    const content = document.createElement('div');
    content.className = 'summary-card-content overview-empty-content';

    // TASK 7 — `icon` is now a name from the shared icon registry rather than
    // an emoji character, so this assigns our own generated SVG (never user
    // input) instead of textContent. .ui-icon-empty gives the large, muted,
    // illustration-weight treatment these empty states already had.
    if (icon && window.UIIcons) {
        const markup = window.UIIcons.svg(icon, { className: 'ui-icon-empty' });
        if (markup) {
            const iconEl = document.createElement('div');
            iconEl.className = 'overview-empty-icon';
            iconEl.setAttribute('aria-hidden', 'true');
            iconEl.innerHTML = markup;
            content.appendChild(iconEl);
        }
    }

    const titleEl = document.createElement('h3');
    titleEl.className = 'summary-card-title overview-empty-title';
    titleEl.textContent = title;
    content.appendChild(titleEl);

    if (hint) {
        const hintEl = document.createElement('p');
        hintEl.className = 'summary-card-desc overview-empty-desc';
        hintEl.textContent = hint;
        content.appendChild(hintEl);
    }

    card.appendChild(content);
    container.appendChild(card);
}

async function initOverview() {
    await loadBuildings();
    // TASK 18 — a 'building' notification (Building Updated) lands here via
    // ?highlight=<building_id>. loadBuildings() is the default landing view
    // (currentLevel = 'building'), so the card is already rendered — just
    // find and highlight it, mirroring highlightUserFromQuery() in users.php.
    highlightBuildingFromQuery();
}

function highlightBuildingFromQuery() {
    const params = new URLSearchParams(window.location.search);
    const highlightId = Number(params.get('highlight') || 0);
    if (!highlightId) return;

    // TASK 46 — building cards are now .building-card (see createBuildingCard()),
    // not the generic .summary-card used by floor/room/item entities.
    const card = document.querySelector(`.building-card[data-type="building"][data-id="${highlightId}"]`);
    if (!card) return;

    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.classList.add('summary-card-highlight');
    setTimeout(() => card.classList.remove('summary-card-highlight'), 3000);
}

// TASK 46 — shows/hides the stat cards + search/sort/view-toggle toolbar,
// which only make sense at the top-level "Buildings" list, not while
// drilled into a floor/room/item view.
function setBuildingChromeVisibility(isVisible) {
    const stats = document.getElementById('buildingsStatsSection');
    const toolbar = document.getElementById('buildingToolbarWrap');
    if (stats) stats.hidden = !isVisible;
    if (toolbar) toolbar.hidden = !isVisible;
}

// TASK 46 — reuses the existing /api/buildings/deployed-items endpoint
// (already wired for "Print Deployed Items") with no filters to get a
// system-wide deployed-items total plus a per-building breakdown, without
// adding any new backend endpoint or touching deployment logic.
async function loadDeployedItemsSummary() {
    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/buildings/deployed-items'), {
            credentials: 'include'
        });
        const data = await res.json();
        if (!data.success) return { total: 0, byBuilding: {} };

        const items = extractList(data, 'items');
        let total = 0;
        const byBuilding = {};
        items.forEach((item) => {
            const qty = Number(item.quantity) || 0;
            total += qty;
            const buildingId = item.building_id;
            if (buildingId) {
                byBuilding[buildingId] = (byBuilding[buildingId] || 0) + qty;
            }
        });
        return { total, byBuilding };
    } catch (err) {
        console.error('Error loading deployed items summary', err);
        return { total: 0, byBuilding: {} };
    }
}

// TASK 46 — reuses the existing /api/dashboard/stats endpoint (already
// powering the main dashboard's "Pending" KPI) for the Pending Requests
// stat card. That endpoint's `pending` figure is semester-scoped and can be
// null when no semester is currently active (see DashboardController::stats);
// in that case the card shows an em dash instead of a fabricated number.
async function loadPendingRequestsCount() {
    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/dashboard/stats'), {
            credentials: 'include'
        });
        const data = await res.json();
        if (!data.success) return null;
        const payload = data.data || {};
        return typeof payload.pending === 'number' ? payload.pending : null;
    } catch (err) {
        console.error('Error loading pending requests count', err);
        return null;
    }
}

function renderBuildingStats(buildings, deployedTotal, pendingCount) {
    const totalBuildings = buildings.length;
    const totalFloors = buildings.reduce((sum, b) => sum + (Number(b.floor_count) || 0), 0);
    const totalRooms = buildings.reduce((sum, b) => sum + (Number(b.room_count) || 0), 0);

    const setStat = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    setStat('statTotalBuildings', String(totalBuildings));
    setStat('statTotalFloors', String(totalFloors));
    setStat('statTotalRooms', String(totalRooms));
    setStat('statDeployedItems', String(deployedTotal || 0));
    setStat('statPendingRequests', pendingCount === null ? '—' : String(pendingCount));
}

function getSortedFilteredBuildings() {
    const term = String(buildingsSearchTerm || '').trim().toLowerCase();
    const filtered = buildingsRawCache.filter((b) => !term || String(b.name || '').toLowerCase().includes(term));

    const sorted = filtered.slice();
    switch (buildingsSortValue) {
        case 'name_desc':
            sorted.sort((a, b) => String(b.name || '').localeCompare(String(a.name || '')));
            break;
        case 'rooms_desc':
            sorted.sort((a, b) => (Number(b.room_count) || 0) - (Number(a.room_count) || 0));
            break;
        case 'floors_desc':
            sorted.sort((a, b) => (Number(b.floor_count) || 0) - (Number(a.floor_count) || 0));
            break;
        case 'items_desc':
            sorted.sort((a, b) => (buildingItemCounts[b.id] || 0) - (buildingItemCounts[a.id] || 0));
            break;
        case 'name_asc':
        default:
            sorted.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
            break;
    }
    return sorted;
}

function renderBuildingCards() {
    const container = document.getElementById('overviewContainer');
    if (!container) return;

    container.innerHTML = '';
    container.className = buildingsViewMode === 'list' ? 'building-cards-list' : 'building-cards-grid';

    const buildings = getSortedFilteredBuildings();

    if (buildings.length === 0) {
        const hasSearch = Boolean(String(buildingsSearchTerm || '').trim());
        renderEmptyOverview(
            hasSearch ? 'No buildings matched your search' : 'No buildings found',
            hasSearch ? 'Try another building name or clear the search.' : 'Create a building first to view floors and rooms.',
            'building'
        );
        return;
    }

    buildings.forEach((b) => {
        const card = createBuildingCard(b, buildingItemCounts[b.id] || 0);
        container.appendChild(card);
    });
}

// TASK 46 — compact professional building card: icon, name, Floors/Rooms/
// Items stat row, and an explicit "View Details" action, replacing the
// generic createEntityCard() rendering previously used for buildings.
// loadFloors(id, name) — the existing drill-down call — is unchanged; it is
// just now triggered from a dedicated button as well as the card itself.
function createBuildingCard(building, itemCount) {
    const card = document.createElement('div');
    card.className = 'building-card';
    card.dataset.type = 'building';
    card.dataset.id = building.id;

    const top = document.createElement('div');
    top.className = 'building-card-top';

    // TASK 66 — dedicated building-card illustration asset (separate from
    // the Dashboard's Buildings Overview widget image in dashboard.php).
    const icon = document.createElement('div');
    icon.className = 'building-card-icon';
    const illustration = document.createElement('img');
    illustration.className = 'building-card-illustration';
    illustration.src = '/School_Facility_Maintenance_System/frontend/assets/images/buildings-illustration.png';
    illustration.alt = '';
    illustration.setAttribute('aria-hidden', 'true');
    icon.appendChild(illustration);
    top.appendChild(icon);

    const name = document.createElement('h3');
    name.className = 'building-card-name';
    name.textContent = building.name || 'Unnamed Building';
    top.appendChild(name);

    // TASK 35 — Edit Building trigger restricted to Administrator
    // (super_admin) only, matching the narrowed PATCH /api/buildings/{id}
    // middleware (routes/web.php). Head Maintenance and Maintenance Staff
    // no longer see this control.
    if (canModifyBuildings) {
        const menuBtn = document.createElement('button');
        menuBtn.type = 'button';
        menuBtn.className = 'building-card-menu-btn';
        menuBtn.setAttribute('aria-label', 'Edit ' + (building.name || 'building'));
        menuBtn.title = 'Edit building';
        menuBtn.textContent = '⋮';
        menuBtn.onclick = (event) => {
            event.stopPropagation();
            openEditBuildingModal(building.id);
        };
        top.appendChild(menuBtn);
    }

    card.appendChild(top);

    const stats = document.createElement('div');
    stats.className = 'building-card-stats';

    const statBlock = (value, label) => {
        const block = document.createElement('div');
        block.className = 'building-card-stat';
        const valueEl = document.createElement('span');
        valueEl.className = 'building-card-stat-value';
        valueEl.textContent = String(value);
        const labelEl = document.createElement('span');
        labelEl.className = 'building-card-stat-label';
        labelEl.textContent = label;
        block.appendChild(valueEl);
        block.appendChild(labelEl);
        return block;
    };

    stats.appendChild(statBlock(Number(building.floor_count) || 0, 'Floors'));
    stats.appendChild(statBlock(Number(building.room_count) || 0, 'Rooms'));
    stats.appendChild(statBlock(itemCount, 'Items'));
    card.appendChild(stats);

    const viewBtn = document.createElement('button');
    viewBtn.type = 'button';
    viewBtn.className = 'building-card-view-btn';
    viewBtn.innerHTML = 'View Details <span aria-hidden="true">' + (window.UIIcons ? window.UIIcons.svg('arrow-right', { size: 14 }) : '') + '</span>';
    viewBtn.onclick = (event) => {
        event.stopPropagation();
        loadFloors(building.id, building.name);
    };
    card.appendChild(viewBtn);

    card.onclick = () => loadFloors(building.id, building.name);

    return card;
}

function clearActions() {
    document.getElementById('overviewActions').innerHTML = '';
}

function updateRoomCategoryFilterUI() {
    document.querySelectorAll('[data-room-category-filter]').forEach((button) => {
        const isActive = (button.getAttribute('data-room-category-filter') || 'all') === roomCategoryFilter;
        button.classList.toggle('is-active', isActive);
    });
}

function setRoomSearchVisibility(isVisible, placeholder = 'Search rooms...', showCategoryFilters = false) {
    const wrap = document.getElementById('roomSearchWrap');
    const input = document.getElementById('roomSearchInput');
    const filterWrap = document.getElementById('roomCategoryFilters');

    if (!wrap || !input) return;

    wrap.hidden = !isVisible;
    input.placeholder = placeholder;
    if (filterWrap) {
        filterWrap.hidden = !isVisible || !showCategoryFilters;
    }
    // Always synchronize the DOM input value with the current search state on every
    // level change (not just when hiding), so stale text typed at a previous level
    // (e.g. Room search) doesn't linger visually when returning to a sibling level
    // (e.g. Items -> Rooms) even though the underlying state was already reset.
    input.value = '';
    roomSearchTerm = '';
    roomCategoryFilter = 'all';
    updateRoomCategoryFilterUI();
    itemSearchTerm = '';
}

// TASK 101B — single place that turns a list of floors into cards, shared by the
// persistent Floor filter on the outer view and by the drill-down floor level,
// so both paths render identically.
function renderFloorCardsInto(floors, emptyTitle, emptyHint, onSelect) {
    const container = document.getElementById('overviewContainer');
    if (!container) return;

    container.innerHTML = '';
    container.className = 'floors-grid';

    if (!floors.length) {
        renderEmptyOverview(emptyTitle, emptyHint, 'folder');
        return;
    }

    floors.forEach((floor) => {
        const card = createFloorCard(floor);
        card.onclick = () => onSelect(floor);
        container.appendChild(card);
    });
}

function renderFloorsOverview() {
    renderFloorCardsInto(
        Array.isArray(floorsRawCache) ? floorsRawCache : [],
        'No floors found',
        'Add a floor for this building.',
        (floor) => loadRooms(floor.id, floor.name)
    );
}

// ---------------------------------------------------------------------------
// TASK 101B — persistent cascading Building -> Floor -> Room filters.
//
// Every function below runs only on the OUTER Buildings Overview view
// (currentLevel === 'building'). None of them calls loadFloors() or loadRooms(),
// which are the drill-down navigation entry points — that is precisely what
// keeps the user on the same view while all three controls stay visible.
// ---------------------------------------------------------------------------

function setCascadeOptions(datalistId, rows) {
    const list = document.getElementById(datalistId);
    if (!list) return;

    list.innerHTML = '';
    rows.forEach((row) => {
        const option = document.createElement('option');
        option.value = String(row.name || '');
        list.appendChild(option);
    });
}

// An exact (case-insensitive) name match is what counts as "selected". Anything
// else is treated as a narrowing term only, which is why a half-typed building
// leaves the Floor control disabled instead of guessing a parent.
function matchCascadeRowByName(rows, value) {
    const needle = String(value || '').trim().toLowerCase();
    if (!needle) return null;

    return (Array.isArray(rows) ? rows : []).find(
        (row) => String(row.name || '').trim().toLowerCase() === needle
    ) || null;
}

function resetRoomFilter() {
    filterRoomsCache = [];
    roomFilterTerm = '';

    const input = document.getElementById('roomFilterInput');
    if (input) {
        input.value = '';
        input.disabled = true;
    }

    setCascadeOptions('roomFilterOptions', []);
}

function resetFloorFilter() {
    filterFloorId = null;
    filterFloorName = '';
    filterFloorsCache = [];
    floorFilterTerm = '';

    const input = document.getElementById('floorFilterInput');
    if (input) {
        input.value = '';
        input.disabled = true;
    }

    setCascadeOptions('floorFilterOptions', []);

    // A building change invalidates the room context underneath it as well.
    resetRoomFilter();
}

function resetCascadeFilters() {
    filterBuildingId = null;
    filterBuildingName = '';
    buildingsSearchTerm = '';

    const input = document.getElementById('buildingSearchInput');
    if (input) {
        input.value = '';
    }

    resetFloorFilter();
}

async function loadFloorFilterOptions() {
    if (!filterBuildingId) return;

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL(`/api/buildings/${encodeURIComponent(filterBuildingId)}/floors`), {
            credentials: 'include'
        });
        if (!res.ok) return;

        const data = await res.json();
        if (!data.success) return;

        filterFloorsCache = extractList(data, 'floors');
        setCascadeOptions('floorFilterOptions', filterFloorsCache);

        const input = document.getElementById('floorFilterInput');
        if (input) {
            input.disabled = filterFloorsCache.length === 0;
        }
    } catch (err) {
        console.error('Error loading floor filter options', err);
    }
}

async function loadRoomFilterOptions() {
    if (!filterBuildingId || !filterFloorId) return;

    try {
        // Scoped by BOTH ids so the room options are constrained server-side to
        // the selected building AND the selected floor.
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/rooms?building_id=' + encodeURIComponent(filterBuildingId) + '&floor_id=' + encodeURIComponent(filterFloorId) + '&with_item_counts=1'), {
            credentials: 'include'
        });
        if (!res.ok) return;

        const data = await res.json();
        if (!data.success) return;

        filterRoomsCache = extractList(data, 'rooms');
        setCascadeOptions('roomFilterOptions', filterRoomsCache);

        const input = document.getElementById('roomFilterInput');
        if (input) {
            input.disabled = filterRoomsCache.length === 0;
        }
    } catch (err) {
        console.error('Error loading room filter options', err);
    }
}

async function onBuildingFilterChanged(rawValue) {
    buildingsSearchTerm = String(rawValue || '').trim().toLowerCase();

    const match = matchCascadeRowByName(buildingsRawCache, rawValue);
    const nextBuildingId = match ? match.id : null;

    if (nextBuildingId !== filterBuildingId) {
        // Cascade rule: the building changed, so the floor and room context
        // beneath it is stale and is cleared before anything new is fetched.
        filterBuildingId = nextBuildingId;
        filterBuildingName = match ? String(match.name || '') : '';
        resetFloorFilter();

        if (filterBuildingId) {
            await loadFloorFilterOptions();
        }
    }

    renderCascadeResults();
}

async function onFloorFilterChanged(rawValue) {
    // The Floor filter cannot operate without a parent building.
    if (!filterBuildingId) return;

    floorFilterTerm = String(rawValue || '').trim().toLowerCase();

    const match = matchCascadeRowByName(filterFloorsCache, rawValue);
    const nextFloorId = match ? match.id : null;

    if (nextFloorId !== filterFloorId) {
        // Cascade rule: the floor changed, so the room context is stale.
        filterFloorId = nextFloorId;
        filterFloorName = match ? String(match.name || '') : '';
        resetRoomFilter();

        if (filterFloorId) {
            await loadRoomFilterOptions();
        }
    }

    renderCascadeResults();
}

function onRoomFilterChanged(rawValue) {
    // The Room filter cannot operate without a parent floor.
    if (!filterFloorId) return;

    roomFilterTerm = String(rawValue || '').trim().toLowerCase();
    renderCascadeResults();
}

function getCascadeFloors() {
    const term = String(floorFilterTerm || '').trim();
    const floors = Array.isArray(filterFloorsCache) ? filterFloorsCache : [];

    if (!term) return floors;

    return floors.filter((floor) => {
        const haystack = [floor.name, floor.description]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return haystack.includes(term);
    });
}

function getCascadeRooms() {
    const term = String(roomFilterTerm || '').trim();
    const rooms = Array.isArray(filterRoomsCache) ? filterRoomsCache : [];

    if (!term) return rooms;

    return rooms.filter((room) => {
        const haystack = [room.name, room.code, room.room_number, room.description]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return haystack.includes(term);
    });
}

// The outer view shows the deepest level the filters have resolved: buildings
// until a building is chosen, that building's floors until a floor is chosen,
// then that floor's rooms. The user reaches all three without navigating.
function renderCascadeResults() {
    if (currentLevel !== 'building') return;

    if (filterFloorId) {
        const container = document.getElementById('overviewContainer');
        if (!container) return;

        const rooms = getCascadeRooms();
        container.innerHTML = '';
        container.className = 'summary-cards-grid';

        if (!rooms.length) {
            renderEmptyOverview('No rooms matched your filters', 'Try another room name, or clear the room filter.', 'door');
            return;
        }

        rooms.forEach((room) => container.appendChild(createRoomCard(room)));
        return;
    }

    if (filterBuildingId) {
        renderFloorCardsInto(
            getCascadeFloors(),
            'No floors matched your filters',
            'Try another floor name, or clear the floor filter.',
            (floor) => {
                // Opening a floor is still available as a shortcut, but it is
                // never required to use the Room filter above.
                currentBuildingId = filterBuildingId;
                currentBuildingName = filterBuildingName;
                loadRooms(floor.id, floor.name);
            }
        );
        return;
    }

    renderBuildingCards();
}

function getFilteredRooms() {
    const term = String(roomSearchTerm || '').trim().toLowerCase();
    const categoryFilter = String(roomCategoryFilter || 'all');
    const rooms = Array.isArray(currentRoomsCache) ? currentRoomsCache : [];

    return rooms.filter((room) => {
        const roomCategory = getRoomCategory(room.name);
        const categoryMatches = categoryFilter === 'all' || roomCategory === categoryFilter;

        if (!categoryMatches) {
            return false;
        }

        const haystack = [room.name, room.code, room.description, room.room_number]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return !term || haystack.includes(term);
    });
}

function getFilteredItems() {
    const term = String(itemSearchTerm || '').trim().toLowerCase();

    if (!term) {
        return Array.isArray(currentItemsCache) ? currentItemsCache : [];
    }

    return (Array.isArray(currentItemsCache) ? currentItemsCache : []).filter((item) => {
        // TASK 53 — room-item search previously only matched name/status/
        // description/quantity, so it could not find a physical unit by its
        // asset_code (e.g. "AI-R101-02"), brand, model, or condition — the
        // exact identifiers printed on the card itself. Uses the item's
        // existing API fields as-is (no frontend-generated codes).
        const haystack = [item.name, item.asset_code, item.brand, item.model, item.item_condition, item.status, item.description, item.quantity]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return haystack.includes(term);
    });
}

function addActionButton(text, onClick, variant = 'secondary') {
    const btn = document.createElement('button');
    btn.className = `btn btn-${variant}`;
    btn.textContent = text;
    btn.onclick = onClick;
    document.getElementById('overviewActions').appendChild(btn);
}

async function openPrintFilterModal() {
    const buildingSelect = document.getElementById('printBuildingSelect');
    const roomSelect = document.getElementById('printRoomSelect');

    buildingSelect.innerHTML = '<option value="">All Buildings</option>';
    roomSelect.innerHTML = '<option value="">All Rooms</option>';

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/buildings'), {
            credentials: 'include'
        });
        const data = await res.json();
        if (data.success) {
            const buildings = extractList(data, 'buildings');
            buildings.forEach((building) => {
                const option = document.createElement('option');
                option.value = building.id;
                option.textContent = building.name;
                buildingSelect.appendChild(option);
            });

            if (currentBuildingId) {
                buildingSelect.value = String(currentBuildingId);
                await onPrintBuildingChange();
                if (currentRoomId) {
                    roomSelect.value = String(currentRoomId);
                }
            }
        } else {
            Components.alert(data.message || 'Failed to load buildings', 'danger');
        }
    } catch (err) {
        console.error('Error loading buildings for print filter', err);
        Components.alert('Error loading buildings for print filter. Check console for details.', 'danger');
    }

    document.getElementById('printFilterModal').classList.add('show');
}

function closePrintFilterModal() {
    document.getElementById('printFilterModal').classList.remove('show');
    document.getElementById('printFilterForm').reset();
}

async function onPrintBuildingChange() {
    const buildingId = document.getElementById('printBuildingSelect').value;
    const roomSelect = document.getElementById('printRoomSelect');
    roomSelect.innerHTML = '<option value="">All Rooms</option>';

    if (!buildingId) {
        return;
    }

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/rooms?building_id=' + encodeURIComponent(buildingId) + '&per_page=200'), {
            credentials: 'include'
        });
        const data = await res.json();
        if (data.success) {
            const rooms = extractList(data, 'rooms');
            rooms.forEach((room) => {
                const option = document.createElement('option');
                option.value = room.id;
                option.textContent = room.name;
                roomSelect.appendChild(option);
            });
        } else {
            Components.alert(data.message || 'Failed to load rooms', 'danger');
        }
    } catch (err) {
        console.error('Error loading rooms for print filter', err);
        Components.alert('Error loading rooms for print filter. Check console for details.', 'danger');
    }
}

function closePrintPreview() {
    const preview = document.getElementById('printPreviewContainer');
    if (preview) {
        preview.hidden = true;
    }
}

async function previewPrintItems() {
    closePrintFilterModal();

    const buildingSelect = document.getElementById('printBuildingSelect');
    const roomSelect = document.getElementById('printRoomSelect');
    const buildingId = buildingSelect.value;
    const roomId = roomSelect.value;
    const buildingName = buildingId ? buildingSelect.options[buildingSelect.selectedIndex].text : 'All Buildings';
    const roomName = roomId ? roomSelect.options[roomSelect.selectedIndex].text : 'All Rooms';
    const previewBody = document.getElementById('printPreviewBody');

    document.getElementById('printBuildingLabel').textContent = buildingName;
    document.getElementById('printRoomLabel').textContent = roomName;
    document.getElementById('printDateLabel').textContent = new Date().toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    document.getElementById('printPreviewContainer').hidden = false;
    previewBody.innerHTML = '<div class="print-preview-loading">Loading deployed items…</div>';

    try {
        const queryParams = [];
        if (buildingId) {
            queryParams.push('building_id=' + encodeURIComponent(buildingId));
        }
        if (roomId) {
            queryParams.push('room_id=' + encodeURIComponent(roomId));
        }
        const queryString = queryParams.length ? '?' + queryParams.join('&') : '';
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/buildings/deployed-items' + queryString), {
            credentials: 'include'
        });
        const data = await res.json();
        if (data.success) {
            const items = extractList(data, 'items');
            renderPrintPreview(items);
        } else {
            Components.alert(data.message || 'Failed to load deployed items', 'danger');
            previewBody.innerHTML = '<div class="print-preview-empty">Unable to load deployed items.</div>';
            document.getElementById('printTotalItems').textContent = '0';
        }
    } catch (err) {
        console.error('Error loading deployed items', err);
        Components.alert('Error loading deployed items. Check console for details.', 'danger');
        previewBody.innerHTML = '<div class="print-preview-empty">Error loading deployed items.</div>';
        document.getElementById('printTotalItems').textContent = '0';
    }
}

function renderPrintPreview(items) {
    const previewBody = document.getElementById('printPreviewBody');
    if (!previewBody) {
        return;
    }

    if (!Array.isArray(items) || items.length === 0) {
        previewBody.innerHTML = '<div class="print-preview-empty">No deployed items found for the selected scope.</div>';
        document.getElementById('printTotalItems').textContent = '0';
        return;
    }

    previewBody.innerHTML = '';
    const showGrouped = document.getElementById('printRoomSelect').value === '';

    if (showGrouped) {
        const grouped = items.reduce((map, item) => {
            const roomName = item.room_name || 'Unassigned Room';
            if (!map[roomName]) {
                map[roomName] = [];
            }
            map[roomName].push(item);
            return map;
        }, {});

        Object.entries(grouped).forEach(([roomName, roomItems]) => {
            const group = document.createElement('div');
            group.className = 'print-room-group';

            const heading = document.createElement('div');
            heading.className = 'print-room-heading';
            heading.textContent = 'Room: ' + roomName;
            group.appendChild(heading);

            group.appendChild(createPrintTable(roomItems));

            const subtotal = document.createElement('div');
            subtotal.className = 'print-room-subtotal';
            subtotal.textContent = 'Subtotal: ' + roomItems.length + ' item' + (roomItems.length === 1 ? '' : 's');
            group.appendChild(subtotal);

            previewBody.appendChild(group);
        });
    } else {
        previewBody.appendChild(createPrintTable(items));
    }

    document.getElementById('printTotalItems').textContent = String(items.length);
}

function createPrintTable(items) {
    const table = document.createElement('table');
    table.className = 'print-preview-table';

    const thead = document.createElement('thead');
    thead.innerHTML = '<tr><th>#</th><th>Item Name</th><th>Category</th><th>Quantity</th><th>Unit</th><th>Condition</th><th>Date Dispatched</th><th>Dispatch Code</th></tr>';
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    items.forEach((item, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${escapeHtml(item.item_name)}</td>
            <td>${escapeHtml(item.category || '')}</td>
            <td>${escapeHtml(item.quantity)}</td>
            <td>${escapeHtml(item.unit || '')}</td>
            <td>${escapeHtml(item.condition || '')}</td>
            <td>${escapeHtml(formatPrintDate(item.date_dispatched))}</td>
            <td>${escapeHtml(item.dispatch_code || '')}</td>
        `;
        tbody.appendChild(row);
    });

    table.appendChild(tbody);
    return table;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatPrintDate(value) {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

async function loadBuildings() {
    currentLevel = 'building';
    currentBuildingId = null; currentBuildingName = '';
    currentFloorId = null; currentFloorName = '';
    currentRoomId = null;
    currentRoomsCache = [];
    currentItemsCache = [];
    currentDeployedItemsCache = [];
    roomSearchTerm = '';
    itemSearchTerm = '';
    setRoomSearchVisibility(false, 'Search rooms...', false);
    // TASK 101B — a (re)load of the outer view starts from an empty cascade: no
    // building is resolved, so the Floor and Room controls are cleared, emptied
    // of options and disabled again.
    floorsRawCache = [];
    resetCascadeFilters();
    buildingsCache = {};
    document.getElementById('overviewTitle').textContent = 'Buildings';
    document.getElementById('overviewSubtitle').textContent = 'Select a building to view floors';
    clearActions();
    // TASK 35 — Administrator (super_admin) only; Head Maintenance and
    // Maintenance Staff are view-only for buildings (enforced server-side by
    // EnsureRole:'super_admin' on POST /api/buildings, see routes/web.php).
    if (canModifyBuildings) {
        addActionButton('Add Building', () => openBuildingModal(), 'primary');
    }
    // replaced refresh with a back button that navigates to previous page
    addActionButton('Back', () => {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            // Role-aware fallback when page is opened directly.
            window.location.href = defaultBackUrl;
        }
    });
    setBuildingChromeVisibility(true);

    const container = document.getElementById('overviewContainer');
    container.innerHTML = '';
    container.className = 'building-cards-grid';

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/buildings'), {
            credentials: 'include'
        });
        const data = await res.json();
        if (data.success) {
            const buildings = extractList(data, 'buildings');
            buildingsRawCache = buildings;
            buildings.forEach(b => { buildingsCache[b.id] = b; });

            if (buildings.length === 0) {
                setBuildingChromeVisibility(false);
                renderEmptyOverview('No buildings found', 'Create a building first to view floors and rooms.', 'building');
                return;
            }

            // TASK 46 — Deployed Items and Pending Requests stat cards reuse
            // existing endpoints (deployed-items, dashboard/stats); fetched
            // alongside so the building cards' per-building item counts and
            // the summary row populate together.
            const [deployedSummary, pendingCount] = await Promise.all([
                loadDeployedItemsSummary(),
                loadPendingRequestsCount(),
            ]);
            buildingItemCounts = deployedSummary.byBuilding;

            renderBuildingStats(buildings, deployedSummary.total, pendingCount);
            // TASK 101B — the Building control's option list is built from the
            // same response the cards are, then the view renders through the
            // cascade so the results always reflect the current filter depth.
            setCascadeOptions('buildingFilterOptions', buildings);
            renderCascadeResults();
        } else {
            Components.alert(data.message || 'Failed to load buildings', 'danger');
        }
    } catch (err) {
        console.error('Error loading buildings', err);
        Components.alert('Error loading buildings. Check console for details.', 'danger');
    }
}

async function loadFloors(buildingId, buildingName) {
    console.log('loadFloors called', {buildingId, buildingName});
    currentLevel = 'floor'; currentBuildingId = buildingId; currentBuildingName = buildingName;
    currentFloorId = null; currentFloorName = '';
    currentRoomId = null;
    currentRoomsCache = [];
    currentItemsCache = [];
    currentDeployedItemsCache = [];
    roomSearchTerm = '';
    itemSearchTerm = '';
    setRoomSearchVisibility(false, 'Search rooms...', false);
    // TASK 101B — entering the floor level (including re-entering it for a
    // DIFFERENT building) always starts from an empty floor cache, so no floor
    // of the previously selected building can survive the change. The floor
    // SEARCH is no longer reset here because it no longer lives at this level —
    // it is one of the persistent toolbar filters on the outer view.
    floorsRawCache = [];
    document.getElementById('overviewTitle').textContent = 'Floors of ' + buildingName;
    document.getElementById('overviewSubtitle').textContent = 'Select a floor to view rooms';
    clearActions();
    // TASK 35 — Administrator (super_admin) only; matches the narrowed
    // POST /api/buildings/{id}/floors middleware (routes/web.php).
    if (canModifyBuildings) {
        addActionButton('Add Floor', () => openFloorModal());
    }
    addActionButton('Back', loadBuildings);
    setBuildingChromeVisibility(false);

    const container = document.getElementById('overviewContainer');
    container.innerHTML = '';
    container.className = 'floors-grid';

    try {
        const res = await fetch(window.SFMS_PUBLIC_URL(`/api/buildings/${encodeURIComponent(buildingId)}/floors`), {
            credentials: 'include'
        });
        if (!res.ok) {
            const txt = await res.text();
            console.error('Floor API responded with non-OK status', res.status, txt);
            Components.alert('Error loading floors: ' + (txt || res.statusText), 'danger');
            return;
        }
        const data = await res.json();
        if (data.success) {
            // TASK 101 — cache the building-scoped response so the Floor search
            // can re-filter it without refetching, exactly as the building level
            // already does with buildingsRawCache.
            floorsRawCache = extractList(data, 'floors');
            renderFloorsOverview();
        } else {
            Components.alert(data.message || 'Failed to load floors', 'danger');
        }
    } catch (err) {
        console.error('Error loading floors', err);
        Components.alert('Error loading floors. Check console for details.', 'danger');
    }
}

async function loadRooms(floorId, floorName) {
    currentLevel = 'room'; currentFloorId = floorId; currentFloorName = floorName; currentRoomId = null;
    currentRoomsCache = [];
    currentItemsCache = [];
    currentDeployedItemsCache = [];
    roomSearchTerm = '';
    roomCategoryFilter = 'all';
    itemSearchTerm = '';
    document.getElementById('overviewTitle').textContent = 'Rooms of ' + floorName;
    document.getElementById('overviewSubtitle').textContent = 'Select a room to view items';
    clearActions();
    // TASK 35 — Administrator (super_admin) only; matches the narrowed
    // POST /api/rooms middleware (routes/web.php).
    if (canModifyBuildings) {
        addActionButton('Add New Room', () => openRoomModal(currentBuildingId, currentFloorId), 'primary');
    }
    addActionButton('Back', () => loadFloors(currentBuildingId, currentBuildingName));
    setRoomSearchVisibility(true, 'Search rooms...', true);
    setBuildingChromeVisibility(false);

    const container = document.getElementById('overviewContainer');
    container.innerHTML = '';
    container.className = 'summary-cards-grid';

    try {
        // TASK 101 — scope the room request by BOTH building and floor. floor_id
        // alone already implied the building (floors.building_id), but sending
        // building_id makes the Building AND Floor constraint explicit
        // server-side (RoomController::index applies both as WHERE clauses), so
        // a room from another building or another floor cannot be returned.
        const res = await fetch(window.SFMS_PUBLIC_URL('/api/rooms?building_id=' + encodeURIComponent(currentBuildingId) + '&floor_id=' + encodeURIComponent(floorId) + '&with_item_counts=1'), {
            credentials: 'include'
        });
        const data = await res.json();
        if (data.success) {
            const rooms = extractList(data, 'rooms');
            currentRoomsCache = rooms;

            renderRoomsOverview();

            if (rooms.length === 0) {
                renderEmptyOverview('No rooms found', 'Add a room for this floor.', 'door');
                return;
            }
        } else {
            Components.alert(data.message || 'Failed to load rooms', 'danger');
        }
    } catch (err) {
        console.error('Error loading rooms', err);
        Components.alert('Error loading rooms. Check console for details.', 'danger');
    }
}

function setRoomCategoryFilter(filter) {
    roomCategoryFilter = filter || 'all';
    updateRoomCategoryFilterUI();

    if (currentLevel === 'room') {
        renderRoomsOverview();
    }
}

function getRoomCategory(roomName) {
    const name = String(roomName || '').toLowerCase();
    if (name.includes('lab') || name.includes('laboratory') || name.includes('science lab') || name.includes('computer lab')) {
        return 'Labs';
    }
    if (name.includes('utility') || name.includes('storage') || name.includes('stock') || name.includes('maintenance') || name.includes('janitor') || name.includes('restroom') || name.includes('comfort room')) {
        return 'Utility';
    }
    if (name.includes('office') || name.includes('college') || name.includes('faculty') || name.includes('principal') || name.includes('president') || name.includes('dean') || name.includes('department')) {
        return 'Office';
    }
    return 'Classroom';
}

function getRoomIcon(roomName, category) {
    const name = String(roomName || '').toLowerCase();
    
    if (category === 'Office') {
        if (name.includes('computer') || name.includes('information') || name.includes('system')) {
            return 'monitor';
        }
        if (name.includes('principal') || name.includes('president')) {
            return 'users';
        }
        if (name.includes('research') || name.includes('graduate') || name.includes('studies')) {
            return 'book';
        }
        if (name.includes('assistant') || name.includes('secretary')) {
            return 'clipboard';
        }
        if (name.includes('college') || name.includes('faculty')) {
            return 'graduation-cap';
        }
        return 'archive';
    }
    
    // Classroom
    return 'book';
}

// TASK 67 — room-card kebab menu (Edit Room / Delete Room). No dropdown-menu
// component existed anywhere in this codebase, so this is new but reuses
// .building-card-menu-btn styling for the trigger and the existing
// UI.systemConfirm/Components.alert/toast primitives for the actions
// themselves (see openEditRoomModal/saveEditRoom and deleteEntity('room', ...)
// below). Only one dropdown is ever open at a time (openRoomCardMenuWrap).
let openRoomCardMenuWrap = null;

function closeRoomCardMenu() {
    if (openRoomCardMenuWrap) {
        openRoomCardMenuWrap.classList.remove('open');
        openRoomCardMenuWrap = null;
    }
}

document.addEventListener('click', closeRoomCardMenu);

function createRoomCardMenu(room) {
    const wrap = document.createElement('div');
    wrap.className = 'room-card-menu-wrap';

    const menuBtn = document.createElement('button');
    menuBtn.type = 'button';
    menuBtn.className = 'room-card-menu-btn building-card-menu-btn';
    menuBtn.setAttribute('aria-label', 'Room options for ' + (room.name || 'room'));
    menuBtn.title = 'Room options';
    menuBtn.textContent = '⋮';
    menuBtn.onclick = (event) => {
        event.stopPropagation();
        const wasOpen = wrap.classList.contains('open');
        closeRoomCardMenu();
        if (!wasOpen) {
            wrap.classList.add('open');
            openRoomCardMenuWrap = wrap;
        }
    };
    wrap.appendChild(menuBtn);

    const dropdown = document.createElement('div');
    dropdown.className = 'room-card-menu-dropdown';

    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'room-card-menu-item';
    editBtn.textContent = 'Edit Room';
    editBtn.onclick = (event) => {
        event.stopPropagation();
        closeRoomCardMenu();
        openEditRoomModal(room.id);
    };
    dropdown.appendChild(editBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'room-card-menu-item room-card-menu-item-danger';
    deleteBtn.textContent = 'Delete Room';
    deleteBtn.onclick = (event) => {
        event.stopPropagation();
        closeRoomCardMenu();
        deleteEntity('room', room.id);
    };
    dropdown.appendChild(deleteBtn);

    wrap.appendChild(dropdown);
    return wrap;
}

// TASK 55 — dedicated room-card component, mirroring createBuildingCard()'s
// structure (icon / name / context / stat row / View Details button) instead
// of the generic createEntityCard()+.summary-card used previously. Reuses
// getRoomCategory()/getRoomIcon() unchanged; item_count comes from the
// already-existing opt-in ?with_item_counts=1 param on /api/rooms (no
// backend change). createEntityCard() itself is left intact/unused so
// nothing else depends on this change.
function createRoomCard(room) {
    const category = getRoomCategory(room.name);
    const icon = getRoomIcon(room.name, category);

    const card = document.createElement('div');
    card.className = 'room-card';
    card.dataset.type = 'room';
    card.dataset.id = room.id;

    // TASK 69 — header row (icon / name / ⋮ menu) mirrors createBuildingCard()'s
    // .building-card-top layout so Room Cards share Building Card's visual
    // hierarchy. Pure DOM reparenting: no data, onclick, or gating logic changes.
    const top = document.createElement('div');
    top.className = 'room-card-top';

    // TASK 7 — getRoomIcon() now returns a registry name, so render the SVG
    // instead of writing an emoji character. The room's own name sits beside
    // it, so the icon is decorative.
    const iconEl = document.createElement('div');
    iconEl.className = 'room-card-icon';
    iconEl.setAttribute('aria-hidden', 'true');
    iconEl.innerHTML = window.UIIcons ? window.UIIcons.svg(icon, { size: 20 }) : '';
    top.appendChild(iconEl);

    const name = document.createElement('h3');
    name.className = 'room-card-name';
    name.textContent = room.name || 'Unnamed Room';
    top.appendChild(name);

    // TASK 35 — Edit/Delete kebab menu restricted to Administrator
    // (super_admin) only, matching the narrowed PATCH/DELETE /api/rooms/{id}
    // middleware (routes/web.php). Previously also shown to maintenance_admin
    // (Head Maintenance), which is now view-only.
    if (canModifyBuildings) {
        top.appendChild(createRoomCardMenu(room));
    }

    card.appendChild(top);

    if (room.floor_name) {
        const context = document.createElement('p');
        context.className = 'room-card-context';
        context.textContent = room.floor_name;
        card.appendChild(context);
    }

    const stats = document.createElement('div');
    stats.className = 'room-card-stats building-card-stats';

    const statBlock = (value, label) => {
        const block = document.createElement('div');
        block.className = 'building-card-stat';
        const valueEl = document.createElement('span');
        valueEl.className = 'building-card-stat-value';
        valueEl.textContent = String(value);
        const labelEl = document.createElement('span');
        labelEl.className = 'building-card-stat-label';
        labelEl.textContent = label;
        block.appendChild(valueEl);
        block.appendChild(labelEl);
        return block;
    };

    // TASK 69 — Category now renders before Items to match the target
    // hierarchy (category is more identifying context than the item count).
    const categoryBlock = document.createElement('div');
    categoryBlock.className = 'building-card-stat';
    const categoryValue = document.createElement('span');
    categoryValue.className = 'building-card-stat-value room-card-category-value';
    categoryValue.textContent = category;
    const categoryLabel = document.createElement('span');
    categoryLabel.className = 'building-card-stat-label';
    categoryLabel.textContent = 'Category';
    categoryBlock.appendChild(categoryValue);
    categoryBlock.appendChild(categoryLabel);
    stats.appendChild(categoryBlock);

    stats.appendChild(statBlock(Number(room.item_count) || 0, 'Items'));

    card.appendChild(stats);

    const viewBtn = document.createElement('button');
    viewBtn.type = 'button';
    viewBtn.className = 'building-card-view-btn';
    viewBtn.innerHTML = 'View Details <span aria-hidden="true">' + (window.UIIcons ? window.UIIcons.svg('arrow-right', { size: 14 }) : '') + '</span>';
    viewBtn.onclick = (event) => {
        event.stopPropagation();
        loadItems(room.id, room.name);
    };
    card.appendChild(viewBtn);

    card.onclick = () => loadItems(room.id, room.name);

    return card;
}

function renderRoomsOverview() {
    const container = document.getElementById('overviewContainer');
    if (!container) return;

    const rooms = getFilteredRooms();
    const hasActiveRoomFilter = String(roomCategoryFilter || 'all') !== 'all';

    if (!rooms.length) {
        const hasSearchTerm = Boolean(String(roomSearchTerm || '').trim());
        renderEmptyOverview(
            hasSearchTerm || hasActiveRoomFilter ? 'No rooms matched your filters' : 'No rooms found',
            hasSearchTerm || hasActiveRoomFilter ? 'Try another room name, switch category, or clear the filters.' : 'Add a room for this floor.',
            'door'
        );
        return;
    }

    container.innerHTML = '';
    rooms.forEach((room) => {
        const card = createRoomCard(room);
        container.appendChild(card);
    });
}

async function loadItems(roomId, roomName) {
    currentLevel = 'items'; currentRoomId = roomId;
    currentItemsCache = [];
    currentDeployedItemsCache = [];
    roomSearchTerm = '';
    itemSearchTerm = '';
    setRoomSearchVisibility(true, 'Search items...', false);
    setBuildingChromeVisibility(false);
    document.getElementById('overviewTitle').textContent = 'Items in ' + roomName;
    document.getElementById('overviewSubtitle').textContent = 'Search items in this room';
    clearActions();
    // New items reach a room through Dispatch (Inventory -> approval ->
    // release), so that is the primary action. "Record Existing Item" is only
    // for equipment already in the room before the system was used. Both are
    // Head/Administrator actions (the same roles the API allows).
    if (['super_admin', 'maintenance_admin'].includes(userRole)) {
        addActionButton('Dispatch Items Here', () => {
            const url = window.SFMS_PUBLIC_URL('/frontend/pages/dispatch-create.php')
                + '?room_id=' + encodeURIComponent(roomId)
                + '&room_name=' + encodeURIComponent(roomName || '');
            window.location.href = url;
        }, 'primary');
        addActionButton('Record Existing Item', () => openItemModal());
    }
    addActionButton('Back', () => loadRooms(currentFloorId, currentFloorName));

    const container = document.getElementById('overviewContainer');
    container.innerHTML = '';

    // TASK 77 — this room's contents come from two independent sources (see
    // DeployedItemsQuery's class comment for the full explanation):
    //   - /api/items?room_id=      room_asset rows: equipment recorded
    //     directly via "Record Existing Item" / the Add Item modal's
    //     "Already Deployed In Room" option. items.room_id is ONLY ever set
    //     for these.
    //   - /api/buildings/deployed-items?room_id=   items that arrived
    //     through Dispatch -> Approve -> Release. Releasing a dispatch never
    //     writes items.room_id (one stock item can be split across many
    //     dispatches/rooms), so these never showed up here before even
    //     though Buildings Overview's own building/floor/room item COUNTS
    //     already counted them (see BuildingController::deployedItems()).
    // Fetched in parallel; each renders as its own card type below since a
    // dispatched batch isn't a single physical unit with its own
    // asset_code/brand/model/status the way a room_asset row is.
    try {
        const [roomAssetRes, deployedRes] = await Promise.all([
            fetch(window.SFMS_PUBLIC_URL('/api/items?room_id=' + roomId), { credentials: 'same-origin' }),
            fetch(window.SFMS_PUBLIC_URL('/api/buildings/deployed-items?room_id=' + roomId), { credentials: 'same-origin' }),
        ]);
        const roomAssetData = await roomAssetRes.json();
        const deployedData = await deployedRes.json();

        if (roomAssetData.success) {
            currentItemsCache = extractList(roomAssetData, 'items');
        } else {
            Components.alert(roomAssetData.message || 'Failed to load items', 'danger');
        }

        if (deployedData.success) {
            currentDeployedItemsCache = extractList(deployedData, 'items');
        }
        // Deployed-items failure is not surfaced as a blocking alert — the
        // room_asset list above is still usable on its own, same as before
        // this task; the section below just stays empty in that case.

        renderItemsOverview();

        if (currentItemsCache.length === 0 && currentDeployedItemsCache.length === 0) {
            renderEmptyOverview('No items found', 'Add an item for this room, or dispatch items here.', 'package');
        }
    } catch (err) {
        console.error('Error loading items', err);
        Components.alert('Error loading items. Check console for details.', 'danger');
    }
}

// TASK 77 — the room-item search filter (itemSearchTerm) now applies to
// BOTH sources shown on this view: room_asset cards (getFilteredItems(),
// unchanged) and dispatch-deployed items (this one). Mirrors the same
// haystack idea, using the deployed-items API's own field names (item_name/
// category/dispatch_code, not name/asset_code — see
// BuildingController::deployedItems()'s response shape).
function getFilteredDeployedItems() {
    const term = String(itemSearchTerm || '').trim().toLowerCase();

    if (!term) {
        return Array.isArray(currentDeployedItemsCache) ? currentDeployedItemsCache : [];
    }

    return (Array.isArray(currentDeployedItemsCache) ? currentDeployedItemsCache : []).filter((item) => {
        const haystack = [item.item_name, item.category, item.dispatch_code, item.condition, item.quantity]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');
        return haystack.includes(term);
    });
}

function renderItemsOverview() {
    const container = document.getElementById('overviewContainer');
    if (!container) return;

    const items = getFilteredItems();
    const deployedItems = getFilteredDeployedItems();

    if (!items.length && !deployedItems.length) {
        renderEmptyOverview(itemSearchTerm ? 'No items matched your search' : 'No items found', itemSearchTerm ? 'Try another item name or clear the search.' : 'Add an item for this room, or dispatch items here.', 'package');
        return;
    }

    container.innerHTML = '';

    items.forEach((item) => {
        const card = createItemCard(item);
        container.appendChild(card);
    });

    // TASK 77 — items that reached this room through Dispatch -> Approve ->
    // Release render as their own, non-deletable card type: unlike a
    // room_asset row, a dispatched batch is not a single physical unit with
    // its own asset_code/brand/model/status (it's a quantity pulled from a
    // shared stock item), and "deleting" it here would have no matching API
    // (there is no undo for a released dispatch — see Deployment Tracking /
    // the Dispatch cancel/verify flows for the actual lifecycle actions).
    if (deployedItems.length) {
        const sectionHeading = document.createElement('h3');
        sectionHeading.className = 'items-section-heading';
        sectionHeading.textContent = 'Deployed via Dispatch';
        container.appendChild(sectionHeading);

        deployedItems.forEach((item) => {
            const card = createDeployedItemCard(item);
            container.appendChild(card);
        });
    }
}

// TASK 77 — read-only card for an item that arrived via a released dispatch
// (see BuildingController::deployedItems() for the exact fields available:
// item_name, category, quantity, unit, condition, dispatch_code,
// date_dispatched). Deliberately reuses the same .item-asset-card shell as
// createItemCard() above so both sections look like one family, but drops
// the delete control and the asset_code/brand/model/status fields that only
// make sense for a room_asset row.
function createDeployedItemCard(item) {
    const div = document.createElement('div');
    div.className = 'item-asset-card';
    div.dataset.type = 'deployed-item';

    const header = document.createElement('div');
    header.className = 'item-asset-card-header';

    const iconEl = document.createElement('div');
    iconEl.className = 'item-asset-card-icon';
    iconEl.setAttribute('aria-hidden', 'true');
    iconEl.innerHTML = window.UIIcons ? window.UIIcons.svg('package', { size: 20 }) : '';
    header.appendChild(iconEl);

    const titleEl = document.createElement('h3');
    titleEl.className = 'item-asset-card-name';
    titleEl.textContent = item.item_name || 'Unnamed Item';
    header.appendChild(titleEl);

    div.appendChild(header);

    // Condition dropped from this card (previously always rendered "Not
    // specified" — a dispatched batch has no per-unit condition to report,
    // unlike a room_asset row, so the field was pure filler on every card).
    // Dispatch Code now gets the same emphasized monospace-chip treatment as
    // Asset Code on createItemCard() above, reusing .item-detail-value-code /
    // .item-detail-row-code so both card families read as one design
    // language instead of Dispatch Code sitting in a plain half-width cell.
    const detailField = (label, rawValue, emphasize = false) => {
        const row = document.createElement('div');
        row.className = 'item-detail-row';
        const labelEl = document.createElement('span');
        labelEl.className = 'item-detail-label';
        labelEl.textContent = label;
        const valueEl = document.createElement('span');
        valueEl.className = 'item-detail-value';
        const text = (rawValue === null || rawValue === undefined || String(rawValue).trim() === '')
            ? 'Not specified'
            : String(rawValue);
        valueEl.textContent = text;
        if (text === 'Not specified') {
            valueEl.classList.add('is-empty');
        } else if (emphasize) {
            valueEl.classList.add('item-detail-value-code');
            row.classList.add('item-detail-row-code');
        }
        row.appendChild(labelEl);
        row.appendChild(valueEl);
        return row;
    };

    const detailGrid = document.createElement('div');
    detailGrid.className = 'item-detail-grid';
    detailGrid.appendChild(detailField('Quantity', item.quantity));
    detailGrid.appendChild(detailField('Category', item.category));
    detailGrid.appendChild(detailField('Date Deployed', item.date_dispatched ? boFormatDateOnly(item.date_dispatched) : null));
    detailGrid.appendChild(detailField('Dispatch Code', item.dispatch_code, true));
    div.appendChild(detailGrid);

    return div;
}

// TASK 77 — small local date formatter so this file doesn't reach into
// dispatch-create.php's boFormatDateOnly() (different page, not loaded
// here). Matches the same "MMM D, YYYY" style used elsewhere in this file's
// date displays.
function boFormatDateOnly(value) {
    if (!value) return null;
    const d = new Date(value);
    if (isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// TASK 42A — room-level item cards surface each room_asset row's individual
// fields (asset_code, brand, model, item_condition, status) instead of the
// collapsed "Qty/Status" summary. Each row here is one physical unit
// deployed via InventoryStockController::deploy() (TASK 37.2 split deploys
// into per-unit room_asset rows), so collapsing them lost exactly the detail
// a technician needs on-site. CSS/JS-only — /api/items?room_id= already
// returns these columns (see app/Models/Item.php $fillable); no new endpoint.
// TASK 59 — Item/room-asset cards previously rode on the generic KPI-style
// .summary-card shell (fixed 280px width, hardcoded purple border, flex-row
// icon layout, clickable hover-lift) which visually orphaned them from the
// Building → Floor → Room card family (.building-card/.floor-card/.room-card:
// 18px radius, var(--border), var(--buildings-surface), neutral shadow).
// This card is also the only one of the four that is NOT a drill-down
// target, so it gets its own non-clickable shell (no cursor:pointer, no
// hover-lift) while reusing the exact same token vocabulary as its siblings.
function createItemCard(item) {
    const div = document.createElement('div');
    div.className = 'item-asset-card';
    div.dataset.type = 'item';
    div.dataset.id = item.id;

    const header = document.createElement('div');
    header.className = 'item-asset-card-header';

    // TASK 7 — was the 📦 emoji. The item's name renders beside it, so the
    // icon is decorative.
    const iconEl = document.createElement('div');
    iconEl.className = 'item-asset-card-icon';
    iconEl.setAttribute('aria-hidden', 'true');
    iconEl.innerHTML = window.UIIcons ? window.UIIcons.svg('package', { size: 20 }) : '';
    header.appendChild(iconEl);

    const titleEl = document.createElement('h3');
    titleEl.className = 'item-asset-card-name';
    titleEl.textContent = item.name || 'Unnamed Item';
    header.appendChild(titleEl);

    div.appendChild(header);

    // TASK 52 — Asset Code is the field that uniquely identifies this physical
    // unit on-site, but it previously rendered with the exact same label/value
    // styling as Brand/Model/Condition/Status, so it had no visual priority
    // over "Not specified" filler text on the same card. The optional
    // `emphasize` flag lets just that one row opt into a distinct chip
    // treatment (see .item-detail-value-code in buildings-redesign.css) while
    // every other field keeps its existing, unchanged appearance.
    const detailField = (label, rawValue, emphasize = false) => {
        const row = document.createElement('div');
        row.className = 'item-detail-row';
        const labelEl = document.createElement('span');
        labelEl.className = 'item-detail-label';
        labelEl.textContent = label;
        const valueEl = document.createElement('span');
        valueEl.className = 'item-detail-value';
        const text = (rawValue === null || rawValue === undefined || String(rawValue).trim() === '')
            ? 'Not specified'
            : String(rawValue);
        valueEl.textContent = text;
        if (text === 'Not specified') valueEl.classList.add('is-empty');
        else if (emphasize) {
            valueEl.classList.add('item-detail-value-code');
            // Full-width row: the 2-column grid slot (~110px) is too narrow
            // for a monospace chip and was wrapping codes like "AI-R101-01"
            // mid-word onto two lines. Asset Code is also the field meant to
            // stand out most, so giving it its own full-width row doubles as
            // the emphasis this task asked for.
            row.classList.add('item-detail-row-code');
        }
        row.appendChild(labelEl);
        row.appendChild(valueEl);
        return row;
    };

    const detailGrid = document.createElement('div');
    detailGrid.className = 'item-detail-grid';
    detailGrid.appendChild(detailField('Asset Code', item.asset_code, true));
    detailGrid.appendChild(detailField('Brand', item.brand));
    detailGrid.appendChild(detailField('Model', item.model));
    detailGrid.appendChild(detailField('Condition', item.item_condition ? String(item.item_condition).replace(/_/g, ' ') : null));
    detailGrid.appendChild(detailField('Status', item.status ? String(item.status).replace(/_/g, ' ') : null));
    div.appendChild(detailGrid);

    // TASK 58 (Fix 3) — .card-delete keeps its existing absolute top-right
    // positioning/handler/API call/confirmation flow, unchanged. It floats
    // over the card shell independently of the header row above.
    // TASK 59 (Part 9) — element changed from <div> to <button type="button">
    // (same class, same onclick, same stopPropagation/deleteEntity call) so
    // it is natively keyboard-focusable and :focus-visible can be styled;
    // no new interaction pattern, just a real focusable control for the
    // click target that already existed.
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'card-delete';
    del.title = 'Delete';
    del.setAttribute('aria-label', 'Delete');
    // TASK 7 — was the 🗑️ emoji. This is an icon-only control, so the
    // accessible name comes from the aria-label/title above (both kept) and
    // the SVG itself stays decorative.
    del.innerHTML = window.UIIcons ? window.UIIcons.svg('trash', { size: 15 }) : '';
    del.onclick = (event) => {
        event.stopPropagation();
        deleteEntity('item', item.id);
    };
    div.appendChild(del);

    return div;
}

function createEntityCard(title, subtitle = '', icon = '', type = null, id = null) {
    const div = document.createElement('div');
    div.className = 'summary-card summary-card-action';
    div.style.cursor = 'pointer';
    if (type && id) {
        div.dataset.type = type;
        div.dataset.id = id;
    }
    const content = document.createElement('div');
    content.className = 'summary-card-content';
    const titleEl = document.createElement('h3');
    titleEl.className = 'summary-card-title';
    titleEl.textContent = title;
    content.appendChild(titleEl);
    if (subtitle) {
        const isCategoryBadge = ['Office', 'Classroom', 'Labs', 'Utility'].includes(subtitle);
        if (isCategoryBadge) {
            const badge = document.createElement('span');
            badge.className = 'summary-card-badge';
            badge.textContent = subtitle;
            content.appendChild(badge);
        } else {
            const desc = document.createElement('p');
            desc.className = 'summary-card-desc';
            desc.textContent = subtitle;
            content.appendChild(desc);
        }
    }
    div.appendChild(content);
    // TASK 7 — this function is currently unused (kept intact by TASK 69), but
    // it is aligned with the icon registry anyway so a future caller cannot
    // quietly reintroduce an emoji glyph here.
    if (icon && window.UIIcons) {
        const markup = window.UIIcons.svg(icon, { size: 20 });
        if (markup) {
            const iconEl = document.createElement('div');
            iconEl.className = 'summary-card-icon';
            iconEl.setAttribute('aria-hidden', 'true');
            iconEl.innerHTML = markup;
            div.appendChild(iconEl);
        }
    }
    if (type && id) {
        // TASK 35 — defensive gating for consistency with the live Add/Edit
        // Building controls above; this function is not currently invoked
        // anywhere in this file (confirmed unreachable), but the same
        // super_admin-only rule is applied here too rather than leaving an
        // unguarded building-mutation trigger in the codebase.
        if (type === 'building' && canModifyBuildings) {
            const actions = document.createElement('div');
            actions.className = 'card-actions';
            const editBtn = document.createElement('button');
            editBtn.className = 'btn btn-secondary';
            editBtn.textContent = 'Edit';
            editBtn.onclick = (event) => {
                event.stopPropagation();
                openEditBuildingModal(id);
            };
            actions.appendChild(editBtn);
            div.appendChild(actions);
        } else if (type === 'item') {
            const del = document.createElement('div');
            del.className = 'card-delete';
            del.title = 'Delete';
            // TASK 7 — was the 🗑️ emoji. Icon-only control, so the existing
            // title is joined by an explicit aria-label for the accessible
            // name, and the SVG itself stays decorative. (This branch lives in
            // the currently-unused createEntityCard(); aligned for consistency.)
            del.setAttribute('aria-label', 'Delete');
            del.innerHTML = window.UIIcons ? window.UIIcons.svg('trash', { size: 15 }) : '';
            del.onclick = (event) => {
                event.stopPropagation();
                deleteEntity(type, id);
            };
            div.appendChild(del);
        }
    }
    return div;
}

function createFloorCard(floor) {
    const div = document.createElement('div');
    div.className = 'floor-card';
    div.style.cursor = 'pointer';
    div.dataset.type = 'floor';
    div.dataset.id = floor.id;

    // Extract floor number from name (e.g., "1st Floor" -> "1F")
    const floorNumberMatch = floor.name.match(/(\d+)/);
    const floorNumber = floorNumberMatch ? floorNumberMatch[1] + 'F' : '•';

    // Floor badge
    const badge = document.createElement('div');
    badge.className = 'floor-card-badge';
    badge.textContent = floorNumber;
    div.appendChild(badge);

    // Content section
    const content = document.createElement('div');
    content.className = 'floor-card-content';

    const nameEl = document.createElement('h3');
    nameEl.className = 'floor-card-name';
    nameEl.textContent = floor.name;
    content.appendChild(nameEl);

    if (floor.description) {
        const descEl = document.createElement('p');
        descEl.className = 'floor-card-desc';
        descEl.textContent = floor.description;
        content.appendChild(descEl);
    }

    const statsEl = document.createElement('div');
    statsEl.className = 'floor-card-stats';
    statsEl.innerHTML = `
        <span class="floor-stat"><strong>${floor.room_count || 0}</strong> rooms</span>
        <span class="floor-stat"><strong>${floor.item_count || 0}</strong> items tracked</span>
    `;
    content.appendChild(statsEl);

    div.appendChild(content);

    // Arrow button
    const arrow = document.createElement('div');
    arrow.className = 'floor-card-arrow';
    // TASK 7 — was a → text glyph.
    arrow.setAttribute('aria-hidden', 'true');
    arrow.innerHTML = window.UIIcons ? window.UIIcons.svg('arrow-right', { size: 18 }) : '';
    div.appendChild(arrow);

    return div;
}

document.addEventListener('DOMContentLoaded', () => {
    const roomSearchInput = document.getElementById('roomSearchInput');
    if (roomSearchInput) {
        roomSearchInput.addEventListener('input', () => {
            const value = roomSearchInput.value.trim().toLowerCase();

            if (currentLevel === 'room') {
                roomSearchTerm = value;
                renderRoomsOverview();
            } else if (currentLevel === 'items') {
                itemSearchTerm = value;
                renderItemsOverview();
            }
        });
    }

    document.querySelectorAll('[data-room-category-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            setRoomCategoryFilter(button.getAttribute('data-room-category-filter') || 'all');
        });
    });

    // TASK 101B — the three persistent cascading filters, all bound on the outer
    // Buildings Overview view. Each child listener returns early unless its
    // parent has resolved, so the Floor control cannot act without a building
    // and the Room control cannot act without a floor — and neither one requires
    // navigating into a drill-down level to become usable.
    const buildingSearchInput = document.getElementById('buildingSearchInput');
    if (buildingSearchInput) {
        buildingSearchInput.addEventListener('input', () => {
            if (currentLevel !== 'building') return;
            onBuildingFilterChanged(buildingSearchInput.value);
        });
    }

    const floorFilterInput = document.getElementById('floorFilterInput');
    if (floorFilterInput) {
        floorFilterInput.addEventListener('input', () => {
            if (currentLevel !== 'building' || !filterBuildingId) return;
            onFloorFilterChanged(floorFilterInput.value);
        });
    }

    const roomFilterInput = document.getElementById('roomFilterInput');
    if (roomFilterInput) {
        roomFilterInput.addEventListener('input', () => {
            if (currentLevel !== 'building' || !filterFloorId) return;
            onRoomFilterChanged(roomFilterInput.value);
        });
    }

    const buildingSortSelect = document.getElementById('buildingSortSelect');
    if (buildingSortSelect) {
        buildingSortSelect.addEventListener('change', () => {
            buildingsSortValue = buildingSortSelect.value;
            renderCascadeResults();
        });
    }

    document.querySelectorAll('.building-view-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            buildingsViewMode = btn.dataset.view === 'list' ? 'list' : 'grid';
            document.querySelectorAll('.building-view-btn').forEach((otherBtn) => {
                const isActive = otherBtn === btn;
                otherBtn.classList.toggle('is-active', isActive);
                otherBtn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            renderCascadeResults();
        });
    });
});

// Delete helper
async function deleteEntity(type, id) {
    // UI_BROWSER_DIALOG_REPLACEMENT — window.UI is always loaded (see
    // includes/footer.php), so this always goes through the shared modal.
    const confirmed = await UI.systemConfirm('Are you sure you want to delete this ' + type + '?', 'Delete', 'Cancel', 'danger');
    if (!confirmed) return;

    // Item deletes use the Laravel REST endpoint
    if (type === 'item') {
        try {
            const resp = await fetch(window.SFMS_PUBLIC_URL(`/api/items/${id}`), {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            const data = await resp.json();
            if (data.success) {
                loadItems(currentRoomId, '');
            } else {
                Components.alert('Delete failed: ' + data.message, 'danger');
            }
        } catch (e) {
            console.error('Delete error', e);
            Components.alert('An error occurred while deleting. See console.', 'danger');
        }
        return;
    }

    // Building / floor / room deletes — Laravel REST routes
    let deleteUrl = '';
    switch (type) {
        case 'building': deleteUrl = window.SFMS_PUBLIC_URL(`/api/buildings/${id}`); break;
        case 'floor':    deleteUrl = window.SFMS_PUBLIC_URL(`/api/buildings/${currentBuildingId}/floors/${id}`); break;
        case 'room':     deleteUrl = window.SFMS_PUBLIC_URL(`/api/rooms/${id}`); break;
    }
    try {
        const resp = await fetch(deleteUrl, {
            method: 'DELETE',
            credentials: 'same-origin'
        });
        const data = await resp.json();
        if (data.success) {
            switch (currentLevel) {
                case 'building': loadBuildings(); break;
                case 'floor': loadFloors(currentBuildingId, currentBuildingName); break;
                case 'room': loadRooms(currentFloorId, currentFloorName); break;
                case 'items': loadItems(currentRoomId, ''); break;
            }
        } else {
            Components.alert('Delete failed: ' + data.message, 'danger');
        }
    } catch (e) {
        console.error('Delete error', e);
        Components.alert('An error occurred while deleting. See console.', 'danger');
    }
}

function openEditBuildingModal(buildingId) {
    const building = buildingsCache[buildingId];
    if (!building) {
        Components.alert('Building not found. Please refresh the list.', 'danger');
        return;
    }
    currentEditBuildingId = buildingId;
    document.getElementById('editBuildingNameInput').value = building.name || '';
    document.getElementById('editBuildingDescInput').value = building.description || '';
    document.getElementById('editBuildingModal').classList.add('show');
    document.getElementById('editBuildingNameInput').focus();
}

function closeEditBuildingModal() {
    document.getElementById('editBuildingModal').classList.remove('show');
    document.getElementById('editBuildingForm').reset();
    currentEditBuildingId = null;
}

async function saveEditBuilding() {
    const name = document.getElementById('editBuildingNameInput').value.trim();
    const description = document.getElementById('editBuildingDescInput').value.trim();
    if (!currentEditBuildingId) {
        Components.alert('No building selected for edit.', 'warning');
        return;
    }
    if (!name) {
        Components.alert('Please enter building name', 'warning');
        return;
    }
    try {
        const res = await fetch(window.SFMS_PUBLIC_URL(`/api/buildings/${currentEditBuildingId}`), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, description })
        });
        const data = await res.json();
        if (data.success) {
            closeEditBuildingModal();
            loadBuildings();
        } else {
            Components.alert(data.message || 'Failed to update building', 'danger');
        }
    } catch (err) {
        console.error('Error updating building', err);
        Components.alert('Error updating building. Check console for details.', 'danger');
    }
}

// TASK 67 — Edit Room, mirroring openEditBuildingModal/closeEditBuildingModal/
// saveEditBuilding() above. Only the name is editable here (rooms have no
// description field); floor/building reassignment is out of scope per the
// task's stated boundaries. currentRoomsCache (populated by loadRooms()) is
// the source of truth, matching how buildingsCache backs the building flow.
function openEditRoomModal(roomId) {
    const room = currentRoomsCache.find((r) => Number(r.id) === Number(roomId));
    if (!room) {
        Components.alert('Room not found. Please refresh the list.', 'danger');
        return;
    }
    currentEditRoomId = roomId;
    document.getElementById('editRoomNameInput').value = room.name || '';
    document.getElementById('editRoomModal').classList.add('show');
    document.getElementById('editRoomNameInput').focus();
}

function closeEditRoomModal() {
    document.getElementById('editRoomModal').classList.remove('show');
    document.getElementById('editRoomForm').reset();
    currentEditRoomId = null;
}

async function saveEditRoom() {
    const name = document.getElementById('editRoomNameInput').value.trim();
    if (!currentEditRoomId) {
        Components.alert('No room selected for edit.', 'warning');
        return;
    }
    if (!name) {
        Components.alert('Please enter room name', 'warning');
        return;
    }
    try {
        const res = await fetch(window.SFMS_PUBLIC_URL(`/api/rooms/${currentEditRoomId}`), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        });
        const data = await res.json();
        if (data.success) {
            closeEditRoomModal();
            loadRooms(currentFloorId, currentFloorName);
        } else {
            Components.alert(data.message || 'Failed to update room', 'danger');
        }
    } catch (err) {
        console.error('Error updating room', err);
        Components.alert('Error updating room. Check console for details.', 'danger');
    }
}

function openBuildingModal() {
    document.getElementById('buildingModal').classList.add('show');
    document.getElementById('buildingNameInput').focus();
}

function closeBuildingModal() {
    document.getElementById('buildingModal').classList.remove('show');
    document.getElementById('buildingForm').reset();
}

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
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: buildingName, description: buildingDesc })
        });
        const result = await response.json();
        if (result.success) {
            closeBuildingModal();
            loadBuildings();
        } else {
            Components.alert(result.message || 'Failed to add building', 'danger');
        }
    } catch (error) {
        console.error('Error saving building:', error);
        Components.alert('Error saving building. Please try again.', 'danger');
    }
}

// Floor modal functions
function openFloorModal() {
    document.getElementById('floorModal').classList.add('show');
    document.getElementById('floorNameInput').focus();
}
function closeFloorModal() {
    document.getElementById('floorModal').classList.remove('show');
    document.getElementById('floorForm').reset();
}
async function saveFloorData() {
    const name = document.getElementById('floorNameInput').value.trim();
    if (!name) {
        Components.alert('Please enter floor name', 'warning');
        return;
    }
    if (!currentBuildingId) {
        // should never happen but guard just in case
        Components.alert('No building selected. Please go back and choose a building first.', 'warning');
        return;
    }
    console.log('Saving floor', {buildingId: currentBuildingId, buildingName: currentBuildingName, name});
    try {
        const res = await fetch(
            window.SFMS_PUBLIC_URL(`/api/buildings/${currentBuildingId}/floors`),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name })
            }
        );
        if (!res.ok) {
            const txt = await res.text();
            console.error('Floor API responded with non-OK status', res.status, txt);
            Components.alert('Error saving floor (HTTP ' + res.status + '): ' + (txt.substring(0, 100) || res.statusText), 'danger');
            return;
        }
        const data = await res.json();
        if (data.success) {
            closeFloorModal();
            // reload current building's floors using stored name variable
            loadFloors(currentBuildingId, currentBuildingName);
        } else {
            Components.alert(data.message || 'Failed to save floor', 'danger');
        }
    } catch (err) {
        console.error('Error in saveFloorData', err);
        Components.alert('An unexpected error occurred while saving the floor. Details: ' + err.message, 'danger');
    }
}

// Room modal functions
function openRoomModal(defaultBuildingId = '', defaultFloorId = '') {
    document.getElementById('roomModal').classList.add('show');
    loadBuildingsInModal(defaultBuildingId, defaultFloorId);
    document.getElementById('roomNameInput').focus();
}
function closeRoomModal() {
    document.getElementById('roomModal').classList.remove('show');
    document.getElementById('roomForm').reset();
}
function loadBuildingsInModal(preselectBuildingId = '', preselectFloorId = '') {
    const buildingSelect = document.getElementById('roomBuildingSelect');
    const floorSelect = document.getElementById('roomFloorSelect');
    buildingSelect.innerHTML = '<option value="">Choose a building</option>';
    floorSelect.innerHTML = '<option value="">Choose a floor</option>';

    fetch(window.SFMS_PUBLIC_URL('/api/buildings'), {
        credentials: 'same-origin'
    })
        .then(res => res.json())
        .then(data => {
            const buildings = extractList(data, 'buildings');
            if (data.success && buildings.length > 0) {
                buildings.forEach(building => {
                    const option = document.createElement('option');
                    option.value = building.id;
                    option.textContent = building.name;
                    buildingSelect.appendChild(option);
                });

                if (preselectBuildingId) {
                    buildingSelect.value = String(preselectBuildingId);
                    loadFloorsForRoom(preselectBuildingId, preselectFloorId);
                }
            }
        })
        .catch(err => {
            console.error('Error loading buildings for room modal', err);
        });
}
function loadFloorsForRoom(buildingId, preselectFloorId = '') {
    const floorSelect = document.getElementById('roomFloorSelect');
    floorSelect.innerHTML = '<option value="">Choose a floor</option>';
    if (!buildingId) return;
    fetch(window.SFMS_PUBLIC_URL(`/api/buildings/${encodeURIComponent(buildingId)}/floors`), {
        credentials: 'same-origin'
    })
        .then(res => res.json())
        .then(data => {
            const floors = extractList(data, 'floors');
            if (data.success && floors.length > 0) {
                floors.forEach(floor => {
                    const opt = document.createElement('option');
                    opt.value = floor.id;
                    opt.textContent = floor.name;
                    floorSelect.appendChild(opt);
                });

                if (preselectFloorId) {
                    floorSelect.value = String(preselectFloorId);
                }
            }
        })
        .catch(err => {
            console.error('Error loading floors for room modal', err);
        });
}
async function saveRoomData() {
    const buildingId = document.getElementById('roomBuildingSelect').value.trim();
    const floorId = document.getElementById('roomFloorSelect').value.trim();
    const roomName = document.getElementById('roomNameInput').value.trim();

    if (!buildingId) { Components.alert('Please select a building', 'warning'); return; }
    if (!floorId) { Components.alert('Please select a floor', 'warning'); return; }
    if (!roomName) { Components.alert('Please enter a room name/number', 'warning'); return; }

    try {
        const response = await fetch(window.SFMS_PUBLIC_URL('/api/rooms'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                building_id: buildingId,
                floor_id: floorId,
                name: roomName
            })
        });
        const result = await response.json();
        if (result.success) {
            closeRoomModal();
            loadRooms(currentFloorId, currentFloorName);
        } else {
            Components.alert(result.message || 'Failed to add room', 'danger');
        }
    } catch (error) {
        console.error('Error saving room:', error);
        Components.alert('Error saving room. Please try again.', 'danger');
    }
}

// Item modal functions
function openItemModal() {
    document.getElementById('itemModal').classList.add('show');
    document.getElementById('itemNameInput').focus();
}
function closeItemModal() {
    document.getElementById('itemModal').classList.remove('show');
    document.getElementById('itemForm').reset();
}
async function saveItemData() {
    const name = document.getElementById('itemNameInput').value.trim();
    const quantity = document.getElementById('itemQuantityInput').value;
    if (!name) { Components.alert('Please enter item name', 'warning'); return; }
    const res = await fetch(window.SFMS_PUBLIC_URL('/api/items'), {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ room_id: currentRoomId, name, quantity })
    });
    const data = await res.json();
    if (data.success) {
        closeItemModal();
        loadItems(currentRoomId, document.getElementById('overviewTitle').textContent.split(' in ')[1]);
    } else {
        Components.alert(data.message, 'danger');
    }
}

// initialize on load
window.addEventListener('DOMContentLoaded', initOverview);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>





