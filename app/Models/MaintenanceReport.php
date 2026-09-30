<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MaintenanceReport extends Model
{
    use HasFactory;

    protected $table = 'maintenance_reports';

    protected $primaryKey = 'report_id';

    protected $fillable = [
        'title',
        'description',
        // SPRINT 4: these three columns were added to the table in Sprint 1
        // (2026_07_28_001500_add_report_category_and_asset_fields_...) but
        // never added to $fillable, so mass-assignment silently dropped them
        // on every create() call. Whitelisting them now — no schema change,
        // no behavior change for any existing caller (nothing passed these
        // keys before; DamageReportService::createReport() is the first).
        'report_category',
        // Problem Type — the category of maintenance concern (Electrical,
        // Plumbing, ...). Distinct from report_category above, which is
        // workflow provenance; see config/maintenance_reports.php for why
        // neither can stand in for the other. problem_type_other holds the
        // free text for the 'Other' category and is null for every other one.
        'problem_type',
        'problem_type_other',
        'item_id',
        'source_dispatch_id',
        'location',
        'priority',
        'status',
        'created_by',
        'assigned_to',
        'department_id',
        'due_date',
        'completed_date',
        'need_change_item_id',
        'need_change_quantity',
        'need_change_status',
        'need_change_approved_by',
        'need_change_approved_at',
        'need_change_deducted_at',
        // Disposal archive — see 2026_09_30_000300_add_disposal_fields_to_maintenance_reports_table.
        'need_change_disposed_by',
        'need_change_disposed_at',
        'need_change_disposal_notes',
        'completion_proof_image',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_date' => 'date',
            'need_change_approved_at' => 'datetime',
            'need_change_deducted_at' => 'datetime',
            'need_change_disposed_at' => 'datetime',
            'archive_reopened_at' => 'datetime',
        ];
    }

    /**
     * Report Archive — a past-term report the Administrator reopened becomes
     * view-only again the moment it is finished again (completed / closed /
     * cancelled), whichever path changes the status. Only touches the
     * columns when a reopen is actually recorded, so tables without them
     * (the isolated test schema) are unaffected.
     */
    protected static function booted(): void
    {
        static::saving(function (self $report): void {
            if ($report->isDirty('status')
                && in_array(strtolower((string) $report->status), \App\Services\ReportArchiveService::FINAL_STATUSES, true)
                && $report->getAttribute('archive_reopened_at') !== null) {
                $report->archive_reopened_at = null;
                $report->archive_reopened_by = null;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to', 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function needChangeItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'need_change_item_id', 'id');
    }

    // -----------------------------------------------------------------------
    // SPRINT 5 — item_id vs. need_change_item_id (Sprint 2 §2 problem 5,
    // Sprint 4 §7 item 6). Reviewed; both columns are already
    // non-duplicative and independently correct as-is, so nothing was
    // merged or renamed. Documenting the standard here rather than
    // changing schema/behavior, per this sprint's "do not introduce
    // duplicate meanings" / "maintain compatibility" instructions:
    //
    //   - item_id: PROVENANCE. The deployed asset this report is ABOUT.
    //     Populated exactly once, at creation, only for
    //     report_category = 'repair_replacement' reports (originated via
    //     DamageReportService::createReport(), Sprint 4). Read-only after
    //     creation; nothing ever writes to it again. Null for any report
    //     not about a specific damaged asset.
    //   - need_change_item_id: REQUEST TARGET. The inventory item a
    //     "Need Change" stock request asks to have deducted. Set later,
    //     independently, via ReportController::update() (case A) and
    //     consumed/approved via NeedChangeService::approve(). May be set
    //     on ANY report regardless of report_category or item_id — it is
    //     a separate request, not a restatement of item_id. It may even
    //     reference the same underlying item as item_id (e.g. "the broken
    //     chair's item type also needs more stock"), which is intentional,
    //     not a duplication.
    //
    // Future code should keep populating each column only through its own
    // originating flow above; neither should be read as a fallback/alias
    // for the other.
    // -----------------------------------------------------------------------

    // SPRINT 5 — reverse of DamageReport::report() (Sprint 4). Read-only
    // convenience relation so a MaintenanceReport can look up its linked
    // compatibility-layer DamageReport (e.g. for status-sync bookkeeping,
    // detail views). Null for any report with no linked damage_reports row
    // (every report created before Sprint 4, and any 'general'/pure
    // need-change report that never had asset details attached).
    public function damageReport(): HasOne
    {
        return $this->hasOne(DamageReport::class, 'report_id', 'report_id');
    }

    // -----------------------------------------------------------------------
    // SPRINT 3 — relationship wiring per SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §8.
    // These are additive, nullable-safe relationships against the new
    // report_id foreign keys.
    //
    // TASK 13 — repairRequest() was removed from this block. It returned an
    // App\Models\RepairRequest, a class this task deletes, so the relation
    // could not survive the model it pointed at. damageReport() above and
    // dispatches()/inventoryTransactions() below are the primary-workflow
    // relations and are untouched.
    // -----------------------------------------------------------------------

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class, 'report_id', 'report_id');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'report_id', 'report_id');
    }
}
