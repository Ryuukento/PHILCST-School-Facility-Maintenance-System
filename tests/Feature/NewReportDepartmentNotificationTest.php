<?php

namespace Tests\Feature;

use App\Services\SmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Department-matched New Report alert (System + Email + SMS) — coverage for
 * the 2026-10-13 feature: a brand-new report now notifies the active Head
 * (maintenance_admin) and Staff (maintenance_staff) of the REPORT'S OWN
 * department, on all three channels, instead of the original email-only-to-
 * Head / in-system-broadcast-to-every-admin-role split.
 *
 * See ReportService::notifyAdminsOfNewReport() (the entry point, unchanged
 * trigger — called once per report creation, same as before this feature),
 * ::activeDepartmentHeadAndStaffRecipients() (who counts: active
 * maintenance_admin + maintenance_staff in the report's department_id), and
 * ::notifyDepartmentOfNewReport() (the three channels themselves, mirroring
 * ::notifyAdministratorsOfNeedChangeRequest()'s per-channel isolation).
 *
 * FALLBACK (asserted in Test C below): a report with no department_id, or
 * whose department currently has no active Head/Staff on file, still falls
 * through to the ORIGINAL org-wide broadcast — already covered by the
 * pre-existing ReportAssignmentNotificationTest::test_new_report_submission_
 * still_notifies_all_admins_unaffected_by_assignment_scoping() and
 * NeedChangeAdministratorAlertTest's own Test G, both of which seed no
 * department_id and still pass unmodified after this feature landed.
 *
 * The SMS channel is asserted via Http::fake() against the IPROG endpoint
 * (config('services.iprog.*') is overridden per test so SmsService never
 * no-ops for "not configured") — same approach as NeedChangeAdministratorAlertTest.
 * The email channel is NOT asserted for actual delivery — EmailService really
 * does attempt a live SMTP connection, pointed at an unroutable local port
 * here exactly like that file does, so only "the request still succeeds" is
 * asserted for it, not an outbox.
 */
class NewReportDepartmentNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /** @var array<string, string|false> */
    private array $originalSmtpEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('new_report_department_notification_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        config([
            'services.iprog.api_token' => 'test-iprog-token',
            'services.iprog.api_url' => 'https://sms.iprogtech.com/api/v1/sms_messages',
            'services.iprog.sms_provider' => 0,
        ]);

        // Same reasoning as NeedChangeAdministratorAlertTest::setUp(): force
        // EmailService's real SMTP attempt to fail fast against an
        // unroutable local port instead of opening a real outbound session
        // to whatever the committer's local .env points at.
        foreach (['SFMS_SMTP_HOST', 'SFMS_SMTP_PORT', 'SFMS_SMTP_USERNAME', 'SFMS_SMTP_PASSWORD'] as $key) {
            $this->originalSmtpEnv[$key] = getenv($key);
        }
        $overrides = [
            'SFMS_SMTP_HOST' => '127.0.0.1',
            'SFMS_SMTP_PORT' => '9',
            'SFMS_SMTP_USERNAME' => 'test@example.com',
            'SFMS_SMTP_PASSWORD' => 'test-password',
        ];
        foreach ($overrides as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSmtpEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    private function seedDepartment(string $name = 'Facilities'): int
    {
        return DB::table('departments')->insertGetId([
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * TEST A — a new report whose department has both an active Head and an
     * active Staff member notifies BOTH of them, on all three channels.
     */
    public function test_new_report_notifies_the_matching_departments_head_and_staff_on_all_three_channels(): void
    {
        Http::fake(['sms.iprogtech.com/*' => Http::response(['status' => 'success'], 200)]);

        $deptId = $this->seedDepartment();
        $headId = $this->seedUser([
            'role' => 'maintenance_admin',
            'status' => 'active',
            'department_id' => $deptId,
            'phone' => '+639171234567',
        ]);
        $staffId = $this->seedUser([
            'role' => 'maintenance_staff',
            'status' => 'active',
            'department_id' => $deptId,
            'phone' => '+639179876543',
        ]);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Pipe In Hallway',
                'description' => 'Water pooling near the stairwell.',
                'location' => 'Room 101',
                'department_id' => $deptId,
            ]);

        $response->assertStatus(201);

        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $headId)->where('title', 'New Maintenance Report Submitted')->count(),
            'Head of the matching department must receive the in-system notification.'
        );
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $staffId)->where('title', 'New Maintenance Report Submitted')->count(),
            'Staff of the matching department must receive the in-system notification.'
        );

        // SMS attempted for both recipients (both have phone numbers).
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://sms.iprogtech.com/api/v1/sms_messages'
            && $request['phone_number'] === '+639171234567');
        Http::assertSent(fn ($request) => $request->url() === 'https://sms.iprogtech.com/api/v1/sms_messages'
            && $request['phone_number'] === '+639179876543');
    }

    /**
     * TEST B — a Head/Staff pair in a DIFFERENT department must never
     * receive this alert; only the report's own department's recipients do.
     */
    public function test_recipients_outside_the_reports_department_are_not_notified(): void
    {
        Http::fake(['sms.iprogtech.com/*' => Http::response(['status' => 'success'], 200)]);

        $reportDeptId = $this->seedDepartment('IT');
        $otherDeptId = $this->seedDepartment('Grounds');

        $correctHeadId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active', 'department_id' => $reportDeptId, 'phone' => '+639171234567']);
        $otherHeadId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active', 'department_id' => $otherDeptId, 'phone' => '+639170000000']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active', 'department_id' => $otherDeptId]);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active', 'department_id' => $reportDeptId]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Flickering Lights',
                'description' => 'Lights flicker intermittently in the server room.',
                'location' => 'Room 202',
                'department_id' => $reportDeptId,
            ]);

        $response->assertStatus(201);

        $this->assertSame(1, DB::table('notifications')->where('user_id', $correctHeadId)->where('title', 'New Maintenance Report Submitted')->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $otherHeadId)->where('title', 'New Maintenance Report Submitted')->count(), 'A Head from a different department must not be notified.');
        $this->assertSame(0, DB::table('notifications')->where('user_id', $otherStaffId)->where('title', 'New Maintenance Report Submitted')->count(), 'A Staff member from a different department must not be notified.');

        // SMS attempted exactly once — only the matching department's Head.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['phone_number'] === '+639171234567');
    }

    /**
     * TEST C — a report with NO department_id falls back to the original
     * org-wide broadcast (every active admin-ish role, regardless of
     * department) rather than silently notifying nobody.
     */
    public function test_a_report_with_no_department_falls_back_to_the_org_wide_broadcast(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Carpentry',
                'title' => 'Broken Door Hinge',
                'description' => 'Door will not close properly.',
                'location' => 'Room 303',
            ]);

        $response->assertStatus(201);

        $this->assertSame(1, DB::table('notifications')->where('user_id', $adminId)->where('title', 'New Maintenance Report Submitted')->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $headId)->where('title', 'New Maintenance Report Submitted')->count());
    }

    /**
     * TEST D — a recipient with no phone number on file must never block
     * report creation or the in-system notification; the SMS leg simply
     * skips that one recipient (mirrors NeedChangeAdministratorAlertTest's
     * Test D for the Need Change flow).
     */
    public function test_missing_phone_number_does_not_break_the_new_report_alert(): void
    {
        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active', 'department_id' => $deptId, 'phone' => null]);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active', 'department_id' => $deptId]);

        // Deliberately NOT faking Http: SmsService must never even reach the
        // HTTP client for a recipient with no phone number.
        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'AC Not Cooling',
                'description' => 'Unit runs but does not cool the room.',
                'location' => 'Room 404',
                'department_id' => $deptId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $headId)->where('title', 'New Maintenance Report Submitted')->count(),
            'In-system notification must still fire even though the Head has no phone number.'
        );
    }

    /**
     * TEST E — a hard SMS failure (the IPROG call throwing, not merely
     * returning a non-success response) must never prevent the in-system
     * notification from being written, and must never surface as a failed
     * report-creation request.
     */
    public function test_sms_failure_does_not_block_the_in_system_notification(): void
    {
        $this->app->bind(SmsService::class, function () {
            return new class extends SmsService {
                public function send(?string $phoneNumber, string $message): bool
                {
                    throw new \RuntimeException('Simulated IPROG outage');
                }
            };
        });

        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active', 'department_id' => $deptId, 'phone' => '+639171234567']);
        $reporterId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Roofing',
                'title' => 'Roof Leak',
                'description' => 'Water dripping from the ceiling.',
                'location' => 'Room 505',
                'department_id' => $deptId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $headId)->where('title', 'New Maintenance Report Submitted')->count(),
            'In-system notification must still be written even though SMS threw.'
        );
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }
}
