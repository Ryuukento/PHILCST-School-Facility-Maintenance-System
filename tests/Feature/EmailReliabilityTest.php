<?php

namespace Tests\Feature;

use App\Services\EmailVerificationService;
use App\Services\SmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class EmailReliabilityTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $actor;
    private int $sendCalls = 0;
    private string $logFile;
    private string $previousLog;
    private array $nativeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertFalse(function_exists('fsockopen'), 'Focused transport tests require outbound socket functions disabled.');
        $this->assertFalse(function_exists('stream_socket_client'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('email_reliability_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->forceLocalTestUrl();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? []; $_SESSION = [];
        Carbon::setTestNow('2026-10-28 12:00:00');
        $this->createUsersTable();
        Schema::table('users', fn (Blueprint $table) => $table->string('verified_email')->nullable()->unique());
        Schema::create('email_verifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('user_id'); $table->string('email');
            $table->string('otp_code'); $table->unsignedInteger('otp_attempts')->default(0);
            $table->timestamp('otp_expires_at'); $table->timestamp('last_otp_sent_at')->nullable();
            $table->boolean('is_verified')->default(false); $table->timestamps();
        });
        $this->actor = $this->seedUser(['role' => 'super_admin', 'full_name' => 'PRIVATE ACCOUNT NAME', 'verified_email' => 'legacy@outlook.com']);
        $_SESSION['auth_user'] = ['user_id' => $this->actor, 'role' => 'super_admin', 'auth_version' => 0];
        foreach (['SFMS_SMTP_USERNAME' => 'synthetic-smtp@gmail.com', 'SFMS_SMTP_PASSWORD' => 'SECRET-APP-PASSWORD',
            'SFMS_MAIL_FROM_ADDRESS' => 'synthetic-smtp@gmail.com'] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->logFile = tempnam(sys_get_temp_dir(), 'sfms_email_test_');
        $this->previousLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        if (isset($this->logFile)) unlink($this->logFile);
        Carbon::setTestNow(); $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    private function transport(string $outcome, bool $details = true): void
    {
        // Intercepts the actual class instantiated by EmailService::createMailer(),
        // rather than faking Laravel Mail or bypassing EmailService.
        $this->assertFalse(class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false));
        Mockery::getConfiguration()->setConstantsMap([
            'PHPMailer\\PHPMailer\\PHPMailer' => ['ENCRYPTION_STARTTLS' => 'tls'],
        ]);
        $mailer = Mockery::mock('overload:PHPMailer\\PHPMailer\\PHPMailer');
        $mailer->shouldReceive('__construct')->with(true);
        $mailer->shouldReceive('isSMTP', 'isHTML', 'setFrom', 'addAddress', 'addBCC')->andReturnNull();
        $smtp = new class($details) {
            public function __construct(private bool $details) {}
            public function getError(): array {
                return $this->details ? ['smtp_code' => '550', 'smtp_code_ex' => '5.7.1', 'error' => 'DATA command failed',
                    'detail' => 'PRIVATE ACCOUNT NAME recipient@gmail.com SECRET-APP-PASSWORD AUTH-SECRET OTP 654321 BODY-SECRET'] : [];
            }
            public function getLastReply(): string {
                return $this->details ? "550 5.7.1 DATA not accepted recipient@gmail.com 654321 AUTH-SECRET\r\nInjected line" : '';
            }
        };
        $mailer->shouldReceive('getSMTPInstance')->andReturn($smtp);
        $mailer->shouldReceive('send')->andReturnUsing(function () use ($outcome) {
            $this->sendCalls++;
            if ($outcome === 'exception') throw new \RuntimeException('SMTP Error: data not accepted. PRIVATE ACCOUNT NAME recipient@gmail.com SECRET-APP-PASSWORD 654321 BODY-SECRET');
            return $outcome === 'success';
        });
        require_once base_path('public/backend/services/EmailService.php');
    }

    private function assertSafeLog(): string
    {
        $log = file_get_contents($this->logFile);
        foreach (['recipient@gmail.com', 'synthetic-smtp@gmail.com', 'SECRET-APP-PASSWORD', 'PRIVATE ACCOUNT NAME', '654321', 'BODY-SECRET', 'AUTH-SECRET', 'Injected line'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        return $log;
    }

    public static function transportCases(): array
    {
        $cases = [];
        foreach (['general', 'report', 'status'] as $workflow) foreach (['success', 'false', 'exception'] as $outcome) $cases[] = [$workflow, $outcome];
        return $cases;
    }

    #[DataProvider('transportCases')]
    public function test_transport_outcome_and_sanitized_diagnostics(string $workflow, string $outcome): void
    {
        $this->transport($outcome);
        $report = ['title' => 'BODY-SECRET', 'description' => '654321', 'status' => 'completed'];
        $recipient = ['email' => 'recipient@gmail.com', 'full_name' => 'PRIVATE ACCOUNT NAME'];
        $result = match ($workflow) {
            'report' => \EmailService::sendNewReportNotification($report, [$recipient]),
            'status' => \EmailService::sendStatusUpdateNotification($report, $recipient),
            default => \EmailService::sendEmail($recipient['email'], 'Verify 654321', 'BODY-SECRET', 'AUTH-SECRET', $recipient['full_name']),
        };
        $this->assertSame($outcome === 'success', $result); $this->assertSame(1, $this->sendCalls);
        $log = $this->assertSafeLog();
        if ($outcome !== 'success') {
            $this->assertStringContainsString('"smtp_code":"550"', $log);
            $this->assertStringContainsString('"smtp_code_ex":"5.7.1"', $log);
            $this->assertStringContainsString('"reason":"smtp_data_failure"', $log);
            $this->assertStringContainsString('"raw_details":"withheld"', $log);
        }
    }

    public function test_unavailable_smtp_details_are_reported_without_inventing_a_status(): void
    {
        $this->transport('false', false);
        $this->assertFalse(\EmailService::sendEmail('recipient@gmail.com', 'Verify 654321', 'BODY-SECRET'));
        $log = $this->assertSafeLog();
        $this->assertStringContainsString('"smtp_status_available":false', $log);
        $this->assertStringContainsString('"smtp_code":null', $log);
        $this->assertStringContainsString('unclassified_transport_failure', $log);
    }

    public static function otpCases(): array
    {
        $cases = [];
        foreach (['initiate', 'resend-otp'] as $action) foreach (['success', 'false', 'exception'] as $outcome) $cases[] = [$action, $outcome];
        return $cases;
    }

    private function pending(int $minutesAgo = 6): int
    {
        return DB::table('email_verifications')->insertGetId([
            'user_id' => $this->actor, 'email' => 'recipient@gmail.com', 'otp_code' => '654321', 'otp_attempts' => 2,
            'otp_expires_at' => now()->addMinutes(2), 'last_otp_sent_at' => now()->subMinutes($minutesAgo), 'is_verified' => false,
        ]);
    }

    #[DataProvider('otpCases')]
    public function test_otp_api_outcomes_preserve_pending_code_on_failure_and_cooldown(string $action, string $outcome): void
    {
        $this->transport($outcome);
        $id = $this->pending();
        $before = DB::table('email_verifications')->where('id', $id)->first();
        $response = $this->actingAsSessionUserWithFlatKeys($this->actor, 'super_admin')
            ->postJson('/api/email-verification/' . $action, ['email' => 'recipient@gmail.com']);
        $response->assertStatus($outcome === 'success' ? 200 : 400)->assertJsonPath('success', $outcome === 'success');
        $this->assertSame(1, $this->sendCalls);
        $after = DB::table('email_verifications')->where('id', $id)->first();
        $this->assertSame(now()->toDateTimeString(), $after->last_otp_sent_at);
        $this->assertSame('legacy@outlook.com', DB::table('users')->where('user_id', $this->actor)->value('verified_email'));
        $this->assertSame(1, DB::table('email_verifications')->count());
        if ($outcome !== 'success') {
            $this->assertSame($before->otp_code, $after->otp_code);
            $this->assertSame($before->otp_expires_at, $after->otp_expires_at);
            $this->assertSame($before->otp_attempts, $after->otp_attempts);
            $response->assertJsonMissingPath('data.otp');
            $this->actingAsSessionUserWithFlatKeys($this->actor, 'super_admin')
                ->postJson('/api/email-verification/' . $action, ['email' => 'recipient@gmail.com'])->assertStatus(400);
            $this->assertSame(1, $this->sendCalls);
            app(EmailVerificationService::class)->verifyOTP($this->actor, 'recipient@gmail.com', '654321');
            $this->assertSame(1, DB::table('email_verifications')->where('id', $id)->value('is_verified'));
        } else {
            $this->assertSame(0, $after->otp_attempts);
            $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $after->otp_expires_at);
            $retry = $this->actingAsSessionUserWithFlatKeys($this->actor, 'super_admin')
                ->postJson('/api/email-verification/' . $action, ['email' => 'recipient@gmail.com']);
            $retry->assertStatus(400);
        }
        $this->assertSame(1, $this->sendCalls); $this->assertSafeLog();
    }

    public function test_failed_first_initiation_retains_pending_record_and_blocks_immediate_retry(): void
    {
        $this->transport('false');
        $service = app(EmailVerificationService::class);
        $this->assertFalse($service->initiateEmailVerification($this->actor, 'recipient@gmail.com')['success']);
        $this->assertSame(1, DB::table('email_verifications')->count());
        $this->assertFalse($service->initiateEmailVerification($this->actor, 'recipient@gmail.com')['success']);
        $this->assertSame(1, $this->sendCalls); $this->assertSafeLog();
    }

    public function test_failed_resend_retains_cooldown_then_allows_one_retry_at_five_minutes(): void
    {
        $this->transport('false'); $id = $this->pending(); $service = app(EmailVerificationService::class);
        $this->assertFalse($service->resendOTP($this->actor, 'recipient@gmail.com')['success']);
        $this->assertFalse($service->resendOTP($this->actor, 'recipient@gmail.com')['success']);
        $this->assertSame(1, $this->sendCalls);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertFalse($service->resendOTP($this->actor, 'recipient@gmail.com')['success']);
        $this->assertSame(2, $this->sendCalls);
        $this->assertSame('654321', DB::table('email_verifications')->where('id', $id)->value('otp_code'));
        $this->assertSafeLog();
    }

    public function test_persistence_failure_rolls_back_without_delivery_or_sensitive_sql_logs(): void
    {
        $this->transport('success'); $this->pending();
        $before = DB::table('email_verifications')->get()->toJson();
        DB::unprepared("CREATE TRIGGER fail_otp_write BEFORE UPDATE ON email_verifications BEGIN SELECT RAISE(ABORT, '654321 BODY-SECRET'); END");
        $result = app(EmailVerificationService::class)->initiateEmailVerification($this->actor, 'recipient@gmail.com');
        $this->assertFalse($result['success']); $this->assertSame(0, $this->sendCalls);
        $this->assertSame($before, DB::table('email_verifications')->get()->toJson());
        $this->assertSame(0, DB::transactionLevel()); $this->assertSafeLog();
    }

    public function test_successful_first_initiation_creates_one_pending_code_with_five_minute_expiry(): void
    {
        $this->transport('success');
        $result = app(EmailVerificationService::class)->initiateEmailVerification($this->actor, 'recipient@gmail.com');
        $this->assertTrue($result['success']); $this->assertSame(1, $this->sendCalls);
        $row = DB::table('email_verifications')->sole();
        $this->assertSame($result['otp'], $row->otp_code);
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $row->otp_expires_at);
        $this->assertStringNotContainsString($row->otp_code, file_get_contents($this->logFile));
        $this->assertSame('legacy@outlook.com', DB::table('users')->where('user_id', $this->actor)->value('verified_email'));
    }

    public function test_report_creation_still_succeeds_when_direct_mail_transport_returns_false(): void
    {
        $this->transport('false');
        $this->createDepartmentsTable(); $this->createItemsTable(); $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable(); $this->createActivityLogsTable();
        // In-system failure is also isolated, as in the existing regression suite.
        $reporter = $this->seedUser(['role' => 'maintenance_staff']); $_SESSION = [];
        $this->actingAsSessionUserWithFlatKeys($reporter, 'maintenance_staff')->postJson('/api/reports', [
            'problem_type' => 'Plumbing', 'title' => 'Synthetic faucet leak',
            'description' => 'Synthetic report for offline mail failure isolation.', 'location' => 'Synthetic room',
        ])->assertCreated();
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        $this->assertSame(1, $this->sendCalls); $this->assertSafeLog();
    }
}
