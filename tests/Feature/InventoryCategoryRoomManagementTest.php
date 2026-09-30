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
 * Task 77 (Inventory audit) Part 5 — fills genuine coverage gaps identified
 * during the audit that the existing Inventory test files do not exercise:
 *
 *   - RBAC on the item/category/room mutation routes: no existing test
 *     asserted that 'maintenance_staff' is rejected (403) from
 *     POST/PATCH/DELETE /api/items, /api/inventory-categories, or
 *     /api/inventory-rooms, nor that 'maintenance_admin' is accepted (201) —
 *     which matters because the audit separately found the *frontend*
 *     (public/frontend/pages/inventory.php) hides the Add Item / Manage
 *     Categories UI from maintenance_admin even though these backend routes
 *     grant it. These tests pin down the backend's actual, current behavior
 *     as a regression baseline (documented, not changed — see audit report).
 *   - InventoryCategoryController/InventoryRoomController integrity guards:
 *     duplicate-name rejection (409) and delete-blocked-while-referenced
 *     (409) had no Feature test coverage at all.
 */
class InventoryCategoryRoomManagementTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('inventory_category_room_testing');
        $this->createTestSchema();

        // See ConfiguresIsolatedSqliteConnection::forceLocalTestUrl() for why
        // this is required for HTTP test requests in this environment.
        $this->forceLocalTestUrl();
    }

    // -----------------------------------------------------------------
    // Items — RBAC
    // -----------------------------------------------------------------

    public function test_maintenance_staff_is_forbidden_from_creating_items(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_staff')
            ->postJson('/api/items', [
                'name' => 'Stapler',
                'quantity' => 5,
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, DB::table('items')->where('name', 'Stapler')->count());
    }

    public function test_maintenance_admin_can_create_items(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/items', [
                'name' => 'Stapler',
                'quantity' => 5,
            ]);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('items')->where('name', 'Stapler')->count());
    }

    public function test_maintenance_staff_is_forbidden_from_updating_or_deleting_items(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Stapler', 'quantity' => 5]);

        $updateResponse = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_staff')
            ->patchJson("/api/items/{$itemId}", ['name' => 'Heavy Duty Stapler']);
        $updateResponse->assertStatus(403);

        $deleteResponse = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_staff')
            ->deleteJson("/api/items/{$itemId}");
        $deleteResponse->assertStatus(403);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame('Stapler', $item->name);
    }

    // -----------------------------------------------------------------
    // Inventory categories — RBAC + integrity guards
    // -----------------------------------------------------------------

    public function test_maintenance_staff_is_forbidden_from_creating_categories(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_staff')
            ->postJson('/api/inventory-categories', ['name' => 'Cleaning Supplies']);

        $response->assertStatus(403);
        $this->assertSame(0, DB::table('inventory_categories')->where('name', 'Cleaning Supplies')->count());
    }

    public function test_maintenance_admin_can_create_categories(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', ['name' => 'Cleaning Supplies']);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('inventory_categories')->where('name', 'Cleaning Supplies')->count());
    }

    public function test_creating_category_with_duplicate_name_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        DB::table('inventory_categories')->insert(['name' => 'Consumables', 'is_active' => 1, 'sort_order' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', ['name' => 'Consumables']);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('inventory_categories')->where('name', 'Consumables')->count());
    }

    /**
     * TASK 56 — Inventory Category API Validation Hardening.
     *
     * DEFECT: inventory_categories.code carries its OWN unique index,
     * independent of `name` (see
     * 2026_04_07_001000_add_inventory_categories_and_thresholds.php line 16:
     * `$table->string('code')->nullable()->unique()`, mirrored exactly by
     * createInventoryCategoriesTable() at the bottom of this file).
     * InventoryCategoryController::store() checked ONLY LOWER(name) for a
     * duplicate before calling insertGetId() with the code — so two
     * categories with different names but the same code passed every
     * application check and then violated the unique index at INSERT time,
     * throwing an uncaught QueryException instead of returning the clean 409
     * this controller already returns for a duplicate NAME.
     *
     * This is the same defect class Task 55 fixed in the parallel LEGACY
     * path (FacilityService::createInventoryCategory(), covered by
     * tests/Unit/LegacyFacilityServiceDuplicateValidationTest.php) — but
     * this Laravel route is the one the live UI actually calls
     * (CATEGORIES_API_BASE in public/frontend/pages/inventory.php), and
     * `code` is a live, editable form field there (categoryCodeInput) with
     * no client-side duplicate check, so this path was reachable by ordinary
     * admin use, not just by direct API calls.
     */
    public function test_creating_category_with_duplicate_code_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        DB::table('inventory_categories')->insert([
            'name' => 'Consumables', 'code' => 'consumables', 'is_active' => 1, 'sort_order' => 0,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', [
                'name' => 'Cleaning Supplies',
                'code' => 'consumables',
            ]);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('inventory_categories')->where('code', 'consumables')->count());
        $this->assertSame(0, DB::table('inventory_categories')->where('name', 'Cleaning Supplies')->count());
    }

    /**
     * TASK 56 — the code comparison must be case-insensitive to match the
     * controller's existing LOWER(name) duplicate check and the frontend,
     * which lowercases the code before sending it
     * (public/frontend/pages/inventory.php saveCategory()). A direct API
     * caller is under no such obligation.
     */
    public function test_creating_category_with_duplicate_code_in_different_case_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        DB::table('inventory_categories')->insert([
            'name' => 'Consumables', 'code' => 'consumables', 'is_active' => 1, 'sort_order' => 0,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', [
                'name' => 'Cleaning Supplies',
                'code' => 'CONSUMABLES',
            ]);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('inventory_categories')->whereRaw('LOWER(code) = ?', ['consumables'])->count());
    }

    /**
     * TASK 56 — same gap on the PATCH path, which had no duplicate-code
     * check either. The update() method's name check already excludes the
     * row's own id; the code check must do the same (see the self-code test
     * below).
     */
    public function test_updating_category_to_another_categorys_code_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        DB::table('inventory_categories')->insert([
            'name' => 'Consumables', 'code' => 'consumables', 'is_active' => 1, 'sort_order' => 0,
        ]);
        $targetId = DB::table('inventory_categories')->insertGetId([
            'name' => 'Equipment', 'code' => 'equipment', 'is_active' => 1, 'sort_order' => 0,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/inventory-categories/{$targetId}", [
                'name' => 'Equipment',
                'code' => 'consumables',
            ]);

        $response->assertStatus(409);
        $this->assertSame('equipment', DB::table('inventory_categories')->where('id', $targetId)->value('code'));
    }

    /**
     * TASK 56 — COUNTER-TEST. Proves the fix does not over-restrict: a
     * category saved without changing its own code must still succeed. The
     * "Manage Categories" UI resubmits every field on every save, so the
     * category's existing code is always present in the PATCH payload —
     * without self-exclusion this ordinary edit would break.
     */
    public function test_updating_category_without_changing_its_own_code_succeeds(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $categoryId = DB::table('inventory_categories')->insertGetId([
            'name' => 'Equipment', 'code' => 'equipment', 'is_active' => 1, 'sort_order' => 0,
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/inventory-categories/{$categoryId}", [
                'name' => 'Equipment & Tools',
                'code' => 'equipment',
                'default_low_stock_threshold' => 7,
            ]);

        $response->assertOk();
        $category = DB::table('inventory_categories')->where('id', $categoryId)->first();
        $this->assertSame('Equipment & Tools', $category->name);
        $this->assertSame('equipment', $category->code);
        $this->assertSame(7, (int) $category->default_low_stock_threshold);
    }

    /**
     * TASK 56 — COUNTER-TEST. A genuinely new code must still be accepted on
     * both create and update.
     */
    public function test_creating_and_updating_a_category_with_a_unique_code_succeeds(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        DB::table('inventory_categories')->insert([
            'name' => 'Consumables', 'code' => 'consumables', 'is_active' => 1, 'sort_order' => 0,
        ]);

        $createResponse = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', [
                'name' => 'Fixtures',
                'code' => 'fixtures',
            ]);
        $createResponse->assertStatus(201);

        $newId = (int) DB::table('inventory_categories')->where('name', 'Fixtures')->value('id');
        $this->assertSame('fixtures', DB::table('inventory_categories')->where('id', $newId)->value('code'));

        $updateResponse = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->patchJson("/api/inventory-categories/{$newId}", [
                'name' => 'Fixtures',
                'code' => 'building-fixtures',
            ]);
        $updateResponse->assertOk();
        $this->assertSame('building-fixtures', DB::table('inventory_categories')->where('id', $newId)->value('code'));
    }

    /**
     * TASK 56 — COUNTER-TEST, and the sharpest constraint on the fix's
     * shape. `code` is NULLABLE, and a unique index permits unlimited NULLs.
     * Categories without a code are ordinary and expected (the Code field on
     * the Manage Categories form is optional — note the absence of `required`
     * on categoryCodeInput, unlike categoryNameInput). A naive uniqueness
     * check that treats null/'' as a comparable value would reject the
     * SECOND code-less category and break existing behavior, so the guard
     * must short-circuit on an absent code exactly as the legacy
     * InventoryCategory::existsByCode() does.
     */
    public function test_multiple_categories_without_a_code_are_still_allowed(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $first = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', ['name' => 'Uncoded One']);
        $first->assertStatus(201);

        $second = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->postJson('/api/inventory-categories', ['name' => 'Uncoded Two', 'code' => '']);
        $second->assertStatus(201);

        $this->assertSame(2, DB::table('inventory_categories')->whereNull('code')->count());
    }

    public function test_deleting_category_referenced_by_an_item_is_blocked(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $categoryId = DB::table('inventory_categories')->insertGetId(['name' => 'Equipment', 'is_active' => 1, 'sort_order' => 0]);
        $this->seedItem(['name' => 'Drill', 'category_id' => $categoryId]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->deleteJson("/api/inventory-categories/{$categoryId}");

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('inventory_categories')->where('id', $categoryId)->count());
    }

    public function test_deleting_category_with_no_items_succeeds(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $categoryId = DB::table('inventory_categories')->insertGetId(['name' => 'Unused Category', 'is_active' => 1, 'sort_order' => 0]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->deleteJson("/api/inventory-categories/{$categoryId}");

        $response->assertOk();
        $this->assertSame(0, DB::table('inventory_categories')->where('id', $categoryId)->count());
    }

    // -----------------------------------------------------------------
    // Inventory rooms — REMOVED.
    //
    // This section used to cover RBAC + integrity guards on
    // POST/DELETE /api/inventory-rooms (InventoryRoomController). That
    // controller, its routes, the inventory_rooms table, and
    // items.inventory_room_id were deliberately deleted — see
    // database/migrations/2026_09_28_000200_drop_inventory_rooms_and_related_columns.php:
    // "Inventory is a single centralized stock pool -- this school does not
    // use separate inventory rooms." These 4 tests were left behind hitting
    // a route that now 404s; there is no "fix" that makes sense here since
    // the feature itself is gone, so the coverage was removed rather than
    // patched.
    // -----------------------------------------------------------------

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('inventory_categories');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createInventoryCategoriesTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createInventoryCategoriesTable(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->unsignedInteger('department_id')->nullable();
            $table->integer('default_low_stock_threshold')->nullable();
            $table->boolean('allow_threshold_override')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }
}
