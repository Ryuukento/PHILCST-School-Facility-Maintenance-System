<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\SupplierController;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function smokeAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function decodeResponse($response): array {
    $raw = method_exists($response, 'getContent') ? (string)$response->getContent() : '';
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

echo "Starting inventory/supplier/components smoke test...\n";

DB::beginTransaction();

try {
    $user = User::query()->first();
    if (!$user) {
        $user = User::query()->create([
            'full_name' => 'Smoke Tester',
            'email' => 'smoke+' . time() . '@example.invalid',
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'role' => 'maintenance_admin',
            'department_id' => 1,
            'status' => 'active',
        ]);
    }

    $item = Item::query()->create([
        'name' => 'smoke-stock-' . time(),
        'brand' => 'SmokeBrand',
        'model' => 'SmokeModel',
        'quantity' => 20,
        'reserved_quantity' => 0,
        'unit_type' => 'pcs',
        'status' => 'available',
    ]);

    echo "Created inventory item #{$item->id} (qty=20,reserved=0)\n";

    $countBefore = InventoryTransaction::query()->count();

    InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'transaction_type' => 'reserve',
        'quantity' => 5,
        'reference_note' => 'Smoke reserve',
        'performed_by' => $user->user_id,
    ]);
    $item->refresh();
    smokeAssert((int)$item->quantity === 20, 'Reserve should not change quantity');
    smokeAssert((int)$item->reserved_quantity === 5, 'Reserve should increase reserved_quantity to 5');

    InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'transaction_type' => 'release',
        'quantity' => 3,
        'reference_note' => 'Smoke release',
        'performed_by' => $user->user_id,
    ]);
    $item->refresh();
    smokeAssert((int)$item->reserved_quantity === 2, 'Release should reduce reserved_quantity to 2');

    InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'transaction_type' => 'deploy',
        'quantity' => 2,
        'reference_note' => 'Smoke deploy',
        'performed_by' => $user->user_id,
    ]);
    $item->refresh();
    smokeAssert((int)$item->quantity === 18, 'Deploy should reduce quantity to 18');
    smokeAssert((int)$item->reserved_quantity === 0, 'Deploy should clear reserved_quantity back to 0');

    InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'transaction_type' => 'return',
        'quantity' => 2,
        'reference_note' => 'Smoke return',
        'performed_by' => $user->user_id,
    ]);
    $item->refresh();
    smokeAssert((int)$item->quantity === 20, 'Return should restore quantity to 20');

    $adjustmentTx = InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'transaction_type' => 'adjustment',
        'quantity' => 1,
        'reference_note' => 'Smoke adjustment',
        'performed_by' => $user->user_id,
    ]);
    $item->refresh();
    smokeAssert((int)$item->quantity === 21, 'Adjustment should increase quantity to 21');

    $disposeTx = InventoryTransaction::query()->create([
        'item_id' => $item->id,
        'transaction_type' => 'dispose',
        'quantity' => 1,
        'reference_note' => 'Smoke dispose',
        'performed_by' => $user->user_id,
    ]);
    $item->refresh();
    smokeAssert((int)$item->quantity === 20, 'Dispose should reduce quantity to 20');

    $txForItem = InventoryTransaction::query()->where('item_id', $item->id)->count();
    smokeAssert($txForItem === 6, 'Expected exactly 6 transactions for smoke item');

    $countAfter = InventoryTransaction::query()->count();
    smokeAssert(($countAfter - $countBefore) === 6, 'Global transaction count increment mismatch');

    $disposeTx->delete();
    $item->refresh();
    smokeAssert((int)$item->quantity === 21, 'Deleting dispose transaction should restore quantity to 21');

    $adjustmentTx->delete();
    $item->refresh();
    smokeAssert((int)$item->quantity === 20, 'Deleting adjustment transaction should restore quantity to 20');

    $caughtInsufficient = false;
    try {
        InventoryTransaction::query()->create([
            'item_id' => $item->id,
            'transaction_type' => 'deploy',
            'quantity' => 999,
            'reference_note' => 'Smoke negative check',
            'performed_by' => $user->user_id,
        ]);
    } catch (Throwable $e) {
        $caughtInsufficient = true;
    }
    smokeAssert($caughtInsufficient, 'Insufficient stock deploy should throw and be blocked');
    smokeAssert((int)$item->quantity >= 0, 'Negative stock detected after checks');

    echo "Inventory transaction lifecycle checks passed.\n";

    $session = app('session')->driver();
    $session->start();
    $session->put('user_id', $user->user_id);

    $supplierController = app(SupplierController::class);
    $supplierName = 'Smoke Supplier ' . time();

    $createReq = Request::create('/api/suppliers', 'POST', [
        'name' => $supplierName,
        'contact_email' => 'smoke.supplier+' . time() . '@example.invalid',
        'status' => 'active',
    ]);
    $createReq->setLaravelSession($session);
    $createRes = $supplierController->store($createReq);
    $createPayload = decodeResponse($createRes);
    smokeAssert(!empty($createPayload['success']), 'Supplier create failed');

    $supplierId = (int)($createPayload['data']['supplier_id'] ?? 0);
    smokeAssert($supplierId > 0, 'Supplier create did not return supplier_id');

    $supplier = Supplier::query()->find($supplierId);
    smokeAssert((bool)$supplier, 'Supplier record was not created');

    $updateReq = Request::create('/api/suppliers/' . $supplierId, 'PATCH', [
        'contact_email' => 'updated.supplier+' . time() . '@example.invalid',
    ]);
    $updateReq->setLaravelSession($session);
    $updateRes = $supplierController->update($updateReq, $supplier);
    $updatePayload = decodeResponse($updateRes);
    smokeAssert(!empty($updatePayload['success']), 'Supplier update failed');

    $historyReq = Request::create('/api/suppliers/' . $supplierId . '/history', 'GET');
    $historyReq->setLaravelSession($session);
    $historyRes = $supplierController->history($historyReq, $supplier->fresh());
    $historyPayload = decodeResponse($historyRes);
    smokeAssert(!empty($historyPayload['success']), 'Supplier history endpoint failed');

    $destroyReq = Request::create('/api/suppliers/' . $supplierId, 'DELETE');
    $destroyReq->setLaravelSession($session);
    $destroyRes = $supplierController->destroy($destroyReq, $supplier->fresh());
    $destroyPayload = decodeResponse($destroyRes);
    smokeAssert(!empty($destroyPayload['success']), 'Supplier delete failed');
    smokeAssert(!Supplier::query()->find($supplierId), 'Supplier delete did not remove record');

    echo "Supplier integration checks passed.\n";

    $componentsPath = __DIR__ . '/../public/frontend/assets/js/components.js';
    $componentsSource = is_file($componentsPath) ? (string)file_get_contents($componentsPath) : '';
    smokeAssert($componentsSource !== '', 'Components source file not found');
    smokeAssert(strpos($componentsSource, 'Components._clickBound') !== false, 'Missing global click-bound guard');
    smokeAssert(strpos($componentsSource, 'pruneDisconnectedInstances') !== false, 'Missing instance pruning helper');
    smokeAssert(substr_count($componentsSource, "document.addEventListener('click'") >= 1, 'Missing document click listener for dropdown close');

    echo "Components utility guard checks passed.\n";

    DB::rollBack();
    echo "Smoke inventory/supplier/components test completed successfully.\n";
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo 'Smoke test failed: ' . $e->getMessage() . "\n";
    exit(1);
}

return 0;
