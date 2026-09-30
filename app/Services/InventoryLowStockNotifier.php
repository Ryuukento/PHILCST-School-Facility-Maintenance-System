<?php

namespace App\Services;

use App\Models\User;

/**
 * TASK 18 — "Item becomes Low Stock" notification.
 *
 * Treats Item.status (the existing persisted single source of truth written
 * by InventoryStatusService::deriveStatus() at every stock-mutation call
 * site) as the state machine itself — no new "already notified" column is
 * introduced. Given the status immediately before and after a mutation:
 *
 *   NORMAL -> LOW   : notify once   (wasBelow=false, isBelow=true)
 *   LOW    -> LOW    : no notify     (wasBelow=true,  isBelow=true)
 *   LOW    -> NORMAL : no notify, state clears (wasBelow=true, isBelow=false)
 *   NORMAL -> LOW    : notify again  (wasBelow=false, isBelow=true) — safe
 *                       because the previous drop already cleared back to
 *                       NORMAL, so this is a genuinely new transition.
 *
 * "LOW" covers both 'low_stock' and 'out_of_stock' — moving between those
 * two while still below threshold must never re-notify.
 */
class InventoryLowStockNotifier
{
    private const BELOW_THRESHOLD_STATUSES = ['low_stock', 'out_of_stock'];

    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    /**
     * @param  ?string $previousStatus  Item.status value before this mutation (null for a
     *                                  never-before-persisted row — treated as "not below").
     * @param  string  $newStatus       Item.status value just computed/persisted.
     */
    public function handleStatusChange(int $itemId, string $itemName, ?string $previousStatus, string $newStatus): void
    {
        $wasBelowThreshold = in_array($previousStatus, self::BELOW_THRESHOLD_STATUSES, true);
        $isBelowThreshold  = in_array($newStatus, self::BELOW_THRESHOLD_STATUSES, true);

        // Only the NORMAL -> LOW transition is notify-worthy. Everything else
        // (LOW -> LOW, LOW -> NORMAL, NORMAL -> NORMAL) is intentionally silent.
        if ($wasBelowThreshold || !$isBelowThreshold) {
            return;
        }

        $label = $newStatus === 'out_of_stock' ? 'Out of Stock' : 'Low Stock';
        $title = "Item {$label}: {$itemName}";
        $message = "\"{$itemName}\" has dropped to {$label} and may need restocking.";

        $recipientRoles = RoleNormalizerService::rawValuesFor(['super_admin', 'maintenance_admin']);
        $recipientIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', $recipientRoles)
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            $this->notificationService->notify((int) $recipientId, $title, $message, 'inventory', $itemId);
        }
    }
}
