<?php
/**
 * Inventory Page
 */
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

$isSuperAdmin = (($user['role'] ?? '') === 'super_admin');
$isMaintenanceStaff = (($user['role'] ?? '') === 'maintenance_staff');
$canAddItems = $isSuperAdmin;
$canManageCategories = $isSuperAdmin;
$canCreateInventoryEntries = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin'], true);
$canAdjustStock = in_array(($user['role'] ?? ''), ['super_admin', 'maintenance_admin'], true);

$pageTitle = 'Inventory - SFMS';
include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.css">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/maintenance-dashboard.inline.css?v=20260921-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/inventory.inline.css?v=20260921-2">
<link rel="stylesheet" href="/School_Facility_Maintenance_System/frontend/assets/css/inventory-redesign.css?v=20260926-1">

<main class="container maintenance-admin-dashboard-page inventory-page">
    <div class="card inventory-page-header-card">
        <div class="card-body inventory-page-header-body">
            <div class="page-header inventory-header-row inventory-page-header-row">
                <div>
                    <h1 class="inventory-title">Inventory</h1>
                    <p class="text-muted inventory-subtitle">Centralized stock and reserve equipment only.<?php if ($isMaintenanceStaff): ?> <strong>(Read-only view)</strong><?php endif; ?></p>
                </div>
                <?php /* The three actions are one group. Each keeps its ORIGINAL
                         classes, id, href and label — `btn btn-secondary` on the
                         anchor, `btn btn-primary` on Inventory Entry, the two
                         two "open..." button ids and the $canCreateInventoryEntries /
                         $canAdjustStock role gates are all untouched, so every
                         existing JS binding and permission check still matches.
                         The added `inv-action*` classes are presentation-only
                         modifiers: they carry the visual hierarchy (strong /
                         soft / neutral) without redefining what a .btn is.
                         Icons come from the existing registry via ui_icon()
                         (icons.php is already required by header.php); no new
                         icon system, and each is aria-hidden so the button's
                         accessible name stays exactly its visible text. */ ?>
                <div class="inventory-header-actions">
                    <a href="/School_Facility_Maintenance_System/frontend/pages/replacement-tracking.php" class="btn btn-secondary inv-action inv-action-strong"><?php echo ui_icon('rotate-ccw', ['size' => 16, 'class' => 'inv-action-icon']); ?><span class="inv-action-label">Replacement Tracking</span></a>
                    <?php if ($canCreateInventoryEntries): ?>
                    <button type="button" class="btn btn-primary inv-action inv-action-soft" id="openInventoryEntryButton"><?php echo ui_icon('plus', ['size' => 16, 'class' => 'inv-action-icon']); ?><span class="inv-action-label">Inventory Entry</span></button>
                    <?php endif; ?>
                    <?php if ($canAdjustStock): ?>
                    <button type="button" class="btn btn-secondary inv-action inv-action-neutral" id="openAdjustStockButton"><?php echo ui_icon('edit', ['size' => 16, 'class' => 'inv-action-icon']); ?><span class="inv-action-label">Adjust Stock</span></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card inventory-toolbar-card">
        <div class="card-body inventory-toolbar-body">
            <div class="inventory-toolbar-grid">
                <div class="inventory-toolbar-search">
                    <svg class="inventory-toolbar-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="search" id="inventory-category-search" class="form-control inventory-category-search"
                           placeholder="Search items by name, category, or location..."
                           autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                </div>
                <!-- TASK 6B PHASE 2 — the "Add Inventory Room" button was
                     removed along with the inventory-room browse mode that was
                     its only trigger. Inventory is one centralized pool, so
                     there are no stock locations for a user to manage. -->
            </div>
        </div>
    </div>

    <div class="card inventory-items-card">
        <div class="card-header d-flex justify-between align-center inventory-items-header">
            <div>
                <h3 class="inventory-section-title inventory-items-title">Inventory Items</h3>
                <p class="inventory-items-subtitle">Manage stock levels, locations, and item availability.</p>
            </div>
            <div class="inventory-items-actions">
                <!-- The "+ Add Item" button (and the create-new-item code path
                     in saveAddItems()) was removed by request — brand-new items
                     are now only ever added through Inventory Entry / Purchase
                     Receipt posting. The #addItemsModal itself is kept: it is
                     still the Edit Item modal, opened pre-filled only via
                     startEditInventoryItem() (row kebab menu → Edit). -->
                <?php if ($canManageCategories): ?>
                <button type="button" class="btn btn-secondary" id="manageCategoriesButton">Manage Categories</button>
                <?php endif; ?>
                <span id="inventory-count" class="inventory-count-badge">0 items</span>
            </div>
        </div>
        <div class="card-body inventory-items-body">
            <div class="inventory-items-filter-grid">
                <select id="inventory-items-category-filter" class="form-control">
                    <option value="">All Categories</option>
                </select>
                <select id="inventory-items-status-filter" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="available">In Stock</option>
                    <option value="low_stock">Low Stock</option>
                    <option value="out_of_stock">Out of Stock</option>
                </select>
                <button type="button" class="btn btn-secondary inventory-items-clear-btn" id="inventory-items-clear-filters">Clear Filters</button>
            </div>
            <div id="inventory-container" class="inventory-items-table-shell">
                <div class="ui-empty-state ui-fade-in">
                    <strong>Loading inventory...</strong>
                    <div class="ui-skeleton-list inventory-loading-skeleton">
                        <div class="ui-skeleton-row w-90"></div>
                        <div class="ui-skeleton-row w-75"></div>
                        <div class="ui-skeleton-row w-55"></div>
                    </div>
                </div>
            </div>
            <!-- TASK 31 — mirrors reports.php's #pagination-container: same
                 canonical .pagination classes, populated by
                 renderInventoryItemsPagination(). -->
            <nav id="inventory-pagination-container" class="inventory-pagination pagination" aria-label="Inventory items pagination"></nav>
        </div>
    </div>

    <div class="card inventory-entry-history-card">
        <div class="card-header d-flex justify-between align-center inventory-entry-history-header">
            <div>
                <h3 class="inventory-section-title">Inventory Entry History</h3>
                <p class="text-muted inventory-entry-subtitle">Track receipts, receivers, suppliers, and stock entries added to inventory.</p>
            </div>
            <span id="inventory-entry-count" class="text-muted">0 entries</span>
        </div>
        <div class="card-body">
            <div class="inventory-entry-filters-grid">
                <input type="search" id="inventory-entry-search" class="form-control" placeholder="Search stock entry ID, OR number, supplier, or item..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                <select id="inventory-entry-filter-category" class="form-control">
                    <option value="">All Categories</option>
                </select>
                <select id="inventory-entry-filter-department" class="form-control">
                    <option value="">All Departments</option>
                </select>
                <select id="inventory-entry-filter-room" class="form-control">
                    <option value="">All Laboratories / Rooms</option>
                </select>
                <input type="date" id="inventory-entry-filter-date-from" class="form-control" title="Date received from">
                <input type="date" id="inventory-entry-filter-date-to" class="form-control" title="Date received to">
                <button type="button" class="btn btn-secondary inventory-entry-clear-btn" id="inventory-entry-clear-filters">Clear Filters</button>
            </div>
            <div id="inventory-entry-container">
                <div class="ui-empty-state ui-fade-in">
                    <strong>Loading inventory entries...</strong>
                    <span>Please wait while we collect your stock entry history.</span>
                </div>
            </div>
        </div>
    </div>
</main>

<div id="addItemsModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header">
            <h2 id="addItemsModalTitle">Edit Item</h2>
            <button type="button" class="inventory-modal-close" id="closeAddItemsModalButton" aria-label="Close">&times;</button>
        </div>
        <div class="inventory-modal-body">
            <div id="addItemsModalMessage" class="inventory-modal-message" hidden></div>
            <form id="addItemsForm">
                <input type="hidden" id="inventoryItemIdInput" value="">
                <div class="inventory-form-grid">
                    <!-- The "Inventory Room *" selector was removed — this
                         school doesn't use separate inventory rooms.
                         Inventory is one centralized pool, and the
                         inventory_room_id column/table have been dropped
                         from the database entirely. -->
                    <div class="form-group inventory-form-group">
                        <label for="inventoryItemNameInput">Item Name *</label>
                        <input type="text" id="inventoryItemNameInput" class="form-control" placeholder="e.g., Whiteboard Marker" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryItemRoomSelect">Already Deployed In Room (optional)</label>
                        <select id="inventoryItemRoomSelect" class="form-control">
                            <option value="">None — this is warehouse / inventory stock</option>
                        </select>
                        <small class="text-muted">Select a room only for pre-existing equipment already physically installed there (e.g., legacy items with no dispatch record). Leave as "None" for normal stock intake.</small>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryQuantityInput">Quantity *</label>
                        <input type="number" id="inventoryQuantityInput" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryCategorySelect">Category</label>
                        <select id="inventoryCategorySelect" class="form-control">
                            <option value="">Uncategorized</option>
                        </select>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryBrandInput">Brand</label>
                        <input type="text" id="inventoryBrandInput" class="form-control" placeholder="e.g., Pilot">
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryModelInput">Model</label>
                        <input type="text" id="inventoryModelInput" class="form-control" placeholder="e.g., Fine Tip">
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryUnitTypeInput">Unit Type *</label>
                        <input type="text" id="inventoryUnitTypeInput" class="form-control" placeholder="e.g., piece, box, set" required>
                    </div>
                </div>
                <!-- Condition and Status fields were removed per request — Condition
                     was never backend-required (item_condition is nullable in
                     ItemController::store()/update()) and Status was always just a
                     readonly, auto-derived preview (InventoryStatusService::deriveStatus()
                     from quantity/reorder_level), never something the user actually set. -->
                <div class="form-group inventory-form-group inventory-full-width" style="margin-top: 12px;">
                    <label for="inventoryThresholdOverrideInput">Low Stock Threshold</label>
                    <input type="number" id="inventoryThresholdOverrideInput" class="form-control" min="0" placeholder="Required item threshold for this stock item.">
                </div>
                <p class="text-muted" style="margin-top: 10px; margin-bottom: 0;">The threshold shown in inventory will follow the exact value you enter here.</p>
                <div class="form-group inventory-form-group inventory-full-width">
                    <label for="inventoryDescriptionInput">Description</label>
                    <textarea id="inventoryDescriptionInput" class="form-control" rows="3" placeholder="Optional notes about the stock intake"></textarea>
                </div>
            </form>
        </div>
        <div class="inventory-modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelAddItemsButton">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveAddItemsButton">Save Changes</button>
        </div>
    </div>
</div>

<div id="inventoryItemHistoryModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header">
            <h2 id="inventoryHistoryModalTitle">Item History</h2>
            <button type="button" class="inventory-modal-close" id="closeInventoryHistoryModalButton" aria-label="Close">&times;</button>
        </div>
        <div class="inventory-modal-body">
            <div id="inventoryHistoryModalMessage" class="inventory-modal-message" hidden></div>
            <div id="inventoryHistoryContainer">
                <div class="ui-empty-state ui-fade-in">
                    <strong>Select an item to load history.</strong>
                    <span>Recent stock adjustments and deployments will appear here.</span>
                </div>
            </div>
        </div>
        <div class="inventory-modal-footer">
            <button type="button" class="btn btn-secondary" id="closeInventoryHistoryFooterButton">Close</button>
        </div>
    </div>
</div>

<?php if ($canCreateInventoryEntries): ?>
<div id="inventoryEntryModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header">
            <h2>Inventory Entry</h2>
            <button type="button" class="inventory-modal-close" id="closeInventoryEntryModalButton" aria-label="Close">&times;</button>
        </div>
        <div class="inventory-modal-body">
            <div id="inventoryEntryModalMessage" class="inventory-modal-message" hidden></div>
            <form id="inventoryEntryForm">
                <div class="inventory-entry-id-banner">
                    <span class="inventory-entry-id-label">Stock Entry ID</span>
                    <strong id="inventoryEntryIdPreview">Auto-generated when saved</strong>
                </div>
                <div class="inventory-form-grid">
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryOrNumber">OR Number / Receipt Number *</label>
                        <input type="text" id="inventoryEntryOrNumber" class="form-control" placeholder="e.g., OR-2026-00124" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntrySupplierName">Supplier Name *</label>
                        <input type="text" id="inventoryEntrySupplierName" class="form-control" placeholder="e.g., ABC Industrial Supply" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryDateReceived">Date Received *</label>
                        <input type="date" id="inventoryEntryDateReceived" class="form-control" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryCategory">Category *</label>
                        <select id="inventoryEntryCategory" class="form-control" required>
                            <option value="">Select category</option>
                        </select>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryItemName">Item Name *</label>
                        <input type="text" id="inventoryEntryItemName" class="form-control" placeholder="e.g., Electrical Tape" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryQuantity">Quantity *</label>
                        <input type="number" id="inventoryEntryQuantity" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryUnitType">Unit Type *</label>
                        <input type="text" id="inventoryEntryUnitType" class="form-control" placeholder="e.g., box, piece, roll" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryDepartment">Department *</label>
                        <select id="inventoryEntryDepartment" class="form-control" required>
                            <option value="">Select department</option>
                        </select>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryRoom">Laboratory / Room</label>
                        <select id="inventoryEntryRoom" class="form-control">
                            <option value="">Select laboratory / room</option>
                        </select>
                    </div>
                    <!-- TASK 6B PHASE 2 — the "Warehouse / Main Inventory *"
                         selector was removed; stock is received into the one
                         centralized Inventory. -->
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryCondition">Condition *</label>
                        <select id="inventoryEntryCondition" class="form-control" required>
                            <option value="">Select condition</option>
                            <option value="new">New</option>
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="damaged">Damaged</option>
                            <option value="for_repair">For Repair</option>
                        </select>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="inventoryEntryReceiver">Receiver</label>
                        <input type="text" id="inventoryEntryReceiver" class="form-control" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" readonly>
                    </div>
                </div>
                <div class="form-group inventory-form-group inventory-full-width">
                    <label for="inventoryEntryDescription">Description</label>
                    <textarea id="inventoryEntryDescription" class="form-control" rows="3" placeholder="Optional notes about the received stock"></textarea>
                </div>
            </form>
        </div>
        <div class="inventory-modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelInventoryEntryButton">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveInventoryEntryButton">Save Entry</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canAdjustStock): ?>
