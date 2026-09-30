<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AnalyticsService;
use Illuminate\Support\Facades\DB;

echo "Starting reporting smoke test...\n";
DB::beginTransaction();
try {
    $svc = app(AnalyticsService::class);
    $summary = $svc->inventorySummary([]);
    if (!is_array($summary) || !isset($summary['total_quantity'])) {
        throw new RuntimeException('Inventory summary not returned');
    }

    $low = $svc->lowStock([]);
    if (!is_array($low) || !isset($low['items'])) {
        throw new RuntimeException('Low stock not returned');
    }

    $damaged = $svc->damagedItems([]);
    $repairs = $svc->repairReport([]);
    $dispatch = $svc->dispatchReport([]);
    $replacement = $svc->replacementReport([]);

    // Basic sanity checks
    echo "Summary total_quantity: " . ($summary['total_quantity'] ?? '0') . "\n";
    echo "Low stock count: " . ($summary['low_stock_count'] ?? '0') . "\n";

    DB::rollBack();
    echo "Reporting smoke test completed successfully.\n";
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo "Reporting smoke test failed: " . $e->getMessage() . "\n";
    exit(1);
}

return 0;
