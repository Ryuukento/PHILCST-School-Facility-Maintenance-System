<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the old, inconsistent category seed (a mix of room-types like
     * "Laboratory"/"Classroom"/"PAB" and equipment-types like "Electrical"/
     * "Furniture") with a single facility-maintenance-concern taxonomy
     * supplied by the school's maintenance staff. Every row classifies WHAT
     * KIND of item/concern it is, not WHICH ROOM it's in (that's what
     * items.room_id / dispatches.room_id already do).
     *
     * Safe to fully replace rather than rename in place: as of this
     * migration, `items.category_id` has zero non-null rows in every known
     * environment (verified via `SELECT COUNT(*) FROM items` = 0 before
     * writing this migration), so there is no live data pointing at any
     * existing category id. If that ever isn't true for some environment,
     * the `nullOnDelete()` FK on items.category_id (see
     * 2026_04_07_001000_add_inventory_categories_and_thresholds.php) means
     * deleting a still-referenced category safely nulls it out rather than
     * failing or cascading — no data loss, just an item that shows
     * "Uncategorized" until re-filed under the new list.
     */
    public function up(): void
    {
        if (!Schema::hasTable('inventory_categories')) {
            return;
        }

        DB::table('inventory_categories')->delete();

        $rows = [
            ['name' => 'Electrical',                       'code' => 'electrical',              'threshold' => 5],
            ['name' => 'Plumbing & Water',                 'code' => 'plumbing_water',           'threshold' => 5],
            ['name' => 'Air Conditioning & Ventilation',   'code' => 'aircon_ventilation',       'threshold' => 3],
            ['name' => 'Information Technology (IT)',      'code' => 'it',                       'threshold' => 3],
            ['name' => 'Furniture',                        'code' => 'furniture',                'threshold' => 3],
            ['name' => 'Building & Structural',            'code' => 'building_structural',      'threshold' => 1],
            ['name' => 'Classroom Facilities',              'code' => 'classroom_facilities',     'threshold' => 3],
            ['name' => 'Laboratory Equipment',              'code' => 'laboratory_equipment',     'threshold' => 3],
            ['name' => 'Restroom Facilities',               'code' => 'restroom_facilities',      'threshold' => 3],
            ['name' => 'Safety & Security',                 'code' => 'safety_security',          'threshold' => 3],
            ['name' => 'Cleaning & Sanitation',              'code' => 'cleaning_sanitation',      'threshold' => 10],
            ['name' => 'Grounds & Outdoor Facilities',      'code' => 'grounds_outdoor',          'threshold' => 1],
            ['name' => 'Communication & Audio-Visual',      'code' => 'communication_av',         'threshold' => 3],
            ['name' => 'Transportation/Vehicle',            'code' => 'transportation_vehicle',   'threshold' => 1],
            ['name' => 'Other / General Maintenance',       'code' => 'other_general',            'threshold' => null],
        ];

        foreach ($rows as $i => $row) {
            DB::table('inventory_categories')->insert([
                'name'                        => $row['name'],
                'code'                        => $row['code'],
                'default_low_stock_threshold' => $row['threshold'],
                'allow_threshold_override'    => 1,
                'is_active'                   => 1,
                'sort_order'                  => $i + 1,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_categories')) {
            return;
        }

        $newCodes = [
            'electrical', 'plumbing_water', 'aircon_ventilation', 'it', 'furniture',
            'building_structural', 'classroom_facilities', 'laboratory_equipment',
            'restroom_facilities', 'safety_security', 'cleaning_sanitation',
            'grounds_outdoor', 'communication_av', 'transportation_vehicle', 'other_general',
        ];

        DB::table('inventory_categories')->whereIn('code', $newCodes)->delete();

        $oldRows = [
            ['name' => 'Consumables',              'code' => 'consumables',             'threshold' => 20, 'sort_order' => 1],
            ['name' => 'Equipment',                'code' => 'equipment',               'threshold' => 3,  'sort_order' => 2],
            ['name' => 'Fixtures',                 'code' => 'fixtures',                'threshold' => 1,  'sort_order' => 3],
            ['name' => 'Electrical',               'code' => 'electrical_old',          'threshold' => 5,  'sort_order' => 4],
            ['name' => 'IT & Electronics',         'code' => 'it_electronics',          'threshold' => 5,  'sort_order' => 5],
            ['name' => 'Furniture',                'code' => 'furniture_old',           'threshold' => 5,  'sort_order' => 6],
            ['name' => 'Plumbing',                 'code' => 'plumbing',                'threshold' => 5,  'sort_order' => 7],
            ['name' => 'Classroom',                'code' => 'classroom',               'threshold' => 5,  'sort_order' => 8],
            ['name' => 'Laboratory',               'code' => 'laboratory',              'threshold' => 5,  'sort_order' => 9],
            ['name' => 'Computer Laboratory',      'code' => 'computer_laboratory',     'threshold' => 5,  'sort_order' => 10],
            ['name' => 'Chemistry Laboratory',     'code' => 'chemistry_laboratory',    'threshold' => 5,  'sort_order' => 11],
            ['name' => 'Simulation Laboratory',    'code' => 'simulation_laboratory',   'threshold' => 5,  'sort_order' => 12],
            ['name' => 'PAB',                      'code' => 'pab',                     'threshold' => 5,  'sort_order' => 13],
        ];

        foreach ($oldRows as $row) {
            $exists = DB::table('inventory_categories')
                ->whereRaw('LOWER(name) = ?', [strtolower($row['name'])])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('inventory_categories')->insert([
                'name'                        => $row['name'],
                'code'                        => $row['code'],
                'default_low_stock_threshold' => $row['threshold'],
                'allow_threshold_override'    => 1,
                'is_active'                   => 1,
                'sort_order'                  => $row['sort_order'],
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);
        }
    }
};
