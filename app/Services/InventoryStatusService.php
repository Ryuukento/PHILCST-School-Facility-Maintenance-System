<?php

namespace App\Services;

class InventoryStatusService
{
    /**
     * Derives an item's status from its current quantity and reorder threshold.
     * Single source of truth for this calculation — previously duplicated across
     * ItemController, InventoryStockController, PurchaseReceiptPostingService,
     * and InventoryAdjustmentService.
     */
    public static function deriveStatus(int $quantity, int $reorderLevel): string
    {
        if ($quantity <= 0)             return 'out_of_stock';
        if ($quantity <= $reorderLevel) return 'low_stock';
        return 'available';
    }
}