<div id="adjustStockModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header">
            <h2>Adjust Stock</h2>
            <button type="button" class="inventory-modal-close" id="closeAdjustStockModalButton" aria-label="Close">&times;</button>
        </div>
        <div class="inventory-modal-body">
            <div id="adjustStockModalMessage" class="inventory-modal-message" hidden></div>
            <form id="adjustStockForm">
                <div class="form-group inventory-form-group inventory-full-width">
                    <label for="adjustStockItemSelect">Inventory Item *</label>
                    <select id="adjustStockItemSelect" class="form-control" required>
                        <option value="">Select an item...</option>
                    </select>
                </div>
                <div class="form-group inventory-form-group inventory-full-width">
                    <label for="adjustStockCurrentQuantity">Current Stock</label>
                    <input type="text" id="adjustStockCurrentQuantity" class="form-control" value="Select an item to view current stock" readonly>
                </div>
                <div class="inventory-form-grid">
                    <div class="form-group inventory-form-group">
                        <label for="adjustStockDirection">Adjustment Type *</label>
                        <select id="adjustStockDirection" class="form-control" required>
                            <option value="increase">Increase</option>
                            <option value="decrease">Decrease</option>
                        </select>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="adjustStockQuantity">Adjustment Quantity *</label>
                        <input type="number" id="adjustStockQuantity" class="form-control" min="1" step="1" placeholder="e.g., 5" required>
                    </div>
                </div>
                <div class="form-group inventory-form-group inventory-full-width">
                    <label for="adjustStockReason">Reason *</label>
                    <textarea id="adjustStockReason" class="form-control" rows="3" maxlength="500" placeholder="Explain why you're adjusting stock (e.g., physical recount, damage/loss, restock outside a purchase receipt)" required></textarea>
                </div>
                <div id="adjustStockReview" class="inventory-modal-message" hidden></div>
            </form>
        </div>
        <div class="inventory-modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelAdjustStockButton">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveAdjustStockButton">Save Adjustment</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- TASK 6B PHASE 2 — the "Add Inventory Room" modal was removed. It was only
     ever opened by the Add Inventory Room toolbar button, which in turn only
     appeared in the inventory-room browse mode; both are gone. -->

<?php if ($canManageCategories): ?>
<div id="manageCategoriesModal" class="inventory-modal" aria-hidden="true">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header">
            <div>
                <h2>Category Management</h2>
                <p class="inventory-modal-subtitle">Create, edit, and organize the categories used across inventory items.</p>
            </div>
            <button type="button" class="inventory-modal-close" id="closeManageCategoriesModalButton" aria-label="Close">&times;</button>
        </div>
        <div class="inventory-modal-body">
            <div id="manageCategoriesMessage" class="inventory-modal-message" hidden></div>
            <form id="categoryForm">
                <input type="hidden" id="categoryIdInput" value="">
                <div class="inventory-form-grid">
                    <div class="form-group inventory-form-group">
                        <label for="categoryNameInput">Category Name *</label>
                        <input type="text" id="categoryNameInput" class="form-control" placeholder="e.g., Consumables" required>
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="categoryCodeInput">Code</label>
                        <input type="text" id="categoryCodeInput" class="form-control" placeholder="e.g., consumables">
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="categoryDepartmentInput">Department</label>
                        <select id="categoryDepartmentInput" class="form-control">
                            <option value="">Unassigned</option>
                        </select>
                    </div>
                    <div class="form-group inventory-form-group" hidden>
                        <label for="categoryThresholdInput">Default Low Stock Threshold</label>
                        <input type="number" id="categoryThresholdInput" class="form-control" min="0" placeholder="Optional">
                    </div>
                    <div class="form-group inventory-form-group">
                        <label for="categorySortOrderInput">Sort Order</label>
                        <input type="number" id="categorySortOrderInput" class="form-control" value="0">
                    </div>
                </div>
                <div class="inventory-form-grid" style="margin-top: 12px;">
                    <label class="inventory-checkbox-label" hidden>
                        <input type="checkbox" id="categoryAllowOverrideInput" checked>
                        Allow item threshold override
                    </label>
                    <label class="inventory-checkbox-label">
                        <input type="checkbox" id="categoryIsActiveInput" checked>
                        Active
                    </label>
                </div>
            </form>
            <div style="margin-top: 16px;">
                <div class="inventory-category-admin-header">
                    <h3 class="inventory-section-title" style="font-size: 16px;">Existing Categories</h3>
                    <span id="categoriesSummaryText" class="text-muted">0 categories</span>
                </div>
                <div class="inventory-category-admin-filters">
                    <input type="search" id="categorySearchInput" class="form-control" placeholder="Search category name or code..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <select id="categoryStatusFilter" class="form-control">
                        <option value="all">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="with_items">With Items</option>
                        <option value="empty">No Items</option>
                    </select>
                </div>
                <div id="categoriesListContainer" class="inventory-categories-list"></div>
            </div>
        </div>
        <div class="inventory-modal-footer">
            <button type="button" class="btn btn-secondary" id="resetCategoryFormButton">Reset</button>
            <button type="button" class="btn btn-primary" id="saveCategoryButton">Save Category</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
const ITEMS_API_BASE = window.SFMS_PUBLIC_URL('/api/items');
const INVENTORY_STOCK_API_BASE = window.SFMS_PUBLIC_URL('/api/inventory-stock');
// TASK 6B PHASE 2 — INVENTORY_ROOMS_API_BASE (/api/inventory-rooms) was
// removed. The route is untouched and still live; this page no longer calls it.
// TASK 34 — the app is served from a SUBDIRECTORY (/School_Facility_Maintenance_System),
// so every API path must go through window.SFMS_PUBLIC_URL(). These two previously used
// bare root-absolute paths, which resolved to http://localhost/api/... (outside the app's
// base path) and returned Apache's 404 HTML page instead of JSON — the routes were fine
// all along; only the URLs were wrong. Same bug/fix already applied in inventory-reports.php.
const ROOMS_API_BASE = window.SFMS_PUBLIC_URL('/api/rooms');
const BUILDINGS_API_BASE = window.SFMS_PUBLIC_URL('/api/buildings');
const CATEGORIES_API_BASE = window.SFMS_PUBLIC_URL('/api/inventory-categories');
const DEPARTMENTS_API_BASE = window.SFMS_PUBLIC_URL('/api/departments');
const CAN_MANAGE_CATEGORIES = <?php echo $canManageCategories ? 'true' : 'false'; ?>;
const CAN_ADD_ITEMS = <?php echo $canAddItems ? 'true' : 'false'; ?>;
const CAN_CREATE_INVENTORY_ENTRIES = <?php echo $canCreateInventoryEntries ? 'true' : 'false'; ?>;
const CAN_ADJUST_STOCK = <?php echo $canAdjustStock ? 'true' : 'false'; ?>;
const HIDDEN_CATEGORY_NAMES = new Set(['janitorial', 'medical']);

let inventoryCategoriesCache = [];
let inventoryDepartmentsCache = [];
let inventoryEntryRoomsCache = [];
let inventoryAllItems = [];
let inventoryEntryHistory = [];
let inventorySelectedCategoryId = null;
let inventorySearchTerm = '';
let inventoryItemsStatusFilter = '';
let inventoryItemsCategoryFilter = null;
let categoryAdminSearchTerm = '';
let categoryAdminStatusFilter = 'all';
let inventoryStatusFilter = (new URLSearchParams(window.location.search).get('status_filter') || '').toLowerCase();
// TASK 6B PHASE 2 — `inventoryBrowseMode` (which toggled between 'category'
// and 'inventory_room') was removed. The inventory-room mode had no entry
// point in the rendered markup, so it was already unreachable.

// TASK 31/33 — Inventory Items pagination. Mirrors reports.php's All Reports
// client-side pagination pattern (currentPage / rowsPerPage), since the
// backend /api/inventory-stock endpoint (InventoryStockController::index)
// has no page/per_page/limit/offset support and already returns the full
// filtered dataset in one response — identical to how allReports works.
let inventoryCurrentPage = 1;
let inventoryRowsPerPage = 20;

if (inventoryStatusFilter !== 'low_stock' && inventoryStatusFilter !== 'out_of_stock' && inventoryStatusFilter !== 'available') {
    inventoryStatusFilter = '';
}

function getStatusClass(status) {
    const value = normalizeInventoryStatus(status);
    if (value === 'low_stock') return 'badge-warning';
    if (value === 'out_of_stock') return 'badge-priority-critical';
    return 'badge-success';
}

function normalizeInventoryStatus(status) {
    const value = String(status || '').toLowerCase();
    if (value === 'out_of_stock') return 'out_of_stock';
    if (value === 'low_stock') return 'low_stock';
    return 'available';
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function setAddItemsMessage(message, type = 'info') {
    const box = document.getElementById('addItemsModalMessage');
    if (!box) return;

    if (!message) {
        box.hidden = true;
        box.textContent = '';
        box.className = 'inventory-modal-message';
        return;
    }

    box.hidden = false;
    box.textContent = message;
    box.className = `inventory-modal-message inventory-modal-message-${type}`;
}

function setManageCategoriesMessage(message, type = 'info') {
    const box = document.getElementById('manageCategoriesMessage');
    if (!box) return;

    if (!message) {
        box.hidden = true;
        box.textContent = '';
        box.className = 'inventory-modal-message';
        return;
    }

    box.hidden = false;
    box.innerHTML = message;
    box.className = `inventory-modal-message inventory-modal-message-${type}`;
}

function setInventoryEntryMessage(message, type = 'info') {
    const box = document.getElementById('inventoryEntryModalMessage');
    if (!box) return;

    if (!message) {
        box.hidden = true;
        box.textContent = '';
        box.className = 'inventory-modal-message';
        return;
    }

    box.hidden = false;
    box.textContent = message;
    box.className = `inventory-modal-message inventory-modal-message-${type}`;
}

function setAdjustStockMessage(message, type = 'info') {
    const box = document.getElementById('adjustStockModalMessage');
    if (!box) return;

    if (!message) {
        box.hidden = true;
        box.textContent = '';
        box.className = 'inventory-modal-message';
        return;
    }

    box.hidden = false;
    box.textContent = message;
    box.className = `inventory-modal-message inventory-modal-message-${type}`;
}

function populateAdjustStockItemOptions(selectedItemId = null) {
    const select = document.getElementById('adjustStockItemSelect');
    if (!select) return;

    const sortedItems = [...inventoryAllItems].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));

    select.innerHTML = '<option value="">Select an item...</option>' + sortedItems.map((item) => {
        const label = `${item.name || 'Unnamed Item'} — ${Number(item.quantity ?? 0)} on hand`;
        return `<option value="${Number(item.id)}">${inventoryEscapeHtml(label)}</option>`;
    }).join('');

    if (selectedItemId) {
        select.value = String(selectedItemId);
    }
}

function updateAdjustStockReview() {
    const itemId = Number(document.getElementById('adjustStockItemSelect')?.value || 0);
    const direction = document.getElementById('adjustStockDirection')?.value || 'increase';
    const quantity = Number(document.getElementById('adjustStockQuantity')?.value || 0);
    const currentQuantityInput = document.getElementById('adjustStockCurrentQuantity');
    const reviewBox = document.getElementById('adjustStockReview');
    if (!currentQuantityInput || !reviewBox) return;

    const item = inventoryAllItems.find((entry) => Number(entry.id) === itemId);
    if (!item) {
        currentQuantityInput.value = 'Select an item to view current stock';
        reviewBox.hidden = true;
        return;
    }

    const onHand = Number(item.quantity ?? 0);
    const available = item.available_quantity !== undefined
        ? Number(item.available_quantity)
        : Math.max(onHand - Number(item.reserved_quantity ?? 0), 0);
    currentQuantityInput.value = `${onHand} on hand (${available} available)`;

    if (!quantity || quantity <= 0) {
        reviewBox.hidden = true;
        return;
    }

    const projected = direction === 'decrease' ? onHand - quantity : onHand + quantity;
    reviewBox.hidden = false;
    reviewBox.className = 'inventory-modal-message inventory-modal-message-info';
    reviewBox.textContent = `Review: ${direction === 'decrease' ? 'Decrease' : 'Increase'} "${item.name}" by ${quantity}. `
        + `Stock will change from ${onHand} to ${projected}.`;
}

