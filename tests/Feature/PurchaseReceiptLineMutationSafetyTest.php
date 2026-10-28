<?php

namespace Tests\Feature;

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
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class PurchaseReceiptLineMutationSafetyTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $headId;
    private int $adminId;
    private int $stockId;
    private array $previousNativeSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('purchase_receipt_line_mutation_safety_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->forceLocalTestUrl();

        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->previousNativeSession = $_SESSION ?? [];
        $_SESSION = [];

        // Create synthetic schema only after the environment/connection guards.
        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('or_number')->unique();
            $table->date('receipt_date');
            $table->string('supplier_name');
            $table->unsignedInteger('supplier_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('received_by');
            $table->text('remarks')->nullable();
            $table->string('status')->default('draft');
            $table->string('proof_image')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('category_id')->nullable();
            $table->integer('quantity_received');
            $table->string('unit', 20)->default('pc');
            $table->timestamps();
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
        $this->stockId = $this->seedItem(['name' => 'Synthetic Stock', 'quantity' => 10, 'reserved_quantity' => 2, 'reorder_level' => 3]);
    }

    protected function tearDown(): void
    {
        if (isset($this->previousNativeSession)) {
            $_SESSION = $this->previousNativeSession;
        }
        parent::tearDown();
    }

    public function test_draft_single_add_preserves_response_and_has_no_stock_effect(): void
    {
        $receipt = $this->receipt();
        $this->mutate('single', $receipt)->assertCreated()->assertJsonPath('message', 'Item added to receipt');
        $this->assertSame(1, DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receipt)->count());
        $this->assertSame(2, (int) DB::table('purchase_receipt_items')->first()->quantity_received);
        $this->assertNoStockEffect();
    }

    public function test_draft_bulk_add_preserves_duplicate_combining_and_response(): void
    {
        $receipt = $this->receipt();
        $this->asHead()->postJson("/api/purchase-receipts/{$receipt}/items/bulk", ['items' => [$this->lineInput(2), $this->lineInput(3)]])
            ->assertCreated()->assertJsonPath('data.count', 1)->assertJsonPath('data.combined', 1)
            ->assertJsonPath('message', '1 item added to receipt');
        $this->assertSame(1, DB::table('purchase_receipt_items')->count());
        $this->assertSame(5, (int) DB::table('purchase_receipt_items')->first()->quantity_received);
        $this->assertNoStockEffect();
    }

    public function test_draft_removal_is_atomic_and_has_no_stock_effect(): void
    {
        $receipt = $this->receipt();
        $keep = $this->line($receipt);
        $remove = $this->line($receipt);
        $this->mutate('remove', $receipt, $remove)->assertOk()
            ->assertJsonPath('message', 'Item removed from receipt')->assertJsonPath('data.id', $remove);
        $this->assertFalse(DB::table('purchase_receipt_items')->where('id', $remove)->exists());
        $this->assertTrue(DB::table('purchase_receipt_items')->where('id', $keep)->exists());
        $this->assertNotSame('2026-01-01 00:00:00', DB::table('purchase_receipts')->find($receipt)->updated_at);
        $this->assertNoStockEffect();
    }

    public static function mutations(): array
    {
        return ['single add' => ['single'], 'bulk add' => ['bulk'], 'removal' => ['remove']];
    }

    #[DataProvider('mutations')]
    public function test_all_mutations_are_rejected_after_actual_posting(string $operation): void
    {
        $receipt = $this->receipt();
        $line = $this->line($receipt);
        $this->postReceipt($receipt)->assertOk();
        $before = $this->snapshot();
        $message = $operation === 'remove' ? 'Cannot remove items from a posted receipt' : 'Cannot add items to a posted receipt';
        $this->mutate($operation, $receipt, $line)->assertStatus(422)->assertJsonPath('message', $message);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('mutations')]
    public function test_parent_read_requests_lock_inside_transaction_before_mutation(string $operation): void
    {
        $receipt = $this->receipt();
        $line = $this->line($receipt);
        // Inspect builder lock intent: SQLite itself does not implement FOR UPDATE.
        $grammar = new class(DB::connection()) extends SQLiteGrammar {
            public array $parentReads = [];

            public function compileSelect(Builder $query)
            {
                if ($query->from === 'purchase_receipts') {
                    $this->parentReads[] = [
                        'lock' => $query->lock, 'depth' => $this->connection->transactionLevel(),
                        'wheres' => $query->wheres, 'columns' => $query->columns,
                    ];
                }
                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        $mutationsObserved = 0;
        DB::listen(function (QueryExecuted $event) use ($grammar, &$mutationsObserved): void {
            if (preg_match('/^\s*(insert into|delete from)\s+["`]?purchase_receipt_items\b/i', $event->sql)) {
                $mutationsObserved++;
                $this->assertCount(1, $grammar->parentReads);
                $this->assertTrue($grammar->parentReads[0]['lock']);
                $this->assertGreaterThan(0, $grammar->parentReads[0]['depth']);
                $this->assertGreaterThan(0, $event->connection->transactionLevel());
            }
        });
        $this->mutate($operation, $receipt, $line)->assertSuccessful();
        $this->assertSame(1, $mutationsObserved);
        $this->assertCount(1, $grammar->parentReads);
        $this->assertContains('status', $grammar->parentReads[0]['columns']);
        $this->assertContains(['type' => 'Basic', 'column' => 'id', 'operator' => '=', 'value' => $receipt, 'boolean' => 'and'], $grammar->parentReads[0]['wheres']);
    }

    public function test_failed_second_bulk_insert_rolls_back_first_insert(): void
    {
        $receipt = $this->receipt();
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER fail_second_line BEFORE INSERT ON purchase_receipt_items
            WHEN NEW.item_name = 'Synthetic failure' BEGIN SELECT RAISE(ABORT, 'Synthetic insertion failure'); END");
        $successfulInserts = 0;
        DB::listen(function (QueryExecuted $event) use (&$successfulInserts): void {
            if (str_starts_with($event->sql, 'insert into "purchase_receipt_items"')) {
                $successfulInserts++;
                $this->assertSame(1, DB::table('purchase_receipt_items')->count());
            }
        });
        $this->asHead()->postJson("/api/purchase-receipts/{$receipt}/items/bulk", ['items' => [
            $this->lineInput(), ['item_name' => 'Synthetic failure', 'quantity_received' => 1],
        ]])->assertStatus(500);
        $this->assertSame(1, $successfulInserts);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_parent_timestamp_failure_rolls_back_line_removal(): void
    {
        $receipt = $this->receipt();
        $line = $this->line($receipt);
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER fail_parent_update BEFORE UPDATE ON purchase_receipts
            BEGIN SELECT RAISE(ABORT, 'Synthetic timestamp failure'); END");
        $deletionObserved = false;
        DB::listen(function (QueryExecuted $event) use ($line, &$deletionObserved): void {
            if (str_starts_with($event->sql, 'delete from "purchase_receipt_items"')) {
                $deletionObserved = true;
                $this->assertFalse(DB::table('purchase_receipt_items')->where('id', $line)->exists());
                $this->assertGreaterThan(0, DB::transactionLevel());
            }
        });
        $this->mutate('remove', $receipt, $line)->assertStatus(500);
        $this->assertTrue($deletionObserved);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_posting_uses_committed_additions_and_excludes_removed_lines(): void
    {
        $receipt = $this->receipt();
        $remove = $this->line($receipt, 7);
        $this->mutate('single', $receipt)->assertCreated();
        $this->asHead()->postJson("/api/purchase-receipts/{$receipt}/items/bulk", ['items' => [$this->lineInput(3)]])->assertCreated();
        $this->mutate('remove', $receipt, $remove)->assertOk();
        $this->postReceipt($receipt)->assertOk();

        $this->assertSame(15, (int) DB::table('items')->find($this->stockId)->quantity);
        $this->assertSame(2, (int) DB::table('items')->find($this->stockId)->reserved_quantity);
        $this->assertSame(2, DB::table('purchase_receipt_items')->count());
        $this->assertSame(2, DB::table('inventory_transactions')->count());
        $this->assertSame(5, (int) DB::table('inventory_transactions')->sum('quantity'));
        $this->assertSame('posted', DB::table('purchase_receipts')->find($receipt)->status);
    }

    public function test_successful_post_then_retry_does_not_duplicate_stock_audit_or_notifications(): void
    {
        $receipt = $this->receipt();
        $this->line($receipt, 4);
        $this->postReceipt($receipt)->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame(14, (int) DB::table('items')->find($this->stockId)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'purchase_posted')->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->adminId)->count());
        $before = $this->snapshot();
        $this->postReceipt($receipt)->assertStatus(422)->assertJsonPath('message', 'Receipt is already posted');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failed_post_rolls_back_then_retry_applies_stock_once(): void
    {
        $receipt = $this->receipt();
        // Force name resolution to update item_id, and verify that also rolls back.
        $line = $this->line($receipt, 4, ['item_id' => null]);
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER fail_post_audit BEFORE INSERT ON activity_logs
            WHEN NEW.action = 'purchase_posted' BEGIN SELECT RAISE(ABORT, 'Synthetic posting failure'); END");
        $movementObserved = false;
        DB::listen(function (QueryExecuted $event) use (&$movementObserved): void {
            if (str_starts_with($event->sql, 'insert into "inventory_transactions"')) {
                $movementObserved = true;
            }
        });
        $this->postReceipt($receipt)->assertStatus(500);
        $this->assertTrue($movementObserved);
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(DB::table('purchase_receipt_items')->find($line)->item_id);
        $this->assertSame(0, DB::transactionLevel());

        // This trigger exists only in the verified in-memory fixture database.
        DB::unprepared('DROP TRIGGER fail_post_audit');
        $this->postReceipt($receipt)->assertOk();
        $this->assertSame(14, (int) DB::table('items')->find($this->stockId)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertSame($this->stockId, (int) DB::table('purchase_receipt_items')->find($line)->item_id);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'purchase_posted')->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_cross_receipt_ownership_is_preserved(): void
    {
        $receipt = $this->receipt();
        $other = $this->receipt();
        $line = $this->line($other);
        $before = $this->snapshot();
        $this->mutate('remove', $receipt, $line)->assertNotFound()
            ->assertJsonPath('message', 'Line item not found on this receipt');
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('mutations')]
    public function test_missing_receipt_is_rejected_without_business_changes(string $operation): void
    {
        $before = $this->snapshot();
        $this->mutate($operation, 99999, 99999)->assertNotFound()->assertJsonPath('message', 'Purchase receipt not found');
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidAdditions(): array
    {
        return [
            'single empty name' => ['single', ['item_name' => '', 'quantity_received' => 1]],
            'single zero quantity' => ['single', ['item_name' => 'Bad', 'quantity_received' => 0]],
            'single unknown stock' => ['single', ['item_name' => 'Bad', 'quantity_received' => 1, 'item_id' => 99999]],
            'bulk empty' => ['bulk', ['items' => []]],
            'bulk bad row' => ['bulk', ['items' => [['item_name' => 'Good', 'quantity_received' => 1], ['item_name' => '', 'quantity_received' => 1]]]],
            'bulk malformed row' => ['bulk', ['items' => ['invalid']]],
            'bulk conflicting units' => ['bulk', ['items' => [['item_name' => 'Same', 'quantity_received' => 1, 'unit' => 'pc'], ['item_name' => 'Same', 'quantity_received' => 1, 'unit' => 'box']]]],
            'bulk too many rows' => ['bulk', ['items' => array_fill(0, 51, ['item_name' => 'Too many', 'quantity_received' => 1])]],
        ];
    }

    #[DataProvider('invalidAdditions')]
    public function test_validation_rejects_without_partial_writes(string $operation, array $payload): void
    {
        $receipt = $this->receipt();
        $before = $this->snapshot();
        $suffix = $operation === 'single' ? 'items' : 'items/bulk';
        $this->asHead()->postJson("/api/purchase-receipts/{$receipt}/{$suffix}", $payload)->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function allowedRoles(): array
    {
        return ['administrator' => ['super_admin'], 'head' => ['maintenance_admin'], 'staff' => ['maintenance_staff']];
    }

    #[DataProvider('allowedRoles')]
    public function test_existing_operational_roles_can_add_and_remove_lines(string $role): void
    {
        $user = $this->seedUser(['role' => $role]);
        $receipt = $this->receipt();
        $this->actingAsSessionUser($user, $role)->postJson("/api/purchase-receipts/{$receipt}/items", $this->lineInput())->assertCreated();
        $this->actingAsSessionUser($user, $role)->postJson("/api/purchase-receipts/{$receipt}/items/bulk", ['items' => [$this->lineInput()]])->assertCreated();
        $line = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receipt)->value('id');
        $this->actingAsSessionUser($user, $role)->deleteJson("/api/purchase-receipts/{$receipt}/items/{$line}")->assertOk();
        $this->assertNoStockEffect();
    }

    public static function deniedOperations(): array
    {
        $cases = [];
        foreach (['single', 'bulk', 'remove'] as $operation) {
            foreach (['guest', 'inactive', 'ineligible'] as $account) {
                $cases["{$operation} / {$account}"] = [$operation, $account];
            }
        }
        return $cases;
    }

    #[DataProvider('deniedOperations')]
    public function test_unauthorized_mutations_preserve_business_state(string $operation, string $account): void
    {
        $receipt = $this->receipt();
        $line = $this->line($receipt);
        if ($account !== 'guest') {
            $role = $account === 'inactive' ? 'maintenance_staff' : 'user';
            $user = $this->seedUser(['role' => $role, 'status' => $account === 'inactive' ? 'inactive' : 'active']);
            $this->actingAsSessionUser($user, $role);
        }
        $before = $this->snapshot();
        $expected = $account === 'ineligible' ? 403 : 401;
        if ($operation === 'remove') {
            $this->deleteJson("/api/purchase-receipts/{$receipt}/items/{$line}")->assertStatus($expected);
        } else {
            $suffix = $operation === 'single' ? 'items' : 'items/bulk';
            $payload = $operation === 'single' ? $this->lineInput() : ['items' => [$this->lineInput()]];
            $this->postJson("/api/purchase-receipts/{$receipt}/{$suffix}", $payload)->assertStatus($expected);
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function receipt(): int
    {
        return DB::table('purchase_receipts')->insertGetId([
            'or_number' => 'SYNTHETIC-' . uniqid(), 'receipt_date' => '2026-01-01',
            'supplier_name' => 'Synthetic Supplier', 'received_by' => $this->headId,
            'status' => 'draft', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function lineInput(int $quantity = 2): array
    {
        return ['item_name' => 'Synthetic Stock', 'item_id' => $this->stockId, 'quantity_received' => $quantity, 'unit' => 'pc'];
    }

    private function line(int $receipt, int $quantity = 2, array $overrides = []): int
    {
        return DB::table('purchase_receipt_items')->insertGetId(array_merge($this->lineInput($quantity), [
            'purchase_receipt_id' => $receipt, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function asHead(): self
    {
        return $this->actingAsSessionUser($this->headId, 'maintenance_admin');
    }

    private function mutate(string $operation, int $receipt, int $line = 0)
    {
        $this->asHead();
        return match ($operation) {
            'single' => $this->postJson("/api/purchase-receipts/{$receipt}/items", $this->lineInput()),
            'bulk' => $this->postJson("/api/purchase-receipts/{$receipt}/items/bulk", ['items' => [$this->lineInput()]]),
            'remove' => $this->deleteJson("/api/purchase-receipts/{$receipt}/items/{$line}"),
        };
    }

    private function postReceipt(int $receipt)
    {
        return $this->asHead()->postJson("/api/purchase-receipts/{$receipt}/post");
    }

    private function assertNoStockEffect(): void
    {
        $this->assertSame(10, (int) DB::table('items')->find($this->stockId)->quantity);
        $this->assertSame(2, (int) DB::table('items')->find($this->stockId)->reserved_quantity);
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['purchase_receipts', 'purchase_receipt_items', 'items', 'inventory_transactions', 'activity_logs', 'notifications'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $result;
    }
}
