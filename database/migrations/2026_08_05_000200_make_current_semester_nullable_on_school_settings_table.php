<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 25.2 — Enterprise Semester Lifecycle.
 *
 * Enterprise Academic/Student Information Systems never "guess" the active
 * semester: if today's date does not fall inside any configured semester
 * range, there simply IS no active semester. This replaces Task 25.1's
 * "nearest semester" fallback (see App\Models\SchoolSetting::syncAutomatic()).
 *
 * `current_semester` therefore needs to be able to represent "no active
 * semester" — previously the column was NOT NULL with a default of
 * 'First Semester', which made an always-guessed value structurally
 * required. This migration ONLY relaxes that constraint (nullable, no
 * default) so the column can honestly represent "nothing is running right
 * now". It does not rename, drop, or repurpose any column, and does not
 * touch any other table (maintenance_reports, notifications, etc. are
 * untouched).
 *
 * Written as raw SQL (not Schema::table()->change()) because this project
 * does not have doctrine/dbal installed, which Laravel's fluent column
 * modification requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('school_settings') || !Schema::hasColumn('school_settings', 'current_semester')) {
            return;
        }

        DB::statement("ALTER TABLE school_settings MODIFY current_semester VARCHAR(20) NULL DEFAULT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('school_settings') || !Schema::hasColumn('school_settings', 'current_semester')) {
            return;
        }

        // Backfill any NULLs before reinstating the NOT NULL constraint,
        // otherwise the ALTER itself would fail.
        DB::table('school_settings')->whereNull('current_semester')->update(['current_semester' => 'First Semester']);
        DB::statement("ALTER TABLE school_settings MODIFY current_semester VARCHAR(20) NOT NULL DEFAULT 'First Semester'");
    }
};