function openAdjustStockModal() {
    const modal = document.getElementById('adjustStockModal');
    if (!modal) return;

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    setAdjustStockMessage('');
    document.getElementById('adjustStockForm')?.reset();
    populateAdjustStockItemOptions();
    updateAdjustStockReview();
    document.getElementById('adjustStockItemSelect')?.focus();
}

function closeAdjustStockModal() {
    const modal = document.getElementById('adjustStockModal');
    if (!modal) return;

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('adjustStockForm')?.reset();
    setAdjustStockMessage('');
    const reviewBox = document.getElementById('adjustStockReview');
    if (reviewBox) reviewBox.hidden = true;
}

async function saveAdjustStock() {
    const itemId = Number(document.getElementById('adjustStockItemSelect')?.value || 0);
    const direction = document.getElementById('adjustStockDirection')?.value || '';
    const quantity = Number(document.getElementById('adjustStockQuantity')?.value || 0);
    const reason = (document.getElementById('adjustStockReason')?.value || '').trim();

    if (!itemId) {
        setAdjustStockMessage('Please select an inventory item.', 'danger');
        return;
    }

    if (direction !== 'increase' && direction !== 'decrease') {
        setAdjustStockMessage('Please choose an adjustment type.', 'danger');
        return;
    }

    if (!Number.isInteger(quantity) || quantity <= 0) {
        setAdjustStockMessage('Please enter a quantity greater than zero.', 'danger');
        return;
    }

    if (!reason) {
        setAdjustStockMessage('Please enter a reason for this adjustment.', 'danger');
        return;
    }

    const item = inventoryAllItems.find((entry) => Number(entry.id) === itemId);
    const itemLabel = item ? item.name : `item #${itemId}`;
    const confirmed = await Components.confirm(
        `${direction === 'decrease' ? 'Decrease' : 'Increase'} stock of "${itemLabel}" by ${quantity}? This action will be recorded in the inventory ledger and cannot be undone.`,
        'Confirm Adjustment',
        'Cancel'
    );
    if (!confirmed) return;

    const saveButton = document.getElementById('saveAdjustStockButton');
    const originalLabel = saveButton ? saveButton.textContent : '';
    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
    }
    setAdjustStockMessage('Saving adjustment...');

    try {
        const response = await fetch(`${ITEMS_API_BASE}/${itemId}/adjust-stock`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ direction, quantity, reason })
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to adjust stock');
        }

        closeAdjustStockModal();
        await loadInventory();
        Components.toast(result.message || 'Stock adjusted successfully.', 'success');
    } catch (error) {
        console.error('Adjust stock error:', error);
        setAdjustStockMessage(error.message || 'Failed to adjust stock.', 'danger');
        Components.alert(error.message || 'Failed to adjust stock.', 'danger');
    } finally {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = originalLabel;
        }
    }
}

function setInventoryHistoryMessage(message, type = 'info') {
    const box = document.getElementById('inventoryHistoryModalMessage');
    if (!box) return;

    if (!message) {
        box.hidden = true;
        box.textContent = '';
        box.className = 'inventory-modal-message';
        return;
    }

    box.hidden = false;
    box.textContent = message;
    box.className = `inventory-modal-message inventory-modal-message-${type}`;
}

function inventoryEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function getCategoryById(id) {
    return inventoryCategoriesCache.find((category) => Number(category.id) === Number(id)) || null;
}

function resolveItemThreshold(item) {
    if (item.reorder_level !== null && item.reorder_level !== undefined && item.reorder_level !== '') {
        return Number(item.reorder_level);
    }

    return null;
}

function computeInventoryStatusPreview(quantity, threshold) {
    const parsedQuantity = Number(quantity || 0);
    const parsedThreshold = Number(threshold || 0);

    if (parsedQuantity <= 0) {
        return 'OUT OF STOCK';
    }

    if (parsedQuantity <= parsedThreshold) {
        return 'LOW STOCK';
    }

    return 'IN STOCK';
}

function updateInventoryStatusPreview() {
    const previewInput = document.getElementById('inventoryStatusPreviewInput');
    const quantityInput = document.getElementById('inventoryQuantityInput');
    const thresholdInput = document.getElementById('inventoryThresholdOverrideInput');
    if (!previewInput || !quantityInput || !thresholdInput) return;

    previewInput.value = computeInventoryStatusPreview(quantityInput.value, thresholdInput.value || 0);
}

function formatThreshold(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }
    return String(Number(value));
}

function isHiddenCategoryName(name) {
    return HIDDEN_CATEGORY_NAMES.has(String(name || '').trim().toLowerCase());
}

function getCategoryIconMarkup(categoryName) {
    const key = String(categoryName || '').trim().toLowerCase();

    if (key === 'all items') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8.5v7a2 2 0 0 1-2 2h-1.5V10a1 1 0 0 0-1-1h-9a1 1 0 0 0-1 1v7.5H5a2 2 0 0 1-2-2v-7l9-5 9 5z"></path><path d="M8.5 20V11h7v9h-7z"></path></svg>';
    }

    if (key === 'electrical') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L5 13h6l-1 9 9-13h-6l0-7z"></path></svg>';
    }

    if (key === 'it & electronics') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"></path><path d="M9 20h6"></path><path d="M10.5 17v3"></path><path d="M13.5 17v3"></path></svg>';
    }

    if (key === 'furniture') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 6a2 2 0 1 1 4 0v3H7V6z"></path><path d="M5 11h14v4H5v-4z"></path><path d="M6 15v4"></path><path d="M18 15v4"></path></svg>';
    }

    if (key === 'plumbing') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3h4v4h-4z"></path><path d="M11 7v5.2l-3.6 3.6a2.5 2.5 0 1 0 3.5 3.5l3.6-3.6H20v-4h-5.5L11 7z"></path></svg>';
    }

    if (key === 'classroom') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v11H3z"></path><path d="M7 20h10"></path><path d="M12 16v4"></path><path d="M6 8h8"></path></svg>';
    }

    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"></path><path d="M8 10h8"></path><path d="M8 14h5"></path></svg>';
}

function getCategoryToneClass(categoryName) {
    const key = String(categoryName || '').trim().toLowerCase();
    if (key === 'all items') return 'tone-all';
    if (key === 'electrical') return 'tone-electrical';
    if (key === 'it & electronics') return 'tone-it';
    if (key === 'furniture') return 'tone-furniture';
    if (key === 'plumbing') return 'tone-plumbing';
    if (key === 'classroom') return 'tone-classroom';
    return 'tone-default';
}

// TASK 6B PHASE 2 — getInventoryRoomById(), fetchInventoryRooms() and
// loadInventoryRoomsForSelect() were removed along with the inventory-room
// selectors they populated. Inventory is now a single centralized pool, so
// there is no room to look up, cache, or choose.

function getVisibleInventoryItems() {
    const term = inventorySearchTerm;

    return inventoryAllItems.filter((item) => {
        const categoryId = item.category_id === null || item.category_id === undefined ? null : Number(item.category_id);
        const category = categoryId === null ? null : getCategoryById(categoryId);
        if (category && isHiddenCategoryName(category.name)) {
            return false;
        }

        if (inventoryStatusFilter && normalizeInventoryStatus(item.status) !== inventoryStatusFilter) {
            return false;
        }

        if (inventorySelectedCategoryId !== null && categoryId !== inventorySelectedCategoryId) {
            return false;
        }

        if (inventoryItemsStatusFilter && normalizeInventoryStatus(item.status) !== inventoryItemsStatusFilter) {
            return false;
        }

        if (inventoryItemsCategoryFilter !== null) {
            const itemCatId = item.category_id === null || item.category_id === undefined ? null : Number(item.category_id);
            if (itemCatId !== inventoryItemsCategoryFilter) return false;
        }

        if (!term) {
            return true;
        }

        const haystack = [
            item.name,
            item.description,
            item.category_name,
            item.brand,
            item.model,
            item.unit_type,
            item.item_condition,
        ].map((value) => String(value || '').toLowerCase()).join(' ');

        return haystack.includes(term);
    });
}

function getItemsForCategoryCards() {
    const term = inventorySearchTerm;

    return inventoryAllItems.filter((item) => {
        const categoryId = item.category_id === null || item.category_id === undefined ? null : Number(item.category_id);
        const category = categoryId === null ? null : getCategoryById(categoryId);
        if (category && isHiddenCategoryName(category.name)) {
            return false;
        }

        if (!term) {
            return true;
        }

        const haystack = [
            item.name,
            item.description,
            item.room_name,
            item.category_name,
        ].map((value) => String(value || '').toLowerCase()).join(' ');

        return haystack.includes(term);
    });
}

// TASK 6B PHASE 2 — getItemsForInventoryRoomCards() was removed together with
// the inventory-room browse mode it fed.

function getItemsForStatusCards() {
    const scopedItems = getItemsForCategoryCards();

    if (inventorySelectedCategoryId === null) {
        return scopedItems;
    }

    return scopedItems.filter((item) => Number(item.category_id || 0) === inventorySelectedCategoryId);
}

function setInventoryStatusFilter(nextStatus) {
    const normalized = String(nextStatus || '').toLowerCase();
    if (normalized !== '' && normalized !== 'low_stock' && normalized !== 'out_of_stock' && normalized !== 'available') {
        return;
    }

    inventoryStatusFilter = normalized;

    const currentUrl = new URL(window.location.href);
    if (!normalized) {
        currentUrl.searchParams.delete('status_filter');
    } else {
        currentUrl.searchParams.set('status_filter', normalized);
    }

    window.history.replaceState({}, '', currentUrl.toString());
    inventoryCurrentPage = 1; // TASK 31 — status filter changed, start back at page 1
    renderInventoryOverview();
}

function renderInventoryStatusCards() {
    const container = document.getElementById('inventory-status-cards');
    if (!container) return;

    const scopedItems = getItemsForStatusCards();
    const totalCount = scopedItems.length;
    const outCount = scopedItems.filter((item) => normalizeInventoryStatus(item.status) === 'out_of_stock').length;
    const lowCount = scopedItems.filter((item) => normalizeInventoryStatus(item.status) === 'low_stock').length;
    const inCount = scopedItems.filter((item) => normalizeInventoryStatus(item.status) === 'available').length;

    const cards = [
        {
            status: '',
            title: 'All Items',
            value: totalCount,
            subtitle: 'All statuses',
            tone: 'tone-all'
        },
        {
            status: 'out_of_stock',
            title: 'Out of Stock',
            value: outCount,
            subtitle: 'Needs restocking',
            tone: 'tone-out'
        },
        {
            status: 'low_stock',
            title: 'Low Stock',
            value: lowCount,
            subtitle: 'Near threshold',
            tone: 'tone-low'
        },
        {
            status: 'available',
            title: 'In Stock',
            value: inCount,
            subtitle: 'Healthy inventory',
            tone: 'tone-in'
        }
    ];

    container.innerHTML = cards.map((card) => {
        const activeClass = inventoryStatusFilter === card.status ? 'is-active' : '';
        return `
            <button type="button" class="summary-card inventory-status-card ${card.tone} ${activeClass}" data-status="${card.status}">
                <span class="summary-card-title">${card.title}</span>
                <span class="summary-card-value">${card.value}</span>
                <span class="summary-card-desc">${card.subtitle}</span>
            </button>
        `;
    }).join('');

    container.querySelectorAll('[data-status]').forEach((card) => {
        card.addEventListener('click', () => {
            const status = card.getAttribute('data-status') || '';
            setInventoryStatusFilter(status);
        });
    });
}

