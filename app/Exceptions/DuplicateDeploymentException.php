<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * TASK 36 PHASE 5 — thrown by InventoryTransactionObserver::creating() when a
 * new 'deploy' InventoryTransaction matches the item_id/room_id/quantity/
 * performed_by signature of another 'deploy' transaction created within the
 * configured dedupe window (see InventoryTransactionObserver::DEPLOY_DEDUPE_WINDOW_SECONDS).
 *
 * Mirrors DuplicateDamageReportException's shape exactly (constructor takes
 * the conflicting record's summary, exposes getDuplicate()), per
 * TASK_36_PHASE_4_DEDUPE_WINDOW_DESIGN_ANALYSIS_REPORT.md Section 11, which
 * establishes 409 + an existing-record payload as this repository's house
 * style for "this looks like a duplicate of something that already happened"
 * rather than inventing a new response shape.
 */
class DuplicateDeploymentException extends RuntimeException
{
    public function __construct(private readonly array $duplicate)
    {
        parent::__construct('This deployment appears to duplicate one just processed for the same item, room, quantity, and user.');
    }

    public function getDuplicate(): array
    {
        return $this->duplicate;
    }
}
