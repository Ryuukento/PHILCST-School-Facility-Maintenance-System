<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * 2026-10-08 — General Report Duplicate Detection (user decision).
 *
 * BACKGROUND: the asset-picker's own duplicate check (checkAssetDuplicate() /
 * DamageReportService::findPotentialDuplicate(), see
 * ReportCreateAssetSeverityImageTest.php and DeployedAssetRegistryTest.php)
 * was the ONLY pre-submit duplicate warning the Create Report page ever had,
 * and it only ever covered the asset-linked sub-form, which was retired the
 * same day this feature was added. The general report path — the one every
 * reporter actually uses — never had any duplicate detection at all. This
 * suite covers the feature that fills that gap.
 *
 * MATCH RULE, BY EXPLICIT USER DECISION: same department, same calendar day,
 * exact (case-insensitive) title, exact derived location, exact problem
 * type. "Same items" was explicitly considered and dropped — the picker that
 * could have supplied it is gone, and the one remaining item-like field (the
 * optional Need Change search box) is unrelated to what a duplicate REPORT
 * is about.
 *
 * SCOPE, BY EXPLICIT USER DECISION: the whole department, not just the
 * current reporter's own submissions — two different staff members
 * reporting the same broken AC in the same room on the same day is exactly
 * the case this exists to catch.
 *
 * UX, BY EXPLICIT USER DECISION: informational only. POST /api/reports
 * itself is NEVER blocked by this — see
 * test_a_detected_duplicate_never_blocks_the_actual_report_submission().
 * The warning (with a "Submit Anyway" override) is purely a frontend
 * pre-flight call to the new POST /api/reports/check-duplicate endpoint,
 * exercised here directly since the modal itself is plain JS with no PHP
 * server round-trip to assert against.
 *
 * See ReportService::findPotentialDuplicate()'s doc comment for the
 * implementation-level rationale.
 */
class ReportDuplicateDetectionTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_duplicate_detection_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // =================================================================
    // 1. The core rule: all four signals must match.
    // =================================================================

    public function test_same_title_location_problem_type_and_department_today_is_flagged_as_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId, 'full_name' => 'Juan Dela Cruz']);
        $checkerId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($reporterId, $deptId, [
            'title' => 'Broken Aircon',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'HVAC / Aircon',
        ]);

        $response = $this
            ->actingAsSessionUser($checkerId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', true);
        $response->assertJsonPath('data.duplicate.reported_by', 'Juan Dela Cruz');
    }

    public function test_a_different_title_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($reporterId, $deptId, [
            'title' => 'Leaking faucet',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'HVAC / Aircon',
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_a_different_location_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($reporterId, $deptId, [
            'title' => 'Broken Aircon',
            // Room 201 (building 2), not Room 102 — the payload below asks
            // about Room 102.
            'location' => 'Building 2 / 1st Floor / Room 201',
            'problem_type' => 'HVAC / Aircon',
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_a_different_problem_type_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($reporterId, $deptId, [
            'title' => 'Broken Aircon',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'Electrical',
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_a_different_department_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptA = $this->seedDepartment();
        $deptB = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptA]);

        $this->seedReport($reporterId, $deptA, [
            'title' => 'Broken Aircon',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'HVAC / Aircon',
        ]);

        // Same title/location/problem_type, but routed to department B.
        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptB, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_a_report_from_a_previous_day_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($reporterId, $deptId, [
            'title' => 'Broken Aircon',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'HVAC / Aircon',
            'created_at' => now()->subDay(),
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_title_matching_is_case_insensitive(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($reporterId, $deptId, [
            'title' => 'BROKEN AIRCON',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'HVAC / Aircon',
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture, [
                'title' => 'broken aircon',
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', true);
    }

    // =================================================================
    // 1b. Asset Code signal (2026-10-08, user decision). Checked FIRST,
    //     mirrors DamageReportService::findPotentialDuplicate()'s pattern:
    //     same registered deployed_asset_id matches regardless of wording,
    //     scoped to reports that are not yet finished.
    // =================================================================

    public function test_same_deployed_asset_id_is_flagged_as_duplicate_even_with_a_different_title_and_location(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId, 'full_name' => 'Juan Dela Cruz']);
        $assetId = $this->seedDeployedAsset();

        $this->seedReport($reporterId, $deptId, [
            'title' => 'Completely different title',
            'location' => 'Somewhere else entirely',
            'problem_type' => 'Electrical',
            'deployed_asset_id' => $assetId,
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture, [
                'title' => 'A totally unrelated title',
                'deployed_asset_id' => $assetId,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', true);
        $response->assertJsonPath('data.duplicate.reported_by', 'Juan Dela Cruz');
    }

    public function test_a_different_deployed_asset_id_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $assetA = $this->seedDeployedAsset();
        $assetB = $this->seedDeployedAsset();

        $this->seedReport($reporterId, $deptId, [
            'deployed_asset_id' => $assetA,
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture, [
                'deployed_asset_id' => $assetB,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_a_finished_report_for_the_same_asset_is_not_a_duplicate(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $assetId = $this->seedDeployedAsset();

        // ReportArchiveService::FINAL_STATUSES — a finished report about
        // this unit is history, not a conflicting duplicate.
        $this->seedReport($reporterId, $deptId, [
            'deployed_asset_id' => $assetId,
            'status' => 'completed',
        ]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture, [
                'deployed_asset_id' => $assetId,
            ]));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    public function test_an_unregistered_deployed_asset_id_is_rejected_at_the_duplicate_check_boundary(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture, [
                // No deployed_assets row has this id.
                'deployed_asset_id' => 999999,
            ]));

        $response->assertStatus(422);
    }

    public function test_store_persists_the_optional_asset_code_and_show_surfaces_it_for_display(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $assetId = $this->seedDeployedAsset(['asset_code' => 'SFMS-2026-000123']);

        $createResponse = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, $fixture, [
                'deployed_asset_id' => $assetId,
            ]));

        $createResponse->assertStatus(201);
        $reportId = $createResponse->json('data.report_id');

        $this->assertSame($assetId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('deployed_asset_id'));

        $showResponse = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->getJson('/api/reports/' . $reportId);

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.report.deployed_asset_id', $assetId);
        $showResponse->assertJsonPath('data.report.asset_code', 'SFMS-2026-000123');
    }

    // =================================================================
    // 2. Scope — by explicit user decision, matched across the WHOLE
    //    department, not just the current reporter's own reports.
    // =================================================================

    public function test_matches_across_different_reporters_in_the_same_department(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $firstReporter = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $secondReporter = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this->seedReport($firstReporter, $deptId, [
            'title' => 'Broken Aircon',
            'location' => 'Building 1 / 2nd Floor / Room 102',
            'problem_type' => 'HVAC / Aircon',
        ]);

        // A DIFFERENT staff member, about to submit the same thing.
        $response = $this
            ->actingAsSessionUser($secondReporter, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', true);
    }

    // =================================================================
    // 3. Informational only — the actual creation endpoint is never
    //    gated by this, by explicit user decision.
    // =================================================================

    public function test_a_detected_duplicate_never_blocks_the_actual_report_submission(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // First submission.
        $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, $fixture, [
                'title' => 'Broken Aircon',
                'problem_type' => 'HVAC / Aircon',
            ]))
            ->assertStatus(201);

        // Confirm the endpoint would now flag a repeat as a duplicate...
        $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture))
            ->assertJsonPath('data.has_duplicate', true);

        // ...but the real creation endpoint still accepts the resubmission —
        // "Submit Anyway" has nothing to override on the backend because
        // there was never a backend gate to begin with.
        $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, $fixture, [
                'title' => 'Broken Aircon',
                'problem_type' => 'HVAC / Aircon',
            ]))
            ->assertStatus(201);

        $this->assertSame(2, DB::table('maintenance_reports')->count());
    }

    // =================================================================
    // 4. Role gate — same roles as store() itself, nothing new invented.
    // =================================================================

    public function test_a_role_that_cannot_create_reports_cannot_check_for_duplicates_either(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $superAdminId = $this->seedUser(['role' => 'super_admin', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($superAdminId, 'super_admin')
            ->postJson('/api/reports/check-duplicate', $this->duplicateCheckPayload($deptId, $fixture));

        $response->assertStatus(403);
    }

    // =================================================================
    // 5. Location resolution reuses store()'s own rule — an invalid
    //    triple fails the exact same way it would on the real submission.
    // =================================================================

    public function test_an_invalid_location_triple_is_rejected_the_same_way_store_would_reject_it(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', [
                'title' => 'Broken Aircon',
                'problem_type' => 'HVAC / Aircon',
                // Room 102 belongs to building_1, not building_2.
                'location_building_id' => $fixture['building_2'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
                'department_id' => $deptId,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    public function test_omitting_the_location_triple_entirely_returns_no_duplicate_rather_than_an_error(): void
    {
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports/check-duplicate', [
                'title' => 'Broken Aircon',
                'problem_type' => 'HVAC / Aircon',
                'department_id' => $deptId,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function seedBuildingsOverview(): array
    {
        $building1 = $this->seedBuilding('Building 1');
        $building2 = $this->seedBuilding('Building 2');

        $b1Floor2 = $this->seedFloor($building1, '2nd Floor');
        $b2Floor1 = $this->seedFloor($building2, '1st Floor');

        return [
            'building_1' => $building1,
            'building_2' => $building2,
            'b1_floor_2' => $b1Floor2,
            'b2_floor_1' => $b2Floor1,
            'room_102' => $this->seedRoomRow($building1, $b1Floor2, 'Room 102'),
            'room_201' => $this->seedRoomRow($building2, $b2Floor1, 'Room 201'),
        ];
    }

    private function seedBuilding(string $name): int
    {
        return DB::table('buildings')->insertGetId([
            'name' => $name,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFloor(int $buildingId, string $name): int
    {
        return DB::table('floors')->insertGetId([
            'building_id' => $buildingId,
            'name' => $name,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRoomRow(int $buildingId, int $floorId, string $name): int
    {
        return DB::table('rooms')->insertGetId([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'name' => $name,
            'capacity' => 30,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid('', true),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    /** Inserts a maintenance_reports row directly — this suite is about reading existing rows back, not about how they got created. */
    private function seedReport(int $createdBy, int $departmentId, array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Existing report',
            'description' => 'Already filed before this change.',
            'problem_type' => 'Electrical',
            'location' => 'Unspecified',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $createdBy,
            'department_id' => $departmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }

    /** The body POST /api/reports/check-duplicate expects, built from a Room 102 selection by default. */
    private function duplicateCheckPayload(int $departmentId, array $fixture, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Broken Aircon',
            'problem_type' => 'HVAC / Aircon',
            'location_building_id' => $fixture['building_1'],
            'location_floor_id' => $fixture['b1_floor_2'],
            'location_room_id' => $fixture['room_102'],
            'department_id' => $departmentId,
        ], $overrides);
    }

    /** The minimum valid POST /api/reports body, aimed at the same Room 102 the duplicate-check fixtures use. */
    private function reportPayload(int $departmentId, array $fixture, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Broken Aircon',
            'description' => 'The aircon will not turn on.',
            'problem_type' => 'HVAC / Aircon',
            'location_building_id' => $fixture['building_1'],
            'location_floor_id' => $fixture['b1_floor_2'],
            'location_room_id' => $fixture['room_102'],
            'priority' => 'medium',
            'department_id' => $departmentId,
        ], $overrides);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createBuildingsTable();
        $this->createFloorsTable();
        $this->createRoomsTableWithBuildingFloor();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->addMaintenanceReportAssetColumns();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();
        // 2026-10-08 — Asset Code signal. deployed_assets must exist so
        // 'exists:deployed_assets,id' (ReportController::store()/
        // checkDuplicate()) and the asset-linked branch of
        // ReportService::findPotentialDuplicate() have a real table to
        // check against.
        $this->createDeployedAssetsTable();

        Schema::enableForeignKeyConstraints();
    }

    /** A registered deployed_assets row — item_id is required by the real schema, so an item is seeded alongside it. */
    private function seedDeployedAsset(array $overrides = []): int
    {
        $itemId = $this->seedItem();

        return DB::table('deployed_assets')->insertGetId(array_merge([
            'asset_code' => 'SFMS-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'code_source' => 'generated',
            'item_id' => $itemId,
            'status' => 'active',
            'deployed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createBuildingsTable(): void
    {
        Schema::create('buildings', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    private function createFloorsTable(): void
    {
        Schema::create('floors', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The shared BuildsSharedTestSchema::createRoomsTable() already has
     * building_id/floor_id columns, but is named/used here under a distinct
     * method name so this file's intent (mirroring
     * ReportLocationValidationTest's building/floor/room resolution) is
     * explicit rather than relying on the trait's dispatch-oriented doc
     * comment.
     */
    private function createRoomsTableWithBuildingFloor(): void
    {
        $this->createRoomsTable();
        Schema::table('rooms', function ($table): void {
            $table->boolean('is_active')->default(true);
        });
    }

    private function addMaintenanceReportAssetColumns(): void
    {
        Schema::table('maintenance_reports', function ($table): void {
            $table->string('report_category', 30)->default('general')->after('description');
            $table->unsignedInteger('item_id')->nullable()->after('report_category');
            $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
        });
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function createDamageReportsTable(): void
    {
        Schema::create('damage_reports', function ($table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDamageReportHistoriesTable(): void
    {
        Schema::create('damage_report_histories', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
