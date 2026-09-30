<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispatch extends Model
{
    use HasFactory;

    protected $table = 'dispatches';

    protected $fillable = [
        'dispatch_code',
        'department_id',
        // TASK 3 — Dispatch Personnel Audit Trail: who actually created this
        // dispatch request. Distinct from approved_by (Administrator) and
        // released_by (whoever hands off/receives the item) — see
        // TASK3_DISPATCH_AUDIT_TRAIL.md.
        'requested_by',
        'approved_by',
        // TASK 1 — Dispatch Inventory Automation: records when an
        // Administrator approved the dispatch (and, per the new workflow,
        // when inventory was automatically released for it).
        'approved_at',
        'released_by',
        // TASK 4 — Approve & Release Workflow: optional free-text remarks
        // about the physical hand-off. TASK 13 moved *who writes them* from
        // the Administrator (at approval) to the assigned Maintenance Staff
        // (at release), since that is now the person actually present for the
        // hand-off. Same column, same meaning, later write point. No accessor
        // needed — it's a plain column and serializes automatically.
        'release_remarks',
        // TASK 13 — Dispatch Release Assignment Workflow: the Maintenance
        // Staff member chosen by Head Maintenance at creation time, who is the
        // only user permitted to release this dispatch. Distinct from
        // released_by, which stays null until the release actually happens.
        'release_assigned_to',
        'release_assigned_by',
        'release_assigned_at',
        'receiver_user_id',
        'room_id',
        // Free-text supplement to room_id — see the migration's doc comment
        // (2026_09_29_000200_add_room_note_to_dispatches_table). Never
        // validated against any list; purely a pointer for whoever performs
        // the release.
        'room_note',
        // TASK 13 (Repair retirement) — 'repair_request_id' was removed from
        // this fillable list. Its only writer was
        // RepairService::fulfillReplacement(), which this task deletes, and
        // the DispatchController::store() rule that accepted it from a client
        // has gone too, so nothing can supply the key any more. The dispatches
        // .repair_request_id COLUMN is deliberately left in the database (it
        // is nullable, so inserts that omit it are unaffected); dropping it
        // belongs to the separate database-cleanup task.
        'damage_report_id',
        'report_id',
        'purchase_receipt_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            // TASK 13 — same treatment as approved_at so the timeline can
            // format it without JS-side date parsing special cases.
            'release_assigned_at' => 'datetime',
        ];
    }

    /**
     * Appended accessors so department_name / room_name / requested_by_name /
     * approved_by_name / released_by_name / receiver_name are always present
     * in the JSON payload without extra JS mapping.
     */
    protected $appends = ['department_name', 'room_name', 'requested_by_name', 'approved_by_name', 'released_by_name', 'receiver_name', 'release_assigned_to_name', 'release_assigned_by_name'];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function items(): HasMany
    {
        return $this->hasMany(DispatchItem::class, 'dispatch_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by', 'user_id');
    }

    public function receiverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_user_id', 'user_id');
    }

    // TASK 13 — Dispatch Release Assignment Workflow.
    public function releaseAssignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'release_assigned_to', 'user_id');
    }

    public function releaseAssignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'release_assigned_by', 'user_id');
    }

    // TASK 13 (Repair retirement) — repairRequest() was removed here. It
    // returned an App\Models\RepairRequest, which this task deletes. Its only
    // reader was DispatchService::notifyDispatchLinkedParty(), whose repair
    // branch went with it; that method still notifies via damageReport()
    // below, so no dispatch loses its notification.
    public function damageReport(): BelongsTo
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id', 'id');
    }

    // SPRINT 3 — new, parallel relationship per
    // SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §5/§8. damageReport() above
    // remains the legacy relationship and is untouched.
    public function report(): BelongsTo
    {
        return $this->belongsTo(MaintenanceReport::class, 'report_id', 'report_id');
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(\App\Models\PurchaseReceipt::class, 'purchase_receipt_id', 'id');
    }

    // -----------------------------------------------------------------------
    // Accessors — keep field names identical to the legacy PHP API so the
    // JS in dispatches.php does not need any column-name changes.
    // -----------------------------------------------------------------------

    public function getDepartmentNameAttribute(): ?string
    {
        return $this->department?->name;
    }

    public function getRoomNameAttribute(): ?string
    {
        return $this->room?->name;
    }

    // TASK 3 — Dispatch Personnel Audit Trail: prefer the dispatch's own
    // requested_by column; fall back to the linked Maintenance Report's
    // creator (the pre-existing, report-only way "Requested By" was ever
    // derived — see DispatchController::index()'s "HEAD DASHBOARD BUG FIX"
    // comment) so dispatches created before this column existed still show
    // a requester whenever one is derivable.
    public function getRequestedByNameAttribute(): ?string
    {
        return $this->requestedByUser?->full_name ?? $this->report?->creator?->full_name;
    }

    public function getApprovedByNameAttribute(): ?string
    {
        return $this->approvedByUser?->full_name;
    }

    public function getReleasedByNameAttribute(): ?string
    {
        return $this->releasedByUser?->full_name;
    }

    public function getReceiverNameAttribute(): ?string
    {
        return $this->receiverUser?->full_name;
    }

    // TASK 13 — exposed the same way as approved_by_name/released_by_name so
    // the dispatch pages can render the assignment without extra JS mapping
    // or a second API call.
    public function getReleaseAssignedToNameAttribute(): ?string
    {
        return $this->releaseAssignedToUser?->full_name;
    }

    public function getReleaseAssignedByNameAttribute(): ?string
    {
        return $this->releaseAssignedByUser?->full_name;
    }
}
