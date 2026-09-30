<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\DamageReportController;
use App\Http\Controllers\Api\DeploymentTrackingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\InventoryCategoryController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PreventiveMaintenanceController;
use App\Http\Controllers\Api\PurchaseReceiptController;
use App\Http\Controllers\Api\ReplacementTrackingController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SchoolSettingsController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\InventoryStockController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureApiAuthenticated;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

// Authentication routes (no auth middleware)
Route::get('/', function () {
    if (request()->session()->has('auth_user') || request()->session()->has('user')) {
        return redirect('/frontend/pages/dashboard.php');
    }
    return redirect('/frontend/pages/index.php');
})->name('login');

Route::get('/login', function () {
    if (request()->session()->has('auth_user') || request()->session()->has('user')) {
        return redirect('/frontend/pages/dashboard.php');
    }
    return redirect('/frontend/pages/index.php');
})->name('auth.login');

// Web routes that require authentication
Route::middleware(EnsureApiAuthenticated::class)->group(function (): void {
    Route::get('/dashboard', fn() => redirect('/frontend/pages/dashboard.php'))->name('dashboard');
    Route::get('/reports', fn() => redirect('/frontend/pages/reports.php'))->name('reports.index');
    Route::get('/reports/create', fn() => redirect('/frontend/pages/create-report.php'))->name('reports.create');
    Route::get('/reports/{id}', fn($id) => redirect('/frontend/pages/report-detail.php?id=' . (int) $id))->name('reports.show');
    Route::get('/profile', fn() => redirect('/frontend/pages/profile.php'))->name('profile');
    Route::get('/users', fn() => redirect('/frontend/pages/users.php'))->name('users.index');
    Route::get('/notifications', fn() => redirect('/frontend/pages/notifications-center.php'))->name('notifications');
    Route::get('/inventory', fn() => redirect('/frontend/pages/inventory.php'))->name('inventory.index');
    Route::get('/suppliers/manage', fn() => redirect('/frontend/pages/suppliers-manage.php'))->name('suppliers.manage');
    // TASK 25 — dedicated Semester Settings page (replaces the old
    // "Change Semester" dashboard modal). Page itself re-checks for
    // super_admin and redirects otherwise; this route only requires login.
    Route::get('/settings/semester', fn() => redirect('/frontend/pages/semester-settings.php'))->name('settings.semester');
    Route::get('/dispatches', fn() => redirect('/frontend/pages/dispatches.php'))->name('dispatches.index');
    Route::get('/dispatches/create', fn() => redirect('/frontend/pages/dispatch-create.php'))->name('dispatches.create');
    Route::get('/dispatches/{id}', function ($id) { return redirect('/frontend/pages/dispatch-detail.php?id=' . (int)$id); })->name('dispatches.show');
    Route::get('/damage-reports', fn() => redirect('/frontend/pages/damage-reports.php'))->name('damage-reports.index');
    // TASK 33 PHASE 11 — the standalone legacy Damage Report creation route
    // (and its target page, damage-report-create.php) was retired here.
    // Phase 10's verification confirmed zero live navigation referenced
    // this route (sidebar.php only had the page in an active-nav highlight
    // array, never as an href target) and damage-reports.php has no Create
    // link of its own. The unified Create Report workflow (/reports/create
    // above) already carries every capability the legacy page had,
    // including Severity Level, Image Upload, and Repair Notes (Task 33
    // Phases 7 and 9). See TASK_33_PHASE_10_LEGACY_DAMAGE_REPORT_RETIREMENT_VERIFICATION_REPORT.md
    // and TASK_33_PHASE_11_LEGACY_DAMAGE_REPORT_RETIREMENT_IMPLEMENTATION_REPORT.md.
    Route::get('/damage-reports/{id}', fn($id) => redirect('/frontend/pages/damage-report-detail.php?id=' . (int)$id))->name('damage-reports.show');
    Route::get('/damage-reports/{id}/update', fn($id) => redirect('/frontend/pages/damage-report-update.php?id=' . (int)$id))->name('damage-reports.update');
    // TASK 13 PHASE 2 — the five Repair Request page routes (/repairs,
    // /repairs/{id}, and its /assign, /update and /replacement variants) were
    // removed here. Each one was a bare redirect into a page that TASK 12
    // deleted, so every one of them had already become a guaranteed 404 for
    // any user who reached it.
    //
    // NOTE the next line: /replacement-tracking is INVENTORY, not Repair. It
    // is one hyphen away from the retired /repairs/{id}/replacement route and
    // is deliberately kept.
    Route::get('/replacement-tracking', fn() => redirect('/frontend/pages/replacement-tracking.php'))->name('inventory.replacement-tracking');
    // TASK 98.2 fix: this used to redirect to '/frontend/pages/settings.php',
    // which does not exist (404) — confirmed no page in public/frontend/pages/
    // is named settings.php, and no live nav link points at this route or
    // path. account.php is the actual account/settings page the sidebar's
    // "Account" nav item links to (see sidebar.php), so repoint here instead
    // of leaving a dead redirect target.
    Route::get('/settings', fn() => redirect('/frontend/pages/account.php'))->name('settings');
    Route::get('/maintenance-dashboard', fn() => redirect('/frontend/pages/maintenance-dashboard.php'))->name('maintenance.dashboard');
    // TASK LEGACY QUARTET PHASE 2 — the /maintenance-reports route (and its
    // target page, maintenance-reports-list.php) was retired here. The page
    // itself only performed a redirect to reports.php (forwarding the query
    // string) after an auth/role check; all 5 live links that used to point
    // here (sidebar "All Reports" for Maintenance Staff, Super Admin
    // dashboard "Last Month Reports", both Maintenance dashboard report
    // links, and maintenance-report-detail.php's "Back to Reports") were
    // repointed directly at reports.php first, eliminating the redirect hop.
    // See TASK_LEGACY_QUARTET_PHASE2_MAINTENANCE_REPORTS_LIST_RETIREMENT_IMPLEMENTATION_REPORT.md.
    Route::get('/maintenance-reports/{id}', fn($id) => redirect('/frontend/pages/maintenance-report-detail.php?id=' . (int) $id))->name('maintenance.reports.show');
    Route::get('/staff-dashboard', fn() => redirect('/frontend/pages/staff-dashboard.php'))->name('staff.dashboard');
    Route::get('/super-admin-dashboard', fn() => redirect('/frontend/pages/super-admin-dashboard.php'))->name('superadmin.dashboard');
    Route::get('/activity-log', fn() => redirect('/frontend/pages/activity-log.php'))->name('activity.log');
    Route::get('/activity-logs', fn() => redirect('/frontend/pages/activity-log.php'))->name('activity-logs.index');
    Route::get('/activity-logs/{id}', fn($id) => redirect('/frontend/pages/activity-log-detail.php?id=' . (int) $id))->name('activity-logs.show');
    Route::get('/analytics', fn() => redirect('/frontend/pages/analytics-dashboard.php'))->name('analytics');
    Route::get('/buildings-overview', fn() => redirect('/frontend/pages/buildings-overview.php'))->name('buildings.overview');
    Route::get('/inventory-transactions', fn() => redirect('/frontend/pages/inventory-transactions.php'))->name('inventory.transactions');
    Route::get('/inventory-reports', fn() => redirect('/frontend/pages/inventory-reports.php'))->name('inventory.reports');
    Route::get('/purchase-receipts', fn() => redirect('/frontend/pages/purchase-receipts.php'))->name('purchase-receipts.page');
    Route::get('/deployment-tracking', fn() => redirect('/frontend/pages/deployment-tracking.php'))->name('deployment.tracking');
    Route::get('/preventive-maintenance', fn() => redirect('/frontend/pages/preventive-maintenance.php'))->name('preventive-maintenance.page');

    // Logout route (web)
    Route::get('/logout', function () {
        // Clear native PHP $_SESSION so legacy frontend pages lose access too
        $_SESSION = [];

        request()->session()->forget('auth_user');
        request()->session()->forget('user');
        request()->session()->forget('user_id');
        request()->session()->forget('role');
        request()->session()->forget('last_activity');
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/frontend/pages/index.php');
    })->name('logout');
});

