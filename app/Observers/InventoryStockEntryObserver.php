<?php

namespace App\Observers;

use App\Models\InventoryStockEntry;
use App\Models\Item;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Cache;

class InventoryStockEntryObserver
{
    public function created(InventoryStockEntry $entry): void
    {
        if ($entry->item_id === null) {
            return;
        }

        $item = Item::find($entry->item_id);
        if (!$item) {
            return;
        }

        // Increase item quantity by received amount
        $item->quantity = (int)$item->quantity + (int)$entry->quantity;
        $item->save();

        app(ActivityLogService::class)->log([
            'user_id' => $entry->receiver_user_id,
            'action' => 'INVENTORY_ENTRY',
            'module' => 'inventory',
            'entity_type' => 'item',
            'entity_id' => $item->id,
            'details' => 'Received stock entry ' . ($entry->stock_entry_id ?? $entry->id) . ' for item ' . $item->name . ' (qty ' . $entry->quantity . ').',
            'meta' => [
                'stock_entry_id' => $entry->stock_entry_id ?? $entry->id,
                'quantity' => (int) $entry->quantity,
                'supplier_id' => $entry->supplier_id,
            ],
            'dedupe_window_seconds' => 1,
        ]);

        // Invalidate analytics caches related to inventory
        try {
            Cache::tags(['analytics'])->flush();
        } catch (\Throwable $e) {
            Cache::forget('analytics.inventorySummary:' . md5(json_encode([])));
            Cache::forget('analytics.overview:' . md5(json_encode([])));
            Cache::forget('analytics.lowStock:' . md5(json_encode([])));
            Cache::forget('analytics.inventoryHealth:' . md5(json_encode([])));
        }
    }

    public function deleted(InventoryStockEntry $entry): void
    {
        if ($entry->item_id === null) {
            return;
        }

        $item = Item::find($entry->item_id);
        if (!$item) {
            return;
        }

        // Roll back quantity when stock entry removed
        $item->quantity = max(0, (int)$item->quantity - (int)$entry->quantity);
        $item->save();

        try {
            Cache::tags(['analytics'])->flush();
        } catch (\Throwable $e) {
            Cache::forget('analytics.inventorySummary:' . md5(json_encode([])));
            Cache::forget('analytics.overview:' . md5(json_encode([])));
            Cache::forget('analytics.lowStock:' . md5(json_encode([])));
            Cache::forget('analytics.inventoryHealth:' . md5(json_encode([])));
        }
    }
}
