<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\DamageReport;
use App\Models\DamageReportHistory;
use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\User;
use App\Services\DamageReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

function smokeAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

echo "Starting damage reporting smoke test...\n";
DB::beginTransaction();

try {
    $service = app(DamageReportService::class);

    $user = User::query()->first();
    if (!$user) {
        $user = User::query()->create([
            'full_name' => 'Damage Smoke User',
            'email' => 'damage.smoke+' . time() . '@example.invalid',
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'maintenance_admin',
            'department_id' => 1,
            'status' => 'active',
        ]);
    }

    $item = Item::query()->create([
        'name' => 'damage-smoke-item-' . time(),
        'brand' => 'SmokeBrand',
        'model' => 'D1',
        'quantity' => 30,
        'reserved_quantity' => 0,
        'unit_type' => 'pcs',
        'status' => 'available',
    ]);

    // Create deploy transaction so item qualifies as deployed in room context.
    InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'report_id' => null,
        'room_id' => 1,
        'transaction_type' => 'deploy',
        'quantity' => 5,
        'reference_note' => 'Damage smoke deployment seed',
        'performed_by' => $user->user_id,
    ]);

    // Build a released dispatch containing this item.
    $dispatch = Dispatch::query()->create([
        'dispatch_code' => 'DMG-SMOKE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        'department_id' => 1,
        'room_id' => 1,
        'status' => 'released',
        'approved_by' => $user->user_id,
        'released_by' => $user->user_id,
    ]);

    DispatchItem::query()->create([
        'dispatch_id' => $dispatch->id,
        'item_id' => $item->id,
        'quantity' => 2,
    ]);

    $authUser = [
        'user_id' => $user->user_id,
        'role' => 'maintenance_admin',
    ];

    $created = $service->createReport([
        'item_id' => $item->id,
        'room_id' => 1,
        'department_id' => 1,
        'source_dispatch_id' => $dispatch->id,
        'damage_description' => 'Screen crack and chassis damage after deployment.',
        'severity_level' => 'high',
        'repair_notes' => 'Initial check required.',
    ], $authUser, null);

    smokeAssert((bool)$created, 'Damage report creation failed.');
    smokeAssert($created->status === 'pending', 'Initial damage status should be pending.');

    // Image upload path validation smoke check.
    $tmpImagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'damage-smoke-' . uniqid('', true) . '.png';
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2oM6kAAAAASUVORK5CYII=');
    file_put_contents($tmpImagePath, $pngData);
    $upload = new UploadedFile($tmpImagePath, 'damage-smoke.png', 'image/png', null, true);

    $imageReport = $service->createReport([
        'item_id' => $item->id,
        'room_id' => 1,
        'department_id' => 2,
        'damage_description' => 'Image upload validation report.',
        'severity_level' => 'low',
    ], $authUser, $upload);

    smokeAssert((bool)$imageReport, 'Image-based damage report creation failed.');
    smokeAssert(!empty($imageReport->image_path), 'Image path should be stored for uploaded damage image.');

    $storedPath = dirname(__DIR__) . '/public' . (string)$imageReport->image_path;
    smokeAssert(is_file($storedPath), 'Uploaded damage image file was not stored on disk.');

    $historyCount = DamageReportHistory::query()->where('damage_report_id', $created->id)->count();
    smokeAssert($historyCount >= 1, 'Damage history should record create action.');

    // Duplicate active report should be prevented.
    $duplicateBlocked = false;
    try {
        $service->createReport([
            'item_id' => $item->id,
            'room_id' => 1,
            'department_id' => 1,
            'damage_description' => 'Duplicate active report attempt.',
            'severity_level' => 'medium',
        ], $authUser, null);
    } catch (Throwable $e) {
        $duplicateBlocked = true;
    }
    smokeAssert($duplicateBlocked, 'Duplicate active damage report was not blocked.');

    // Workflow progression.
    $service->updateStatus($created, [
        'status' => 'under_review',
        'repair_notes' => 'Under diagnostic review.',
    ], $authUser);

    $created->refresh();
    smokeAssert($created->status === 'under_review', 'Status should move to under_review.');

    // Replacement should create a deploy transaction and reduce stock.
    $qtyBeforeReplace = (int)$item->fresh()->quantity;
    $service->updateStatus($created, [
        'status' => 'replaced',
        'repair_notes' => 'Unit replaced from stock.',
        'replacement_item_id' => $item->id,
        'replacement_quantity' => 1,
    ], $authUser);

    $created->refresh();
    $item->refresh();

    smokeAssert($created->status === 'replaced', 'Status should be replaced.');
    smokeAssert(!empty($created->replacement_transaction_id), 'Replacement transaction should be recorded.');
    smokeAssert((int)$item->quantity === ($qtyBeforeReplace - 1), 'Replacement should deduct stock by 1 through deploy transaction.');

    $replacementTx = InventoryTransaction::query()->find($created->replacement_transaction_id);
    smokeAssert((bool)$replacementTx, 'Replacement inventory transaction missing.');
    smokeAssert($replacementTx->transaction_type === 'deploy', 'Replacement transaction should be deploy.');

    // Close workflow.
    $service->updateStatus($created, [
        'status' => 'closed',
        'repair_notes' => 'Case completed and closed.',
    ], $authUser);

    $created->refresh();
    smokeAssert($created->status === 'closed', 'Damage report should close successfully.');
    smokeAssert($created->closed_at !== null, 'Closed timestamp should be set.');

    $finalHistoryCount = DamageReportHistory::query()->where('damage_report_id', $created->id)->count();
    smokeAssert($finalHistoryCount >= 4, 'Expected multiple history entries after workflow updates.');

    smokeAssert((int)$item->quantity >= 0, 'Negative stock detected after damage replacement flow.');

    if (isset($storedPath) && is_file($storedPath)) {
        @unlink($storedPath);
    }

    if (isset($tmpImagePath) && is_file($tmpImagePath)) {
        @unlink($tmpImagePath);
    }

    DB::rollBack();
    echo "Damage reporting smoke test completed successfully.\n";
} catch (Throwable $e) {
    if (isset($storedPath) && is_file($storedPath)) {
        @unlink($storedPath);
    }

    if (isset($tmpImagePath) && is_file($tmpImagePath)) {
        @unlink($tmpImagePath);
    }

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo 'Damage reporting smoke test failed: ' . $e->getMessage() . "\n";
    exit(1);
}

return 0;
