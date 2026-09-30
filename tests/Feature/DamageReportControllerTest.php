<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 73 — Damage Reports: Full Investigation, Bug Fix, and Verification.
 *
 * No dedicated Damage Reports test file existed prior to this task (only
 * incidental coverage in ReportAuthorizationBypassAuditTest.php for the
 * status-sync authorization gate, and InventoryReportsDateFilterTest.php for
 * the date-range filter). This suite exercises the full module end-to-end
 * through its actual HTTP surface:
 *
 *   GET  /api/damage-reports                    index()
 *   POST /api/damage-reports                     store()
 *   POST /api/damage-reports/check-duplicate      checkDuplicate()
 *   GET  /api/damage-reports/{id}                 show()
 *   POST /api/damage-reports/{id}/status           updateStatus()
 *   GET  /api/damage-reports/{id}/history          history()
 */
class DamageReportControllerTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('damage_report_controller_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------
    // store()
    // ---------------------------------------------------------------

    public function test_maintenance_staff_can_create_a_damage_report_for_a_room_asset(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId, 'quantity' => 1]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Screen is cracked badly.',
                'severity_level' => 'high',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('damage_reports')->count());

        $row = DB::table('damage_reports')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame($staffId, (int) $row->reported_by);
        $this->assertNotNull($row->report_id);

        // Sprint 4: a linked maintenance_reports row must be created alongside it.
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        $linked = DB::table('maintenance_reports')->first();
        $this->assertSame('submitted', $linked->status);
        $this->assertSame('repair_replacement', $linked->report_category);

        // History row for creation must exist.
        $this->assertSame(1, DB::table('damage_report_histories')->where('action_type', 'created')->count());
    }

    public function test_store_rejects_missing_required_fields(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    public function test_store_rejects_an_item_not_deployed_to_the_selected_room(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomA = $this->seedRoom();
        $roomB = $this->seedRoom();
        // room_asset deployed to roomA, but the request claims roomB.
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomA, 'quantity' => 1]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomB,
                'department_id' => $deptId,
                'damage_description' => 'Mismatched room claim.',
                'severity_level' => 'low',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    public function test_store_rejects_an_inventory_stock_item_never_deployed_anywhere(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'inventory_stock', 'quantity' => 10]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Never deployed to any room.',
                'severity_level' => 'low',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    public function test_store_allows_a_deployed_inventory_stock_item(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'inventory_stock', 'quantity' => 10]);
        DB::table('inventory_transactions')->insert([
            'item_id' => $itemId,
            'room_id' => $roomId,
            'transaction_type' => 'deploy',
            'quantity' => 2,
            'performed_by' => $staffId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'A deployed stock unit is broken.',
                'severity_level' => 'medium',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('damage_reports')->count());
    }

    public function test_store_rejects_a_role_outside_the_allowed_posting_roles(): void
    {
        $deptId = $this->seedDepartment();
        // 'user' role can never be active in production (see approve()), but
        // the middleware must still reject it defensively if ever reached.
        $userId = $this->seedUser(['role' => 'user', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($userId, 'user')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Should not be allowed to post.',
                'severity_level' => 'low',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    /**
     * TASK 33 PHASE 3 — Administrator (super_admin) is a supervisory role
     * for the maintenance-report family of features (matching the existing
     * /api/reports policy locked in by ReportRbacPolicyTest) and must not be
     * able to create/submit a Damage Report. Head Maintenance and
     * Maintenance Staff remain the operational creators (see the two
     * "can create" tests above).
     */
    public function test_store_rejects_super_admin_as_a_creator(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId, 'quantity' => 1]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Administrator should not be able to submit this.',
                'severity_level' => 'high',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    public function test_store_blocks_a_duplicate_report_unless_overridden(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this->seedDamageReport([
            'item_id' => $itemId,
            'room_id' => $roomId,
            'department_id' => $deptId,
            'damage_description' => 'The projector bulb is burnt out.',
            'status' => 'pending',
        ]);

        // Same normalized description against the same item/room/department.
        $blocked = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'The projector bulb is burnt out.',
                'severity_level' => 'medium',
            ]);
        $blocked->assertStatus(409);
        $blocked->assertJsonPath('data.duplicate.status', 'pending');
        $this->assertSame(1, DB::table('damage_reports')->count());

        // Overriding must let a second report through.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'The projector bulb is burnt out.',
                'severity_level' => 'medium',
                'override_duplicate' => true,
            ])
            ->assertStatus(201);
        $this->assertSame(2, DB::table('damage_reports')->count());
    }

    public function test_store_does_not_treat_a_closed_report_as_a_duplicate(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this->seedDamageReport([
            'item_id' => $itemId,
            'room_id' => $roomId,
            'department_id' => $deptId,
            'damage_description' => 'The projector bulb is burnt out.',
            'status' => 'closed',
        ]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'The projector bulb is burnt out.',
                'severity_level' => 'medium',
            ])
            ->assertStatus(201);

        $this->assertSame(2, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // checkDuplicate()
    // ---------------------------------------------------------------

    public function test_check_duplicate_reports_an_existing_active_match(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this->seedDamageReport([
            'item_id' => $itemId,
            'room_id' => $roomId,
            'department_id' => $deptId,
            'damage_description' => 'Keyboard keys are missing.',
            'status' => 'under_review',
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports/check-duplicate', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Keyboard keys are missing.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', true);
    }

    public function test_check_duplicate_returns_none_for_a_fresh_combination(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports/check-duplicate', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Totally unrelated issue text.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.has_duplicate', false);
    }

    // ---------------------------------------------------------------
    // index()
    // ---------------------------------------------------------------

    public function test_index_filters_by_status_severity_and_search_text(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom(['name' => 'Faculty Room']);
        $itemA = $this->seedItem(['name' => 'Projector Alpha']);
        $itemB = $this->seedItem(['name' => 'Aircon Beta']);

        $this->seedDamageReport([
            'item_id' => $itemA, 'room_id' => $roomId, 'department_id' => $deptId,
            'status' => 'pending', 'severity_level' => 'high',
            'damage_description' => 'Projector will not power on.',
        ]);
        $this->seedDamageReport([
            'item_id' => $itemB, 'room_id' => $roomId, 'department_id' => $deptId,
            'status' => 'closed', 'severity_level' => 'low',
            'damage_description' => 'Aircon leaks water.',
        ]);

        $byStatus = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports?status=pending')
            ->json('data.reports.data');
        $this->assertCount(1, $byStatus);

        $bySeverity = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports?severity_level=low')
            ->json('data.reports.data');
        $this->assertCount(1, $bySeverity);

        $byQ = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports?q=projector')
            ->json('data.reports.data');
        $this->assertCount(1, $byQ);
    }

    public function test_index_returns_reports_for_privileged_roles_regardless_of_reporter(): void
    {
        $deptId = $this->seedDepartment();
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem();

        $this->seedDamageReport([
            'item_id' => $itemId, 'room_id' => $roomId, 'department_id' => $deptId,
            'reported_by' => $reporterId,
        ]);

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/damage-reports');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.reports.data'));
    }

    // ---------------------------------------------------------------
    // show() / history()
    // ---------------------------------------------------------------

    public function test_show_returns_report_with_relationships_loaded(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom(['name' => 'Room 101']);
        $itemId = $this->seedItem(['name' => 'Ceiling Fan']);
        $damageReportId = $this->seedDamageReport([
            'item_id' => $itemId, 'room_id' => $roomId, 'department_id' => $deptId,
            'reported_by' => $staffId,
        ]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson("/api/damage-reports/{$damageReportId}");

        $response->assertOk();
        $response->assertJsonPath('data.report.room.name', 'Room 101');
        $response->assertJsonPath('data.report.item.name', 'Ceiling Fan');
    }

    /**
     * TASK 73 — during this investigation, requesting a nonexistent damage
     * report was found to return HTTP 500 (with the raw exception message)
     * instead of the clean 404 the app's global exception renderer clearly
     * intends (bootstrap/app.php explicitly special-cases
     * ModelNotFoundException -> 404 "Resource not found."). Root-caused to
     * Laravel core's Handler::render() converting ModelNotFoundException to
     * NotFoundHttpException via prepareException() BEFORE the app's custom
     * render callback runs, making that branch unreachable dead code — for
     * EVERY implicit route-model-bound route in the whole application, not
     * just Damage Reports. Confirmed with the user this is a real, shared,
     * app-wide bug in bootstrap/app.php (outside the Damage Reports module),
     * and per Task 73's change-discipline rules it was intentionally not
     * fixed there.
     *
     * 2026-09-27 — fixed in bootstrap/app.php: HTTP exceptions keep their own
     * status, and a missing route-model-bound record answers 404
     * "Resource not found." (no model class name in the message).
     */
    public function test_show_of_a_missing_report_returns_a_clean_404(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/damage-reports/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_history_lists_entries_in_descending_created_at_order(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport(['department_id' => $deptId, 'reported_by' => $staffId]);

        // Seeded directly with distinct, well-separated timestamps (rather
        // than relying on two real requests completing within the same
        // second) so the ordering assertion is deterministic and isn't a
        // race against wall-clock resolution.
        DB::table('damage_report_histories')->insert([
            'damage_report_id' => $damageReportId,
            'action_type' => 'created',
            'from_status' => null,
            'to_status' => 'pending',
            'created_at' => now()->subHour(),
        ]);
        DB::table('damage_report_histories')->insert([
            'damage_report_id' => $damageReportId,
            'action_type' => 'status_changed',
            'from_status' => 'pending',
            'to_status' => 'under_review',
            'created_at' => now(),
        ]);

        $historyResp = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson("/api/damage-reports/{$damageReportId}/history");

        $historyResp->assertOk();
        $entries = $historyResp->json('data.histories');
        $this->assertCount(2, $entries);
        // Most recent (status_changed) must be first.
        $this->assertSame('status_changed', $entries[0]['action_type']);
        $this->assertSame('created', $entries[1]['action_type']);
    }

    // ---------------------------------------------------------------
    // updateStatus()
    // ---------------------------------------------------------------

    public function test_valid_status_transition_succeeds_and_syncs_linked_report(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport(['department_id' => $deptId, 'status' => 'submitted']);
        $damageReportId = $this->seedDamageReport([
            'report_id' => $reportId,
            'department_id' => $deptId,
            'status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertOk();

        $this->assertSame('under_review', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
        $this->assertSame('assigned', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'pending',
        ]);

        // pending -> repaired is not an allowed transition.
        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'repaired'])
            ->assertStatus(422);

        $this->assertSame('pending', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
    }

    public function test_closed_status_is_terminal_and_cannot_be_reopened(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'closed',
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'pending'])
            ->assertStatus(422);

        $this->assertSame('closed', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
    }

    public function test_marking_replaced_requires_a_replacement_item(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'under_review',
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'replaced'])
            ->assertStatus(422);

        $this->assertSame('under_review', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
    }

    public function test_marking_replaced_creates_an_inventory_deploy_transaction_and_stamps_fields(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $replacementItemId = $this->seedItem(['item_type' => 'inventory_stock', 'quantity' => 5]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'room_id' => $roomId,
            'status' => 'under_review',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", [
                'status' => 'replaced',
                'replacement_item_id' => $replacementItemId,
                'replacement_quantity' => 1,
            ]);
        $response->assertOk();

        $row = DB::table('damage_reports')->where('id', $damageReportId)->first();
        $this->assertSame('replaced', $row->status);
        $this->assertSame($replacementItemId, (int) $row->replacement_item_id);
        $this->assertSame(1, (int) $row->replacement_quantity);
        $this->assertNotNull($row->replacement_transaction_id);
        $this->assertNotNull($row->replaced_at);
        $this->assertSame($adminId, (int) $row->replaced_by);

        $this->assertSame(1, DB::table('inventory_transactions')
            ->where('item_id', $replacementItemId)
            ->where('transaction_type', 'deploy')
            ->where('quantity', 1)
            ->count());
    }

    public function test_replaced_report_can_then_be_closed(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'replaced',
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'closed'])
            ->assertOk();

        $row = DB::table('damage_reports')->where('id', $damageReportId)->first();
        $this->assertSame('closed', $row->status);
        $this->assertNotNull($row->closed_at);
    }

    public function test_update_status_requires_the_posting_role_middleware(): void
    {
        $deptId = $this->seedDepartment();
        $userId = $this->seedUser(['role' => 'user', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport(['department_id' => $deptId, 'status' => 'pending']);

        $this
            ->actingAsSessionUser($userId, 'user')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertStatus(403);

        $this->assertSame('pending', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
    }

    public function test_update_status_rejects_an_invalid_status_value(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport(['department_id' => $deptId, 'status' => 'pending']);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'not_a_real_status'])
            ->assertStatus(422);
    }

    /**
     * TASK 33 PHASE 3 — Supervisory access verification: Administrator's
     * create/submit capability was removed (see
     * test_store_rejects_super_admin_as_a_creator), but Administrator's
     * pre-existing review/approve capability on the status-update endpoint
     * must be unaffected, since this task only restricts creation.
     */
    public function test_super_admin_can_only_close_an_asset_report(): void
    {
        // 2026-09-27 — the Administrator approves and monitors; Head
        // Maintenance moves repairs through their statuses. The
        // Administrator keeps only the ability to close (cancel) one.
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $damageReportId = $this->seedDamageReport(['department_id' => $deptId, 'status' => 'pending']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertStatus(403);
        $this->assertSame('pending', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'closed'])
            ->assertOk();
        $this->assertSame('closed', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
    }

    // ---------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------

    public function test_creating_a_report_notifies_active_admins_but_not_other_staff(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $superId = $this->seedUser(['role' => 'super_admin']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $inactiveAdminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId, 'status' => 'inactive']);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Notify admins on create.',
                'severity_level' => 'medium',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $superId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $otherStaffId)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $inactiveAdminId)->count());
    }

    public function test_marking_replaced_notifies_the_original_reporter(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $replacementItemId = $this->seedItem(['item_type' => 'inventory_stock', 'quantity' => 5]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'under_review',
            'reported_by' => $reporterId,
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", [
                'status' => 'replaced',
                'replacement_item_id' => $replacementItemId,
                'replacement_quantity' => 1,
            ])
            ->assertOk();

        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $reporterId)
            ->where('entity_type', 'damage_report')
            ->where('entity_id', $damageReportId)
            ->count());
    }

    public function test_marking_closed_notifies_the_original_reporter(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport([
            'department_id' => $deptId,
            'status' => 'repaired',
            'reported_by' => $reporterId,
        ]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'closed'])
            ->assertOk();

        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $reporterId)
            ->where('entity_type', 'damage_report')
            ->where('entity_id', $damageReportId)
            ->count());
    }

    // ---------------------------------------------------------------
    // Attachment (damage_image) handling
    // ---------------------------------------------------------------

    public function test_store_accepts_a_valid_image_and_persists_its_path(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->post('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Has a photo attached.',
                'severity_level' => 'low',
                'damage_image' => $this->makeRealJpegUploadedFile(),
            ]);

        $response->assertStatus(201);
        $row = DB::table('damage_reports')->first();
        $this->assertNotNull($row->image_path);
        $this->assertStringStartsWith('/frontend/uploads/damage-reports/', $row->image_path);

        $absolutePath = public_path(ltrim($row->image_path, '/'));
        $this->assertFileExists($absolutePath);
        // Test-only cleanup — this suite writes into the real
        // public/frontend/uploads/damage-reports directory (the service
        // hardcodes public_path(), same as production), so the uploaded
        // fixture file must not be left behind after the test run.
        @unlink($absolutePath);
    }

    public function test_store_rejects_a_disallowed_file_extension_for_the_image(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->post('/api/damage-reports', [
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $deptId,
                'damage_description' => 'Has a bad file attached.',
                'severity_level' => 'low',
                'damage_image' => \Illuminate\Http\UploadedFile::fake()->create('malware.exe', 100),
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // Authentication boundary
    // ---------------------------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/damage-reports')->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Schema / seeding
    // ---------------------------------------------------------------

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
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
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
     * SPRINT 1's report_category/item_id/source_dispatch_id addition to
     * maintenance_reports (2026_07_28_001500_...) isn't part of the shared
     * BuildsSharedTestSchema::createMaintenanceReportsTable() builder, but
     * DamageReportService::createReport() writes to report_category and
     * item_id on every damage-report creation, so this isolated schema needs
     * them too.
     */
    private function addMaintenanceReportAssetColumns(): void
    {
        Schema::table('maintenance_reports', function ($table): void {
            $table->string('report_category', 30)->default('general')->after('description');
            $table->unsignedInteger('item_id')->nullable()->after('report_category');
            $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
        });
    }

    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
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

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Room ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $this->seedUser(),
            'assigned_to' => null,
            'department_id' => null,
            'due_date' => null,
            'completed_date' => null,
            'need_change_item_id' => null,
            'need_change_quantity' => 1,
            'need_change_status' => null,
            'need_change_approved_by' => null,
            'need_change_approved_at' => null,
            'need_change_deducted_at' => null,
            'completion_proof_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }

    /**
     * The GD extension is not installed in this environment, so
     * UploadedFile::fake()->image() (which requires imagecreatetruecolor())
     * cannot be used to exercise the store() endpoint's 'image' validation
     * rule, which inspects actual file content rather than the declared
     * extension. A minimal, valid, real 1x1 JPEG is written to a temp file
     * and wrapped as a test UploadedFile instead.
     */
    private function makeRealJpegUploadedFile(): \Illuminate\Http\UploadedFile
    {
        // Smallest valid 1x1 black JPEG, base64-encoded.
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIy'
            . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIA'
            . 'AhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEB'
            . 'AQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX'
            . '/9k='
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'dmgtest') . '.jpg';
        file_put_contents($tmpPath, $bytes);

        return new \Illuminate\Http\UploadedFile($tmpPath, 'damage.jpg', 'image/jpeg', null, true);
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
