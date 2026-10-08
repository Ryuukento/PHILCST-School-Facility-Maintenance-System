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
 * TASK 99 — Damage Report as a report CLASSIFICATION of Maintenance Report.
 *
 * Damage Report is no longer a sidebar module. It is a classification that a
 * Maintenance Report acquires, and it is reached through the Report Type
 * filter on All Reports / Export Reports.
 *
 * 2026-10-08 — the predicate was WIDENED by explicit user decision. TASK 99
 * originally scoped "Damage Report" to the replacement OUTCOME only
 * (damage_reports.status = 'replaced', or a non-null replaced_at):
 *
 *   REPAIRABLE      — the item could still be fixed. damage_reports.status
 *                     ends at 'repaired'. Stayed a normal Maintenance Report.
 *   NOT REPAIRABLE  — the item had to be replaced. RepairService::
 *                     fulfillReplacement() writes status = 'replaced' and
 *                     stamps replaced_at / replaced_by / replacement_item_id.
 *                     Classified as a Damage Report.
 *
 * That left a gap: Need Change requests auto-link into a damage_reports row
 * at EVERY status (pending, under_review, repairing, repaired, replaced,
 * closed — see DamageReportService::attachNeedChangeAsDamageReport()), and
 * those were invisible to the replaced-only filter, which for a while forced
 * a dedicated "Damage Reports" sidebar entry back in as the only way to see
 * them (see DamageReportRoleRedesignTest's history of that reversal). The
 * rule is now simply "this report has a linked damage_reports row at all"
 * (ReportController::index()'s `dr.report_id IS NOT NULL`), which makes every
 * such case reachable through this filter and lets the sidebar entry stay
 * retired for good.
 *
 * What these tests now pin down:
 *
 *  - The predicate is "a linked damage_reports row exists", regardless of its
 *    status or outcome — not report_category, and not any text match on
 *    title/description. A report about a damaged asset that was successfully
 *    repaired (and never replaced) IS still classified as a Damage Report,
 *    because the linkage itself is what matters now, not the outcome.
 *  - Wording and report_category alone, with NO linked damage_reports row,
 *    still do not classify a report as Damage.
 *  - The filter composes with the controller's role scoping instead of
 *    replacing it, so it cannot be used to reach a report the caller is not
 *    authorized to see. Export reads this same authorized response, so the
 *    export inherits both the classification filter and the scoping.
 *  - Filtering is read-only: no damage_reports row is created or mutated.
 */
class ReportTypeClassificationFilterTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_type_classification_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    public function test_no_report_type_filter_returns_both_classifications(): void
    {
        $scenario = $this->seedClassificationScenario();

        $ids = $this->reportIdsFor($scenario['viewer_id']);

        $this->assertContains($scenario['plain_id'], $ids);
        $this->assertContains($scenario['repaired_id'], $ids);
        $this->assertContains($scenario['replaced_id'], $ids);
        $this->assertContains($scenario['closed_after_replacement_id'], $ids);
    }

    public function test_report_type_damage_returns_any_linked_damage_case(): void
    {
        $scenario = $this->seedClassificationScenario();

        $ids = $this->reportIdsFor($scenario['viewer_id'], ['report_type' => 'damage']);

        $this->assertContains($scenario['replaced_id'], $ids, 'A replaced item is a Damage Report.');
        $this->assertContains(
            $scenario['closed_after_replacement_id'],
            $ids,
            'Closing a replaced case must not erase its Damage classification.'
        );
        $this->assertContains(
            $scenario['repaired_id'],
            $ids,
            '2026-10-08: any linked damage_reports row counts, including a repaired (non-replaced) outcome.'
        );

        $this->assertNotContains(
            $scenario['plain_id'],
            $ids,
            'A report with no linked damage_reports row at all is never a Damage Report.'
        );
    }

    public function test_report_type_maintenance_excludes_any_linked_damage_case(): void
    {
        $scenario = $this->seedClassificationScenario();

        $ids = $this->reportIdsFor($scenario['viewer_id'], ['report_type' => 'maintenance']);

        $this->assertContains(
            $scenario['plain_id'],
            $ids,
            'A general report has no damage_reports row; NULL must not drop it from the Maintenance set.'
        );

        $this->assertNotContains(
            $scenario['repaired_id'],
            $ids,
            '2026-10-08: a repaired outcome still has a linked damage_reports row, so it is Damage now, not Maintenance.'
        );
        $this->assertNotContains($scenario['replaced_id'], $ids);
        $this->assertNotContains($scenario['closed_after_replacement_id'], $ids);
    }

    /**
     * The two branches must partition the base set exactly. If they did not,
     * "All Reports" would either hide rows or show them twice, and the Dean's
     * Damage-vs-Maintenance split would not reconcile against the total.
     */
    public function test_maintenance_and_damage_partition_the_unfiltered_result_set(): void
    {
        $scenario = $this->seedClassificationScenario();
        $viewerId = $scenario['viewer_id'];

        $all = $this->reportIdsFor($viewerId);
        $maintenance = $this->reportIdsFor($viewerId, ['report_type' => 'maintenance']);
        $damage = $this->reportIdsFor($viewerId, ['report_type' => 'damage']);

        sort($all);
        $union = array_merge($maintenance, $damage);
        sort($union);

        $this->assertSame($all, $union, 'Maintenance + Damage must sum to the unfiltered set.');
        $this->assertSame([], array_intersect($maintenance, $damage), 'The two classifications must not overlap.');
    }

    /**
     * 'all' is what the UI sends for "no classification filter" if the blank
     * option is ever replaced, and an unknown value must fail open to the
     * unfiltered list rather than silently returning nothing.
     */
    public function test_unrecognised_report_type_values_do_not_filter(): void
    {
        $scenario = $this->seedClassificationScenario();

        foreach (['', 'all', 'not-a-classification'] as $value) {
            $ids = $this->reportIdsFor($scenario['viewer_id'], ['report_type' => $value]);

            $this->assertContains($scenario['plain_id'], $ids, "report_type={$value} must not filter.");
            $this->assertContains($scenario['replaced_id'], $ids, "report_type={$value} must not filter.");
        }
    }

    public function test_every_row_carries_its_computed_classification(): void
    {
        $scenario = $this->seedClassificationScenario();

        $rows = collect($this->reportRowsFor($scenario['viewer_id']))->keyBy('report_id');

        $this->assertSame('maintenance', $rows[$scenario['plain_id']]['report_type']);
        // 2026-10-08: repaired now classifies as 'damage' too — any linked
        // damage_reports row counts, not just the 'replaced' outcome.
        $this->assertSame('damage', $rows[$scenario['repaired_id']]['report_type']);
        $this->assertSame('damage', $rows[$scenario['replaced_id']]['report_type']);
        $this->assertSame('damage', $rows[$scenario['closed_after_replacement_id']]['report_type']);

        // The classification is derived from the repair outcome, never from
        // report_category: both damage cases below share the same category as
        // the repaired one, yet classify differently.
        $this->assertSame('repair_replacement', $rows[$scenario['repaired_id']]['report_category']);
        $this->assertSame('repair_replacement', $rows[$scenario['replaced_id']]['report_category']);
    }

    /**
     * The Dean's original rule, stated as a test: "broken", "damaged" or a
     * repair_replacement category alone do not make a Damage Report.
     *
     * 2026-10-08 — this test used to insert an actual damage_reports row
     * (status='under_review') for the wordy report and assert it stayed
     * 'maintenance', to prove the OUTCOME mattered, not the wording. Now
     * that any linked damage_reports row counts as 'damage' (see the file
     * docblock), that setup would correctly classify as damage — the
     * linkage itself, not the wording, is what the new rule reacts to. This
     * test is narrowed to what it can still honestly prove: wording and
     * report_category, with NO linked damage_reports row at all, never
     * classify a report as Damage.
     */
    public function test_wording_and_category_alone_with_no_linked_damage_row_do_not_classify_as_damage(): void
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        $wordyId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'title' => 'Damage to broken projector — needs replacement',
            'description' => 'The unit is damaged and broken beyond use.',
            'report_category' => 'repair_replacement',
        ]);

        // Deliberately no damage_reports row at all for this report.

        $damageIds = $this->reportIdsFor($viewerId, ['report_type' => 'damage']);
        $maintenanceIds = $this->reportIdsFor($viewerId, ['report_type' => 'maintenance']);

        $this->assertNotContains($wordyId, $damageIds);
        $this->assertContains($wordyId, $maintenanceIds);
    }

    /**
     * 2026-10-08 — the counterpart to the test above: once an actual
     * damage_reports row IS linked, status/outcome no longer matters. Even
     * an early-lifecycle status like 'under_review' (the shape a fresh Need
     * Change auto-link arrives in) is enough to classify as Damage.
     */
    public function test_any_linked_damage_row_classifies_as_damage_regardless_of_status(): void
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        $linkedId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'title' => 'Classroom chair needs replacement part',
            'report_category' => 'general',
        ]);

        DB::table('damage_reports')->insert($this->damageReportRow([
            'report_id' => $linkedId,
            'status' => 'under_review',
        ]));

        $damageIds = $this->reportIdsFor($viewerId, ['report_type' => 'damage']);
        $maintenanceIds = $this->reportIdsFor($viewerId, ['report_type' => 'maintenance']);

        $this->assertContains($linkedId, $damageIds);
        $this->assertNotContains($linkedId, $maintenanceIds);
    }

    /**
     * Phase 6/7: the filter is an additional narrowing on top of role scoping,
     * never a way around it. A non-maintenance role sees only reports it
     * created or is assigned to — asking for the Damage classification must
     * not surface somebody else's Damage Report.
     */
    public function test_report_type_filter_cannot_widen_role_scoped_access(): void
    {
        $deptId = $this->seedDepartment();
        $ownerId = $this->seedUser(['role' => 'user', 'department_id' => $deptId]);
        $strangerId = $this->seedUser(['role' => 'user', 'department_id' => $deptId]);

        $ownDamageId = $this->seedReport([
            'created_by' => $ownerId,
            'department_id' => $deptId,
            'report_category' => 'repair_replacement',
        ]);
        $this->seedReplacedDamageCase($ownDamageId);

        $foreignDamageId = $this->seedReport([
            'created_by' => $strangerId,
            'department_id' => $deptId,
            'report_category' => 'repair_replacement',
        ]);
        $this->seedReplacedDamageCase($foreignDamageId);

        $ids = $this->reportIdsFor($ownerId, ['report_type' => 'damage'], 'user');

        $this->assertContains($ownDamageId, $ids);
        $this->assertNotContains(
            $foreignDamageId,
            $ids,
            'The Report Type filter must not bypass the controller\'s role scoping.'
        );

        // And the unfiltered list is no wider, so the filter is not the thing
        // holding the scope in place.
        $unfiltered = $this->reportIdsFor($ownerId, [], 'user');
        $this->assertNotContains($foreignDamageId, $unfiltered);
    }

    /**
     * Export Reports is rendered client-side from the array the All Reports
     * page already holds — the authorization-scoped GET /api/reports response.
     * There is no second, unscoped query to audit, so proving the export
     * honours the classification reduces to proving the export UI drives this
     * endpoint's filter rather than re-deriving the classification itself.
     */
    public function test_export_ui_drives_the_authorized_report_type_filter(): void
    {
        $page = file_get_contents(__DIR__ . '/../../public/frontend/pages/reports.php');

        $this->assertStringContainsString(
            'filters.report_type = reportType',
            $page,
            'The page filter must forward the Report Type to GET /api/reports.'
        );
        $this->assertStringContainsString(
            'print-filter-report-type',
            $page,
            'The Export Reports modal must expose a Report Type control.'
        );
        $this->assertStringContainsString(
            'pageTypeEl.value = printFilterReportTypeEl.value',
            $page,
            'The export control must reuse the page filter, not build its own dataset.'
        );
        $this->assertStringContainsString(
            'report?.report_type',
            $page,
            'The rendered/exported type label must read the server-computed classification.'
        );

        // The export builds its rows from the already-authorized list rather
        // than fetching again; a direct fetch here would be an authorization
        // bypass surface.
        $this->assertStringNotContainsString(
            'apiRequest(`/reports/export',
            $page,
            'Export must not call a separate, unscoped export endpoint.'
        );
    }

    public function test_filtering_is_read_only_and_leaves_damage_cases_intact(): void
    {
        $scenario = $this->seedClassificationScenario();

        $before = DB::table('damage_reports')->orderBy('id')->get()->toArray();

        $this->reportIdsFor($scenario['viewer_id'], ['report_type' => 'damage']);
        $this->reportIdsFor($scenario['viewer_id'], ['report_type' => 'maintenance']);
        $this->reportIdsFor($scenario['viewer_id']);

        $after = DB::table('damage_reports')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'Filtering must not create, delete or mutate damage cases.');
        $this->assertCount(3, $after);
        $this->assertSame(
            4,
            DB::table('maintenance_reports')->count(),
            'Filtering must not delete maintenance reports.'
        );
    }

    /**
     * Removing the sidebar entry must not remove the data or the workflow: the
     * Damage Report records and the replacement stamps all still resolve.
     *
     * TASK 13 — narrowed to the Damage Report half, and given a new job.
     *
     * The two repair_status assertions were removed because
     * ReportController::index() no longer projects that column; every Damage
     * Report assertion is retained verbatim, which is the coverage that
     * actually matters here.
     *
     * What replaces them is stronger than what was lost. The scenario helper
     * still seeds repair_requests rows linked to these reports (deliberately
     * left in place — see seedClassificationScenario()), so this test now
     * proves TWO things at once:
     *
     *   1. The list surface no longer reads repair_requests, even when
     *      matching rows exist for the reports being listed.
     *   2. The repair_requests DATA IS UNTOUCHED. Task 13 is an
     *      application-level retirement with an explicit "database must
     *      remain unchanged" constraint, so a test that confirms the rows
     *      still sit in the table — while the API ignores them — is exactly
     *      the right shape for this task, and is the regression guard for a
     *      future Task 14 that goes too far.
     */
    public function test_damage_cases_survive_the_module_removal_and_repair_rows_are_left_untouched(): void
    {
        $scenario = $this->seedClassificationScenario();

        $replaced = DB::table('damage_reports')->where('report_id', $scenario['replaced_id'])->first();

        $this->assertNotNull($replaced);
        $this->assertSame('replaced', $replaced->status);
        $this->assertNotNull($replaced->replaced_at);
        $this->assertNotNull($replaced->replacement_item_id);

        $rows = collect($this->reportRowsFor($scenario['viewer_id']))->keyBy('report_id');

        $this->assertSame('replaced', $rows[$scenario['replaced_id']]['damage_report_status']);
        $this->assertSame('repaired', $rows[$scenario['repaired_id']]['damage_report_status']);

        // 1. The retired projection is gone despite matching rows existing.
        $this->assertArrayNotHasKey('repair_status', $rows[$scenario['replaced_id']]);
        $this->assertArrayNotHasKey('repair_status', $rows[$scenario['repaired_id']]);

        // 2. Those matching rows really are still there, unmodified.
        $this->assertSame(
            'failed',
            DB::table('repair_requests')->where('report_id', $scenario['replaced_id'])->value('repair_status'),
            'Task 13 must not have altered repair_requests data.'
        );
        $this->assertSame(
            'completed',
            DB::table('repair_requests')->where('report_id', $scenario['repaired_id'])->value('repair_status'),
            'Task 13 must not have altered repair_requests data.'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{viewer_id:int,plain_id:int,repaired_id:int,replaced_id:int,closed_after_replacement_id:int}
     */
    private function seedClassificationScenario(): array
    {
        $deptId = $this->seedDepartment();
        $viewerId = $this->seedUser(['role' => 'super_admin']);

        // 1. Ordinary maintenance work, no damaged asset involved.
        $plainId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'title' => 'Leaking faucet',
            'report_category' => 'general',
        ]);

        // 2. Damaged asset that COULD be fixed — stays a Maintenance Report.
        $repairedId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'title' => 'Aircon not cooling',
            'report_category' => 'repair_replacement',
        ]);
        DB::table('damage_reports')->insert($this->damageReportRow([
            'report_id' => $repairedId,
            'status' => 'repaired',
        ]));
        DB::table('repair_requests')->insert($this->repairRequestRow([
            'report_id' => $repairedId,
            'repair_status' => 'completed',
        ]));

        // 3. Damaged asset that could NOT be fixed and was replaced.
        $replacedId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'title' => 'Projector lamp assembly failure',
            'report_category' => 'repair_replacement',
        ]);
        $this->seedReplacedDamageCase($replacedId);
        DB::table('repair_requests')->insert($this->repairRequestRow([
            'report_id' => $replacedId,
            'repair_status' => 'failed',
        ]));

        // 4. Same as 3, but the case has since been closed. status no longer
        //    reads 'replaced'; the permanent replacement stamps must carry the
        //    classification.
        $closedId = $this->seedReport([
            'created_by' => $viewerId,
            'department_id' => $deptId,
            'title' => 'Replaced office chair',
            'report_category' => 'repair_replacement',
        ]);
        DB::table('damage_reports')->insert($this->damageReportRow([
            'report_id' => $closedId,
            'status' => 'closed',
            'replaced_at' => now(),
            'replaced_by' => $viewerId,
            'replacement_item_id' => 1,
        ]));

        return [
            'viewer_id' => $viewerId,
            'plain_id' => $plainId,
            'repaired_id' => $repairedId,
            'replaced_id' => $replacedId,
            'closed_after_replacement_id' => $closedId,
        ];
    }

    private function seedReplacedDamageCase(int $reportId): void
    {
        DB::table('damage_reports')->insert($this->damageReportRow([
            'report_id' => $reportId,
            'status' => 'replaced',
            'replaced_at' => now(),
            'replaced_by' => $this->seedUser(['role' => 'maintenance_staff']),
            'replacement_item_id' => 1,
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function reportRowsFor(int $viewerId, array $query = [], string $role = 'super_admin'): array
    {
        $url = '/api/reports?' . http_build_query(array_merge(['per_page' => 200], $query));

        $response = $this->actingAsSessionUser($viewerId, $role)->getJson($url);
        $response->assertOk();

        return $response->json('data.reports');
    }

    /** @return array<int, int> */
    private function reportIdsFor(int $viewerId, array $query = [], string $role = 'super_admin'): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['report_id'],
            $this->reportRowsFor($viewerId, $query, $role)
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

        // Mirrors 2026_05_15_000500_create_damage_reports_tables, including the
        // replacement stamps fulfillReplacement() writes. Unlike the other
        // Feature tests' minimal damage_reports table this one carries
        // replaced_at, because the classification depends on it.
        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
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

    private function seedReport(array $overrides = []): int
    {
        $attributes = array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'report_category' => 'general',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => null,
            'assigned_to' => null,
            'department_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $attributes['created_by'] ??= $this->seedUser();

        return DB::table('maintenance_reports')->insertGetId($attributes, 'report_id');
    }

    private function damageReportRow(array $overrides = []): array
    {
        return array_merge([
            'damage_report_code' => 'DR-' . uniqid(),
            'report_id' => null,
            'status' => 'pending',
            'replacement_item_id' => null,
            'replacement_quantity' => null,
            'replaced_by' => null,
            'replaced_at' => null,
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
