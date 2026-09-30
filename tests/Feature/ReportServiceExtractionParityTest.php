<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 50 — ReportService extraction parity.
 *
 * The extraction moved report creation, update, delete, location resolution
 * and the report notification helpers out of ReportController and into
 * app/Services/ReportService.php. Every move was a verbatim code move with
 * exactly ONE substitution that was not literal:
 *
 *     ActivityLogService::log($payload, $request)
 *         -> ActivityLogService::logFromSession($payload, $authUser)
 *
 * That substitution was forced by the house convention (no service in
 * app/Services takes an Illuminate\Http\Request; all six existing services use
 * logFromSession()), and it is argued to be behaviour-identical because
 * logFromSession() resolves the very same Request via the request() helper and
 * then merges user_id/user_role with ??, which is a no-op for payloads that
 * already set both. "Argued to be" is not "proven to be", and a grep of tests/
 * showed that NO existing test asserted the request-derived activity-log
 * attributes at all — ip_address and user_agent appeared only as column
 * definitions in Tests\Support\BuildsSharedTestSchema.
 *
 * So the controller's gate could have silently started writing NULL
 * ip_address / user_agent on every report audit row and the whole suite would
 * still have been green. This file closes that hole: it pins the request-derived
 * audit attributes for all three report audit actions the extraction touched
 * (CREATE_REPORT, ASSIGN_REPORT, DELETE_REPORT), plus the two counting
 * properties a refactor of this shape most plausibly breaks — a duplicated
 * activity log row, and a duplicated notification.
 *
 * These tests are deliberately behavioural and would have passed identically
 * against the pre-extraction controller. They assert parity, not the new
 * structure.
 */
class ReportServiceExtractionParityTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /**
     * A distinctive UA so the assertions below prove the value came from the
     * actual HTTP request rather than from some default the model happened to
     * fill in.
     */
    private const TEST_USER_AGENT = 'Task50-Parity-Probe/1.0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_service_extraction_parity_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------
    // 1-3. Request-derived audit attributes survive the log() ->
    //      logFromSession() substitution, for every moved audit action.
    // ---------------------------------------------------------------

    public function test_create_report_activity_log_still_records_the_request_metadata(): void
    {
        $reporterId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->withHeaders(['User-Agent' => self::TEST_USER_AGENT])
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Faucet',
                'description' => 'Water leaking under the sink.',
                'location' => 'Room 204',
            ])
            ->assertStatus(201);

        $log = $this->latestLog('CREATE_REPORT');

        $this->assertSame($reporterId, (int) $log->user_id);
        $this->assertSame('maintenance_staff', $log->user_role);
        $this->assertRequestMetadataWasRecorded($log, 'CREATE_REPORT');
    }

    public function test_assignment_activity_log_still_records_the_request_metadata(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport();

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->withHeaders(['User-Agent' => self::TEST_USER_AGENT])
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $staffId])
            ->assertOk();

        $log = $this->latestLog('ASSIGN_REPORT');

        $this->assertSame($adminId, (int) $log->user_id);
        $this->assertSame('maintenance_admin', $log->user_role);
        $this->assertSame($reportId, (int) $log->entity_id);
        $this->assertRequestMetadataWasRecorded($log, 'ASSIGN_REPORT');
    }

    public function test_delete_report_activity_log_still_records_the_request_metadata(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $reportId = $this->seedReport();

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->withHeaders(['User-Agent' => self::TEST_USER_AGENT])
            ->deleteJson("/api/reports/{$reportId}")
            ->assertOk();

        $log = $this->latestLog('DELETE_REPORT');

        $this->assertSame($adminId, (int) $log->user_id);
        $this->assertSame('super_admin', $log->user_role);
        $this->assertSame($reportId, (int) $log->entity_id);
        $this->assertRequestMetadataWasRecorded($log, 'DELETE_REPORT');
        $this->assertSame(0, DB::table('maintenance_reports')->where('report_id', $reportId)->count());
    }

    // ---------------------------------------------------------------
    // 4-6. No duplication: one request still produces one audit row and
    //      one notification, on both sides of the delegation boundary.
    // ---------------------------------------------------------------

    public function test_one_update_request_still_writes_exactly_one_activity_log(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport();

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $staffId])
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('activity_logs')->where('entity_id', $reportId)->where('entity_type', 'report')->count(),
            'Delegating the update to ReportService must not add a second audit row alongside the controller.'
        );
    }

    public function test_one_create_request_still_writes_exactly_one_activity_log(): void
    {
        $reporterId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($reporterId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Flickering Light',
                'description' => 'Ceiling light flickers in the lab.',
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            DB::table('activity_logs')->where('action', 'CREATE_REPORT')->count(),
            'Report creation must still be audited exactly once.'
        );
    }

    public function test_one_assignment_request_still_sends_exactly_one_notification(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $reportId = $this->seedReport();

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->patchJson("/api/reports/{$reportId}", ['status' => 'assigned', 'assigned_to' => $staffId])
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $staffId)->count(),
            'The assignee must still be notified exactly once per assignment.'
        );
    }

    // ---------------------------------------------------------------
    // 7-8. Structural invariants the brief made non-negotiable.
    // ---------------------------------------------------------------

    public function test_report_service_opens_no_database_transaction(): void
    {
        // TASK 50 §8 — ReportController opened ZERO transactions before the
        // extraction, so ReportService must open zero after it. Introducing one
        // would silently change rollback semantics for the notification and
        // audit writes that deliberately sit outside any transaction (see
        // ReportService::notifyAdminsOfNewReport()'s TASK 52 comment).
        //
        // Asserted against the comment-stripped source: the class docblock
        // explains at length WHY no transaction is opened, and naming the thing
        // you are not doing must not be able to fail this test.
        $this->assertStringNotContainsString(
            'DB::transaction',
            $this->reportServiceExecutableSource(),
            'ReportService must not introduce a transaction boundary the controller never had.'
        );
    }

    public function test_report_service_makes_no_authorization_decision(): void
    {
        // TASK 50 §5 — authorization stays in ReportAuthorizationService and is
        // invoked from the controller. ReportService reads $authUser['role']
        // only to STAMP it onto audit rows; it must never branch on it, and it
        // must not reach for the role normalizer or the authorization service's
        // decision methods.
        //
        // Comment-stripped for the same reason as the transaction test above:
        // ReportService's docblocks deliberately CITE canModifyReport() to say
        // where the decision still lives, and a citation is the opposite of a
        // duplication.
        $source = $this->reportServiceExecutableSource();

        foreach (['canModifyReport', 'canApproveNeedChange', 'canRejectNeedChange', 'RoleNormalizerService', 'normalizeRole'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "ReportService must not duplicate the authorization decision '{$forbidden}'."
            );
        }

        // Every $authUser['role'] read in the file must be a log-payload stamp,
        // never a comparison.
        $this->assertSame(
            0,
            preg_match_all("/\\\$authUser\\['role'\\]\s*(===|==|!==|!=)/", $source),
            'ReportService must never compare the acting user\'s role.'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function reportServiceSource(): string
    {
        $path = app_path('Services/ReportService.php');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * ReportService's source with every comment and docblock removed, so that
     * structural assertions describe what the class DOES, not what its comments
     * say about neighbouring classes.
     */
    private function reportServiceExecutableSource(): string
    {
        $executable = '';

        foreach (token_get_all($this->reportServiceSource()) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $executable .= is_array($token) ? $token[1] : $token;
        }

        return $executable;
    }

    private function latestLog(string $action): object
    {
        $log = DB::table('activity_logs')->where('action', $action)->latest('id')->first();
        $this->assertNotNull($log, "Expected a {$action} activity log entry.");

        return $log;
    }

    private function assertRequestMetadataWasRecorded(object $log, string $action): void
    {
        $this->assertNotNull(
            $log->ip_address,
            "{$action} must still record the request IP — logFromSession() resolves the same Request the controller used to hold."
        );
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame(
            self::TEST_USER_AGENT,
            $log->user_agent,
            "{$action} must still record the request User-Agent verbatim."
        );
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createMaintenanceReportsTable();
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

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Socket',
            'description' => 'Wall socket sparking in the lab.',
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
}
