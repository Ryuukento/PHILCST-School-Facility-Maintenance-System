<?php

namespace Tests\Feature;

use App\Models\DamageReport;
use App\Models\MaintenanceReport;
use App\Services\DamageReportService;
use App\Services\NeedChangeService;
use App\Services\SmsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class DamageReplacementConcurrencySafetyTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $admin;
    private int $head;
    private int $staff;
    private int $department;
    private int $stock;
    private int $room;
    private array $nativeSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('damage_replacement_concurrency_safety_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->forceLocalTestUrl();
        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeSession = $_SESSION ?? [];
        $_SESSION = [];

        // Synthetic schema only, after asserting the actual connection.
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedInteger('dispatch_id')->nullable();
        });
        $this->createActivityLogsTable();
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
        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('damage_report_code');
            $table->unsignedInteger('report_id')->nullable()->unique();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('reported_by');
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->string('status')->default('pending');
            $table->text('repair_notes')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('damage_report_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type', 50);
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by');
            $table->timestamp('created_at');
        });
        $this->department = DB::table('departments')->insertGetId([
            'name' => 'Synthetic maintenance department', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], 'department_id');
        $this->admin = $this->seedUser(['role' => 'super_admin']);
        $this->head = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->department]);
        $this->staff = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->stock = $this->seedItem(['quantity' => 20, 'reserved_quantity' => 2]);
        $this->room = $this->seedRoom();
    }

    protected function tearDown(): void
    {
        if (isset($this->nativeSession)) $_SESSION = $this->nativeSession;
        parent::tearDown();
    }

    public static function replacementKinds(): array
    {
        return ['standalone' => [false], 'Need Change linked' => [true]];
    }

    #[DataProvider('replacementKinds')]
    public function test_completion_preserves_issuance_and_synchronizes_parent(bool $linked): void
    {
        [$parent, $damage] = $this->fixture($linked);
        if ($linked) $this->approve($parent);
        $issued = $linked ? (int) DB::table('inventory_transactions')->first()->id : null;
        $this->complete($damage->id, ['replaced_by' => $this->admin, 'performed_by' => $this->admin])->assertOk()
            ->assertJsonPath('message', 'Damage report updated successfully')
            ->assertJsonPath('data.report.status', 'replaced');
        $done = $damage->fresh();
        $this->assertSame('completed', $parent->fresh()->status);
        $this->assertNotNull($parent->fresh()->completed_date);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        if ($linked) $this->assertSame($issued, (int) $done->replacement_transaction_id);
        $this->assertSame($this->staff, (int) $done->replaced_by);
        $this->assertNotNull($done->replaced_at);
        $this->assertSame($linked ? $this->admin : $this->staff, (int) DB::table('inventory_transactions')->first()->performed_by);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'REPLACEMENT_ACTION')->count());
        $audit = DB::table('activity_logs')->where('action', 'UPDATE_DAMAGE_REPORT_STATUS')->sole();
        $this->assertSame($this->staff, (int) $audit->user_id);
        $this->assertSame('maintenance_staff', $audit->user_role);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    #[DataProvider('replacementKinds')]
    public function test_delayed_matching_retries_leave_stock_reference_history_and_audits_unchanged(bool $linked): void
    {
        [$parent, $damage] = $this->fixture($linked);
        if ($linked) $this->approve($parent);
        $this->complete($damage->id)->assertOk();
        $before = $this->snapshot();
        // Exceed both controller and service audit deduplication windows.
        $this->travel(10)->seconds();
        try {
            $this->complete($damage->id)->assertOk();
            $this->complete($damage->id)->assertOk();
            $this->assertSame($before, $this->snapshot());
        } finally {
            $this->travelBack();
        }
    }

    #[DataProvider('replacementKinds')]
    public function test_conflicting_item_and_quantity_retries_are_rejected(bool $linked): void
    {
        [$parent, $damage] = $this->fixture($linked);
        $other = $this->seedItem(['quantity' => 50]);
        if ($linked) $this->approve($parent);
        $this->complete($damage->id)->assertOk();
        $before = $this->snapshot();
        $this->complete($damage->id, ['replacement_item_id' => $other])->assertUnprocessable();
        $this->complete($damage->id, ['replacement_quantity' => 4])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public static function terminalParents(): array
    {
        return ['standalone closed' => [false, 'closed'], 'standalone cancelled' => [false, 'cancelled'],
            'linked closed' => [true, 'closed'], 'linked cancelled' => [true, 'cancelled']];
    }

    #[DataProvider('terminalParents')]
    public function test_current_terminal_parent_status_and_completion_date_are_preserved(bool $linked, string $status): void
    {
        [$parent, $damage] = $this->fixture($linked);
        if ($linked) $this->approve($parent);
        DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update([
            'status' => $status, 'completed_date' => '2026-01-05',
        ]);
        // Retain a stale relationship deliberately; synchronization must query current state.
        $damage->setRelation('report', $parent);
        app(DamageReportService::class)->updateStatus($damage, $this->payload(), $this->staffAuth());
        $this->assertSame($status, $parent->fresh()->status);
        $this->assertSame('2026-01-05', $parent->fresh()->getRawOriginal('completed_date'));
        $this->assertSame('replaced', $damage->fresh()->status);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
    }

    public function test_parent_is_revalidated_immediately_before_sync(): void
    {
        [$parent, $damage] = $this->fixture(false);
        $changed = false;
        DB::connection()->beforeExecuting(static function ($sql) use (&$changed, $parent): void {
            if (!$changed && str_starts_with($sql, 'update "damage_reports"')) {
                $changed = true;
                // Controlled same-connection state change, not a concurrency simulation.
                DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update([
                    'status' => 'cancelled', 'completed_date' => '2026-01-05',
                ]);
            }
        });
        $this->complete($damage->id)->assertOk();
        $this->assertTrue($changed);
        $this->assertSame('cancelled', $parent->fresh()->status);
        $this->assertSame('2026-01-05', $parent->fresh()->getRawOriginal('completed_date'));
    }

    public function test_synchronization_requests_current_parent_read_without_changing_lock_order(): void
    {
        [, $damage] = $this->fixture(false);
        $grammar = new class(DB::connection()) extends SQLiteGrammar {
            public array $reads = [];
            public function compileSelect(Builder $query)
            {
                $this->reads[] = [$query->from, (bool) $query->lock, $query->getConnection()->transactionLevel()];
                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        app(DamageReportService::class)->updateStatus($damage, $this->payload(), $this->staffAuth());
        $locked = array_values(array_filter($grammar->reads, static fn ($read) => $read[1]));
        $this->assertSame(['maintenance_reports', 'damage_reports'], array_slice(array_column($locked, 0), 0, 2));
        $parents = array_values(array_filter($grammar->reads, static fn ($read) => $read[0] === 'maintenance_reports'));
        $this->assertCount(2, $parents);
        foreach ($parents as [, $lock, $depth]) {
            $this->assertTrue($lock, 'Parent synchronization must not use a snapshot SELECT.');
            $this->assertGreaterThan(0, $depth);
        }
    }

    public static function auditFailures(): array
    {
        return ['standalone exception' => [false, 'exception'], 'linked exception' => [true, 'exception'],
            'standalone null' => [false, 'null'], 'linked null' => [true, 'null'],
            'standalone unsaved audit' => [false, 'unsaved'], 'linked unsaved audit' => [true, 'unsaved']];
    }

    #[DataProvider('auditFailures')]
    public function test_controller_audit_failure_rolls_back_and_retry_succeeds(bool $linked, string $mode): void
    {
        [$parent, $damage] = $this->fixture($linked);
        if ($linked) $this->approve($parent);
        $before = $this->snapshot();
        $real = app(\App\Services\ActivityLogService::class);
        $fail = true;
        $auditDepth = null;
        $historyPresent = false;
        $mock = \Mockery::mock(\App\Services\ActivityLogService::class)->makePartial();
        $mock->shouldReceive('log')->andReturnUsing(function ($payload, $request = null) use ($real, &$fail, $mode, &$auditDepth, &$historyPresent) {
            if (($payload['action'] ?? '') === 'UPDATE_DAMAGE_REPORT_STATUS' && $fail) {
                $auditDepth = DB::connection()->transactionLevel();
                $historyPresent = DB::table('damage_report_histories')->where('action_type', 'status_changed')->exists();
                if ($mode === 'exception') throw new RuntimeException('Synthetic controller audit failure');
                return $mode === 'null' ? null : new \App\Models\ActivityLog();
            }
            return $real->log($payload, $request);
        });
        $this->instance(\App\Services\ActivityLogService::class, $mock);
        $this->complete($damage->id)->assertStatus(500)->assertJsonPath('success', false);
        $this->assertSame(1, $auditDepth, 'Controller audit must run before the outer transaction commits.');
        $this->assertTrue($historyPresent, 'Failure must occur after service history was saved.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $fail = false;
        $this->complete($damage->id)->assertOk();
        $this->assertSame('replaced', $damage->fresh()->status);
        $this->assertSame('completed', $parent->fresh()->status);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'UPDATE_DAMAGE_REPORT_STATUS')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'REPLACEMENT_ACTION')->count());
        $this->assertSame(1, DB::table('notifications')->where('title', 'Damage Report Replacement Update')->count());
        if ($linked) $this->assertSame($before['inventory_transactions'], $this->snapshot()['inventory_transactions']);
    }

    public function test_parentless_standalone_replacement_and_closure_preserve_reference(): void
    {
        [, $damage] = $this->fixture(false);
        DB::table('damage_reports')->where('id', $damage->id)->update(['report_id' => null]);
        $this->complete($damage->id)->assertOk();
        $reference = $damage->fresh()->replacement_transaction_id;
        $this->complete($damage->id, ['status' => 'closed'])->assertOk();
        $this->assertSame($reference, $damage->fresh()->replacement_transaction_id);
        $this->assertSame('closed', $damage->fresh()->status);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unapproved_linked_completion_remains_rejected(): void
    {
        [, $damage] = $this->fixture(true);
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_existing_authorization_validation_and_head_completion_remain_intact(): void
    {
        [$parent, $damage] = $this->fixture(false);
        $before = $this->snapshot();
        $this->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertUnauthorized();
        $user = $this->seedUser(['role' => 'user']);
        $this->actingAsSessionUser($user, 'user')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertForbidden();
        $this->actingAsSessionUser($this->admin, 'super_admin')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertForbidden();
        $unassigned = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->actingAsSessionUser($unassigned, 'maintenance_staff')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertForbidden();
        $this->complete($damage->id, ['replacement_quantity' => 0])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->actingAsSessionUser($this->head, 'maintenance_admin')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertOk();
        $this->assertSame($this->head, (int) $damage->fresh()->replaced_by);
        $audit = DB::table('activity_logs')->where('action', 'UPDATE_DAMAGE_REPORT_STATUS')->sole();
        $this->assertSame($this->head, (int) $audit->user_id);
    }

    public function test_nonreplacement_status_and_note_updates_keep_controller_audit_behavior(): void
    {
        [, $damage] = $this->fixture(false);
        $this->complete($damage->id, ['status' => 'repairing', 'repair_notes' => 'Work continuing'])->assertOk();
        $this->assertSame('Work continuing', $damage->fresh()->repair_notes);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'UPDATE_DAMAGE_REPORT_STATUS')->count());
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->complete($damage->id, ['status' => 'repaired'])->assertOk();
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    private function fixture(bool $linked): array
    {
        $parentId = DB::table('maintenance_reports')->insertGetId([
            'title' => 'Synthetic R6 case', 'description' => 'Synthetic damage', 'status' => 'in_progress',
            'priority' => 'medium', 'created_by' => $this->staff, 'assigned_to' => $this->staff,
            'department_id' => $this->department, 'need_change_item_id' => $linked ? $this->stock : null,
            'need_change_quantity' => 3, 'need_change_status' => $linked ? 'pending' : null,
            'created_at' => now(), 'updated_at' => now(),
        ], 'report_id');
        $damage = DamageReport::query()->create([
            'damage_report_code' => 'DMG-R6-' . $parentId, 'report_id' => $parentId,
            'item_id' => $this->stock, 'room_id' => $this->room, 'department_id' => $this->department,
            'reported_by' => $this->staff, 'status' => 'repairing',
        ]);
        if ($linked) DB::table('damage_report_histories')->insert([
            'damage_report_id' => $damage->id, 'action_type' => 'created', 'to_status' => 'pending',
            'meta_json' => json_encode(['auto_linked_from_need_change' => true]), 'changed_by' => $this->staff, 'created_at' => now(),
        ]);
        return [MaintenanceReport::query()->findOrFail($parentId), $damage];
    }

    private function approve(MaintenanceReport $parent): void
    {
        app(NeedChangeService::class)->approve($parent, ['user_id' => $this->admin, 'role' => 'super_admin']);
    }

    private function staffAuth(): array
    {
        return ['user_id' => $this->staff, 'role' => 'maintenance_staff', 'department_id' => $this->department];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['status' => 'replaced', 'replacement_item_id' => $this->stock, 'replacement_quantity' => 3], $overrides);
    }

    private function complete(int $damageId, array $overrides = [])
    {
        return $this->actingAsSessionUser($this->staff, 'maintenance_staff')
            ->postJson("/api/damage-reports/{$damageId}/status", $this->payload($overrides));
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['items', 'inventory_transactions', 'maintenance_reports', 'damage_reports', 'damage_report_histories', 'activity_logs', 'notifications'] as $table) {
            $key = $table === 'maintenance_reports' ? 'report_id' : 'id';
            $snapshot[$table] = DB::table($table)->orderBy($key)->get()->map(static fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }
}
