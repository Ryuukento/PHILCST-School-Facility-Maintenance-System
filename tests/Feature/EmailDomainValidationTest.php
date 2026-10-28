<?php

namespace Tests\Feature;

use App\Services\EmailVerificationService;
use App\Services\SmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class EmailDomainValidationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $actor;
    private array $nativeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('email_domain_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->forceLocalTestUrl();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? [];
        $_SESSION = [];
        $this->createUsersTable();
        Schema::table('users', fn (Blueprint $table) => $table->string('verified_email')->nullable()->unique());
        $this->createSuppliersTable();
        $this->createSupplierHistoriesTable();
        $this->createActivityLogsTable();
        Schema::create('email_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('email');
            $table->string('otp_code');
            $table->unsignedInteger('otp_attempts')->default(0);
            $table->timestamp('otp_expires_at');
            $table->timestamp('last_otp_sent_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
        $this->actor = $this->seedUser(['role' => 'super_admin', 'verified_email' => 'legacy@outlook.com']);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    private function loginAsActor(): self
    {
        $_SESSION['auth_user'] = ['user_id' => $this->actor, 'role' => 'super_admin', 'auth_version' => 0];
        return $this->actingAsSessionUserWithFlatKeys($this->actor, 'super_admin');
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy($table === 'users' ? 'user_id' : 'id')->get()->toJson(),
            ['users', 'suppliers', 'supplier_histories', 'activity_logs', 'email_verifications']);
    }

    public static function invalidEmails(): array
    {
        return array_map(fn ($email) => [$email], [
            'a@outlook.com', 'a@hotmail.com', 'a@gmail.com.ph', 'a@yahoo.co',
            'a@gmail.com.evil.com', 'a@sub.gmail.com', 'not-an-email', 'a@@gmail.com',
            'a..b@gmail.com', 'a b@gmail.com', ['a@gmail.com'], str_repeat('a', 246) . '@gmail.com',
        ]);
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_supplier_email_has_no_mutations(mixed $email): void
    {
        $id = DB::table('suppliers')->insertGetId(['name' => 'Legacy Supplier', 'contact_email' => 'legacy@outlook.com']);
        $before = $this->snapshot();
        foreach (['/api/suppliers', '/api/suppliers/quick-create'] as $url) {
            $this->loginAsActor()->postJson($url, ['name' => 'New Supplier', 'contact_email' => $email])
                ->assertUnprocessable()->assertJsonValidationErrors('contact_email');
            $this->assertSame($before, $this->snapshot());
        }
        $this->loginAsActor()->patchJson('/api/suppliers/' . $id, ['notes' => 'Must not persist', 'contact_email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_email');
        $this->assertSame($before, $this->snapshot());
    }

    public static function allowedEmails(): array
    {
        // Preserve the existing Laravel email rule and its 255-character ceiling.
        return [['a@gmail.com'], ['a@yahoo.com'], ['A@GMAIL.COM'], ['a@YaHoO.CoM'], [str_repeat('a', 64) . '@gmail.com'], [str_repeat('a', 245) . '@gmail.com']];
    }

    #[DataProvider('allowedEmails')]
    public function test_allowed_supplier_email_on_create_and_edit(string $email): void
    {
        $response = $this->loginAsActor()->postJson('/api/suppliers', ['name' => 'Allowed Supplier', 'contact_email' => $email])->assertCreated();
        $id = $response->json('data.supplier_id');
        $this->assertSame($email, DB::table('suppliers')->where('id', $id)->value('contact_email'));
        $this->loginAsActor()->patchJson('/api/suppliers/' . $id, ['contact_email' => 'changed@yahoo.com'])->assertOk();
        $this->assertSame('changed@yahoo.com', DB::table('suppliers')->where('id', $id)->value('contact_email'));
    }

    public function test_optional_email_patch_omission_and_unchanged_legacy_address(): void
    {
        foreach ([[], ['contact_email' => null], ['contact_email' => '']] as $i => $fields) {
            $this->loginAsActor()->postJson('/api/suppliers', ['name' => 'Optional ' . $i] + $fields)->assertCreated();
        }
        $id = DB::table('suppliers')->insertGetId(['name' => 'Legacy Supplier', 'contact_email' => 'legacy@outlook.com']);
        foreach ([['notes' => 'Unrelated edit'], ['contact_email' => 'legacy@outlook.com', 'notes' => 'Same legacy email']] as $fields) {
            $this->loginAsActor()->patchJson('/api/suppliers/' . $id, $fields)->assertOk();
            $this->assertSame('legacy@outlook.com', DB::table('suppliers')->where('id', $id)->value('contact_email'));
        }
        $this->loginAsActor()->patchJson('/api/suppliers/' . $id, ['contact_email' => null])->assertOk();
        $this->assertNull(DB::table('suppliers')->where('id', $id)->value('contact_email'));
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_verification_requests_never_call_service_or_mutate_records(mixed $email): void
    {
        $this->pending('pending@outlook.com');
        $mock = $this->mock(EmailVerificationService::class);
        $mock->shouldNotReceive('initiateEmailVerification', 'verifyOTP', 'resendOtp');
        $before = $this->snapshot();
        foreach (['initiate', 'verify-otp', 'resend-otp'] as $action) {
            $this->loginAsActor()->postJson('/api/email-verification/' . $action, ['email' => $email, 'otp' => '123456'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertSame($before, $this->snapshot());
        }
    }

    #[DataProvider('allowedEmails')]
    public function test_allowed_verification_requests_delegate_to_service_with_authenticated_actor(string $email): void
    {
        $mock = $this->mock(EmailVerificationService::class);
        $mock->shouldReceive('initiateEmailVerification')->once()->with($this->actor, $email)->andReturn(['success' => true, 'message' => 'Sent']);
        $mock->shouldReceive('verifyOTP')->once()->with($this->actor, $email, '123456')->andReturn(['success' => true, 'message' => 'Verified']);
        $mock->shouldReceive('resendOtp')->once()->with($this->actor, $email)->andReturn(['success' => true, 'message' => 'Sent']);
        foreach (['initiate', 'verify-otp', 'resend-otp'] as $action) {
            $this->loginAsActor()->postJson('/api/email-verification/' . $action, ['email' => $email, 'otp' => '123456', 'user_id' => 999])->assertOk();
        }
        $this->assertSame('legacy@outlook.com', DB::table('users')->where('user_id', $this->actor)->value('verified_email'));
    }

    private function pending(string $email, array $overrides = []): int
    {
        return DB::table('email_verifications')->insertGetId(array_merge([
            'user_id' => $this->actor, 'email' => $email, 'otp_code' => '123456',
            'otp_attempts' => 0, 'otp_expires_at' => now()->addMinutes(5), 'last_otp_sent_at' => now(), 'is_verified' => false,
        ], $overrides));
    }

    public function test_actual_service_preserves_uniqueness_expiry_attempts_and_successful_verification(): void
    {
        // These branches cannot send email: uniqueness/rate checks return before SMTP, verify never sends.
        $this->seedUser(['verified_email' => 'taken@gmail.com']);
        $this->loginAsActor()->postJson('/api/email-verification/initiate', ['email' => 'taken@gmail.com'])->assertStatus(400);
        $id = $this->pending('pending@gmail.com', ['otp_expires_at' => now()->subMinute()]);
        $this->loginAsActor()->postJson('/api/email-verification/initiate', ['email' => 'pending@gmail.com'])->assertStatus(400);
        $this->loginAsActor()->postJson('/api/email-verification/verify-otp', ['email' => 'pending@gmail.com', 'otp' => '123456'])->assertStatus(400);
        $this->assertSame(0, DB::table('email_verifications')->where('id', $id)->value('otp_attempts'));
        DB::table('email_verifications')->where('id', $id)->update(['otp_expires_at' => now()->addMinutes(5)]);
        for ($i = 1; $i <= 3; $i++) {
            $this->loginAsActor()->postJson('/api/email-verification/verify-otp', ['email' => 'pending@gmail.com', 'otp' => '000000'])->assertStatus(400);
            $this->assertSame($i, DB::table('email_verifications')->where('id', $id)->value('otp_attempts'));
        }
        $this->loginAsActor()->postJson('/api/email-verification/verify-otp', ['email' => 'pending@gmail.com', 'otp' => '123456'])->assertStatus(400);
        $this->assertSame('legacy@outlook.com', DB::table('users')->where('user_id', $this->actor)->value('verified_email'));
        $this->loginAsActor()->postJson('/api/email-verification/resend-otp', ['email' => 'pending@gmail.com'])->assertStatus(400);
        $this->pending('success@yahoo.com');
        $this->loginAsActor()->postJson('/api/email-verification/verify-otp', ['email' => 'success@yahoo.com', 'otp' => '123456'])->assertOk();
        $this->assertSame('success@yahoo.com', DB::table('users')->where('user_id', $this->actor)->value('verified_email'));
        $this->assertSame(1, DB::table('email_verifications')->where('email', 'success@yahoo.com')->value('is_verified'));
    }

    public function test_authorization_and_required_email_and_otp_validation_are_preserved(): void
    {
        $this->postJson('/api/email-verification/initiate', ['email' => 'a@gmail.com'])->assertUnauthorized();
        $this->postJson('/api/suppliers', ['name' => 'Supplier'])->assertUnauthorized();
        $this->loginAsActor()->postJson('/api/email-verification/initiate', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->loginAsActor()->postJson('/api/email-verification/verify-otp', ['email' => 'a@gmail.com'])->assertUnprocessable()->assertJsonValidationErrors('otp');
        $staff = $this->seedUser(['role' => 'staff']);
        $_SESSION = [];
        $this->actingAsSessionUserWithFlatKeys($staff, 'staff')->postJson('/api/suppliers', ['name' => 'Supplier', 'contact_email' => 'a@gmail.com'])->assertForbidden();
    }

    public function test_verification_transaction_rollback_and_read_only_legacy_status(): void
    {
        $id = $this->pending('legacy@outlook.com');
        $before = $this->snapshot();
        $this->loginAsActor()->getJson('/api/email-verification/status?email=legacy%40outlook.com')->assertOk();
        $this->assertSame($before, $this->snapshot());
        DB::table('email_verifications')->where('id', $id)->update(['email' => 'new@gmail.com']);
        DB::unprepared("CREATE TRIGGER block_verified_email BEFORE UPDATE OF verified_email ON users BEGIN SELECT RAISE(ABORT, 'synthetic rollback'); END");
        $before = $this->snapshot();
        $this->loginAsActor()->postJson('/api/email-verification/verify-otp', ['email' => 'new@gmail.com', 'otp' => '123456'])->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
    }
}
