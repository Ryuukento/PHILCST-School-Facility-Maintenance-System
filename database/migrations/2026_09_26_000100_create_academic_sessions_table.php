<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Report Archive — history of academic sessions (one row per School Year).
 *
 * school_settings is a single row that the Administrator overwrites from
 * "Manage Academic Session" (semester-settings.php), so past school years
 * were lost the moment a new one was saved. This table keeps every school
 * year's semester schedule; SchoolSettingsController::update() upserts the
 * row for the saved school year, so the history builds itself from the same
 * screen the Administrator already uses. The Report Archive groups reports
 * into School Year / Semester from these rows.
 *
 * The current school_settings schedule is copied in here so the history
 * starts with the session that is configured today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('school_year', 20)->unique();
            $table->date('first_sem_start');
            $table->date('first_sem_end');
            $table->date('second_sem_start');
            $table->date('second_sem_end');
            $table->timestamps();
        });

        if (Schema::hasTable('school_settings')) {
            $current = DB::table('school_settings')->orderBy('id')->first();
            if ($current && $current->school_year && $current->first_sem_start && $current->first_sem_end
                && $current->second_sem_start && $current->second_sem_end) {
                DB::table('academic_sessions')->insert([
                    'school_year'      => $current->school_year,
                    'first_sem_start'  => $current->first_sem_start,
                    'first_sem_end'    => $current->first_sem_end,
                    'second_sem_start' => $current->second_sem_start,
                    'second_sem_end'   => $current->second_sem_end,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_sessions');
    }
};
