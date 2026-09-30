<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Services\ActivityLogService;
use App\Services\ReportArchiveService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * TASK 16/25 — School Settings (school_year + semester schedule).
 *
 * Single-row global settings the dashboard uses to (a) scope KPI
 * statistics (Total Reports, Pending, In Progress, Completed) to the
 * "current semester" and (b) automatically determine which semester
 * today's date belongs to, from an Administrator-configured schedule.
 *
 * The Administrator no longer picks "First Semester" / "Second Semester"
 * directly — they configure the School Year and the four semester dates.
 * `current_semester` is derived automatically (see SchoolSetting::
 * syncAutomatic()) and returned here for display only.
 *
 * This controller only reads/writes that one row — it never touches
 * maintenance_reports, audit logs, or report filters.
 */
class SchoolSettingsController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    // Any authenticated user may view the current semester / schedule
    // (e.g. to display it next to the dashboard KPI cards).
    public function show(): JsonResponse
    {
        $settings = SchoolSetting::current();

        return $this->ok('School settings retrieved', $this->present($settings));
    }

    // super_admin only (route-gated via EnsureRole::class . ':super_admin').
    // The Administrator only ever edits the schedule (school year + the
    // four semester dates) — current_semester is never accepted here; it
    // is always (re)computed automatically from these dates.
    //
    // TASK 25.1 — validation is intentionally explicit and defensive
    // rather than relying only on Laravel's cross-field `after`/`before`
    // rules, so that every rejection reason is unambiguous to the
    // Administrator (and to automated tests) and nothing is silently
    // "fixed" (e.g. swapped dates, clamped ranges).
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'school_year'      => ['required', 'string', 'max:20'],
            'first_sem_start'  => ['required', 'date'],
            'first_sem_end'    => ['required', 'date'],
            'second_sem_start' => ['required', 'date'],
            'second_sem_end'   => ['required', 'date'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $firstStart  = $request->input('first_sem_start');
            $firstEnd    = $request->input('first_sem_end');
            $secondStart = $request->input('second_sem_start');
            $secondEnd   = $request->input('second_sem_end');

            // If any individual date already failed basic "date" validation
            // above, skip the range/overlap checks — they'd be meaningless.
            if ($validator->errors()->hasAny(['first_sem_start', 'first_sem_end', 'second_sem_start', 'second_sem_end'])) {
                return;
            }

            $firstStart  = Carbon::parse($firstStart)->startOfDay();
            $firstEnd    = Carbon::parse($firstEnd)->startOfDay();
            $secondStart = Carbon::parse($secondStart)->startOfDay();
            $secondEnd   = Carbon::parse($secondEnd)->startOfDay();

            if ($firstStart->greaterThan($firstEnd)) {
                $validator->errors()->add('first_sem_end', 'First Semester Start must not be after First Semester End.');
            }

            if ($secondStart->greaterThan($secondEnd)) {
                $validator->errors()->add('second_sem_end', 'Second Semester Start must not be after Second Semester End.');
            }

            // Symmetric overlap check — catches First-overlaps-Second AND
            // Second-overlaps-First regardless of which pair the
            // Administrator entered "out of order". Two closed date
            // ranges [a1,a2] and [b1,b2] overlap iff a1 <= b2 AND b1 <= a2.
            if ($firstStart->lessThanOrEqualTo($secondEnd) && $secondStart->lessThanOrEqualTo($firstEnd)) {
                $validator->errors()->add('second_sem_start', 'First Semester and Second Semester date ranges must not overlap.');
            }
        });

        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, $validator->errors());
        }

        $validated = $validator->validated();

        $settings = SchoolSetting::current();

        // Plain field updates — none of these touch `semester_started_at`
        // or `current_semester` directly. Whether this counts as a REAL
        // semester transition (and therefore whether the KPI-card cutoff
        // moves) is decided exclusively by syncAutomatic() below, comparing
        // the freshly-computed semester against what was already stored.
        // A no-op save (e.g. fixing a typo in school_year, or re-saving
        // identical dates) never resets the KPI cards.
        $settings->school_year      = $validated['school_year'];
        $settings->first_sem_start  = $validated['first_sem_start'];
        $settings->first_sem_end    = $validated['first_sem_end'];
        $settings->second_sem_start = $validated['second_sem_start'];
        $settings->second_sem_end   = $validated['second_sem_end'];
        $settings->save();

        $settings->syncAutomatic();

        $this->recordAcademicSession($validated);

        $this->activityLogService->log([
            'action' => 'UPDATE_SCHOOL_SETTINGS',
            'module' => 'settings',
            'entity_type' => 'school_setting',
            'entity_id' => $settings->id,
            'details' => 'Updated school year to ' . $validated['school_year']
                . ' (' . $validated['first_sem_start'] . ' to ' . $validated['second_sem_end'] . ').',
        ], $request);

        return $this->ok('School settings updated', $this->present($settings));
    }

    /**
     * Report Archive — keep this School Year's schedule in academic_sessions
     * (the history the Report Archive groups past-term reports by), since
     * school_settings itself only ever holds the current one.
     *
     * One row per School Year: re-saving a School Year updates its row. When
     * only the School Year label changed and the dates are identical to an
     * existing row, that row is renamed instead — a typo fix in "2026-2027"
     * must not leave a phantom School Year behind in the archive.
     *
     * @param  array<string, string>  $validated
     */
    private function recordAcademicSession(array $validated): void
    {
        if (!Schema::hasTable('academic_sessions')) {
            return;
        }

        $dates = [
            'first_sem_start'  => Carbon::parse($validated['first_sem_start'])->toDateString(),
            'first_sem_end'    => Carbon::parse($validated['first_sem_end'])->toDateString(),
            'second_sem_start' => Carbon::parse($validated['second_sem_start'])->toDateString(),
            'second_sem_end'   => Carbon::parse($validated['second_sem_end'])->toDateString(),
        ];

        $existing = AcademicSession::query()->where('school_year', $validated['school_year'])->first();
        if (!$existing) {
            $existing = AcademicSession::query()
                ->whereDate('first_sem_start', $dates['first_sem_start'])
                ->whereDate('first_sem_end', $dates['first_sem_end'])
                ->whereDate('second_sem_start', $dates['second_sem_start'])
                ->whereDate('second_sem_end', $dates['second_sem_end'])
                ->first();
        }

        ($existing ?? new AcademicSession())
            ->fill(['school_year' => $validated['school_year']] + $dates)
            ->save();
    }

    /**
     * Report Archive — the recorded School Years and their semesters, for
     * the All Reports archive picker. Any authenticated user (read-only).
     */
    public function sessions(ReportArchiveService $archive): JsonResponse
    {
        $terms = $archive->terms();

        $schoolYears = [];
        foreach ($terms as $term) {
            $key = $term['school_year'];
            $schoolYears[$key] ??= [
                'school_year' => $key,
                'label'       => 'SY ' . ReportArchiveService::formatSchoolYear($key),
                'start'       => $term['start'],
                'end'         => $term['end'],
                'semesters'   => [],
            ];
            $schoolYears[$key]['start'] = min($schoolYears[$key]['start'], $term['start']);
            $schoolYears[$key]['end'] = max($schoolYears[$key]['end'], $term['end']);
            $schoolYears[$key]['semesters'][] = [
                'semester' => $term['semester'],
                'start'    => $term['start'],
                'end'      => $term['end'],
            ];
        }

        // Newest School Year first for the picker.
        $schoolYears = array_reverse(array_values($schoolYears));

        // Reports filed before the earliest recorded School Year have no term
        // to belong to; the picker offers them as "Earlier records".
        $earliestStart = $terms[0]['start'] ?? null;
        $hasEarlierReports = $earliestStart !== null
            && Schema::hasTable('maintenance_reports')
            && DB::table('maintenance_reports')->whereDate('created_at', '<', $earliestStart)->exists();

        return $this->ok('Academic sessions retrieved', [
            'archive_cutoff'      => $archive->cutoff(),
            'school_years'        => $schoolYears,
            'earliest_start'      => $earliestStart,
            'has_earlier_reports' => $hasEarlierReports,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SchoolSetting $settings): array
    {
        return [
            'school_year'          => $settings->school_year,
            // TASK 25.2 — null whenever no semester is literally running
            // right now (Upcoming / Break / Completed). Never guessed.
            'current_semester'     => $settings->current_semester,
            'semester_active'      => $settings->isActive(),
            'semester_status'      => $settings->semesterStatus(),
            'semester_days_until_start' => $settings->daysUntilFirstSemesterStart(),
            'first_sem_start'      => optional($settings->first_sem_start)->toDateString(),
            'first_sem_end'        => optional($settings->first_sem_end)->toDateString(),
            'second_sem_start'     => optional($settings->second_sem_start)->toDateString(),
            'second_sem_end'       => optional($settings->second_sem_end)->toDateString(),
            'semester_started_at'  => optional($settings->semester_started_at)->toIso8601String(),
            'updated_at'           => optional($settings->updated_at)->toIso8601String(),
        ];
    }
}
