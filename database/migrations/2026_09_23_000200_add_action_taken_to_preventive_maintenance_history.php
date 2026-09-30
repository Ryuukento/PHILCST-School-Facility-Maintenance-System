<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive column for the completion checklist's "Action Taken" field,
 * distinct from the existing "notes" (Remarks) and "findings" fields per
 * the Preventive Maintenance Management Plan checklist spec. Does not
 * touch any existing column, table, or row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_maintenance_history', function (Blueprint $table) {
            $table->text('action_taken')->nullable()->after('findings');
        });
    }

    public function down(): void
    {
        Schema::table('preventive_maintenance_history', function (Blueprint $table) {
            $table->dropColumn('action_taken');
        });
    }
};
