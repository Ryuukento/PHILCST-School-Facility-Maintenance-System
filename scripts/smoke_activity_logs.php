<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ActivityLog;
use App\Models\DamageReport;
use App\Models\InventoryStockEntry;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\DamageReportService;
use App\Services\DispatchService;
use Illuminate\Support\Facades\DB;

function activitySmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

echo "Starting activity logs smoke test...\n";
DB::beginTransaction();

try {
    $activityLogService = app(ActivityLogService::class);
    $damageService = app(DamageReportService::class);
    $dispatchService = app(DispatchService::class);

    $user = User::query()->first();
    if (!$user) {
        $user = User::query()->create([
            'full_name' => 'Activity Smoke User',
            'email' => 'activity.smoke+' . time() . '@example.invalid',
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'maintenance_admin',
            'department_id' => 1,
            'status' => 'active',
        ]);
    } elseif ($user->role !== 'maintenance_admin') {
        $user->role = 'maintenance_admin';
        $user->status = 'active';
        $user->department_id = $user->department_id ?: 1;
        $user->save();
    }

    $authUser = [
        'user_id' => $user->user_id,
        'role' => 'maintenance_admin',
        'full_name' => $user->full_name,
        'department_id' => $user->department_id ?: 1,
    ];

    $item = Item::query()->create([
        'name' => 'activity-smoke-item-' . time(),
        'brand' => 'SmokeBrand',
        'model' => 'A1',
        'quantity' => 12,
        'reserved_quantity' => 0,
        'unit_type' => 'pcs',
        'status' => 'available',
    ]);

    $activityLogService->log([
        'user_id' => $user->user_id,
        'user_role' => $authUser['role'],
        'action' => 'UPDATE_RECORD',
        'module' => 'system',
        'entity_type' => 'test',
        'entity_id' => 999,
        'details' => 'Deduplication smoke event.',
    ]);

    $activityLogService->log([
        'user_id' => $user->user_id,
        'user_role' => $authUser['role'],
        'action' => 'UPDATE_RECORD',
        'module' => 'system',
        'entity_type' => 'test',
        'entity_id' => 999,
        'details' => 'Deduplication smoke event.',
    ]);

    $dedupeCount = ActivityLog::query()
        ->where('action', 'UPDATE_RECORD')
        ->where('entity_type', 'test')
        ->where('entity_id', 999)
        ->count();
    activitySmokeAssert($dedupeCount === 1, 'Duplicate rapid log entries were not suppressed.');

    $inventoryRoomId = (int) (DB::table('inventory_rooms')->orderBy('id')->value('id') ?? 1);
    $categoryId = DB::table('inventory_categories')->orderBy('id')->value('id');
    $departmentId = (int) ($authUser['department_id'] ?: (DB::table('departments')->orderBy('department_id')->value('department_id') ?? 1));
    $roomId = (int) (DB::table('rooms')->orderBy('id')->value('id') ?? 1);

    InventoryStockEntry::query()->create([
        'stock_entry_id' => 'ACT-SMOKE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        'or_number' => 'OR-' . time(),
        'supplier_name' => 'Smoke Supplier',
        'date_received' => now()->toDateString(),
        'item_id' => $item->id,
        'inventory_room_id' => $inventoryRoomId,
        'category_id' => $categoryId,
        'department_id' => $departmentId,
        'room_id' => $roomId,
        'receiver_user_id' => $user->user_id,
        'item_name' => $item->name,
        'quantity' => 2,
        'unit_type' => 'pcs',
        'description' => 'Inventory entry smoke test.',
        'item_condition' => 'good',
    ]);

    activitySmokeAssert(ActivityLog::query()->where('action', 'INVENTORY_ENTRY')->where('entity_id', $item->id)->exists(), 'Inventory entry log was not generated.');

    InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'report_id' => null,
        'room_id' => $roomId,
        'transaction_type' => 'deploy',
        'quantity' => 2,
        'reference_note' => 'Activity smoke deployment seed',
        'performed_by' => $user->user_id,
    ]);

    $dispatch = $dispatchService->createDispatch([
        'department_id' => $departmentId,
        'room_id' => $roomId,
        'notes' => 'Activity log smoke dispatch.',
        'items' => [[
            'item_id' => $item->id,
            'quantity' => 1,
        ]],
    ], $user->user_id);

    $dispatch = $dispatchService->approveDispatch($dispatch, $user->user_id, $user->user_id);
    $dispatchService->releaseDispatch($dispatch, $user->user_id, $user->user_id, $user->user_id);

    activitySmokeAssert(ActivityLog::query()->where('action', 'CREATE_DISPATCH')->where('entity_id', $dispatch->id)->exists(), 'Dispatch creation log was not generated.');
    activitySmokeAssert(ActivityLog::query()->where('action', 'APPROVE_DISPATCH')->where('entity_id', $dispatch->id)->exists(), 'Dispatch approval log was not generated.');
    activitySmokeAssert(ActivityLog::query()->where('action', 'RELEASE_DISPATCH')->where('entity_id', $dispatch->id)->exists(), 'Dispatch release log was not generated.');

    $damageReport = $damageService->createReport([
        'item_id' => $item->id,
        'room_id' => $roomId,
        'department_id' => $departmentId,
        'source_dispatch_id' => $dispatch->id,
        'damage_description' => 'Activity smoke damage report.',
        'severity_level' => 'medium',
        'repair_notes' => 'Needs maintenance review.',
    ], $authUser, null);

    activitySmokeAssert(ActivityLog::query()->where('action', 'CREATE_DAMAGE_REPORT')->where('entity_id', $damageReport->id)->exists(), 'Damage report creation log was not generated.');

    // TASK 13C (Repair retirement) — this block previously drove
    // RepairService through createRequest -> assignTechnician ->
    // updateRequest -> fulfillReplacement and asserted four activity
    // actions: CREATE_REPAIR_REQUEST, ASSIGN_REPAIR_TECHNICIAN,
    // UPDATE_REPAIR and REPLACEMENT_ACTION.
    //
    // RepairService and the RepairRequest/RepairHistory models were deleted
    // in Task 13, so the first three actions no longer have ANY producer in
    // the application — nothing emits them and no substitute can honestly
    // stand in for them. Their assertions are therefore dropped rather than
    // faked.
    //
    // REPLACEMENT_ACTION is different and is deliberately KEPT: it survives
    // Repair retirement because DamageReportService::updateStatus() still
    // emits it on a genuine transition to 'replaced' (see that method's
    // activity-log call, which selects REPLACEMENT_ACTION / module
    // 'replacement' for that status). So the coverage is re-pointed at the
    // real surviving producer instead of being deleted with the rest.
    //
    // Note the entity changes with the producer: the Repair path logged
    // entity_id = repair request id, whereas the damage-report path logs
    // entity_type 'damage_report' and entity_id = damage report id.
    $replacementItem = Item::query()->create([
        'name' => 'activity-smoke-replacement-' . time(),
        'brand' => 'SmokeBrand',
        'model' => 'AR1',
        'quantity' => 9,
        'reserved_quantity' => 0,
        'unit_type' => 'pcs',
        'status' => 'available',
    ]);

    // 'pending' cannot go straight to 'replaced' under the service's
    // STATUS_TRANSITIONS map; 'under_review' is the legal intermediate hop.
    $damageReport = $damageService->updateStatus($damageReport, [
        'status' => 'under_review',
        'repair_notes' => 'Assessed during activity log smoke test.',
    ], $authUser);

    activitySmokeAssert(ActivityLog::query()->where('action', 'UPDATE_DAMAGE_REPORT')->where('entity_id', $damageReport->id)->exists(), 'Damage report status update log was not generated.');

    $damageReport = $damageService->updateStatus($damageReport, [
        'status' => 'replaced',
        'replacement_item_id' => $replacementItem->id,
        'replacement_quantity' => 1,
        'repair_notes' => 'Replacement fulfilled in smoke test.',
    ], $authUser);

    activitySmokeAssert(ActivityLog::query()->where('action', 'REPLACEMENT_ACTION')->where('entity_type', 'damage_report')->where('entity_id', $damageReport->id)->exists(), 'Replacement action log was not generated.');

    $logDetail = ActivityLog::query()->where('action', 'RELEASE_DISPATCH')->where('entity_id', $dispatch->id)->first();
    activitySmokeAssert((bool) $logDetail && $logDetail->module === 'dispatch', 'Dispatch log should store the correct module.');
    activitySmokeAssert(!empty($logDetail->user_role), 'Activity logs should store the acting user role.');

    DB::rollBack();
    echo "Activity logs smoke test completed successfully.\n";
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    echo 'Activity logs smoke test failed: ' . $e->getMessage() . "\n";
    exit(1);
}

return 0;
