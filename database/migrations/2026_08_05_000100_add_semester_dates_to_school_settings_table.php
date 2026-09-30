<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 25 — Automatic Semester Management.
 *
 * Extends Task 16's single-row `school_settings` table with the four
 * date columns the Administrator configures ONCE:
 *
 *   first_sem_start  / first_sem_end   — First Semester date range.
 *   second_sem_start / second_sem_end  — Second Semester date range.
 *
 * With these dates known, `current_semester` and `semester_started_at`
 * (both already present from Task 16) no longer need to be set by hand —
 * `SchoolSetting::current()` derives them automatically from today's date
 * every time the row is read (see App\Models\SchoolSetting::syncAutomatic()).
 *
 * This migration does NOT touch `maintenance_reports` or any other table.
 * It does NOT remove `current_semester`/`semester_started_at` — they are
 * kept so `DashboardController::stats()` continues to work with zero
 * changes to its existing WHERE-scoping logic.
 *
 * The existing single row (id = 1) is seeded with the example dates from
 * the accepted design (School Year 2026-2027):
 *   First Semester:  Oct 1, 2026 – Feb 28, 2027
 *   Second Semester: Mar 1, 2027 – Jul 31, 2027
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('school_settings', 'first_sem_start')) {
            Schema::table('school_settings', function (Blueprint $table): void {
                $table->date('first_sem_start')->nullable()->after('current_semester');
                $table->date('first_sem_end')->nullable()->after('first_sem_start');
                $table->date('second_sem_start')->nullable()->after('first_sem_end');
                $table->date('second_sem_end')->nullable()->after('second_sem_start');
            });
        }

        // Seed the existing row (if present and not already configured)
        // with the example dates so the dashboard has a sensible default
        // instead of nulls. This never resets school_year/current_semester
        // and never touches maintenance_reports.
        if (Schema::hasTable('school_settings')) {
            DB::table('school_settings')
                ->where('id', 1)
                ->whereNull('first_sem_start')
                ->update([
                    'first_sem_start'  => '2026-10-01',
                    'first_sem_end'    => '2027-02-28',
                    'second_sem_start' => '2027-03-01',
                    'second_sem_end'   => '2027-07-31',
                    'updated_at'       => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('school_settings', 'first_sem_start')) {
            Schema::table('school_settings', function (Blueprint $table): void {
                $table->dropColumn(['first_sem_start', 'first_sem_end', 'second_sem_start', 'second_sem_end']);
            });
        }
    }
};
