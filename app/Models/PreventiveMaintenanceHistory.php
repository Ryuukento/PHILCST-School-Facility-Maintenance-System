<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only completion record for a PreventiveMaintenanceTask. Nothing in
 * this codebase ever updates or deletes a row here once written — see the
 * migration's design notes.
 *
 * One narrow exception (2026-09-27): maintenance_report_id may be filled in
 * ONCE, from null, when a repair report is raised for a "needs_repair"
 * inspection (PreventiveMaintenanceService::createRepairReport()). It only
 * links the row to the report; the recorded inspection itself never changes.
 */
class PreventiveMaintenanceHistory extends Model
{
    use HasFactory;

    protected $table = 'preventive_maintenance_history';

    protected $fillable = [
        'preventive_maintenance_task_id',
        'completed_date',
        'performed_by',
        'recorded_by',
        'notes',
        'findings',
        'action_taken',
        'condition_result',
        'maintenance_report_id',
        'completion_proof_path',
        'next_due_date_snapshot',
    ];

    protected function casts(): array
    {
        return [
            // See PreventiveMaintenanceTask::casts() for why these use the
            // Y-m-d format modifier rather than plain 'date' — it avoids a
            // UTC-shift-driven off-by-one-day bug when this record is
            // serialized to JSON for the frontend (app.timezone is
            // Asia/Manila, UTC+8).
            'completed_date' => 'date:Y-m-d',
            'next_due_date_snapshot' => 'date:Y-m-d',
        ];
    }

    protected $appends = [
        'performed_by_name',
        'recorded_by_name',
        'condition_result_label',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenanceTask::class, 'preventive_maintenance_task_id', 'id');
    }

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_id');
    }

    public function maintenanceReport(): BelongsTo
    {
        return $this->belongsTo(MaintenanceReport::class, 'maintenance_report_id', 'report_id');
    }

    public function getConditionResultLabelAttribute(): ?string
    {
        return match ($this->condition_result) {
            'working' => 'Working',
            'needs_repair' => 'Needs Repair',
            default => null,
        };
    }

    public function getPerformedByNameAttribute(): ?string
    {
        return $this->performedByUser?->full_name;
    }

    public function getRecordedByNameAttribute(): ?string
    {
        return $this->recordedByUser?->full_name;
    }
}
