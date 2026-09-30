<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\Support\BuildsLegacyRehearsalDataset;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\Support\RehearsesLegacyMigration;
use Tests\TestCase;

/**
 * TASK 68 — the legacy -> unified migration rehearsal, as executable evidence.
 *
 * WHAT IS BEING PROVEN
 * --------------------
 * Not "the migration works". The question is narrower and more useful:
 *
 *     Run against the schema as it exists TODAY, which legacy records survive
 *     intact, which ones cannot, and is the whole thing reversible?
 *
 * The answer this suite pins down is that 9 of the 19 synthetic legacy records
 * transform safely and 10 cannot, because they carry state that needs the
 * `work_stage` / `resolution` columns Task 64 §9.3 specified and nobody has
 * built yet. Those 10 are stopped and recorded, never silently written.
 *
 * A PARTIAL TRANSFORM IS THE SUCCESSFUL OUTCOME HERE. If this suite ever
 * reports 19 transformed against an unchanged schema, something has started
 * swallowing the losses, and that is a regression however green it looks.
 *
 * SAFETY
 * ------
 * Everything runs in a disposable scratch database built from the real
 * migration chain. No connection to the application database is opened at any
 * point — not even to read — which is why the "real database unchanged" proof
 * below is structural rather than a before/after count. Counting the live rows
 * would require opening the very connection the safety model exists to
 * prevent; that check belongs outside the suite, and Task 68 §20 performs it
 * there.
 */
class LegacyMigrationRehearsalTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;
    use BuildsLegacyRehearsalDataset;
    use RehearsesLegacyMigration;

    private const SCRATCH_SUFFIX = 'legacy_migration';

    /** Expected outcome per damage report, stated once so tests cannot drift. */
    private const EXPECTED_DAMAGE_DECISIONS = [
        1  => [self::DECISION_TRANSFORMED,      self::ACTION_RECONCILED, 1,   'submitted'],
        2  => [self::DECISION_TRANSFORMED,      self::ACTION_CREATED,    102, 'assigned'],
        3  => [self::DECISION_TRANSFORMED,      self::ACTION_CREATED,    103, 'assigned'],
        4  => [self::DECISION_PRODUCT_DECISION, self::ACTION_NONE,       null, null],
        5  => [self::DECISION_TRANSFORMED,      self::ACTION_RECONCILED, 2,   'in_progress'],
        6  => [self::DECISION_PRODUCT_DECISION, self::ACTION_NONE,       null, null],
        7  => [self::DECISION_PRODUCT_DECISION, self::ACTION_NONE,       null, null],
        8  => [self::DECISION_PRODUCT_DECISION, self::ACTION_NONE,       null, null],
        9  => [self::DECISION_PRODUCT_DECISION, self::ACTION_NONE,       null, null],
        10 => [self::DECISION_TRANSFORMED,      self::ACTION_CREATED,    110, 'closed'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped('Legacy migration rehearsal skipped. ' . $reason);
        }

        $this->buildCleanRehearsalDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropScratchMySqlDatabase();

        parent::tearDown();
    }

    /**
     * A scratch database, the real migration chain, the Task 67 fixture, and
     * the rehearsal-only target tables — but deliberately NOT the transform.
     *
     * Leaving the transform out of setUp is what lets each test below show the
     * MAP -> TRANSFORM boundary explicitly, and is what makes the rollback test
     * able to start from a genuinely untransformed database.
     */
    private function buildCleanRehearsalDatabase(): void
    {
        $this->useScratchMySqlDatabase(self::SCRATCH_SUFFIX);

        Artisan::call('migrate', ['--force' => true]);

        $this->seedLegacyRehearsalDataset();
        $this->createRehearsalTargetStructures();
    }

    /** MAP then TRANSFORM, in that order. */
    private function runRehearsal(): array
    {
        $map = $this->buildRehearsalMap();
        $this->applyRehearsalTransform($map);

        return $map;
    }

    // -----------------------------------------------------------------------
    // GATE A / B — the ground the rehearsal stands on
    // -----------------------------------------------------------------------

    public function test_the_rehearsal_runs_on_a_migrated_scratch_database_holding_the_task_67_fixture(): void
    {
        $database = DB::connection()->getDatabaseName();

        $this->assertMatchesRegularExpression(
            '/^sfms_test_scratch_/',
            $database,
            'The rehearsal must never run anywhere but a scratch database.'
        );

        foreach (['maintenance_reports', 'damage_reports', 'repair_requests', 'damage_report_histories', 'repair_histories', 'dispatches', 'inventory_transactions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "The migration chain must create {$table}.");
        }

        $this->assertGreaterThan(
            0,
            DB::table('migrations')->count(),
            'The migration ledger must be populated — the schema came from the chain, not from hand-written DDL.'
        );

        $this->assertSame(10, DB::table('damage_reports')->count());
        $this->assertSame(9, DB::table('repair_requests')->count());
    }

    /**
     * The target columns this rehearsal writes must actually exist, and the two
     * Task 64 companion columns must NOT — the entire blocking logic is
     * premised on their absence, so if someone adds them this test is where
     * that shows up first.
     */
    public function test_the_target_schema_is_what_the_mapping_assumes(): void
    {
        foreach (['report_id', 'title', 'description', 'report_category', 'item_id', 'source_dispatch_id', 'location', 'priority', 'status', 'created_by', 'assigned_to', 'department_id', 'completed_date'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('maintenance_reports', $column),
                "maintenance_reports.{$column} is written by the transform and must exist."
            );
        }

        foreach (['work_stage', 'resolution', 'room_id', 'technician_user_id', 'failure_reason', 'repair_notes'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('maintenance_reports', $column),
                "maintenance_reports.{$column} does not exist today. Every REQUIRES_PRODUCT_DECISION "
                . 'verdict in this suite is justified by its absence — if it has been added, the mapping '
                . 'must be revisited, not this assertion relaxed.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // GATE C / D — every source record gets an explicit decision
    // -----------------------------------------------------------------------

    public function test_every_damage_report_and_repair_request_receives_an_explicit_decision(): void
    {
        $this->runRehearsal();

        foreach (DB::table('damage_reports')->orderBy('id')->pluck('id') as $id) {
            $this->assertSame(
                1,
                DB::table('rehearsal_mapping_ledger')->where('source_table', 'damage_reports')->where('source_id', $id)->count(),
                "damage_reports#{$id} must have exactly one mapping decision."
            );
        }

        foreach (DB::table('repair_requests')->orderBy('id')->pluck('id') as $id) {
            $this->assertSame(
                1,
                DB::table('rehearsal_mapping_ledger')->where('source_table', 'repair_requests')->where('source_id', $id)->count(),
                "repair_requests#{$id} must have exactly one mapping decision."
            );
        }

        $this->assertSame(
            [self::DECISION_PRODUCT_DECISION, self::DECISION_TRANSFORMED],
            DB::table('rehearsal_mapping_ledger')->distinct()->orderBy('decision')->pluck('decision')->all(),
            'Only two verdicts are legitimate. Anything else means a record fell through the map.'
        );
    }

    // -----------------------------------------------------------------------
    // PHASE 9 — damage report mapping
    // -----------------------------------------------------------------------

    public function test_damage_reports_map_exactly_as_specified(): void
    {
        $this->runRehearsal();

        foreach (self::EXPECTED_DAMAGE_DECISIONS as $id => [$decision, $action, $targetReportId, $targetStatus]) {
            $row = DB::table('rehearsal_mapping_ledger')
                ->where('source_table', 'damage_reports')->where('source_id', $id)->first();

            $this->assertSame($decision, $row->decision, "damage_reports#{$id} decision");
            $this->assertSame($action, $row->target_action, "damage_reports#{$id} target action");
            $this->assertSame($targetReportId, $row->target_report_id === null ? null : (int) $row->target_report_id, "damage_reports#{$id} target report");
            $this->assertSame($targetStatus, $row->target_status, "damage_reports#{$id} target status");
        }
    }

    /**
     * The three created reports, field by field. This is the actual output of
     * the migration, so it is asserted concretely rather than by count.
     */
    public function test_created_target_reports_carry_the_expected_values(): void
    {
        $this->runRehearsal();

        $created = DB::table('maintenance_reports')
            ->where('report_id', '>=', self::TARGET_REPORT_ID_BASE)
            ->orderBy('report_id')->get();

        $this->assertSame([102, 103, 110], $created->pluck('report_id')->map(fn ($id) => (int) $id)->all());

        $report102 = $created->firstWhere('report_id', 102);
        $this->assertSame('Damage Report: DR-REH-0002', $report102->title);
        $this->assertSame('assigned', $report102->status);
        $this->assertSame('medium', $report102->priority, 'severity_level -> priority is an identity mapping.');
        $this->assertSame('repair_replacement', $report102->report_category);
        $this->assertSame('Rehearsal Hall / REH-101', $report102->location);
        $this->assertSame(self::USER_REPORTER, (int) $report102->created_by, 'reported_by -> created_by.');
        $this->assertSame(1, (int) $report102->department_id);
        $this->assertSame('2026-01-05 08:00:00', $report102->created_at, 'Legacy timestamps are carried, not regenerated.');

        $report110 = $created->firstWhere('report_id', 110);
        $this->assertSame('closed', $report110->status);
        $this->assertSame('2026-01-09', $report110->completed_date, 'completion_date -> completed_date is a genuine field target.');
    }

    /**
     * A pre-existing unified report is never rewritten — Task 64 §10's most
     * important safety property. The rehearsal verifies agreement instead.
     */
    public function test_existing_reports_are_reconciled_and_never_overwritten(): void
    {
        $before = DB::table('maintenance_reports')->where('report_id', '<', self::TARGET_REPORT_ID_BASE)
            ->orderBy('report_id')->get()->toArray();

        $this->runRehearsal();

        $after = DB::table('maintenance_reports')->where('report_id', '<', self::TARGET_REPORT_ID_BASE)
            ->orderBy('report_id')->get()->toArray();

        $this->assertEquals($before, $after, 'The four pre-existing maintenance reports must be byte-identical afterwards.');

        $this->assertSame(
            [],
            $this->reconciliationMismatches(),
            'Where a unified report already exists, the live sync and the migration map must agree about its status.'
        );
    }

    // -----------------------------------------------------------------------
    // PHASE 10 — repair request mapping
    // -----------------------------------------------------------------------

    public function test_repair_requests_follow_their_damage_report(): void
    {
        $this->runRehearsal();

        foreach (self::legacyRepairRequestCases() as $case) {
            $parentExpected = self::EXPECTED_DAMAGE_DECISIONS[$case['damage_report_id']][0];

            $row = DB::table('rehearsal_mapping_ledger')
                ->where('source_table', 'repair_requests')->where('source_id', $case['id'])->first();

            $this->assertSame(
                $parentExpected,
                $row->decision,
                "repair_requests#{$case['id']} must share the fate of damage report {$case['damage_report_id']} — "
                . 'the two collapse into a single unified row, so one cannot be migrated without the other.'
            );

            $this->assertSame(
                $parentExpected === self::DECISION_TRANSFORMED ? self::ACTION_MERGED : self::ACTION_NONE,
                $row->target_action,
                "repair_requests#{$case['id']} never gets its own target row."
            );
        }
    }

    /**
     * The two fields whose loss has consequences beyond information: without
     * them the replacement workflow has no precondition and no explanation.
     */
    public function test_failure_reason_and_technician_have_no_target_and_are_recorded_as_lost(): void
    {
        $this->runRehearsal();

        $failed = DB::table('rehearsal_mapping_ledger')
            ->where('source_table', 'repair_requests')->where('source_id', 7)->first();

        $this->assertStringContainsString('failure_reason', $failed->lost_fields);
        $this->assertStringContainsString('technician_user_id', $failed->lost_fields);
        $this->assertSame('failed', $failed->required_work_stage);
        $this->assertStringContainsString('RepairService.php:386', $failed->reason);

        $this->assertSame(
            'LOST',
            self::repairRequestFieldMap()['failure_reason']['result'],
            'failure_reason has no column in the current schema and must be declared lost, not quietly dropped.'
        );
    }

    // -----------------------------------------------------------------------
    // PHASE 6 — status mapping
    // -----------------------------------------------------------------------

    /**
     * Every one of the eight repair states, classified. This is the table the
     * product decision will be made from, so it is executable rather than prose.
     */
    public function test_every_repair_status_is_classified_as_safe_or_blocked(): void
    {
        $expected = [
            'pending'       => null,
            'assigned'      => null,
            'diagnosing'    => 'diagnosing',
            'repairing'     => null,
            'waiting_parts' => 'waiting_parts',
            'completed'     => null,
            'failed'        => 'failed',
            'archived'      => null,
        ];

        foreach ($expected as $repairStatus => $requiredWorkStage) {
            $this->assertSame(
                $requiredWorkStage,
                self::requiredWorkStage($repairStatus),
                "repair_status '{$repairStatus}' work_stage requirement"
            );
        }

        $this->assertSame(
            ['diagnosing', 'failed', 'waiting_parts'],
            collect($expected)->filter()->values()->sort()->values()->all(),
            'Exactly three repair states are inexpressible today.'
        );
    }

    public function test_every_damage_status_maps_and_two_of_them_need_a_resolution_column(): void
    {
        $expected = [
            'pending'      => ['submitted',   null],
            'under_review' => ['assigned',    null],
            'repairing'    => ['in_progress', null],
            'repaired'     => ['completed',   'repaired'],
            'replaced'     => ['completed',   'replaced'],
            'closed'       => ['closed',      null],
        ];

        foreach ($expected as $damageStatus => [$targetStatus, $resolution]) {
            $this->assertSame($targetStatus, self::damageStatusToReportStatus($damageStatus), "damage '{$damageStatus}' -> status");
            $this->assertSame($resolution, self::requiredResolution($damageStatus), "damage '{$damageStatus}' -> resolution");
        }

        $this->assertSame(
            self::damageStatusToReportStatus('repaired'),
            self::damageStatusToReportStatus('replaced'),
            'The collapse itself: a repaired asset and a replaced asset become the same value, and '
            . 'without a resolution column nothing downstream can tell them apart again.'
        );
    }

    /**
     * The mapping this rehearsal uses must be the one production actually runs,
     * not a restatement that has drifted from it.
     */
    public function test_the_status_maps_match_the_live_services(): void
    {
        $this->assertSame('under_review', self::repairStatusToDamageStatus('failed', 'under_review'));
        $this->assertSame('repairing', self::repairStatusToDamageStatus('waiting_parts', 'repairing'));
        $this->assertSame('repaired', self::repairStatusToDamageStatus('completed', 'repairing'));

        // The one genuinely ambiguous branch in RepairService: `archived`
        // resolves against the damage report's current status.
        $this->assertSame('replaced', self::repairStatusToDamageStatus('archived', 'replaced'));
        $this->assertSame('closed', self::repairStatusToDamageStatus('archived', 'repairing'));

        foreach (self::legacyRepairRequestCases() as $case) {
            $damageStatus = DB::table('damage_reports')->where('id', $case['damage_report_id'])->value('status');

            $this->assertSame(
                $case['damage_status'],
                $damageStatus,
                "The fixture's recorded pairing for {$case['code']} must match the real rows."
            );
        }
    }

    // -----------------------------------------------------------------------
    // PHASE 7 — assignment mapping
    // -----------------------------------------------------------------------

    /**
     * The security-relevant one. Task 64 §11: the three assignment fields have
     * strictly ordered authorization constraints, so folding a technician into
     * assigned_to would widen a security boundary.
     */
    public function test_the_technician_is_never_written_into_assigned_to(): void
    {
        $this->runRehearsal();

        $created = DB::table('maintenance_reports')->where('report_id', '>=', self::TARGET_REPORT_ID_BASE)->get();

        foreach ($created as $report) {
            $this->assertNull(
                $report->assigned_to,
                "Report {$report->report_id} must not inherit a technician. assigned_to accepts ANY user; "
                . 'technician_user_id is restricted to active maintenance staff/admins. Copying one into the '
                . 'other silently promotes a narrower permission into a wider field.'
            );
        }

        // The technicians genuinely exist on the source rows — this is a real
        // loss being refused, not an absence of data.
        $this->assertSame(
            [self::USER_TECHNICIAN_A, self::USER_TECHNICIAN_B],
            DB::table('repair_requests')->whereNotNull('technician_user_id')
                ->distinct()->orderBy('technician_user_id')->pluck('technician_user_id')
                ->map(fn ($id) => (int) $id)->all()
        );

        $this->assertSame(
            'LOST',
            self::repairRequestFieldMap()['technician_user_id']['result'],
            'The technician has no target column and the migration must say so.'
        );
    }

    public function test_the_three_assignment_fields_stay_independent(): void
    {
        $this->runRehearsal();

        // dispatches.release_assigned_to is a Dispatch concern and stays there.
        $this->assertSame(
            self::USER_TECHNICIAN_A,
            (int) DB::table('dispatches')->where('id', 2)->value('release_assigned_to'),
            'The releaser is untouched by the migration.'
        );

        // Report 4's assignee and the repair technician on the same chain are
        // different people — proof the fields are not interchangeable.
        $this->assertSame(self::USER_TECHNICIAN_B, (int) DB::table('maintenance_reports')->where('report_id', 4)->value('assigned_to'));
        $this->assertSame(self::USER_TECHNICIAN_A, (int) DB::table('repair_requests')->where('id', 8)->value('technician_user_id'));
    }

    // -----------------------------------------------------------------------
    // PHASE 8 — room / location mapping
    // -----------------------------------------------------------------------

    public function test_structured_room_becomes_unstructured_text_and_the_loss_is_recorded(): void
    {
        $this->runRehearsal();

        $this->assertFalse(
            Schema::hasColumn('maintenance_reports', 'room_id'),
            'The gap itself: there is no structured destination for a room.'
        );

        $this->assertSame(
            'Rehearsal Hall / REH-101',
            DB::table('maintenance_reports')->where('report_id', 102)->value('location'),
            'room_id 1 is rendered to free text — readable, but no longer joinable or filterable.'
        );

        // A NULL room stays NULL. Inventing a placeholder would be worse than
        // admitting the absence.
        $this->assertNull(DB::table('damage_reports')->where('id', 10)->value('room_id'));
        $this->assertNull(DB::table('maintenance_reports')->where('report_id', 110)->value('location'));

        $this->assertSame(
            'PARTIAL',
            self::damageReportFieldMap()['room_id']['result'],
            'Neither PRESERVED nor LOST: the human-readable part survives, the machine-readable part does not.'
        );

        foreach ([2, 3] as $damageReportId) {
            $this->assertStringContainsString(
                'room_id',
                DB::table('rehearsal_mapping_ledger')->where('source_table', 'damage_reports')
                    ->where('source_id', $damageReportId)->value('lost_fields'),
                'Every transformed report with a room must record the structural loss.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // PHASE 11 — history mapping
    // -----------------------------------------------------------------------

    public function test_history_is_carried_only_for_records_that_were_transformed(): void
    {
        $this->runRehearsal();

        $carried = DB::table('rehearsal_target_report_histories')->orderBy('source_id')->get();

        // DR 1 has one event, DR 10 has two. Every other history row in the
        // fixture belongs to a blocked record.
        $this->assertSame(3, $carried->count());
        $this->assertSame([1, 10, 11], $carried->pluck('source_id')->map(fn ($id) => (int) $id)->all());

        foreach ($carried as $event) {
            $this->assertSame(
                'damage_report_histories',
                $event->source_table,
                'No repair history is carried at all: every repair request that HAS history '
                . '(6, 7, 8) is blocked by a product decision.'
            );

            $this->assertNotNull(
                DB::table('maintenance_reports')->where('report_id', $event->report_id)->first(),
                'Every carried event must land on a report that exists.'
            );
        }

        // Statuses are rewritten into the target vocabulary.
        $closed = $carried->firstWhere('source_id', 11);
        $this->assertSame('submitted', $closed->from_status, "'pending' becomes 'submitted'.");
        $this->assertSame('closed', $closed->to_status);
    }

    /**
     * The finding that matters more than the count: the history merge is
     * UNREHEARSED, because no transformable report has trails in both tables.
     */
    public function test_no_transformed_report_exercises_the_two_table_history_merge(): void
    {
        $this->runRehearsal();

        $perReport = DB::table('rehearsal_target_report_histories')
            ->select('report_id', DB::raw('COUNT(DISTINCT source_table) as trails'))
            ->groupBy('report_id')->get();

        foreach ($perReport as $row) {
            $this->assertSame(
                1,
                (int) $row->trails,
                'If this ever becomes 2, the interleaving of damage and repair trails is finally being '
                . 'exercised and the ordering guarantees below become load-bearing.'
            );
        }

        $this->assertGreaterThan(
            0,
            DB::table('damage_report_histories')->count() + DB::table('repair_histories')->count()
                - DB::table('rehearsal_target_report_histories')->count(),
            'History belonging to blocked records is left behind — 17 of the 20 events in this dataset.'
        );
    }

    /**
     * Second-granularity timestamps are not a sufficient sort key, and this
     * dataset proves it rather than assuming it.
     */
    public function test_same_second_history_events_exist_so_timestamp_ordering_is_insufficient(): void
    {
        $collisions = $this->sameSecondHistoryCollisions();

        $this->assertNotEmpty(
            $collisions,
            'A unified trail that ORDER BYs created_at alone has no defined order for these events. '
            . 'The merge needs a deterministic tiebreaker (source table + source id).'
        );

        $this->assertStringContainsString('repair_histories', implode(' ', $collisions));
    }

    // -----------------------------------------------------------------------
    // PHASE 12 — replacement mapping
    // -----------------------------------------------------------------------

    public function test_replacement_chains_are_traced_without_being_executed(): void
    {
        $this->runRehearsal();

        $trace = DB::table('rehearsal_replacement_trace')->orderBy('damage_report_id')->get();

        $this->assertSame(2, $trace->count(), 'Both replacement scenarios in the fixture are traced.');

        $approved = $trace->firstWhere('damage_report_id', 8);
        $this->assertSame(7, (int) $approved->repair_request_id);
        $this->assertSame(1, (int) $approved->dispatch_id);
        $this->assertSame('approved', $approved->dispatch_status, 'Approved but never released.');
        $this->assertNull($approved->inventory_transaction_id);

        $released = $trace->firstWhere('damage_report_id', 9);
        $this->assertSame('released', $released->dispatch_status);
        $this->assertSame(1, (int) $released->inventory_transaction_id);
        $this->assertSame(1, (int) $released->destination_room_id);

        // Both chains hang off blocked records — a finding, not an accident.
        foreach ($trace as $row) {
            $this->assertSame(self::DECISION_PRODUCT_DECISION, $row->decision);
            $this->assertNull($row->target_report_id, 'Neither replacement chain can be migrated today.');
        }
    }

    /**
     * GATE L. The strongest form of this claim is structural: the transform
     * never calls Eloquent, so InventoryTransactionObserver — the single writer
     * of items.quantity — cannot run. The assertion confirms the consequence.
     */
    public function test_the_rehearsal_performs_no_inventory_mutation(): void
    {
        $this->runRehearsal();

        $item = DB::table('items')->where('id', self::REPLACEMENT_ITEM_ID)->first();

        $this->assertSame(self::REPLACEMENT_ITEM_SEEDED_QUANTITY, (int) $item->quantity);
        $this->assertSame(self::REPLACEMENT_ITEM_SEEDED_RESERVED, (int) $item->reserved_quantity);
        $this->assertSame('available', $item->status);

        $this->assertSame(
            self::DAMAGED_ITEM_SEEDED_QUANTITY,
            (int) DB::table('items')->where('id', self::DAMAGED_ITEM_ID)->value('quantity')
        );

        $this->assertSame(1, DB::table('inventory_transactions')->count(), 'No new ledger row was created.');
        $this->assertSame(2, DB::table('dispatches')->count());
        $this->assertSame(
            'approved',
            DB::table('dispatches')->where('id', 1)->value('status'),
            'The un-released dispatch stays un-released. The rehearsal never releases anything.'
        );
    }

    // -----------------------------------------------------------------------
    // PHASE 14 / 15 — conservation and silent-loss
    // -----------------------------------------------------------------------

    public function test_every_source_record_is_conserved(): void
    {
        $this->runRehearsal();

        $this->assertSame([
            'source_records'    => 19,
            'source_damage'     => 10,
            'source_repair'     => 9,
            'mapped_records'    => 19,
            'transformed'       => 9,
            'product_decision'  => 10,
            'target_created'    => 3,
            'target_reconciled' => 2,
            'target_merged'     => 4,
            'unmapped'          => 0,
            'failed'            => 0,
        ], $this->rehearsalConservation());
    }

    /**
     * GATE E. "Nothing lost" would be false. The honest property is that
     * nothing is lost SILENTLY: every source record is accounted for, and every
     * record that drops a populated field says which fields.
     */
    public function test_no_loss_is_silent(): void
    {
        $this->runRehearsal();

        $this->assertSame(
            0,
            $this->rehearsalConservation()['unmapped'],
            'A source record with no ledger row is the definition of a silent loss.'
        );

        foreach (DB::table('rehearsal_mapping_ledger')->orderBy('id')->get() as $row) {
            $this->assertNotSame('', trim($row->reason), "{$row->source_table}#{$row->source_id} must carry a reason.");

            if ($row->decision === self::DECISION_PRODUCT_DECISION) {
                $this->assertTrue(
                    $row->required_work_stage !== null || $row->required_resolution !== null,
                    "{$row->source_table}#{$row->source_id} is blocked, so it must name the column it needs."
                );
            }
        }

        // Even the records that DO transform lose populated fields. Reporting
        // them as clean successes would be the subtlest possible lie.
        $lossyButTransformed = DB::table('rehearsal_mapping_ledger')
            ->where('decision', self::DECISION_TRANSFORMED)
            ->where('lost_fields', '<>', '')->count();

        $this->assertSame(
            9,
            $lossyButTransformed,
            'All nine transformed records still drop at least one populated field. '
            . 'TRANSFORMED means the STATUS survives, not that the record does.'
        );
    }

    // -----------------------------------------------------------------------
    // GATE F — duplicates
    // -----------------------------------------------------------------------

    public function test_a_second_transform_cannot_silently_duplicate_the_target(): void
    {
        $map = $this->runRehearsal();

        $this->assertSame(19, DB::table('rehearsal_mapping_ledger')->count());

        // Re-running is NOT idempotent — it is duplicate-PROTECTED, which is a
        // different and weaker property. The unique key turns a silent double
        // migration into a loud failure. Making it genuinely re-runnable is
        // Task 69 work; this test pins the current behaviour honestly.
        $threw = false;
        try {
            $this->applyRehearsalTransform($map);
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'A second transform must fail loudly on the unique key rather than writing a second '
            . 'set of target rows.'
        );

        $this->assertSame(
            3,
            DB::table('maintenance_reports')->where('report_id', '>=', self::TARGET_REPORT_ID_BASE)->count(),
            'And it must not have created duplicate reports before failing.'
        );
    }

    // -----------------------------------------------------------------------
    // GATE M — rollback
    // -----------------------------------------------------------------------

    /**
     * PHASE 16. A controlled failure AFTER target rows exist, then a rollback,
     * then proof that the database is exactly as it was.
     */
    public function test_rollback_after_a_controlled_failure_leaves_no_trace(): void
    {
        $sourceDigestBefore = $this->legacySourceDigest();
        $reportsBefore      = DB::table('maintenance_reports')->count();

        $this->assertSame(0, DB::table('rehearsal_mapping_ledger')->count());

        $map = $this->buildRehearsalMap();

        $failed = false;
        DB::beginTransaction();
        try {
            $this->applyRehearsalTransform($map);

            // The transform really did happen — otherwise the rollback below
            // would be proving nothing.
            $this->assertSame(19, DB::table('rehearsal_mapping_ledger')->count());
            $this->assertSame($reportsBefore + 3, DB::table('maintenance_reports')->count());

            throw new \RuntimeException('Controlled rehearsal failure, triggered deliberately after the target rows existed.');
        } catch (\RuntimeException $e) {
            DB::rollBack();
            $failed = true;
        }

        $this->assertTrue($failed);

        // Target rows are gone.
        $this->assertSame(0, DB::table('rehearsal_mapping_ledger')->count());
        $this->assertSame(0, DB::table('rehearsal_target_report_histories')->count());
        $this->assertSame(0, DB::table('rehearsal_replacement_trace')->count());
        $this->assertSame(
            0,
            DB::table('maintenance_reports')->where('report_id', '>=', self::TARGET_REPORT_ID_BASE)->count(),
            'The three created reports must be gone.'
        );
        $this->assertSame($reportsBefore, DB::table('maintenance_reports')->count());

        // Sources are byte-identical: no legacy row, history row, dispatch,
        // inventory transaction, item or notification was disturbed.
        $this->assertSame(
            $sourceDigestBefore,
            $this->legacySourceDigest(),
            'Every legacy table must be exactly as it was before the failed transform.'
        );

        // And the database is still usable afterwards — a rollback that left
        // broken constraints would show up here.
        $this->applyRehearsalTransform($this->buildRehearsalMap());
        $this->assertSame(19, DB::table('rehearsal_mapping_ledger')->count());
    }

    // -----------------------------------------------------------------------
    // GATE N — repeatability
    // -----------------------------------------------------------------------

    /**
     * PHASE 17. Two clean scratch databases, two full rehearsals, one digest.
     */
    public function test_the_rehearsal_is_repeatable_against_a_clean_database(): void
    {
        $this->runRehearsal();
        $firstDigest       = $this->rehearsalDigest();
        $firstConservation = $this->rehearsalConservation();
        $firstSource       = $this->legacySourceDigest();

        // Genuinely clean: the scratch database is dropped and rebuilt from the
        // migration chain, not truncated.
        $this->buildCleanRehearsalDatabase();

        $this->assertSame(0, DB::table('rehearsal_mapping_ledger')->count(), 'The second database starts empty.');

        $this->runRehearsal();

        $this->assertSame($firstDigest, $this->rehearsalDigest(), 'Ledger, target reports, history and replacement trace must all be identical.');
        $this->assertSame($firstConservation, $this->rehearsalConservation());
        $this->assertSame($firstSource, $this->legacySourceDigest(), 'Even the fixture must rebuild byte-identically.');
    }

    // -----------------------------------------------------------------------
    // GATE O — isolation and cleanup
    // -----------------------------------------------------------------------

    /**
     * PHASE 18. Proof by construction: the rehearsal cannot reach the
     * application database, because it is never connected to it.
     */
    public function test_the_rehearsal_is_structurally_unable_to_touch_the_application_database(): void
    {
        $this->runRehearsal();

        $current = DB::connection()->getDatabaseName();
        $real    = Config::get('database.connections.mysql.database');

        $this->assertSame('sfms_test_scratch_' . self::SCRATCH_SUFFIX, $current);
        $this->assertNotSame($real, $current, 'The rehearsal connection is not the application connection.');

        $this->assertStringStartsWith(
            'sfms_test_scratch_',
            $current,
            'Every write in this suite lands in a database whose name marks it disposable — including '
            . 'the writes to maintenance_reports, which is a freshly migrated empty copy, not the live table.'
        );
    }

    /**
     * The scratch database is genuinely removable — verified against the
     * server, not assumed from tearDown.
     */
    public function test_the_scratch_database_is_actually_dropped(): void
    {
        $name = DB::connection()->getDatabaseName();

        $this->assertTrue($this->databaseExistsOnServer($name));

        $this->dropScratchMySqlDatabase();

        $this->assertFalse(
            $this->databaseExistsOnServer($name),
            'A scratch database that survives the test is a leak: the next run inherits stale rows.'
        );

        // Rebuild so tearDown has something valid to clean up.
        $this->buildCleanRehearsalDatabase();
    }

    private function databaseExistsOnServer(string $database): bool
    {
        $config = Config::get('database.connections.mysql');

        $pdo = new PDO(
            'mysql:host=' . ($config['host'] ?? '127.0.0.1') . ';port=' . ($config['port'] ?? 3306),
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $statement = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$database]);

        return $statement->fetch() !== false;
    }
}
