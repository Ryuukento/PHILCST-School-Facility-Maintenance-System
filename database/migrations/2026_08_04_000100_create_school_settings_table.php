<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 16 — Semester-Based Dashboard Statistics.
 *
 * Investigation confirmed no semester/academic-year/settings table exists
 * anywhere in this schema (exhaustive grep across all prior migrations).
 * Per the accepted design, this is deliberately the SMALLEST possible table:
 *
 *   school_year          — free-text label the Administrator sets, e.g. "2026-2027".
 *   current_semester     — "First Semester" or "Second Semester".
 *   semester_started_at  — explicit business field: the moment the CURRENT
 *                          semester began. This is deliberately separate
 *                          from the standard `updated_at` metadata column,
 *                          because `updated_at` changes on ANY edit to this
 *                          row (e.g. fixing a typo in school_year) and is
 *                          therefore unsafe to use as a statistics cutoff.
 *                          `semester_started_at` only changes when the
 *                          Super Administrator explicitly starts a new
 *                          semester (see SchoolSettingsController::update()).
 *
 * Nothing more. There is no semester-history table, no academic_semesters
 * CRUD, and no automatic date inference. This table is never joined against
 * `maintenance_reports`, and no column, row, or date on `maintenance_reports`
 * is touched by this migration.
 *
 * Exactly one row ever exists (id = 1), seeded below with a sensible
 * default so the dashboard never breaks on first load.
 *
 * Seed value for semester_started_at: if we seeded it with `now()`, every
 * report created before today (i.e. everything that already exists) would
 * instantly vanish from the KPI cards the moment this migration runs — an
 * artificial, confusing drop that has nothing to do with an actual semester
 * change. To avoid that one-time false "reset", the seed value is backdated
 * to the earliest existing `maintenance_reports.created_at` (falling back to
 * `now()` on a brand-new/empty database) — for backward compatibility on
 * first deployment ONLY. The very first REAL change to this field happens
 * the next time a super_admin explicitly starts a new semester via
 * PUT /api/school-settings. This is a one-time bootstrap value only — it is
 * not an ongoing/automatic semester-date-inference mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_settings')) {
            return;
        }

        Schema::create('school_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('school_year', 20)->default('2026-2027');
            $table->string('current_semester', 20)->default('First Semester');
            $table->timestamp('semester_started_at')->nullable();
            $table->timestamps();
        });

        // Backward-compatibility seed ONLY: makes first deployment a no-op
        // for the dashboard (see class doc above). Not used after this.
        $seedTimestamp = Schema::hasTable('maintenance_reports')
            ? (DB::table('maintenance_reports')->min('created_at') ?? now())
            : now();

        DB::table('school_settings')->insert([
            'school_year'         => '2026-2027',
            'current_semester'    => 'First Semester',
            'semester_started_at' => $seedTimestamp,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_settings');
    }
};
