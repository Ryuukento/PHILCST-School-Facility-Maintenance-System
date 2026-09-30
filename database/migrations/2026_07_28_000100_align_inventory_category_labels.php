<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 1 / Feature 1 — Inventory Category Alignment
     * (IMPLEMENTATION_ROADMAP.md Feature 1 / FEATURE_GAP_ANALYSIS.md §1.1).
     *
     * The `inventory_categories` table already fully supports an arbitrary,
     * admin-editable category taxonomy (see InventoryCategoryController — full
     * CRUD, unique name constraint, no code change required to add/rename/remove
     * a category). This migration does not change that mechanism at all.
     *
     * What it does: align the *labels* of the categories that already exist for
     * this exact purpose (added 2026-05-24, currently unused by any item — see
     * verification in SPRINT1_FEATURE1_REPORT.md) with the panel's exact wording:
     *   "Laboratories"       -> "Laboratory"
     *   "Computer Lab"       -> "Computer Laboratory"
     *   "Chemistry Lab"      -> "Chemistry Laboratory"
     *   "Simulation Lab"     -> "Simulation Laboratory"
     *
     * For any environment where these categories don't exist yet (e.g. a fresh
     * install seeded only with the generic Consumables/Equipment/Fixtures set),
     * the correctly-worded category is created instead, so the fix is portable
     * across environments rather than being a one-off hand edit on this database.
     *
     * No schema change. No existing category is deleted. No item's category_id
     * assignment is touched — items keep pointing at the same category id, only
     * that category's display name changes for the 4 rows in scope.
     */
    public function up(): void
    {
        if (!Schema::hasTable('inventory_categories')) {
            return;
        }

        $targets = [
            ['old' => 'laboratories',    'new' => 'Laboratory',            'code' => 'laboratory',            'threshold' => 5],
            ['old' => 'computer lab',    'new' => 'Computer Laboratory',   'code' => 'computer_laboratory',   'threshold' => 5],
            ['old' => 'chemistry lab',   'new' => 'Chemistry Laboratory',  'code' => 'chemistry_laboratory',  'threshold' => 5],
            ['old' => 'simulation lab',  'new' => 'Simulation Laboratory','code' => 'simulation_laboratory', 'threshold' => 5],
        ];

        foreach ($targets as $t) {
            $alreadyAligned = DB::table('inventory_categories')
                ->whereRaw('LOWER(name) = ?', [strtolower($t['new'])])
                ->exists();

            if ($alreadyAligned) {
                continue;
            }

            $existing = DB::table('inventory_categories')
                ->whereRaw('LOWER(name) = ?', [$t['old']])
                ->first();

            if ($existing) {
                // Rename the pre-existing, item-free near-match category to the
                // panel's exact wording. Preserves id, code, threshold, sort_order.
                DB::table('inventory_categories')->where('id', $existing->id)->update([
                    'name'       => $t['new'],
                    'updated_at' => now(),
                ]);
                continue;
            }

            // No near-match found in this environment — create it fresh,
            // additive-only, matching the pattern of the original category seed.
            $maxSortOrder = (int) DB::table('inventory_categories')->max('sort_order');

            DB::table('inventory_categories')->insert([
                'name'                        => $t['new'],
                'code'                        => $t['code'],
                'default_low_stock_threshold' => $t['threshold'],
                'allow_threshold_override'    => 1,
                'is_active'                   => 1,
                'sort_order'                  => $maxSortOrder + 1,
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

        $targets = [
            ['old' => 'Laboratories',   'new' => 'Laboratory'],
            ['old' => 'Computer Lab',   'new' => 'Computer Laboratory'],
            ['old' => 'Chemistry Lab',  'new' => 'Chemistry Laboratory'],
            ['old' => 'Simulation Lab', 'new' => 'Simulation Laboratory'],
        ];

        foreach ($targets as $t) {
            $row = DB::table('inventory_categories')
                ->whereRaw('LOWER(name) = ?', [strtolower($t['new'])])
                ->first();

            if (!$row) {
                continue;
            }

            $hasItems = Schema::hasTable('items')
                && DB::table('items')->where('category_id', $row->id)->exists();

            if ($hasItems) {
                // A real item now depends on this category's current label —
                // leave it alone rather than risk renaming/removing live data.
                continue;
            }

            DB::table('inventory_categories')->where('id', $row->id)->update([
                'name'       => $t['old'],
                'updated_at' => now(),
            ]);
        }
    }
};
