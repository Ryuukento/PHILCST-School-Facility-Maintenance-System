<?php

/**
 * Maintenance Report domain vocabularies.
 *
 * WHY THIS FILE EXISTS: the Problem Type list has four consumers — the backend
 * `in:` validation rule, the Create Report card grid, the Edit Report card
 * grid, and the report detail view. Hardcoding it in any of them would create a
 * second copy that can silently drift from the validation rule, which is the
 * exact failure mode config/preventive_maintenance.php already avoids for its
 * own fixed category list ("served from config so the frontend never hardcodes
 * them either" — PreventiveMaintenanceController::options()). This is that same
 * established mechanism, not a new one.
 *
 * DELIBERATELY NO env() ANYWHERE IN THIS FILE. The two Create/Edit Report pages
 * are plain PHP served directly by Apache — they never boot the Laravel
 * container, so they cannot call config(). They `require` this file directly
 * instead, which works only because every value below is a literal. Introducing
 * env() here would return null on those pages and silently empty the picker.
 * (config/preventive_maintenance.php can use env() because its only consumer is
 * an API controller running inside the framework.)
 *
 * The list is FIXED. Adding a category is a deliberate edit here, which
 * automatically updates the validation rule and both card grids together.
 */
return [
    /**
     * Problem Type — the category of maintenance concern being reported.
     *
     * NOT to be confused with maintenance_reports.report_category, which is a
     * different axis entirely: that column is workflow provenance
     * ('general' vs 'repair_replacement', i.e. which creation path produced the
     * row) and is written by DamageReportService, never by a human. A report is
     * simultaneously one report_category AND one problem_type; they do not
     * overlap and neither can be derived from the other. See the "Root cause"
     * note in the task report for why report_category could not be reused.
     *
     * `value` is what is persisted in maintenance_reports.problem_type and what
     * the backend `in:` rule accepts, so it is also the display label — one
     * string, no value/label mapping to keep in sync.
     *
     * `icon` is a key from public/frontend/includes/icon-paths.php (the
     * codebase's single icon registry, TASK 7). No emoji, no icon library.
     */
    'problem_types' => [
        ['value' => 'Electrical',    'icon' => 'zap'],
        ['value' => 'Plumbing',      'icon' => 'droplet'],
        ['value' => 'HVAC / Aircon', 'icon' => 'wind'],
        ['value' => 'Carpentry',     'icon' => 'hammer'],
        ['value' => 'Furniture',     'icon' => 'armchair'],
        ['value' => 'Grounds',       'icon' => 'leaf'],
        ['value' => 'Roofing',       'icon' => 'roof'],
        ['value' => 'Other',         'icon' => 'help-circle'],
    ],

    /**
     * The one entry that unlocks the free-text "Please specify the problem
     * type" field. Named here rather than written as a bare 'Other' literal in
     * the controller, the service, and two pages, so the special case is
     * defined exactly once alongside the list it belongs to.
     */
    'problem_type_other_value' => 'Other',

    // Matches the problem_type_other column width in
    // 2026_09_20_000100_add_problem_type_to_maintenance_reports_table.php.
    'problem_type_other_max' => 100,
];
