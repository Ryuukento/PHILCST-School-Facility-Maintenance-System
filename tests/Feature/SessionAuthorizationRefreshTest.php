<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureApiAuthenticated;
use App\Http\Middleware\EnsureRole;
use App\Models\Dispatch;
use App\Models\MaintenanceReport;
use App\Services\DispatchAuthorizationService;
use App\Services\ReportAuthorizationService;
use App\Services\SmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\TestCase;

class SessionAuthorizationRefreshTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;

    private int $userId;
    private int $otherId;
    private int $deptA;
    private int $deptB;
    private int $reportA;
    private int $reportB;
    private int $dispatchId;
    private array $nativeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('session_authorization_refresh_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->forceLocalTestUrl();
        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? [];
        $_SESSION = [];
        unset($GLOBALS['sfms_native_authorization_account']);
        require_once base_path('public/frontend/includes/session-guard.php');

        // Only synthetic schema after the environment/actual connection guards.
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createMaintenanceReportsTable();
        $this->createActivityLogsTable();
        $this->createDispatchesTable();
        $this->createDispatchPersonnelAssignmentsTable();
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
        $this->deptA = DB::table('departments')->insertGetId(['name' => 'Department A', 'status' => 'active'], 'department_id');
        $this->deptB = DB::table('departments')->insertGetId(['name' => 'Department B', 'status' => 'active'], 'department_id');
        $this->userId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $this->deptA,
            'username' => 'refresh_actor', 'password' => Hash::make('synthetic-password')]);
        $this->otherId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->deptB]);
        $this->reportA = $this->seedReport($this->deptA);
        $this->reportB = $this->seedReport($this->deptB);
        $this->dispatchId = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'DSP-SYNTHETIC', 'status' => 'approved', 'department_id' => $this->deptB,
        ]);
        DB::table('dispatch_personnel_assignments')->insert([
            'dispatch_id' => $this->dispatchId, 'personnel_user_id' => $this->userId,
        ]);

        Route::middleware(['web', EnsureApiAuthenticated::class])->get('/api/um02/probe', function (Request $request) {
            $auth = $request->session()->get('auth_user');
            $reports = app(ReportAuthorizationService::class);
            $dispatches = app(DispatchAuthorizationService::class);
            return response()->json([
                'auth' => $auth,
                'user' => $request->session()->get('user'),
                'flat' => array_intersect_key($request->session()->all(), array_flip(['user_id', 'role', 'department_id', 'status'])),
                'native' => $_SESSION,
                'report_a' => $reports->canModifyReport($auth, MaintenanceReport::findOrFail($this->reportA)),
                'report_b' => $reports->canModifyReport($auth, MaintenanceReport::findOrFail($this->reportB)),
                'can_create_dispatch' => $dispatches->canCreateDispatch($auth),
                'can_release_dispatch' => $dispatches->canReleaseDispatch($auth, Dispatch::findOrFail($this->dispatchId)),
                'can_approve_need_change' => $reports->canApproveNeedChange($auth),
            ]);
        });
        Route::middleware(['web', EnsureApiAuthenticated::class, EnsureRole::class . ':maintenance_admin,super_admin'])
            ->get('/api/um02/head', fn () => response()->json(['allowed' => true]));
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        unset($GLOBALS['sfms_native_authorization_account']);
        parent::tearDown();
    }

    public function test_head_demotion_takes_effect_on_next_request(): void
    {
        $this->withSession(['auth_user' => $this->payload()])->getJson('/api/um02/head')->assertOk();
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_staff']);
        $this->getJson('/api/um02/head')->assertForbidden();
        $this->getJson('/api/um02/probe')->assertOk()->assertJsonPath('auth.role', 'maintenance_staff')
            ->assertJsonPath('can_create_dispatch', false)->assertJsonPath('can_release_dispatch', true);
    }

    public function test_promotion_is_available_without_relogin(): void
    {
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_staff']);
        $this->withSession(['auth_user' => $this->payload('maintenance_staff')])->getJson('/api/um02/head')->assertForbidden();
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_admin']);
        $this->getJson('/api/um02/head')->assertOk();
    }

    public function test_transfer_denies_old_department_and_preserves_assignment_rules(): void
    {
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_staff']);
        $this->withSession(['auth_user' => $this->payload('maintenance_staff')])->getJson('/api/um02/probe')
            ->assertJsonPath('report_a', true)->assertJsonPath('report_b', false);
        $assignments = DB::table('maintenance_reports')->pluck('assigned_to', 'report_id')->all();
        DB::table('users')->where('user_id', $this->userId)->update(['department_id' => $this->deptB]);
        $this->getJson('/api/um02/probe')->assertJsonPath('report_a', false)->assertJsonPath('report_b', true);
        $this->assertSame($assignments, DB::table('maintenance_reports')->pluck('assigned_to', 'report_id')->all());
        DB::table('maintenance_reports')->where('report_id', $this->reportB)->update(['assigned_to' => $this->otherId]);
        $this->getJson('/api/um02/probe')->assertJsonPath('report_b', false);
        $this->assertSame(1, DB::table('dispatch_personnel_assignments')->count());
    }

    public function test_database_null_department_clears_every_cached_value(): void
    {
        $_SESSION = ['user' => $this->payload(), 'department_id' => $this->deptA];
        DB::table('users')->where('user_id', $this->userId)->update(['department_id' => null]);
        $response = $this->withSession(['auth_user' => $this->payload(), 'department_id' => $this->deptA])->getJson('/api/um02/probe');
        foreach (['auth.department_id', 'user.department_id', 'flat.department_id', 'native.user.department_id',
            'native.auth_user.department_id', 'native.department_id'] as $key) $response->assertJsonPath($key, null);
        $response->assertJsonPath('report_a', false)->assertJsonPath('report_b', false);
    }

    public static function sessionStores(): array
    {
        return ['Laravel auth_user only' => ['laravel'], 'Laravel user only' => ['old_laravel'],
            'native only' => ['native'], 'both stores' => ['both']];
    }

    #[DataProvider('sessionStores')]
    public function test_supported_sessions_refresh_and_preserve_unrelated_metadata(string $store): void
    {
        $old = $this->payload('maintenance_staff');
        if (in_array($store, ['native', 'both'], true)) $_SESSION = ['user' => $old, 'last_activity' => 123, 'native_custom' => 'kept'];
        if ($store !== 'native') $this->withSession([$store === 'old_laravel' ? 'user' : 'auth_user' => $old, 'custom' => 'kept']);
        $response = $this->getJson('/api/um02/probe')->assertOk()
            ->assertJsonPath('auth.role', 'maintenance_admin')->assertJsonPath('user.role', 'maintenance_admin')
            ->assertJsonPath('flat.role', 'maintenance_admin')->assertJsonPath('auth.display_metadata', 'kept');
        if (in_array($store, ['native', 'both'], true)) {
            $response->assertJsonPath('native.auth_user.role', 'maintenance_admin')
                ->assertJsonPath('native.user.role', 'maintenance_admin')->assertJsonPath('native.role', 'maintenance_admin')
                ->assertJsonPath('native.department_id', $this->deptA)->assertJsonPath('native.last_activity', 123)
                ->assertJsonPath('native.native_custom', 'kept');
        } else {
            $this->assertSame([], $_SESSION, 'A CLI Laravel-only request has no native session to persist.');
        }
        $this->assertArrayNotHasKey('password', $response->json('auth'));
        $this->assertArrayNotHasKey('remember_token', $response->json('auth'));
    }

    public function test_stale_native_permissions_cannot_restore_revoked_permissions(): void
    {
        $_SESSION = ['auth_user' => $this->payload(), 'user' => $this->payload(), 'role' => 'maintenance_admin'];
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_staff', 'department_id' => $this->deptB]);
        $this->withSession(['auth_user' => $this->payload()])->getJson('/api/um02/probe')
            ->assertJsonPath('auth.role', 'maintenance_staff')->assertJsonPath('native.role', 'maintenance_staff');
        $_SESSION['auth_user'] = $this->payload();
        $_SESSION['user'] = $this->payload();
        $this->getJson('/api/um02/head')->assertForbidden();
        $this->assertSame($this->deptB, $_SESSION['auth_user']['department_id']);
    }

    public static function invalidStatuses(): array
    {
        return ['inactive' => ['inactive'], 'suspended' => ['suspended'], 'pending' => ['pending'], 'deleted' => ['deleted']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_non_active_api_account_is_rejected_and_both_stores_are_cleared(string $status): void
    {
        $_SESSION = ['auth_user' => $this->payload()];
        if ($status === 'deleted') DB::table('users')->where('user_id', $this->userId)->delete();
        else DB::table('users')->where('user_id', $this->userId)->update(['status' => $status]);
        $this->withSession(['auth_user' => $this->payload()])->getJson('/api/um02/probe')->assertUnauthorized()
            ->assertSessionMissing('auth_user')->assertSessionMissing('user')->assertSessionMissing('role');
        $this->assertSame([], $_SESSION);
    }

    public static function conflicts(): array
    {
        return ['between stores' => ['stores'], 'native primary' => ['native'], 'Laravel primary' => ['laravel'],
            'legacy fallback identities' => ['fallback'], 'malformed identity' => ['malformed']];
    }

    #[DataProvider('conflicts')]
    public function test_genuine_identity_conflicts_fail_closed_without_lookup(string $kind): void
    {
        $other = array_replace($this->payload(), ['user_id' => $this->otherId]);
        $laravel = ['auth_user' => $this->payload()];
        if ($kind === 'stores') $_SESSION = ['user' => $other];
        if ($kind === 'native') $_SESSION = ['auth_user' => $other, 'user' => $this->payload()];
        if ($kind === 'laravel') { $laravel = ['auth_user' => $other, 'user' => $this->payload()]; $_SESSION = ['user' => $this->payload()]; }
        if ($kind === 'fallback') { $laravel = ['user' => $this->payload()]; $_SESSION = ['user' => $other]; }
        if ($kind === 'malformed') $_SESSION = ['user' => ['user_id' => 'not-an-id']];
        DB::connection()->enableQueryLog();
        $this->withSession($laravel)->getJson('/api/um02/probe')->assertUnauthorized();
        $this->assertCount(0, $this->userQueries());
        $this->assertSame([], $_SESSION);
    }

    public function test_compatibility_copies_do_not_override_primary_authenticated_identity(): void
    {
        $other = array_replace($this->payload(), ['user_id' => $this->otherId, 'full_name' => 'Wrong account']);
        $this->withSession(['auth_user' => $this->payload(), 'user' => $other, 'user_id' => $this->otherId])
            ->getJson('/api/um02/probe')->assertOk()->assertJsonPath('auth.user_id', $this->userId)
            ->assertJsonPath('user.user_id', $this->userId)->assertJsonPath('user.full_name', 'Synthetic actor')
            ->assertJsonPath('flat.user_id', $this->userId);
        $pdo = $this->nativeDatabase();
        $_SESSION = ['auth_user' => $this->payload(), 'user' => $other, 'user_id' => $this->otherId];
        $this->assertTrue(sfms_refresh_native_authorization($pdo));
        $this->assertSame($this->userId, $_SESSION['user_id']);
        $this->assertSame('Synthetic actor', $_SESSION['user']['full_name']);
    }

    public function test_forged_request_fields_do_not_change_authority(): void
    {
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_staff']);
        $before = DB::table('users')->orderBy('user_id')->get()->toArray();
        $this->withSession(['auth_user' => $this->payload('super_admin')])
            ->getJson('/api/um02/probe?role=super_admin&department_id=' . $this->deptB . '&user_id=' . $this->otherId)
            ->assertJsonPath('auth.user_id', $this->userId)->assertJsonPath('auth.role', 'maintenance_staff')
            ->assertJsonPath('auth.department_id', $this->deptA)->assertJsonPath('can_approve_need_change', false);
        $this->assertEquals($before, DB::table('users')->orderBy('user_id')->get()->toArray());
    }

    public static function aliases(): array
    {
        return ['Head alias' => ['admin_maintenance', 'maintenance_admin'],
            'Staff alias' => ['eelab_staff', 'maintenance_staff'], 'personnel alias' => ['maintenance_personnel', 'maintenance_staff']];
    }

    #[DataProvider('aliases')]
    public function test_api_aliases_are_normalized_from_database(string $raw, string $canonical): void
    {
        DB::table('users')->where('user_id', $this->userId)->update(['role' => $raw]);
        $this->withSession(['auth_user' => $this->payload('super_admin')])->getJson('/api/um02/probe')
            ->assertJsonPath('auth.role', $canonical)->assertJsonPath('flat.role', $canonical)
            ->assertJsonPath('can_release_dispatch', true);
    }

    public function test_administrator_and_active_user_policies_remain_unchanged(): void
    {
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'super_admin']);
        $this->withSession(['auth_user' => $this->payload()])->getJson('/api/users')->assertOk();
        $this->getJson('/api/um02/probe')->assertJsonPath('can_approve_need_change', true)->assertJsonPath('can_release_dispatch', false);
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'user']);
        $this->getJson('/api/departments')->assertOk();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_refresh_replaces_the_existing_status_query_and_anonymous_uses_none(): void
    {
        DB::connection()->enableQueryLog();
        $this->getJson('/api/auth/check')->assertUnauthorized();
        $this->assertCount(0, $this->userQueries());
        DB::connection()->flushQueryLog();
        $response = $this->withSession(['auth_user' => $this->payload()])->getJson('/api/auth/check')->assertOk();
        $this->assertCount(1, $this->userQueries());
        $this->assertStringNotContainsString('password', $this->userQueries()[0]['query']);
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
    }

    public function test_login_and_logout_preserve_both_session_systems(): void
    {
        $this->postJson('/api/auth/login', ['username' => 'refresh_actor', 'password' => 'synthetic-password'])->assertOk();
        $this->assertSame($this->userId, $_SESSION['auth_user']['user_id']);
        DB::table('users')->where('user_id', $this->userId)->update(['department_id' => $this->deptB]);
        $this->getJson('/api/auth/check')->assertOk()->assertJsonPath('data.user.department_id', $this->deptB);
        $this->postJson('/api/auth/logout')->assertOk()->assertSessionMissing('auth_user');
        $this->assertSame([], $_SESSION);
        $this->getJson('/api/auth/check')->assertUnauthorized();
    }

    public function test_native_refresh_uses_current_role_null_department_and_preserves_idle_time(): void
    {
        $pdo = $this->nativeDatabase('active', 'eelab_staff', null);
        $_SESSION = ['user' => $this->payload(), 'last_activity' => 123, 'session_token' => 'kept', 'custom' => 'kept'];
        $this->assertTrue(sfms_refresh_native_authorization($pdo));
        foreach (['user', 'auth_user'] as $key) {
            $this->assertSame('maintenance_staff', $_SESSION[$key]['role']);
            $this->assertNull($_SESSION[$key]['department_id']);
            $this->assertArrayNotHasKey('password', $_SESSION[$key]);
        }
        $this->assertSame('maintenance_staff', $_SESSION['role']);
        $this->assertNull($_SESSION['department_id']);
        $this->assertSame(123, $_SESSION['last_activity']);
        $this->assertSame('kept', $_SESSION['session_token']);
    }

    public function test_native_request_cache_reapplies_canonical_values_without_another_query(): void
    {
        $pdo = $this->nativeDatabase();
        $_SESSION = ['user' => $this->payload()];
        $this->assertTrue(sfms_refresh_native_authorization($pdo));
        $reads = $pdo->reads;
        $_SESSION['user']['role'] = 'super_admin';
        $this->assertTrue(sfms_refresh_native_authorization($pdo));
        $this->assertSame($reads, $pdo->reads);
        $this->assertSame('maintenance_staff', $_SESSION['user']['role']);
        $pdo->exec("UPDATE users SET role = 'maintenance_admin', department_id = 2");
        // A fresh HTTP request has fresh PHP globals.
        unset($GLOBALS['sfms_native_authorization_account']);
        $this->assertTrue(sfms_refresh_native_authorization($pdo));
        $this->assertSame($reads + 1, $pdo->reads);
        $this->assertSame('maintenance_admin', $_SESSION['auth_user']['role']);
        $this->assertSame(2, $_SESSION['department_id']);
    }

    #[DataProvider('invalidStatuses')]
    public function test_native_non_active_accounts_are_rejected(string $status): void
    {
        $pdo = $this->nativeDatabase($status);
        $_SESSION = ['auth_user' => $this->payload(), 'user_id' => $this->userId];
        $this->assertFalse(sfms_refresh_native_authorization($pdo));
        $this->assertSame([], $_SESSION);
    }

    public function test_native_anonymous_and_conflicts_do_not_query_database(): void
    {
        $pdo = $this->nativeDatabase();
        $before = $pdo->reads;
        $this->assertFalse(sfms_refresh_native_authorization($pdo));
        $_SESSION = ['user' => ['user_id' => 'invalid']];
        $this->assertFalse(sfms_refresh_native_authorization($pdo));
        $this->assertSame($before, $pdo->reads);
        $this->assertSame([], $_SESSION);
    }

    public function test_native_database_failure_does_not_fall_back_to_stale_permissions(): void
    {
        $pdo = $this->nativeDatabase();
        $pdo->exec('DROP TABLE users');
        $_SESSION = ['user' => $this->payload('super_admin')];
        try {
            sfms_refresh_native_authorization($pdo);
            $this->fail('Expected isolated database read failure.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('no such table', $e->getMessage());
        }
        $this->assertSame([], $_SESSION);
    }

    public static function pingStates(): array
    {
        return ['active' => ['active', false], 'inactive' => ['inactive', false], 'suspended' => ['suspended', false],
            'pending' => ['pending', false], 'deleted' => ['deleted', false], 'idle expiry' => ['active', true]];
    }

    #[DataProvider('pingStates')]
    public function test_actual_ping_script_with_isolated_native_memory_session(string $status, bool $expired): void
    {
        // Separate CLI process: session data uses memory callbacks, not disk.
        // Prime the request-local account lookup with explicit SQLite PDO.
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';'
            . 'session_set_save_handler(fn()=>true,fn()=>true,fn()=>"",fn()=>true,fn()=>true,fn()=>0); session_start();'
            . 'require ' . var_export(base_path('public/frontend/includes/session-guard.php'), true) . ';'
            . '$pdo = new PDO("sqlite::memory:"); if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "sqlite") throw new RuntimeException("Unsafe PDO");'
            . '$pdo->exec("CREATE TABLE users (user_id INTEGER, role TEXT, department_id INTEGER, status TEXT, auth_version INTEGER DEFAULT 0, force_profile_update INTEGER DEFAULT 0)");'
            . ($status === 'deleted' ? '' : '$pdo->exec(' . var_export("INSERT INTO users (user_id, role, department_id, status) VALUES (1, 'maintenance_staff', 1, '{$status}')", true) . ');')
            . '$_SESSION = ["user"=>["user_id"=>1,"role"=>"super_admin"],"last_activity"=>time()-' . ($expired ? '3600' : '0') . '];'
            . 'sfms_refresh_native_authorization($pdo);'
            . 'register_shutdown_function(function(){fwrite(STDERR,(string)(http_response_code() ?: 200));});'
            . 'require ' . var_export(base_path('public/frontend/pages/session-ping.php'), true) . ';';
        $process = new Process([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d',
            'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail', '-r', $script], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'APP_DEBUG' => 'false']);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($status === 'active' && !$expired, $data['success']);
        $this->assertSame($status === 'active' && !$expired ? '200' : '401', trim($process->getErrorOutput()));
        if ($expired) $this->assertSame('Session expired', $data['message']);
    }

    public function test_native_hook_is_early_scoped_and_later_pages_do_not_restore_partial_authorization(): void
    {
        $this->assertFalse(sfms_native_authorization_entrypoint(base_path('public/frontend/pages/users.php'), 'cli'));
        $this->assertFalse(sfms_native_authorization_entrypoint(base_path('public/index.php'), 'apache2handler'));
        if (!defined('LARAVEL_START')) $this->assertTrue(sfms_native_authorization_entrypoint(base_path('public/frontend/pages/users.php'), 'apache2handler'));
        $settings = file_get_contents(base_path('public/backend/config/settings.php'));
        $this->assertStringContainsString("PHP_SAPI !== 'cli' && !defined('LARAVEL_START')", $settings);
        $this->assertStringContainsString('if (sfms_native_authorization_entrypoint())', $settings);
        foreach (['users.php', 'dispatch-create.php', 'activity-log.php', 'role-dashboard.php'] as $file) {
            $source = file_get_contents(base_path('public/frontend/pages/' . $file));
            $this->assertLessThan(strpos($source, '$_SESSION'), strpos($source, 'backend/config/settings.php'));
        }
        $this->assertStringNotContainsString('SELECT role FROM users', file_get_contents(base_path('public/frontend/pages/staff-dashboard.php')));
        $account = file_get_contents(base_path('public/frontend/pages/account.php'));
        preg_match('/SELECT user_id, email[^\n]+FROM users/', $account, $select);
        $this->assertNotEmpty($select);
        $this->assertStringNotContainsString('role', $select[0]);
        $this->assertStringNotContainsString('department_id', $select[0]);
    }

    private function payload(string $role = 'maintenance_admin'): array
    {
        return ['user_id' => $this->userId, 'full_name' => 'Synthetic actor', 'role' => $role,
            'department_id' => $this->deptA, 'status' => 'active', 'display_metadata' => 'kept'];
    }

    private function seedReport(int $department): int
    {
        return DB::table('maintenance_reports')->insertGetId(['title' => 'Synthetic report', 'description' => 'Test only',
            'created_by' => $this->userId, 'assigned_to' => $this->userId, 'department_id' => $department,
            'status' => 'submitted', 'priority' => 'medium'], 'report_id');
    }

    private function userQueries(): array
    {
        return array_values(array_filter(DB::connection()->getQueryLog(), static fn ($entry) => str_contains($entry['query'], 'from "users"')));
    }

    private function nativeDatabase(string $status = 'active', string $role = 'maintenance_staff', ?int $department = 2): \PDO
    {
        $pdo = new class('sqlite::memory:') extends \PDO {
            public int $reads = 0;
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->reads++;
                return parent::prepare($query, $options);
            }
        };
        $this->assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, role TEXT, department_id INTEGER, status TEXT, password TEXT, auth_version INTEGER DEFAULT 0, force_profile_update INTEGER DEFAULT 0)');
        if ($status !== 'deleted') {
            $pdo->prepare('INSERT INTO users (user_id, role, department_id, status, password) VALUES (?, ?, ?, ?, ?)')->execute([$this->userId, $role, $department, $status, 'never-copy-this-hash']);
        }
        return $pdo;
    }
}
