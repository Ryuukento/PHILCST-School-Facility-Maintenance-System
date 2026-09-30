<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preventive Maintenance module — additive columns to represent the school's
 * physical Preventive Maintenance Management Plan manual inside the existing
 * preventive_maintenance_tasks table. No existing column is altered/dropped.
 *
 * Design notes:
 *  - location_name: the manual's location column uses informal areas ("All
 *    Buildings", "Gymnasium", "Boiler Room", "School Campus", "INTERNET",
 *    "Local Area Network (LAN)") that do not correspond to real building/
 *    floor/room records. Rather than forcing these into the existing
 *    building_id/floor_id/room_id FKs (which point at actual Building/Room
 *    rows), this free-text column sits alongside them — same precedent as
 *    maintenance_reports.location, which is also plain text. A task may use
 *    location_name, the real FKs, both, or neither.
 *  - scheduled_months: the manual marks specific calendar months with "X"
 *    per equipment/location row (e.g. a semi-annual item might be marked
 *    April + October specifically, not just "6 months after whenever it
 *    started"). frequency + next_due_date alone cannot express which
 *    specific months recur every year, so this JSON array of integers
 *    (1-12) captures the X-mark pattern directly and drives both the annual
 *    schedule grid and the month-based checklist. Nullable/empty for tasks
 *    that don't use the manual's fixed-month model (existing behavior —
 *    frequency-interval rolling from last_completed_date — is unaffected
 *    when this is null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_maintenance_tasks', function (Blueprint $table): void {
            if (!Schema::hasColumn('preventive_maintenance_tasks', 'location_name')) {
                $table->string('location_name', 255)->nullable()->after('room_id');
            }
            if (!Schema::hasColumn('preventive_maintenance_tasks', 'scheduled_months')) {
                $table->json('scheduled_months')->nullable()->after('frequency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preventive_maintenance_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('preventive_maintenance_tasks', 'scheduled_months')) {
                $table->dropColumn('scheduled_months');
            }
            if (Schema::hasColumn('preventive_maintenance_tasks', 'location_name')) {
                $table->dropColumn('location_name');
            }
        });
    }
};