function renderInventoryCategoryCards() {
    const container = document.getElementById('inventory-category-cards');
    if (!container) return;

    const categories = inventoryCategoriesCache.filter((category) => Number(category.is_active || 0) === 1 && !isHiddenCategoryName(category.name));
    const cardItems = getItemsForCategoryCards();

    const lowCount = cardItems.filter((item) => normalizeInventoryStatus(item.status) === 'low_stock').length;
    const outCount = cardItems.filter((item) => normalizeInventoryStatus(item.status) === 'out_of_stock').length;
    const okCount = cardItems.filter((item) => normalizeInventoryStatus(item.status) === 'available').length;

    const cards = [];
    const allActiveClass = inventorySelectedCategoryId === null ? 'is-active' : '';
    const allCountClass = cardItems.length === 0 ? 'is-empty' : '';
    cards.push(`
        <button type="button" class="inventory-category-card ${getCategoryToneClass('all items')} ${allActiveClass} ${allCountClass}" data-category="all" aria-pressed="${inventorySelectedCategoryId === null ? 'true' : 'false'}">
            <span class="inventory-category-card-icon">${getCategoryIconMarkup('all items')}</span>
            <span class="inventory-category-card-content">
                <span class="inventory-category-card-name">All Items</span>
                <span class="inventory-category-card-meta">
                    ${lowCount > 0 ? `<span class="inventory-category-chip is-low">${lowCount} low</span>` : ''}
                    ${outCount > 0 ? `<span class="inventory-category-chip is-out">${outCount} out</span>` : ''}
                    ${okCount > 0 ? `<span class="inventory-category-chip is-ok">${okCount} ok</span>` : ''}
                </span>
            </span>
            <span class="inventory-category-card-count-badge">${cardItems.length}</span>
        </button>
    `);


    categories.forEach((category) => {
        const categoryItems = cardItems.filter((item) => Number(item.category_id || 0) === Number(category.id));
        const catLow = categoryItems.filter((item) => normalizeInventoryStatus(item.status) === 'low_stock').length;
        const catOut = categoryItems.filter((item) => normalizeInventoryStatus(item.status) === 'out_of_stock').length;
        const catOk = categoryItems.filter((item) => normalizeInventoryStatus(item.status) === 'available').length;
        const activeClass = inventorySelectedCategoryId === Number(category.id) ? 'is-active' : '';
        const countClass = categoryItems.length === 0 ? 'is-empty' : '';

        cards.push(`
            <button type="button" class="inventory-category-card ${getCategoryToneClass(category.name || '')} ${activeClass} ${countClass}" data-category="${Number(category.id)}" aria-pressed="${inventorySelectedCategoryId === Number(category.id) ? 'true' : 'false'}">
                <span class="inventory-category-card-icon">${getCategoryIconMarkup(category.name || '')}</span>
                <span class="inventory-category-card-content">
                    <span class="inventory-category-card-name">${category.name || 'Unnamed Category'}</span>
                    <span class="inventory-category-card-meta">
                        ${catLow > 0 ? `<span class="inventory-category-chip is-low">${catLow} low</span>` : ''}
                        ${catOut > 0 ? `<span class="inventory-category-chip is-out">${catOut} out</span>` : ''}
                        ${catOk > 0 ? `<span class="inventory-category-chip is-ok">${catOk} ok</span>` : ''}
                    </span>
                </span>
                <span class="inventory-category-card-count-badge">${categoryItems.length}</span>
            </button>
        `);

    });

    container.innerHTML = cards.join('');

    container.querySelectorAll('[data-category]').forEach((card) => {
        card.addEventListener('click', () => {
            const value = card.getAttribute('data-category');
            if (value === 'all') {
                inventorySelectedCategoryId = null;
            } else {
                inventorySelectedCategoryId = Number(value);
            }

            setInventoryStatusFilter('');
        });
    });

    // TASK 6B PHASE 2 — the '[data-inventory-room="true"]' listener that
    // switched the browser into inventory-room mode was removed. No card
    // markup ever rendered that attribute, so the branch was already
    // unreachable; removing it cannot change behaviour.
}

// TASK 6B PHASE 2 — renderInventoryRoomCards() and
// toggleInventoryRoomActionButton() were removed. Both were only reachable
// from the inventory-room browse mode, which nothing could enter.

function updateInventoryBrowserHeading() {
    const heading = document.getElementById('inventory-browser-heading');
    if (!heading) return;
    heading.textContent = 'Browse by Category';
}

function populateItemsCategoryFilter() {
    const select = document.getElementById('inventory-items-category-filter');
    if (!select) return;
    const active = inventoryCategoriesCache.filter((c) => Number(c.is_active || 0) === 1);
    select.innerHTML = '<option value="">All Categories</option>';
    active.forEach((c) => {
        const opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = c.name || 'Unnamed Category';
        select.appendChild(opt);
    });
}

function renderInventoryOverview() {
    const visibleItems = getVisibleInventoryItems();

    // TASK 31 — paginate the filtered result set the same way reports.php
    // paginates allReports: clamp the current page (so a CRUD refresh that
    // empties the last page — e.g. deleting the last item on it — moves
    // back automatically), slice, render only the current page's rows, and
    // keep the count badge tied to the *total filtered* count, not the
    // per-page count.
    const totalItems = visibleItems.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / inventoryRowsPerPage));

    if (inventoryCurrentPage > totalPages) {
        inventoryCurrentPage = totalPages;
    }
    if (inventoryCurrentPage < 1) {
        inventoryCurrentPage = 1;
    }

    const startIndex = (inventoryCurrentPage - 1) * inventoryRowsPerPage;
    const pagedItems = visibleItems.slice(startIndex, startIndex + inventoryRowsPerPage);

    renderItems(pagedItems, totalItems);
    renderInventoryItemsPagination(totalItems, totalPages, startIndex);
}

function resetCategoryForm() {
    const idInput = document.getElementById('categoryIdInput');
    const nameInput = document.getElementById('categoryNameInput');
    const codeInput = document.getElementById('categoryCodeInput');
    const departmentInput = document.getElementById('categoryDepartmentInput');
    const thresholdInput = document.getElementById('categoryThresholdInput');
    const sortOrderInput = document.getElementById('categorySortOrderInput');
    const allowOverrideInput = document.getElementById('categoryAllowOverrideInput');
    const isActiveInput = document.getElementById('categoryIsActiveInput');

    if (idInput) idInput.value = '';
    if (nameInput) nameInput.value = '';
    if (codeInput) codeInput.value = '';
    if (departmentInput) departmentInput.value = '';
    if (thresholdInput) thresholdInput.value = '';
    if (sortOrderInput) sortOrderInput.value = '0';
    if (allowOverrideInput) allowOverrideInput.checked = true;
    if (isActiveInput) isActiveInput.checked = true;
}

function fillCategoryForm(category) {
    const idInput = document.getElementById('categoryIdInput');
    const nameInput = document.getElementById('categoryNameInput');
    const codeInput = document.getElementById('categoryCodeInput');
    const departmentInput = document.getElementById('categoryDepartmentInput');
    const thresholdInput = document.getElementById('categoryThresholdInput');
    const sortOrderInput = document.getElementById('categorySortOrderInput');
    const allowOverrideInput = document.getElementById('categoryAllowOverrideInput');
    const isActiveInput = document.getElementById('categoryIsActiveInput');

    if (idInput) idInput.value = String(category.id || '');
    if (nameInput) nameInput.value = category.name || '';
    if (codeInput) codeInput.value = category.code || '';
    if (departmentInput) departmentInput.value = category.department_id ? String(category.department_id) : '';
    if (thresholdInput) thresholdInput.value = category.default_low_stock_threshold ?? '';
    if (sortOrderInput) sortOrderInput.value = String(category.sort_order ?? 0);
    if (allowOverrideInput) allowOverrideInput.checked = Number(category.allow_threshold_override || 0) === 1;
    if (isActiveInput) isActiveInput.checked = Number(category.is_active || 0) === 1;
}

