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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class PurchaseReceiptItemNameValidationTest extends TestCase
{
    use BuildsSharedTestSchema, ConfiguresIsolatedSqliteConnection, InteractsWithLegacySession;

    private int $userId;
    private int $receiptId;
    private array $nativeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('receipt_name_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->forceLocalTestUrl();
        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? [];
        $_SESSION = [];
        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('or_number');
            $table->string('status')->default('draft');
            $table->unsignedInteger('received_by');
            $table->timestamps();
        });
        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->string('item_name');
            $table->unsignedInteger('category_id')->nullable();
            $table->integer('quantity_received');
            $table->string('unit')->default('pc');
            $table->timestamps();
        });
        $this->userId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->receiptId = DB::table('purchase_receipts')->insertGetId([
            'or_number' => 'SYNTHETIC-NAME', 'status' => 'draft', 'received_by' => $this->userId,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    public static function validNames(): array
    {
        return array_map(fn ($name) => [$name], ['Electric Fan', 'Office Chair', 'Keyboard', 'Monitor',
            'RJ45 Connector', 'USB-C Cable', '3.5mm Audio Cable', '1.5 HP Air Conditioner', 'TV', 'PC',
            'Air Conditioner (Window Type)', 'Cable, USB/Audio', 'Écran électrique', '電気扇', "Cafe\u{0301} Monitor", str_repeat('A', 100)]);
    }

    #[DataProvider('validNames')]
    public function test_valid_names_are_accepted_by_single_and_bulk(string $name): void
    {
        $this->actingAsSessionUser($this->userId, 'maintenance_admin')
            ->postJson($this->url(), $this->line($name))->assertCreated();
        $this->postJson($this->url() . '/bulk', ['items' => [$this->line($name)]])->assertCreated();
        $this->assertSame(2, DB::table('purchase_receipt_items')->where('item_name', $name)->count());
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    public static function invalidNames(): array
    {
        return array_map(fn ($name) => [$name], ['', '   ', 'A', '12345', '@@@###', 'Chair!!!', 'Electric@Fan',
            'Cable & Plug', 'Item_Name', '---', str_repeat('A', 101), ['Chair'], 'Fan🙂', 'Fan½', "Fa\0n"]);
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected_atomically_on_both_endpoints(mixed $name): void
    {
        $this->actingAsSessionUser($this->userId, 'maintenance_admin')
            ->postJson($this->url(), $this->line($name))->assertUnprocessable();
        $this->postJson($this->url() . '/bulk', ['items' => [$this->line('Valid Chair'), $this->line($name)]])
            ->assertUnprocessable()->assertJsonPath('data.errors.0.index', 1)->assertJsonPath('data.errors.0.field', 'item_name');
        $this->assertSame(0, DB::table('purchase_receipt_items')->count());
    }

    public function test_whitespace_normalization_preserves_acronyms_and_combines_duplicates(): void
    {
        $this->actingAsSessionUser($this->userId, 'maintenance_admin')->postJson($this->url() . '/bulk', [
            'items' => [$this->line("  USB-C\t  Cable\n"), $this->line('USB-C Cable')],
        ])->assertCreated()->assertJsonPath('data.combined', 1);
        $line = DB::table('purchase_receipt_items')->sole();
        $this->assertSame('USB-C Cable', $line->item_name);
        $this->assertSame(4, $line->quantity_received);
    }

    public function test_existing_inventory_selection_and_legacy_reorder_remain_available(): void
    {
        $name = 'Legacy Fan & Cable!!!';
        $id = $this->seedItem(['name' => $name, 'quantity' => 8]);
        $before = (array) DB::table('items')->where('id', $id)->first();
        $line = $this->line($name) + ['item_id' => $id];
        $this->actingAsSessionUser($this->userId, 'maintenance_admin')->postJson($this->url(), $line)->assertCreated();
        $this->postJson($this->url() . '/bulk', ['items' => [$line]])->assertCreated();
        $this->postJson($this->url(), $this->line('Changed!!!') + ['item_id' => $id])->assertUnprocessable();
        $this->postJson($this->url(), $this->line($name))->assertUnprocessable();
        $this->assertSame($before, (array) DB::table('items')->where('id', $id)->first());
        $this->assertSame(2, DB::table('purchase_receipt_items')->where('item_id', $id)->count());
    }

    public function test_existing_draft_lines_are_not_revalidated_or_rewritten(): void
    {
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $this->receiptId, 'item_name' => 'Old!!!', 'quantity_received' => 1,
        ]);
        $before = (array) DB::table('purchase_receipt_items')->first();
        $this->actingAsSessionUser($this->userId, 'maintenance_admin')->postJson($this->url(), $this->line('New PC'))->assertCreated();
        $this->assertSame($before, (array) DB::table('purchase_receipt_items')->where('id', $before['id'])->first());
    }

    private function url(): string { return '/api/purchase-receipts/' . $this->receiptId . '/items'; }
    private function line(mixed $name): array { return ['item_name' => $name, 'quantity_received' => 2, 'unit' => 'pc']; }
}
