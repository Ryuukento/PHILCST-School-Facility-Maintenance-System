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

class PasswordResetSafetyTest extends TestCase
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
        $this->useInMemoryDatabase('password_reset_safety_testing');
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
        $this->createUsersTable(); Schema::table('users', function (Blueprint $table): void { $table->string('verified_email')->nullable(); });
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

    public static function targetRoles(): array
    {
        return ['regular user' => ['user'], 'Maintenance Staff' => ['maintenance_staff'],
            'Head' => ['maintenance_admin'], 'Administrator' => ['super_admin']];
    }

    #[DataProvider('targetRoles')]
    public function test_authorized_reset_is_atomic_and_explicit(string $role): void
    {
        $this->accounts($role);
        $before = (array) DB::table('users')->where('user_id', $this->otherId)->first();
        $this->resetTarget($role === 'super_admin' ? ['current_password' => 'synthetic-password'] : [])->assertOk();
        $after = (array) DB::table('users')->where('user_id', $this->otherId)->first();
        $this->assertTrue(Hash::check('Temporary!123', $after['password']));
        $this->assertNotSame('Temporary!123', $after['password']);
        $this->assertSame(1, (int) $after['force_profile_update']);
        $this->assertSame(1, (int) $after['auth_version']);
        foreach ($before as $key => $value) {
            if (!in_array($key, ['password', 'force_profile_update', 'auth_version', 'updated_at'], true)) $this->assertSame($value, $after[$key], $key);
        }
        $audit = DB::table('activity_logs')->where('action', 'RESET_USER_PASSWORD')->sole();
        $this->assertSame($this->userId, (int) $audit->user_id);
        $this->assertSame($this->otherId, (int) $audit->entity_id);
        $encoded = json_encode((array) $audit);
        foreach (['Temporary!123', 'synthetic-password', $after['password']] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame(0, DB::table('notifications')->where('entity_id', $this->otherId)->where('title', 'Password Reset Request')->where('is_read', 0)->count());
        $this->assertSame(1, DB::table('notifications')->where('entity_id', $this->userId)->where('is_read', 0)->count());
    }

    public static function invalidProofs(): array
    {
        return ['missing' => [null], 'incorrect' => ['wrong-proof'], 'target password' => ['OldTarget!123']];
    }

    #[DataProvider('invalidProofs')]
    public function test_administrator_recovery_requires_the_actual_actor_password(?string $proof): void
    {
        $this->accounts('super_admin');
        $before = $this->snapshot();
        $payload = ['actor_user_id' => $this->otherId, 'user_id' => $this->otherId, 'role' => 'super_admin'];
        if ($proof !== null) $payload['current_password'] = $proof;
        $this->resetTarget($payload)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_self_pending_missing_and_non_admin_resets_are_rejected(): void
    {
        $this->accounts();
        $before = $this->snapshot();
        $this->withSession(['auth_user' => $this->actor()])->postJson("/api/users/{$this->userId}/reset-password",
            ['new_password' => 'Temporary!123'])->assertUnprocessable();
        $this->postJson('/api/users/99999/reset-password', ['new_password' => 'Temporary!123'])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        DB::table('users')->where('user_id', $this->otherId)->update(['status' => 'pending']);
        $before = $this->snapshot();
        $this->resetTarget()->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'maintenance_admin']);
        $this->resetTarget()->assertForbidden();
    }

    public function test_inactive_target_is_not_activated_and_admin_recovery_is_visible_without_edit_controls(): void
    {
        $this->accounts('super_admin');
        DB::table('users')->where('user_id', $this->otherId)->update(['status' => 'inactive']);
        $this->resetTarget(['current_password' => 'synthetic-password'])->assertOk();
        $this->assertSame('inactive', DB::table('users')->where('user_id', $this->otherId)->value('status'));
        $ids = $this->getJson('/api/users')->assertOk()->json('data.users');
        $this->assertContains($this->otherId, array_map(static fn ($u) => (int) $u['user_id'], $ids));
        $this->assertNotContains($this->userId, array_map(static fn ($u) => (int) $u['user_id'], $ids));
    }

    public static function persistenceFailures(): array
    {
        return ['notification' => ['notification'], 'audit exception' => ['audit'], 'audit null' => ['null']];
    }

    #[DataProvider('persistenceFailures')]
    public function test_reset_persistence_failure_rolls_back_every_required_change(string $failure): void
    {
        $this->accounts();
        $before = $this->snapshot();
        $fail = true;
        if ($failure === 'null') {
            $real = app(\App\Services\ActivityLogService::class);
            $mock = \Mockery::mock(\App\Services\ActivityLogService::class)->makePartial();
            $mock->shouldReceive('log')->andReturnUsing(function ($payload, $request = null) use ($real, &$fail) {
                return $fail && ($payload['action'] ?? '') === 'RESET_USER_PASSWORD' ? null : $real->log($payload, $request);
            });
            $this->instance(\App\Services\ActivityLogService::class, $mock);
        } else {
            DB::connection()->beforeExecuting(static function ($sql, $bindings) use ($failure, &$fail): void {
                if ($fail && (($failure === 'notification' && str_starts_with($sql, 'update "notifications"'))
                    || ($failure === 'audit' && str_starts_with($sql, 'insert into "activity_logs"') && in_array('RESET_USER_PASSWORD', $bindings, true)))) {
                    throw new \RuntimeException('Synthetic required persistence failure');
                }
            });
        }
        $this->resetTarget()->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
        $fail = false;
        $this->resetTarget()->assertOk();
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'RESET_USER_PASSWORD')->count());
        $this->assertSame(1, (int) DB::table('users')->where('user_id', $this->otherId)->value('auth_version'));
    }

    public static function oldStores(): array
    {
        return ['Laravel only' => ['laravel'], 'native only' => ['native'], 'combined' => ['both']];
    }

    #[DataProvider('oldStores')]
    public function test_old_sessions_are_rejected_after_reset(string $store): void
    {
        $this->accounts();
        $old = $this->targetSession(0);
        $this->resetTarget()->assertOk();
        $this->app['session']->flush();
        $_SESSION = [];
        if ($store !== 'laravel') $_SESSION = ['auth_user' => $old];
        if ($store !== 'native') $this->withSession(['auth_user' => $old]);
        $this->getJson('/api/auth/check')->assertUnauthorized()->assertSessionMissing('auth_user');
        $this->assertSame([], $_SESSION);
    }

    public static function mixedVersions(): array
    {
        return ['old Laravel current native' => [0, 1], 'current Laravel old native' => [1, 0]];
    }

    #[DataProvider('mixedVersions')]
    public function test_bridge_cannot_conceal_either_original_stale_version(int $laravel, int $native): void
    {
        $this->accounts();
        $this->resetTarget()->assertOk();
        $_SESSION = ['auth_user' => $this->targetSession($native)];
        $this->withSession(['auth_user' => $this->targetSession($laravel)])->getJson('/api/auth/check')->assertUnauthorized();
        $this->assertSame([], $_SESSION);
    }

    public function test_missing_version_compatibility_is_limited_to_database_zero(): void
    {
        $this->accounts();
        $identity = $this->targetSession(0);
        unset($identity['auth_version']);
        $this->withSession(['auth_user' => $identity])->getJson('/api/auth/check')->assertOk();
        DB::table('users')->where('user_id', $this->otherId)->update(['auth_version' => 1]);
        $this->withSession(['auth_user' => $identity])->getJson('/api/auth/check')->assertUnauthorized();
    }

    public function test_temporary_login_and_complete_forced_setup_revoke_all_old_setup_sessions(): void
    {
        $this->accounts();
        $this->resetTarget()->assertOk();
        $this->loginTarget('Temporary!123')->assertOk()->assertJsonPath('data.user.auth_version', 1)
            ->assertJsonPath('data.user.force_profile_update', true);
        $temporary = $_SESSION['auth_user'];
        $this->getJson('/api/departments')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->patchJson('/api/users/profile', $this->completion('Temporary!123', 'Temporary!123'))->assertUnprocessable();
        $this->assertSame(1, (int) DB::table('users')->where('user_id', $this->otherId)->value('force_profile_update'));
        $this->patchJson('/api/users/profile', $this->completion())->assertOk()
            ->assertJsonPath('data.requires_reauthentication', true)->assertSessionMissing('auth_user');
        $target = DB::table('users')->where('user_id', $this->otherId)->first();
        $this->assertSame(0, (int) $target->force_profile_update);
        $this->assertSame(2, (int) $target->auth_version);
        $this->assertSame([], $_SESSION);
        $this->getJson('/api/auth/check')->assertUnauthorized();
        $this->loginTarget('Permanent!123')->assertOk()->assertJsonPath('data.user.auth_version', 2);
        $_SESSION = ['auth_user' => $temporary];
        $this->withSession(['auth_user' => $temporary])->getJson('/api/auth/check')->assertUnauthorized();
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'CHANGE_PASSWORD')->count());
    }

    public function test_exact_setup_actions_remain_available_but_business_and_management_do_not(): void
    {
        $this->accounts();
        $this->resetTarget()->assertOk();
        $this->loginTarget('Temporary!123')->assertOk();
        $this->getJson('/api/auth/check')->assertOk();
        $this->postJson('/api/email-verification/initiate', [])->assertUnprocessable();
        $this->postJson('/api/email-verification/verify-otp', [])->assertUnprocessable();
        $this->postJson('/api/email-verification/resend-otp', [])->assertUnprocessable();
        $this->getJson('/api/email-verification/status')->assertStatus(400);
        $this->postJson('/api/users/profile', ['_method' => 'PATCH'])->assertUnprocessable();
        foreach (['/api/users', '/api/reports', '/api/dispatches', '/api/notifications', '/api/items'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        }
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertSame([], $_SESSION);
    }

    public function test_voluntary_password_change_retains_current_session_and_revokes_others(): void
    {
        $this->accounts();
        $this->loginTarget('OldTarget!123')->assertOk();
        $old = $_SESSION['auth_user'];
        $this->patchJson('/api/users/profile', $this->completion('OldTarget!123', 'Voluntary!123'))
            ->assertOk()->assertJsonPath('data.requires_reauthentication', false);
        $fresh = $_SESSION['auth_user'];
        $this->assertSame(1, $fresh['auth_version']);
        $this->getJson('/api/auth/check')->assertOk();
        $_SESSION = [];
        $this->withSession(['auth_user' => $old])->getJson('/api/auth/check')->assertUnauthorized();
        $this->withSession(['auth_user' => $fresh])->getJson('/api/auth/check')->assertOk();
    }

    public function test_stale_profile_request_cannot_clear_a_newer_reset(): void
    {
        $this->accounts();
        $this->withSession(['auth_user' => $this->targetSession(0)]);
        $changed = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$changed): void {
            if (!$changed && str_starts_with($sql, 'select * from "users" where "user_id" =')) {
                $changed = true;
                DB::table('users')->where('user_id', $this->otherId)->update([
                    'password' => Hash::make('Concurrent!123'), 'auth_version' => 1, 'force_profile_update' => 1,
                ]);
            }
        });
        $this->patchJson('/api/users/profile', ['full_name' => 'Stale profile'])->assertUnauthorized()->assertSessionMissing('auth_user');
        $this->assertTrue($changed);
        $target = DB::table('users')->where('user_id', $this->otherId)->first();
        $this->assertSame(1, (int) $target->auth_version);
        $this->assertSame(1, (int) $target->force_profile_update);
        $this->assertNotSame('Stale profile', $target->full_name);
        $this->assertTrue(Hash::check('Concurrent!123', $target->password));
    }

    public function test_failed_password_change_audit_rolls_back_flag_and_credentials(): void
    {
        $this->accounts();
        $this->resetTarget()->assertOk();
        $this->loginTarget('Temporary!123')->assertOk();
        $before = $this->snapshot();
        DB::connection()->beforeExecuting(static function ($sql, $bindings): void {
            if (str_starts_with($sql, 'insert into "activity_logs"') && in_array('CHANGE_PASSWORD', $bindings, true)) {
                throw new \RuntimeException('Synthetic password-change audit failure');
            }
        });
        $this->patchJson('/api/users/profile', $this->completion())->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $_SESSION['auth_user']['auth_version']);
    }

    public function test_verified_email_login_and_method_override_are_preserved(): void
    {
        $this->accounts();
        $this->postJson('/api/auth/login', ['username' => 'recovery@example.test', 'password' => 'OldTarget!123'])->assertOk();
        $this->postJson('/api/users/profile', array_merge($this->completion('OldTarget!123', 'MethodOverride!123'), ['_method' => 'PATCH']))->assertOk();
        $this->loginTarget('MethodOverride!123')->assertOk();
    }

    public function test_native_version_and_setup_gate_use_current_state(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $pdo->exec('CREATE TABLE users (user_id INTEGER, role TEXT, department_id INTEGER, status TEXT, auth_version INTEGER, force_profile_update INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (1, 'maintenance_staff', NULL, 'active', 1, 1)");
        $_SESSION = ['auth_user' => ['user_id' => 1, 'auth_version' => 0]];
        $this->assertFalse(sfms_refresh_native_authorization($pdo));
        $this->assertSame([], $_SESSION);
        $_SESSION = ['auth_user' => ['user_id' => 1, 'auth_version' => 1]];
        $this->assertTrue(sfms_refresh_native_authorization($pdo));
        $this->assertTrue($_SESSION['auth_user']['force_profile_update']);
        $this->assertTrue(sfms_native_setup_allowed('account.php'));
        $this->assertTrue(sfms_native_setup_allowed('session-ping.php'));
        $this->assertFalse(sfms_native_setup_allowed('users.php'));
        $this->assertFalse(sfms_native_setup_allowed('dispatch-detail.php'));
    }

    public static function avatarOutcomes(): array
    {
        return ['successful commit' => [false], 'audit rollback' => [true]];
    }

    #[DataProvider('avatarOutcomes')]
    public function test_avatar_cleanup_waits_for_credential_commit(bool $fail): void
    {
        $this->accounts();
        $this->resetTarget()->assertOk();
        $this->loginTarget('Temporary!123')->assertOk();
        $root = sys_get_temp_dir() . '/sfms-um03-' . bin2hex(random_bytes(8));
        $dir = $root . '/frontend/assets/uploads/avatars';
        mkdir($dir, 0700, true);
        $this->app->usePublicPath($root);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZxkAAAAASUVORK5CYII=');
        file_put_contents($dir . '/original.png', $bytes);
        file_put_contents($root . '/upload.png', $bytes);
        DB::table('users')->where('user_id', $this->otherId)->update([
            'avatar' => '/School_Facility_Maintenance_System/frontend/assets/uploads/avatars/original.png',
        ]);
        $before = $this->snapshot();
        if ($fail) DB::connection()->beforeExecuting(static function ($sql, $bindings): void {
            if (str_starts_with($sql, 'insert into "activity_logs"') && in_array('CHANGE_PASSWORD', $bindings, true)) throw new \RuntimeException('Synthetic avatar audit failure');
        });
        try {
            $upload = new \Illuminate\Http\UploadedFile($root . '/upload.png', 'avatar.png', 'image/png', null, true);
            $response = $this->post('/api/users/profile', array_merge($this->completion(), ['_method' => 'PATCH', 'profile_picture' => $upload]), ['Accept' => 'application/json']);
            $response->assertStatus($fail ? 500 : 200);
            $this->assertSame($fail, is_file($dir . '/original.png'));
            $this->assertCount(1, glob($dir . '/*'));
            if ($fail) $this->assertSame($before, $this->snapshot());
        } finally {
            // Only this test's newly created, uniquely named temp tree.
            foreach (glob($dir . '/*') as $file) unlink($file);
            if (is_file($root . '/upload.png')) unlink($root . '/upload.png');
            rmdir($dir); rmdir(dirname($dir)); rmdir(dirname($dir, 2)); rmdir(dirname($dir, 3)); rmdir($root);
        }
    }

    public function test_native_forced_setup_gate_redirects_before_protected_page_output(): void
    {
        foreach (['users.php' => '302', 'account.php' => '200', 'session-ping.php' => '200'] as $page => $status) {
            $script = 'require ' . var_export(base_path('public/frontend/includes/session-guard.php'), true) . ';'
                . 'function public_url($path){return $path;} $_SESSION=["auth_user"=>["force_profile_update"=>true]];'
                . '$_SERVER["SCRIPT_FILENAME"]=' . var_export(base_path('public/frontend/pages/' . $page), true) . ';'
                . 'register_shutdown_function(function(){fwrite(STDERR,(string)(http_response_code() ?: 200));});'
                . 'sfms_enforce_forced_setup(); echo "allowed";';
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->mustRun();
            $this->assertSame($status, trim($process->getErrorOutput()));
            $this->assertSame($status === '302' ? '' : 'allowed', $process->getOutput());
        }
    }

    public function test_migration_up_and_down_only_on_guarded_memory_schema(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_version'));
        $migration = require base_path('database/migrations/2026_10_28_000100_add_auth_version_to_users_table.php');
        $migration->up();
        $this->assertSame(0, (int) DB::table('users')->where('user_id', $this->userId)->value('auth_version'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'auth_version'));
    }

    private function accounts(string $targetRole = 'maintenance_staff'): void
    {
        DB::table('users')->where('user_id', $this->userId)->update(['role' => 'super_admin']);
        DB::table('users')->where('user_id', $this->otherId)->update([
            'role' => $targetRole, 'username' => 'recovery_target', 'password' => Hash::make('OldTarget!123'),
            'phone' => '09171234567', 'verified_email' => 'recovery@example.test',
        ]);
        foreach ([$this->otherId, $this->userId] as $id) DB::table('notifications')->insert([
            'user_id' => $this->userId, 'entity_type' => 'user', 'entity_id' => $id,
            'title' => 'Password Reset Request', 'message' => 'Synthetic reset request', 'is_read' => 0,
        ]);
    }

    private function actor(): array
    {
        return ['user_id' => $this->userId, 'role' => 'super_admin', 'department_id' => $this->deptA, 'auth_version' => 0];
    }

    private function targetSession(int $version): array
    {
        return ['user_id' => $this->otherId, 'role' => 'maintenance_staff', 'department_id' => $this->deptB, 'auth_version' => $version];
    }

    private function resetTarget(array $extra = [])
    {
        $_SESSION = [];
        return $this->withSession(['auth_user' => $this->actor()])->postJson("/api/users/{$this->otherId}/reset-password",
            array_merge(['new_password' => 'Temporary!123'], $extra));
    }

    private function loginTarget(string $password)
    {
        $_SESSION = [];
        $this->app['session']->flush();
        return $this->postJson('/api/auth/login', ['username' => 'recovery_target', 'password' => $password]);
    }

    private function completion(string $current = 'Temporary!123', string $next = 'Permanent!123'): array
    {
        return ['full_name' => 'Synthetic target', 'current_password' => $current, 'new_password' => $next];
    }

    private function seedReport(int $department): int
    {
        return DB::table('maintenance_reports')->insertGetId(['title' => 'Synthetic report', 'description' => 'Test',
            'created_by' => $this->userId, 'assigned_to' => $this->userId, 'department_id' => $department,
            'status' => 'submitted'], 'report_id');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['users', 'notifications', 'activity_logs'] as $table) {
            $result[$table] = DB::table($table)->orderBy($table === 'users' ? 'user_id' : 'id')->get()->map(static fn ($row) => (array) $row)->all();
        }
        return $result;
    }
}
