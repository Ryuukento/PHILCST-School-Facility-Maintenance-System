<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\InventoryStockEntry;
use App\Models\InventoryTransaction;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    use ApiResponder;

    public function summary(Request $request)
    {
        // Current stock: sum of items.quantity
        $totalCurrent = (int) Item::query()->sum('quantity');

        // Incoming stock: stock entries with date_received in the future
        $totalIncoming = (int) InventoryStockEntry::query()->whereDate('date_received', '>', now()->toDateString())->sum('quantity');

        // Outgoing stock: deployed/disposed transactions in last 30 days
        $totalOutgoing = (int) InventoryTransaction::query()
            ->whereIn('transaction_type', ['deploy', 'dispose'])
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('quantity');

        // TASK 78 — standardized on the canonical i.status column (see
        // AnalyticsService::inventorySummary() for the full rationale). This
        // endpoint has no current frontend consumer, but is fixed for
        // consistency rather than left as a 3rd copy of the old
        // override/category-default logic.
        $lowStockItems = collect();
        $items = Item::query()->where('status', 'low_stock')->get();
        foreach ($items as $item) {
            $lowStockItems->push([
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'threshold' => $item->reorder_level,
            ]);
        }

        return $this->ok('Stock summary retrieved', [
            'total_current' => $totalCurrent,
            'total_incoming' => $totalIncoming,
            'total_outgoing_30d' => $totalOutgoing,
            'low_stock_count' => $lowStockItems->count(),
            'low_stock_items' => $lowStockItems->take(20)->values(),
        ]);
    }

    public function movements(Request $request)
    {
        $itemId = $request->integer('item_id');

        $txs = InventoryTransaction::query()
            ->when($itemId > 0, fn($q) => $q->where('item_id', $itemId))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $stockEntries = InventoryStockEntry::query()
            ->when($itemId > 0, fn($q) => $q->where('item_id', $itemId))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return $this->ok('Movements retrieved', ['transactions' => $txs, 'stock_entries' => $stockEntries]);
    }
}
