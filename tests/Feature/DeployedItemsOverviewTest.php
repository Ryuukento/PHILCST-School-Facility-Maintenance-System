<?php

namespace Tests\Feature;

use App\Services\SmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class DeployedItemsOverviewTest extends TestCase
{
    use BuildsSharedTestSchema, ConfiguresIsolatedSqliteConnection, InteractsWithLegacySession;
    private array $nativeBefore;
    private int $admin;
    private int $building;
    private int $floor;
    private int $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('deployed_overview_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->forceLocalTestUrl();
        Log::swap(new NullLogger()); Http::preventStrayRequests(); Mail::fake(); Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? []; $_SESSION = [];
        $this->createUsersTable(); $this->createItemsTable(); $this->createDispatchesTable();
        $this->createDispatchItemsTable(); $this->createDeployedAssetsTable();
        Schema::create('buildings', function (Blueprint $table) { $table->increments('id'); $table->string('name'); });
        Schema::create('floors', function (Blueprint $table) { $table->increments('id'); $table->integer('building_id'); $table->string('name'); });
        Schema::create('rooms', function (Blueprint $table) {
            $table->increments('id'); $table->integer('building_id')->nullable(); $table->integer('floor_id')->nullable();
            $table->string('name'); $table->boolean('is_active')->default(true);
        });
        Schema::create('inventory_categories', function (Blueprint $table) { $table->increments('id'); $table->string('name'); });
        $this->admin = $this->seedUser(['role' => 'super_admin']);
        $this->building = DB::table('buildings')->insertGetId(['name' => 'Building A']);
        $this->floor = DB::table('floors')->insertGetId(['building_id' => $this->building, 'name' => 'Floor 1']);
        $this->room = DB::table('rooms')->insertGetId(['building_id' => $this->building, 'floor_id' => $this->floor, 'name' => 'Room A']);
    }

    protected function tearDown(): void { $_SESSION = $this->nativeBefore; parent::tearDown(); }

    public function test_union_quantities_and_additive_identity_fields_preserve_original_contract(): void
    {
        $item = $this->seedItem(['name' => 'Chair', 'quantity' => 99]);
        [$dispatch, $line] = $this->dispatch($item, 5);
        $direct = $this->seedItem(['name' => 'Chair', 'item_type' => 'room_asset', 'room_id' => $this->room, 'quantity' => 7]);
        $rows = $this->rows();
        $this->assertCount(2, $rows); $this->assertSame(12, array_sum(array_column($rows, 'quantity')));
        $dispatched = collect($rows)->firstWhere('source_type', 'dispatch');
        $recorded = collect($rows)->firstWhere('source_type', 'direct');
        $this->assertSame($dispatch, $dispatched['dispatch_id']); $this->assertSame($line, $dispatched['source_record_id']);
        $this->assertSame($item, $dispatched['item_id']); $this->assertSame($direct, $recorded['source_record_id']);
        $this->assertSame($direct, $recorded['item_id']); $this->assertNull($recorded['dispatch_id']);
        foreach ($rows as $row) {
            $this->assertSame($this->floor, $row['floor_id']); $this->assertSame('Floor 1', $row['floor_name']);
            foreach (['item_name', 'category', 'quantity', 'unit', 'condition', 'asset_code', 'status', 'date_dispatched', 'dispatch_code', 'room_name', 'building_name', 'building_id', 'room_id'] as $key) $this->assertArrayHasKey($key, $row);
            $this->assertArrayNotHasKey('dispatch_item_id', $row);
        }
    }

    public function test_non_released_dispatches_and_stock_quantity_are_not_counted(): void
    {
        $item = $this->seedItem(['name' => 'Fan', 'quantity' => 100]);
        foreach (['pending', 'approved', 'cancelled', 'rejected'] as $status) $this->dispatch($item, 10, $status);
        $this->dispatch($item, 3);
        $this->assertCount(1, $rows = $this->rows()); $this->assertSame(3, $rows[0]['quantity']);
    }

    public function test_asset_codes_enrich_a_line_without_multiplying_quantity_or_filtering_asset_states(): void
    {
        $item = $this->seedItem(['name' => 'Aircon', 'item_condition' => 'damaged']);
        [$dispatch, $line] = $this->dispatch($item, 3);
        foreach (['active', 'replaced', 'disposed'] as $n => $status) DB::table('deployed_assets')->insert([
            'asset_code' => 'SYNTHETIC-' . $n, 'code_source' => 'generated', 'item_id' => $item,
            'dispatch_id' => $dispatch, 'dispatch_item_id' => $line, 'room_id' => $this->room, 'status' => $status,
        ]);
        $rows = $this->rows(); $this->assertCount(1, $rows); $this->assertSame(3, $rows[0]['quantity']);
        $this->assertSame('damaged', $rows[0]['condition']);
        $this->assertSame('SYNTHETIC-0, SYNTHETIC-1, SYNTHETIC-2', $rows[0]['asset_code']);
    }

    public function test_inactive_and_unlocated_rows_remain_included_and_existing_filters_still_work(): void
    {
        DB::table('rooms')->where('id', $this->room)->update(['is_active' => false]);
        $this->seedItem(['name' => 'Old Chair', 'item_type' => 'room_asset', 'room_id' => $this->room, 'quantity' => 4]);
        $item = $this->seedItem(['name' => 'No Location']);
        $this->dispatch($item, 2, 'released', null);
        $rows = $this->rows(); $this->assertCount(2, $rows);
        $missing = collect($rows)->firstWhere('source_type', 'dispatch');
        foreach (['building_id', 'floor_id', 'room_id', 'building_name', 'floor_name', 'room_name'] as $field) $this->assertNull($missing[$field]);
        $filtered = $this->rows('?building_id=' . $this->building . '&room_id=' . $this->room);
        $this->assertCount(1, $filtered); $this->assertSame(4, $filtered[0]['quantity']);
    }

    public function test_complete_response_is_not_paginated_and_same_names_keep_separate_locations(): void
    {
        $other = DB::table('rooms')->insertGetId(['name' => 'Room B', 'building_id' => $this->building, 'floor_id' => null]);
        for ($n = 0; $n < 25; $n++) $this->seedItem(['name' => 'Chair', 'item_type' => 'room_asset', 'room_id' => $n % 2 ? $other : $this->room, 'quantity' => 2]);
        $rows = $this->rows('?per_page=1&page=2');
        $this->assertCount(25, $rows); $this->assertSame(50, array_sum(array_column($rows, 'quantity')));
        $this->assertCount(25, array_unique(array_column($rows, 'source_record_id')));
    }

    public function test_existing_read_authorization_is_unchanged(): void
    {
        $this->getJson('/api/buildings/deployed-items')->assertUnauthorized();
        foreach (['super_admin', 'maintenance_admin', 'maintenance_staff'] as $role) {
            $_SESSION = []; session()->flush();
            $id = $this->seedUser(['role' => $role]);
            $this->actingAsSessionUser($id, $role)->getJson('/api/buildings/deployed-items')->assertOk();
        }
    }

    private function rows(string $query = ''): array
    {
        return $this->actingAsSessionUser($this->admin, 'super_admin')->getJson('/api/buildings/deployed-items' . $query)->assertOk()->json('data.items');
    }
    private function dispatch(int $item, int $quantity, string $status = 'released', ?int $room = -1): array
    {
        $id = DB::table('dispatches')->insertGetId(['dispatch_code' => 'SYN-' . DB::table('dispatches')->count(),
            'status' => $status, 'room_id' => $room === -1 ? $this->room : $room, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        $line = DB::table('dispatch_items')->insertGetId(['dispatch_id' => $id, 'item_id' => $item, 'quantity' => $quantity]);
        return [$id, $line];
    }
}
