<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Item;
use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

echo "Starting dispatch smoke test...\n";

DB::beginTransaction();
try {
    // Create or find a test user
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

    // Create a test item
    $itemName = 'smoke-item-' . time();
    $item = Item::query()->create([
        'name' => $itemName,
        'brand' => 'TestBrand',
        'model' => 'T1',
        'quantity' => 50,
        'reserved_quantity' => 0,
        'unit_type' => 'pcs',
        'status' => 'available',
    ]);

    echo "Created item id {$item->id} with quantity {$item->quantity}\n";

    // Create dispatch
    $dispatch = Dispatch::query()->create([
        'dispatch_code' => 'SMOKE-' . strtoupper(Str::random(6)),
        'department_id' => $user->department_id ?? null,
        'room_id' => null,
        'status' => 'pending',
    ]);

    $dispatchItem = DispatchItem::query()->create([
        'dispatch_id' => $dispatch->id,
        'item_id' => $item->id,
        'quantity' => 10,
    ]);

    echo "Created dispatch id {$dispatch->id} for item qty {$dispatchItem->quantity}\n";

    // Approve dispatch
    $dispatch->approved_by = $user->user_id;
    $dispatch->status = 'approved';
    $dispatch->save();
    echo "Approved dispatch\n";

    // Release dispatch: create inventory transaction of type deploy
    $items = $dispatch->items()->with('item')->get();
    foreach ($items as $di) {
        $it = $di->item;
        $available = (int)$it->quantity - (int)$it->reserved_quantity;
        if ($di->quantity > $available) {
            throw new Exception('Insufficient stock for item ' . $it->name);
        }
        InventoryTransaction::query()->create([
            'item_id' => $it->id,
            'report_id' => null,
            'room_id' => $dispatch->room_id,
            'transaction_type' => 'deploy',
            'quantity' => $di->quantity,
            'reference_note' => 'Smoke dispatch ' . $dispatch->dispatch_code,
            'performed_by' => $user->user_id,
        ]);
    }

    $dispatch->released_by = $user->user_id;
    $dispatch->status = 'released';
    $dispatch->save();

    echo "Released dispatch\n";

    // Refresh item
    $item->refresh();
    echo "Item quantity after release: {$item->quantity}, reserved: {$item->reserved_quantity}\n";

    if ($item->quantity < 0) {
        throw new Exception('Negative stock detected');
    }

    DB::commit();
    echo "Smoke test completed successfully.\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo "Smoke test failed: " . $e->getMessage() . "\n";
    exit(1);
}

return 0;
