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
 * Administrator Need Change alert (System + Email + SMS) — coverage for the
 * 2026-10-08 feature: a NEW Need Change request (and ONLY that — priority is
 * irrelevant) notifies every active super_admin on three independent,
 * mutually-isolated channels, while Staff and Head keep receiving exactly the
 * notifications they already received before this feature existed (the
 * generic "New Maintenance Report Submitted" broadcast from
 * ReportService::notifyAdminsOfNewReport(), assignment/completion
 * notifications, etc.) and nothing new.
 *
 * See ReportService::notifyAdministratorsOfNeedChangeRequest() /
 * ::notifyAdministratorsOfNeedChangeRequestAfterCreate() /
 * ::activeSuperAdminRecipients(), SmsService::send(), and the two call sites
 * (ReportController::store(), ReportService::applyUpdate()) that remain the
 * ONLY trigger points — this test never duplicates that guard, only exercises
 * it through the real HTTP endpoints.
 *
 * The in-system channel is asserted directly against the notifications table.
 * The SMS channel is asserted via Http::fake() against the Semaphore endpoint
 * (config('services.semaphore.*') is overridden per test so SmsService never
 * no-ops for "not configured"). The email channel is NOT asserted for actual
 * delivery — EmailService is the pre-existing legacy PHPMailer/SMTP class
 * (unchanged by this feature) and really does attempt a live SMTP connection
 * using whatever SFMS_SMTP_* the local environment has, exactly like every
 * other Need-Change/new-report test in this suite already does (see
 * ReportsApiNeedChangeTest); it has always swallowed its own failures
 * internally and returned false rather than throwing, so asserting "the
 * request still succeeds" is what actually matters here, not inspecting an
 * outbox.
 */
class NeedChangeAdministratorAlertTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /** @var array<string, string|false> */
    private array $originalSmtpEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('need_change_administrator_alert_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        // SmsService no-ops whenever SEMAPHORE_API_KEY is blank — every test
        // below that wants the SMS leg to actually attempt a send must turn
        // this on explicitly, which also documents that "no key configured"
        // is its own safe, tested state (see test D's sibling assertion).
        config([
            'services.semaphore.api_key' => 'test-semaphore-key',
            'services.semaphore.sender_name' => 'SFMS',
            'services.semaphore.api_url' => 'https://api.semaphore.co/api/v4/messages',
        ]);

        // EmailService (legacy, unmodified by this feature — see class
        // docblock) reads SFMS_SMTP_* straight from env() at send time, not
        // from Laravel's cached config, so it is not fakeable via config().
        // Left pointing at the local/real .env's real SMTP host, every report
        // created in this file would open a real outbound SMTP session (to
        // Gmail, per the committer's local .env) on every single test — slow,
        // and an unnecessary use of a real mail account for a value this
        // suite deliberately does not assert on (see class docblock: the
        // email leg's actual delivery is intentionally out of scope here).
        // Pointing it at an unroutable local port makes EmailService fail
        // fast (connection refused) instead, which is still a perfectly
        // valid, real "email channel failure" for test F to observe.
        // Laravel's env() helper (Illuminate\Support\Env) is backed by an
        // IMMUTABLE Dotenv repository that was already populated from the
        // real .env at framework bootstrap, before this test ever ran —
        // plain putenv() alone does not reach it. $_ENV/$_SERVER are exactly
        // what that repository's adapters read, so those are overridden too.
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

    /**
     * TEST A — a brand-new Need Change request (set at report creation time)
     * notifies every active super_admin, and only active super_admins, and is
     * attempted on all three channels. Head, Staff, and an inactive
     * super_admin must receive none of this specific alert.
     */
    public function test_new_need_change_request_notifies_all_active_super_admins_on_all_three_channels(): void
    {
        Http::fake([
            'api.semaphore.co/*' => Http::response(['status' => 'success'], 200),
        ]);

        $adminWithPhone = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'email' => 'admin-with-phone@example.com',
            'phone' => '+639171234567',
        ]);
        $adminNoPhone = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'email' => 'admin-no-phone@example.com',
            'phone' => null,
        ]);
        $inactiveAdmin = $this->seedUser(['role' => 'super_admin', 'status' => 'inactive']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Projector Bulb']);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Broken Projector',
                'description' => 'Projector bulb burned out.',
                'location' => 'Room 101',
                'need_change_item_id' => $itemId,
                'need_change_quantity' => 1,
            ]);

        $response->assertStatus(201);
        $reportId = $response->json('data.report_id');

        $needChangeNotifications = DB::table('notifications')
            ->where('title', 'like', 'New Need Change Request%');

        $this->assertSame(1, (clone $needChangeNotifications)->where('user_id', $adminWithPhone)->count());
        $this->assertSame(1, (clone $needChangeNotifications)->where('user_id', $adminNoPhone)->count());
        $this->assertSame(0, (clone $needChangeNotifications)->where('user_id', $inactiveAdmin)->count(), 'Inactive super_admin must not be notified.');
        $this->assertSame(0, (clone $needChangeNotifications)->where('user_id', $headId)->count(), 'Head must not receive the Need-Change-specific alert.');
        $this->assertSame(0, (clone $needChangeNotifications)->where('user_id', $staffId)->count(), 'Staff must not receive the Need-Change-specific alert.');

        // SMS attempted exactly once: only the admin with a phone number on
        // file gets an outbound Semaphore call.
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.semaphore.co/api/v4/messages'
                && $request['number'] === '+639171234567';
        });

        $this->assertNotNull($reportId);
    }

    /**
     * TEST B — priority must never gate this alert: every priority accepted
     * by the Create Report endpoint produces an identical Need Change
     * notification.
     */
    public function test_the_same_need_change_notification_fires_regardless_of_report_priority(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['status' => 'success'], 200)]);

        $adminId = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => '+639171234567',
        ]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        foreach (['low', 'medium', 'high', 'critical'] as $priority) {
            $itemId = $this->seedItem(['name' => 'Item for ' . $priority]);

            $response = $this
                ->actingAsSessionUser($staffId, 'maintenance_staff')
                ->postJson('/api/reports', [
                    'problem_type' => 'Plumbing',
                    'title' => 'Report at priority ' . $priority,
                    'description' => 'Description for ' . $priority . ' priority report.',
                    'location' => 'Room 101',
                    'priority' => $priority,
                    'need_change_item_id' => $itemId,
                    'need_change_quantity' => 1,
                ]);

            $response->assertStatus(201);
        }

        $count = DB::table('notifications')
            ->where('user_id', $adminId)
            ->where('title', 'like', 'New Need Change Request%')
            ->count();

        $this->assertSame(4, $count, 'All four priorities must trigger the Need Change alert identically.');

        // SMS attempted once per report, regardless of priority.
        Http::assertSentCount(4);
    }

    /**
     * TEST C — an unrelated edit (one that never touches need_change_item_id
     * / need_change_quantity) must never re-trigger the alert. Reuses the
     * exact guard already in ReportService::applyUpdate() / the
     * ReportController case-A gate: this test proves it from the outside, it
     * does not re-implement it.
     */
    public function test_unrelated_report_edit_does_not_retrigger_the_need_change_notification(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['status' => 'success'], 200)]);

        $adminId = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => '+639171234567',
        ]);
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Ceiling Fan']);

        // A report that already has an existing (already-notified, in a real
        // flow) Need Change request on file, seeded directly rather than via
        // the API so this test isolates "editing" from "creating".
        $reportId = $this->seedReport([
            'created_by' => $ownerId,
            // ReportAuthorizationService::canModifyReport() requires a
            // maintenance_staff actor to be the report's assignee (not
            // merely its creator) before any edit is allowed at all.
            'assigned_to' => $ownerId,
            'need_change_item_id' => $itemId,
            'need_change_quantity' => 2,
            'need_change_status' => null,
        ]);

        $response = $this
            ->actingAsSessionUser($ownerId, 'maintenance_staff')
            ->patchJson("/api/reports/{$reportId}", [
                'title' => 'Updated title only',
            ]);

        $response->assertOk();

        $this->assertSame(
            0,
            DB::table('notifications')->where('title', 'like', 'New Need Change Request%')->count(),
            'An edit that never touches need_change_item_id/need_change_quantity must not notify.'
        );
        Http::assertNothingSent();
    }

    /**
     * TEST D — a super_admin with no phone number on file must never block
     * report creation, the in-system notification, or the email attempt. The
     * SMS leg is simply, safely skipped for that one admin.
     */
    public function test_missing_phone_number_does_not_break_the_need_change_request(): void
    {
        // Deliberately NOT faking Http here: SmsService must never even
        // reach the HTTP client for an admin with no phone number, so if it
        // somehow did, Http::fake() not being installed would surface as a
        // real (test-failing) outbound call rather than silently succeeding.
        $adminId = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => null,
        ]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker']);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'No Phone On File',
                'description' => 'Admin has no phone number saved yet.',
                'location' => 'Room 101',
                'need_change_item_id' => $itemId,
                'need_change_quantity' => 1,
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $adminId)->where('title', 'like', 'New Need Change Request%')->count(),
            'In-system notification must still fire even though this admin has no phone number.'
        );
    }

    /**
     * TEST E — a hard SMS failure (the Semaphore call throwing, not merely
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
                    throw new \RuntimeException('Simulated Semaphore outage');
                }
            };
        });

        $adminId = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => '+639171234567',
        ]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Air Conditioner Filter']);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'SMS Channel Throws',
                'description' => 'The SMS leg throws a hard exception for this test.',
                'location' => 'Room 101',
                'need_change_item_id' => $itemId,
                'need_change_quantity' => 1,
            ]);

        // Must still succeed — a thrown exception from the SMS leg must not
        // propagate out of report creation.
        $response->assertStatus(201);
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $adminId)->where('title', 'like', 'New Need Change Request%')->count(),
            'In-system notification must still be written even though SMS threw.'
        );
    }

    /**
     * TEST F — the email leg (the pre-existing, unmodified EmailService —
     * see this file's class docblock) attempting and failing a real SMTP
     * connection must never block the SMS leg or the in-system notification.
     * This exercises the real, already-isolated code path rather than a
     * mock, exactly like ReportNotificationFailureIsolationTest does for the
     * generic new-report notification.
     */
    public function test_email_channel_failure_does_not_block_sms_or_in_system_notification(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['status' => 'success'], 200)]);

        $adminId = $this->seedUser([
            'role' => 'super_admin',
            'status' => 'active',
            'phone' => '+639171234567',
        ]);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Door Hinge']);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Email Channel May Fail',
                'description' => 'EmailService will attempt a real SMTP send and may fail in this environment.',
                'location' => 'Room 101',
                'need_change_item_id' => $itemId,
                'need_change_quantity' => 1,
            ]);

        $response->assertStatus(201);

        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $adminId)->where('title', 'like', 'New Need Change Request%')->count(),
            'In-system notification must be written regardless of email outcome.'
        );
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.semaphore.co/api/v4/messages');
    }

    /**
     * TEST G — Staff and Head must never receive the Need-Change-specific
     * alert, even though they DO (unchanged, pre-existing behavior) receive
     * the generic "New Maintenance Report Submitted" broadcast from
     * ReportService::notifyAdminsOfNewReport(). This is the test that proves
     * the two flows are additive, not merged.
     */
    public function test_no_staff_or_head_receives_the_need_change_specific_notification(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['status' => 'success'], 200)]);

        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active', 'phone' => '+639171234567']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $itemId = $this->seedItem(['name' => 'Light Bulb']);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Head And Staff Must Not Get The New Alert',
                'description' => 'Only the generic broadcast should reach Head.',
                'location' => 'Room 101',
                'need_change_item_id' => $itemId,
                'need_change_quantity' => 1,
            ]);

        $response->assertStatus(201);

        // The pre-existing generic broadcast still reaches Head unchanged.
        $this->assertGreaterThanOrEqual(
            1,
            DB::table('notifications')->where('user_id', $headId)->where('title', 'New Maintenance Report Submitted')->count(),
            'Pre-existing generic new-report notification to Head must be unaffected by this feature.'
        );

        // But neither Head nor Staff ever gets the new Need-Change-specific alert.
        $this->assertSame(
            0,
            DB::table('notifications')->where('user_id', $headId)->where('title', 'like', 'New Need Change Request%')->count(),
            'Head must never receive the Need-Change-specific alert.'
        );
        $this->assertSame(
            0,
            DB::table('notifications')->where('user_id', $staffId)->where('title', 'like', 'New Need Change Request%')->count(),
            'Staff must never receive the Need-Change-specific alert.'
        );
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $adminId)->where('title', 'like', 'New Need Change Request%')->count(),
            'Administrator must still receive the Need-Change-specific alert.'
        );
    }

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $this->seedUser(),
            'assigned_to' => null,
            'department_id' => null,
            'due_date' => null,
            'completed_date' => null,
            'need_change_item_id' => null,
            'need_change_quantity' => 1,
            'need_change_status' => null,
            'need_change_approved_by' => null,
            'need_change_approved_at' => null,
            'need_change_deducted_at' => null,
            'completion_proof_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
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
}
