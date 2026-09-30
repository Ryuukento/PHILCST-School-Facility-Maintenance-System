<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * TASK 17 — $entityType/$entityId are optional and additive so every
     * existing call site keeps compiling unchanged. When the caller already
     * has the record in scope (it always does — see DispatchService,
     * DamageReportService), pass its type/id through so the notification
     * knows what it is about and the frontend can deep-link to it without
     * parsing $title/$message text. $entityType is a short, lowercase,
     * singular noun ('report', 'dispatch', 'damage_report', 'user')
     * matching the routing map in notification.js.
     *
     * TASK 13 PHASE 8/9 (Repair retirement) — RepairService was dropped from
     * the caller list above (the class is deleted) and 'repair_request' from
     * the vocabulary, because nothing writes that entity_type any more.
     *
     * THIS IS A DOCBLOCK CHANGE ONLY. No historical data is touched: existing
     * notifications rows that already carry entity_type='repair_request' are
     * left exactly as they are, per the task's "DO NOT delete historical
     * notifications" rule. notification.js already stopped deep-linking that
     * type in TASK 12 (it falls through to a non-navigating notification
     * rather than erroring), so old rows still render, they just do not link.
     */
    public function notify(
        int $userId,
        string $title,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null
    ): void {
        if ($userId <= 0) {
            return;
        }

        try {
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create notification', [
                'user_id' => $userId,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
