<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 72 — Deployment Tracking verification coverage.
 *
 * Deployment Tracking (DeploymentTrackingController) has no dedicated table:
 * it is a read-only view derived from dispatches/dispatch_items joined to
 * items, purchase_receipts (direct link, or a fallback of the oldest
 * purchase_receipt_items row referencing the item), rooms, and departments.
 * Prior to this file the module had zero automated test coverage. These
 * tests lock in the module's actual (investigated) behavior: RBAC gating
 * identical to the legacy sidebar/page guard, the default status=released
 * filter, q/room_id/department_id filtering, filter_options scoping to
 * released-only rooms/departments, and the OR-number search's one-row-per
 * (receipt line x dispatch) semantics including the "not yet deployed"
 * NULL-dispatch-columns case.
 */
class DeploymentTrackingControllerTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('deployment_tracking_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/deployment-tracking');

        $response->assertStatus(401);
    }

    public function test_index_forbidden_for_maintenance_staff(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/deployment-tracking');

        $response->assertStatus(403);
    }

    public function test_index_allowed_for_super_admin(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
    }

    public function test_index_allowed_for_maintenance_admin(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->getJson('/api/deployment-tracking/search?q=OR');

        $response->assertStatus(401);
    }

    public function test_search_forbidden_for_maintenance_staff(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/deployment-tracking/search?q=OR');

        $response->assertStatus(403);
    }

    public function test_search_allowed_for_super_admin(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking/search?q=OR');

        $response->assertOk();
    }

    // -----------------------------------------------------------------
    // index() — default status filter
    // -----------------------------------------------------------------

    public function test_index_defaults_to_released_status_only(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $released = $this->seedDispatch($itemId, 3, ['dispatch_code' => 'DSP-RELEASED', 'status' => 'released']);
        $this->seedDispatch($itemId, 2, ['dispatch_code' => 'DSP-PENDING', 'status' => 'pending']);
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-APPROVED', 'status' => 'approved']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$released], $ids);
    }

    public function test_index_status_param_overrides_default(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $this->seedDispatch($itemId, 3, ['dispatch_code' => 'DSP-RELEASED', 'status' => 'released']);
        $pending = $this->seedDispatch($itemId, 2, ['dispatch_code' => 'DSP-PENDING', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?status=pending');

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$pending], $ids);
    }

    public function test_index_rejects_invalid_status_and_falls_back_to_released(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $released = $this->seedDispatch($itemId, 3, ['dispatch_code' => 'DSP-RELEASED', 'status' => 'released']);
        $this->seedDispatch($itemId, 2, ['dispatch_code' => 'DSP-PENDING', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?status=not-a-real-status');

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$released], $ids);
    }

    // -----------------------------------------------------------------
    // index() — q / room_id / department_id filters
    // -----------------------------------------------------------------

    public function test_index_filters_by_q_matching_item_name(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $markerId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $chairId = $this->seedItem(['name' => 'Plastic Chair']);
        $markerDispatch = $this->seedDispatch($markerId, 1, ['dispatch_code' => 'DSP-MARKER', 'status' => 'released']);
        $this->seedDispatch($chairId, 1, ['dispatch_code' => 'DSP-CHAIR', 'status' => 'released']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?q=marker');

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$markerDispatch], $ids);
    }

    public function test_index_filters_by_q_matching_source_or_number(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $receiptId = $this->seedReceipt($userId, ['or_number' => 'OR-UNIQUE-9001']);
        $dispatchId = $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-DIRECT',
            'status' => 'released',
            'purchase_receipt_id' => $receiptId,
        ]);
        $otherItemId = $this->seedItem(['name' => 'Plastic Chair']);
        $this->seedDispatch($otherItemId, 1, ['dispatch_code' => 'DSP-OTHER', 'status' => 'released']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?q=OR-UNIQUE-9001');

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$dispatchId], $ids);
    }

    public function test_index_filters_by_room_id(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $roomA = $this->seedRoom(['name' => 'Room A']);
        $roomB = $this->seedRoom(['name' => 'Room B']);
        $inRoomA = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-A', 'status' => 'released', 'room_id' => $roomA]);
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-B', 'status' => 'released', 'room_id' => $roomB]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?room_id=' . $roomA);

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$inRoomA], $ids);
    }

    public function test_index_filters_by_department_id(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $deptA = DB::table('departments')->insertGetId(['name' => 'Dept A', 'created_at' => now(), 'updated_at' => now()]);
        $deptB = DB::table('departments')->insertGetId(['name' => 'Dept B', 'created_at' => now(), 'updated_at' => now()]);
        $inDeptA = $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-A', 'status' => 'released', 'department_id' => $deptA]);
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-B', 'status' => 'released', 'department_id' => $deptB]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?department_id=' . $deptA);

        $response->assertOk();
        $ids = collect($response->json('data.rows'))->pluck('dispatch_id')->all();

        $this->assertSame([$inDeptA], $ids);
    }

    // -----------------------------------------------------------------
    // index() — filter_options scoping (released-only)
    // -----------------------------------------------------------------

    public function test_index_filter_options_only_include_rooms_and_departments_with_released_dispatches(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $releasedRoom = $this->seedRoom(['name' => 'Released Room']);
        $pendingOnlyRoom = $this->seedRoom(['name' => 'Pending Only Room']);
        $releasedDept = DB::table('departments')->insertGetId(['name' => 'Released Dept', 'created_at' => now(), 'updated_at' => now()]);
        $pendingOnlyDept = DB::table('departments')->insertGetId(['name' => 'Pending Only Dept', 'created_at' => now(), 'updated_at' => now()]);

        $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-RELEASED',
            'status' => 'released',
            'room_id' => $releasedRoom,
            'department_id' => $releasedDept,
        ]);
        $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-PENDING',
            'status' => 'pending',
            'room_id' => $pendingOnlyRoom,
            'department_id' => $pendingOnlyDept,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $roomNames = collect($response->json('data.filter_options.rooms'))->pluck('name')->all();
        $deptNames = collect($response->json('data.filter_options.departments'))->pluck('name')->all();

        $this->assertContains('Released Room', $roomNames);
        $this->assertNotContains('Pending Only Room', $roomNames);
        $this->assertContains('Released Dept', $deptNames);
        $this->assertNotContains('Pending Only Dept', $deptNames);
    }

    // -----------------------------------------------------------------
    // index() — source OR direct link vs. fallback
    // -----------------------------------------------------------------

    public function test_index_source_or_uses_direct_purchase_receipt_link_when_present(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $directReceiptId = $this->seedReceipt($userId, ['or_number' => 'OR-DIRECT']);
        // Older receipt referencing the same item via purchase_receipt_items —
        // must be ignored, since a direct purchase_receipt_id link exists.
        $olderReceiptId = $this->seedReceipt($userId, ['or_number' => 'OR-OLDER']);
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $olderReceiptId,
            'item_id' => $itemId,
            'item_name' => 'Whiteboard Marker',
            'quantity_received' => 10,
            'unit' => 'pc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedDispatch($itemId, 1, [
            'dispatch_code' => 'DSP-DIRECT',
            'status' => 'released',
            'purchase_receipt_id' => $directReceiptId,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $rows = collect($response->json('data.rows'));

        $this->assertSame('OR-DIRECT', $rows->first()['source_or']);
    }

    public function test_index_source_or_falls_back_to_oldest_receipt_item_when_no_direct_link(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $olderReceiptId = $this->seedReceipt($userId, ['or_number' => 'OR-OLDEST']);
        $newerReceiptId = $this->seedReceipt($userId, ['or_number' => 'OR-NEWEST']);
        foreach ([$olderReceiptId, $newerReceiptId] as $receiptId) {
            DB::table('purchase_receipt_items')->insert([
                'purchase_receipt_id' => $receiptId,
                'item_id' => $itemId,
                'item_name' => 'Whiteboard Marker',
                'quantity_received' => 10,
                'unit' => 'pc',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // No purchase_receipt_id on the dispatch itself -> must use the
        // fallback (MIN purchase_receipt_id referencing this item).
        $this->seedDispatch($itemId, 1, ['dispatch_code' => 'DSP-NOLINK', 'status' => 'released']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $rows = collect($response->json('data.rows'));

        $this->assertSame('OR-OLDEST', $rows->first()['source_or']);
    }

    // -----------------------------------------------------------------
    // index() — TASK 36 PHASE 2: direct room deployment traceability
    //
    // Coverage added for the Deployment Tracking traceability gap: prior to
    // this task, index() only ever queried dispatches/dispatch_items, so a
    // "Deploy to Room" event (InventoryStockController::createOrUpdateRoomAsset()
    // — an `items` row with item_type='room_asset', asset_code, room_id) never
    // appeared here even though it is a real, fully-recorded deployment. These
    // tests lock in the new UNION-ALL "direct" branch alongside the untouched
    // dispatch branch, confirming both deployment workflows now surface
    // side-by-side without either being merged, removed, or misrepresented.
    // -----------------------------------------------------------------

    public function test_index_includes_dispatch_sourced_deployment(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $dispatchId = $this->seedDispatch($itemId, 3, ['dispatch_code' => 'DSP-VISIBLE', 'status' => 'released']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $row = collect($response->json('data.rows'))->firstWhere('dispatch_id', $dispatchId);

        $this->assertNotNull($row);
        $this->assertSame('dispatch', $row['deployment_source']);
        $this->assertSame('DSP-VISIBLE', $row['dispatch_code']);
    }

    public function test_index_includes_direct_room_deployment(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom(['name' => 'Computer Lab 3']);
        $this->seedRoomAssetItem([
            'name' => 'Dell Monitor',
            'asset_code' => 'AST-0001',
            'room_id' => $roomId,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $row = collect($response->json('data.rows'))->firstWhere('asset_code', 'AST-0001');

        $this->assertNotNull($row, 'Direct room_asset deployment did not appear in Deployment Tracking.');
        $this->assertSame('direct', $row['deployment_source']);
        $this->assertSame('Dell Monitor', $row['item_name']);
        $this->assertSame('Computer Lab 3', $row['room_name']);
    }

    public function test_index_direct_deployment_has_asset_code_and_no_fabricated_dispatch_code(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom(['name' => 'Faculty Room']);
        $this->seedRoomAssetItem([
            'name' => 'Office Chair',
            'asset_code' => 'AST-9999',
            'room_id' => $roomId,
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $row = collect($response->json('data.rows'))->firstWhere('asset_code', 'AST-9999');

        $this->assertNotNull($row);
        // Must not fabricate a dispatch_code/dispatch_id/dispatch_status for a
        // deployment that never went through the Dispatch workflow.
        $this->assertNull($row['dispatch_code']);
        $this->assertNull($row['dispatch_id']);
        $this->assertNull($row['dispatch_status']);
    }

    public function test_index_dispatch_deployment_retains_real_dispatch_code_alongside_direct_rows(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);
        $dispatchId = $this->seedDispatch($itemId, 2, ['dispatch_code' => 'DSP-REAL-CODE', 'status' => 'released']);

        $roomId = $this->seedRoom(['name' => 'Room Z']);
        $this->seedRoomAssetItem(['name' => 'Projector', 'asset_code' => 'AST-PROJ', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $dispatchRow = collect($response->json('data.rows'))->firstWhere('dispatch_id', $dispatchId);

        $this->assertNotNull($dispatchRow);
        $this->assertSame('DSP-REAL-CODE', $dispatchRow['dispatch_code']);
        $this->assertSame('dispatch', $dispatchRow['deployment_source']);
        // No asset_code fabricated for a dispatch-sourced row.
        $this->assertNull($dispatchRow['asset_code']);
    }

    public function test_index_q_filter_matches_direct_deployment_by_asset_code(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom(['name' => 'Room Q']);
        $this->seedRoomAssetItem(['name' => 'Laptop Unit A', 'asset_code' => 'AST-FIND-ME', 'room_id' => $roomId]);
        $this->seedRoomAssetItem(['name' => 'Laptop Unit B', 'asset_code' => 'AST-OTHER', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?q=FIND-ME');

        $response->assertOk();
        $assetCodes = collect($response->json('data.rows'))->pluck('asset_code')->all();

        $this->assertSame(['AST-FIND-ME'], $assetCodes);
    }

    public function test_index_room_filter_applies_to_direct_deployment(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $roomA = $this->seedRoom(['name' => 'Room Direct A']);
        $roomB = $this->seedRoom(['name' => 'Room Direct B']);
        $this->seedRoomAssetItem(['name' => 'Aircon Unit', 'asset_code' => 'AST-ROOM-A', 'room_id' => $roomA]);
        $this->seedRoomAssetItem(['name' => 'Aircon Unit', 'asset_code' => 'AST-ROOM-B', 'room_id' => $roomB]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?room_id=' . $roomA);

        $response->assertOk();
        $assetCodes = collect($response->json('data.rows'))->pluck('asset_code')->all();

        $this->assertSame(['AST-ROOM-A'], $assetCodes);
    }

    public function test_index_direct_deployment_excluded_when_status_filter_is_not_released(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $roomId = $this->seedRoom(['name' => 'Room Filtered']);
        $this->seedRoomAssetItem(['name' => 'Extension Cord', 'asset_code' => 'AST-STATUS', 'room_id' => $roomId]);

        // A direct deployment has no dispatch-style lifecycle, so it must not
        // appear when the caller explicitly asks for a dispatch-only status.
        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?status=pending');

        $response->assertOk();
        $assetCodes = collect($response->json('data.rows'))->pluck('asset_code')->filter()->all();

        $this->assertSame([], $assetCodes);
    }

    public function test_index_direct_deployment_excluded_when_department_filter_applied(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $deptId = DB::table('departments')->insertGetId(['name' => 'Some Dept', 'created_at' => now(), 'updated_at' => now()]);
        $roomId = $this->seedRoom(['name' => 'Room Dept Filtered']);
        $this->seedRoomAssetItem(['name' => 'Router', 'asset_code' => 'AST-DEPT', 'room_id' => $roomId]);

        // room_asset items carry no department_id concept, so the direct
        // branch is skipped entirely under a department filter rather than
        // silently matching everything or nothing.
        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking?department_id=' . $deptId);

        $response->assertOk();
        $assetCodes = collect($response->json('data.rows'))->pluck('asset_code')->filter()->all();

        $this->assertSame([], $assetCodes);
    }

    public function test_index_filter_options_include_room_with_only_direct_deployment(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $directOnlyRoom = $this->seedRoom(['name' => 'Direct Only Room']);
        $this->seedRoomAssetItem(['name' => 'Server Rack', 'asset_code' => 'AST-RACK', 'room_id' => $directOnlyRoom]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking');

        $response->assertOk();
        $roomNames = collect($response->json('data.filter_options.rooms'))->pluck('name')->all();

        $this->assertContains('Direct Only Room', $roomNames);
    }

    public function test_index_forbidden_for_maintenance_staff_even_with_direct_deployments_present(): void
    {
        // Confirms the RBAC gate (unchanged by this task) still applies once
        // the response can include direct room deployments.
        $userId = $this->seedUser(['role' => 'maintenance_staff']);
        $roomId = $this->seedRoom(['name' => 'Room RBAC']);
        $this->seedRoomAssetItem(['name' => 'Scanner', 'asset_code' => 'AST-RBAC', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/deployment-tracking');

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // search() — OR-number partial match
    // -----------------------------------------------------------------

    public function test_search_returns_empty_when_no_receipt_matches(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $this->seedReceipt($userId, ['or_number' => 'OR-1234']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking/search?q=NO-MATCH-XYZ');

        $response->assertOk();
        $this->assertSame([], $response->json('data.rows'));
        $this->assertSame([], $response->json('data.receipts'));
    }

    public function test_search_returns_null_dispatch_columns_when_item_not_yet_deployed(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $receiptId = $this->seedReceipt($userId, ['or_number' => 'OR-5001']);
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId,
            'item_id' => null,
            'item_name' => 'Undeployed Widget',
            'quantity_received' => 5,
            'unit' => 'pc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking/search?q=OR-5001');

        $response->assertOk();
        $rows = $response->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Undeployed Widget', $rows[0]['receipt_item_name']);
        $this->assertNull($rows[0]['dispatch_id']);
        $this->assertNull($rows[0]['dispatched_qty']);
    }

    public function test_search_returns_one_row_per_dispatch_for_deployed_items(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Deployed Widget']);
        $receiptId = $this->seedReceipt($userId, ['or_number' => 'OR-6002']);
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId,
            'item_id' => $itemId,
            'item_name' => 'Deployed Widget',
            'quantity_received' => 5,
            'unit' => 'pc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedDispatch($itemId, 2, ['dispatch_code' => 'DSP-FIRST', 'status' => 'released']);
        $this->seedDispatch($itemId, 3, ['dispatch_code' => 'DSP-SECOND', 'status' => 'released']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking/search?q=OR-6002');

        $response->assertOk();
        $rows = $response->json('data.rows');

        $this->assertCount(2, $rows);
        $dispatchCodes = collect($rows)->pluck('dispatch_code')->sort()->values()->all();
        $this->assertSame(['DSP-FIRST', 'DSP-SECOND'], $dispatchCodes);
    }

    public function test_search_matches_or_number_partially(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $this->seedReceipt($userId, ['or_number' => 'OR-PARTIAL-MATCH-777']);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->getJson('/api/deployment-tracking/search?q=PARTIAL-MATCH');

        $response->assertOk();
        $orNumbers = collect($response->json('data.receipts'))->pluck('or_number')->all();

        $this->assertContains('OR-PARTIAL-MATCH-777', $orNumbers);
    }

    // -----------------------------------------------------------------
    // Schema / seeding
    // -----------------------------------------------------------------

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();

        // asset_code is now built into BuildsSharedTestSchema::createItemsTable()
        // itself (added for the Per Room Report / deployed-items fix), so the
        // additive Schema::table() patch that used to live here would now
        // collide with a duplicate column definition.

        $this->createActivityLogsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        // Minimal standalone rooms table (no buildings/floors FKs) — the
        // controller only ever needs r.id / r.name for its LEFT JOINs.
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('purchase_receipts', function ($table): void {
            $table->bigIncrements('id');
            $table->string('or_number')->unique();
            $table->date('receipt_date');
            $table->string('supplier_name');
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('received_by');
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('proof_image')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('inventory_room_id')->nullable();
            $table->integer('quantity_received');
            $table->string('unit', 20)->default('pc');
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Test Room',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedReceipt(int $receivedBy, array $overrides = []): int
    {
        return DB::table('purchase_receipts')->insertGetId(array_merge([
            'or_number' => 'OR-' . uniqid('', true),
            'receipt_date' => '2026-07-14',
            'supplier_name' => 'Test Supplier',
            'department_id' => null,
            'received_by' => $receivedBy,
            'remarks' => null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * TASK 36 PHASE 2 — seeds a room_asset `items` row shaped the way
     * InventoryStockController::createOrUpdateRoomAsset() actually creates
     * one (item_type='room_asset', quantity=1, unique asset_code, room_id).
     * This is the "direct" deployment record itself — see
     * DeploymentTrackingController::index() for why the query reads from
     * `items` directly rather than `inventory_transactions`.
     */
    private function seedRoomAssetItem(array $overrides = []): int
    {
        return $this->seedItem(array_merge([
            'item_type' => 'room_asset',
            'quantity' => 1,
            'status' => 'available',
        ], $overrides));
    }

    private function seedDispatch(int $itemId, int $quantity, array $overrides = []): int
    {
        $dispatchId = DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid('', true),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        DB::table('dispatch_items')->insert([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $dispatchId;
    }
}
