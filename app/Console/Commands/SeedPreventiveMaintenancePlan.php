<?php

namespace App\Console\Commands;

use App\Models\PreventiveMaintenanceTask;
use App\Services\PreventiveMaintenanceService;
use Illuminate\Console\Command;

/**
 * One-time data load for the school's real Preventive Maintenance
 * Management Plan Manual (photographed source, not sample/demo data).
 *
 * The equipment, frequency, location, AND the Jan-Dec X-mark grid are all
 * transcribed here from the manual's schedule table (a second, clearer
 * photo confirmed the grid after the first pass had to leave it unset).
 * Every semi-annual (SA) row in the manual marks exactly April and
 * October; the one quarterly (Q) row — Electric Fans/Ceiling Fans — marks
 * January, April, July, and October. The two Monthly groups (ROOFTOP,
 * COMPUTERS) are marked every month. None of this is invented: it mirrors
 * the X marks actually printed in the manual's table.
 *
 * "Lourdes Building #1"/"#2" in the manual are linked to the real
 * building_id rows ("Lourdes Building 1"/"2") since they refer to the same
 * physical buildings, just formatted without the "#". All other manual
 * locations (Gymnasium, Boiler Room, offices, etc.) don't correspond to
 * existing Building/Room records, so they're stored as free-text
 * location_name instead of invented FK links.
 *
 * Idempotent: skips any (category, location_name/building_id) pair that
 * already exists so re-running never creates duplicates. Because of that,
 * this file being updated does NOT retroactively fix rows already seeded
 * in a live database — any already-running install needed a one-time
 * PreventiveMaintenanceService::updateTask() backfill (run once via
 * tinker, 2026-09-23) to set scheduled_months on the 15 SA/Q rows created
 * before the grid was legible, letting each task recompute its own
 * next_due_date from the new months.
 */
class SeedPreventiveMaintenancePlan extends Command
{
    protected $signature = 'pm:seed-manual-plan {--dry-run : Report what would be created without writing to the database}';

    protected $description = "Seeds preventive_maintenance_tasks from the school's Preventive Maintenance Management Plan Manual (equipment/frequency/location AND the confirmed Jan-Dec X-mark schedule for every row)";

