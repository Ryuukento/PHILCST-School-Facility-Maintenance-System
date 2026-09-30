<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 10 — Final Security & Permission Audit.
 *
 * Task 9 centralized "can this user modify this report" in
 * App\Services\ReportAuthorizationService and enforced it in
 * ReportController::update(). This suite locks the *other* write paths that
 * can reach maintenance_reports, so none of them can be used as a way around
 * that gate:
 *
 *   1. DELETE /api/reports/{id}            — destroy()
 *   2. POST /api/damage-reports/{id}/status — propagates to the linked
 *      maintenance report via MaintenanceReportSyncService
 *   3. POST /api/reports                    — creation must not be usable to
 *      self-assign or to plant a pre-completed report
 *
 * Every case asserts BOTH the HTTP status AND that the stored row is
 * unchanged, so a "403 that still wrote" cannot pass.
 */
class ReportAuthorizationBypassAuditTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_authorization_bypass_audit_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------
    // 1. DELETE /api/reports/{id}
    // ---------------------------------------------------------------

    public function test_head_maintenance_cannot_delete_a_report_from_another_department(): void
    {
        $ownDept = $this->seedDepartment();
        $otherDept = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDept]);
        // The head is the report's author, but the report now belongs to
        // another department — deletion is the most destructive modification
        // there is, so department ownership must still govern it.
        $reportId = $this->seedReport(['department_id' => $otherDept, 'created_by' => $headId]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->deleteJson("/api/reports/{$reportId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('maintenance_reports', ['report_id' => $reportId]);
    }

    public function test_staff_cannot_delete_a_report_from_another_department(): void
    {
        $staffDept = $this->seedDepartment();
        $otherDept = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $staffDept]);
        $reportId = $this->seedReport([
            'department_id' => $otherDept,
            'created_by' => $staffId,
            'assigned_to' => $staffId,
        ]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->deleteJson("/api/reports/{$reportId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('maintenance_reports', ['report_id' => $reportId]);
    }

    public function test_administrator_can_still_delete_any_report(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $reportId = $this->seedReport(['department_id' => $this->seedDepartment()]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->deleteJson("/api/reports/{$reportId}")
            ->assertOk();

        $this->assertDatabaseMissing('maintenance_reports', ['report_id' => $reportId]);
    }

    public function test_head_maintenance_can_still_delete_own_department_report(): void
    {
        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport(['department_id' => $deptId, 'created_by' => $headId]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->deleteJson("/api/reports/{$reportId}")
            ->assertOk();

        $this->assertDatabaseMissing('maintenance_reports', ['report_id' => $reportId]);
    }

    // ---------------------------------------------------------------
    // 2. Damage-report status sync bypass
    // ---------------------------------------------------------------

    public function test_head_maintenance_cannot_change_another_departments_report_status_via_damage_report_sync(): void
    {
        $ownDept = $this->seedDepartment();
        $otherDept = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $ownDept]);

        $reportId = $this->seedReport(['department_id' => $otherDept, 'status' => 'submitted']);
        $damageReportId = $this->seedDamageReport([
            'report_id' => $reportId,
            'department_id' => $otherDept,
            'status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertStatus(403);

        // The linked maintenance report must be untouched.
        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
        $this->assertSame('pending', DB::table('damage_reports')->where('id', $damageReportId)->value('status'));
    }

    public function test_unassigned_staff_cannot_drive_report_status_via_damage_report_sync(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // Same department, but assigned to somebody else — ReportController
        // would answer 403 here, so this path must too.
        $reportId = $this->seedReport([
            'department_id' => $deptId,
            'assigned_to' => $otherStaffId,
            'status' => 'submitted',
        ]);
        $damageReportId = $this->seedDamageReport([
            'report_id' => $reportId,
            'department_id' => $deptId,
            'status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertStatus(403);

        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_head_maintenance_can_still_update_own_department_damage_report(): void
    {
        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);

        $reportId = $this->seedReport(['department_id' => $deptId, 'status' => 'submitted']);
        $damageReportId = $this->seedDamageReport([
            'report_id' => $reportId,
            'department_id' => $deptId,
            'status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertOk();

        // Sync still works for an authorized actor.
        $this->assertSame('assigned', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_administrator_cannot_drive_a_linked_report_through_the_damage_status_endpoint(): void
    {
        // 2026-09-27 — this endpoint syncs onto the linked maintenance report
        // (under_review -> "assigned"), so it must not become a way around
        // the rule that the Administrator does not assign or work reports.
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $otherDept = $this->seedDepartment();

        $reportId = $this->seedReport(['department_id' => $otherDept, 'status' => 'submitted']);
        $damageReportId = $this->seedDamageReport([
            'report_id' => $reportId,
            'department_id' => $otherDept,
            'status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertStatus(403);

        $this->assertSame('submitted', DB::table('maintenance_reports')->where('report_id', $reportId)->value('status'));
    }

    public function test_damage_report_without_linked_maintenance_report_is_unaffected_by_the_gate(): void
    {
        // A legacy damage report with report_id = null has no maintenance
        // report to protect, so the department gate must not block the
        // existing damage-report-only workflow.
        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $damageReportId = $this->seedDamageReport([
            'report_id' => null,
            'department_id' => $deptId,
            'status' => 'pending',
        ]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson("/api/damage-reports/{$damageReportId}/status", ['status' => 'under_review'])
            ->assertOk();
    }

    // ---------------------------------------------------------------
    // 3. Creation must not be an escalation vector
    // ---------------------------------------------------------------

    public function test_staff_cannot_assign_personnel_through_report_creation(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $victimId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Carpentry',
                'title' => 'Broken Window',
                'description' => 'Window latch is broken.',
                'location' => 'Room 210',
                'assigned_to' => $victimId,
            ])
            ->assertStatus(201);

        // Maintenance Staff cannot assign personnel (BUSINESS_RULES.md), so a
        // client-supplied assigned_to must be ignored, not honored.
        $this->assertNull(DB::table('maintenance_reports')->where('created_by', $staffId)->value('assigned_to'));
    }

    public function test_staff_cannot_plant_a_precompleted_report(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Already Fixed',
                'description' => 'Skipping the workflow.',
                'location' => 'Room 210',
                'status' => 'completed',
            ])
            ->assertStatus(201);

        // Bypassing the STATUS_TRANSITIONS workflow (and the completion-proof
        // requirement) by creating a report that is already finished must not
        // be possible.
        $this->assertSame('submitted', DB::table('maintenance_reports')->where('created_by', $staffId)->value('status'));
    }

    public function test_administrator_does_not_submit_reports_at_all(): void
    {
        // Pre-existing RBAC policy (BUSINESS_RULES.md): Administrator reviews,
        // assigns and monitors reports but is not a report submitter, so
        // POST /api/reports is role-gated away from super_admin entirely.
        // Asserted here so the store() hardening above cannot be misread as
        // having taken this capability away.
        $adminId = $this->seedUser(['role' => 'super_admin', 'department_id' => $this->seedDepartment()]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking Pipe',
                'description' => 'Pipe under the sink is leaking.',
                'location' => 'Room 101',
            ])
            ->assertStatus(403);
    }

    public function test_head_maintenance_can_still_assign_at_creation_time(): void
    {
        // Head Maintenance CAN assign personnel, so its assigned_to must still
        // be honored — the hardening above must not over-reach.
        //
        // NOTE: 'in_progress' (not 'assigned') is used because store()'s
        // pre-existing validation only accepts
        // submitted|in_progress|completed|closed|cancelled at creation time.
        // That is unrelated to the TASK 10 hardening, which only decides
        // whether a supplied status is HONORED or silently downgraded to
        // 'submitted'.
        $deptId = $this->seedDepartment();
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $techId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'Electrical',
                'title' => 'Flickering Light',
                'description' => 'Ceiling light flickers.',
                'location' => 'Room 305',
                'assigned_to' => $techId,
                'status' => 'in_progress',
            ])
            ->assertStatus(201);

        $row = DB::table('maintenance_reports')->where('created_by', $headId)->first();
        $this->assertSame($techId, (int) $row->assigned_to);
        $this->assertSame('in_progress', $row->status);
    }

    // ---------------------------------------------------------------
    // Bypass via repair-request CREATION (POST /api/repairs) — VECTOR REMOVED
    //
    // TASK 13 (application-level Repair Request retirement).
    //
    // THE ATTACK THIS SECTION GUARDED
    // RepairService::createRequest() called syncDamageReportStatus() at the
    // end, which mapped repair_status 'pending'|'assigned' -> damage status
    // 'under_review' -> maintenance report status 'assigned'. The target was
    // chosen entirely by a client-supplied report_id / damage_report_id whose
    // only validation was `exists:`, so creating a repair request was a way to
    // drive another department's report out of 'submitted' — and to assign a
    // technician to it — without ever touching the gated update() endpoint.
    // Task 10 closed it by applying ReportAuthorizationService to that path.
    //
    // WHY FIVE TESTS WERE REPLACED BY THE ONE BELOW
    // Task 13 deleted RepairController, RepairService and POST /api/repairs
    // outright. The five tests here all drove that endpoint: three asserted it
    // returned 403 across department boundaries, two asserted the legitimate
    // same-department and unlinked-damage-report cases still returned 201.
    // None of them can run now — the route is gone, so every one of them fails
    // on a missing endpoint rather than on anything they were written to
    // detect.
    //
    // THIS IS NOT A WEAKENING. The bypass is not merely un-asserted; the write
    // path that carried it no longer exists, which is a strictly stronger
    // outcome than a gate returning 403. The gate itself remains fully covered
    // by the three sections ABOVE — DELETE /api/reports/{id},
    // POST /api/damage-reports/{id}/status (which is the OTHER route into
    // MaintenanceReportSyncService, and the one that still matters), and
    // POST /api/reports — all of which are untouched by Task 13 and still
    // assert both the HTTP status and that the stored row did not move.
    //
    // What replaces the five is a guard that the vector cannot come back
    // quietly, plus an explicit check that the shared gate was not deleted
    // along with the Repair code that used to call it.
    // ---------------------------------------------------------------

    public function test_the_repair_request_write_path_no_longer_exists(): void
    {
        $registered = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/repairs')) {
                $registered[] = implode('|', $route->methods()) . ' /' . $route->uri();
            }
        }

        $this->assertSame(
            [],
            $registered,
            "The POST /api/repairs bypass vector was removed in Task 13. These routes are back:\n"
            . implode("\n", $registered)
        );

        // Belt and braces: the service that performed the unauthorized write
        // is gone too, so the vector cannot be re-exposed by wiring the old
        // logic to a differently-named route.
        $this->assertFalse(
            class_exists(\App\Services\RepairService::class),
            'RepairService performed the cross-department write this section guarded.'
        );
        $this->assertFalse(
            class_exists(\App\Http\Controllers\Api\RepairController::class),
            'RepairController exposed the cross-department write this section guarded.'
        );
    }

    /**
     * TASK 13 — the gate must OUTLIVE the module that used to call it.
     *
     * ReportAuthorizationService is on the retirement brief's protected list.
     * Deleting it while deleting RepairService would be the single most
     * dangerous possible over-reach in this task: every other bypass test in
     * this file would keep passing, because they assert 403s that the
     * middleware and ownership checks would still produce in most scenarios,
     * while the centralized rule silently vanished.
     */
    public function test_the_centralized_authorization_gate_survived_the_repair_retirement(): void
    {
        $this->assertTrue(
            class_exists(\App\Services\ReportAuthorizationService::class),
            'ReportAuthorizationService is the centralized "can this user modify this report" '
            . 'rule and must survive the Repair Request retirement.'
        );

        foreach ([
            \App\Services\MaintenanceReportSyncService::class,
            \App\Services\PersonnelDirectoryService::class,
            \App\Services\RoleNormalizerService::class,
            \App\Services\NotificationService::class,
            \App\Services\DispatchService::class,
        ] as $shared) {
            $this->assertTrue(
                class_exists($shared),
                "{$shared} is a shared service explicitly protected by the Task 13 brief."
            );
        }
    }

    // ---------------------------------------------------------------
    // Schema / seeding
    // ---------------------------------------------------------------

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('repair_histories');
        Schema::dropIfExists('repair_requests');
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
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
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();
        $this->createRepairRequestsTable();
        $this->createRepairHistoriesTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createRepairRequestsTable(): void
    {
        Schema::create('repair_requests', function ($table): void {
            $table->bigIncrements('id');
            $table->string('repair_code', 50);
            $table->unsignedBigInteger('damage_report_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('technician_user_id')->nullable();
            $table->string('repair_type', 100)->default('corrective');
            $table->text('repair_description')->nullable();
            $table->decimal('repair_cost', 12, 2)->default(0);
            $table->string('repair_status', 30)->default('pending');
            $table->date('repair_date')->nullable();
            $table->date('estimated_completion_date')->nullable();
            $table->date('completion_date')->nullable();
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->unsignedInteger('replacement_quantity')->nullable();
            $table->unsignedBigInteger('replacement_dispatch_id')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    private function createRepairHistoriesTable(): void
    {
        Schema::create('repair_histories', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('repair_request_id');
            $table->string('action_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->unsignedInteger('technician_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->longText('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function ($table): void {
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

    private function createDamageReportsTable(): void
    {
        Schema::create('damage_reports', function ($table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedBigInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedBigInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDamageReportHistoriesTable(): void
    {
        Schema::create('damage_report_histories', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
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

    private function seedDamageReport(array $overrides = []): int
    {
        return DB::table('damage_reports')->insertGetId(array_merge([
            'damage_report_code' => 'DR-' . uniqid(),
            'report_id' => null,
            'item_id' => null,
            'room_id' => null,
            'department_id' => null,
            'damage_description' => 'Something is broken.',
            'severity_level' => 'medium',
            'reported_by' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
