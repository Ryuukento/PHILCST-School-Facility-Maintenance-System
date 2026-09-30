<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One School Year's semester schedule, kept as history for the Report
 * Archive. Written by SchoolSettingsController::update() whenever the
 * Administrator saves "Manage Academic Session"; read by ReportArchiveService.
 */
class AcademicSession extends Model
{
    protected $table = 'academic_sessions';

    protected $fillable = [
        'school_year',
        'first_sem_start',
        'first_sem_end',
        'second_sem_start',
        'second_sem_end',
    ];

    protected $casts = [
        'first_sem_start'  => 'date',
        'first_sem_end'    => 'date',
        'second_sem_start' => 'date',
        'second_sem_end'   => 'date',
    ];
}
