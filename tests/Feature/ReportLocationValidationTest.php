<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 38 — Create Report location validation against Buildings Overview.
 *
 * THE DEFECT: maintenance_reports.location was a free-text varchar and
 * ReportController::store() validated it only as
 * ['nullable','string','max:255']. A report could therefore name a Building /
 * Floor / Room that does not exist, and the system accepted it silently. The
 * live data proved it: every existing report had an unparseable location
 * ('103', 'Probe Location', 'Lourdes V 4 floor room 102').
 *
 * WHAT THIS SUITE PINS DOWN: when the caller supplies the structured
 * location_building_id / location_floor_id / location_room_id triple, the
 * backend resolves it against the same buildings/floors/rooms tables that
 * back Buildings Overview, rejects anything that does not describe a real,
 * active, correctly-registered room, and derives the stored location string
 * itself rather than trusting the client's.
 *
 * The bypass tests matter most: these all POST straight to /api/reports with
 * no browser involved, which is exactly the "manually crafted request" the
 * task requires to be blocked. The frontend picker is convenience only.
 *
 * BACKWARD COMPATIBILITY IS ALSO PINNED DOWN HERE (see the "legacy" tests at
 * the end): omitting the triple entirely still works, because location is
 * nullable in the real schema for the internal creators (dispatch / PM /
 * asset-damage flows) that never set it. Making the triple unconditionally
 * mandatory would have been a NEW business rule, which this task must not
 * invent.
 */
class ReportLocationValidationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_location_validation_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // =================================================================
    // 1. The happy path — a real room is accepted and the stored
    //    location string is built from the database.
    // =================================================================

    public function test_a_room_registered_under_the_selected_building_and_floor_is_accepted(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ]));

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $report = DB::table('maintenance_reports')->first();
        $this->assertNotNull($report);
        // Derived server-side from buildings.name / floors.name / rooms.name,
        // joined with the ' / ' convention the report detail view already uses.
        $this->assertSame('Building 1 / 2nd Floor / Room 102', $report->location);
    }

    // =================================================================
    // 2. The anti-spoofing guarantee — a caller cannot dictate the
    //    location string alongside a valid triple.
    // =================================================================

    public function test_a_client_supplied_location_string_is_replaced_by_the_derived_one(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                // A hand-crafted request naming a room in a building it did
                // not select, hoping the free-text field is stored verbatim.
                'location' => 'Building 9 / Penthouse / Room 999',
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ]))
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame('Building 1 / 2nd Floor / Room 102', $report->location);
        $this->assertStringNotContainsString('999', (string) $report->location);
    }

    // =================================================================
    // 3. Rejections — each is a distinct way of naming a room that does
    //    not exist where the request claims it does.
    // =================================================================

    public function test_a_room_id_that_does_not_exist_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => 999999,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_room_id']);

        $this->assertNoReportWasCreated();
    }

    public function test_a_deactivated_room_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // Deactivated rooms are retained forever so historical records still
        // resolve, but the room picker never offers them — accepting one via
        // a crafted request would be a bypass of that same rule.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_1'],
                'location_room_id' => $fixture['room_101_inactive'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_room_id']);

        $this->assertNoReportWasCreated();
    }

    public function test_a_real_room_claimed_under_the_wrong_building_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // Room 102 is real, and Building 2 is real — but Room 102 is not in
        // Building 2. This is the exact scenario the task describes.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => $fixture['building_2'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_room_id']);

        $this->assertNoReportWasCreated();
    }

    public function test_a_real_room_claimed_on_the_wrong_floor_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // Right building, wrong floor. Worth its own case because
        // rooms.building_id and rooms.floor_id are independent columns, so
        // checking the building alone would let this through.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_1'],
                'location_room_id' => $fixture['room_102'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_room_id']);

        $this->assertNoReportWasCreated();
    }

    public function test_a_floor_belonging_to_another_building_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // The inconsistent-data guard. rooms.building_id and rooms.floor_id
        // have separate foreign keys and nothing at the DB level forces the
        // floor to belong to the building, so this row can exist: a room
        // whose own two columns disagree. Both of the room's columns match
        // what the request claims, yet the claim is still incoherent.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b2_floor_1'],
                'location_room_id' => $fixture['room_mismatched'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_floor_id']);

        $this->assertNoReportWasCreated();
    }

    public function test_a_building_id_that_does_not_exist_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location_building_id' => 999999,
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_building_id']);

        $this->assertNoReportWasCreated();
    }

    /**
     * A partial triple must not be "best-effort" resolved: a room id on its
     * own would skip the building/floor agreement check entirely, which is
     * the whole point of this validation.
     *
     * @dataProvider partialLocationTripleProvider
     */
    public function test_a_partial_location_triple_is_rejected(array $keysToSend, array $expectedErrors): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $available = [
            'location_building_id' => $fixture['building_1'],
            'location_floor_id' => $fixture['b1_floor_2'],
            'location_room_id' => $fixture['room_102'],
        ];

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload(
                $deptId,
                array_intersect_key($available, array_flip($keysToSend))
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors($expectedErrors);

        $this->assertNoReportWasCreated();
    }

    public static function partialLocationTripleProvider(): array
    {
        return [
            'room only' => [
                ['location_room_id'],
                ['location_building_id', 'location_floor_id'],
            ],
            'building only' => [
                ['location_building_id'],
                ['location_floor_id', 'location_room_id'],
            ],
            'building and floor without a room' => [
                ['location_building_id', 'location_floor_id'],
                ['location_room_id'],
            ],
        ];
    }

    // =================================================================
    // 4. The asset-linked branch gets the same treatment.
    // =================================================================

    public function test_an_asset_linked_report_also_stores_the_derived_location(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $fixture['room_102']]);

        // room_id (the asset's room) and location_room_id (where the issue
        // is) are deliberately separate parameters — room_id + item_id is
        // what routes this request to storeWithAssetDetails(), so reusing it
        // for the location would have changed which branch runs.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'item_id' => $itemId,
                'room_id' => $fixture['room_102'],
                'severity_level' => 'high',
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ]))
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame('Building 1 / 2nd Floor / Room 102', $report->location);
        // The asset branch itself is untouched — it still created its
        // damage_reports row exactly as before.
        $this->assertSame(1, DB::table('damage_reports')->count());
    }

    public function test_an_asset_linked_report_with_an_invalid_location_is_rejected_before_any_damage_row_is_written(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $fixture['room_102']]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'item_id' => $itemId,
                'room_id' => $fixture['room_102'],
                'severity_level' => 'high',
                'location_building_id' => $fixture['building_2'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ]))
            ->assertStatus(422);

        $this->assertNoReportWasCreated();
        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // =================================================================
    // 5. Editing is held to the same standard as creating.
    // =================================================================

    public function test_updating_a_report_with_an_invalid_location_triple_is_rejected(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        // Head Maintenance, because editing is gated by the pre-existing
        // canModifyReport() policy (staff may only edit reports assigned to
        // them). That policy is untouched by this task — see the RBAC test
        // above — so the editor here just needs to satisfy it.
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, 'Building 1 / 2nd Floor / Room 102');

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, [
                'location_building_id' => $fixture['building_2'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ])
            ->assertStatus(422);

        // Otherwise a report could be created with a verified location and
        // then quietly edited into a room that does not exist.
        $this->assertSame(
            'Building 1 / 2nd Floor / Room 102',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('location')
        );
    }

    public function test_updating_a_report_with_a_valid_location_triple_stores_the_derived_string(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, 'somewhere vague');

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, [
                'location' => 'a string the caller made up',
                'location_building_id' => $fixture['building_2'],
                'location_floor_id' => $fixture['b2_floor_1'],
                'location_room_id' => $fixture['room_201'],
            ])
            ->assertStatus(200);

        $this->assertSame(
            'Building 2 / 1st Floor / Room 201',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('location')
        );
    }

    // =================================================================
    // 6. Nothing that worked before stops working.
    // =================================================================

    public function test_a_report_with_a_plain_location_string_and_no_triple_is_still_accepted(): void
    {
        $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // The pre-existing contract, still honoured verbatim. Many existing
        // API callers and tests post exactly this shape.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'location' => 'Room 204',
            ]))
            ->assertStatus(201);

        $this->assertSame('Room 204', DB::table('maintenance_reports')->first()->location);
    }

    public function test_a_report_with_no_location_at_all_is_still_accepted(): void
    {
        $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // location is nullable in the real schema because the internal
        // creators (dispatch / PM / asset-damage) never populate it. That is
        // an existing rule, not one this task gets to change.
        $payload = $this->reportPayload($deptId);
        unset($payload['location']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $payload)
            ->assertStatus(201);

        $this->assertNull(DB::table('maintenance_reports')->first()->location);
    }

    public function test_the_other_report_fields_are_unaffected_by_location_validation(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Flickering lights',
                'description' => 'Two tubes flicker constantly.',
                'priority' => 'critical',
                'department_id' => $deptId,
                'location_building_id' => $fixture['building_1'],
                'location_floor_id' => $fixture['b1_floor_2'],
                'location_room_id' => $fixture['room_102'],
            ])
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame('Flickering lights', $report->title);
        $this->assertSame('Two tubes flicker constantly.', $report->description);
        $this->assertSame('critical', $report->priority);
        $this->assertSame($deptId, (int) $report->department_id);
        $this->assertSame($staffId, (int) $report->created_by);
        // Creation still enters the workflow at the same point it always did.
        $this->assertSame('submitted', $report->status);
    }

    public function test_role_permissions_for_report_creation_are_unchanged(): void
    {
        $fixture = $this->seedBuildingsOverview();
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);

        $validTriple = [
            'location_building_id' => $fixture['building_1'],
            'location_floor_id' => $fixture['b1_floor_2'],
            'location_room_id' => $fixture['room_102'],
        ];

        // Administrator still cannot submit reports (RBAC POLICY UPDATE in
        // routes/web.php), and a perfectly valid location does not change that.
        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/reports', $this->reportPayload($deptId, $validTriple))
            ->assertStatus(403);

        // Head Maintenance still can.
        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/reports', $this->reportPayload($deptId, $validTriple))
            ->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    // =================================================================
    // 7. Frontend contract. The picker is convenience, but it must
    //    actually be a picker — a free-text box would put us straight
    //    back where we started.
    // =================================================================

    public function test_the_create_report_page_uses_dependent_selects_instead_of_a_free_text_location(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/create-report.php'));

        $this->assertStringNotContainsString(
            '<input
                                    type="text"
                                    id="location"',
            $markup,
            'The free-text location input must be gone, not merely hidden.'
        );

        foreach (['location-building', 'location-floor', 'location-room'] as $id) {
            $this->assertStringContainsString('<select id="' . $id . '"', $markup);
        }

        // Floor and Room start disabled so the cascade cannot be skipped by
        // simply not touching the earlier selects.
        $this->assertStringContainsString('name="location_floor_id" required disabled', $markup);
        $this->assertStringContainsString('name="location_room_id" required disabled', $markup);
    }

    public function test_the_create_report_page_reuses_the_existing_buildings_overview_endpoints(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/create-report.php'));

        $this->assertStringContainsString("'/api/buildings?per_page=200'", $markup);
        $this->assertStringContainsString("'/api/buildings/' + encodeURIComponent(buildingId) + '/floors'", $markup);
        $this->assertStringContainsString("'/api/rooms?per_page=200&building_id='", $markup);
        // The room list is narrowed by floor too, so what the picker offers
        // is exactly what the backend will accept.
        $this->assertStringContainsString("'&floor_id=' + encodeURIComponent(floorId)", $markup);
    }

    public function test_the_create_report_page_does_not_use_a_browser_alert_for_location_errors(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/create-report.php'));

        // The page's own inline alert container is the established idiom here.
        $this->assertStringContainsString('alert alert-danger', $markup);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])alert\s*\(/', $markup);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * A miniature Buildings Overview. Deliberately includes the awkward rows
     * the validation has to cope with: a deactivated room, and a room whose
     * own building_id/floor_id disagree (possible because those are two
     * independent foreign keys).
     *
     * @return array<string, int>
     */
    private function seedBuildingsOverview(): array
    {
        $building1 = $this->seedBuilding('Building 1');
        $building2 = $this->seedBuilding('Building 2');

        $b1Floor1 = $this->seedFloor($building1, '1st Floor');
        $b1Floor2 = $this->seedFloor($building1, '2nd Floor');
        $b2Floor1 = $this->seedFloor($building2, '1st Floor');

        return [
            'building_1' => $building1,
            'building_2' => $building2,
            'b1_floor_1' => $b1Floor1,
            'b1_floor_2' => $b1Floor2,
            'b2_floor_1' => $b2Floor1,
            'room_102' => $this->seedRoom($building1, $b1Floor2, 'Room 102'),
            'room_201' => $this->seedRoom($building2, $b2Floor1, 'Room 201'),
            'room_101_inactive' => $this->seedRoom($building1, $b1Floor1, 'Room 101', false),
            'room_mismatched' => $this->seedRoom($building1, $b2Floor1, 'Room 150'),
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

    private function seedRoom(int $buildingId, int $floorId, string $name, bool $isActive = true): int
    {
        return DB::table('rooms')->insertGetId([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'name' => $name,
            'capacity' => 30,
            'is_active' => $isActive ? 1 : 0,
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

    private function seedReport(int $createdBy, int $departmentId, string $location): int
    {
        return DB::table('maintenance_reports')->insertGetId([
            'title' => 'Existing report',
            'description' => 'Already filed before this change.',
            'location' => $location,
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $createdBy,
            'department_id' => $departmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'report_id');
    }

    /** The minimum valid POST body, plus whatever the test is actually about. */
    private function reportPayload(int $departmentId, array $overrides = []): array
    {
        return array_merge([
            // problem_type became required on /api/reports when the Problem
            // Type field was added to Create Report. It is orthogonal to
            // location validation, so it is part of the baseline body here
            // rather than something any individual test varies.
            'problem_type' => 'Electrical',
            'title' => 'Broken projector',
            'description' => 'The projector will not power on.',
            'location' => 'Unspecified',
            'priority' => 'medium',
            'department_id' => $departmentId,
        ], $overrides);
    }

    private function assertNoReportWasCreated(): void
    {
        $this->assertSame(
            0,
            DB::table('maintenance_reports')->count(),
            'A report was persisted despite an invalid location.'
        );
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
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->addMaintenanceReportAssetColumns();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();

        Schema::enableForeignKeyConstraints();
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
     * Mirrors the real rooms table, including the detail that makes this task
     * necessary: building_id and floor_id are two separate columns, so the
     * schema alone cannot guarantee the floor belongs to the building.
     */
    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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
