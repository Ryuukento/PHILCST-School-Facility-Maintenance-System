<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\EmailVerificationService;
use App\Services\SmsService;
use App\Support\UserContactPolicy as Contacts;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class UserContactUniquenessTest extends TestCase
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
        $this->useInMemoryDatabase('contact_uniqueness_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        DB::statement('PRAGMA foreign_keys = ON');
        $this->forceLocalTestUrl();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? []; $_SESSION = [];
        $this->createUsersTable();
        Schema::table('users', fn (Blueprint $table) => $table->string('verified_email')->nullable()->unique());
        $this->createDepartmentsTable(); $this->createActivityLogsTable();
        // Schema fixtures only: the prepared production migration is NOT executed.
        if (!Schema::hasTable('user_contact_claims')) Schema::create('user_contact_claims', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->string('contact_type', 8); $table->string('normalized_value', 255);
            $table->unsignedInteger('user_id'); $table->unique(['contact_type', 'normalized_value'], 'user_contact_claims_value_unique');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
        if (!Schema::hasTable('migrations')) Schema::create('migrations', function (Blueprint $table): void {
            $table->increments('id'); $table->string('migration'); $table->integer('batch');
        });
        if (!DB::table('migrations')->where('migration', Contacts::MIGRATION)->exists()) DB::table('migrations')->insert(['migration' => Contacts::MIGRATION, 'batch' => 1]);
        Schema::create('email_verifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('user_id'); $table->string('email'); $table->string('otp_code', 6);
            $table->integer('otp_attempts')->default(0); $table->timestamp('otp_expires_at');
            $table->timestamp('last_otp_sent_at')->nullable(); $table->boolean('is_verified')->default(false); $table->timestamps();
        });
        $this->actor = $this->seedUser(['full_name' => 'Contact Claim Actor', 'username' => 'claim_actor',
            'password' => Hash::make('Temporary!123'), 'role' => 'maintenance_staff', 'verified_email' => null]);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    private function loginAs(int $id, string $role = 'maintenance_staff'): self
    {
        $_SESSION['auth_user'] = ['user_id' => $id, 'role' => $role, 'auth_version' => 0];
        return $this->actingAsSessionUserWithFlatKeys($id, $role);
    }

    private function profile(array $fields)
    {
        return $this->loginAs($this->actor)->patchJson('/api/users/profile', ['full_name' => 'Contact Claim Actor'] + $fields);
    }

    private function pending(string $email = 'new@gmail.com', int $minutesAgo = 6): int
    {
        return DB::table('email_verifications')->insertGetId(['user_id' => $this->actor, 'email' => $email,
            'otp_code' => '123456', 'otp_attempts' => 0, 'otp_expires_at' => now()->addMinutes(5),
            'last_otp_sent_at' => now()->subMinutes($minutesAgo), 'is_verified' => false]);
    }

    private function reconcile(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = DB::table('users')->where('user_id', $id)->lockForUpdate()->first();
            Contacts::synchronize($id, (array) $row, static fn () => null);
        });
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy($table === 'users' ? 'user_id' : 'id')->get()->toJson(),
            ['users', 'activity_logs', 'email_verifications', 'user_contact_claims']);
    }

    public static function phones(): array
    {
        return [['09922244713'], ['+639922244713'], ['639922244713'], ['9922244713']];
    }

    #[DataProvider('phones')]
    public function test_equivalent_phone_owned_by_another_account_is_rejected_without_setup_side_effects(string $stored): void
    {
        $this->seedUser(['phone' => $stored, 'status' => 'inactive']);
        DB::table('users')->where('user_id', $this->actor)->update(['force_profile_update' => 1]);
        $before = $this->snapshot();
        $this->profile(['phone' => '09922244713', 'current_password' => 'Temporary!123', 'new_password' => 'Changed!456'])
            ->assertStatus(422)->assertJsonPath('message', Contacts::PHONE_TAKEN);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($this->actor, $_SESSION['auth_user']['user_id']);
        $this->assertSame('09922244713', Contacts::phone($stored));
    }

    public function test_public_phone_format_is_not_broadened_and_own_unchanged_phone_is_accepted(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['phone' => '09922244713']);
        $this->profile(['phone' => '09922244713'])->assertOk();
        $this->assertSame(1, DB::table('user_contact_claims')->where('contact_type', 'phone')->count());
        foreach (['+639922244713', '639922244713', '9922244713', '123'] as $phone) $this->profile(['phone' => $phone])->assertStatus(422);
        $this->assertSame('09922244713', DB::table('users')->where('user_id', $this->actor)->value('phone'));
    }

    public static function emailOwners(): array
    {
        $cases = [];
        foreach (['email', 'verified_email'] as $column) foreach (['active', 'inactive', 'suspended', 'pending'] as $status) $cases[] = [$column, $status];
        return $cases;
    }

    #[DataProvider('emailOwners')]
    public function test_signup_and_otp_reject_cross_column_case_whitespace_conflicts(string $column, string $status): void
    {
        $this->seedUser([$column => '  Taken@Gmail.COM  ', 'status' => $status]);
        $this->pending('taken@gmail.com');
        $before = $this->snapshot();
        $this->postJson('/api/auth/register', ['full_name' => 'Fresh Contact Applicant', 'username' => 'fresh_applicant',
            'email' => 'taken@gmail.com', 'password' => 'Temporary!123'])->assertStatus(422)->assertJsonPath('message', Contacts::EMAIL_TAKEN);
        $service = $this->partialMock(EmailVerificationService::class);
        $service->shouldNotReceive('generateOTP');
        foreach (['initiate', 'resend-otp', 'verify-otp'] as $action) {
            $this->loginAs($this->actor)->postJson('/api/email-verification/' . $action, ['email' => 'taken@gmail.com', 'otp' => '123456'])
                ->assertStatus(400)->assertJsonPath('message', Contacts::EMAIL_TAKEN);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_email_can_be_shared_between_own_columns_and_legacy_domain_is_preserved(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['email' => 'Legacy@School.example', 'verified_email' => 'Legacy@School.example', 'force_profile_update' => 1]);
        $this->profile(['phone' => '', 'current_password' => 'Temporary!123', 'new_password' => 'Changed!456'])->assertOk()->assertJsonPath('data.requires_reauthentication', true);
        $this->assertSame(1, DB::table('user_contact_claims')->count());
        $this->assertSame('legacy@school.example', DB::table('user_contact_claims')->value('normalized_value'));
        $this->assertSame('Legacy@School.example', DB::table('users')->where('user_id', $this->actor)->value('verified_email'));
    }

    public function test_null_contacts_create_no_claims_and_do_not_satisfy_forced_setup(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['email' => null]);
        $this->profile(['phone' => ''])->assertOk();
        $this->assertSame(0, DB::table('user_contact_claims')->count());
        DB::table('users')->where('user_id', $this->actor)->update(['force_profile_update' => 1]);
        $this->profile(['phone' => '', 'current_password' => 'Temporary!123', 'new_password' => 'Changed!456'])->assertStatus(422);
    }

    public function test_successful_signup_reserves_unverified_email_in_shared_namespace(): void
    {
        $response = $this->postJson('/api/auth/register', ['full_name' => 'Fresh Contact Applicant', 'username' => 'fresh_applicant',
            'email' => 'Fresh@Example.test', 'password' => 'Temporary!123'])->assertCreated();
        $id = $response->json('data.user_id');
        $this->assertSame($id, DB::table('user_contact_claims')->where('normalized_value', 'fresh@example.test')->value('user_id'));
        $this->assertSame('pending', DB::table('users')->where('user_id', $id)->value('status'));
    }

    public function test_ownership_changed_after_initiation_prevents_verification_without_consuming_otp(): void
    {
        $this->pending();
        $owner = $this->seedUser(['email' => 'new@gmail.com']); $this->reconcile($owner);
        $before = $this->snapshot();
        $result = app(EmailVerificationService::class)->verifyOTP($this->actor, 'NEW@gmail.com', '123456');
        $this->assertFalse($result['success']); $this->assertSame(Contacts::EMAIL_TAKEN, $result['message']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_verified_contact_replacement_releases_only_obsolete_keys(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['email' => 'keep@example.test', 'verified_email' => 'old@example.test', 'phone' => '09922244713']);
        $this->reconcile($this->actor); $this->pending();
        $this->assertTrue(app(EmailVerificationService::class)->verifyOTP($this->actor, 'NEW@gmail.com', '123456')['success']);
        $this->assertSame(0, DB::table('user_contact_claims')->where('normalized_value', 'old@example.test')->count());
        foreach (['keep@example.test', 'new@gmail.com', '09922244713'] as $value) $this->assertSame($this->actor, DB::table('user_contact_claims')->where('normalized_value', $value)->value('user_id'));
        $this->profile(['phone' => '09171234567'])->assertOk();
        $this->assertSame(0, DB::table('user_contact_claims')->where('normalized_value', '09922244713')->count());
    }

    public function test_same_own_email_in_base_column_keeps_claim_after_verified_email_changes(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['email' => 'old@example.test', 'verified_email' => 'OLD@example.test']);
        $this->reconcile($this->actor); $this->pending();
        $this->assertTrue(app(EmailVerificationService::class)->verifyOTP($this->actor, 'new@gmail.com', '123456')['success']);
        $this->assertSame(2, DB::table('user_contact_claims')->where('user_id', $this->actor)->count());
    }

    public function test_claims_and_user_fields_roll_back_when_password_audit_fails(): void
    {
        DB::table('users')->where('user_id', $this->actor)->update(['phone' => '09922244713', 'force_profile_update' => 1]);
        $this->reconcile($this->actor);
        $before = $this->snapshot();
        $this->mock(ActivityLogService::class)->shouldReceive('log')->once()->andReturnNull();
        $this->profile(['phone' => '09171234567', 'current_password' => 'Temporary!123', 'new_password' => 'Changed!456'])->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_user_deletion_cascades_only_the_deleted_users_claims(): void
    {
        $this->reconcile($this->actor);
        $other = $this->seedUser(['email' => 'other@example.test']); $this->reconcile($other);
        DB::transaction(fn () => User::findOrFail($this->actor)->delete());
        $this->assertSame(0, DB::table('user_contact_claims')->where('user_id', $this->actor)->count());
        $this->assertSame(1, DB::table('user_contact_claims')->where('user_id', $other)->count());
    }

    public function test_database_unique_constraint_and_policy_collision_never_steal_claim(): void
    {
        $owner = $this->seedUser(['email' => 'owner@example.test']);
        DB::table('user_contact_claims')->insert(['contact_type' => 'phone', 'normalized_value' => '09922244713', 'user_id' => $owner]);
        $before = $this->snapshot();
        $this->profile(['phone' => '09922244713'])->assertStatus(422)->assertJsonPath('message', Contacts::PHONE_TAKEN);
        $this->assertSame($before, $this->snapshot());
        try {
            DB::table('user_contact_claims')->insert(['contact_type' => 'phone', 'normalized_value' => '09922244713', 'user_id' => $this->actor]);
            $this->fail('The database must enforce the shared canonical key.');
        } catch (QueryException $e) { $this->assertTrue(Contacts::uniqueViolation($e)); }
    }

    public function test_simulated_interleaving_after_precheck_is_stopped_by_atomic_claim_ownership(): void
    {
        $owner = $this->seedUser(['email' => 'owner@example.test']); $injected = false;
        DB::connection()->beforeExecuting(function ($query) use ($owner, &$injected): void {
            if (!$injected && str_starts_with(strtolower($query), 'insert into "user_contact_claims"')) {
                $injected = true;
                DB::table('user_contact_claims')->insert(['contact_type' => 'email', 'normalized_value' => 'race@example.test', 'user_id' => $owner]);
            }
        });
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => Contacts::synchronize($this->actor, ['email' => 'race@example.test'], static fn () => null));
            $this->fail('Competing claim must reject the requester.');
        } catch (ValidationException $e) { $this->assertSame(Contacts::EMAIL_TAKEN, Contacts::publicMessage($e)); }
        $this->assertTrue($injected); $this->assertSame($before, $this->snapshot());
    }

    public function test_prepared_backfill_deduplicates_self_and_stops_on_conflicts_without_user_changes(): void
    {
        $claims = Contacts::backfillClaims([['user_id' => 1, 'phone' => '+639922244713', 'email' => ' A@Example.test ', 'verified_email' => 'a@example.test']]);
        $this->assertCount(2, $claims);
        $this->assertSame('a@example.test', $claims[0]['normalized_value']);
        $this->expectException(\RuntimeException::class);
        Contacts::backfillClaims([['user_id' => 1, 'email' => 'same@example.test'], ['user_id' => 2, 'verified_email' => ' SAME@example.test ']]);
    }

    public function test_missing_or_unrecorded_migration_fails_closed_without_user_changes(): void
    {
        DB::table('migrations')->where('migration', Contacts::MIGRATION)->delete();
        $before = $this->snapshot();
        $this->profile(['phone' => '09922244713'])->assertStatus(422)->assertJsonPath('message', Contacts::NOT_READY);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unauthenticated_contact_mutations_are_denied(): void
    {
        $this->patchJson('/api/users/profile', ['phone' => '09922244713'])->assertUnauthorized();
        $this->postJson('/api/email-verification/verify-otp', ['email' => 'new@gmail.com', 'otp' => '123456'])->assertUnauthorized();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pending_otp_send_uses_mocked_direct_transport_and_does_not_reserve_email(): void
    {
        $this->assertFalse(function_exists('fsockopen'));
        \Mockery::getConfiguration()->setConstantsMap(['PHPMailer\\PHPMailer\\PHPMailer' => ['ENCRYPTION_STARTTLS' => 'tls']]);
        $mailer = \Mockery::mock('overload:PHPMailer\\PHPMailer\\PHPMailer');
        $mailer->shouldReceive('__construct')->with(true);
        $mailer->shouldReceive('isSMTP', 'isHTML', 'setFrom', 'addAddress')->andReturnNull();
        $mailer->shouldReceive('send')->once()->andReturnTrue();
        $service = app(EmailVerificationService::class);
        $this->assertTrue($service->initiateEmailVerification($this->actor, 'new@gmail.com')['success']);
        $this->assertSame(0, DB::table('user_contact_claims')->where('normalized_value', 'new@gmail.com')->count());
        $this->assertFalse($service->initiateEmailVerification($this->actor, 'new@gmail.com')['success']);
    }
}
