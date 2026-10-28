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

class ReplacementFulfillmentSafetyTest extends TestCase
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
        $this->useInMemoryDatabase('replacement_fulfillment_safety_testing');
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

    public function test_approval_issues_once_without_recording_physical_completion(): void
    {
        [$parent, $damage] = $this->fixture();
        $before = $damage->fresh()->getAttributes();
        $stale = clone $parent;
        $this->approve($parent);
        $first = $this->snapshot();
        $this->approve($stale);
        $this->assertSame($first, $this->snapshot());
        $tx = DB::table('inventory_transactions')->first();
        $this->assertSame($this->admin, (int) $tx->performed_by);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame($before, $damage->fresh()->getAttributes());
        $this->assertSame('in_progress', $parent->fresh()->status);
        $this->assertNull($parent->fresh()->completed_date);
        foreach (['replacement_transaction_id', 'replacement_item_id', 'replacement_quantity', 'replaced_by', 'replaced_at'] as $field) {
            $this->assertNull($damage->fresh()->getAttribute($field));
        }
        $history = $this->issuanceHistory();
        $this->assertSame($this->admin, (int) $history->changed_by);
        $this->assertSame('repairing', $history->from_status);
        $this->assertSame('repairing', $history->to_status);
        $this->assertSame((int) $tx->id, json_decode($history->meta_json, true)['transaction_id']);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'APPROVE_NEED_CHANGE')->count());
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'REPLACEMENT_ACTION')->count());
        // Same predicate used by replacement analytics: approval stays absent.
        $this->assertSame(0, DB::table('damage_reports')->whereNotNull('replacement_transaction_id')->count());
    }

    public function test_linked_completion_reuses_issuance_and_records_maintenance_actor(): void
    {
        [$parent, $damage] = $this->fixture();
        $this->approve($parent);
        $ledger = DB::table('inventory_transactions')->get()->toArray();
        $items = DB::table('items')->get()->toArray();
        $tx = $ledger[0];
        $this->complete($damage->id, ['replaced_by' => $this->admin, 'performed_by' => $this->admin])->assertOk();
        $done = $damage->fresh();
        $this->assertSame((int) $tx->id, (int) $done->replacement_transaction_id);
        $this->assertSame($this->staff, (int) $done->replaced_by);
        $this->assertNotNull($done->replaced_at);
        $this->assertSame('replaced', $done->status);
        $this->assertSame('completed', $parent->fresh()->status);
        $this->assertNotNull($parent->fresh()->completed_date);
        $this->assertEquals($ledger, DB::table('inventory_transactions')->get()->toArray());
        $this->assertEquals($items, DB::table('items')->get()->toArray());
        $audit = DB::table('activity_logs')->where('action', 'REPLACEMENT_ACTION')->first();
        $this->assertSame($this->staff, (int) $audit->user_id);
        $this->assertSame((int) $tx->id, json_decode($audit->meta_json, true)['replacement_transaction_id']);
        $this->assertSame(1, DB::table('notifications')->where('title', 'Damage Report Replacement Update')->count());
        $first = $this->snapshot();
        // Supplied stale model still says repairing with no transaction.
        $this->finishInService($damage);
        $this->assertSame($first, $this->snapshot());
    }

    public static function unapprovedStates(): array
    {
        return ['pending' => ['pending'], 'rejected' => ['rejected'], 'approved but unissued' => ['approved']];
    }

    #[DataProvider('unapprovedStates')]
    public function test_unissued_linked_requests_cannot_be_replaced(string $status): void
    {
        [$parent, $damage] = $this->fixture();
        DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update(['need_change_status' => $status]);
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clearing_need_change_fields_does_not_enable_standalone_fallback(): void
    {
        [$parent, $damage] = $this->fixture();
        DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update([
            'need_change_item_id' => null, 'need_change_status' => null,
        ]);
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_current_need_change_fields_identify_a_link_without_origin_history(): void
    {
        [, $damage] = $this->fixture();
        DB::table('damage_report_histories')->delete();
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_forged_replacement_item_and_quantity_are_rejected(): void
    {
        [$parent, $damage] = $this->fixture();
        $other = $this->seedItem(['quantity' => 30]);
        $this->approve($parent);
        $before = $this->snapshot();
        $this->complete($damage->id, ['replacement_item_id' => $other])->assertUnprocessable();
        $this->complete($damage->id, ['replacement_quantity' => 4])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidReferences(): array
    {
        return array_combine($cases = [
            'missing_history', 'duplicate_history', 'malformed_history', 'missing_transaction_id',
            'source', 'report_id', 'item_id', 'quantity', 'approved_by', 'history_actor',
            'missing_transaction', 'ledger_report', 'ledger_item', 'ledger_quantity', 'ledger_type',
            'ledger_actor', 'ledger_dispatch', 'approval_timestamp', 'deduction_timestamp',
            'conflicting_completion_reference',
        ], array_map(static fn ($case) => [$case], $cases));
    }

    #[DataProvider('invalidReferences')]
    public function test_invalid_or_ambiguous_issuance_is_rejected_without_mutation(string $case): void
    {
        [$parent, $damage] = $this->fixture();
        $this->approve($parent);
        $history = $this->issuanceHistory();
        $meta = json_decode($history->meta_json, true);
        $txId = $meta['transaction_id'];
        if ($case === 'missing_history') {
            DB::table('damage_report_histories')->where('id', $history->id)->delete();
        } elseif ($case === 'duplicate_history') {
            $copy = (array) $history;
            unset($copy['id']);
            DB::table('damage_report_histories')->insert($copy);
        } elseif ($case === 'malformed_history') {
            DB::table('damage_report_histories')->where('id', $history->id)->update(['meta_json' => '{broken']);
        } elseif ($case === 'missing_transaction_id' || in_array($case, ['source', 'report_id', 'item_id', 'quantity', 'approved_by'], true)) {
            if ($case === 'missing_transaction_id') unset($meta['transaction_id']);
            else $meta[$case] = $case === 'source' ? 'dispatch' : 999;
            DB::table('damage_report_histories')->where('id', $history->id)->update(['meta_json' => json_encode($meta)]);
        } elseif ($case === 'history_actor') {
            DB::table('damage_report_histories')->where('id', $history->id)->update(['changed_by' => $this->staff]);
        } elseif ($case === 'missing_transaction') {
            DB::table('inventory_transactions')->where('id', $txId)->delete();
        } elseif (str_starts_with($case, 'ledger_')) {
            $columns = ['ledger_report' => 'report_id', 'ledger_item' => 'item_id', 'ledger_quantity' => 'quantity',
                'ledger_type' => 'transaction_type', 'ledger_actor' => 'performed_by', 'ledger_dispatch' => 'dispatch_id'];
            DB::table('inventory_transactions')->where('id', $txId)->update([$columns[$case] => $case === 'ledger_type' ? 'return' : 999]);
        } elseif (str_ends_with($case, '_timestamp')) {
            DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update([
                $case === 'approval_timestamp' ? 'need_change_approved_at' : 'need_change_deducted_at' => null,
            ]);
        } else {
            DB::table('damage_reports')->where('id', $damage->id)->update(['replacement_transaction_id' => 999]);
        }
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public static function standaloneParents(): array
    {
        return ['no parent' => [false], 'ordinary maintenance parent' => [true]];
    }

    #[DataProvider('standaloneParents')]
    public function test_standalone_replacement_is_preserved_and_stale_retry_does_not_deduct(bool $withParent): void
    {
        [$parent, $damage] = $this->fixture(false);
        if (!$withParent) {
            DB::table('damage_reports')->where('id', $damage->id)->update(['report_id' => null]);
            $damage->refresh();
        }
        $this->complete($damage->id)->assertOk();
        $done = $damage->fresh();
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame($this->staff, (int) DB::table('inventory_transactions')->first()->performed_by);
        $this->assertSame($this->staff, (int) $done->replaced_by);
        $this->assertNotNull($done->replacement_transaction_id);
        $this->assertSame($withParent ? 'completed' : 'in_progress', $parent->fresh()->status);
        $first = $this->snapshot();
        $this->finishInService($damage);
        $this->assertSame($first, $this->snapshot());
    }

    public function test_stale_model_cannot_reopen_a_closed_report_or_overwrite_notes(): void
    {
        [, $damage] = $this->fixture(false);
        DB::table('damage_reports')->where('id', $damage->id)->update(['status' => 'closed', 'repair_notes' => 'Current notes']);
        $before = $this->snapshot();
        try {
            $this->finishInService($damage);
            $this->fail('Closed report must reject stale completion.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_parent_identity_and_notes_are_reloaded(): void
    {
        [$parent, $damage] = $this->fixture(false);
        [, $linked] = $this->fixture();
        // Supplied model still points to the ordinary parent; current row does not.
        DB::table('damage_reports')->where('id', $linked->id)->update(['report_id' => null]);
        $linkedParent = $linked->report_id;
        DB::table('damage_reports')->where('id', $damage->id)->update(['report_id' => $linkedParent]);
        $before = $this->snapshot();
        try {
            $this->finishInService($damage);
            $this->fail('Current linked request must require approval.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame($before, $this->snapshot());
        DB::table('damage_reports')->where('id', $damage->id)->update(['report_id' => $parent->report_id, 'repair_notes' => 'Fresh notes']);
        $done = app(DamageReportService::class)->updateStatus($damage, ['status' => 'repaired'], $this->staffAuth());
        $this->assertSame('Fresh notes', $done->repair_notes);
        $done = app(DamageReportService::class)->updateStatus($damage, ['repair_notes' => 'Updated current notes'], $this->staffAuth());
        $this->assertSame('repaired', $done->status);
        $this->assertSame('Updated current notes', $done->repair_notes);
    }

    public function test_approval_rolls_back_when_issuance_history_cannot_be_saved(): void
    {
        [$parent] = $this->fixture();
        $before = $this->snapshot();
        $fail = true;
        DB::connection()->beforeExecuting(static function ($sql) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert into "damage_report_histories"')) throw new RuntimeException('Synthetic history failure');
        });
        try {
            $this->approve($parent);
            $this->fail('Expected history persistence failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Synthetic history failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $fail = false;
        $this->approve($parent);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
    }

    public static function rollbackBranches(): array
    {
        return ['linked completion' => [true], 'standalone issuance' => [false]];
    }

    #[DataProvider('rollbackBranches')]
    public function test_failed_completion_rolls_back_stock_history_status_and_audit(bool $linked): void
    {
        [$parent, $damage] = $this->fixture($linked);
        if ($linked) $this->approve($parent);
        $before = $this->snapshot();
        $fail = true;
        DB::connection()->beforeExecuting(static function ($sql, $bindings) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert into "activity_logs"') && in_array('REPLACEMENT_ACTION', $bindings, true)) {
                throw new RuntimeException('Synthetic completion audit failure');
            }
        });
        try {
            $this->finishInService($damage);
            $this->fail('Expected audit persistence failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Synthetic completion audit failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $fail = false;
        $this->finishInService($damage);
        $this->assertSame('replaced', $damage->fresh()->status);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        $this->assertSame(1, DB::table('inventory_transactions')->count());
    }

    public function test_approval_and_completion_lock_current_parent_then_damage_before_stock(): void
    {
        [$parent, $damage] = $this->fixture();
        $grammar = new class(DB::connection()) extends SQLiteGrammar {
            public array $locks = [];
            public function compileSelect(Builder $query)
            {
                if ($query->lock) $this->locks[] = [$query->from, $query->getConnection()->transactionLevel()];
                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        $this->approve($parent);
        $this->assertSame(['maintenance_reports', 'damage_reports', 'damage_report_histories', 'items'], array_slice(array_column($grammar->locks, 0), 0, 4));
        foreach ($grammar->locks as [, $depth]) $this->assertGreaterThan(0, $depth);
        $grammar->locks = [];
        $this->finishInService($damage);
        $this->assertSame(['maintenance_reports', 'damage_reports', 'damage_report_histories', 'inventory_transactions', 'maintenance_reports'], array_column($grammar->locks, 0));
        foreach ($grammar->locks as [, $depth]) $this->assertGreaterThan(0, $depth);
        [, $standalone] = $this->fixture(false);
        DB::table('damage_reports')->where('id', $standalone->id)->update(['report_id' => null]);
        $grammar->locks = [];
        $this->finishInService($standalone);
        $this->assertSame(['damage_reports', 'damage_report_histories', 'items'], array_slice(array_column($grammar->locks, 0), 0, 3));
    }

    public function test_low_stock_alert_occurs_only_at_issuance_and_completion_notifies_once(): void
    {
        [$parent, $damage] = $this->fixture();
        DB::table('items')->where('id', $this->stock)->update(['quantity' => 6, 'reorder_level' => 4, 'status' => 'available']);
        $this->approve($parent);
        $low = DB::table('notifications')->where('entity_type', 'inventory')->count();
        $this->assertSame(2, $low);
        $this->assertGreaterThan(0, DB::table('notifications')->where('entity_type', 'report')->count());
        $this->complete($damage->id)->assertOk();
        $this->complete($damage->id)->assertOk();
        $this->assertSame($low, DB::table('notifications')->where('entity_type', 'inventory')->count());
        $this->assertSame(1, DB::table('notifications')->where('title', 'Damage Report Replacement Update')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'REPLACEMENT_ACTION')->count());
    }

    public function test_maintenance_cannot_approve_and_existing_completion_permissions_are_preserved(): void
    {
        [$parent, $damage] = $this->fixture();
        $before = $this->snapshot();
        $this->actingAsSessionUser($this->head, 'maintenance_admin')
            ->patchJson("/api/reports/{$parent->report_id}", ['approve_need_change' => true])->assertForbidden();
        $this->actingAsSessionUser($this->staff, 'maintenance_staff')
            ->patchJson("/api/reports/{$parent->report_id}", ['approve_need_change' => true])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        $this->approve($parent);
        $this->actingAsSessionUser($this->admin, 'super_admin')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertForbidden();
        $otherStaff = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->actingAsSessionUser($otherStaff, 'maintenance_staff')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertForbidden();
        $this->actingAsSessionUser($this->head, 'maintenance_admin')->postJson("/api/damage-reports/{$damage->id}/status", $this->payload())->assertOk();
        $this->assertSame($this->head, (int) $damage->fresh()->replaced_by);
    }

    public function test_unrelated_movements_remain_allowed_and_existing_broad_guard_is_preserved(): void
    {
        [$parent, $damage] = $this->fixture();
        $this->approve($parent);
        $other = $this->seedItem(['quantity' => 20]);
        \App\Models\InventoryTransaction::query()->create([
            'item_id' => $other, 'report_id' => $parent->report_id, 'transaction_type' => 'deploy',
            'quantity' => 1, 'performed_by' => $this->head, 'reference_note' => 'Unrelated repair supplies',
        ]);
        $this->complete($damage->id)->assertOk();
        $this->assertSame(19, (int) DB::table('items')->find($other)->quantity);
        $this->assertSame(17, (int) DB::table('items')->find($this->stock)->quantity);
        [$blocked] = $this->fixture();
        \App\Models\InventoryTransaction::query()->create([
            'item_id' => $other, 'report_id' => $blocked->report_id, 'transaction_type' => 'deploy',
            'quantity' => 2, 'performed_by' => $this->head, 'reference_note' => 'Existing report deployment',
        ]);
        $before = $this->snapshot();
        try {
            $this->approve($blocked);
            $this->fail('Existing broad report-level guard must remain.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('need_change_status', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_issuance_history_blocks_reapproval_when_deduction_marker_is_missing(): void
    {
        [$parent] = $this->fixture();
        $this->approve($parent);
        DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update(['need_change_deducted_at' => null]);
        $before = $this->snapshot();
        try {
            $this->approve($parent);
            $this->fail('Existing issuance must require review rather than reissue.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('need_change_status', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_completed_legacy_report_without_reference_never_issues_again(): void
    {
        [$parent, $damage] = $this->fixture(false);
        DB::table('damage_reports')->where('id', $damage->id)->update(['status' => 'replaced']);
        $before = $this->snapshot();
        $this->complete($damage->id)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('maintenance_reports')->where('report_id', $parent->report_id)->update([
            'need_change_item_id' => $this->stock, 'need_change_status' => 'pending',
        ]);
        $before = $this->snapshot();
        try {
            $this->approve($parent);
            $this->fail('A completed replacement must not be issued through approval again.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('need_change_status', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function fixture(bool $linked = true): array
    {
        $parentId = DB::table('maintenance_reports')->insertGetId([
            'title' => 'Synthetic replacement case', 'description' => 'Synthetic damage', 'status' => 'in_progress',
            'priority' => 'medium', 'created_by' => $this->staff, 'assigned_to' => $this->staff,
            'department_id' => $this->department, 'need_change_item_id' => $linked ? $this->stock : null,
            'need_change_quantity' => 3, 'need_change_status' => $linked ? 'pending' : null,
            'created_at' => now(), 'updated_at' => now(),
        ], 'report_id');
        $damage = DamageReport::query()->create([
            'damage_report_code' => 'DMG-TEST-' . $parentId, 'report_id' => $parentId,
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

    private function finishInService(DamageReport $damage): DamageReport
    {
        return app(DamageReportService::class)->updateStatus($damage, $this->payload(), $this->staffAuth());
    }

    private function issuanceHistory(): object
    {
        return DB::table('damage_report_histories')->where('action_type', 'need_change_stock_issued')->first();
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
