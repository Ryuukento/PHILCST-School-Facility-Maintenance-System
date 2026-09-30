<?php

namespace App\Services;

use App\Models\DamageReport;
use App\Models\MaintenanceReport;

/**
 * SPRINT 5 — Status Synchronization.
 *
 * Sprint 4's remaining-work list flagged that a DamageReport status change
 * never propagated up to the paired maintenance_reports row created alongside
 * it, so `maintenance_reports.status` could silently go stale for any report
 * created after Sprint 4 shipped.
 *
 * This service closes that gap with a small, single-purpose, one-directional
 * sync: damage_reports.status -> maintenance_reports.status. It does NOT
 * redesign the status model — `DamageReport::STATUS_TRANSITIONS`
 * (DamageReportService) keeps enforcing its own transitions exactly as before;
 * this service only maps an already-valid resulting damage-report status
 * onto the closest equivalent maintenance_reports.status value.
 *
 * Deliberately one-directional (damage -> maintenance, never the reverse):
 * maintenance_reports.status is coarser-grained than damage_reports.status
 * (6 states), so there is no lossless way to derive a specific damage
 * sub-state from a generic maintenance_reports.status edit. Reversing the
 * direction is left as an open question for a future sprint (see
 * SPRINT_5_PRIMARY_WORKFLOW_MIGRATION.md §8).
 *
 * TASK 13 PHASE 4 (Repair retirement) — THIS SERVICE MUST REMAIN; it is on
 * the brief's protected list and is part of the primary workflow. Only the
 * docblock changed. It previously named RepairRequest::STATUS_TRANSITIONS as
 * a second status model and claimed RepairService was a caller via
 * syncDamageReportStatus()/fulfillReplacement(). Both statements are now
 * false — RepairService is deleted — and the second was ALREADY misleading
 * before this task, because the class exposes exactly one public method,
 * syncFromDamageReport(), whose only input is a damage report.
 *
 * The sole caller is DamageReportService::updateStatus(). No behaviour,
 * signature or mapping table below is altered by Task 13.
 */
class MaintenanceReportSyncService
{
    /**
     * damage_reports.status -> maintenance_reports.status.
     * Mirrors the exact same linear progression already enforced by
     * DamageReportService::STATUS_TRANSITIONS (pending -> under_review ->
     * repairing -> repaired/replaced -> closed), mapped onto the nearest
     * equivalent step of ReportController::STATUS_TRANSITIONS (submitted ->
     * assigned -> in_progress -> completed -> closed).
     */
    private const DAMAGE_STATUS_MAP = [
        'pending'      => 'submitted',
        'under_review' => 'assigned',
        'repairing'    => 'in_progress',
        'repaired'     => 'completed',
        'replaced'     => 'completed',
        'closed'       => 'closed',
    ];

    /**
     * Syncs a DamageReport's current status onto its linked MaintenanceReport
     * (via the report_id FK Sprint 4 introduced). Safe no-op for any legacy
     * DamageReport with report_id === null (nothing to sync to) and for any
     * MaintenanceReport already in a terminal state ('closed'/'cancelled'
     * are never reopened by this sync, matching the same terminal-state rule
     * already enforced in ReportController::STATUS_TRANSITIONS).
     */
    public function syncFromDamageReport(DamageReport $damageReport): void
    {
        $reportId = $damageReport->report_id;
        if (!$reportId) {
            return;
        }

        $mappedStatus = self::DAMAGE_STATUS_MAP[$damageReport->status] ?? null;
        if ($mappedStatus === null) {
            return;
        }

        $maintenanceReport = MaintenanceReport::query()->find($reportId);
        if (!$maintenanceReport) {
            return;
        }

        if (in_array($maintenanceReport->status, ['closed', 'cancelled'], true)) {
            return;
        }

        if ($maintenanceReport->status === $mappedStatus) {
            return;
        }

        $changes = ['status' => $mappedStatus];
        if (in_array($mappedStatus, ['completed', 'closed'], true) && !$maintenanceReport->completed_date) {
            $changes['completed_date'] = now()->toDateString();
        }

        $maintenanceReport->update($changes);
    }
}
