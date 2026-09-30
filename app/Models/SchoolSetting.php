<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TASK 16/25/25.2 — single-row global School Settings.
 *
 * The Administrator configures the semester SCHEDULE only once:
 * `school_year` + the four date columns (first_sem_start/end,
 * second_sem_start/end). `current_semester` and `semester_started_at`
 * are no longer hand-picked — they are derived automatically from
 * today's date every time this row is read, via `syncAutomatic()`.
 *
 * `current()` remains the one entry point the rest of the app uses (e.g.
 * DashboardController::stats()) — automatic semester detection is applied
 * transparently here with zero changes required in any consumer.
 *
 * TASK 25.2 — Enterprise Semester Lifecycle: the system never GUESSES the
 * active semester. `current_semester` is only ever 'First Semester' or
 * 'Second Semester' when today's date is literally inside that semester's
 * configured range — otherwise it is `null` ("no active semester"), and
 * `semesterStatus()` explains WHY (Upcoming First Semester / Semester
 * Break / School Year Completed). There is no "nearest semester" fallback
 * anymore (that was Task 25.1's behavior; it has been replaced here).
 *
 * The "no accidental reset" invariant from Task 16 is preserved: the
 * business field `semester_started_at` (used to scope KPI cards) is only
 * ever rewritten when a semester actually STARTS running — never on an
 * unrelated edit (e.g. fixing a typo in school_year, re-saving identical
 * dates, or the semester ending/going on break).
 */
class SchoolSetting extends Model
{
    public const STATUS_RUNNING               = 'Running';
    public const STATUS_UPCOMING_FIRST         = 'Upcoming First Semester';
    public const STATUS_SEMESTER_BREAK         = 'Semester Break';
    public const STATUS_SCHOOL_YEAR_COMPLETED  = 'School Year Completed';
    public const STATUS_NOT_CONFIGURED         = 'Not Configured';

    protected $table = 'school_settings';

    public $timestamps = true;

    protected $fillable = [
        'school_year',
        'current_semester',
        'first_sem_start',
        'first_sem_end',
        'second_sem_start',
        'second_sem_end',
        'semester_started_at',
    ];

    protected $casts = [
        'first_sem_start'     => 'date',
        'first_sem_end'       => 'date',
        'second_sem_start'    => 'date',
        'second_sem_end'      => 'date',
        'semester_started_at' => 'datetime',
    ];

    public static function current(): self
    {
        $settings = static::query()->first() ?? static::create([
            'school_year'         => '2026-2027',
            'current_semester'    => 'First Semester',
            'first_sem_start'     => '2026-10-01',
            'first_sem_end'       => '2027-02-28',
            'second_sem_start'    => '2027-03-01',
            'second_sem_end'      => '2027-07-31',
            'semester_started_at' => now(),
        ]);

        $settings->syncAutomatic();

        return $settings;
    }

    /**
     * TASK 25.2 — Enterprise Semester Lifecycle. Determine which semester
     * today's date belongs to using the FULL configured ranges (start AND
     * end of both semesters), and persist that determination ONLY if it
     * actually changed (a real semester transition) — this is what makes
     * "Change Semester" unnecessary while still never resetting KPI
     * scoping on a no-op save (e.g. re-saving with a typo fix).
     *
     * Rule (matches the spec exactly, NO fallback/guessing):
     *   IF   today is within [first_sem_start, first_sem_end]  -> First Semester
     *   ELSE IF today is within [second_sem_start, second_sem_end] -> Second Semester
     *   ELSE -> null ("no active semester" — Upcoming / Break / Completed,
     *           see semesterStatus() for which). Enterprise Student
     *           Information Systems never guess the active semester when
     *           today falls outside every configured range — Task 25.1's
     *           "nearest semester" fallback has been removed entirely.
     */
    public function syncAutomatic(): void
    {
        if ($this->first_sem_start === null || $this->first_sem_end === null
            || $this->second_sem_start === null || $this->second_sem_end === null) {
            // Schedule not fully configured yet — no active semester.
            if ($this->current_semester !== null) {
                $this->current_semester = null;
                $this->save();
            }
            return;
        }

        $today = Carbon::today();

        if ($today->betweenIncluded($this->first_sem_start, $this->first_sem_end)) {
            $computedSemester = 'First Semester';
        } elseif ($today->betweenIncluded($this->second_sem_start, $this->second_sem_end)) {
            $computedSemester = 'Second Semester';
        } else {
            // Today is outside BOTH configured ranges (upcoming, on break,
            // or the school year has completed) — there is simply no
            // active semester. Do NOT guess.
            $computedSemester = null;
        }

        if ($computedSemester === $this->current_semester) {
            // No real transition — leave semester_started_at untouched so
            // KPI-card scoping is never falsely reset.
            return;
        }

        $this->current_semester = $computedSemester;

        if ($computedSemester !== null) {
            // A semester is actually STARTING to run right now — this is
            // the one case the Task 16 invariant requires us to record.
            $this->semester_started_at = $computedSemester === 'Second Semester'
                ? $this->second_sem_start
                : $this->first_sem_start;
        }
        // When transitioning OUT of a running semester (computedSemester
        // === null), semester_started_at is intentionally left untouched:
        // it becomes irrelevant the moment DashboardController stops
        // running semester-scoped queries for an inactive semester, and
        // will be correctly rewritten the next time a semester starts.

        $this->save();
    }

    /**
     * Whether a semester is currently running (today literally falls
     * inside a configured range). Consumers (DashboardController) use
     * this to decide whether semester-scoped statistics may be computed
     * at all, instead of silently showing numbers scoped to a stale or
     * nonexistent semester.
     */
    public function isActive(): bool
    {
        return $this->current_semester !== null;
    }

    /**
     * TASK 25.2 — Explains WHY there is or isn't an active semester right
     * now. This is what the Dashboard and Semester Settings page display
     * instead of ever guessing/defaulting to a semester.
     *
     *   Case 1: today in [first_sem_start, first_sem_end]   -> Running
     *   Case 2: today in [second_sem_start, second_sem_end] -> Running
     *   Case 3: today before first_sem_start                -> Upcoming First Semester
     *   Case 4: today after second_sem_end                  -> School Year Completed
     *   Case 5: today between first_sem_end and second_sem_start -> Semester Break
     */
    public function semesterStatus(): string
    {
        if ($this->first_sem_start === null || $this->first_sem_end === null
            || $this->second_sem_start === null || $this->second_sem_end === null) {
            return self::STATUS_NOT_CONFIGURED;
        }

        $today = Carbon::today();

        if ($today->betweenIncluded($this->first_sem_start, $this->first_sem_end)
            || $today->betweenIncluded($this->second_sem_start, $this->second_sem_end)) {
            return self::STATUS_RUNNING;
        }

        if ($today->lessThan($this->first_sem_start)) {
            return self::STATUS_UPCOMING_FIRST;
        }

        if ($today->greaterThan($this->second_sem_end)) {
            return self::STATUS_SCHOOL_YEAR_COMPLETED;
        }

        // Neither running, nor before First Semester, nor after Second
        // Semester -> the gap between First Semester End and Second
        // Semester Start (validation guarantees the two ranges never
        // overlap, so this gap, if any, is unambiguous).
        return self::STATUS_SEMESTER_BREAK;
    }

    /**
     * Days remaining until First Semester starts — only meaningful (and
     * only ever non-null) while status is "Upcoming First Semester".
     * Optional display hint ("Starts in X days") requested by Task 25.2.
     */
    public function daysUntilFirstSemesterStart(): ?int
    {
        if ($this->semesterStatus() !== self::STATUS_UPCOMING_FIRST) {
            return null;
        }

        // NOTE: Carbon 3.x's diffInDays() returns a SIGNED difference by
        // default (negative when the argument date is before the calling
        // date) — abs() is required here for the same reason it was
        // required in Task 25.1's now-removed distanceToRange() helper.
        return (int) abs(Carbon::today()->diffInDays($this->first_sem_start));
    }
}