function renderCategoriesList() {
    const container = document.getElementById('categoriesListContainer');
    if (!container) return;

    const categories = Array.isArray(inventoryCategoriesCache) ? inventoryCategoriesCache.filter((category) => {
        const name = String(category.name || '').toLowerCase();
        const code = String(category.code || '').toLowerCase();
        const matchesSearch = categoryAdminSearchTerm === ''
            || name.includes(categoryAdminSearchTerm)
            || code.includes(categoryAdminSearchTerm);

        if (!matchesSearch) {
            return false;
        }

        const isActive = Number(category.is_active || 0) === 1;
        const hasItems = Number(category.total_items || 0) > 0;

        if (categoryAdminStatusFilter === 'active') return isActive;
        if (categoryAdminStatusFilter === 'inactive') return !isActive;
        if (categoryAdminStatusFilter === 'with_items') return hasItems;
        if (categoryAdminStatusFilter === 'empty') return !hasItems;
        return true;
    }) : [];

    const summary = document.getElementById('categoriesSummaryText');
    if (summary) {
        const total = Array.isArray(inventoryCategoriesCache) ? inventoryCategoriesCache.length : 0;
        summary.textContent = categories.length === total
            ? `${total} ${total === 1 ? 'category' : 'categories'}`
            : `${categories.length} of ${total} categories`;
    }

    if (!categories.length) {
        const hasFilters = categoryAdminSearchTerm !== '' || categoryAdminStatusFilter !== 'all';
        container.innerHTML = hasFilters
            ? '<div class="ui-empty-state ui-fade-in"><strong>No matching categories found.</strong><span>Try changing the search keyword or status filter.</span></div>'
            : '<div class="ui-empty-state ui-fade-in"><strong>No categories yet.</strong><span>Create one above to start grouping inventory items.</span></div>';
        return;
    }

    let html = '<div class="inventory-category-admin-grid">';

    categories.forEach((category) => {
        const active = Number(category.is_active || 0) === 1 ? 'Active' : 'Inactive';
        const activeClass = Number(category.is_active || 0) === 1 ? 'is-active' : 'is-inactive';
        const totalItems = Number(category.total_items || 0);
        const totalQuantity = Number(category.total_stock_quantity || 0);
        const codeLabel = String(category.code || '').trim();
        const departmentLabel = String(category.department_name || '').trim();

        html += `
            <article class="inventory-category-admin-card">
                <div class="inventory-category-admin-card-head">
                    <div>
                        <h4>${inventoryEscapeHtml(category.name || '-')}</h4>
                        <div class="inventory-category-admin-card-meta">
                            ${codeLabel ? `<span class="inventory-category-admin-code">${inventoryEscapeHtml(codeLabel)}</span>` : '<span class="inventory-category-admin-code is-empty">No code</span>'}
                            ${departmentLabel ? `<span class="inventory-category-admin-code">${inventoryEscapeHtml(departmentLabel)}</span>` : '<span class="inventory-category-admin-code is-empty">Unassigned dept.</span>'}
                            <span class="inventory-category-admin-status ${activeClass}">${active}</span>
                        </div>
                    </div>
                    <div class="inventory-category-admin-sort">Sort #${Number(category.sort_order || 0)}</div>
                </div>
                <div class="inventory-category-admin-stats">
                    <div class="inventory-category-admin-stat">
                        <span class="inventory-category-admin-stat-label">Items</span>
                        <strong>${totalItems}</strong>
                    </div>
                    <div class="inventory-category-admin-stat">
                        <span class="inventory-category-admin-stat-label">Stock Qty</span>
                        <strong>${totalQuantity}</strong>
                    </div>
                </div>
                <div class="inventory-category-admin-actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="startEditCategory(${Number(category.id)})">Edit</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteCategory(${Number(category.id)})">Delete</button>
                </div>
            </article>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

async function loadInventoryCategoriesForSelect() {
    const select = document.getElementById('inventoryCategorySelect');
    if (!select) return;

    const active = inventoryCategoriesCache.filter((category) => Number(category.is_active || 0) === 1);
    select.innerHTML = '<option value="">Uncategorized</option>';

    active.forEach((category) => {
        const option = document.createElement('option');
        option.value = String(category.id);
        option.textContent = category.name || 'Unnamed Category';
        select.appendChild(option);
    });
}

function applySelectedCategoryRules() {
    const thresholdInput = document.getElementById('inventoryThresholdOverrideInput');
    if (!thresholdInput) return;

    thresholdInput.disabled = false;
    thresholdInput.placeholder = 'Required item threshold for this stock item.';
}

async function fetchInventoryCategories() {
    // include_inactive=1 so the "Manage Categories" admin list can see/reactivate inactive
    // categories too. Every other consumer of inventoryCategoriesCache (Browse-by-Category
    // cards, item-entry dropdowns) already filters to is_active === 1 client-side, so this
    // is safe for the whole page.
    const response = await fetch(window.SFMS_PUBLIC_URL('/api/inventory-categories?include_inactive=1'), { credentials: 'same-origin' });
    const payload = await response.json();

    if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Failed to load categories');
    }

    inventoryCategoriesCache = (Array.isArray(payload.data?.categories) ? payload.data.categories : [])
        .filter((category) => !isHiddenCategoryName(category.name));
    inventoryCategoriesCache.sort((a, b) => {
        const sortA = Number(a.sort_order || 0);
        const sortB = Number(b.sort_order || 0);
        if (sortA !== sortB) return sortA - sortB;
        return String(a.name || '').localeCompare(String(b.name || ''));
    });
}

async function refreshCategoriesUI() {
    try {
        await fetchInventoryCategories();
        await loadInventoryCategoriesForSelect();
        await loadInventoryEntryCategoryOptions();
        populateItemsCategoryFilter();
        renderCategoriesList();
        applySelectedCategoryRules();
    } catch (error) {
        console.error('Category load error:', error);
            setAddItemsMessage('Could not load inventory categories.', 'warning');
        setManageCategoriesMessage(error.message || 'Could not load categories.', 'danger');
    }
}

async function fetchInventoryDepartments() {
    const response = await fetch(DEPARTMENTS_API_BASE, { credentials: 'same-origin' });
    const payload = await response.json();
    const departments = Array.isArray(payload?.data?.departments)
        ? payload.data.departments
        : Array.isArray(payload?.departments)
            ? payload.departments
            : [];

    if (!response.ok || payload?.success !== true) {
        throw new Error(payload?.message || 'Failed to load departments');
    }

    inventoryDepartmentsCache = departments;
}

async function fetchInventoryEntryRooms() {
    const response = await fetch(`${ROOMS_API_BASE}`, { credentials: 'same-origin' });
    const payload = await response.json();

    if (!response.ok || !payload.success) {
        throw new Error(payload.message || 'Failed to load laboratory / room options');
    }

    inventoryEntryRoomsCache = Array.isArray(payload.data?.rooms) ? payload.data.rooms : [];
}

async function loadInventoryEntryCategoryOptions() {
    const formSelect = document.getElementById('inventoryEntryCategory');
    const filterSelect = document.getElementById('inventory-entry-filter-category');
    const activeCategories = inventoryCategoriesCache.filter((category) => Number(category.is_active || 0) === 1);

    if (formSelect) {
        formSelect.innerHTML = '<option value="">Select category</option>';
        activeCategories.forEach((category) => {
            const option = document.createElement('option');
            option.value = String(category.id);
            option.textContent = category.name || 'Unnamed Category';
            formSelect.appendChild(option);
        });
    }

    if (filterSelect) {
        filterSelect.innerHTML = '<option value="">All Categories</option>';
        activeCategories.forEach((category) => {
            const option = document.createElement('option');
            option.value = String(category.id);
            option.textContent = category.name || 'Unnamed Category';
            filterSelect.appendChild(option);
        });
    }
}

async function loadInventoryEntryDepartmentOptions() {
    const formSelect = document.getElementById('inventoryEntryDepartment');
    const filterSelect = document.getElementById('inventory-entry-filter-department');

    if (formSelect) {
        formSelect.innerHTML = '<option value="">Select department</option>';
        inventoryDepartmentsCache.forEach((department) => {
            const option = document.createElement('option');
            option.value = String(department.department_id);
            option.textContent = department.name || 'Unnamed Department';
            formSelect.appendChild(option);
        });
    }

    if (filterSelect) {
        filterSelect.innerHTML = '<option value="">All Departments</option>';
        inventoryDepartmentsCache.forEach((department) => {
            const option = document.createElement('option');
            option.value = String(department.department_id);
            option.textContent = department.name || 'Unnamed Department';
            filterSelect.appendChild(option);
        });
    }
}

function loadCategoryDepartmentOptions() {
    const select = document.getElementById('categoryDepartmentInput');
    if (!select) return;

    const previousValue = select.value;
    select.innerHTML = '<option value="">Unassigned</option>';
    inventoryDepartmentsCache.forEach((department) => {
        const option = document.createElement('option');
        option.value = String(department.department_id);
        option.textContent = department.name || 'Unnamed Department';
        select.appendChild(option);
    });
    select.value = previousValue;
}

async function loadInventoryEntryRoomOptions() {
    const formSelect = document.getElementById('inventoryEntryRoom');
    const filterSelect = document.getElementById('inventory-entry-filter-room');
    // Add Item modal's "Already Deployed In Room" selector (legacy/room_asset
    // entry) shares the same room list, so it's populated here too.
    const itemRoomSelect = document.getElementById('inventoryItemRoomSelect');

    if (formSelect) {
        formSelect.innerHTML = '<option value="">Select laboratory / room</option>';
        inventoryEntryRoomsCache.forEach((room) => {
            const option = document.createElement('option');
            option.value = String(room.id);
            option.textContent = room.name || 'Unnamed Room';
            formSelect.appendChild(option);
        });
    }

    if (filterSelect) {
        filterSelect.innerHTML = '<option value="">All Laboratories / Rooms</option>';
        inventoryEntryRoomsCache.forEach((room) => {
            const option = document.createElement('option');
            option.value = String(room.id);
            option.textContent = room.name || 'Unnamed Room';
            filterSelect.appendChild(option);
        });
    }

    if (itemRoomSelect) {
        itemRoomSelect.innerHTML = '<option value="">None — this is warehouse / inventory stock</option>';
        inventoryEntryRoomsCache.forEach((room) => {
            const option = document.createElement('option');
            option.value = String(room.id);
            option.textContent = room.building_name ? `${room.name || 'Unnamed Room'} — ${room.building_name}` : (room.name || 'Unnamed Room');
            itemRoomSelect.appendChild(option);
        });
    }
}

// TASK 6B PHASE 2 — loadInventoryEntryInventoryRoomOptions() was removed
// together with the "Warehouse / Main Inventory" selector it populated.

function formatInventoryEntryCondition(condition) {
    return String(condition || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function renderInventoryEntryHistory(entries) {
    const container = document.getElementById('inventory-entry-container');
    if (!container) return;

    if (!Array.isArray(entries) || entries.length === 0) {
        container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>No inventory entries found.</strong><span>Inventory receipt and stock entry records will appear here after the first entry is saved.</span></div>';
        setText('inventory-entry-count', '0 entries');
        return;
    }

    let html = '<div class="inventory-entry-table-wrap"><table class="table inventory-entry-table">';
    html += '<thead><tr>';
    html += '<th>Stock Entry ID</th><th>Date Received</th><th>Item</th><th>Supplier</th><th>OR / Receipt</th><th>Category</th><th>Department</th><th>Laboratory / Room</th><th>Receiver</th><th>Condition</th><th>Description</th>';
    html += '</tr></thead><tbody>';

    entries.forEach((entry) => {
        const dateReceived = entry.date_received
            ? new Date(String(entry.date_received).replace(' ', 'T')).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
            : '-';
        const itemLabel = `${inventoryEscapeHtml(entry.item_name || 'Unnamed Item')} <span class="inventory-entry-qty-pill">${Number(entry.quantity || 0)} ${inventoryEscapeHtml(entry.unit_type || '')}</span>`;

        html += '<tr>';
        html += `<td><strong>${inventoryEscapeHtml(entry.stock_entry_id || '-')}</strong></td>`;
        html += `<td>${inventoryEscapeHtml(dateReceived)}</td>`;
        html += `<td>${itemLabel}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.supplier_name || '-')}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.or_number || '-')}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.category_name || 'Uncategorized')}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.department_name || '-')}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.room_name || '-')}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.receiver_name || '-')}</td>`;
        html += `<td>${inventoryEscapeHtml(formatInventoryEntryCondition(entry.item_condition || '-'))}</td>`;
        html += `<td>${inventoryEscapeHtml(entry.description || '-')}</td>`;
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
    setText('inventory-entry-count', `${entries.length} ${entries.length === 1 ? 'entry' : 'entries'}`);
}

async function loadInventoryEntryHistory() {
    const container = document.getElementById('inventory-entry-container');
    if (container) {
        container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>Loading inventory entries...</strong><span>Please wait while we collect your stock entry history.</span></div>';
    }

    const params = new URLSearchParams();
    const search = (document.getElementById('inventory-entry-search')?.value || '').trim();
    const categoryId = document.getElementById('inventory-entry-filter-category')?.value || '';
    const departmentId = document.getElementById('inventory-entry-filter-department')?.value || '';
    const roomId = document.getElementById('inventory-entry-filter-room')?.value || '';
    const dateFrom = document.getElementById('inventory-entry-filter-date-from')?.value || '';
    const dateTo = document.getElementById('inventory-entry-filter-date-to')?.value || '';

    if (search) params.set('search', search);
    if (categoryId) params.set('category_id', categoryId);
    if (departmentId) params.set('department_id', departmentId);
    if (roomId) params.set('room_id', roomId);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);

    try {
        const response = await fetch(`${INVENTORY_STOCK_API_BASE}/entries?${params.toString()}`, {
            credentials: 'same-origin'
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load inventory entries');
        }

        inventoryEntryHistory = Array.isArray(payload.data?.entries) ? payload.data.entries : [];
        renderInventoryEntryHistory(inventoryEntryHistory);
    } catch (error) {
        console.error('Inventory entry history load error:', error);
        if (container) {
            container.innerHTML = '<div class="alert alert-danger ui-fade-in">Could not load inventory entry history right now.</div>';
        }
        setText('inventory-entry-count', '0 entries');
    }
}

function openInventoryEntryModal() {
    const modal = document.getElementById('inventoryEntryModal');
    if (!modal) return;

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    setInventoryEntryMessage('');
    document.getElementById('inventoryEntryForm')?.reset();
    const dateInput = document.getElementById('inventoryEntryDateReceived');
    if (dateInput) {
        dateInput.value = new Date().toISOString().slice(0, 10);
    }
    document.getElementById('inventoryEntryIdPreview').textContent = 'Auto-generated when saved';
    document.getElementById('inventoryEntryOrNumber')?.focus();
}

function closeInventoryEntryModal() {
    const modal = document.getElementById('inventoryEntryModal');
    if (!modal) return;

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('inventoryEntryForm')?.reset();
    setInventoryEntryMessage('');
    document.getElementById('inventoryEntryIdPreview').textContent = 'Auto-generated when saved';
}

async function saveInventoryEntry() {
    const payload = {
        or_number: (document.getElementById('inventoryEntryOrNumber')?.value || '').trim(),
        supplier_name: (document.getElementById('inventoryEntrySupplierName')?.value || '').trim(),
        date_received: document.getElementById('inventoryEntryDateReceived')?.value || '',
        category_id: document.getElementById('inventoryEntryCategory')?.value || '',
        item_name: (document.getElementById('inventoryEntryItemName')?.value || '').trim(),
        quantity: Number(document.getElementById('inventoryEntryQuantity')?.value || 0),
        unit_type: (document.getElementById('inventoryEntryUnitType')?.value || '').trim(),
        department_id: document.getElementById('inventoryEntryDepartment')?.value || '',
        room_id: document.getElementById('inventoryEntryRoom')?.value || '',
        description: (document.getElementById('inventoryEntryDescription')?.value || '').trim(),
        item_condition: document.getElementById('inventoryEntryCondition')?.value || '',
    };

    if (!payload.or_number || !payload.supplier_name || !payload.date_received || !payload.category_id || !payload.item_name || payload.quantity <= 0 || !payload.unit_type || !payload.department_id || !payload.item_condition) {
        setInventoryEntryMessage('Please complete all required inventory entry fields before saving.', 'danger');
        return;
    }

    const saveButton = document.getElementById('saveInventoryEntryButton');
    const originalLabel = saveButton?.textContent || 'Save Entry';
    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
    }

    setInventoryEntryMessage('Saving inventory entry and updating inventory stock...', 'info');

    try {
        const response = await fetch(`${INVENTORY_STOCK_API_BASE}/entries`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to save inventory entry');
        }

        const stockEntryId = result.data?.entry?.stock_entry_id || 'Saved';
        document.getElementById('inventoryEntryIdPreview').textContent = stockEntryId;
        setInventoryEntryMessage(`Inventory entry ${stockEntryId} saved successfully.`, 'info');
        await loadInventory();
        await loadInventoryEntryHistory();

        window.setTimeout(() => {
            closeInventoryEntryModal();
        }, 600);
    } catch (error) {
        console.error('Save inventory entry error:', error);
        setInventoryEntryMessage(error.message || 'Failed to save inventory entry.', 'danger');
    } finally {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = originalLabel;
        }
    }
}

// TASK 6B PHASE 2 — openAddInventoryRoomModal() / closeAddInventoryRoomModal()
// were removed along with the Add Inventory Room modal itself.

function openAddItemsModal() {
    const modal = document.getElementById('addItemsModal');
    if (!modal) return;

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    setAddItemsMessage('');
    document.getElementById('addItemsModalTitle').textContent = 'Edit Item';
    document.getElementById('saveAddItemsButton').textContent = 'Save Changes';
    document.getElementById('addItemsForm').reset();
    document.getElementById('inventoryItemIdInput').value = '';
    document.getElementById('inventoryQuantityInput').value = '1';
    document.getElementById('inventoryQuantityInput').setAttribute('min', '1');
    document.getElementById('inventoryQuantityInput').readOnly = false;
    const thresholdInput = document.getElementById('inventoryThresholdOverrideInput');
    if (thresholdInput) {
        thresholdInput.value = '';
        thresholdInput.disabled = false;
    }
    const roomSelectReset = document.getElementById('inventoryItemRoomSelect');
    if (roomSelectReset) {
        roomSelectReset.disabled = false;
    }

    refreshCategoriesUI()
        .then(() => {
            // TASK 6B PHASE 2 — the inventory-room selector used to be the
            // first editable control; focus now starts on the item name.
            const firstEditable = document.getElementById('inventoryItemNameInput');
            if (firstEditable && !firstEditable.disabled) {
                firstEditable.focus();
            }
        })
        .catch((error) => {
            console.error('Add item modal bootstrap error:', error);
            setAddItemsMessage(error.message || 'Could not load category options.', 'danger');
        });
}

function closeAddItemsModal() {
    const modal = document.getElementById('addItemsModal');
    if (!modal) return;

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('addItemsForm').reset();
    document.getElementById('inventoryItemIdInput').value = '';
    document.getElementById('addItemsModalTitle').textContent = 'Edit Item';
    document.getElementById('saveAddItemsButton').textContent = 'Save Changes';
    document.getElementById('inventoryQuantityInput').value = '1';
    document.getElementById('inventoryQuantityInput').setAttribute('min', '1');
    document.getElementById('inventoryQuantityInput').readOnly = false;
    const thresholdInput = document.getElementById('inventoryThresholdOverrideInput');
    if (thresholdInput) {
        thresholdInput.value = '';
        thresholdInput.disabled = false;
    }
    setAddItemsMessage('');
}

function fillInventoryItemForm(item) {
    document.getElementById('inventoryItemIdInput').value = String(item.id || '');
    document.getElementById('inventoryItemNameInput').value = item.name || '';
    document.getElementById('inventoryQuantityInput').value = String(Number(item.quantity || 0));
    document.getElementById('inventoryQuantityInput').setAttribute('min', '0');
    document.getElementById('inventoryCategorySelect').value = item.category_id === null || item.category_id === undefined ? '' : String(item.category_id);
    document.getElementById('inventoryBrandInput').value = item.brand || '';
    document.getElementById('inventoryModelInput').value = item.model || '';
    document.getElementById('inventoryUnitTypeInput').value = item.unit_type || '';
    document.getElementById('inventoryThresholdOverrideInput').value = item.reorder_level === null || item.reorder_level === undefined ? '' : String(Number(item.reorder_level));
    document.getElementById('inventoryDescriptionInput').value = item.description || '';
    const roomSelect = document.getElementById('inventoryItemRoomSelect');
    if (roomSelect) {
        roomSelect.value = item.room_id === null || item.room_id === undefined ? '' : String(item.room_id);
    }
}

function startEditInventoryItem(itemId) {
    const item = inventoryAllItems.find((entry) => Number(entry.id) === Number(itemId));
    if (!item) {
        return;
    }

    openAddItemsModal();

    refreshCategoriesUI()
        .then(() => {
            fillInventoryItemForm(item);
            // Quantity can only be changed via Manual Stock Adjustment, so it's
            // read-only here — Edit Item only ever touches metadata fields.
            document.getElementById('inventoryQuantityInput').readOnly = true;
            // Room deployment can only be set at creation — the metadata-only
            // update endpoint (PUT /api/inventory-stock/{id}) has no room_id
            // field, so changing it here would silently do nothing.
            const roomSelect = document.getElementById('inventoryItemRoomSelect');
            if (roomSelect) {
                roomSelect.disabled = true;
            }
            document.getElementById('inventoryItemNameInput')?.focus();
        })
        .catch((error) => {
            console.error('Edit item modal bootstrap error:', error);
            setAddItemsMessage(error.message || 'Could not load item details.', 'danger');
        });
}

function openInventoryHistoryModal() {
    const modal = document.getElementById('inventoryItemHistoryModal');
    if (!modal) return;

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeInventoryHistoryModal() {
    const modal = document.getElementById('inventoryItemHistoryModal');
    if (!modal) return;

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    setInventoryHistoryMessage('');
}

async function openInventoryItemHistory(itemId) {
    const item = inventoryAllItems.find((entry) => Number(entry.id) === Number(itemId));
    const container = document.getElementById('inventoryHistoryContainer');
    if (!container || !item) return;

    openInventoryHistoryModal();
    document.getElementById('inventoryHistoryModalTitle').textContent = `${item.name || 'Item'} History`;
    container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>Loading item history...</strong><span>Please wait while we collect recent inventory activity.</span></div>';
    setInventoryHistoryMessage('');

    try {
        const response = await fetch(`${INVENTORY_STOCK_API_BASE}/${encodeURIComponent(itemId)}/transactions`, {
            credentials: 'same-origin'
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load item history');
        }

        const transactions = Array.isArray(payload.data?.transactions) ? payload.data.transactions : [];
        if (transactions.length === 0) {
            container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>No history found.</strong><span>This item does not have recorded transactions yet.</span></div>';
            return;
        }

        let html = '<div class="inventory-history-table-wrap"><table class="table inventory-history-table"><thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Performed By</th><th>Note</th></tr></thead><tbody>';
        transactions.forEach((transaction) => {
            const formattedDate = transaction.created_at
                ? new Date(String(transaction.created_at).replace(' ', 'T')).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
                : 'N/A';
            const transactionType = String(transaction.transaction_type || '').replace(/_/g, ' ').toUpperCase();
            html += '<tr>';
            html += `<td>${inventoryEscapeHtml(formattedDate)}</td>`;
            html += `<td>${inventoryEscapeHtml(transactionType)}</td>`;
            html += `<td>${inventoryEscapeHtml(transaction.quantity ?? 0)}</td>`;
            html += `<td>${inventoryEscapeHtml(transaction.performed_by_name || 'System')}</td>`;
            html += `<td>${inventoryEscapeHtml(transaction.reference_note || '-')}</td>`;
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (error) {
        console.error('Inventory item history error:', error);
        container.innerHTML = '<div class="alert alert-danger ui-fade-in">Could not load item history right now.</div>';
    }
}

async function deleteInventoryItem(itemId) {
    const item = inventoryAllItems.find((entry) => Number(entry.id) === Number(itemId));
    if (!item) return;

    const confirmed = await Components.confirm(`Delete "${item.name || 'this item'}"? This will remove it from inventory if it has no active report allocations.`, 'Delete', 'Cancel');
    if (!confirmed) return;

    try {
        const response = await fetch(`${INVENTORY_STOCK_API_BASE}/${Number(itemId)}`, {
            method: 'DELETE',
            credentials: 'same-origin',
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to delete item');
        }

        await loadInventory();
        Components.toast(payload.message || 'Item deleted successfully.', 'success');
    } catch (error) {
        console.error('Delete item error:', error);
        Components.alert(error.message || 'Failed to delete item.', 'danger');
    }
}

function openManageCategoriesModal() {
    const modal = document.getElementById('manageCategoriesModal');
    if (!modal) return;

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    setManageCategoriesMessage('');
    loadCategoryDepartmentOptions();
    resetCategoryForm();
    refreshCategoriesUI();
}

function closeManageCategoriesModal() {
    const modal = document.getElementById('manageCategoriesModal');
    if (!modal) return;

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    setManageCategoriesMessage('');
    resetCategoryForm();
}

function startEditCategory(id) {
    const category = getCategoryById(id);
    if (!category) {
        setManageCategoriesMessage('Category not found.', 'danger');
        return;
    }

    fillCategoryForm(category);
    setManageCategoriesMessage(`Editing category: ${category.name}`, 'info');
}

async function saveCategory() {
    const id = Number(document.getElementById('categoryIdInput')?.value || 0);
    const name = (document.getElementById('categoryNameInput')?.value || '').trim();
    const code = (document.getElementById('categoryCodeInput')?.value || '').trim().toLowerCase();
    const departmentRaw = document.getElementById('categoryDepartmentInput')?.value || '';
    const thresholdRaw = document.getElementById('categoryThresholdInput')?.value;
    const sortOrderRaw = document.getElementById('categorySortOrderInput')?.value;
    const allowOverride = document.getElementById('categoryAllowOverrideInput')?.checked ? 1 : 0;
    const isActive = document.getElementById('categoryIsActiveInput')?.checked ? 1 : 0;

    if (!name) {
        setManageCategoriesMessage('Category name is required.', 'danger');
        return;
    }

    const payload = {
        name,
        code: code || null,
        department_id: departmentRaw === '' ? null : Number(departmentRaw),
        default_low_stock_threshold: thresholdRaw === '' ? null : Number(thresholdRaw),
        sort_order: sortOrderRaw === '' ? 0 : Number(sortOrderRaw),
        allow_threshold_override: allowOverride,
        is_active: isActive
    };

    if (payload.default_low_stock_threshold !== null && payload.default_low_stock_threshold < 0) {
        setManageCategoriesMessage('Default threshold must be zero or greater.', 'danger');
        return;
    }

    const action = id > 0 ? 'update' : 'create';
    if (id > 0) {
        payload.id = id;
    }

    const saveButton = document.getElementById('saveCategoryButton');
    const originalLabel = saveButton ? saveButton.textContent : 'Save Category';
    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
    }

    try {
        const url    = id > 0 ? `${CATEGORIES_API_BASE}/${id}` : CATEGORIES_API_BASE;
        const method = id > 0 ? 'PATCH' : 'POST';
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to save category');
        }

        resetCategoryForm();
        setManageCategoriesMessage(result.message || 'Category saved.', 'success');
        await refreshCategoriesUI();
        await loadInventory();
    } catch (error) {
        console.error('Save category error:', error);
        setManageCategoriesMessage(error.message || 'Failed to save category.', 'danger');
    } finally {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = originalLabel;
        }
    }
}

function systemCategoryDeleteConfirm(categoryName) {
    return new Promise((resolve) => {
        const box = document.getElementById('manageCategoriesMessage');
        if (!box) { resolve(false); return; }
        box.hidden = false;
        box.className = 'inventory-modal-message inventory-modal-message-warning';
        box.innerHTML = `
            <span>Are you sure you want to delete <strong>${categoryName}</strong>? This cannot be undone.</span>
            <span style="display:inline-flex;gap:8px;margin-left:12px;">
                <button id="catDelYes" class="btn btn-danger btn-sm">Yes, Delete</button>
                <button id="catDelNo" class="btn btn-secondary btn-sm">Cancel</button>
            </span>
        `;
        box.querySelector('#catDelYes').onclick = () => { setManageCategoriesMessage(''); resolve(true); };
        box.querySelector('#catDelNo').onclick = () => { setManageCategoriesMessage(''); resolve(false); };
    });
}

async function deleteCategory(id) {
    if (!id) return;

    const category = getCategoryById(id);
    const confirmed = await systemCategoryDeleteConfirm(category ? category.name : 'this category');
    if (!confirmed) return;

    try {
        const response = await fetch(`${CATEGORIES_API_BASE}/${id}`, {
            method: 'DELETE',
            credentials: 'same-origin'
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            if (response.status === 409) {
                showCategoryDeleteBlockedMessage(category);
                return;
            }
            throw new Error(result.message || 'Failed to delete category');
        }

        setManageCategoriesMessage(result.message || 'Category deleted.', 'success');
        await refreshCategoriesUI();
        await loadInventory();
    } catch (error) {
        console.error('Delete category error:', error);
        setManageCategoriesMessage(error.message || 'Failed to delete category.', 'danger');
    }
}

function showCategoryDeleteBlockedMessage(category) {
    const box = document.getElementById('manageCategoriesMessage');
    if (!box) return;

    const count = category ? Number(category.total_items || 0) : 0;
    const itemNoun = count === 1 ? 'inventory item' : 'inventory items';
    const itemPronoun = count === 1 ? 'that item' : 'those items';

    box.hidden = false;
    box.className = 'inventory-modal-message inventory-modal-message-danger';
    box.innerHTML = `
        <div><strong>Category cannot be deleted</strong></div>
        <div style="margin-top:4px;">This category is currently assigned to ${count} ${itemNoun}. Reassign ${itemPronoun} to another category before deleting it.</div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <button id="catDelViewItems" type="button" class="btn btn-secondary btn-sm">View Items</button>
            <button id="catDelCancel" type="button" class="btn btn-secondary btn-sm">Cancel</button>
        </div>
    `;
    box.querySelector('#catDelViewItems').onclick = () => {
        setManageCategoriesMessage('');
        closeManageCategoriesModal();
        inventorySelectedCategoryId = category ? Number(category.id) : null;
        inventoryCurrentPage = 1;
        renderInventoryOverview();
        document.getElementById('inventory-browser-heading')?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    };
    box.querySelector('#catDelCancel').onclick = () => setManageCategoriesMessage('');
}

// NOTE: this function was formerly named/shaped for both creating a brand-new
// item AND editing an existing one. The "+ Add Item" entry point was removed
// by request, so the create-new-item branch (quantity + room_id in the
// payload, POST to ITEMS_API_BASE) was removed along with it. This is now
// edit-only, reached exclusively via startEditInventoryItem() (row kebab menu
// → Edit), and always PUTs to the metadata-only update endpoint.
async function saveAddItems() {
    const itemId = Number(document.getElementById('inventoryItemIdInput')?.value || 0);
    const categoryIdRaw = document.getElementById('inventoryCategorySelect')?.value || '';
    const name = document.getElementById('inventoryItemNameInput').value.trim();
    const brand = document.getElementById('inventoryBrandInput').value.trim();
    const model = document.getElementById('inventoryModelInput').value.trim();
    const unitType = document.getElementById('inventoryUnitTypeInput').value.trim();
    const reorderLevelRaw = document.getElementById('inventoryThresholdOverrideInput')?.value || '';
    const description = document.getElementById('inventoryDescriptionInput').value.trim();

    if (itemId <= 0) {
        setAddItemsMessage('No item selected to edit.', 'danger');
        return;
    }

    if (!name) {
        setAddItemsMessage('Please enter an item name.', 'danger');
        return;
    }

    if (!unitType) {
        setAddItemsMessage('Please enter a unit type.', 'danger');
        return;
    }

    if (reorderLevelRaw === '') {
        setAddItemsMessage('Please enter a low stock threshold.', 'danger');
        return;
    }

    if (Number(reorderLevelRaw) < 0) {
        setAddItemsMessage('Reorder level must be zero or greater.', 'danger');
        return;
    }

    // Quantity can only be changed via Manual Stock Adjustment, so it's
    // deliberately left out of this payload — the update endpoint has no
    // quantity field.
    const payload = {
        id: itemId,
        name,
        brand,
        model,
        unit_type: unitType,
        description,
        category_id: categoryIdRaw === '' ? null : Number(categoryIdRaw),
        reorder_level: Number(reorderLevelRaw)
    };

    const saveButton = document.getElementById('saveAddItemsButton');
    const originalLabel = saveButton.textContent;
    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';
    setAddItemsMessage('Saving item...');

    try {
        const response = await fetch(`${INVENTORY_STOCK_API_BASE}/${itemId}`, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to update item');
        }

        closeAddItemsModal();
        await loadInventory();
        await loadInventoryEntryHistory();
    } catch (error) {
        console.error('Edit item error:', error);
        setAddItemsMessage(error.message || 'Failed to update item.', 'danger');
    } finally {
        saveButton.disabled = false;
        saveButton.textContent = originalLabel;
    }
}

// TASK 6B PHASE 2 — saveInventoryRoom() was removed. Inventory rooms are no
// longer a user-facing concept, so the UI never creates one. The
// POST /api/inventory-rooms route itself is retained untouched.

function renderItems(items, totalItems) {
    const container = document.getElementById('inventory-container');
    if (!container) return;

    // TASK 31 — `items` is now just the current page's slice; the badge
    // must reflect the total filtered count, not items.length. Callers
    // that still pass a single array (none currently do) fall back to
    // items.length so this stays backward compatible.
    const badgeCount = typeof totalItems === 'number' ? totalItems : (Array.isArray(items) ? items.length : 0);

    if (!Array.isArray(items) || items.length === 0) {
        container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>No inventory items found.</strong><span>Inventory records will appear here after items are added.</span></div>';
        setText('inventory-count', `${badgeCount} items`);
        return;
    }

    let html = '<div class="inventory-items-table-wrap">';
    html += '<table class="table inventory-items-table">';
    html += '<thead><tr>';
    html += '<th>ID</th><th>Item</th><th>Category</th><th>Quantity</th><th>Threshold</th><th>Status</th><th>Description</th><th>Updated</th><th>Actions</th>';
    html += '</tr></thead><tbody>';

    items.forEach((item) => {
        const normalizedStatus = normalizeInventoryStatus(item.status);
        const status = normalizedStatus === 'out_of_stock'
            ? 'OUT OF STOCK'
            : (normalizedStatus === 'low_stock' ? 'LOW STOCK' : 'IN STOCK');
        const statusClass = getStatusClass(normalizedStatus);
        const updated = item.updated_at ? new Date(String(item.updated_at).replace(' ', 'T')).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';
        const threshold = resolveItemThreshold(item);
        const quantityValue = Number(item.quantity ?? 0);
        html += `<tr data-row-item-id="${Number(item.id)}">`;
        html += `<td class="inventory-item-id">#${item.id ?? '-'}</td>`;
        html += `<td class="inventory-item-cell"><div class="inventory-item-name">${inventoryEscapeHtml(item.name || 'Unnamed Item')}</div>${item.brand ? `<div class="inventory-item-meta">Brand: ${inventoryEscapeHtml(item.brand)}</div>` : ''}${item.model ? `<div class="inventory-item-meta">Model: ${inventoryEscapeHtml(item.model)}</div>` : ''}${item.unit_type ? `<div class="inventory-item-meta">Unit: ${inventoryEscapeHtml(item.unit_type)}</div>` : ''}</td>`;
        html += `<td class="inventory-table-cell-muted">${item.category_name || 'Uncategorized'}</td>`;
        html += `<td class="inventory-table-cell-numeric">${quantityValue}</td>`;
        html += `<td class="inventory-table-cell-numeric">${formatThreshold(threshold)}</td>`;
        html += `<td><span class="badge inventory-status-badge ${statusClass}">${status}</span></td>`;
        html += `<td class="inventory-table-cell-muted">${inventoryEscapeHtml(item.description || '-')}</td>`;
        html += `<td class="inventory-table-cell-muted">${updated}</td>`;
        // TASK 4 — when the user has more than one action available the row
        // actions collapse into a single ⋮ menu. Roles without CAN_ADD_ITEMS
        // only ever had History (Edit/Delete were never rendered for them),
        // so they keep the plain History button: wrapping a single action in
        // a dropdown would cost a click and buy nothing. The authorization
        // gate itself is unchanged — still exactly CAN_ADD_ITEMS.
        html += `<td class="inventory-actions-cell">
            <div class="inventory-table-actions">
                ${CAN_ADD_ITEMS ? `<button type="button" class="inventory-action-kebab" data-action="row-menu" data-item-id="${Number(item.id)}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${inventoryEscapeHtml(item.name || 'item')}" title="Actions">&#8942;</button>` : `<button type="button" class="btn btn-secondary btn-sm inventory-action-btn is-history" data-action="history" data-item-id="${Number(item.id)}">History</button>`}
            </div>
        </td>`;
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
    setText('inventory-count', `${badgeCount} items`);

    // Read-only roles still render a bare History button (no menu).
    container.querySelectorAll('[data-action="history"]').forEach((button) => {
        button.addEventListener('click', () => openInventoryItemHistory(Number(button.getAttribute('data-item-id') || 0)));
    });
    if (CAN_ADD_ITEMS) {
        container.querySelectorAll('[data-action="row-menu"]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                toggleInventoryActionMenu(button, Number(button.getAttribute('data-item-id') || 0));
            });
        });
    }
}

// TASK 4 — single shared row-action menu. One element is reused by every
// row (rather than one dropdown per row) and it lives directly on <body>
// so that #inventory-container's `overflow: auto` cannot clip it and no
// ancestor `transform` can capture its fixed positioning. Actions dispatch
// to the exact same functions the old inline buttons called, so behaviour
// and authorization are unchanged.
let inventoryActionMenuEl = null;
let inventoryActionMenuTrigger = null;

function getInventoryActionMenu() {
    if (inventoryActionMenuEl) return inventoryActionMenuEl;

    const menu = document.createElement('div');
    menu.className = 'inventory-action-menu';
    menu.id = 'inventoryRowActionMenu';
    menu.setAttribute('role', 'menu');

    [
        { label: 'History', action: 'history', danger: false },
        { label: 'Edit', action: 'edit', danger: false },
        { label: 'Delete', action: 'delete', danger: true },
    ].forEach((entry) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'inventory-action-menu-item' + (entry.danger ? ' is-danger' : '');
        button.setAttribute('role', 'menuitem');
        button.dataset.menuAction = entry.action;
        button.textContent = entry.label;
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const itemId = Number(menu.dataset.itemId || 0);
            closeInventoryActionMenu();
            if (entry.action === 'history') openInventoryItemHistory(itemId);
            else if (entry.action === 'edit') startEditInventoryItem(itemId);
            else if (entry.action === 'delete') deleteInventoryItem(itemId);
        });
        menu.appendChild(button);
    });

    document.body.appendChild(menu);
    inventoryActionMenuEl = menu;
    return menu;
}

function closeInventoryActionMenu() {
    if (!inventoryActionMenuEl) return;
    inventoryActionMenuEl.classList.remove('is-open');
    if (inventoryActionMenuTrigger) {
        inventoryActionMenuTrigger.setAttribute('aria-expanded', 'false');
        inventoryActionMenuTrigger = null;
    }
}

function positionInventoryActionMenu(trigger, menu) {
    const rect = trigger.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const gap = 4;

    // Right-align to the trigger, clamped into the viewport.
    let left = rect.right - menuRect.width;
    left = Math.max(8, Math.min(left, window.innerWidth - menuRect.width - 8));

    // Below by default; flip above when there is not enough room.
    let top = rect.bottom + gap;
    if (top + menuRect.height > window.innerHeight - 8) {
        const above = rect.top - menuRect.height - gap;
        if (above >= 8) top = above;
        else top = Math.max(8, window.innerHeight - menuRect.height - 8);
    }

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;
}

function toggleInventoryActionMenu(trigger, itemId) {
    const menu = getInventoryActionMenu();
    const wasOpenForThisRow = menu.classList.contains('is-open') && inventoryActionMenuTrigger === trigger;
    closeInventoryActionMenu();
    if (wasOpenForThisRow) return;

    menu.dataset.itemId = String(itemId);
    menu.classList.add('is-open');
    inventoryActionMenuTrigger = trigger;
    trigger.setAttribute('aria-expanded', 'true');
    positionInventoryActionMenu(trigger, menu);
}

document.addEventListener('click', closeInventoryActionMenu);
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const trigger = inventoryActionMenuTrigger;
    closeInventoryActionMenu();
    if (trigger) trigger.focus();
});
// The menu is fixed-positioned, so it does not travel with the table's own
// scroll container or the page — close it instead of letting it detach.
window.addEventListener('scroll', closeInventoryActionMenu, true);
window.addEventListener('resize', closeInventoryActionMenu);

// TASK 31/33 — Inventory Items pagination controls. Mirrors reports.php's
// renderPagination() exactly (same summary line + first/prev/page-numbers/
// next/last button structure + rows-per-page select, same canonical
// .pagination-list/.pagination-link classes from design-system-components.css),
// targeting the #inventory-pagination-container <nav> placed right after
// #inventory-container in the static HTML.
function renderInventoryItemsPagination(totalItems, totalPages, startIndex) {
    const container = document.getElementById('inventory-pagination-container');
    if (!container) return;

    if (totalItems === 0) {
        container.innerHTML = '';
        return;
    }

    const endIndex = Math.min(startIndex + inventoryRowsPerPage, totalItems);
    const pageButtons = [];
    for (let page = 1; page <= totalPages; page += 1) {
        const isActive = inventoryCurrentPage === page;
        pageButtons.push(`
            <li><button type="button" class="inventory-pagination-btn pagination-link ${isActive ? 'active is-active' : ''}" data-inventory-page="${page}" ${isActive ? 'aria-current="page"' : ''} aria-label="Page ${page}">${page}</button></li>
        `);
    }

    container.innerHTML = `
        <div class="inventory-pagination-summary">Showing <strong>${startIndex + 1}-${endIndex}</strong> of <strong>${totalItems}</strong> items</div>
        <ul class="inventory-pagination-controls pagination-list">
            <li><button type="button" class="inventory-pagination-btn pagination-link pagination-prev" data-inventory-page="1" ${inventoryCurrentPage === 1 ? 'disabled' : ''} aria-label="First page">&laquo;</button></li>
            <li><button type="button" class="inventory-pagination-btn pagination-link pagination-prev" data-inventory-page="${inventoryCurrentPage - 1}" ${inventoryCurrentPage === 1 ? 'disabled' : ''} aria-label="Previous page">&lsaquo;</button></li>
            ${pageButtons.join('')}
            <li><button type="button" class="inventory-pagination-btn pagination-link pagination-next" data-inventory-page="${inventoryCurrentPage + 1}" ${inventoryCurrentPage === totalPages ? 'disabled' : ''} aria-label="Next page">&rsaquo;</button></li>
            <li><button type="button" class="inventory-pagination-btn pagination-link pagination-next" data-inventory-page="${totalPages}" ${inventoryCurrentPage === totalPages ? 'disabled' : ''} aria-label="Last page">&raquo;</button></li>
        </ul>
        <label class="inventory-pagination-size" for="inventory-rows-per-page-select">
            Rows per page:
            <select id="inventory-rows-per-page-select" aria-label="Rows per page">
                <option value="5" ${inventoryRowsPerPage === 5 ? 'selected' : ''}>5</option>
                <option value="10" ${inventoryRowsPerPage === 10 ? 'selected' : ''}>10</option>
                <option value="15" ${inventoryRowsPerPage === 15 ? 'selected' : ''}>15</option>
                <option value="20" ${inventoryRowsPerPage === 20 ? 'selected' : ''}>20</option>
            </select>
        </label>
    `;
}

// TASK 18 — mirrors highlightUserFromQuery() in users.php.
function highlightInventoryItemFromQuery() {
    const params = new URLSearchParams(window.location.search);
    const highlightId = Number(params.get('highlight') || 0);
    if (!highlightId) return;

    const row = document.querySelector(`[data-row-item-id="${highlightId}"]`);
    if (!row) return;

    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.classList.add('inventory-row-highlight');
    setTimeout(() => row.classList.remove('inventory-row-highlight'), 3000);
}

async function loadInventory() {
    const container = document.getElementById('inventory-container');
    if (container) {
        container.innerHTML = '<div class="ui-empty-state ui-fade-in"><strong>Loading inventory...</strong><div class="ui-skeleton-list inventory-loading-skeleton"><div class="ui-skeleton-row w-90"></div><div class="ui-skeleton-row w-75"></div><div class="ui-skeleton-row w-55"></div></div></div>';
    }

    try {
        const response = await fetch(INVENTORY_STOCK_API_BASE, {
            credentials: 'same-origin'
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Failed to load inventory');
        }

        inventoryAllItems = Array.isArray(payload.data?.items) ? payload.data.items : [];
        renderInventoryOverview();
    } catch (error) {
        if (container) {
            container.innerHTML = '<div class="alert alert-danger ui-fade-in">Could not load inventory right now.</div>';
        }
        console.error('Inventory load error:', error);
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        await refreshCategoriesUI();
    } catch (error) {
        console.error('Inventory bootstrap error (categories):', error);
    }

    try {
        await fetchInventoryDepartments();
        await loadInventoryEntryDepartmentOptions();
    } catch (error) {
        console.error('Inventory bootstrap error (departments):', error);
    }

    try {
        await fetchInventoryEntryRooms();
        await loadInventoryEntryRoomOptions();
    } catch (error) {
        console.error('Inventory bootstrap error (laboratory / room):', error);
    }

    await loadInventory();
    // TASK 18 — an 'inventory' notification (item became Low Stock / Out of
    // Stock) lands here via ?highlight=<item_id>. getVisibleInventoryItems()
    // shows every item by default (no category filter pre-applied), so the
    // row is already rendered — just find and highlight it, mirroring
    // highlightUserFromQuery() in users.php.
    highlightInventoryItemFromQuery();
    await loadInventoryEntryHistory();

    const inventoryEntryButton = document.getElementById('openInventoryEntryButton');
    const closeButton = document.getElementById('closeAddItemsModalButton');
    const cancelButton = document.getElementById('cancelAddItemsButton');
    const saveButton = document.getElementById('saveAddItemsButton');
    const inventoryEntryModal = document.getElementById('inventoryEntryModal');
    const closeInventoryEntryModalButton = document.getElementById('closeInventoryEntryModalButton');
    const cancelInventoryEntryButton = document.getElementById('cancelInventoryEntryButton');
    const saveInventoryEntryButton = document.getElementById('saveInventoryEntryButton');
    const openAdjustStockButton = document.getElementById('openAdjustStockButton');
    const closeAdjustStockModalButton = document.getElementById('closeAdjustStockModalButton');
    const cancelAdjustStockButton = document.getElementById('cancelAdjustStockButton');
    const saveAdjustStockButton = document.getElementById('saveAdjustStockButton');
    const adjustStockItemSelect = document.getElementById('adjustStockItemSelect');
    const adjustStockDirectionSelect = document.getElementById('adjustStockDirection');
    const adjustStockQuantityInput = document.getElementById('adjustStockQuantity');
    const addItemsModal = document.getElementById('addItemsModal');
    const inventoryHistoryModal = document.getElementById('inventoryItemHistoryModal');
    const closeInventoryHistoryModalButton = document.getElementById('closeInventoryHistoryModalButton');
    const closeInventoryHistoryFooterButton = document.getElementById('closeInventoryHistoryFooterButton');
    const categorySelect = document.getElementById('inventoryCategorySelect');
    const inventorySearchInput = document.getElementById('inventory-category-search');
    const inventoryEntrySearchInput = document.getElementById('inventory-entry-search');
    const inventoryEntryFilterCategory = document.getElementById('inventory-entry-filter-category');
    const inventoryEntryFilterDepartment = document.getElementById('inventory-entry-filter-department');
    const inventoryEntryFilterRoom = document.getElementById('inventory-entry-filter-room');
    const inventoryEntryFilterDateFrom = document.getElementById('inventory-entry-filter-date-from');
    const inventoryEntryFilterDateTo = document.getElementById('inventory-entry-filter-date-to');
    const inventoryEntryClearFiltersButton = document.getElementById('inventory-entry-clear-filters');
    const inventoryItemsStatusFilterInput = document.getElementById('inventory-items-status-filter');
    const inventoryItemsClearFiltersButton = document.getElementById('inventory-items-clear-filters');
    const inventoryQuantityInput = document.getElementById('inventoryQuantityInput');
    const inventoryThresholdInput = document.getElementById('inventoryThresholdOverrideInput');
    const categorySearchInput = document.getElementById('categorySearchInput');
    const categoryStatusFilter = document.getElementById('categoryStatusFilter');

    // 'Inventory Entry' modal POSTs to a deprecated backend endpoint
    // (InventoryStockController::createEntry() returns 410 and is not even
    // routed for POST — see routes/web.php). Stock intake is now handled
    // exclusively via Purchase Receipts, so the button navigates there
    // instead of opening the non-functional modal.
    if (inventoryEntryButton) inventoryEntryButton.addEventListener('click', () => {
        window.location.href = window.SFMS_PUBLIC_URL('/purchase-receipts');
    });
    if (closeButton) closeButton.addEventListener('click', closeAddItemsModal);
    if (cancelButton) cancelButton.addEventListener('click', closeAddItemsModal);
    if (saveButton) saveButton.addEventListener('click', saveAddItems);
    if (closeInventoryHistoryModalButton) closeInventoryHistoryModalButton.addEventListener('click', closeInventoryHistoryModal);
    if (closeInventoryHistoryFooterButton) closeInventoryHistoryFooterButton.addEventListener('click', closeInventoryHistoryModal);
    if (closeInventoryEntryModalButton) closeInventoryEntryModalButton.addEventListener('click', closeInventoryEntryModal);
    if (cancelInventoryEntryButton) cancelInventoryEntryButton.addEventListener('click', closeInventoryEntryModal);
    if (saveInventoryEntryButton) saveInventoryEntryButton.addEventListener('click', saveInventoryEntry);
    if (openAdjustStockButton) openAdjustStockButton.addEventListener('click', openAdjustStockModal);
    if (closeAdjustStockModalButton) closeAdjustStockModalButton.addEventListener('click', closeAdjustStockModal);
    if (cancelAdjustStockButton) cancelAdjustStockButton.addEventListener('click', closeAdjustStockModal);
    if (saveAdjustStockButton) saveAdjustStockButton.addEventListener('click', saveAdjustStock);
    if (adjustStockItemSelect) adjustStockItemSelect.addEventListener('change', updateAdjustStockReview);
    if (adjustStockDirectionSelect) adjustStockDirectionSelect.addEventListener('change', updateAdjustStockReview);
    if (adjustStockQuantityInput) adjustStockQuantityInput.addEventListener('input', updateAdjustStockReview);
    if (categorySelect) categorySelect.addEventListener('change', applySelectedCategoryRules);
    if (inventorySearchInput) {
        inventorySearchInput.addEventListener('input', () => {
            inventorySearchTerm = inventorySearchInput.value.trim().toLowerCase();
            inventoryCurrentPage = 1; // TASK 31 — new search term, start back at page 1
            renderInventoryOverview();
        });
    }
    if (inventoryItemsStatusFilterInput) {
        inventoryItemsStatusFilterInput.addEventListener('change', () => {
            inventoryItemsStatusFilter = String(inventoryItemsStatusFilterInput.value || '').toLowerCase();
            inventoryCurrentPage = 1; // TASK 31 — filtered set changed, start back at page 1
            renderInventoryOverview();
        });
    }
    const inventoryItemsCategoryFilterInput = document.getElementById('inventory-items-category-filter');
    if (inventoryItemsCategoryFilterInput) {
        inventoryItemsCategoryFilterInput.addEventListener('change', () => {
            const val = inventoryItemsCategoryFilterInput.value;
            inventoryItemsCategoryFilter = val === '' ? null : Number(val);
            inventoryCurrentPage = 1;
            renderInventoryOverview();
        });
    }
    if (inventoryItemsClearFiltersButton) {
        inventoryItemsClearFiltersButton.addEventListener('click', () => {
            inventoryItemsStatusFilter = '';
            inventoryItemsCategoryFilter = null;
            if (inventoryItemsStatusFilterInput) inventoryItemsStatusFilterInput.value = '';
            if (inventoryItemsCategoryFilterInput) inventoryItemsCategoryFilterInput.value = '';
            inventoryCurrentPage = 1;
            renderInventoryOverview();
        });
    }
    // TASK 31 — delegated pagination click handler, mirrors reports.php's
    // #pagination-container listener (attached once here rather than
    // re-attached per render, since the container's innerHTML is fully
    // replaced by renderInventoryItemsPagination() on every render anyway).
    const inventoryPaginationContainer = document.getElementById('inventory-pagination-container');
    if (inventoryPaginationContainer) {
        inventoryPaginationContainer.addEventListener('click', (event) => {
            const pageButton = event.target.closest('[data-inventory-page]');
            if (!pageButton || pageButton.hasAttribute('disabled')) return;

            const nextPage = Number(pageButton.dataset.inventoryPage);
            if (Number.isNaN(nextPage) || nextPage < 1) return;

            inventoryCurrentPage = nextPage;
            renderInventoryOverview();
        });

        // TASK 33 — delegated rows-per-page change handler, mirrors reports.php's
        // #pagination-container 'change' listener (same reset-to-page-1 behavior).
        inventoryPaginationContainer.addEventListener('change', (event) => {
            if (event.target.id !== 'inventory-rows-per-page-select') return;

            const nextRows = Number(event.target.value);
            if (Number.isNaN(nextRows) || nextRows < 1) return;

            inventoryRowsPerPage = nextRows;
            inventoryCurrentPage = 1;
            renderInventoryOverview();
        });
    }
    if (inventoryEntrySearchInput) inventoryEntrySearchInput.addEventListener('input', loadInventoryEntryHistory);
    if (inventoryEntryFilterCategory) inventoryEntryFilterCategory.addEventListener('change', loadInventoryEntryHistory);
    if (inventoryEntryFilterDepartment) inventoryEntryFilterDepartment.addEventListener('change', loadInventoryEntryHistory);
    if (inventoryEntryFilterRoom) inventoryEntryFilterRoom.addEventListener('change', loadInventoryEntryHistory);
    if (inventoryEntryFilterDateFrom) inventoryEntryFilterDateFrom.addEventListener('change', loadInventoryEntryHistory);
    if (inventoryEntryFilterDateTo) inventoryEntryFilterDateTo.addEventListener('change', loadInventoryEntryHistory);
    if (inventoryEntryClearFiltersButton) {
        inventoryEntryClearFiltersButton.addEventListener('click', () => {
            if (inventoryEntrySearchInput) inventoryEntrySearchInput.value = '';
            if (inventoryEntryFilterCategory) inventoryEntryFilterCategory.value = '';
            if (inventoryEntryFilterDepartment) inventoryEntryFilterDepartment.value = '';
            if (inventoryEntryFilterRoom) inventoryEntryFilterRoom.value = '';
            if (inventoryEntryFilterDateFrom) inventoryEntryFilterDateFrom.value = '';
            if (inventoryEntryFilterDateTo) inventoryEntryFilterDateTo.value = '';
            loadInventoryEntryHistory();
        });
    }
    if (categorySearchInput) {
        categorySearchInput.addEventListener('input', () => {
            categoryAdminSearchTerm = categorySearchInput.value.trim().toLowerCase();
            renderCategoriesList();
        });
    }
    if (categoryStatusFilter) {
        categoryStatusFilter.addEventListener('change', () => {
            categoryAdminStatusFilter = String(categoryStatusFilter.value || 'all').toLowerCase();
            renderCategoriesList();
        });
    }

    if (addItemsModal) {
        addItemsModal.addEventListener('click', (event) => {
            if (event.target === addItemsModal) {
                closeAddItemsModal();
            }
        });
    }

    if (inventoryHistoryModal) {
        inventoryHistoryModal.addEventListener('click', (event) => {
            if (event.target === inventoryHistoryModal) {
                closeInventoryHistoryModal();
            }
        });
    }

    if (inventoryEntryModal) {
        inventoryEntryModal.addEventListener('click', (event) => {
            if (event.target === inventoryEntryModal) {
                closeInventoryEntryModal();
            }
        });
    }

    if (CAN_MANAGE_CATEGORIES) {
        const manageButton = document.getElementById('manageCategoriesButton');
        const closeManageButton = document.getElementById('closeManageCategoriesModalButton');
        const saveCategoryButton = document.getElementById('saveCategoryButton');
        const resetCategoryButton = document.getElementById('resetCategoryFormButton');
        const manageModal = document.getElementById('manageCategoriesModal');

        if (manageButton) manageButton.addEventListener('click', openManageCategoriesModal);
        if (closeManageButton) closeManageButton.addEventListener('click', closeManageCategoriesModal);
        if (saveCategoryButton) saveCategoryButton.addEventListener('click', saveCategory);
        if (resetCategoryButton) resetCategoryButton.addEventListener('click', resetCategoryForm);

        if (manageModal) {
            manageModal.addEventListener('click', (event) => {
                if (event.target === manageModal) {
                    closeManageCategoriesModal();
                }
            });
        }
    }

    if (inventoryQuantityInput) {
        inventoryQuantityInput.addEventListener('input', updateInventoryStatusPreview);
    }
    if (inventoryThresholdInput) {
        inventoryThresholdInput.addEventListener('input', updateInventoryStatusPreview);
    }
});
</script>

</body>
</html>
