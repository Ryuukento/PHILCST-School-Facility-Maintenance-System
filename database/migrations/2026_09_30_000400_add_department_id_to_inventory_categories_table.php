<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK — lays the groundwork for splitting a single dispatch's Release
     * Personnel assignment by equipment category/department (a dispatch can
     * mix items from several categories — e.g. Computer + Aircon — that no
     * single department's staff can competently release together). Before
     * that split can exist, each inventory_categories row needs to know
     * which department normally handles that kind of item.
     *
     * This migration only adds the column and seeds the initial mapping the
     * user approved. It does NOT change dispatch/release logic yet — that is
     * a separate, larger phase (new dispatch_release_groups table + release
     * UI overhaul) to follow once this mapping has been reviewed.
     */
    public function up(): void
    {
        if (!Schema::hasTable('inventory_categories') || Schema::hasColumn('inventory_categories', 'department_id')) {
            return;
        }

        Schema::table('inventory_categories', function ($table) {
            $table->unsignedInteger('department_id')->nullable()->after('code');
            $table->foreign('department_id')->references('department_id')->on('departments')->nullOnDelete();
        });

        // User-approved category -> department mapping (by department name,
        // resolved to department_id at migration time so this works
        // regardless of the exact ids in any given environment).
        $mapping = [
            'electrical'            => 'Electrical',
            'plumbing_water'        => 'Plumbing',
            'aircon_ventilation'    => 'Electrical',
            'it'                    => 'Computer',
            'furniture'             => 'Carpentry',
            'building_structural'   => 'Carpentry',
            'classroom_facilities'  => 'General Maintenance',
            'laboratory_equipment'  => 'General Maintenance',
            'restroom_facilities'   => 'Plumbing',
            'safety_security'       => 'General Maintenance',
            'cleaning_sanitation'   => 'General Maintenance',
            'grounds_outdoor'       => 'General Maintenance',
            'communication_av'      => 'Computer',
            'transportation_vehicle' => 'General Maintenance',
            'other_general'         => 'General Maintenance',
        ];

        $departmentIdsByName = DB::table('departments')
            ->pluck('department_id', 'name');

        foreach ($mapping as $code => $departmentName) {
            $departmentId = $departmentIdsByName[$departmentName] ?? null;

            if ($departmentId === null) {
                continue;
            }

            DB::table('inventory_categories')
                ->where('code', $code)
                ->update(['department_id' => $departmentId]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_categories') || !Schema::hasColumn('inventory_categories', 'department_id')) {
            return;
        }

        Schema::table('inventory_categories', function ($table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
