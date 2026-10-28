<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryLowStockNotifier;
use App\Services\InventoryStatusService;
use App\Services\SmsService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class InventoryMetadataUpdateStockSafetyTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $headId;
    private int $adminId;
    private array $previousNativeSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('inventory_metadata_stock_safety_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $pdo = DB::connection()->getPdo();
        $this->assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        // Compatibility functions only on this isolated SQLite connection.
        $pdo->sqliteCreateFunction('GREATEST', fn ($a, $b) => max($a, $b), 2);
        $pdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
        $this->forceLocalTestUrl();

        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->previousNativeSession = $_SESSION ?? [];
        $_SESSION = [];

        // All schema operations follow the environment/connection assertions.
        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('title');
            $table->text('message');
            $table->string('entity_type')->nullable();
            $table->unsignedInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
        $this->headId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->adminId = $this->seedUser(['role' => 'super_admin']);
    }

    protected function tearDown(): void
    {
        if (isset($this->previousNativeSession)) {
            $_SESSION = $this->previousNativeSession;
        }
        parent::tearDown();
    }

    public function test_all_metadata_fields_save_without_changing_balances_or_ledger(): void
    {
        $id = $this->stock();
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Equipment']);
        $this->edit($id, [
            'name' => 'Updated Item', 'unit_type' => 'BOX', 'item_condition' => 'GOOD',
            'category_id' => $category, 'reorder_level' => 4, 'description' => 'Updated description',
            'brand' => 'Example Brand', 'model' => 'Example Model',
        ])->assertOk()->assertJsonPath('data.item.quantity', 10)
            ->assertJsonPath('data.item.reserved_quantity', 2)
            ->assertJsonPath('data.item.available_quantity', 8);

        $item = DB::table('items')->find($id);
        $this->assertSame('Updated Item', $item->name);
        $this->assertSame('box', $item->unit_type);
        $this->assertSame('good', $item->item_condition);
        $this->assertSame($category, (int) $item->category_id);
        $this->assertSame(4, (int) $item->reorder_level);
        $this->assertSame('Updated description', $item->description);
        $this->assertSame('Example Brand', $item->brand);
        $this->assertSame('Example Model', $item->model);
        $this->assertBalances($id, 10, 2);
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    public function test_crafted_balance_and_status_fields_are_ignored(): void
    {
        $id = $this->stock(['low_stock_threshold_override' => 123]);
        $this->edit($id, [
            'description' => 'Metadata only', 'quantity' => 999, 'reserved_quantity' => -20,
            'available_quantity' => 999, 'status' => 'out_of_stock',
            'low_stock_threshold_override' => 999,
        ])->assertOk();
        $this->assertBalances($id, 10, 2);
        $this->assertSame('available', DB::table('items')->find($id)->status);
        $this->assertSame(123, (int) DB::table('items')->find($id)->low_stock_threshold_override);
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_partial_update_preserves_omitted_metadata_and_allows_explicit_category_clear(): void
    {
        $category = DB::table('inventory_categories')->insertGetId(['name' => 'Equipment']);
        $id = $this->stock(['category_id' => $category, 'brand' => 'Original', 'model' => 'Model A']);
        $this->edit($id, ['description' => 'Changed'])->assertOk();
        $item = DB::table('items')->find($id);
        $this->assertSame($category, (int) $item->category_id);
        $this->assertSame('Original', $item->brand);
        $this->assertSame('Model A', $item->model);
        $this->edit($id, ['category_id' => null])->assertOk();
        $this->assertNull(DB::table('items')->find($id)->category_id);
        $this->assertBalances($id, 10, 2);
    }

    public static function statusCases(): array
    {
        return [
            'zero quantity' => [0, 0, 0, 'out_of_stock'],
            'equal to threshold' => [10, 2, 10, 'low_stock'],
            'below threshold' => [10, 2, 11, 'low_stock'],
            'above threshold' => [10, 2, 9, 'available'],
            'reservations do not define status' => [10, 9, 5, 'available'],
        ];
    }

    #[DataProvider('statusCases')]
    public function test_status_uses_current_quantity_and_updated_threshold(int $quantity, int $reserved, int $level, string $expected): void
    {
        $id = $this->stock(['quantity' => $quantity, 'reserved_quantity' => $reserved]);
        $this->edit($id, ['reorder_level' => $level, 'status' => 'damaged'])->assertOk();
        $this->assertSame($expected, DB::table('items')->find($id)->status);
        $this->assertBalances($id, $quantity, $reserved);
    }

    public function test_threshold_transition_notifies_once_until_stock_classification_recovers(): void
    {
        $id = $this->stock();
        $this->edit($id, ['reorder_level' => 10])->assertOk();
        $this->assertSame([$this->headId, $this->adminId], DB::table('notifications')->orderBy('user_id')->pluck('user_id')->map(fn ($value) => (int) $value)->all());
        $this->edit($id, ['description' => 'Still low'])->assertOk();
        $this->edit($id, ['reorder_level' => 11])->assertOk();
        $this->assertSame(2, DB::table('notifications')->count());
        $this->edit($id, ['reorder_level' => 1])->assertOk();
        $this->assertSame('available', DB::table('items')->find($id)->status);
        $this->assertSame(2, DB::table('notifications')->count());
        $this->edit($id, ['reorder_level' => 10])->assertOk();
        $this->assertSame(4, DB::table('notifications')->count());
        $this->assertBalances($id, 10, 2);
    }

    public function test_metadata_update_sql_excludes_balance_assignments(): void
    {
        $id = $this->stock();
        $updates = [];
        DB::listen(function (QueryExecuted $event) use (&$updates): void {
            if (preg_match('/^\s*update\s+["`]?items\b/i', $event->sql)) {
                $updates[] = str_replace(['"', '`'], '', $event->sql);
            }
        });
        $this->edit($id, ['description' => 'SQL regression', 'quantity' => 999])->assertOk();
        $this->assertCount(1, $updates);
        $this->assertDoesNotMatchRegularExpression('/\b(quantity|reserved_quantity)\s*=/i', $updates[0]);
        $this->assertMatchesRegularExpression('/\bstatus\s*=/i', $updates[0]);
        $this->assertBalances($id, 10, 2);
    }

    public function test_initial_item_read_requests_a_write_lock_inside_the_transaction(): void
    {
        $id = $this->stock();
        // SQLite emits no FOR UPDATE clause. Inspect the actual query-builder
        // lock request before compilation, without claiming row-lock concurrency.
        $grammar = new class(DB::connection()) extends SQLiteGrammar {
            public array $itemReads = [];

            public function compileSelect(Builder $query)
            {
                if ($query->from === 'items') {
                    $this->itemReads[] = [
                        'lock' => $query->lock,
                        'transaction' => $this->connection->transactionLevel(),
                        'wheres' => $query->wheres,
                    ];
                }
                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        $this->edit($id, ['description' => 'Locked metadata'])->assertOk();
        $this->assertNotEmpty($grammar->itemReads);
        $firstRead = $grammar->itemReads[0];
        $this->assertTrue($firstRead['lock']);
        $this->assertGreaterThan(0, $firstRead['transaction']);
        $this->assertContains(['type' => 'Basic', 'column' => 'id', 'operator' => '=', 'value' => $id, 'boolean' => 'and'], $firstRead['wheres']);
        $this->assertContains(['type' => 'Basic', 'column' => 'item_type', 'operator' => '=', 'value' => 'inventory_stock', 'boolean' => 'and'], $firstRead['wheres']);
    }

    public static function adjustments(): array
    {
        return ['increase' => ['increase', 4, 14], 'decrease' => ['decrease', 6, 4]];
    }

    #[DataProvider('adjustments')]
    public function test_committed_adjustment_survives_metadata_save(string $direction, int $amount, int $expected): void
    {
        $id = $this->stock();
        app(InventoryAdjustmentService::class)->adjust(Item::findOrFail($id), $direction, $amount, 'Synthetic adjustment', $this->headId);
        $ledger = DB::table('inventory_transactions')->get()->toJson();
        $this->edit($id, ['description' => 'After stock movement', 'quantity' => 10])->assertOk();
        $this->assertBalances($id, $expected, 2);
        $this->assertSame(InventoryStatusService::deriveStatus($expected, 3), DB::table('items')->find($id)->status);
        $this->assertSame($ledger, DB::table('inventory_transactions')->get()->toJson());
    }

    public function test_stock_movement_after_metadata_uses_the_new_threshold(): void
    {
        $id = $this->stock();
        $this->edit($id, ['name' => 'Renamed stock', 'reorder_level' => 5])->assertOk();
        app(InventoryAdjustmentService::class)->adjust(Item::findOrFail($id), 'decrease', 6, 'Synthetic adjustment', $this->headId);
        $this->assertBalances($id, 4, 2);
        $this->assertSame('low_stock', DB::table('items')->find($id)->status);
        $this->assertSame('Renamed stock', DB::table('items')->find($id)->name);
        $this->assertSame(2, DB::table('notifications')->count());
    }

    public function test_failure_after_metadata_write_rolls_back_the_transaction(): void
    {
        $id = $this->stock();
        $before = $this->snapshot();
        $this->mock(InventoryLowStockNotifier::class)->shouldReceive('handleStatusChange')->once()
            ->andReturnUsing(function () use ($id): void {
                $this->assertSame('Temporary name', DB::table('items')->find($id)->name);
                $this->assertGreaterThan(0, DB::transactionLevel());
                throw new RuntimeException('Synthetic metadata failure');
            });
        $this->edit($id, ['name' => 'Temporary name', 'reorder_level' => 10])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_duplicate_metadata_is_rejected_without_business_changes(): void
    {
        $id = $this->stock();
        $this->stock(['name' => 'Already exists']);
        $before = $this->snapshot();
        $this->edit($id, ['name' => 'Already exists'])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidMetadata(): array
    {
        return [
            'negative threshold' => [['reorder_level' => -1]],
            'unknown category' => [['category_id' => 99999]],
            'oversized description' => [['description' => str_repeat('x', 1001)]],
            'empty name' => [['name' => '']],
            'empty unit' => [['unit_type' => '']],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_metadata_preserves_business_state(array $payload): void
    {
        $id = $this->stock();
        $before = $this->snapshot();
        $this->edit($id, $payload)->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_staff_cannot_edit_metadata(): void
    {
        $staff = $this->seedUser(['role' => 'maintenance_staff']);
        $id = $this->stock();
        $before = $this->snapshot();
        $this->actingAsSessionUser($staff, 'maintenance_staff')->putJson("/api/inventory-stock/{$id}", ['name' => 'Denied'])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_guest_cannot_edit_metadata(): void
    {
        $id = $this->stock();
        $before = $this->snapshot();
        $this->putJson("/api/inventory-stock/{$id}", ['name' => 'Denied'])->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_administrator_can_edit_metadata(): void
    {
        $id = $this->stock();
        $this->actingAsSessionUser($this->adminId, 'super_admin')->putJson("/api/inventory-stock/{$id}", ['description' => 'Administrator edit'])->assertOk();
        $this->assertBalances($id, 10, 2);
    }

    public function test_missing_non_stock_and_invalid_ids_preserve_business_state(): void
    {
        $roomAsset = $this->stock(['item_type' => 'room_asset']);
        $before = $this->snapshot();
        foreach ([99999 => 404, $roomAsset => 404, 0 => 400] as $id => $expected) {
            $this->edit($id, ['description' => 'Denied'])->assertStatus($expected);
            $this->assertSame($before, $this->snapshot());
        }
    }

    private function stock(array $overrides = []): int
    {
        $values = array_merge(['name' => 'Test warehouse item', 'quantity' => 10, 'reserved_quantity' => 2, 'reorder_level' => 3, 'item_condition' => 'good'], $overrides);
        $values['status'] = InventoryStatusService::deriveStatus($values['quantity'], $values['reorder_level']);
        return $this->seedItem($values);
    }

    private function edit(int $id, array $payload)
    {
        return $this->actingAsSessionUser($this->headId, 'maintenance_admin')->putJson("/api/inventory-stock/{$id}", $payload);
    }

    private function assertBalances(int $id, int $quantity, int $reserved): void
    {
        $item = DB::table('items')->find($id);
        $this->assertSame($quantity, (int) $item->quantity);
        $this->assertSame($reserved, (int) $item->reserved_quantity);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['items', 'inventory_transactions', 'activity_logs', 'notifications'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $result;
    }
}
