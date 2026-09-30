<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK 39 — Replace sample/demo Buildings Overview data with the actual
 * PHILCST building/floor/room/office/facility structure, per the
 * user-approved affected-record summary (2026-08-10).
 *
 * This migration does three things, all idempotent (safe to run twice):
 *
 *  1. Adds rooms.is_active (default true) so obsolete/sample rooms can be
 *     hidden from active-room selectors WITHOUT hard-deleting the row —
 *     preserving history for dispatches that already reference them
 *     (explicit user requirement: "historical data preservation is more
 *     important than physical cleanup").
 *  2. Reconciles Lourdes Building I's existing rows in place: fixes
 *     floor-name typos, fixes room/office case/typos on rows that already
 *     match an authoritative entry (same id, same building/floor — reused,
 *     not duplicated), and deactivates (never deletes) the 8 rows that are
 *     confirmed sample/junk data with no authoritative match.
 *  3. Inserts the new authoritative floors/rooms for Lourdes Building I
 *     (missing 1st/2nd/3rd-floor entries), renames Building II (id 15) to
 *     "Lourdes Buildings 2 & 3" and populates its floors/rooms, and
 *     populates Buildings V/VI/VII.
 *
 * Explicitly NOT touched, per direct instruction: Lourdes Building III
 * (id 14, left as an empty, unused shell — no authoritative data exists
 * for it and it must not be treated as absorbed/renamed/populated),
 * Lourdes Building IV (id 13), Lourdes Building VIII (id 9).
 *
 * No row in the `rooms` or `buildings` tables is ever deleted by this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rooms', 'is_active')) {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('capacity');
            });
        }

        $this->reconcileBuildingI();
        $this->renameAndPopulateBuildings2And3();
        $this->populateBuildingV();
        $this->populateBuildingVI();
        $this->populateBuildingVII();
        $this->deactivateSampleRooms();
    }

    public function down(): void
    {
        // Additive/data-reconciliation migration — intentionally no destructive
        // rollback of renamed/inserted location data (would risk deleting rows
        // that may since be referenced by real records). Only the schema
        // addition is reversed.
        if (Schema::hasColumn('rooms', 'is_active')) {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->dropColumn('is_active');
            });
        }
    }

    // -----------------------------------------------------------------------
    // Lourdes Building I (id 16) — fix existing rows in place, add missing
    // authoritative rows. Existing floor/room ids are reused, never duplicated.
    // -----------------------------------------------------------------------
    private function reconcileBuildingI(): void
    {
        $buildingId = 16;
        if (!DB::table('buildings')->where('id', $buildingId)->exists()) {
            return;
        }

        // --- Floor name corrections (guarded by id, no-op if already fixed) ---
        // No `where('name', '!=', ...)` guard here: under MySQL's default
        // case-insensitive collation that comparison treats "2nd" and
        // "2ND"/"2nd Floor" as equal for case-only differences, silently
        // skipping the update. These are static, hardcoded corrections, so
        // unconditionally writing them is already idempotent (same value
        // written on every run; no duplicate rows are ever created).
        $floorRenames = [
            2 => '2nd Floor',
            3 => '3rd Floor',
            4 => '4th Floor',
        ];
        foreach ($floorRenames as $floorId => $newName) {
            DB::table('floors')
                ->where('id', $floorId)
                ->where('building_id', $buildingId)
                ->update(['name' => $newName, 'updated_at' => now()]);
        }

        $floor1 = DB::table('floors')->where('id', 1)->where('building_id', $buildingId)->value('id');
        $floor2 = DB::table('floors')->where('id', 2)->where('building_id', $buildingId)->value('id');
        $floor3 = DB::table('floors')->where('id', 3)->where('building_id', $buildingId)->value('id');
        $floor4 = DB::table('floors')->where('id', 4)->where('building_id', $buildingId)->value('id');

        // --- Room/office case & typo corrections on existing 3rd/4th-floor rows
        //     that already match an authoritative entry (id reused, not new). ---
        // Same collation trap as the floor renames above (see comment there):
        // no `where('name', '!=', ...)` guard, since MySQL's default
        // case-insensitive collation would silently skip case-only fixes
        // (e.g. "ROOM 301" -> "Room 301"). Static hardcoded values, so
        // unconditional writes stay idempotent.
        $roomRenames = [
            8  => 'Office of the Vice President for Administration',
            9  => 'Office of the Vice President for Academic Affairs',
            10 => 'College of Criminal Justice Education Faculty Room',
            12 => "Dean's Office – Criminal Justice Education",
            13 => 'Office of the Guidance and Counseling',
            14 => 'Incubation and Faculty Room',
            15 => 'Shipboard Training Office',
            16 => 'Room 301',
            17 => 'Room 302',
            18 => 'Room 303',
            19 => 'Room 305',
            20 => 'Library',
            21 => 'Chapel',
        ];
        foreach ($roomRenames as $roomId => $newName) {
            DB::table('rooms')
                ->where('id', $roomId)
                ->where('building_id', $buildingId)
                ->update(['name' => $newName, 'updated_at' => now()]);
        }

        // --- Missing authoritative rows to add ---
        if ($floor1) {
            $this->insertRoomsIfMissing($buildingId, $floor1, [
                'Room 101', 'Room 102', 'Room 103', 'Room 104',
                'Office of the President', 'Accounting Office',
                "Registrar's Office", 'School Clinic',
            ]);
        }

        if ($floor2) {
            $this->insertRoomsIfMissing($buildingId, $floor2, [
                'Room 201', 'Room 202', 'Room 203', 'Room 204', 'Room 205', 'Room 206',
                'Office of the Vice President for Institutional Advancement',
                'Office of the Assistant Principal',
                'Office of the Research and Graduate Studies',
                'College of Computer Studies',
                'Senior High School Faculty Room',
                'Management Information System',
            ]);
        }

        if ($floor3) {
            // "Education Faculty Room" and "Education Incubation Room" are
            // distinct authoritative entries from the existing (mismatched,
            // now-deactivated) id 11 "College of Criminal Justice Education
            // Incubation Room" — see deactivateSampleRooms().
            $this->insertRoomsIfMissing($buildingId, $floor3, [
                'Education Faculty Room',
                'Education Incubation Room',
            ]);
        }

        // 4th floor (Library/Chapel) already fully covered by the rename above.
        unset($floor4);
    }

    // -----------------------------------------------------------------------
    // Lourdes Building II (id 15) -> "Lourdes Buildings 2 & 3". Currently
    // empty (0 floors/0 rooms), so this is a pure rename + populate.
    // Lourdes Building III (id 14) is intentionally left completely untouched.
    // -----------------------------------------------------------------------
    private function renameAndPopulateBuildings2And3(): void
    {
        $buildingId = 15;
        $newName = 'Lourdes Buildings 2 & 3';

        if (!DB::table('buildings')->where('id', $buildingId)->exists()) {
            return;
        }

        DB::table('buildings')
            ->where('id', $buildingId)
            ->where('name', '!=', $newName)
            ->update(['name' => $newName, 'updated_at' => now()]);

        $floor1 = $this->insertFloorIfMissing($buildingId, '1st Floor');
        $floor2 = $this->insertFloorIfMissing($buildingId, '2nd Floor');

        $this->insertRoomsIfMissing($buildingId, $floor1, [
            'GMD Simulator', 'Room 101', 'GSD',
            'Room 106', 'Room 107', 'Room 108', 'Room 109', 'Room 110',
        ]);

        $this->insertRoomsIfMissing($buildingId, $floor2, [
            'Radar Simulation Room',
            "College of Maritime Studies Dean's Office / Faculty Room",
            'Room 211', 'Room 212', 'Room 213', 'Room 209', 'Room 205', 'Room 206',
            'College of Engineering and Architecture',
        ]);
    }

    // -----------------------------------------------------------------------
    // Lourdes Building V (id 12) — currently empty, pure additions.
    // -----------------------------------------------------------------------
    private function populateBuildingV(): void
    {
        $buildingId = 12;
        if (!DB::table('buildings')->where('id', $buildingId)->exists()) {
            return;
        }

        $floor1 = $this->insertFloorIfMissing($buildingId, '1st Floor');
        $floor2 = $this->insertFloorIfMissing($buildingId, '2nd Floor');
        $floor3 = $this->insertFloorIfMissing($buildingId, '3rd Floor');

        $this->insertRoomsIfMissing($buildingId, $floor1, [
            'Office of the Executive Vice President',
            'Chemistry Room',
            'Chemistry Apparatus Stock Room',
            'TLE',
            'Fire Range',
            'School Canteen',
        ]);

        $this->insertRoomsIfMissing($buildingId, $floor2, [
            'L201', 'L202', 'L203', 'L204', 'L205', 'L206', 'L207', 'L208',
        ]);

        $this->insertRoomsIfMissing($buildingId, $floor3, [
            'L301', 'L302', 'L303', 'L304', 'L305', 'L306', 'L307', 'L308', 'L309',
        ]);
    }

    // -----------------------------------------------------------------------
    // Lourdes Building VI (id 11) — currently empty, pure additions.
    // -----------------------------------------------------------------------
    private function populateBuildingVI(): void
    {
        $buildingId = 11;
        if (!DB::table('buildings')->where('id', $buildingId)->exists()) {
            return;
        }

        $floor1 = $this->insertFloorIfMissing($buildingId, '1st Floor');
        $floor2 = $this->insertFloorIfMissing($buildingId, '2nd Floor');
        $floor3 = $this->insertFloorIfMissing($buildingId, '3rd Floor');
        $floor4 = $this->insertFloorIfMissing($buildingId, '4th Floor');

        $this->insertRoomsIfMissing($buildingId, $floor1, [
            'N101', 'N102', 'N103', 'ECL Welding Lab', 'Electrical Welding Lab',
            'N107', 'N108', 'N109',
        ]);

        $this->insertRoomsIfMissing($buildingId, $floor2, [
            'N201', 'N202', 'N203', 'N204', 'N205', 'N206', 'N207', 'N208', 'N209', 'N210',
        ]);

        $this->insertRoomsIfMissing($buildingId, $floor3, [
            'N301', 'N302', 'N303', 'N304', 'N305', 'N306', 'N307', 'N308', 'N309',
        ]);

        $this->insertRoomsIfMissing($buildingId, $floor4, [
            'N401', 'N402', 'N403', 'N404', 'N405', 'N406', 'N407', 'N408',
        ]);
    }

    // -----------------------------------------------------------------------
    // Lourdes Building VII (id 10) — currently empty, pure additions.
    // -----------------------------------------------------------------------
    private function populateBuildingVII(): void
    {
        $buildingId = 10;
        if (!DB::table('buildings')->where('id', $buildingId)->exists()) {
            return;
        }

        $floor1 = $this->insertFloorIfMissing($buildingId, '1st Floor');
        $floor2 = $this->insertFloorIfMissing($buildingId, '2nd Floor');
        $floor3 = $this->insertFloorIfMissing($buildingId, '3rd Floor');

        $this->insertRoomsIfMissing($buildingId, $floor1, ['M101', 'M102', 'M103']);
        $this->insertRoomsIfMissing($buildingId, $floor2, ['M201', 'M202', 'M203']);
        $this->insertRoomsIfMissing($buildingId, $floor3, ['M301', 'M302', 'M303']);
    }

    // -----------------------------------------------------------------------
    // Deactivate (never delete) the 8 confirmed sample/junk rooms under
    // Lourdes Building I that have no authoritative match. Guarded by exact
    // id + current-name match so this is a no-op if already deactivated or
    // if the row was since legitimately renamed by an admin.
    // -----------------------------------------------------------------------
    private function deactivateSampleRooms(): void
    {
        $targets = [
            1  => 'Storage Room A',
            2  => 'Science Lab 101',
            3  => 'HAHAHA ROOMS',
            4  => 'lab 102',
            5  => 'lab 103',
            6  => 'lab 104',
            7  => 'lab 105',
            11 => 'COLLEGE OF CRIMINAL JUSTICE EDUCATION INCUBATION ROOM',
        ];

        foreach ($targets as $roomId => $currentName) {
            DB::table('rooms')
                ->where('id', $roomId)
                ->where('name', $currentName)
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers — idempotent insert-if-missing for floors/rooms, matching the
    // guarded-exists() convention already used by
    // create_inventory_rooms_table.php's seedDefaultInventoryRooms().
    // -----------------------------------------------------------------------
    private function insertFloorIfMissing(int $buildingId, string $name): int
    {
        $existing = DB::table('floors')
            ->where('building_id', $buildingId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('floors')->insertGetId([
            'building_id' => $buildingId,
            'name' => $name,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param string[] $names */
    private function insertRoomsIfMissing(int $buildingId, ?int $floorId, array $names): void
    {
        if (!$floorId) {
            return;
        }

        foreach ($names as $name) {
            $exists = DB::table('rooms')
                ->where('floor_id', $floorId)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('rooms')->insert([
                'building_id' => $buildingId,
                'floor_id' => $floorId,
                'name' => $name,
                'capacity' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
