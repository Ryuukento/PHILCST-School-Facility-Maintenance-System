<?php

namespace App\Services;

use App\Models\MaintenanceReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Report Archive — decides which maintenance reports belong to a past
 * academic term and which of those are view-only.
 *
 * Terms come from academic_sessions (the School Year history kept by
 * "Manage Academic Session"), so the archive always follows what the
 * Administrator configured — never a hard-coded calendar.
 *
 *   Archive cutoff  = the start of the most recent semester that has already
 *                     begun (First or Second Semester of any recorded School
 *                     Year, start <= today). Reports filed BEFORE it are
 *                     "archived" (from a past term).
 *   Locked          = archived AND finished (completed / closed / cancelled)
 *                     AND not reopened by the Administrator -> view-only.
 *   Carried over    = archived but still unfinished -> stays fully actionable
 *                     (a problem reported last term still has to be fixed),
 *                     and locks automatically once it is finished.
 *
 * With no academic_sessions table or no recorded session (e.g. the isolated
 * test schema), there is no cutoff and nothing is archived.
 */
class ReportArchiveService
{
    public const FINAL_STATUSES = ['completed', 'closed', 'cancelled'];

    public const LOCKED_MESSAGE = 'This report is from a past academic term and is archived (view only). '
        . 'Only an Administrator can reopen it for changes.';

    /** @var array<int, array<string, mixed>>|null */
    private ?array $termsCache = null;

    /**
     * Every recorded semester, oldest first:
     * [{ school_year, semester, start (Y-m-d), end (Y-m-d), label }].
     *
     * @return array<int, array<string, string>>
     */
    public function terms(): array
    {
        if ($this->termsCache !== null) {
            return $this->termsCache;
        }

        if (!Schema::hasTable('academic_sessions')) {
            return $this->termsCache = [];
        }

        $terms = [];
        $sessions = DB::table('academic_sessions')->orderBy('first_sem_start')->get();
        foreach ($sessions as $session) {
            foreach ([
                'First Semester'  => ['first_sem_start', 'first_sem_end'],
                'Second Semester' => ['second_sem_start', 'second_sem_end'],
            ] as $semester => [$startKey, $endKey]) {
                if (!$session->{$startKey} || !$session->{$endKey}) {
                    continue;
                }
                $terms[] = [
                    'school_year' => (string) $session->school_year,
                    'semester'    => $semester,
                    'start'       => Carbon::parse($session->{$startKey})->toDateString(),
                    'end'         => Carbon::parse($session->{$endKey})->toDateString(),
                    'label'       => $semester . ' ' . self::formatSchoolYear((string) $session->school_year),
                ];
            }
        }

        usort($terms, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return $this->termsCache = $terms;
    }

    /**
     * The date (Y-m-d) before which reports are archived, or null when no
     * recorded semester has started yet.
     */
    public function cutoff(?Carbon $today = null): ?string
    {
        $todayKey = ($today ?? Carbon::today())->toDateString();
        $cutoff = null;

        foreach ($this->terms() as $term) {
            if ($term['start'] <= $todayKey && ($cutoff === null || $term['start'] > $cutoff)) {
                $cutoff = $term['start'];
            }
        }

        return $cutoff;
    }

    /**
     * Human label of the term a date falls in: "First Semester 2025–2026",
     * "Semester break 2025–2026", or "Before 2026–2027" for dates earlier
     * than every recorded School Year.
     */
    public function termLabelFor(?string $dateKey): ?string
    {
        if (!$dateKey) {
            return null;
        }

        $terms = $this->terms();
        foreach ($terms as $term) {
            if ($dateKey >= $term['start'] && $dateKey <= $term['end']) {
                return $term['label'];
            }
        }

        if ($terms === []) {
            return null;
        }

        if ($dateKey < $terms[0]['start']) {
            return 'Before ' . self::formatSchoolYear($terms[0]['school_year']);
        }

        // Between two recorded terms (a semester break or summer).
        $previous = null;
        foreach ($terms as $term) {
            if ($term['start'] <= $dateKey) {
                $previous = $term;
            }
        }

        return $previous ? 'Break after ' . $previous['label'] : null;
    }

    /**
     * Archive state for one report, as returned to the frontend.
     *
     * @param  mixed  $createdAt   string|Carbon|null
     * @param  mixed  $reopenedAt  string|Carbon|null
     * @return array{is_archived: bool, is_locked: bool, is_carried_over: bool, is_reopened: bool, term_label: ?string}
     */
    public function describe($createdAt, ?string $status, $reopenedAt = null): array
    {
        $dateKey = $createdAt ? Carbon::parse($createdAt)->toDateString() : null;
        $cutoff = $this->cutoff();
        $isArchived = $dateKey !== null && $cutoff !== null && $dateKey < $cutoff;
        $isFinished = in_array(strtolower((string) $status), self::FINAL_STATUSES, true);
        $isReopened = $reopenedAt !== null && $reopenedAt !== '';

        return [
            'is_archived'     => $isArchived,
            'is_locked'       => $isArchived && $isFinished && !$isReopened,
            'is_carried_over' => $isArchived && !$isFinished,
            'is_reopened'     => $isArchived && $isReopened,
            'term_label'      => $this->termLabelFor($dateKey),
        ];
    }

    public function isLocked(MaintenanceReport $report): bool
    {
        return $this->describe(
            $report->created_at,
            (string) $report->status,
            $report->getAttribute('archive_reopened_at')
        )['is_locked'];
    }

    public function isArchived(MaintenanceReport $report): bool
    {
        return $this->describe($report->created_at, (string) $report->status)['is_archived'];
    }

    public static function formatSchoolYear(string $schoolYear): string
    {
        return preg_match('/^\d{4}-\d{4}$/', $schoolYear) ? str_replace('-', '–', $schoolYear) : $schoolYear;
    }
}
