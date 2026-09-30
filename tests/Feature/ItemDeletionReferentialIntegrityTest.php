<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 50 — regression coverage for a real defect found while auditing
 * ItemController::destroy() (DELETE /api/items/{item}) against the actual
 * production schema: the production database (MySQL, see
 * database/migrations/2026_05_15_000400_create_dispatches_and_items.php and
 * .../2026_05_15_000500_create_damage_reports_tables.php) declares
 * `dispatch_items.item_id` and `damage_reports.item_id` as foreign keys with
 * ->onDelete('restrict') — i.e. the database itself refuses to delete an
 * Item row that either table still references.
 *
 * ItemController::destroy(), however, only pre-checks `inventory_transactions`
 * before calling $item->delete(). An item can be referenced by:
 *   - a `dispatch_items` row from the moment a Dispatch is *created*
 *     (DispatchService::createDispatch()) — no InventoryTransaction is
 *     written until releaseDispatch() actually hands the stock over, so a
 *     brand-new item with zero inventory_transactions can already sit inside
 *     a pending/approved dispatch; or
 *   - a `damage_reports` row for a `room_asset`-type item — DamageReportService
 *     ::validateDeployedItem() checks room_asset items via item->room_id
 *     instead of requiring a prior 'deploy' InventoryTransaction, so a
 *     room_asset item can be damage-reported with zero inventory_transactions
 *     ever existing for it.
 *
 * In both cases the controller's guard sees no inventory_transactions, lets
 * $item->delete() proceed, and the database's restrict constraint throws an
 * uncaught QueryException — an uncontrolled HTTP 500 instead of the clean,
 * validated 400 response every other reference-check in this controller
 * (and its sibling InventoryCategoryController::destroy()) produces.
 *
 * These tests run against an isolated in-memory SQLite connection with
 * foreign_key_constraints genuinely ENABLED (unlike the rest of this suite,
 * which deliberately disables them — see ConfiguresIsolatedSqliteConnection)
 * specifically so the dispatch_items/damage_reports restrict FKs are
 * enforced here exactly as they are in production, making this a faithful
 * reproduction rather than a documentation-only test.
 *
 * Fixed by extending ItemController::destroy() to also pre-check
 * `dispatch_items` and `damage_reports` for the item_id, returning the same
 * clean 400 response pattern already used for inventory_transactions.
 */
class ItemDeletionReferentialIntegrityTest extends TestCase
{
    use BuildsSharedTestSchema;
    use InteractsWithLegacySession;

    private const CONNECTION = 'item_deletion_referential_integrity_testing';

    protected function setUp(): void
    {
        parent::setUp();

        // Deliberately mirrors ConfiguresIsolatedSqliteConnection::useInMemoryDatabase()
        // except foreign_key_constraints is left ON, so this connection enforces
        // the real onDelete('restrict') behaviour the production MySQL schema has.
        Config::set('session.driver', 'array');
        Config::set('cache.default', 'array');
        Config::set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        Config::set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        DB::reconnect(self::CONNECTION);

        Config::set('app.url', 'http://localhost');
        app('url')->forceRootUrl('http://localhost');

        $this->createTestSchema();
    }

    public function test_deleting_item_referenced_by_a_pending_dispatch_returns_a_clean_error_not_a_server_error(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'HDMI Cable', 'quantity' => 5]);

        // Zero inventory_transactions rows for this item — the dispatch has
        // been created but not yet released, exactly like
        // DispatchService::createDispatch() leaves things.
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());

        $dispatchId = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'DSP-TEST1',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dispatch_items')->insert([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->deleteJson("/api/items/{$itemId}");

        $response->assertStatus(400);
        $this->assertStringContainsString('dispatch', strtolower((string) $response->json('message')));

        // The item must still exist — the delete must have been refused
        // cleanly by the controller, not attempted and rolled back by a
        // database-level exception.
        $this->assertNotNull(DB::table('items')->where('id', $itemId)->first());
    }

    public function test_deleting_room_asset_item_referenced_by_a_damage_report_returns_a_clean_error_not_a_server_error(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $itemId = $this->seedItem([
            'name' => 'Projector Unit',
            'item_type' => 'room_asset',
            'room_id' => 1,
            'quantity' => 1,
        ]);

        // Zero inventory_transactions rows for this item — room_asset items
        // can be damage-reported without ever having a 'deploy' transaction
        // (DamageReportService::validateDeployedItem() branches on item_type).
        $this->assertSame(0, DB::table('inventory_transactions')->where('item_id', $itemId)->count());

        DB::table('damage_reports')->insert([
            'damage_report_code' => 'DMG-TEST1',
            'item_id' => $itemId,
            'damage_description' => 'Cracked lens.',
            'severity_level' => 'medium',
            'reported_by' => $adminId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->deleteJson("/api/items/{$itemId}");

        $response->assertStatus(400);
        $this->assertStringContainsString('damage report', strtolower((string) $response->json('message')));

        $this->assertNotNull(DB::table('items')->where('id', $itemId)->first());
    }

    public function test_deleting_unreferenced_item_still_succeeds(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 3]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->deleteJson("/api/items/{$itemId}");

        $response->assertOk();
        $this->assertNull(DB::table('items')->where('id', $itemId)->first());
    }

    private function createTestSchema(): void
    {
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        Schema::create('dispatches', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('dispatch_code', 50)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });

        Schema::create('dispatch_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('dispatch_id');
            $table->unsignedInteger('item_id');
            $table->integer('quantity');
            $table->timestamps();

            $table->foreign('dispatch_id')->references('id')->on('dispatches')->onDelete('cascade');
            // Mirrors the real, production-only restrict FK — this is the
            // constraint ItemController::destroy() must not let the database
            // enforce on its behalf via an uncaught exception.
            $table->foreign('item_id')->references('id')->on('items')->onDelete('restrict');
        });

        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('damage_report_code', 50)->unique();
            $table->unsignedInteger('item_id');
            $table->text('damage_description');
            $table->string('severity_level', 20)->default('medium');
            $table->unsignedInteger('reported_by');
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            // Mirrors the real, production-only restrict FK.
            $table->foreign('item_id')->references('id')->on('items')->onDelete('restrict');
        });
    }
}
