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
 * TASK 44 (M1 — Unified Maintenance Report Read Surface).
 *
 * Additive coverage for the new Schema::hasTable()-guarded fields
 * ReportController::index()/show() now expose from the damage_reports and
 * repair_requests compatibility layer (damage_report_status, repair_status,
 * repair_technician_name, report_category, and the show()-only replacement
 * dispatch fields). Also proves the Phase 5 N+1/fan-out safety finding: the
 * new joins key off unique report_id/replacement_dispatch_id FKs, never off
 * dispatches.report_id (a general, user-suppliable, non-unique field), so a
 * report with several unrelated dispatches sharing its report_id still
 * returns exactly one row.
 *
 * No existing behavior changed by this task — these tests pin down new,
 * additive response fields only.
 *
 * TASK 65 PHASE 4 — production-valid seed values.
 *
 * These tests run on SQLite, where every one of the columns below is a plain
 * VARCHAR, so the seed data was never checked against the real ENUM
 * definitions and had drifted away from them. Four seeded values
 * ('asset', 'in_repair', 'in_progress', 'failed_repair') do not exist in
 * production MySQL.
 *
 * That was proven, not assumed: inserting those values into a session-scoped
 * TEMPORARY table mirroring the live ENUM definitions produced
 * "Data truncated for column ... at row 1" and stored the EMPTY STRING. The
 * server's sql_mode (NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION)
 * omits STRICT_TRANS_TABLES, so MySQL silently coerces rather than rejecting.
 *
 * The consequence is that this file — the designated regression gate for the
 * unified read surface — was certifying the read surface against rows
 * production can never contain. The fix is to make the test truthful, NOT to
 * relax what it proves: every assertion below is retained, and each is now
 * made against a value the live schema actually accepts. The production ENUM
 * members are pinned as constants and asserted membership-wise, so if the
 * seed data drifts again the guard test fails instead of passing silently.
 *
 * Production ENUMs are NOT changed by this task.
 */
class ReportUnifiedReadSurfaceTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /**
     * Read from information_schema on the live database
     * (school_facility_maintenance) — see the class docblock. These mirror
     * production; they do not define it.
     */
    private const PRODUCTION_REPORT_CATEGORIES = ['general', 'repair_replacement'];

    private const PRODUCTION_DAMAGE_REPORT_STATUSES = [
        'pending', 'under_review', 'repairing', 'repaired', 'replaced', 'closed',
    ];

    private const PRODUCTION_REPAIR_STATUSES = [
        'pending', 'assigned', 'diagnosing', 'repairing', 'waiting_parts',
        'completed', 'failed', 'archived',
    ];

    private const PRODUCTION_DISPATCH_STATUSES = ['pending', 'approved', 'released', 'cancelled'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_unified_read_surface_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    /**
     * TASK 13 PHASE 7 — narrowed, and deliberately made HARDER to pass.
     *
     * This test used to assert that index() exposed FOUR fields:
     * report_category, damage_report_status, repair_status and
     * repair_technician_name. The last two came from a LEFT JOIN onto
     * repair_requests that Task 13 removed, so they are no longer in the
     * payload and asserting them would fail.
     *
     * The Damage Report assertions are retained UNCHANGED — that is the
     * shared coverage the brief protects, and it is the half that proves the
     * All Reports list still works.
     *
     * The crucial detail is that a repair_requests row is STILL SEEDED, with
     * a technician attached and linked to this very report. Deleting the seed
     * along with the assertions would have made the test vacuous: it would
     * pass just as happily against a ReportController that still had the join
     * but had no data to find. By seeding the row and then asserting the
     * fields are ABSENT, the test proves the join itself is gone rather than
     * merely unexercised.
     */
    public function test_index_exposes_report_category_and_damage_lifecycle_fields(): void
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $technicianId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Jane Technician']);

        // 'repair_replacement' / 'repairing' / 'diagnosing' replace the
        // previously seeded 'asset' / 'in_repair' / 'in_progress', which
        // production MySQL truncates to ''. The scenario is unchanged: a
        // report mid-repair, with a technician actively working it.
        $reportId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'report_category' => 'repair_replacement',
        ]);

        DB::table('damage_reports')->insert($this->damageReportRow([
            'report_id' => $reportId,
            'status' => 'repairing',
        ]));

        // Seeded ON PURPOSE even though nothing should read it — see docblock.
        DB::table('repair_requests')->insertGetId($this->repairRequestRow([
            'report_id' => $reportId,
            'technician_user_id' => $technicianId,
            'repair_status' => 'diagnosing',
        ]));

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $row = collect($response->json('data.reports'))->firstWhere('report_id', $reportId);

        $this->assertNotNull($row, 'The seeded report was not present in the list response.');
        $this->assertSame('repair_replacement', $row['report_category']);
        $this->assertSame('repairing', $row['damage_report_status']);

        // The read surface is only meaningfully proven if the values it
        // round-tripped are values production can actually hold.
        $this->assertContains($row['report_category'], self::PRODUCTION_REPORT_CATEGORIES);
        $this->assertContains($row['damage_report_status'], self::PRODUCTION_DAMAGE_REPORT_STATUSES);

        // TASK 13 — the Repair Request projection is retired.
        foreach (['repair_status', 'repair_technician_name', 'repair_request_id', 'repair_code'] as $retired) {
            $this->assertArrayNotHasKey(
                $retired,
                $row,
                "ReportController::index() must not re-introduce the repair_requests join ({$retired})."
            );
        }

        // report_category is 'repair_replacement' here and must NOT be
        // mistaken for Repair Request data — it is a maintenance_reports
        // column and the brief keeps it explicitly. Asserted above; restated
        // here because these two are one grep apart.
        $this->assertArrayHasKey('report_category', $row);
    }

    /**
     * TASK 13 PHASE 7 — the primary All Reports list must keep every column
     * the page actually renders. The removed joins were LEFT joins used
     * purely for projection (no WHERE / ORDER BY / GROUP BY referenced them),
     * so this asserts the rest of the row survived intact.
     */
    public function test_index_still_returns_the_full_primary_workflow_row(): void
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        $reportId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
        ]);

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $row = collect($response->json('data.reports'))->firstWhere('report_id', $reportId);

        $this->assertNotNull($row);

        foreach ([
            'report_id',
            'title',
            'status',
            'priority',
            'location',
            'department_id',
            'assigned_to',
            'created_by',
            'created_at',
            'report_category',
            'damage_report_status',
        ] as $field) {
            $this->assertArrayHasKey(
                $field,
                $row,
                "Retiring Repair Requests must not have removed '{$field}' from the All Reports list."
            );
        }
    }

    /**
     * TASK 13 PHASE 7 — inverted for the Repair half, unchanged for Damage.
     *
     * This previously asserted show() exposed repair_code, repair_status,
     * repair_technician_name AND the three replacement-dispatch fields.
     *
     * THE REPLACEMENT DISPATCH FIELDS WENT WITH THE REPAIR JOIN, AND THAT IS
     * DELIBERATE. replacement_dispatch_id/_code/_status were reachable ONLY
     * through repair_requests.replacement_dispatch_id — that column was the
     * join key. There is no equivalent link anywhere else: damage_reports has
     * no replacement_dispatch_id, and dispatches.report_id is a general,
     * user-suppliable field that can be one-to-many against a report (the
     * fan-out test below exists precisely because of that). Re-sourcing the
     * field would mean redesigning the relationship, which Task 13 puts out
     * of scope.
     *
     * The scenario below still seeds all of it — the repair request, the
     * technician, and a real dispatch wired up as the replacement — so the
     * absence assertions prove the join is gone rather than merely starved of
     * data. The damage_report_code / damage_report_status assertions are
     * retained verbatim.
     */
    public function test_show_exposes_damage_fields_and_no_longer_exposes_repair_fields(): void
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);
        $technicianId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'John Repairman']);

        $reportId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
        ]);

        // 'replaced' replaces the previously seeded 'failed_repair', which
        // production MySQL truncates to ''. It is also the semantically
        // correct terminal damage status for this scenario: the repair
        // failed, so the item went out for replacement instead.
        DB::table('damage_reports')->insert($this->damageReportRow([
            'report_id' => $reportId,
            'damage_report_code' => 'DR-0001',
            'status' => 'replaced',
        ]));

        $dispatchId = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'DSP-0001',
            'department_id' => $deptId,
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('repair_requests')->insert($this->repairRequestRow([
            'report_id' => $reportId,
            'repair_code' => 'RR-0001',
            'technician_user_id' => $technicianId,
            'repair_status' => 'failed',
            'replacement_dispatch_id' => $dispatchId,
        ]));

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson("/api/reports/{$reportId}");

        $response->assertOk();
        $data = $response->json('data.report');

        // The Damage Report half of the read surface is untouched.
        $this->assertSame('DR-0001', $data['damage_report_code']);
        $this->assertSame('replaced', $data['damage_report_status']);
        $this->assertContains($data['damage_report_status'], self::PRODUCTION_DAMAGE_REPORT_STATUSES);

        // The Repair Request half — and the replacement-dispatch fields that
        // could only be reached through it — are retired.
        foreach ([
            'repair_code',
            'repair_status',
            'repair_technician_name',
            'repair_request_id',
            'replacement_dispatch_id',
            'replacement_dispatch_code',
            'replacement_dispatch_status',
        ] as $retired) {
            $this->assertArrayNotHasKey(
                $retired,
                $data,
                "ReportController::show() must not re-introduce the repair_requests join ({$retired}). "
                . 'A repair request, technician and replacement dispatch are all seeded in this '
                . 'test, so this assertion fails the moment the join comes back.'
            );
        }

        // Sanity: the dispatch row really does exist and really is wired to
        // the repair request, so the absences above are caused by the removed
        // join and not by a broken fixture.
        $this->assertSame(
            $dispatchId,
            (int) DB::table('repair_requests')->where('repair_code', 'RR-0001')->value('replacement_dispatch_id')
        );
        $this->assertContains(
            DB::table('dispatches')->where('dispatch_code', 'DSP-0001')->value('status'),
            self::PRODUCTION_DISPATCH_STATUSES
        );
    }

    public function test_index_does_not_fan_out_when_unrelated_dispatches_share_report_id(): void
    {
        // Phase 5 safety proof: dispatches.report_id is a general, nullable,
        // user-suppliable field (see DispatchController::store() validation)
        // and is NOT joined on directly by ReportController. Two unrelated
        // dispatches sharing this report's report_id must not duplicate the
        // report row in the list response.
        //
        // TASK 13 — this test is unchanged and still passes, but its meaning
        // has strengthened. It used to prove "we join the UNIQUE
        // repair_requests.replacement_dispatch_id rather than the non-unique
        // dispatches.report_id". After the retirement there is no dispatch
        // join at all, so it now proves the simpler and stronger property
        // that removing the joins did not change row counts. It is kept
        // because it is the only guard stopping someone "restoring" the
        // replacement-dispatch fields by joining dispatches.report_id —
        // which would silently fan out the All Reports list.
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        $reportId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
        ]);

        DB::table('dispatches')->insert([
            [
                'dispatch_code' => 'DSP-1001',
                'department_id' => $deptId,
                'report_id' => $reportId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'dispatch_code' => 'DSP-1002',
                'department_id' => $deptId,
                'report_id' => $reportId,
                'status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');

        $response->assertOk();
        $matches = collect($response->json('data.reports'))->where('report_id', $reportId);

        $this->assertCount(1, $matches, 'Unrelated dispatches sharing report_id must not duplicate the report row.');
    }

    public function test_index_and_show_return_null_lifecycle_fields_when_no_linked_records_exist(): void
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        $reportId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
        ]);

        $indexResponse = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson('/api/reports');
        $indexResponse->assertOk();
        $row = collect($indexResponse->json('data.reports'))->firstWhere('report_id', $reportId);

        // TASK 13 — the Damage Report field must still be PRESENT-but-null
        // for a report with no damage record. "Present and null" is the
        // contract the frontend relies on (reports.php reads
        // report.damage_report_status directly), so this stays a null check
        // rather than being softened to a key check.
        $this->assertArrayHasKey('damage_report_status', $row);
        $this->assertNull($row['damage_report_status']);

        // The repair fields are not null-here-because-unlinked; they are gone.
        $this->assertArrayNotHasKey('repair_status', $row);
        $this->assertArrayNotHasKey('repair_technician_name', $row);

        $showResponse = $this
            ->actingAsSessionUser($viewerId, 'super_admin')
            ->getJson("/api/reports/{$reportId}");
        $showResponse->assertOk();
        $data = $showResponse->json('data.report');

        $this->assertArrayHasKey('damage_report_id', $data);
        $this->assertNull($data['damage_report_id']);

        $this->assertArrayNotHasKey('repair_request_id', $data);
        $this->assertArrayNotHasKey('replacement_dispatch_id', $data);
    }

    /**
     * TASK 65 PHASE 4 — the guard that would have caught the original drift.
     *
     * The per-test assertions above only cover values a test happens to
     * override. The seed helpers' DEFAULTS are what silently propagate into
     * every other scenario, and SQLite's VARCHAR columns accept anything, so
     * nothing else in this file can detect a default going stale.
     *
     * This does not test application code; it tests the fixtures, which is
     * the layer that was actually wrong.
     */
    public function test_seed_helper_defaults_are_values_production_mysql_can_store(): void
    {
        $this->assertContains(
            $this->seedReportDefaults()['report_category'],
            self::PRODUCTION_REPORT_CATEGORIES,
            'Default report_category is not a member of the production ENUM.'
        );
        $this->assertContains(
            $this->damageReportRow()['status'],
            self::PRODUCTION_DAMAGE_REPORT_STATUSES,
            'Default damage_reports.status is not a member of the production ENUM.'
        );
        $this->assertContains(
            $this->repairRequestRow()['repair_status'],
            self::PRODUCTION_REPAIR_STATUSES,
            'Default repair_requests.repair_status is not a member of the production ENUM.'
        );
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('repair_requests');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
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
        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->string('report_category', 30)->default('general')->after('description');
        });
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('repair_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('repair_code', 50)->nullable();
            $table->unsignedBigInteger('damage_report_id')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('technician_user_id')->nullable();
            $table->string('repair_status', 30)->default('pending');
            $table->unsignedBigInteger('replacement_dispatch_id')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
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

    /**
     * Split out from seedReport() so the drift guard can inspect the defaults
     * without inserting a row (and without the seedUser() side effect).
     */
    private function seedReportDefaults(): array
    {
        return [
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'report_category' => 'general',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'assigned_to' => null,
            'department_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function seedReport(array $overrides = []): int
    {
        $attributes = array_merge(
            $this->seedReportDefaults(),
            ['created_by' => null],
            $overrides
        );

        $attributes['created_by'] ??= $this->seedUser();

        return DB::table('maintenance_reports')->insertGetId($attributes, 'report_id');
    }

    private function damageReportRow(array $overrides = []): array
    {
        return array_merge([
            'damage_report_code' => 'DR-' . uniqid(),
            'report_id' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function repairRequestRow(array $overrides = []): array
    {
        return array_merge([
            'repair_code' => 'RR-' . uniqid(),
            'damage_report_id' => null,
            'report_id' => null,
            'technician_user_id' => null,
            'repair_status' => 'pending',
            'replacement_dispatch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
