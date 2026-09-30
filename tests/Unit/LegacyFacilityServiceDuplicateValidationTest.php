<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * TASK 55 — Security & Input Validation Hardening.
 *
 * public/backend/services/FacilityService.php is a plain procedural-style
 * PHP class sitting behind public/backend/controllers/FacilityController.php
 * (a legacy endpoint dispatcher, not routed through Laravel — see
 * tests/Unit/LegacyNotificationModelOwnershipTest.php for the identical
 * testing approach used for Task 52's Notification model fix). It cannot be
 * exercised through Laravel's HTTP test client, so this test instantiates
 * the real FacilityService/Building/Floor/Room/Item/InventoryCategory
 * classes directly against a real PDO SQLite connection (mirroring how
 * public/backend/bootstrap.php hands FacilityService a real PDO instance in
 * production), exactly like LegacyNotificationModelOwnershipTest already
 * does for Notification.php. NOW() has no SQLite equivalent — the same
 * MySQL-only-function gap documented in
 * tests/Feature/InventoryStockUpdateQuantityBypassTest.php — so it is
 * registered via PDO::sqliteCreateFunction() exactly as that test does;
 * this is test-only scaffolding and changes no application code.
 *
 * Two independent but structurally identical defects were found and fixed:
 *
 * DEFECT A — FacilityService::createRoom()/updateRoom() only checked
 * Room::existsByFloorAndName(), which mirrors HALF of the real DB
 * constraint. The `rooms` table (2026_03_27_000300_create_facility_tables)
 * carries a SECOND, independent unique(building_id, name) index spanning
 * every floor of the building. A legitimate room name reused on a
 * DIFFERENT floor of the SAME building passed the floor-scoped check and
 * then hit an uncaught PDOException on the building-scoped unique index at
 * INSERT time.
 *
 * DEFECT B — FacilityService::createInventoryCategory()/
 * updateInventoryCategory() only checked InventoryCategory::existsByName().
 * inventory_categories.code carries its OWN unique index, independent of
 * `name` (2026_04_07_001000_add_inventory_categories_and_thresholds) — and
 * `code` is a real, live field on the Category admin form
 * (categoryCodeInput in public/frontend/pages/inventory.php), not merely an
 * API-only concern. Reusing a code under a different name passed the
 * name-only check and then hit the same class of uncaught PDOException on
 * the code's unique index.
 *
 * In BOTH cases, before this fix, the resulting PDOException was caught by
 * a generic `catch (Exception $e)` block that returned
 * 'Database error: ' . $e->getMessage() directly in the JSON HTTP response
 * — echoing the raw SQLSTATE code and driver detail straight to the client,
 * unlike every sibling update()/delete() method in the same file (which
 * already used a generic message with no $e->getMessage() interpolation).
 * This test proves both the validation gap (wrong status code, silently
 * inconsistent data) and the information-disclosure risk (raw exception
 * text reaching the client) in one place, and pins the fixed behavior:
 * a clean 409 conflict with a generic, specific message and zero raw
 * exception detail.
 */
class LegacyFacilityServiceDuplicateValidationTest extends TestCase
{
    private PDO $pdo;
    /** @var object */
    private $facilityService;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../public/backend/utils/Response.php';
        require_once __DIR__ . '/../../public/backend/utils/Logger.php';
        require_once __DIR__ . '/../../public/backend/models/Building.php';
        require_once __DIR__ . '/../../public/backend/models/Floor.php';
        require_once __DIR__ . '/../../public/backend/models/Room.php';
        require_once __DIR__ . '/../../public/backend/models/Item.php';
        require_once __DIR__ . '/../../public/backend/models/InventoryCategory.php';
        require_once __DIR__ . '/../../public/backend/services/FacilityService.php';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);

        $this->pdo->exec('
            CREATE TABLE buildings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE floors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                building_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE(building_id, name)
            )
        ');

        // Mirrors 2026_03_27_000300_create_facility_tables.php exactly: TWO
        // independent unique indexes, one per-floor and one building-wide.
        $this->pdo->exec('
            CREATE TABLE rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                building_id INTEGER NOT NULL,
                floor_id INTEGER NULL,
                name TEXT NOT NULL,
                capacity INTEGER NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE(building_id, name),
                UNIQUE(floor_id, name)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id INTEGER NOT NULL,
                category_id INTEGER NULL,
                item_type TEXT NULL,
                name TEXT NOT NULL,
                status TEXT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                reserved_quantity INTEGER NOT NULL DEFAULT 0,
                reorder_level INTEGER NULL,
                low_stock_threshold_override INTEGER NULL,
                description TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE inventory_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                code TEXT NULL UNIQUE,
                default_low_stock_threshold INTEGER NULL,
                allow_threshold_override INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
        ');

        $_SESSION['user'] = ['user_id' => 1, 'role' => 'super_admin'];

        $this->facilityService = new \FacilityService($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user']);
        parent::tearDown();
    }

    private function seedBuilding(string $name): int
    {
        $result = $this->facilityService->createBuilding(['name' => $name]);
        $this->assertTrue($result['success'], 'Fixture building creation must succeed: ' . ($result['message'] ?? ''));

        return (int) $result['id'];
    }

    private function seedFloor(int $buildingId, string $name): int
    {
        $result = $this->facilityService->createFloor(['building_id' => $buildingId, 'name' => $name]);
        $this->assertTrue($result['success'], 'Fixture floor creation must succeed: ' . ($result['message'] ?? ''));

        return (int) $result['id'];
    }

    /**
     * DEFECT A (fixed) — a room name reused on a DIFFERENT floor of the SAME
     * building must be rejected as a clean 409 conflict, not crash.
     */
    public function test_creating_a_room_with_a_name_already_used_on_another_floor_of_the_same_building_is_rejected_cleanly(): void
    {
        $buildingId = $this->seedBuilding('Main Hall');
        $floorA = $this->seedFloor($buildingId, 'Floor A');
        $floorB = $this->seedFloor($buildingId, 'Floor B');

        $first = $this->facilityService->createRoom([
            'building_id' => $buildingId,
            'floor_id' => $floorA,
            'name' => 'Room 101',
        ]);
        $this->assertTrue($first['success']);

        $second = $this->facilityService->createRoom([
            'building_id' => $buildingId,
            'floor_id' => $floorB,
            'name' => 'Room 101',
        ]);

        $this->assertFalse($second['success'], 'A cross-floor duplicate room name in the same building must be rejected.');
        $this->assertSame(409, $second['code'] ?? null, 'Must be a clean conflict, not an uncaught-exception 500.');
        $this->assertStringNotContainsString('SQLSTATE', $second['message'] ?? '', 'The raw PDO exception message must never reach the client.');
        $this->assertStringNotContainsString('Database error', $second['message'] ?? '', 'The raw PDO exception message must never reach the client.');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rooms WHERE building_id = ? AND name = ?');
        $stmt->execute([$buildingId, 'Room 101']);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'The rejected duplicate must not have been inserted.');
    }

    /**
     * Positive control — a same-named room in a DIFFERENT building must
     * still be allowed (proves the fix did not over-broaden the check
     * beyond what the DB constraint actually requires).
     */
    public function test_creating_a_room_with_the_same_name_in_a_different_building_still_succeeds(): void
    {
        $buildingA = $this->seedBuilding('Building A');
        $buildingB = $this->seedBuilding('Building B');
        $floorInA = $this->seedFloor($buildingA, 'Ground Floor');
        $floorInB = $this->seedFloor($buildingB, 'Ground Floor');

        $first = $this->facilityService->createRoom([
            'building_id' => $buildingA,
            'floor_id' => $floorInA,
            'name' => 'Faculty Room',
        ]);
        $second = $this->facilityService->createRoom([
            'building_id' => $buildingB,
            'floor_id' => $floorInB,
            'name' => 'Faculty Room',
        ]);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success'], 'Same room name in a different building must not be blocked.');
    }

    /**
     * DEFECT A (fixed), update path — updateRoom() carried the identical gap.
     */
    public function test_updating_a_room_to_a_name_used_on_another_floor_of_the_same_building_is_rejected_cleanly(): void
    {
        $buildingId = $this->seedBuilding('Science Building');
        $floorA = $this->seedFloor($buildingId, 'Floor 1');
        $floorB = $this->seedFloor($buildingId, 'Floor 2');

        $this->facilityService->createRoom(['building_id' => $buildingId, 'floor_id' => $floorA, 'name' => 'Lab 1']);
        $roomOnB = $this->facilityService->createRoom(['building_id' => $buildingId, 'floor_id' => $floorB, 'name' => 'Lab 2']);

        $update = $this->facilityService->updateRoom([
            'id' => $roomOnB['id'],
            'building_id' => $buildingId,
            'floor_id' => $floorB,
            'name' => 'Lab 1',
        ]);

        $this->assertFalse($update['success']);
        $this->assertSame(409, $update['code'] ?? null);
        $this->assertStringNotContainsString('SQLSTATE', $update['message'] ?? '');
    }

    /**
     * DEFECT B (fixed) — a category `code` reused under a different `name`
     * must be rejected as a clean 409 conflict, not crash.
     */
    public function test_creating_an_inventory_category_with_a_code_already_used_by_another_category_is_rejected_cleanly(): void
    {
        $first = $this->facilityService->createInventoryCategory([
            'name' => 'Consumables',
            'code' => 'consumables',
        ]);
        $this->assertTrue($first['success'], 'Fixture category creation must succeed: ' . ($first['message'] ?? ''));

        $second = $this->facilityService->createInventoryCategory([
            'name' => 'Consumable Supplies',
            'code' => 'consumables',
        ]);

        $this->assertFalse($second['success'], 'A duplicate category code under a different name must be rejected.');
        $this->assertSame(409, $second['code'] ?? null, 'Must be a clean conflict, not an uncaught-exception 500.');
        $this->assertStringNotContainsString('SQLSTATE', $second['message'] ?? '', 'The raw PDO exception message must never reach the client.');
        $this->assertStringNotContainsString('Database error', $second['message'] ?? '', 'The raw PDO exception message must never reach the client.');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_categories WHERE code = ?');
        $stmt->execute(['consumables']);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'The rejected duplicate must not have been inserted.');
    }

    /**
     * DEFECT B (fixed), update path — updateInventoryCategory() carried the
     * identical gap.
     */
    public function test_updating_an_inventory_category_to_a_code_used_by_another_category_is_rejected_cleanly(): void
    {
        $first = $this->facilityService->createInventoryCategory(['name' => 'Equipment', 'code' => 'equipment']);
        $second = $this->facilityService->createInventoryCategory(['name' => 'Fixtures', 'code' => 'fixtures']);

        $update = $this->facilityService->updateInventoryCategory([
            'id' => $second['id'],
            'name' => 'Fixtures',
            'code' => 'equipment',
        ]);

        $this->assertFalse($update['success']);
        $this->assertSame(409, $update['code'] ?? null);
        $this->assertStringNotContainsString('SQLSTATE', $update['message'] ?? '');
    }

    /**
     * Positive control — a category may keep its OWN existing code across
     * an update (proves $excludeId is wired correctly and the fix does not
     * make a no-op update on `code` impossible).
     */
    public function test_updating_an_inventory_category_without_changing_its_own_code_still_succeeds(): void
    {
        $created = $this->facilityService->createInventoryCategory(['name' => 'Consumables', 'code' => 'consumables']);

        $update = $this->facilityService->updateInventoryCategory([
            'id' => $created['id'],
            'name' => 'Consumables',
            'code' => 'consumables',
            'sort_order' => 5,
        ]);

        $this->assertTrue($update['success'], 'A category must be able to keep its own code unchanged: ' . ($update['message'] ?? ''));
    }
}
