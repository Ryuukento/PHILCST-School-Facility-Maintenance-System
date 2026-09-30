<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 35 — BUILDINGS OVERVIEW ROLE-BASED ACCESS CONTROL.
 *
 * routes/web.php previously allowed maintenance_admin (Head Maintenance) and
 * maintenance_staff to create/update/delete buildings and floors
 * (EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff'),
 * and allowed maintenance_admin to create/update/delete rooms
 * (EnsureRole::class . ':super_admin,maintenance_admin') — a direct API/URL
 * bypass regardless of what the frontend showed or hid. Per this task, only
 * Administrator (super_admin) may mutate building/floor/room records; Head
 * Maintenance and Maintenance Staff are view-only. The fix narrows the
 * EnsureRole role list on the 8 mutating routes to 'super_admin' only — same
 * middleware used everywhere else in this file, no new authorization system.
 *
 * View (GET) routes for buildings/floors/rooms carry no EnsureRole
 * middleware and are unchanged — any authenticated user may view.
 */
class BuildingsRbacTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('buildings_rbac_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // ------------------------------------------------------------------
    // VIEW routes — allowed for all three roles
    // ------------------------------------------------------------------

    public function test_super_admin_can_view_buildings(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/buildings')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_head_maintenance_can_view_buildings(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->getJson('/api/buildings')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_maintenance_staff_can_view_buildings(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/buildings')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_head_maintenance_can_view_floors(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->getJson("/api/buildings/{$buildingId}/floors")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_maintenance_staff_can_view_rooms(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/rooms')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ------------------------------------------------------------------
    // GET /api/buildings/deployed-items — TASK 54
    //
    // Task 51 flagged that this endpoint had no dedicated RBAC test. It
    // carries no EnsureRole and BuildingController::deployedItems() has no
    // role check of its own, so every authenticated role reaches it. These
    // tests exist to pin that as DELIBERATE rather than an oversight, and
    // to make the boundary that justifies it explicit.
    //
    // Why it is correct that this endpoint is open to all three roles while
    // GET /api/deployment-tracking is restricted to super_admin +
    // maintenance_admin (DeploymentTrackingController::ALLOWED_ROLES):
    // the two expose different CLASSES of data, not different slices of
    // the same data. deployedItems() returns facility-location facts —
    // which item, how many, in which room/building — the same class of
    // information GET /api/buildings, /api/rooms and /api/items already
    // serve to all three roles. DeploymentTrackingController additionally
    // returns PROCUREMENT data (source_or, source_supplier,
    // source_receipt_date), which is what earns it the tighter gate.
    // Maintenance Staff need to know what equipment is in a room to do
    // their job; they do not need its purchase-order trail.
    //
    // So this is intentionally asymmetric and must NOT be "harmonized".
    // The last test below is the guard that keeps it honest: if anyone
    // later widens the SELECT to include procurement columns, this
    // endpoint's open access stops being justified — and that test fails
    // rather than silently leaking supplier/OR data to every role.
    // ------------------------------------------------------------------

    public function test_super_admin_can_view_deployed_items(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/buildings/deployed-items')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_head_maintenance_can_view_deployed_items(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->getJson('/api/buildings/deployed-items')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_maintenance_staff_can_view_deployed_items(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/buildings/deployed-items')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_unauthenticated_request_cannot_view_deployed_items(): void
    {
        $this->getJson('/api/buildings/deployed-items')->assertStatus(401);
    }

    /**
     * The endpoint only surfaces RELEASED dispatches (`d.status =
     * 'released'`), so an unreleased dispatch's contents must not appear —
     * this is a data-exposure boundary, not merely a display filter.
     */
    public function test_deployed_items_excludes_dispatches_that_are_not_released(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId);

        $releasedItemId = $this->seedItem(['name' => 'Released Projector']);
        $pendingItemId = $this->seedItem(['name' => 'Pending Projector']);

        $releasedDispatchId = $this->seedDispatch($roomId, ['status' => 'released']);
        $pendingDispatchId = $this->seedDispatch($roomId, ['status' => 'pending']);

        $this->seedDispatchItem($releasedDispatchId, $releasedItemId);
        $this->seedDispatchItem($pendingDispatchId, $pendingItemId);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/buildings/deployed-items')
            ->assertOk();

        $names = array_column($response->json('data.items'), 'item_name');

        $this->assertContains('Released Projector', $names);
        $this->assertNotContains('Pending Projector', $names, 'Unreleased dispatch contents must not be exposed.');
    }

    /**
     * Guards the justification for this endpoint's open access (see the
     * block comment above): it must stay free of the procurement columns
     * that DeploymentTrackingController restricts to super_admin +
     * maintenance_admin. If a future change adds them here, the role gate
     * would need to be reconsidered — so fail loudly instead.
     */
    public function test_deployed_items_does_not_expose_procurement_data(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId);
        $itemId = $this->seedItem(['name' => 'Deployed Item']);
        $dispatchId = $this->seedDispatch($roomId, ['status' => 'released']);
        $this->seedDispatchItem($dispatchId, $itemId);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/buildings/deployed-items')
            ->assertOk();

        $rows = $response->json('data.items');
        $this->assertNotEmpty($rows, 'Fixture must produce a row for this assertion to mean anything.');

        foreach ($rows as $row) {
            foreach (['source_or', 'or_number', 'source_supplier', 'supplier_name', 'source_receipt_date'] as $procurementField) {
                $this->assertArrayNotHasKey(
                    $procurementField,
                    (array) $row,
                    "deployed-items is open to all roles and must not expose procurement field '{$procurementField}'."
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Administrator (super_admin) — full management access retained
    // ------------------------------------------------------------------

    public function test_super_admin_can_create_building(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/buildings', ['name' => 'Science Wing', 'description' => 'New wing'])
            ->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_update_building(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding(['name' => 'Old Name']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/buildings/{$buildingId}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('New Name', DB::table('buildings')->where('id', $buildingId)->value('name'));
    }

    public function test_super_admin_can_delete_building(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->deleteJson("/api/buildings/{$buildingId}")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_create_floor(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/buildings/{$buildingId}/floors", ['name' => '1st Floor'])
            ->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_delete_floor(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->deleteJson("/api/buildings/{$buildingId}/floors/{$floorId}")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_create_room(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/rooms', [
                'building_id' => $buildingId,
                'floor_id' => $floorId,
                'name' => 'Room 101',
            ])
            ->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_update_room(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId, ['name' => 'Old Room']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/rooms/{$roomId}", ['name' => 'New Room'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_delete_room(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->deleteJson("/api/rooms/{$roomId}")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Head Maintenance (maintenance_admin) — view-only, 403 on mutation
    // ------------------------------------------------------------------

    public function test_head_maintenance_cannot_create_building(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/buildings', ['name' => 'Unauthorized Building'])
            ->assertStatus(403);
    }

    public function test_head_maintenance_cannot_update_building(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding(['name' => 'Untouched']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/buildings/{$buildingId}", ['name' => 'Hacked Name'])
            ->assertStatus(403);

        $this->assertSame('Untouched', DB::table('buildings')->where('id', $buildingId)->value('name'));
    }

    public function test_head_maintenance_cannot_delete_building(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->deleteJson("/api/buildings/{$buildingId}")
            ->assertStatus(403);

        $this->assertDatabaseHasBuilding($buildingId);
    }

    public function test_head_maintenance_cannot_create_floor(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson("/api/buildings/{$buildingId}/floors", ['name' => 'Unauthorized Floor'])
            ->assertStatus(403);
    }

    public function test_head_maintenance_cannot_delete_floor(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->deleteJson("/api/buildings/{$buildingId}/floors/{$floorId}")
            ->assertStatus(403);

        $this->assertSame(1, DB::table('floors')->where('id', $floorId)->count());
    }

    public function test_head_maintenance_cannot_create_room(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/rooms', [
                'building_id' => $buildingId,
                'floor_id' => $floorId,
                'name' => 'Unauthorized Room',
            ])
            ->assertStatus(403);
    }

    public function test_head_maintenance_cannot_update_room(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId, ['name' => 'Untouched Room']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson("/api/rooms/{$roomId}", ['name' => 'Hacked Room'])
            ->assertStatus(403);

        $this->assertSame('Untouched Room', DB::table('rooms')->where('id', $roomId)->value('name'));
    }

    public function test_head_maintenance_cannot_delete_room(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->deleteJson("/api/rooms/{$roomId}")
            ->assertStatus(403);

        $this->assertSame(1, DB::table('rooms')->where('id', $roomId)->count());
    }

    // ------------------------------------------------------------------
    // Maintenance Staff — view-only, 403 on mutation
    // ------------------------------------------------------------------

    public function test_maintenance_staff_cannot_create_building(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/buildings', ['name' => 'Unauthorized Building'])
            ->assertStatus(403);
    }

    public function test_maintenance_staff_cannot_update_building(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding(['name' => 'Untouched']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/buildings/{$buildingId}", ['name' => 'Hacked Name'])
            ->assertStatus(403);

        $this->assertSame('Untouched', DB::table('buildings')->where('id', $buildingId)->value('name'));
    }

    public function test_maintenance_staff_cannot_delete_building(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->deleteJson("/api/buildings/{$buildingId}")
            ->assertStatus(403);

        $this->assertDatabaseHasBuilding($buildingId);
    }

    public function test_maintenance_staff_cannot_create_floor(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson("/api/buildings/{$buildingId}/floors", ['name' => 'Unauthorized Floor'])
            ->assertStatus(403);
    }

    public function test_maintenance_staff_cannot_delete_floor(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->deleteJson("/api/buildings/{$buildingId}/floors/{$floorId}")
            ->assertStatus(403);

        $this->assertSame(1, DB::table('floors')->where('id', $floorId)->count());
    }

    public function test_maintenance_staff_cannot_create_room(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/rooms', [
                'building_id' => $buildingId,
                'floor_id' => $floorId,
                'name' => 'Unauthorized Room',
            ])
            ->assertStatus(403);
    }

    public function test_maintenance_staff_cannot_update_room(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId, ['name' => 'Untouched Room']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->patchJson("/api/rooms/{$roomId}", ['name' => 'Hacked Room'])
            ->assertStatus(403);

        $this->assertSame('Untouched Room', DB::table('rooms')->where('id', $roomId)->value('name'));
    }

    public function test_maintenance_staff_cannot_delete_room(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $buildingId = $this->seedBuilding();
        $floorId = $this->seedFloor($buildingId);
        $roomId = $this->seedRoom($buildingId, $floorId);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->deleteJson("/api/rooms/{$roomId}")
            ->assertStatus(403);

        $this->assertSame(1, DB::table('rooms')->where('id', $roomId)->count());
    }

    // ------------------------------------------------------------------
    // Unauthenticated — 401, not 403 (consistent with EnsureRole behavior
    // exercised elsewhere, e.g. AnalyticsRbacTest::test_unauthenticated_request_is_denied)
    // ------------------------------------------------------------------

    public function test_unauthenticated_request_to_create_building_is_denied(): void
    {
        $this->postJson('/api/buildings', ['name' => 'No Session'])
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Schema + seed helpers
    // ------------------------------------------------------------------

    private function assertDatabaseHasBuilding(int $buildingId): void
    {
        $this->assertSame(1, DB::table('buildings')->where('id', $buildingId)->count());
    }

    private function seedBuilding(array $overrides = []): int
    {
        return DB::table('buildings')->insertGetId(array_merge([
            'name' => 'Main Building ' . uniqid('', true),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedFloor(int $buildingId, array $overrides = []): int
    {
        return DB::table('floors')->insertGetId(array_merge([
            'building_id' => $buildingId,
            'name' => '1st Floor ' . uniqid('', true),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedRoom(int $buildingId, int $floorId, array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'name' => 'Room ' . uniqid('', true),
            'capacity' => null,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedDispatch(int $roomId, array $overrides = []): int
    {
        return DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid('', true),
            'room_id' => $roomId,
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedDispatchItem(int $dispatchId, int $itemId, int $quantity = 1): int
    {
        return DB::table('dispatch_items')->insertGetId([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_categories');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();

        // TASK 17/18 — BuildingController::update()/destroy() and
        // destroyFloor(), and RoomController::destroy(), now log to
        // activity_logs via ActivityLogService. This fixture predates that
        // feature, so mutation tests 500'd on "no such table: activity_logs".
        $this->createActivityLogsTable();

        Schema::create('buildings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('floors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });

        $this->createItemsTable();

        // TASK 54 — deployedItems() joins dispatches/dispatch_items/items and
        // LEFT JOINs inventory_categories, so its RBAC tests need these too.
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }
}
