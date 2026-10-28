<?php

namespace Tests\Feature;

use App\Models\Dispatch;
use App\Services\DispatchAuthorizationService;
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

class DispatchItemAuthorizationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $staffId;
    private int $headId;
    private int $roomId;
    private array $previousNativeSession;

    private const BUSINESS_TABLES = [
        'dispatches', 'dispatch_items', 'dispatch_personnel_assignments',
        'items', 'inventory_transactions', 'deployed_assets',
        'asset_code_sequences', 'activity_logs', 'notifications',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // Remove alternate connections before any fixture/schema operation.
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('dispatch_item_authorization_testing');
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

        $this->staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Assigned Staff']);
        $this->headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Requesting Head']);
        $this->roomId = $this->seedRoom();
    }

    protected function tearDown(): void
    {
        if (isset($this->previousNativeSession)) {
            $_SESSION = $this->previousNativeSession;
        }
        parent::tearDown();
    }

    public static function assetPayloads(): array
    {
        return [
            'omitted assets' => ['omitted'],
            'empty assets' => ['empty'],
            'tracked assets' => ['tracked'],
            'empty line entries' => ['empty_line'],
        ];
    }

    #[DataProvider('assetPayloads')]
    public function test_unassigned_staff_are_denied_regardless_of_assets(string $kind): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $otherId = $this->seedUser(['role' => 'maintenance_staff']);
        $payload = ['dispatched_by' => $this->staffId];
        if ($kind !== 'omitted') {
            $payload['assets'] = match ($kind) {
                'tracked' => [$lines[0] => ['ROOM-PC-01']],
                'empty_line' => [$lines[0] => []],
                default => [],
            };
        }
        $before = $this->snapshot();

        $this->actingAsSessionUserWithFlatKeys($otherId, 'maintenance_staff')
            ->postJson($this->endpoint($dispatchId, $lines[0]), $payload)->assertForbidden();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_empty_roster_is_denied_without_business_mutation(): void
    {
        [$dispatchId, $lines] = $this->fixture(roster: []);
        $before = $this->snapshot();
        $this->dispatchLine($dispatchId, $lines[0])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_removed_roster_member_is_denied_without_business_mutation(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        DB::table('dispatch_personnel_assignments')->where('dispatch_id', $dispatchId)->delete();
        $before = $this->snapshot();
        $this->dispatchLine($dispatchId, $lines[0])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_authorization_runs_inside_transaction_before_business_mutation(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $before = $this->snapshot();
        $this->mock(DispatchAuthorizationService::class)
            ->shouldReceive('canReleaseDispatch')->once()
            ->withArgs(function (array $user, Dispatch $dispatch) use ($dispatchId, $before): bool {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertSame($before, $this->snapshot());
                return (int) $user['user_id'] === $this->staffId && (int) $dispatch->id === $dispatchId;
            })->andReturn(false);

        $this->dispatchLine($dispatchId, $lines[0])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_authorized_staff_mark_non_final_line_without_moving_stock(): void
    {
        [$dispatchId, $lines, $itemId] = $this->fixture(lineCount: 2);
        $this->dispatchLine($dispatchId, $lines[0])->assertOk();

        $this->assertSame($this->staffId, (int) DB::table('dispatch_items')->where('id', $lines[0])->value('dispatched_by'));
        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(10, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->assertSame(0, DB::table('deployed_assets')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'DISPATCH_ITEM')->count());
    }

    public function test_non_first_cross_department_roster_member_can_release(): void
    {
        $otherId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => 200]);
        [$dispatchId, $lines] = $this->fixture(roster: [$otherId, $this->staffId]);
        $this->dispatchLine($dispatchId, $lines[0])->assertOk();
        $this->assertReleaseActor($dispatchId, $lines[0]);
    }

    public function test_forged_dispatched_by_is_ignored_for_all_attribution(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $this->dispatchLine($dispatchId, $lines[0], ['dispatched_by' => $this->headId])->assertOk();
        $this->assertReleaseActor($dispatchId, $lines[0]);
    }

    public function test_missing_dispatched_by_uses_authenticated_staff(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $this->dispatchLine($dispatchId, $lines[0])->assertOk();
        $this->assertReleaseActor($dispatchId, $lines[0]);
    }

    public function test_invalid_dispatched_by_is_ignored_without_validation_or_lookup(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $this->dispatchLine($dispatchId, $lines[0], ['dispatched_by' => ['untrusted']])->assertOk();
        $this->assertReleaseActor($dispatchId, $lines[0]);
    }

    public function test_nested_session_identity_wins_over_mismatched_flat_user_id(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $this->actingAsSessionUser($this->staffId, 'maintenance_staff')
            ->withSession(['user_id' => $this->headId])
            ->postJson($this->endpoint($dispatchId, $lines[0]))->assertOk();
        $this->assertReleaseActor($dispatchId, $lines[0]);
    }

    public function test_final_line_releases_stock_once_registers_assets_and_notifies_head(): void
    {
        [$dispatchId, $lines, $itemId] = $this->fixture();
        $this->dispatchLine($dispatchId, $lines[0], ['assets' => [$lines[0] => ['', 'ROOM-PC-02']]])->assertOk();
        $this->assertReleaseActor($dispatchId, $lines[0]);
        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->count());
        $this->assertSame(2, DB::table('deployed_assets')->count());
        $this->assertSame([$this->staffId], DB::table('deployed_assets')->distinct()->pluck('created_by')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->headId)->where('title', 'Dispatch Released')->count());

        // Preserve the existing released-line marking behavior, without a second release.
        $this->dispatchLine($dispatchId, $lines[0])->assertOk();
        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->count());
        $this->assertSame(2, DB::table('deployed_assets')->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'RELEASE_DISPATCH')->count());
        $this->assertSame(1, DB::table('notifications')->where('title', 'Dispatch Released')->count());
    }

    public function test_failed_final_release_rolls_back_current_marking_and_preserves_prior_progress(): void
    {
        [$dispatchId, $lines, $itemId] = $this->fixture(lineCount: 2);
        $this->dispatchLine($dispatchId, $lines[0])->assertOk();
        DB::table('items')->where('id', $itemId)->update(['quantity' => 3]);
        $before = $this->snapshot();

        $this->dispatchLine($dispatchId, $lines[1])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_invalid_asset_rolls_back_final_marking_and_all_business_changes(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $before = $this->snapshot();
        $this->dispatchLine($dispatchId, $lines[0], ['assets' => [$lines[0] => ['SFMS-2026-000123', '']]])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_assets_on_non_final_line_are_rejected_and_marking_is_rolled_back(): void
    {
        [$dispatchId, $lines] = $this->fixture(lineCount: 2);
        $before = $this->snapshot();
        $this->dispatchLine($dispatchId, $lines[0], ['assets' => [$lines[0] => ['', '']]])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }

    public static function deniedAccounts(): array
    {
        return [
            'administrator' => ['super_admin', 'active', 403],
            'head maintenance' => ['maintenance_admin', 'active', 403],
            'inactive staff' => ['maintenance_staff', 'inactive', 401],
        ];
    }

    #[DataProvider('deniedAccounts')]
    public function test_role_and_account_guards_are_preserved(string $role, string $status, int $expected): void
    {
        DB::table('users')->where('user_id', $this->staffId)->update(['role' => $role, 'status' => $status]);
        [$dispatchId, $lines] = $this->fixture();
        $before = $this->snapshot();
        $this->actingAsSessionUserWithFlatKeys($this->staffId, $role)
            ->postJson($this->endpoint($dispatchId, $lines[0]))->assertStatus($expected);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_guest_is_denied_without_business_mutation(): void
    {
        [$dispatchId, $lines] = $this->fixture();
        $before = $this->snapshot();
        $this->postJson($this->endpoint($dispatchId, $lines[0]))->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_line_from_another_dispatch_is_rejected_without_business_mutation(): void
    {
        [$dispatchId] = $this->fixture();
        [, $otherLines] = $this->fixture();
        $before = $this->snapshot();
        $this->dispatchLine($dispatchId, $otherLines[0])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_pending_and_cancelled_dispatches_cannot_be_released(): void
    {
        foreach (['pending', 'cancelled'] as $status) {
            [$dispatchId, $lines] = $this->fixture(status: $status);
            $before = $this->snapshot();
            $this->dispatchLine($dispatchId, $lines[0])->assertStatus(400);
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_main_release_still_allows_rostered_head_with_session_attribution(): void
    {
        [$dispatchId, , $itemId] = $this->fixture(roster: [$this->headId]);
        $this->actingAsSessionUserWithFlatKeys($this->headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/release", ['released_by' => $this->staffId])->assertOk();
        $this->assertSame($this->headId, (int) DB::table('dispatches')->where('id', $dispatchId)->value('released_by'));
        $this->assertSame(8, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    private function fixture(int $lineCount = 1, ?array $roster = null, string $status = 'approved'): array
    {
        $itemId = $this->seedItem(['quantity' => 10]);
        $dispatchId = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'DSP-' . uniqid(), 'status' => $status,
            'requested_by' => $this->headId, 'room_id' => $this->roomId,
            'department_id' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roster ?? [$this->staffId] as $userId) {
            DB::table('dispatch_personnel_assignments')->insert([
                'dispatch_id' => $dispatchId, 'personnel_user_id' => $userId,
                'assigned_by' => $this->headId, 'assigned_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $lines = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $lines[] = DB::table('dispatch_items')->insertGetId([
                'dispatch_id' => $dispatchId, 'item_id' => $itemId, 'quantity' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return [$dispatchId, $lines, $itemId];
    }

    private function endpoint(int $dispatchId, int $lineId): string
    {
        return "/api/dispatches/{$dispatchId}/items/{$lineId}/dispatch";
    }

    private function dispatchLine(int $dispatchId, int $lineId, array $payload = [])
    {
        return $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')
            ->postJson($this->endpoint($dispatchId, $lineId), $payload);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::BUSINESS_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->orderBy($table === 'asset_code_sequences' ? 'year' : 'id')
                ->get()->map(fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }

    private function assertReleaseActor(int $dispatchId, int $lineId): void
    {
        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame($this->staffId, (int) DB::table('dispatch_items')->where('id', $lineId)->value('dispatched_by'));
        $this->assertSame($this->staffId, (int) DB::table('dispatches')->where('id', $dispatchId)->value('released_by'));
        $this->assertSame($this->staffId, (int) DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->value('performed_by'));
        foreach (['DISPATCH_ITEM', 'RELEASE_DISPATCH', 'AUTO_COMPLETE_DISPATCH'] as $action) {
            $log = DB::table('activity_logs')->where('action', $action)->first();
            $this->assertNotNull($log, $action);
            $this->assertSame($this->staffId, (int) $log->user_id, $action);
            $meta = json_decode($log->meta_json, true);
            if ($action === 'DISPATCH_ITEM') {
                $this->assertSame($this->staffId, $meta['dispatched_by']);
            } elseif ($action === 'RELEASE_DISPATCH') {
                $this->assertSame($this->staffId, $meta['released_by']);
            }
        }
    }
}
