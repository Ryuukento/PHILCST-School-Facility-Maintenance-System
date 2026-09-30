<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Problem Type on Create Report.
     *
     * WHY A NEW COLUMN WAS GENUINELY NEEDED. The brief required an existing
     * field to be reused if one could safely carry this. Every candidate on
     * maintenance_reports was checked first and none can:
     *
     *   - report_category — ENUM('general','repair_replacement'). This is
     *     workflow PROVENANCE, not a trade category: it records which creation
     *     path produced the row, it is written by DamageReportService (never by
     *     a reporter), and ReportController::index() already filters on it.
     *     Overloading it with 'Electrical'/'Plumbing'/... would both destroy
     *     that filter and require widening an enum whose two values other code
     *     branches on.
     *   - priority — severity, an orthogonal axis (an Electrical problem can be
     *     any priority).
     *   - department_id — WHO fixes it, not WHAT is wrong, and is separately
     *     required on the same form.
     *   - title / description — free text; the entire point of this change is
     *     to stop relying on them to infer the category.
     *
     * So two new columns are added. Both are NULLABLE, which is what makes this
     * migration backward compatible:
     *
     *   - Every existing report keeps its data and stays readable; it simply
     *     has no problem type, which the detail view renders as "Not
     *     specified" rather than failing.
     *   - The internal creators that never touch the Create Report form
     *     (PreventiveMaintenanceService, DispatchService, and
     *     DamageReportService's own MaintenanceReport insert) continue to work
     *     untouched — a NOT NULL column would have broken all three.
     *
     * "Required" is therefore enforced at the Create Report boundary
     * (ReportController::store()), not by the schema. That is the same split
     * the existing `location` column already uses: nullable in the schema for
     * the internal creators, required by the form for human reporters.
     *
     * TWO COLUMNS, NOT ONE. problem_type is restricted to the approved
     * vocabulary so the backend `in:` rule the brief requires is actually
     * enforceable and the value stays groupable; the user's free text for
     * "Other" goes in its own problem_type_other column. Storing that free text
     * INTO problem_type would have made the `in:` rule impossible and turned
     * every custom entry into its own unqueryable category.
     *
     * No index is added. Nothing queries, filters or groups by problem_type
     * yet — the brief explicitly rules out new analytics/filtering for this
     * task — and an index with no reader is speculative structure. Add one in
     * the task that introduces the first query.
     */
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('maintenance_reports', 'problem_type')) {
                // varchar, not enum: the vocabulary's single source of truth is
                // config/maintenance_reports.php (read by the validation rule
                // AND both card grids). A DB enum would be a second copy of the
                // same list that can only be changed by another migration, and
                // the two could drift.
                $table->string('problem_type', 50)->nullable()->after('description');
            }

            if (!Schema::hasColumn('maintenance_reports', 'problem_type_other')) {
                // Only ever populated when problem_type === 'Other'.
                // ReportService::resolveProblemTypeOther() is the single place
                // that decides this, and it nulls the column for every other
                // category so a stale custom value cannot survive an edit.
                $table->string('problem_type_other', 100)->nullable()->after('problem_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $dropColumns = [];
            foreach (['problem_type', 'problem_type_other'] as $column) {
                if (Schema::hasColumn('maintenance_reports', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
