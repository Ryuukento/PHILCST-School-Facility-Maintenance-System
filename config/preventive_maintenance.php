<?php

// Preventive Maintenance module configuration. Kept in its own file (rather
// than a new column on the shared school_settings table) so this task does
// not touch any existing settings storage — see the "Data safety" section of
// the task brief. Values can be overridden via .env without a code change.
return [
    // Number of days before next_due_date a task is considered "Due Soon"
    // rather than "Upcoming". Kept configurable here (not hardcoded in the
    // UI/controller/service) per the task's explicit instruction.
    'due_soon_days' => (int) env('PM_DUE_SOON_DAYS', 14),

    // Fixed "Equipment Nomenclature" list, transcribed verbatim (including
    // casing) from the school's physical Preventive Maintenance Management
    // Plan manual. "Other" is appended so an activity not on the official
    // list can still be recorded (as free text) without ever guessing a new
    // official equipment name into existence.
    'categories' => [
        'ROOFTOP',
        'WATER PUMP (JET MATIC)',
        'FIRE EXTINGUISHER',
        'FIRE ALARM SYSTEM',
        'COMPUTERS',
        'BOILER',
        'DIESEL ENGINE',
        'HYDRAULICS AND PNEUMATIC EQUIPMENT',
        'REFRIGERATION TRAINING MODULE',
        'PROCESS CONTROL EQUIPMENT',
        'LAB-VOLT (TRAINING MODULE FOR ELECTRO-TECHNOLOGY COURSE)',
        'AIR CONDITIONING UNIT (ACU)',
        'GENERATOR',
        'ELECTRIC FANS/CEILING FANS',
        'Other',
    ],

    // Default Problem Type for a repair report raised from a "Needs Repair"
    // PM inspection, keyed by PM equipment category. Values must come from
    // config('maintenance_reports.problem_types'); equipment without a close
    // match uses 'Other' with the equipment name as the specified type. The
    // Head/Staff can still pick a different type in the Complete modal.
    'report_problem_types' => [
        'ROOFTOP' => 'Roofing',
        'WATER PUMP (JET MATIC)' => 'Plumbing',
        'FIRE ALARM SYSTEM' => 'Electrical',
        'PROCESS CONTROL EQUIPMENT' => 'Electrical',
        'LAB-VOLT (TRAINING MODULE FOR ELECTRO-TECHNOLOGY COURSE)' => 'Electrical',
        'GENERATOR' => 'Electrical',
        'ELECTRIC FANS/CEILING FANS' => 'Electrical',
        'AIR CONDITIONING UNIT (ACU)' => 'HVAC / Aircon',
        'REFRIGERATION TRAINING MODULE' => 'HVAC / Aircon',
    ],

    // Frequency options and their legend labels, matching the source table's
    // M / SA / Q / A codes.
    'frequencies' => [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annually' => 'Semi-annually',
        'annually' => 'Annually',
    ],
];
