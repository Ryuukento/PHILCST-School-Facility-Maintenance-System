<?php

namespace Tests\Feature;

use App\Models\Dispatch;
use App\Services\ActivityLogService;
use App\Services\DispatchService;
use App\Services\SmsService;
use Illuminate\Auth\Access\AuthorizationException;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class DispatchCancellationSafetyTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $adminId;
    private int $headId;
    private int $staffId;
    private int $stockId;
    private int $roomId;
    private array $previousNativeSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('dispatch_cancellation_safety_testing');
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

        // Only synthetic, in-memory schema, after the connection assertions.
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedInteger('dispatch_id')->nullable();
        });
        $this->createActivityLogsTable();
        $this->createMaintenanceReportsTable();
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();
        $this->createDispatchPersonnelAssignmentsTable();
        $this->createDeployedAssetsTable();
        $this->createAssetCodeSequencesTable();
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
        $this->adminId = $this->seedUser(['role' => 'super_admin']);
        $this->headId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $this->roomId = $this->seedRoom();
        $this->stockId = $this->seedItem(['quantity' => 10, 'reserved_quantity' => 2]);
    }

    protected function tearDown(): void
    {
        if (isset($this->previousNativeSession)) $_SESSION = $this->previousNativeSession;
        parent::tearDown();
    }

    public static function eligibleStatuses(): array
    {
        return ['pending' => ['pending'], 'approved' => ['approved']];
    }

    #[DataProvider('eligibleStatuses')]
    public function test_administrator_can_cancel_without_changing_inventory_or_personnel(string $status): void
    {
        [$id] = $this->fixture($status);
        $history = $this->inventoryAndPersonnel();
        $before = (array) DB::table('dispatches')->find($id);
        $this->cancel($id)->assertOk()->assertJsonPath('message', 'Dispatch cancelled successfully');
        $after = (array) DB::table('dispatches')->find($id);
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame('Original notes | CANCELLED: Duplicate request', $after['notes']);
        foreach (['requested_by', 'approved_by', 'released_by', 'room_id', 'receiver_user_id'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertSame($history, $this->inventoryAndPersonnel());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'CANCEL_DISPATCH')->count());
        $audit = DB::table('activity_logs')->where('action', 'CANCEL_DISPATCH')->first();
        $this->assertSame($this->adminId, (int) $audit->user_id);
        $this->assertSame('super_admin', $audit->user_role);
        $this->assertSame($id, (int) $audit->entity_id);
        $meta = json_decode($audit->meta_json, true);
        $this->assertSame('Duplicate request', $meta['reason']);
        $this->assertSame($status, $meta['from_status']);
    }

    public static function deniedRoles(): array
    {
        return ['head' => ['maintenance_admin'], 'maintenance staff' => ['maintenance_staff'], 'regular user' => ['user']];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_cancel_even_with_forged_admin_identity(string $role): void
    {
        [$id] = $this->fixture();
        $actor = $this->seedUser(['role' => $role]);
        $before = $this->snapshot();
        $this->actingAsSessionUserWithFlatKeys($actor, $role)->postJson("/api/dispatches/{$id}/cancel", [
            'reason' => 'Forged request', 'user_id' => $this->adminId, 'actor_user_id' => $this->adminId, 'role' => 'super_admin',
        ])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_guest_and_inactive_administrator_are_denied(): void
    {
        [$id] = $this->fixture();
        $before = $this->snapshot();
        $this->postJson("/api/dispatches/{$id}/cancel", ['reason' => 'Not allowed'])->assertUnauthorized();
        DB::table('users')->where('user_id', $this->adminId)->update(['status' => 'inactive']);
        $this->cancel($id)->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_service_rejects_a_stale_administrator_session_and_direct_non_admin_actor(): void
    {
        [$id] = $this->fixture();
        DB::table('users')->where('user_id', $this->adminId)->update(['role' => 'maintenance_admin']);
        $before = $this->snapshot();
        $this->cancel($id)->assertForbidden();
        $this->detail($id)->assertOk()->assertJsonPath('data.can_cancel', false);
        foreach ([$this->headId, $this->staffId, null] as $actor) {
            try {
                app(DispatchService::class)->cancelDispatch(Dispatch::findOrFail($id), 'Direct call', $actor);
                $this->fail('Unauthorized service call succeeded.');
            } catch (AuthorizationException $e) {
                $this->assertSame('Only the Administrator may cancel dispatches.', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_authenticated_identity_wins_over_flat_session_and_request_identity(): void
    {
        [$id] = $this->fixture();
        $this->actingAsSessionUser($this->adminId, 'super_admin')->withSession(['user_id' => $this->headId])
            ->postJson("/api/dispatches/{$id}/cancel", ['reason' => 'Valid request', 'actor_user_id' => $this->staffId, 'user_id' => $this->headId])->assertOk();
        $this->assertSame($this->adminId, (int) DB::table('activity_logs')->where('action', 'CANCEL_DISPATCH')->value('user_id'));
    }

    public static function ineligibleStatuses(): array
    {
        return ['released' => ['released'], 'cancelled' => ['cancelled'], 'unknown' => ['unknown'], 'completed' => ['completed']];
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_ineligible_states_are_unchanged(string $status): void
    {
        [$id] = $this->fixture($status);
        $before = $this->snapshot();
        $this->cancel($id)->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('eligibleStatuses')]
    public function test_deployment_history_blocks_cancellation_even_with_eligible_status(string $status): void
    {
        [$id] = $this->fixture($status);
        // Synthetic inconsistent history; do not invoke the stock observer.
        DB::table('inventory_transactions')->insert([
            'item_id' => $this->stockId, 'dispatch_id' => $id, 'transaction_type' => 'deploy', 'quantity' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->snapshot();
        $this->cancel($id)->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
        $this->detail($id)->assertOk()->assertJsonPath('data.can_cancel', false);
    }

    public function test_duplicate_cancellation_cannot_duplicate_audit_or_change_reason(): void
    {
        [$id] = $this->fixture();
        $this->cancel($id)->assertOk();
        $before = $this->snapshot();
        $this->cancel($id, ['reason' => 'Different reason'])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidReasons(): array
    {
        return ['missing' => [[]], 'empty' => [['reason' => '']], 'short' => [['reason' => 'ab']], 'long' => [['reason' => str_repeat('a', 501)]], 'array' => [['reason' => ['invalid']]]];
    }

    #[DataProvider('invalidReasons')]
    public function test_reason_validation_rejects_without_mutation(array $payload): void
    {
        [$id] = $this->fixture();
        $before = $this->snapshot();
        $this->actingAsSessionUser($this->adminId, 'super_admin')->postJson("/api/dispatches/{$id}/cancel", $payload)->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reason_length_boundaries_and_empty_notes_are_supported(): void
    {
        foreach (['abc', str_repeat('a', 500)] as $reason) {
            [$id] = $this->fixture();
            DB::table('dispatches')->where('id', $id)->update(['notes' => null]);
            $this->cancel($id, ['reason' => $reason])->assertOk();
            $this->assertSame('CANCELLED: ' . $reason, DB::table('dispatches')->find($id)->notes);
        }
    }

    public function test_stale_model_cannot_overwrite_released_state(): void
    {
        [$id] = $this->fixture();
        $stale = Dispatch::findOrFail($id);
        DB::table('dispatches')->where('id', $id)->update(['status' => 'released', 'notes' => 'Current released notes']);
        $before = $this->snapshot();
        try {
            app(DispatchService::class)->cancelDispatch($stale, 'Stale request', $this->adminId);
            $this->fail('Stale cancellation succeeded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_cancellation_appends_current_notes_instead_of_stale_notes(): void
    {
        [$id] = $this->fixture();
        $stale = Dispatch::findOrFail($id);
        DB::table('dispatches')->where('id', $id)->update(['notes' => 'Latest notes']);
        app(DispatchService::class)->cancelDispatch($stale, 'Current cancellation', $this->adminId);
        $this->assertSame('Latest notes | CANCELLED: Current cancellation', DB::table('dispatches')->find($id)->notes);
    }

    public function test_cancellation_requests_parent_lock_inside_transaction_before_mutation(): void
    {
        [$id] = $this->fixture();
        $grammar = new class(DB::connection()) extends SQLiteGrammar {
            public array $reads = [];
            public function compileSelect(Builder $query)
            {
                if ($query->from === 'dispatches') {
                    $this->reads[] = ['lock' => $query->lock, 'depth' => $this->connection->transactionLevel()];
                }
                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        $observed = false;
        DB::listen(function (QueryExecuted $event) use ($grammar, &$observed): void {
            if (str_starts_with($event->sql, 'update "dispatches"')) {
                $observed = true;
                // Route binding is unlocked; the service's second read must be locked.
                $this->assertTrue($grammar->reads[1]['lock']);
                $this->assertGreaterThan(0, $grammar->reads[1]['depth']);
                $this->assertGreaterThan(0, $event->connection->transactionLevel());
            }
        });
        $this->cancel($id)->assertOk();
        $this->assertTrue($observed);
    }

    public static function auditFailures(): array
    {
        return ['exception' => [true], 'missing record' => [false]];
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_cancellation(bool $throw): void
    {
        [$id] = $this->fixture();
        $before = $this->snapshot();
        $this->mock(ActivityLogService::class)->shouldReceive('logFromSession')->once()
            ->andReturnUsing(function (array $payload) use ($id, $throw) {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertSame('cancelled', DB::table('dispatches')->find($id)->status);
                $this->assertSame($this->adminId, $payload['user_id']);
                if ($throw) throw new RuntimeException('Synthetic audit persistence failure');
                return null;
            });
        $this->cancel($id)->assertStatus(500)->assertJsonPath('message', 'Failed to cancel dispatch. Please try again.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_release_after_cancellation_moves_no_stock(): void
    {
        [$id] = $this->fixture();
        $this->cancel($id)->assertOk();
        $before = $this->snapshot();
        $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')->postJson("/api/dispatches/{$id}/release")->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_cancellation_after_release_preserves_stock_assets_and_ledger(): void
    {
        [$id, $line] = $this->fixture();
        $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')->postJson("/api/dispatches/{$id}/release", ['assets' => [$line => ['LAB-PC-01', '']]])->assertOk();
        $before = $this->snapshot();
        $this->cancel($id)->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(8, (int) DB::table('items')->find($this->stockId)->quantity);
        $this->assertSame(2, DB::table('deployed_assets')->count());
    }

    public function test_automatic_completion_and_cancellation_respect_current_status(): void
    {
        [$id, $line] = $this->fixture();
        $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')->postJson("/api/dispatches/{$id}/items/{$line}/dispatch")->assertOk();
        $before = $this->snapshot();
        $this->cancel($id)->assertStatus(400);
        $this->assertSame($before, $this->snapshot());

        [$other, $otherLine] = $this->fixture();
        $this->cancel($other)->assertOk();
        $before = $this->snapshot();
        $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')->postJson("/api/dispatches/{$other}/items/{$otherLine}/dispatch")->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_rejection_remains_separate_and_historical_cancelled_records_are_preserved(): void
    {
        [$historical] = $this->fixture('cancelled');
        $history = (array) DB::table('dispatches')->find($historical);
        [$id] = $this->fixture('pending');
        $this->actingAsSessionUserWithFlatKeys($this->adminId, 'super_admin')->postJson("/api/dispatches/{$id}/reject", ['reason' => 'Rejected independently'])->assertOk();
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'REJECT_DISPATCH')->count());
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'CANCEL_DISPATCH')->count());
        $this->assertSame($history, (array) DB::table('dispatches')->find($historical));
        $this->assertSame(10, (int) DB::table('items')->find($this->stockId)->quantity);
    }

    public function test_detail_capability_is_administrator_only_and_status_sensitive(): void
    {
        foreach (['pending' => true, 'approved' => true, 'released' => false, 'cancelled' => false] as $status => $eligible) {
            [$id] = $this->fixture($status);
            $this->detail($id)->assertOk()->assertJsonPath('data.can_cancel', $eligible)->assertJsonPath('data.dispatch.status', $status);
            foreach ([[$this->headId, 'maintenance_admin'], [$this->staffId, 'maintenance_staff']] as [$actor, $role]) {
                $this->actingAsSessionUser($actor, $role)->getJson("/api/dispatches/{$id}")->assertOk()->assertJsonPath('data.can_cancel', false);
            }
        }
    }

    public static function frontendRoles(): array
    {
        return ['administrator' => ['super_admin', true], 'head' => ['maintenance_admin', false], 'maintenance staff' => ['maintenance_staff', false], 'regular user' => ['user', false]];
    }

    #[DataProvider('frontendRoles')]
    public function test_actual_frontend_cancel_fragments_are_rendered_only_for_administrator(string $role, bool $visible): void
    {
        $source = file_get_contents(base_path('public/frontend/pages/dispatch-detail.php'));
        $this->assertSame(1, preg_match('/\$canCancel\s*=\s*([^;]+);/', $source, $assignment));
        $this->assertSame(2, preg_match_all('/<\?php if \(\$canCancel\): \?>(.*?)<\?php endif; \?>/s', $source, $blocks));
        // Evaluate only the real, self-contained capability and role-gated HTML
        // fragments. Never include the page, which loads legacy database code.
        $_ddRole = $role;
        eval('$canCancel = ' . $assignment[1] . ';');
        ob_start();
        try {
            foreach ($blocks[0] as $block) eval('?>' . $block);
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame($visible, str_contains($html, 'id="dd-cancel-btn"'));
        $this->assertSame($visible, str_contains($html, 'id="dd-cancel-modal"'));
        if ($visible) {
            $this->assertStringContainsString('Confirm Cancellation', $html);
            $this->assertStringContainsString('required minlength="3" maxlength="500"', $html);
        }
    }

    public function test_frontend_requires_explicit_server_eligibility_and_confirmation_wiring(): void
    {
        $source = file_get_contents(base_path('public/frontend/pages/dispatch-detail.php'));
        $this->assertStringContainsString('DSP_CAN_CANCEL && dispatch.can_cancel === true', $source);
        $this->assertStringContainsString('dispatch.can_cancel = payload.data?.can_cancel === true;', $source);
        $this->assertStringContainsString("ddShowButton('dd-cancel-btn', false)", $source);
        $this->assertStringContainsString('DD_CURRENT_DISPATCH?.can_cancel !== true || DD_CANCEL_SUBMITTING', $source);
        $this->assertStringContainsString('reason.length < 3 || reason.length > 500', $source);
        $this->assertStringContainsString("'dd-cancel-confirm-btn',        doCancel", $source);
    }

    private function fixture(string $status = 'approved'): array
    {
        $id = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'SYNTHETIC-' . uniqid(), 'status' => $status,
            'room_id' => $this->roomId, 'requested_by' => $this->headId, 'approved_by' => $this->adminId,
            'notes' => 'Original notes', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dispatch_personnel_assignments')->insert([
            'dispatch_id' => $id, 'personnel_user_id' => $this->staffId, 'assigned_by' => $this->headId,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = DB::table('dispatch_items')->insertGetId([
            'dispatch_id' => $id, 'item_id' => $this->stockId, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$id, $line];
    }

    private function cancel(int $id, array $payload = ['reason' => 'Duplicate request'])
    {
        return $this->actingAsSessionUser($this->adminId, 'super_admin')->postJson("/api/dispatches/{$id}/cancel", $payload);
    }

    private function detail(int $id)
    {
        return $this->actingAsSessionUser($this->adminId, 'super_admin')->getJson("/api/dispatches/{$id}");
    }

    private function inventoryAndPersonnel(): array
    {
        $result = [];
        foreach (['items', 'inventory_transactions', 'dispatch_items', 'dispatch_personnel_assignments', 'deployed_assets', 'asset_code_sequences', 'notifications'] as $table) {
            $result[$table] = DB::table($table)->orderBy($table === 'asset_code_sequences' ? 'year' : 'id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $result;
    }

    private function snapshot(): array
    {
        return $this->inventoryAndPersonnel() + [
            'dispatches' => DB::table('dispatches')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'activity_logs' => DB::table('activity_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
