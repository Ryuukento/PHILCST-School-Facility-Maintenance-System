<?php

namespace Tests\Feature;

use App\Services\ActivityLogService;
use App\Services\EmailVerificationService;
use App\Services\SmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class FirstTimeSetupContactRequirementTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $actor;
    private array $nativeBefore;
    private const TEMPORARY = 'Temporary!123';
    private const CHANGED = 'Changed!456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('first_setup_contact_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->forceLocalTestUrl();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->mock(EmailVerificationService::class)->shouldNotReceive('initiateEmailVerification', 'resendOTP', 'verifyOTP');
        $this->nativeBefore = $_SESSION ?? []; $_SESSION = [];
        $this->createUsersTable();
        Schema::table('users', fn (Blueprint $table) => $table->string('verified_email')->nullable()->unique());
        $this->createActivityLogsTable();
        $this->actor = $this->seedUser([
            'full_name' => 'Contact Setup User', 'username' => 'contact_setup_user',
            'password' => Hash::make(self::TEMPORARY), 'role' => 'maintenance_staff',
            'force_profile_update' => 1, 'auth_version' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    private function save(array $overrides = [])
    {
        $_SESSION['auth_user'] = ['user_id' => $this->actor, 'role' => 'maintenance_staff', 'auth_version' => 0];
        return $this->actingAsSessionUserWithFlatKeys($this->actor, 'maintenance_staff')->patchJson('/api/users/profile', array_merge([
            'full_name' => 'Contact Setup User', 'current_password' => self::TEMPORARY, 'new_password' => self::CHANGED,
        ], $overrides));
    }

    public static function qualifyingContacts(): array
    {
        return [
            'submitted phone only' => [null, null, ['phone' => '09171234567']],
            'existing phone omitted' => ['09171234567', null, []],
            'Gmail only' => [null, 'verified@gmail.com', ['phone' => '']],
            'Yahoo only' => [null, 'verified@yahoo.com', []],
            'legacy verified domain only' => [null, 'verified@example.test', []],
            'both' => ['09171234567', 'verified@gmail.com', []],
            'phone with typed unverified email' => [null, null, ['phone' => '09171234567', 'email' => 'typed@gmail.com']],
        ];
    }

    #[DataProvider('qualifyingContacts')]
    public function test_qualifying_contact_and_changed_password_complete_setup(?string $phone, ?string $verified, array $input): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['phone' => $phone, 'verified_email' => $verified]);
        $this->save($input)->assertOk()->assertJsonPath('data.requires_reauthentication', true);
        $row = DB::table('users')->where('user_id', $this->actor)->first();
        $this->assertSame(0, $row->force_profile_update); $this->assertSame(1, $row->auth_version);
        $this->assertTrue(Hash::check(self::CHANGED, $row->password));
        $this->assertSame($verified, $row->verified_email);
        $this->assertSame(($input['phone'] ?? $phone) ?: null, $row->phone);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'CHANGE_PASSWORD')->count());
        $this->assertEmpty($_SESSION);
        $this->getJson('/api/auth/check')->assertUnauthorized();
        $this->actingAsSessionUserWithFlatKeys($this->actor, 'maintenance_staff')->getJson('/api/auth/check')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['username' => 'contact_setup_user', 'password' => self::CHANGED])
            ->assertOk()->assertJsonPath('data.user.force_profile_update', false)->assertJsonPath('data.user.auth_version', 1);
    }

    public static function invalidRequests(): array
    {
        return [
            'neither' => [null, []],
            'typed email' => [null, ['email' => 'typed@gmail.com']],
            'forged verification and flags' => [null, ['verified_email' => 'forged@gmail.com', 'force_profile_update' => 0, 'auth_version' => 99]],
            'invalid phone only' => [null, ['phone' => '123']],
            'invalid phone with verified email' => ['verified@gmail.com', ['phone' => '123']],
            'missing current password' => ['verified@gmail.com', ['current_password' => '']],
            'wrong current password' => ['verified@gmail.com', ['current_password' => 'Wrong!123']],
            'missing new password' => ['verified@gmail.com', ['new_password' => '']],
            'same temporary password' => ['verified@gmail.com', ['new_password' => self::TEMPORARY]],
            'short new password' => ['verified@gmail.com', ['new_password' => 'short']],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_rejected_requests_cannot_clear_setup_or_change_password(?string $verified, array $input): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['verified_email' => $verified]);
        $before = DB::table('users')->where('user_id', $this->actor)->first();
        $this->save($input)->assertStatus(422);
        $this->assertEquals($before, DB::table('users')->where('user_id', $this->actor)->first());
        $this->assertSame(0, DB::table('activity_logs')->count());
    }

    public function test_pending_verification_record_and_forged_display_cannot_qualify(): void
    {
        // Profile saving must never treat a pending verification or users.email as proof.
        Schema::create('email_verifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('user_id'); $table->string('email'); $table->boolean('is_verified');
        });
        DB::table('email_verifications')->insert(['user_id' => $this->actor, 'email' => 'pending@gmail.com', 'is_verified' => false]);
        $this->save(['email' => 'pending@gmail.com', 'email_verified' => true])->assertStatus(422);
        $this->assertSame(1, DB::table('users')->where('user_id', $this->actor)->value('force_profile_update'));
    }

    public function test_audit_failure_rolls_back_password_contacts_flag_and_version(): void
    {
        $this->mock(ActivityLogService::class)->shouldReceive('log')->once()->andReturnNull();
        $before = DB::table('users')->where('user_id', $this->actor)->first();
        $this->save(['phone' => '09171234567'])->assertStatus(500);
        $this->assertEquals($before, DB::table('users')->where('user_id', $this->actor)->first());
    }

    public function test_newer_administrator_reset_rejects_stale_setup_session(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['auth_version' => 1]);
        $before = DB::table('users')->where('user_id', $this->actor)->first();
        $this->save(['phone' => '09171234567', 'auth_version' => 1, 'force_profile_update' => 0])->assertUnauthorized();
        $this->assertEquals($before, DB::table('users')->where('user_id', $this->actor)->first());
    }

    public function test_optional_second_contact_can_be_added_without_forcing_setup_or_changing_password(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['force_profile_update' => 0, 'verified_email' => 'legacy@example.test']);
        $before = DB::table('users')->where('user_id', $this->actor)->first();
        $this->save(['phone' => '09171234567', 'current_password' => '', 'new_password' => ''])
            ->assertOk()->assertJsonPath('data.requires_reauthentication', false);
        $after = DB::table('users')->where('user_id', $this->actor)->first();
        $this->assertSame(0, $after->force_profile_update); $this->assertSame(0, $after->auth_version);
        $this->assertSame($before->password, $after->password); $this->assertSame($before->verified_email, $after->verified_email);
        $this->assertSame('09171234567', $after->phone);
    }
}