// Compatibility redirect for legacy hardcoded URLs that still include the old
// /laravel_app/public segment after the app was moved to the project root.
Route::get('/School_Facility_Maintenance_System/laravel_app/public/{path}', function (string $path) {
    $qs  = request()->getQueryString();
    $url = '/public/' . ltrim($path, '/');
    return redirect($qs ? $url . '?' . $qs : $url);
})->where('path', '.*');

// Keep the new /public-prefixed path working consistently as well.
Route::get('/School_Facility_Maintenance_System/{path}', function (string $path) {
    $qs  = request()->getQueryString();
    $url = '/' . ltrim($path, '/');
    return redirect($qs ? $url . '?' . $qs : $url);
})->where('path', '.*');

Route::prefix('api')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('forgot_password_request', [AuthController::class, 'forgotPasswordRequest']);
        Route::post('logout', [AuthController::class, 'logout'])->middleware(EnsureApiAuthenticated::class);
        Route::get('check', [AuthController::class, 'check'])->middleware(EnsureApiAuthenticated::class);
    });

    Route::middleware([EnsureApiAuthenticated::class])->group(function (): void {
        Route::get('reports',          [ReportController::class, 'index']);
        // RBAC POLICY UPDATE — Administrator (super_admin) reviews/assigns/
        // monitors reports but does not submit them; Head Maintenance
        // (maintenance_admin) and Maintenance Staff are the report submitters.
        Route::post('reports',         [ReportController::class, 'store'])
            ->middleware(EnsureRole::class . ':maintenance_admin,maintenance_staff');
        Route::get('reports/recent',   [ReportController::class, 'recent']);
        Route::get('reports/{report}', [ReportController::class, 'show']);
        Route::patch('reports/{report}', [ReportController::class, 'update']);
        Route::delete('reports/{report}', [ReportController::class, 'destroy']);

        // Report Archive — only the Administrator may reopen a view-only
        // past-term report, or make a reopened one view-only again.
        Route::post('reports/{report}/archive-reopen', [ReportController::class, 'archiveReopen'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::post('reports/{report}/archive-lock', [ReportController::class, 'archiveLock'])
            ->middleware(EnsureRole::class . ':super_admin');

        // TASK 16 — single-row global School Settings (school_year /
        // current_semester) used to scope dashboard KPI statistics to the
        // current semester. Any authenticated user may read it; only
        // super_admin may change it.
        Route::get('school-settings', [SchoolSettingsController::class, 'show']);
        Route::put('school-settings', [SchoolSettingsController::class, 'update'])
            ->middleware(EnsureRole::class . ':super_admin');
        // Report Archive — recorded School Years / semesters (read-only).
        Route::get('academic-sessions', [SchoolSettingsController::class, 'sessions']);

        Route::prefix('dashboard')->group(function (): void {
            Route::get('stats', [DashboardController::class, 'stats']);
            // TASK — Technician Workload card. Read-only workload visibility
            // for the two management dashboards. Guarded by the SAME
            // centralized EnsureRole middleware every other role-restricted
            // route here uses, rather than a role check inside the controller
            // — Maintenance Staff is refused before the handler runs. This
            // grants no assignment capability; it returns counts only.
            Route::get('technician-workload', [DashboardController::class, 'technicianWorkload'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
            Route::prefix('maintenance')->group(function (): void {
                Route::get('stats',     [DashboardController::class, 'maintenanceStats']);
                Route::get('charts',    [DashboardController::class, 'maintenanceCharts']);
                Route::get('personnel', [DashboardController::class, 'maintenancePersonnel']);
                Route::get('activity',  [DashboardController::class, 'maintenanceActivity']);
            });
            Route::prefix('super-admin')->middleware(EnsureRole::class . ':super_admin')->group(function (): void {
                Route::get('stats',    [DashboardController::class, 'superAdminStats']);
                Route::get('charts',   [DashboardController::class, 'superAdminCharts']);
                Route::get('overview', [DashboardController::class, 'superAdminOverview']);
                Route::get('activity', [DashboardController::class, 'superAdminActivity']);
            });
        });

        Route::get('departments', [DepartmentController::class, 'index']);

        Route::get('rooms',         [RoomController::class, 'index']);
        // TASK 35 — Buildings Overview RBAC: only Administrator (super_admin)
        // may create/modify/delete rooms. Previously also allowed
        // maintenance_admin (Head Maintenance), which must now be view-only.
        Route::post('rooms',        [RoomController::class, 'store'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::patch('rooms/{id}',  [RoomController::class, 'update'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::delete('rooms/{id}', [RoomController::class, 'destroy'])
            ->middleware(EnsureRole::class . ':super_admin');

        Route::get('buildings',                                  [BuildingController::class, 'index']);
        // TASK 35 — Buildings Overview RBAC: only Administrator (super_admin)
        // may create/modify/delete buildings and floors. Previously also
        // allowed maintenance_admin (Head Maintenance) and maintenance_staff,
        // which must now be view-only.
        Route::post('buildings',                                 [BuildingController::class, 'store'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::patch('buildings/{id}',                           [BuildingController::class, 'update'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::delete('buildings/{id}',                          [BuildingController::class, 'destroy'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::get('buildings/deployed-items',                   [BuildingController::class, 'deployedItems']);
        Route::get('buildings/{id}/floors',                      [BuildingController::class, 'floors']);
        Route::post('buildings/{id}/floors',                     [BuildingController::class, 'storeFloor'])
            ->middleware(EnsureRole::class . ':super_admin');
        Route::delete('buildings/{id}/floors/{floorId}',         [BuildingController::class, 'destroyFloor'])
            ->middleware(EnsureRole::class . ':super_admin');

        Route::get('inventory-categories',    [InventoryCategoryController::class, 'index']);
        Route::post('inventory-categories',   [InventoryCategoryController::class, 'store'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
        Route::patch('inventory-categories/{id}', [InventoryCategoryController::class, 'update'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
        Route::delete('inventory-categories/{id}', [InventoryCategoryController::class, 'destroy'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');

        Route::prefix('notifications')->group(function (): void {
            Route::get('',              [NotificationController::class, 'index']);
            Route::get('unread',        [NotificationController::class, 'unread']);
            Route::post('read-all',     [NotificationController::class, 'markAllRead']);
            Route::post('{id}/read',    [NotificationController::class, 'markRead']);
            Route::delete('{id}',       [NotificationController::class, 'destroy']);
        });

        Route::get('items', [ItemController::class, 'index']);
        Route::post('items', [ItemController::class, 'store'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
        Route::get('items/{item}', [ItemController::class, 'show']);
        Route::patch('items/{item}', [ItemController::class, 'update'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
        Route::delete('items/{item}', [ItemController::class, 'destroy'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
        Route::get('items/{item}/history', [ItemController::class, 'history']);
        Route::post('items/{item}/adjust-stock', [ItemController::class, 'adjustStock'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');

        Route::prefix('users')->group(function (): void {
            Route::get('', [UserController::class, 'index'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::patch('profile', [UserController::class, 'updateProfile']);
            Route::post('', [UserController::class, 'store'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::patch('{user}/deactivate', [UserController::class, 'deactivate'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::patch('{user}/activate', [UserController::class, 'activate'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::patch('{user}', [UserController::class, 'update'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::patch('{user}/approve', [UserController::class, 'approve'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::delete('{user}/reject', [UserController::class, 'reject'])
                ->middleware(EnsureRole::class . ':super_admin');
            Route::post('{user}/reset-password', [UserController::class, 'resetPassword'])
                ->middleware(EnsureRole::class . ':super_admin');
        });

        // Suppliers
        Route::prefix('suppliers')->group(function (): void {
            Route::get('', [\App\Http\Controllers\Api\SupplierController::class, 'index']);
            Route::post('', [\App\Http\Controllers\Api\SupplierController::class, 'store'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
            Route::get('{supplier}', [\App\Http\Controllers\Api\SupplierController::class, 'show']);
            Route::patch('{supplier}', [\App\Http\Controllers\Api\SupplierController::class, 'update'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
            Route::delete('{supplier}', [\App\Http\Controllers\Api\SupplierController::class, 'destroy'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
            Route::get('{supplier}/history', [\App\Http\Controllers\Api\SupplierController::class, 'history']);
        });

        // Stock management
        Route::prefix('stock')->group(function (): void {
            Route::get('summary', [\App\Http\Controllers\Api\StockController::class, 'summary']);
            Route::get('movements', [\App\Http\Controllers\Api\StockController::class, 'movements']);
        });

        // Dispatching
        // TASK 13 — Dispatch Release Assignment Workflow. Role gates below now
        // mirror the revised business process exactly:
        //   Head Maintenance   creates + assigns/reassigns release personnel
        //   Administrator      approves or rejects Head-created dispatches;
        //                      TASK 41 also creates its own, which skip
        //                      approval and are immediately releasable
        //   Maintenance Staff  releases (and only its own assignment — the
        //                      identity check is in DispatchAuthorizationService,
        //                      because a role gate alone cannot express it)
        //   TASK 57            Release Personnel eligibility widened to also
        //                      accept Head Maintenance (maintenance_admin);
        //                      the release route's role gate below was
        //                      widened to match — the identity check above
        //                      still confines it to whoever is actually named
        //                      in release_assigned_to, and super_admin stays
        //                      excluded from this gate entirely.
        Route::prefix('dispatches')->group(function (): void {
            Route::get('', [\App\Http\Controllers\Api\DispatchController::class, 'index']);
            // TASK 41 — Administrator Create Dispatch Without Approval.
            // Head Maintenance creates dispatches that require approval;
            // Administrator creates dispatches that skip approval entirely
            // (DispatchAuthorizationService::creationRequiresApproval()).
            // Maintenance Staff are deliberately NOT listed here — both
            // allowed roles are named explicitly so no other role can reach
            // store() at all, and the bypass itself is re-derived from the
            // session inside the controller rather than trusted from input.
            Route::post('', [\App\Http\Controllers\Api\DispatchController::class, 'store'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':maintenance_admin,super_admin');
            // MUST be registered before the '{dispatch}' route below, otherwise
            // 'support' is captured as a dispatch id and fails model binding.
            // Same ordering rule the repairs group already relies on.
            Route::get('support/release-personnel', [\App\Http\Controllers\Api\DispatchController::class, 'releasePersonnel'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':maintenance_admin,super_admin');
            Route::get('{dispatch}', [\App\Http\Controllers\Api\DispatchController::class, 'show']);
            Route::post('{dispatch}/assign-personnel', [\App\Http\Controllers\Api\DispatchController::class, 'assignPersonnel'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':maintenance_admin');
            Route::post('{dispatch}/approve', [\App\Http\Controllers\Api\DispatchController::class, 'approve'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':super_admin');
            Route::post('{dispatch}/reject', [\App\Http\Controllers\Api\DispatchController::class, 'reject'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':super_admin');
            Route::post('{dispatch}/release', [\App\Http\Controllers\Api\DispatchController::class, 'release'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':maintenance_admin,maintenance_staff');
            Route::post('{dispatch}/cancel', [\App\Http\Controllers\Api\DispatchController::class, 'cancel'])
                ->middleware(\App\Http\Middleware\EnsureRole::class . ':super_admin,maintenance_admin');
            Route::get('{dispatch}/print', [\App\Http\Controllers\Api\DispatchController::class, 'print']);
        });

        Route::prefix('damage-reports')->group(function (): void {
            Route::get('', [DamageReportController::class, 'index']);
            // TASK 33 PHASE 3 — Administrator (super_admin) is a supervisory
            // role and does not submit/create Damage Reports, matching the
            // existing Maintenance Report policy (see /api/reports POST
            // above). Head Maintenance (maintenance_admin) and Maintenance
            // Staff remain the operational creators.
            Route::post('', [DamageReportController::class, 'store'])
                ->middleware(EnsureRole::class . ':maintenance_admin,maintenance_staff');
            // check-duplicate MUST precede {damageReport} to avoid route capture.
            Route::post('check-duplicate', [DamageReportController::class, 'checkDuplicate'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');
            Route::get('{damageReport}', [DamageReportController::class, 'show']);
            Route::post('{damageReport}/status', [DamageReportController::class, 'updateStatus'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');
            Route::get('{damageReport}/history', [DamageReportController::class, 'history']);
        });

        // TASK 13 PHASE 3 — the entire /api/repairs group (index, store,
        // show, history, assign, update, replacement, and the two
        // support/* lookups) was removed here along with RepairController.
        //
        // Every consumer had already been decoupled before this task ran:
        //   - the five Repair pages that called index/show/assign/update/
        //     replacement were deleted in TASK 12;
        //   - Preventive Maintenance stopped calling
        //     GET repairs/support/technicians in TASK 11 and now uses
        //     GET /api/preventive-maintenance/support/personnel, which is
        //     backed by the neutral PersonnelDirectoryService;
        //   - Dispatch stopped routing through RepairService in TASK 65 and
        //     uses GET /api/dispatches/support/release-personnel.
        //
        // support/damage-reports had no remaining consumer at all — its only
        // caller was the deleted repair-requests.php creation form.
        //
        // The repair_requests / repair_histories TABLES are deliberately left
        // in place; dropping them is a separate, later task.

        // 2026-09-30 — Activity Logs is now Administrator-only. Head
        // Maintenance (maintenance_admin) previously shared this middleware;
        // removed at the user's request alongside the sidebar link
        // (includes/sidebar.php $showAuditLogs) and the page guards in
        // activity-log.php / activity-log-detail.php.
        Route::prefix('activity-logs')->middleware(EnsureRole::class . ':super_admin')->group(function (): void {
            Route::get('', [ActivityLogController::class, 'index']);
            Route::get('support/options', [ActivityLogController::class, 'options']);
            Route::get('{activityLog}', [ActivityLogController::class, 'show']);
        });

        Route::prefix('deployment-tracking')->group(function (): void {
            Route::get('search', [DeploymentTrackingController::class, 'search']);
            Route::get('',       [DeploymentTrackingController::class, 'index']);
        });

        Route::get('replacement-tracking', [ReplacementTrackingController::class, 'index']);
        Route::post('replacement-tracking/{reportId}/dispose', [ReplacementTrackingController::class, 'dispose']);

        // Purchase receipts — search MUST precede apiResource to avoid {id} capture
        Route::get('purchase-receipts/search', [PurchaseReceiptController::class, 'search']);
        // store registered explicitly (before apiResource) so it can carry a role gate
        // while index/show remain open to any authenticated user.
        Route::post('purchase-receipts', [PurchaseReceiptController::class, 'store'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff')
            ->name('purchase-receipts.store');
        Route::apiResource('purchase-receipts', PurchaseReceiptController::class)
            ->except(['store', 'update', 'destroy'])
            ->parameters(['purchase-receipts' => 'id']);
        // TASK H — bulk entry. Registered BEFORE the single-item route so the
        // literal "bulk" segment is matched first. Same role gate as addItem:
        // bulk entry is the same operation performed N times, so it must not be
        // reachable by anyone who could not already add a line individually.
        Route::post('purchase-receipts/{id}/items/bulk', [PurchaseReceiptController::class, 'addItems'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');
        Route::post('purchase-receipts/{id}/items', [PurchaseReceiptController::class, 'addItem'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');
        // Remove a mistaken line — draft receipts only (no stock effect yet).
        Route::delete('purchase-receipts/{id}/items/{lineId}', [PurchaseReceiptController::class, 'removeItem'])
            ->whereNumber(['id', 'lineId'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');
        Route::post('purchase-receipts/{id}/post',  [PurchaseReceiptController::class, 'postReceipt'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');
        // Proof-of-Receipt photo upload. Documentation only (no stock effect),
        // so it uses the same role gate as the other write actions above
        // rather than being open to every authenticated viewer.
        Route::post('purchase-receipts/{id}/proof-image', [PurchaseReceiptController::class, 'uploadProofImage'])
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff');

        // Inventory stock management
        Route::prefix('inventory-stock')->group(function (): void {
            Route::get('',                  [InventoryStockController::class, 'index']);
            Route::get('summary',           [InventoryStockController::class, 'summary']);
            Route::get('entries',           [InventoryStockController::class, 'listEntries']);
            Route::get('{id}/transactions', [InventoryStockController::class, 'transactions']);
            Route::put('{id}',              [InventoryStockController::class, 'update'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
            Route::delete('{id}',           [InventoryStockController::class, 'destroy'])
                ->middleware(EnsureRole::class . ':super_admin,maintenance_admin');
        });

        // Preventive Maintenance module. RBAC per the confirmed plan:
        // create/archive/activate/assign are Head Maintenance only (2026-09-27:
        // the Administrator is view/monitor only for PM — PM is performed by
        // Head Maintenance and Staff, so super_admin was removed from every
        // write route below; the read routes stay open to all roles);
        // index/show/summary/options/history are open to any authenticated
        // role (Maintenance Staff sees the full list, per "view all, edit
        // only own"); update/complete carry the broader role gate here and
        // the actual "own task only" restriction is enforced per-record
        // inside PreventiveMaintenanceController via
        // PreventiveMaintenanceService::canManageTask() — a role middleware
        // alone cannot express an identity check, same reasoning as
        // Dispatch's release-personnel restriction.
        Route::prefix('preventive-maintenance')->group(function (): void {
            Route::get('support/options', [PreventiveMaintenanceController::class, 'options']);
            // TASK 11 — neutral personnel source for the PM assignee /
            // performed-by selectors, replacing the page's former dependency
            // on GET repairs/support/technicians. Delegates to the shared
            // PersonnelDirectoryService, same as Dispatch's
            // support/release-personnel (Task 65). No EnsureRole, matching
            // both the sibling support/options route and the un-gated Repair
            // endpoint it replaces — the data source moved, the access
            // boundary did not. Registered with the other support/* routes so
            // it resolves before {preventiveMaintenanceTask}.
            Route::get('support/personnel', [PreventiveMaintenanceController::class, 'supportPersonnel']);
            Route::get('summary', [PreventiveMaintenanceController::class, 'summary']);
            // Registered ahead of the {preventiveMaintenanceTask} show route
            // so these literal segments never get swallowed by route model
            // binding as if "schedule-grid"/"checklist" were a task id.
            Route::get('schedule-grid', [PreventiveMaintenanceController::class, 'scheduleGrid']);
            Route::get('checklist', [PreventiveMaintenanceController::class, 'checklist']);
            // Bulk assign (Head Maintenance) and "raise repair report" for a
            // Needs Repair inspection — literal segments, so registered ahead
            // of the {preventiveMaintenanceTask} routes.
            Route::post('assign', [PreventiveMaintenanceController::class, 'assign'])
                ->middleware(EnsureRole::class . ':maintenance_admin');
            Route::post('history/{history}/repair-report', [PreventiveMaintenanceController::class, 'repairReport'])
                ->middleware(EnsureRole::class . ':maintenance_admin,maintenance_staff');
            Route::get('', [PreventiveMaintenanceController::class, 'index']);
            Route::post('', [PreventiveMaintenanceController::class, 'store'])
                ->middleware(EnsureRole::class . ':maintenance_admin');
            Route::get('{preventiveMaintenanceTask}', [PreventiveMaintenanceController::class, 'show']);
            Route::patch('{preventiveMaintenanceTask}', [PreventiveMaintenanceController::class, 'update'])
                ->middleware(EnsureRole::class . ':maintenance_admin,maintenance_staff');
            Route::post('{preventiveMaintenanceTask}/complete', [PreventiveMaintenanceController::class, 'complete'])
                ->middleware(EnsureRole::class . ':maintenance_admin,maintenance_staff');
            Route::post('{preventiveMaintenanceTask}/archive', [PreventiveMaintenanceController::class, 'archive'])
                ->middleware(EnsureRole::class . ':maintenance_admin');
            Route::post('{preventiveMaintenanceTask}/activate', [PreventiveMaintenanceController::class, 'activate'])
                ->middleware(EnsureRole::class . ':maintenance_admin');
            Route::get('{preventiveMaintenanceTask}/history', [PreventiveMaintenanceController::class, 'history']);
        });

        // Analytics and reporting endpoints (aggregates, trends, exports)
        // TASK 82 GAP #1 — previously had no EnsureRole at all (any
        // authenticated session, including a pending/unapproved 'user'-role
        // account, could reach every endpoint below). The frontend has
        // always restricted the Analytics Dashboard nav link and page itself
        // to super_admin/maintenance_admin/maintenance_staff (see
        // includes/sidebar.php and pages/analytics-dashboard.php) — this
        // just brings route-level enforcement in line with that existing,
        // already-shipped access model instead of inventing a new one.
        Route::prefix('analytics')
            ->middleware(EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff')
            ->group(function (): void {
                Route::get('options', [AnalyticsReportController::class, 'options']);
                Route::get('overview', [AnalyticsReportController::class, 'overview']);
                Route::get('top-requested', [AnalyticsReportController::class, 'topRequested']);
                // TASK 13 PHASE 8 — 'top-repaired' was removed here. It read
                // repair_requests exclusively (topRepairedItems()), so it is
                // Repair-only, not a shared inventory metric. 'top-requested'
                // above is the Inventory/Dispatch metric and is untouched.
                Route::get('monthly-comparison', [AnalyticsReportController::class, 'monthlyComparison']);
                Route::get('department-usage', [AnalyticsReportController::class, 'departmentUsage']);
                Route::get('semester-comparison', [AnalyticsReportController::class, 'semesterComparison']);
                Route::get('inventory-health', [AnalyticsReportController::class, 'inventoryHealth']);
                Route::get('inventory-summary', [AnalyticsReportController::class, 'inventorySummary']);
                Route::get('low-stock', [AnalyticsReportController::class, 'lowStock']);
                Route::get('damaged-items', [AnalyticsReportController::class, 'damagedItems']);
                Route::get('dispatch-report', [AnalyticsReportController::class, 'dispatchReport']);
                // TASK 13 PHASE 8 — 'repair-report' was removed here; it read
                // repair_requests exclusively. 'dispatch-report' above and
                // 'replacement-report' below are NOT Repair: replacementReport()
                // reads damage_reports.replacement_transaction_id, which is
                // Damage Report data and stays.
                Route::get('replacement-report', [AnalyticsReportController::class, 'replacementReport']);
                Route::get('semester-detail', [AnalyticsReportController::class, 'semesterDetail']);
            });
    });
});