    public function handle(PreventiveMaintenanceService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $allMonths = range(1, 12);

        $lourdesBuilding1 = (int) \DB::table('buildings')->where('name', 'Lourdes Building 1')->value('id');
        $lourdesBuilding2 = (int) \DB::table('buildings')->where('name', 'Lourdes Building 2')->value('id');

        $lb1 = fn () => ['building_id' => $lourdesBuilding1 ?: null, 'location_name' => $lourdesBuilding1 ? null : 'Lourdes Building #1'];
        $lb2 = fn () => ['building_id' => $lourdesBuilding2 ?: null, 'location_name' => $lourdesBuilding2 ? null : 'Lourdes Building #2'];
        $aprOct = [4, 10];

        // Transcribed row-by-row, top to bottom, from the manual's
        // PREVENTIVE MAINTENANCE TABLE (page 13), re-checked 2026-09-27
        // against the full-page photo. The order here IS the manual's order;
        // scheduleGrid() keeps it within each equipment group.
        $rows = [
            // M — every month
            ['category' => 'ROOFTOP', 'frequency' => 'monthly', 'location_name' => 'All Buildings', 'scheduled_months' => $allMonths],

            // SA — April + October
            ['category' => 'WATER PUMP (JET MATIC)', 'frequency' => 'semi_annually', 'scheduled_months' => $aprOct] + $lb1(),
            ['category' => 'WATER PUMP (JET MATIC)', 'frequency' => 'semi_annually', 'scheduled_months' => $aprOct] + $lb2(),

            ['category' => 'FIRE EXTINGUISHER', 'frequency' => 'semi_annually', 'location_name' => 'Gymnasium', 'scheduled_months' => $aprOct],
            ['category' => 'FIRE EXTINGUISHER', 'frequency' => 'semi_annually', 'location_name' => 'Offices', 'scheduled_months' => $aprOct],
            ['category' => 'FIRE EXTINGUISHER', 'frequency' => 'semi_annually', 'location_name' => 'Hallways', 'scheduled_months' => $aprOct],
            ['category' => 'FIRE EXTINGUISHER', 'frequency' => 'semi_annually', 'location_name' => 'Laboratory Rooms/Simulators', 'scheduled_months' => $aprOct],

            ['category' => 'FIRE ALARM SYSTEM', 'frequency' => 'semi_annually', 'location_name' => 'Hallways', 'scheduled_months' => $aprOct],

            // M — every month
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'Offices', 'scheduled_months' => $allMonths],
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'Laboratory Rooms/Simulator Rooms', 'scheduled_months' => $allMonths],
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'INTERNET', 'scheduled_months' => $allMonths],
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'Local Area Network (LAN)', 'scheduled_months' => $allMonths],
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'Wi-Fi System', 'scheduled_months' => $allMonths],
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'Printers', 'scheduled_months' => $allMonths],
            ['category' => 'COMPUTERS', 'frequency' => 'monthly', 'location_name' => 'LCD Monitors', 'scheduled_months' => $allMonths],

            // SA — April + October
            ['category' => 'BOILER', 'frequency' => 'semi_annually', 'location_name' => 'Boiler Room', 'scheduled_months' => $aprOct],
            ['category' => 'DIESEL ENGINE', 'frequency' => 'semi_annually', 'location_name' => 'Engine Room', 'scheduled_months' => $aprOct],
            ['category' => 'HYDRAULICS AND PNEUMATIC EQUIPMENT', 'frequency' => 'semi_annually', 'scheduled_months' => $aprOct] + $lb2(),
            ['category' => 'REFRIGERATION TRAINING MODULE', 'frequency' => 'semi_annually', 'scheduled_months' => $aprOct] + $lb2(),
            ['category' => 'PROCESS CONTROL EQUIPMENT', 'frequency' => 'semi_annually', 'scheduled_months' => $aprOct] + $lb2(),
            ['category' => 'LAB-VOLT (TRAINING MODULE FOR ELECTRO-TECHNOLOGY COURSE)', 'frequency' => 'semi_annually', 'scheduled_months' => $aprOct] + $lb2(),

            ['category' => 'AIR CONDITIONING UNIT (ACU)', 'frequency' => 'semi_annually', 'location_name' => 'Offices', 'scheduled_months' => $aprOct],
            ['category' => 'AIR CONDITIONING UNIT (ACU)', 'frequency' => 'semi_annually', 'location_name' => 'Classrooms', 'scheduled_months' => $aprOct],
            ['category' => 'AIR CONDITIONING UNIT (ACU)', 'frequency' => 'semi_annually', 'location_name' => 'Simulator Rooms', 'scheduled_months' => $aprOct],

            ['category' => 'GENERATOR', 'frequency' => 'semi_annually', 'location_name' => 'School Campus', 'scheduled_months' => $aprOct],

            // Q — January, April, July, October
            ['category' => 'ELECTRIC FANS/CEILING FANS', 'frequency' => 'quarterly', 'location_name' => 'Offices', 'scheduled_months' => [1, 4, 7, 10]],
            ['category' => 'ELECTRIC FANS/CEILING FANS', 'frequency' => 'quarterly', 'location_name' => 'Classrooms', 'scheduled_months' => [1, 4, 7, 10]],
            ['category' => 'ELECTRIC FANS/CEILING FANS', 'frequency' => 'quarterly', 'location_name' => 'Laboratory Rooms', 'scheduled_months' => [1, 4, 7, 10]],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $exists = PreventiveMaintenanceTask::query()
                ->where('category', $row['category'])
                ->where(function ($q) use ($row) {
                    if (!empty($row['building_id'])) {
                        $q->where('building_id', $row['building_id']);
                    } else {
                        $q->where('location_name', $row['location_name'] ?? null);
                    }
                })
                ->exists();

            if ($exists) {
                $skipped++;
                $this->line("SKIP (already exists): {$row['category']} @ " . ($row['location_name'] ?? "building_id={$row['building_id']}"));
                continue;
            }

            $this->line("CREATE: {$row['category']} @ " . ($row['location_name'] ?? "building_id={$row['building_id']}") . " [{$row['frequency']}]" . (isset($row['scheduled_months']) ? ' months=' . implode(',', $row['scheduled_months']) : ' months=unset'));

            if (!$dryRun) {
                $service->createTask([
                    'category' => $row['category'],
                    'title' => $row['category'],
                    'frequency' => $row['frequency'],
                    'building_id' => $row['building_id'] ?? null,
                    'location_name' => $row['location_name'] ?? null,
                    'scheduled_months' => $row['scheduled_months'] ?? null,
                    'is_active' => true,
                ]);
            }

            $created++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Preventive maintenance manual plan seed complete. Created: {$created}, Skipped (existing): {$skipped}");

        return self::SUCCESS;
    }
}
