<?php

namespace Tests\Feature;

use App\Support\ScannedDispatchDestination as Destination;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\TestCase;

class ScannedDispatchDestinationTest extends TestCase
{
    use ConfiguresIsolatedSqliteConnection;

    private array $nativeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('scanned_dispatch_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->forceLocalTestUrl();
        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->nativeBefore = $_SESSION ?? [];
        $_SESSION = [];
        Schema::create('users', function (Blueprint $table): void {
            $table->integer('user_id')->primary();
            $table->string('role');
            $table->integer('department_id')->nullable();
            $table->string('status');
            $table->integer('auth_version')->default(0);
            $table->boolean('force_profile_update')->default(false);
        });
        DB::table('users')->insert(['user_id' => 7, 'role' => 'maintenance_staff', 'status' => 'active']);
        // Route model binding runs before the custom authentication middleware.
        Schema::create('dispatches', fn (Blueprint $table) => $table->id());
        DB::table('dispatches')->insert(['id' => 6]);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    public static function invalidReferences(): array
    {
        return array_map(fn ($value) => [$value], [null, '', '0', '-1', '+1', '01', ' 6', '6 ',
            '1.0', '1e2', '2147483648', '99999999999999999999', [], ['6'], true, 6.0,
            'https://evil.example', '//evil.example', '../6', '6/approve', '%2f%2fevil.example', "6\n"]);
    }

    #[DataProvider('invalidReferences')]
    public function test_invalid_references_cannot_select_a_destination(mixed $value): void
    {
        $this->assertNull(Destination::id($value));
        $this->assertSame('/dashboard', Destination::destination($value));
        $this->assertSame('/login', Destination::carry('/login', $value));
    }

    public function test_anonymous_scan_preserves_route_id_not_forged_query(): void
    {
        $this->get('/dispatches/6?next_dispatch=99&return_url=https://evil.example')->assertRedirect('/login?next_dispatch=6');
        $this->get('/login?next_dispatch=6')->assertRedirect('/frontend/pages/index.php?next_dispatch=6');
        $this->assertSame('/dispatches/6', Destination::destination('6'));
        $this->get('/api/dispatches/6')->assertUnauthorized();
    }

    public function test_invalid_route_and_normal_login_do_not_create_intent(): void
    {
        $this->get('/dispatches/2147483648')->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/frontend/pages/index.php');
        $this->get('/login?next_dispatch[]=6')->assertRedirect('/frontend/pages/index.php');
    }

    public function test_authenticated_scan_continues_to_existing_detail_route(): void
    {
        $this->withSession(['auth_user' => $this->identity()])->get('/dispatches/6')
            ->assertRedirect('/frontend/pages/dispatch-detail.php?id=6');
        $this->get('/login?next_dispatch=6')->assertRedirect('/dispatches/6');
        $this->get('/login')->assertRedirect('/frontend/pages/dashboard.php');
    }

    public function test_forced_setup_and_second_login_keep_reference_without_session_storage(): void
    {
        DB::table('users')->where('user_id', 7)->update(['force_profile_update' => true]);
        $this->withSession(['auth_user' => $this->identity()])->get('/dispatches/6')
            ->assertRedirect('/account?setup=1&next_dispatch=6');
        $this->getJson('/api/dispatches/6')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $relogin = Destination::carry('/login?password_changed=1', '6');
        $this->assertSame('/login?password_changed=1&next_dispatch=6', $relogin);
        // Match the existing password-change boundary: no saved session intent survives.
        session()->invalidate();
        $_SESSION = [];
        DB::table('users')->where('user_id', 7)->update(['force_profile_update' => false, 'auth_version' => 1]);
        $this->get($relogin)->assertRedirect('/frontend/pages/index.php?next_dispatch=6');
        $this->withSession(['auth_user' => $this->identity(1)])->get('/dispatches/6')
            ->assertRedirect('/frontend/pages/dispatch-detail.php?id=6');
    }

    public function test_revoked_session_preserves_only_navigation_and_remains_unauthorized(): void
    {
        DB::table('users')->where('user_id', 7)->update(['auth_version' => 1]);
        $this->withSession(['auth_user' => $this->identity()])->get('/dispatches/6')
            ->assertRedirect('/frontend/pages/index.php?session_expired=1&next_dispatch=6');
        $this->getJson('/api/dispatches/6')->assertUnauthorized();
    }

    public function test_disabled_account_stays_blocked(): void
    {
        DB::table('users')->where('user_id', 7)->update(['status' => 'inactive']);
        $this->withSession(['auth_user' => $this->identity()])->get('/dispatches/6')
            ->assertRedirect('/frontend/pages/index.php?session_expired=1&next_dispatch=6');
        $this->getJson('/api/dispatches/6')->assertUnauthorized();
    }

    public function test_logout_does_not_forward_reference(): void
    {
        $beforeGet = $_GET;
        $beforeFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        try {
            $_GET = ['next_dispatch' => '6'];
            $_SERVER['SCRIPT_FILENAME'] = 'logout.php';
            $this->assertNull(Destination::nativeReference());
            $_SERVER['SCRIPT_FILENAME'] = 'account.php';
            $this->assertSame(6, Destination::nativeReference());
            $_SERVER['SCRIPT_FILENAME'] = 'dispatch-detail.php';
            $_GET = ['id' => '9', 'next_dispatch' => '6'];
            $this->assertSame(9, Destination::nativeReference());
        } finally {
            $_GET = $beforeGet;
            if ($beforeFilename === null) unset($_SERVER['SCRIPT_FILENAME']);
            else $_SERVER['SCRIPT_FILENAME'] = $beforeFilename;
        }
        $this->assertStringNotContainsString('next_dispatch', file_get_contents(public_path('frontend/pages/logout.php')));
    }

    public function test_standalone_native_setup_redirect_preserves_reference_without_composer(): void
    {
        $code = 'require ' . var_export(public_path('frontend/includes/session-guard.php'), true) . ';'
            . 'function public_url($path){fwrite(STDERR,$path);return $path;}'
            . '$_SESSION=["auth_user"=>["force_profile_update"=>true]];'
            . '$_SERVER["SCRIPT_FILENAME"]="index.php";$_GET=["next_dispatch"=>"6"];'
            . 'sfms_enforce_forced_setup(); echo "must-not-render";';
        $process = new Process([PHP_BINARY, '-r', $code], base_path());
        $process->mustRun();
        $this->assertSame('', $process->getOutput());
        $this->assertSame('/account?setup=1&next_dispatch=6', $process->getErrorOutput());
    }

    private function identity(int $version = 0): array
    {
        return ['user_id' => 7, 'role' => 'maintenance_staff', 'department_id' => null, 'auth_version' => $version];
    }
}
