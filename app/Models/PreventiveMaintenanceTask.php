<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PreventiveMaintenanceTask extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'preventive_maintenance_tasks';

    protected $fillable = [
        'category',
        'title',
        'item_id',
        'building_id',
        'floor_id',
        'room_id',
        'department_id',
        'assigned_user_id',
        'frequency',
        'scheduled_months',
        'location_name',
        'last_completed_date',
        'next_due_date',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_months' => 'array',
            // Plain 'date' serializes to a full UTC ISO datetime (Carbon's
            // toJSON()), which shifts a Manila-midnight date (app.timezone
            // = Asia/Manila, UTC+8) back into the previous UTC day — e.g.
            // 2026-08-10 becomes "2026-08-09T16:00:00.000000Z" in the JSON
            // payload. The frontend's date-formatting helper re-parses that
            // through a JS Date object and re-localizes it, which happens
            // to round-trip correctly, but code that takes the raw string
            // and does a naive slice(0, 10) (the edit-form populator) picks
            // up the wrong, earlier day. Forcing the Y-m-d format here
            // removes the timezone-conversion step entirely at the source
            // — the attribute is still a Carbon instance for date math
            // elsewhere in this class, only its JSON/array serialization
            // changes.
            'last_completed_date' => 'date:Y-m-d',
            'next_due_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Appended so the frontend table/cards never need a second lookup call
     * per row — same convention as Dispatch's $appends.
     */
    protected $appends = [
        'item_name',
        'building_name',
        'floor_name',
        'room_name',
        'department_name',
        'assigned_user_name',
        'created_by_name',
        'updated_by_name',
        'frequency_label',
        'status',
        'status_label',
        'days_until_due',
        'location_label',
        'scheduled_months_label',
        'next_due_label',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id', 'id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id', 'id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id', 'user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(PreventiveMaintenanceHistory::class, 'preventive_maintenance_task_id', 'id')
            ->orderByDesc('completed_date');
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    public function getItemNameAttribute(): ?string
    {
        return $this->item?->name;
    }

    public function getBuildingNameAttribute(): ?string
    {
        return $this->building?->name;
    }

    public function getFloorNameAttribute(): ?string
    {
        return $this->floor?->name;
    }

    public function getRoomNameAttribute(): ?string
    {
        return $this->room?->name;
    }

    public function getDepartmentNameAttribute(): ?string
    {
        return $this->department?->name;
    }

    public function getAssignedUserNameAttribute(): ?string
    {
        return $this->assignedUser?->full_name;
    }

    public function getCreatedByNameAttribute(): ?string
    {
        return $this->createdByUser?->full_name;
    }

    public function getUpdatedByNameAttribute(): ?string
    {
        return $this->updatedByUser?->full_name;
    }

    public function getFrequencyLabelAttribute(): string
    {
        return (string) (config('preventive_maintenance.frequencies')[$this->frequency] ?? $this->frequency);
    }

    /**
     * Human-readable location, in priority order: the manual's free-text
     * location_name (informal areas like "Gymnasium" or "School Campus" that
     * have no matching building/room record), then the real
     * "Building / Floor / Room" chain, then the linked item's name. The UI
     * never needs to know which of these a given task actually uses.
     */
    public function getLocationLabelAttribute(): ?string
    {
        if (!empty($this->location_name)) {
            return $this->location_name;
        }

        $parts = array_filter([$this->building_name, $this->floor_name, $this->room_name]);
        if (!empty($parts)) {
            return implode(' / ', $parts);
        }

        return $this->item_name;
    }

    /**
     * Whether this task's manual-derived schedule marks the given calendar
     * month (1-12) for maintenance. Tasks without scheduled_months (i.e. not
     * modeled on the manual's fixed-month table, just rolling from
     * last_completed_date + frequency) always return false here — callers
     * fall back to next_due_date comparisons for those.
     */
    public function isScheduledInMonth(int $month): bool
    {
        return in_array($month, $this->scheduled_months ?? [], true);
    }

    /**
     * Comma-separated abbreviated month list for the scheduled_months
     * array, e.g. "Apr, Oct" — used by the annual grid/print view captions
     * and the create/edit form's month picker summary.
     */
    public function getScheduledMonthsLabelAttribute(): ?string
    {
        $months = $this->scheduled_months;
        if (empty($months)) {
            return null;
        }

        static $names = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

        $sorted = $months;
        sort($sorted);

        return implode(', ', array_map(static fn (int $m) => $names[$m] ?? (string) $m, $sorted));
    }

    /**
     * Display string for "when is this next due" that never fabricates a
     * day the manual doesn't specify (spec: "DO NOT invent exact maintenance
     * dates ... If the schedule only specifies a month, show the item as
     * 'Scheduled for {Month} {Year}'"). next_due_date is still stored as a
     * real day internally (needed for due/overdue day-math and sorting),
     * but any task driven by the manual's scheduled_months only ever had
     * that day synthesized (calculateNextDueDateFromMonths always lands on
     * the 1st) — so for those tasks this label collapses the date back down
     * to month + year. Tasks with no scheduled_months (rolling
     * last_completed_date + frequency, or a user-entered exact date) still
     * show the literal next_due_date.
     */
    public function getNextDueLabelAttribute(): ?string
    {
        if (!empty($this->scheduled_months)) {
            if (!$this->next_due_date) {
                return null;
            }

            return 'Scheduled for ' . $this->next_due_date->format('F Y');
        }

        return $this->next_due_date ? $this->next_due_date->format('M j, Y') : null;
    }

    /**
     * Days remaining until next_due_date (negative when overdue). Null when
     * no due date has been set yet.
     *
     * Carbon 3's diffInDays($other, false) is signed as ($other - $this) in
     * days — verified empirically for this app's Carbon install: a future
     * date returns a positive count, a past date returns negative. That is
     * exactly the "days remaining" semantics this accessor needs, so no sign
     * flip is applied.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->next_due_date) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->next_due_date, false);
    }

    /**
     * Computed, not stored — see the migration's design notes for why.
     * Machine-readable key: unscheduled | overdue | due | due_soon | upcoming.
     */
    public function getStatusAttribute(): string
    {
        $days = $this->days_until_due;

        if ($days === null) {
            return 'unscheduled';
        }

        if ($days < 0) {
            return 'overdue';
        }

        if ($days === 0) {
            return 'due';
        }

        $dueSoonDays = (int) config('preventive_maintenance.due_soon_days', 14);
        if ($days <= $dueSoonDays) {
            return 'due_soon';
        }

        return 'upcoming';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'unscheduled' => 'Unscheduled',
            'overdue' => 'Overdue',
            'due' => 'Due',
            'due_soon' => 'Due Soon',
            default => 'Upcoming',
        };
    }
}
