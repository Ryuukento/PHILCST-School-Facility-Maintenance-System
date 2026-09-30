<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DamageReport extends Model
{
    use HasFactory;

    protected $table = 'damage_reports';

    protected $fillable = [
        'damage_report_code',
        'report_id',
        'item_id',
        'room_id',
        'department_id',
        'source_dispatch_id',
        'damage_description',
        'severity_level',
        'reported_by',
        'status',
        'image_path',
        'repair_notes',
        'replacement_item_id',
        'replacement_quantity',
        'replacement_transaction_id',
        'replaced_by',
        'replaced_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'replaced_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by', 'user_id');
    }

    public function replacementItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'replacement_item_id', 'id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DamageReportHistory::class, 'damage_report_id', 'id');
    }

    // TASK 13 (Repair retirement) — repairRequest() was removed here. It
    // returned an App\Models\RepairRequest, which this task deletes. Its
    // readers were all inside RepairService (duplicate-request guard and the
    // "eligible damage reports" search), which goes with it.
    //
    // NOTE this removes the RELATION only. Damage Report's own repair
    // vocabulary — the repair_notes column, the 'repairing'/'repaired'
    // statuses, and replacement_item_id / replacement_quantity /
    // replacement_transaction_id / replaced_by / replaced_at above — is
    // Damage Report data, not Repair Request data, and is untouched.

    // SPRINT 4 — new, parallel relationship per SPRINT_4_WORKFLOW_MIGRATION.md.
    // Populated going forward by DamageReportService::createReport(), which
    // now also creates a linked maintenance_reports row. Legacy rows (and
    // any created via a path that predates this sprint) simply have
    // report_id = null, same as every other nullable relationship column.
    public function report(): BelongsTo
    {
        return $this->belongsTo(MaintenanceReport::class, 'report_id', 'report_id');
    }
}
