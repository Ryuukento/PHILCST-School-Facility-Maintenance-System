<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 45 — Damage Report Role Redesign.
 *
 * Task 45 reframes Damage Reports as a SPECIALISED ASSET-DAMAGE VIEW over the
 * primary maintenance workflow, rather than a second, independent reporting
 * workflow. The behaviour this suite pins down is therefore mostly about what
 * must *stay true* while the view changes:
 *
 *   - the damage list/detail can now resolve the linked maintenance report,
 *     its assignee and the asset's building, so the UI can present the case in
 *     context instead of as a standalone record;
 *   - assignment, authorization and the Task 44 cross-department warning stay
 *     exclusively on the maintenance-report side and are not duplicated here.
 *
 * 2026-10-08 — the "creation happens through exactly ONE path (POST
 * /api/reports), which creates a maintenance report and its paired damage row
 * 1:1" bullet that used to be here described ReportService::createAssetReport()
 * (SPRINT 5/TASK 50), which forked on item_id/room_id/deployed_asset_id. That
 * fork was removed by explicit user decision: Damage Reports must come from
 * exactly ONE trigger — a Need Change request — not from naming an asset on
 * Create Report. POST /api/reports no longer creates a damage_reports row at
 * all; the only remaining path into damage_reports is
 * DamageReportService::attachNeedChangeAsDamageReport(), driven by
 * need_change_item_id. Tests 1 and 5-6 below were updated accordingly.
 *
 * A note on the schema used below. Unlike DamageReportControllerTest's fixture,
 * `rooms` here is created WITH `building_id` and a real `buildings` table,
 * because production's `rooms` table has always had that column and Task 45
 * surfaces it. This matters: it was verified during this task that the SQLite
 * test driver does NOT raise an error when a select references a column the
 * table lacks — it silently omits the attribute. A fixture missing
 * `building_id` would therefore have produced a green test that proved nothing
 * about the building lookup. Building the column explicitly is what makes the
 * building assertions meaningful.
 */
class DamageReportRoleRedesignTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const DAMAGE_LIST_PAGE = __DIR__ . '/../../public/frontend/pages/damage-reports.php';
    private const DAMAGE_DETAIL_PAGE = __DIR__ . '/../../public/frontend/pages/damage-report-detail.php';
    private const MAINTENANCE_DETAIL_PAGE = __DIR__ . '/../../public/frontend/pages/maintenance-report-detail.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('damage_report_role_redesign_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    // -----------------------------------------------------------------
    // 1-2. Creation: asset fields are now inert, non-asset reports stay valid
    // -----------------------------------------------------------------

    // 2026-10-08 — renamed and inverted. This used to pin that item_id/
    // room_id/damage_description/severity_level on POST /api/reports made
    // ReportController::store() fork to ReportService::createAssetReport(),
    // pairing a damage_reports row with the maintenance report 1:1 (TASK
    // 45/SPRINT 5). That fork was removed, by explicit user decision: Damage
    // Reports must come from exactly ONE trigger — a Need Change request —
    // not from naming an asset on Create Report. None of these fields are
    // validated or read by ReportController::store() any more; they are
    // silently ignored and the report is created as an ordinary general
    // report with no damage relationship at all.
    public function test_asset_fields_on_report_creation_no_longer_create_a_damage_relationship(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Keyboard is not working',
                'description' => 'The keyboard in the laboratory has stopped responding.',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'damage_description' => 'Several keys are unresponsive.',
                'severity_level' => 'high',
            ]);

        $response->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
        // The asset fields above are now inert on this endpoint.
        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame('general', DB::table('maintenance_reports')->first()->report_category);
    }

    public function test_non_asset_maintenance_report_remains_valid_and_creates_no_damage_row(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Hallway light flickers',
                'description' => 'The corridor lighting flickers intermittently.',
                'priority' => 'medium',
                'department_id' => $deptId,
            ]);

        $response->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
        // No asset was named, so this is NOT an asset-damage case.
        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame('general', DB::table('maintenance_reports')->first()->report_category);
    }

    // -----------------------------------------------------------------
    // 3. The list surfaces asset-damage records only
    // -----------------------------------------------------------------

    public function test_damage_list_returns_only_asset_damage_records(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        // One asset-damage case...
        $reportId = $this->seedReport(['department_id' => $deptId, 'created_by' => $staffId]);
        $this->seedDamageReport([
            'report_id' => $reportId, 'item_id' => $itemId, 'room_id' => $roomId,
            'department_id' => $deptId, 'reported_by' => $staffId,
        ]);

        // ...and two maintenance reports with no asset damage at all.
        $this->seedReport(['department_id' => $deptId, 'created_by' => $staffId]);
        $this->seedReport(['department_id' => $deptId, 'created_by' => $staffId]);

        $rows = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports')
            ->assertOk()
            ->json('data.reports.data');

        // The page is an asset-damage view: it must not become a second list
        // of every maintenance report.
        $this->assertCount(1, $rows);
        $this->assertSame($reportId, (int) $rows[0]['report_id']);
    }

    // -----------------------------------------------------------------
    // 4. The detail payload connects the case to its primary report
    // -----------------------------------------------------------------

    public function test_damage_detail_exposes_the_linked_maintenance_report_and_assignment(): void
    {
        [$deptId, $staffId, $roomId, $itemId, $buildingId] = $this->seedAssetContext();
        $assigneeId = $this->seedUser([
            'role' => 'maintenance_staff',
            'full_name' => 'Euclide Tester',
            'department_id' => $deptId,
        ]);

        $reportId = $this->seedReport([
            'title' => 'Keyboard is not working',
            'department_id' => $deptId,
            'created_by' => $staffId,
            'assigned_to' => $assigneeId,
            'status' => 'assigned',
            'priority' => 'high',
        ]);
        $damageId = $this->seedDamageReport([
            'report_id' => $reportId, 'item_id' => $itemId, 'room_id' => $roomId,
            'department_id' => $deptId, 'reported_by' => $staffId,
        ]);

        $payload = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports/' . $damageId)
            ->assertOk()
            ->json('data.report');

        // The linked primary report, so the detail page can render the
        // "Maintenance Report" section and the "View Maintenance Report" link.
        $this->assertSame($reportId, (int) $payload['report_id']);
        $this->assertSame('Keyboard is not working', $payload['report']['title']);
        $this->assertSame('assigned', $payload['report']['status']);

        // Assignment is shown here but never performed here.
        $this->assertSame('Euclide Tester', $payload['report']['assignee']['full_name']);

        // The asset's building, resolved through the room.
        $this->assertSame($buildingId, (int) $payload['room']['building_id']);
        $this->assertSame('Lourdes Building 1', $payload['room']['building']['name']);
    }

    public function test_damage_detail_tolerates_a_legacy_record_with_no_linked_report(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        // Rows predating the unified flow legitimately have report_id = null.
        $damageId = $this->seedDamageReport([
            'report_id' => null, 'item_id' => $itemId, 'room_id' => $roomId,
            'department_id' => $deptId, 'reported_by' => $staffId,
        ]);

        $payload = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports/' . $damageId)
            ->assertOk()
            ->json('data.report');

        $this->assertNull($payload['report_id']);
        $this->assertNull($payload['report']);
    }

    // -----------------------------------------------------------------
    // 5-6. No duplicate records
    // -----------------------------------------------------------------

    // 2026-10-08 — the "asset report" framing in this name is stale (see the
    // note above test_asset_fields_on_report_creation_no_longer_create_a_damage_relationship),
    // but the assertion itself still holds, for an unrelated reason: a single
    // POST to /api/reports always creates exactly one maintenance report,
    // asset fields or not. Left in place rather than removed so this
    // invariant stays pinned.
    public function test_creating_one_asset_report_does_not_produce_duplicate_maintenance_reports(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        $this->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->assetReportPayload($deptId, $itemId, $roomId))
            ->assertStatus(201);

        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    // 2026-10-08 — renamed and inverted. This used to pin that a single
    // asset-linked submission produced exactly one damage_reports row, not
    // two, guarding against a double-insert bug in createAssetReport()'s
    // paired create (TASK 45/SPRINT 5). ReportService::createAssetReport()
    // was removed, by explicit user decision, so POST /api/reports no longer
    // creates ANY damage_reports row from these fields — see
    // test_asset_fields_on_report_creation_no_longer_create_a_damage_relationship
    // above for the full explanation.
    public function test_asset_fields_on_report_creation_produce_no_damage_reports_at_all(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        $this->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->assetReportPayload($deptId, $itemId, $roomId))
            ->assertStatus(201);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    public function test_the_damage_view_never_writes_a_second_damage_row_for_the_same_report(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        $reportId = $this->seedReport(['department_id' => $deptId, 'created_by' => $staffId]);
        $this->seedDamageReport([
            'report_id' => $reportId, 'item_id' => $itemId, 'room_id' => $roomId,
            'department_id' => $deptId, 'reported_by' => $staffId,
        ]);

        // Reading the list and the detail is what the redesigned page does.
        // Neither may have any write side effect.
        $before = DB::table('damage_reports')->count();
        $this->actingAsSessionUser($staffId, 'maintenance_staff')->getJson('/api/damage-reports')->assertOk();
        $damageId = DB::table('damage_reports')->first()->id;
        $this->actingAsSessionUser($staffId, 'maintenance_staff')->getJson('/api/damage-reports/' . $damageId)->assertOk();

        $this->assertSame($before, DB::table('damage_reports')->count());
        $this->assertSame(1, DB::table('maintenance_reports')->where('report_id', $reportId)->count());
    }

    // -----------------------------------------------------------------
    // 7-9. Assignment, Task 44 and authorization stay where they were
    // -----------------------------------------------------------------

    public function test_damage_reports_page_offers_no_assignment_control(): void
    {
        $page = file_get_contents(self::DAMAGE_LIST_PAGE) . file_get_contents(self::DAMAGE_DETAIL_PAGE);

        // Phase 8 REQUIRES the damage view to display Assigned Personnel and
        // Assignment Status, so the mere presence of the string `assigned_to`
        // is expected and correct — the detail page reads `linked?.assigned_to`
        // to render the Assignment section. What Phases 9/10 forbid is a
        // parallel assignment WORKFLOW, i.e. the page sending an assignment.
        //
        // The invariant asserted here is therefore that the damage pages are
        // strictly read-only. Both pages build their requests through
        // Components.fetchJson without a `method` option, which means every
        // request they can possibly issue is a GET. If anyone later adds a
        // mutating call — an assignment or anything else — they must introduce
        // a `method:` key, and this assertion fails.
        $this->assertStringNotContainsString('method:', $page);

        // `assigned_to` may be READ (`.assigned_to`) but never WRITTEN, i.e.
        // never used as a request-payload key or assignment target.
        $this->assertStringNotContainsString('assigned_to:', $page);
        $this->assertStringNotContainsString('assigned_to =', $page);

        // No assignment UI control, and no call to the assignment endpoint.
        $this->assertStringNotContainsString('assignment-select', $page);
        $this->assertStringNotContainsString('Assign Anyway', $page);
        $this->assertStringNotContainsString('/assign', $page);
    }

    public function test_damage_report_pages_link_to_the_existing_maintenance_report_workflow(): void
    {
        $list = file_get_contents(self::DAMAGE_LIST_PAGE);
        $detail = file_get_contents(self::DAMAGE_DETAIL_PAGE);

        // Phase 9: a clear route back to the primary report, pointing at the
        // EXISTING detail page rather than a parallel screen.
        // Clean URLs (2026-10-06): the detail page is now /maintenance-report-detail.
        $this->assertStringContainsString("'/maintenance-report-detail'", $list);
        $this->assertStringContainsString("'/maintenance-report-detail'", $detail);
        $this->assertStringContainsString('View Maintenance Report', $detail);
    }

    public function test_task_44_cross_department_warning_remains_intact(): void
    {
        $page = file_get_contents(self::MAINTENANCE_DETAIL_PAGE);

        // Task 45 must not have disturbed any part of Task 44.
        $this->assertStringContainsString('confirmCrossDepartmentAssignment', $page);
        $this->assertStringContainsString('isCrossDepartmentAssignment', $page);
        $this->assertStringContainsString('Cross-Department Assignment', $page);
        $this->assertStringContainsString('Assign Anyway', $page);
        $this->assertStringContainsString('isStatusUpdateInFlight', $page);
    }

    public function test_existing_damage_report_authorization_is_unchanged(): void
    {
        [$deptId, $staffId, $roomId, $itemId] = $this->seedAssetContext();

        // A non-privileged reporter may only see their OWN damage records —
        // the scoping rule that existed before Task 45.
        $otherUserId = $this->seedUser(['role' => 'faculty', 'department_id' => $deptId]);
        $this->seedDamageReport([
            'item_id' => $itemId, 'room_id' => $roomId, 'department_id' => $deptId,
            'reported_by' => $staffId,
        ]);

        $rows = $this
            ->actingAsSessionUser($otherUserId, 'faculty')
            ->getJson('/api/damage-reports')
            ->assertOk()
            ->json('data.reports.data');

        $this->assertCount(0, $rows);
    }

    public function test_unauthenticated_access_to_the_damage_list_is_still_rejected(): void
    {
        $this->getJson('/api/damage-reports')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // 15. Empty state
    // -----------------------------------------------------------------

    public function test_damage_list_returns_an_empty_set_when_no_asset_damage_exists(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $rows = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports')
            ->assertOk()
            ->json('data.reports.data');

        $this->assertSame([], $rows);
    }

    public function test_empty_state_explains_the_feature_and_offers_no_creation_action(): void
    {
        $page = file_get_contents(self::DAMAGE_LIST_PAGE);

        $this->assertStringContainsString('No asset damage reports found.', $page);
        $this->assertStringContainsString(
            'Asset-related maintenance reports will appear here when a physical item is reported as damaged.',
            $page
        );

        // Phase 15/16: creation stays in Create Report. The retired standalone
        // creation page must not come back in any form.
        //
        // The assertion runs against the page with comments stripped. The
        // source deliberately DOCUMENTS the absence of a creation control
        // ("there is deliberately no \"Create Damage Report\" control here"),
        // so a raw substring search matches the explanation rather than a
        // button. Stripping comments tests the rendered/executed page, which is
        // the thing that actually has to be free of a creation action.
        $markup = $this->stripComments($page);

        $this->assertStringNotContainsString('Create Damage Report', $markup);
        $this->assertStringNotContainsString('damage-report-create', $markup);
        $this->assertStringNotContainsString('damage-reports/create', $markup);

        // The comments themselves must still say why, so the intent survives
        // the next person who edits this page.
        $this->assertStringContainsString('creation happens only through Create Report', $page);
    }

    public function test_damage_reports_page_presents_itself_as_an_asset_damage_view(): void
    {
        $page = file_get_contents(self::DAMAGE_LIST_PAGE);

        $this->assertStringContainsString('Asset Damage Cases', $page);
        $this->assertStringContainsString(
            'Track maintenance reports involving damaged physical assets and their repair/replacement progress.',
            $page
        );
    }

    // -----------------------------------------------------------------
    // 10-14. Neighbouring modules are untouched
    // -----------------------------------------------------------------

    /**
     * TASK 13 narrows this test for the second time — and again without
     * relaxing it.
     *
     * History: Task 45 asserted the whole Repair Request module (backend AND
     * the repair-requests.php page) was left in place. Task 12 retired the
     * USER-FACING half and inverted the page assertion while keeping the three
     * backend assertions, so this file stayed the gate against a PREMATURE
     * backend removal. Task 13 is the authorized backend removal, so those
     * three flip too.
     *
     * Every expectation here has now been inverted rather than dropped, which
     * keeps the retirement PINNED: this file still fails loudly if any part of
     * the Repair module reappears. It is deliberately NOT deleted — the brief
     * warns against removing tests merely because they mention "repair", and
     * the rest of this class is Damage Report coverage that must survive.
     *
     * CRITICALLY, the Damage Report side is untouched and still asserted by
     * test_damage_report_backend_is_left_in_place() immediately below. That is
     * the pairing that matters: Damage Reports share the word "repair" (the
     * repair_notes column, the repairing/repaired statuses, the
     * report_category='repair_replacement' value asserted earlier in this
     * file) and must NOT be retired with it.
     */
    public function test_repair_request_module_is_retired(): void
    {
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Models/RepairRequest.php');
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Models/RepairHistory.php');
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Services/RepairService.php');
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Http/Controllers/Api/RepairController.php');

        $this->assertFileDoesNotExist(
            __DIR__ . '/../../public/frontend/pages/repair-requests.php',
            'TASK 12 retired the user-facing Repair Request page; TASK 13 retired the backend behind it.'
        );
    }

    /**
     * TASK 13 — the Damage Report vocabulary that merely SHARES the word
     * "repair" must survive the Repair Request retirement. The brief names
     * each of these explicitly as must-keep, and they are the likeliest
     * casualties of a text-matching cleanup pass.
     */
    public function test_damage_report_repair_vocabulary_survives_the_repair_retirement(): void
    {
        $model = file_get_contents(__DIR__ . '/../../app/Models/DamageReport.php');

        $this->assertStringContainsString(
            'repair_notes',
            $model,
            'damage_reports.repair_notes is Damage Report data, not a Repair Request field.'
        );

        foreach ([
            'replacement_item_id',
            'replacement_quantity',
            'replacement_transaction_id',
            'replaced_by',
            'replaced_at',
        ] as $field) {
            $this->assertStringContainsString(
                $field,
                $model,
                "Damage Report replacement field {$field} must survive Task 13."
            );
        }

        $service = file_get_contents(__DIR__ . '/../../app/Services/DamageReportService.php');

        foreach (['repairing', 'repaired'] as $status) {
            $this->assertStringContainsString(
                "'" . $status . "'",
                $service,
                "The '{$status}' damage-report status is part of the Damage Report "
                . 'lifecycle and is unrelated to repair_requests.repair_status.'
            );
        }
    }

    public function test_damage_report_backend_is_left_in_place(): void
    {
        $this->assertFileExists(__DIR__ . '/../../app/Models/DamageReport.php');
        $this->assertFileExists(__DIR__ . '/../../app/Models/DamageReportHistory.php');
        $this->assertFileExists(__DIR__ . '/../../app/Services/DamageReportService.php');
        $this->assertFileExists(__DIR__ . '/../../app/Http/Controllers/Api/DamageReportController.php');
    }

    /**
     * 2026-10-08 supersedes TASK 99 and this file's own prior reversal here.
     *
     * Earlier the same day, this test asserted the OPPOSITE: that the
     * sidebar DID need a standalone "Damage Reports" entry, because TASK 99's
     * All Reports / Export Reports Report Type filter only matched the
     * 'replaced' outcome (`dr.status = 'replaced'` or a non-null
     * `replaced_at`), leaving every other-status Need-Change-auto-linked
     * damage_reports row permanently unreachable without a dedicated nav
     * entry back to /damage-reports.
     *
     * By a second, later explicit user decision the same day, that gap is
     * closed a different way instead: ReportController::index()'s
     * report_type classification/filter was widened from "replaced outcome
     * only" to "has a linked damage_reports row at all" (any status — see
     * the comment above the report_type SELECT there). Every
     * Need-Change-auto-linked case is now reachable through the existing All
     * Reports "Damage Reports" Report Type filter, so the standalone sidebar
     * entry that worked around the old gap is removed again. The
     * /damage-reports page itself, and damage-report-detail.php /
     * damage-report-update.php, are NOT removed — only the sidebar
     * destination is gone, same precedent as TASK 100's Create Report.
     *
     * The TASK 12 Repair Requests retirement is untouched and still asserted
     * absent below — this file remains the regression gate for both.
     */
    public function test_damage_reports_have_no_standalone_sidebar_destination(): void
    {
        $sidebar = file_get_contents(__DIR__ . '/../../public/frontend/includes/sidebar.php');

        // Comments in this file discuss damage-reports.php at length, so the
        // assertion has to look at what the sidebar actually renders — an
        // <a href> nav entry — not at the documentation around it.
        $rendered = $this->stripComments($sidebar);

        $this->assertStringNotContainsString(
            'damage-reports.php',
            $rendered,
            'The sidebar must not link to the standalone Damage Reports page.'
        );
        $this->assertStringNotContainsString(
            'Damage Reports',
            $rendered,
            'The sidebar must not render a Damage Reports nav item.'
        );
        $this->assertStringNotContainsString(
            'damage-report-detail.php',
            $rendered,
            'The removed nav entry\'s active-state list must be gone with it.'
        );

        // TASK 12 — the Repair Requests nav entry remains retired.
        $this->assertStringNotContainsString(
            'repair-requests.php',
            $rendered,
            'The sidebar must not link to the retired Repair Requests page.'
        );

        // Neighbouring navigation is untouched: All Reports, the entry point of
        // the primary workflow, must still be present.
        $this->assertStringContainsString('reports.php', $rendered);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Strip HTML and JavaScript comments from page source.
     *
     * These pages carry deliberate comments EXPLAINING that a control is
     * absent — for example "there is deliberately no \"Create Damage Report\"
     * control here". A naive substring search cannot tell that explanation
     * apart from an actual button, so an assertion about what the page OFFERS
     * has to look at the executed markup, not the documentation around it.
     */
    private function stripComments(string $source): string
    {
        // HTML comments: <!-- ... --> (spanning lines).
        $source = preg_replace('/<!--.*?-->/s', '', $source);
        // JS/PHP block comments: /* ... */
        $source = preg_replace('#/\*.*?\*/#s', '', $source);
        // Whole-line // comments (leading whitespace only, so `https://` and
        // other mid-line occurrences are left alone).
        $source = preg_replace('#^[ \t]*//.*$#m', '', $source);

        return $source;
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array{0:int,1:int,2:int,3:int,4:int} dept, staff, room, item, building */
    private function seedAssetContext(): array
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $buildingId = $this->seedBuilding(['name' => 'Lourdes Building 1']);
        $roomId = $this->seedRoom(['name' => 'Laboratory ROOM 102', 'building_id' => $buildingId]);
        $itemId = $this->seedItem([
            'name' => 'Keyboard',
            'item_type' => 'room_asset',
            'room_id' => $roomId,
            'quantity' => 1,
        ]);

        return [$deptId, $staffId, $roomId, $itemId, $buildingId];
    }

    private function assetReportPayload(int $deptId, int $itemId, int $roomId): array
    {
        return [
            // problem_type became required on /api/reports when the Problem
            // Type field was added to Create Report. Not what this suite is
            // about, but the endpoint will not accept a body without it.
            'problem_type' => 'Electrical',
            'title' => 'Keyboard is not working',
            'description' => 'The keyboard in the laboratory has stopped responding.',
            'priority' => 'high',
            'department_id' => $deptId,
            'item_id' => $itemId,
            'room_id' => $roomId,
            'damage_description' => 'Several keys are unresponsive.',
            'severity_level' => 'high',
        ];
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
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createBuildingsTable();
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

    /**
     * Mirrors SPRINT 1's report_category/item_id/source_dispatch_id columns,
     * which DamageReportService::createReport() writes on every creation.
     */
    private function addMaintenanceReportAssetColumns(): void
    {
        Schema::table('maintenance_reports', function ($table): void {
            $table->string('report_category', 30)->default('general')->after('description');
            $table->unsignedInteger('item_id')->nullable()->after('report_category');
            $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
        });
    }

    private function createBuildingsTable(): void
    {
        Schema::create('buildings', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Includes `building_id` because production's `rooms` table does. See the
     * class docblock: without it the building assertions would silently pass
     * against a missing column and prove nothing.
     */
    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->timestamps();
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

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    private function seedBuilding(array $overrides = []): int
    {
        return DB::table('buildings')->insertGetId(array_merge([
            'name' => 'Building ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Room ' . uniqid(),
            'building_id' => null,
            'floor_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'report_category' => 'general',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $this->seedUser(),
            'assigned_to' => null,
            'department_id' => null,
            'due_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }

    private function seedDamageReport(array $overrides = []): int
    {
        return DB::table('damage_reports')->insertGetId(array_merge([
            'damage_report_code' => 'DMG-' . uniqid(),
            'report_id' => null,
            'item_id' => null,
            'room_id' => null,
            'department_id' => null,
            'damage_description' => 'Something is broken.',
            'severity_level' => 'medium',
            'reported_by' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
