<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsLegacyRehearsalDataset;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\TestCase;

/**
 * TASK 67 — proof that the legacy rehearsal dataset is real, complete and inert.
 *
 * WHAT THIS TEST IS FOR
 * ---------------------
 * The next task rehearses the Damage Report / Repair Request -> Unified
 * Maintenance Report migration. A rehearsal is only worth running if the data
 * it consumes is (a) accepted by the REAL database engine, not just by SQLite,
 * (b) identical on every run, and (c) incapable of touching anything outside
 * its own sandbox. This test establishes all three before the rehearsal is
 * written, so that a failure during the rehearsal can be attributed to the
 * migration logic rather than to the fixture.
 *
 * WHY IT RUNS AGAINST REAL MySQL/MariaDB
 * --------------------------------------
 * The rest of the Feature suite runs on in-memory SQLite, where an ENUM is just
 * a string and an out-of-range value stores happily. Every status assertion
 * below would therefore be vacuous on SQLite: it would pass whether or not the
 * value was legal. The dataset has to be proven against the engine production
 * actually uses, under the strict sql_mode Laravel's own connection sets — see
 * assertStrictModeIsActive(), without which a "successful" insert proves
 * nothing at all (the same trap documented in
 * LegacyNonStrictSqlModeAsymmetryTest).
 *
 * SAFETY
 * ------
 * Everything happens in a disposable scratch database whose name must match
 * /^sfms_test_scratch_[a-z0-9_]+$/, a pattern the real application database
 * cannot satisfy, asserted before every CREATE and DROP by
 * ConnectsToScratchMySqlDatabase. The real database is never opened, read,
 * named or migrated, and the scratch database is dropped in tearDown.
 *
 * It SKIPS rather than fails when MySQL is unreachable, so a developer without
 * a server running still gets a green suite.
 */
class LegacyRehearsalFixtureTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;
    use BuildsLegacyRehearsalDataset;

    /**
     * The full legacy vocabulary, from the live schema and RepairService.
     * The dataset must cover every one of these — partial coverage would let
     * the rehearsal miss precisely the states that are hardest to map.
     */
    private const ALL_DAMAGE_STATUSES = ['pending', 'under_review', 'repairing', 'repaired', 'replaced', 'closed'];

    private const ALL_REPAIR_STATUSES = ['pending', 'assigned', 'diagnosing', 'repairing', 'waiting_parts', 'completed', 'failed', 'archived'];

    protected function setUp(): void
    {
        parent::setUp();

        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped('Legacy rehearsal fixture check skipped. ' . $reason);
        }

        $this->useScratchMySqlDatabase('legacy_rehearsal');

        // GATE A — build the schema from the repository's real migration chain,
        // not from a hand-written helper. If the chain cannot produce a usable
        // database, no rehearsal built on it would mean anything.
        Artisan::call('migrate', ['--force' => true]);

        $this->seedLegacyRehearsalDataset();
    }

    protected function tearDown(): void
    {
        $this->dropScratchMySqlDatabase();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // GATE A/B/C — the dataset exists, in the shape it claims
    // -----------------------------------------------------------------------

    /**
     * GATE A — the fixture was built on a migrated scratch database, and not
     * anywhere else.
     */
    public function test_dataset_is_built_on_a_migrated_scratch_database(): void
    {
        $this->assertSame(
            'sfms_test_scratch_legacy_rehearsal',
            DB::connection()->getDatabaseName(),
            'Sanity: the fixture must have been seeded into the scratch database, never the real one.'
        );

        $this->assertGreaterThan(
            0,
            DB::table('migrations')->count(),
            'The schema must have come from the migration chain.'
        );
    }

    /**
     * GATE B + C — the exact row counts. These are the numbers the rehearsal
     * will assert it processed, so they are pinned here first.
     */
    public function test_dataset_has_the_expected_counts(): void
    {
        $expected = [
            'departments'             => 1,
            'users'                   => 4,
            'buildings'               => 1,
            'floors'                  => 1,
            'rooms'                   => 2,
            'items'                   => 2,
            'maintenance_reports'     => 4,
            'damage_reports'          => 10,
            'repair_requests'         => 9,
            'dispatches'              => 2,
            'dispatch_items'          => 2,
            'inventory_transactions'  => 1,
            'damage_report_histories' => 11,
            'repair_histories'        => 9,
            'notifications'           => 3,
        ];

        foreach ($expected as $table => $count) {
            $this->assertSame(
                $count,
                DB::table($table)->count(),
                "{$table} must contain exactly {$count} rows — the rehearsal asserts on these totals."
            );
        }
    }

    /**
     * The dataset is deterministic: the same literals every time, so a
     * rehearsal can assert on primary keys and two runs can be diffed.
     */
    public function test_identifiers_and_codes_are_deterministic(): void
    {
        $this->assertSame(
            range(1, 10),
            array_map('intval', DB::table('damage_reports')->orderBy('id')->pluck('id')->all()),
            'Damage report ids must be the literal 1..10, not auto-assigned.'
        );

        $this->assertSame(
            ['DR-REH-0001', 'DR-REH-0002', 'DR-REH-0003', 'DR-REH-0004', 'DR-REH-0005',
             'DR-REH-0006', 'DR-REH-0007', 'DR-REH-0008', 'DR-REH-0009', 'DR-REH-0010'],
            DB::table('damage_reports')->orderBy('id')->pluck('damage_report_code')->all(),
            'Codes must be fixed literals — no faker, no sequence, no timestamp.'
        );

        $this->assertSame(
            range(1, 9),
            array_map('intval', DB::table('repair_requests')->orderBy('id')->pluck('id')->all())
        );

        // Synthetic, and visibly so. No production string may leak in.
        foreach (DB::table('users')->pluck('email') as $email) {
            $this->assertStringEndsWith(
                '@example.invalid',
                (string) $email,
                'Fixture users must use the reserved .invalid TLD — never a routable address.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // GATE E — every status is accepted by the real engine, under strict mode
    // -----------------------------------------------------------------------

    /**
     * GATE E — all six damage statuses round-trip intact.
     *
     * Reading the value back is the point. Under a non-strict session
     * MySQL/MariaDB stores '' instead of rejecting an out-of-range ENUM, so an
     * insert that merely "did not throw" would prove nothing; the value has to
     * come back byte-identical.
     */
    public function test_every_damage_status_is_valid_in_the_real_engine(): void
    {
        $this->assertStrictModeIsActive();

        $stored = DB::table('damage_reports')->orderBy('id')->pluck('status', 'id')->all();

        foreach (self::legacyDamageReportCases() as $case) {
            $this->assertSame(
                $case['status'],
                $stored[$case['id']],
                "damage_reports.status '{$case['status']}' must round-trip intact. A silent "
                . "coercion to '' here would hand the rehearsal a value no branch can match."
            );
        }

        $this->assertSame(
            self::ALL_DAMAGE_STATUSES,
            array_values(array_intersect(self::ALL_DAMAGE_STATUSES, array_unique(array_values($stored)))),
            'The dataset must exercise all six damage statuses. A gap here is a state the '
            . 'rehearsal would never see and the migration would never be tested against.'
        );
    }

    /**
     * GATE E — all eight repair statuses round-trip intact.
     *
     * Eight statuses require eight repair requests because
     * repair_requests.damage_report_id is UNIQUE — one repair request per
     * damage report. That constraint is asserted below rather than merely
     * assumed, because it is the single fact that determines the size and shape
     * of this dataset.
     */
    public function test_every_repair_status_is_valid_in_the_real_engine(): void
    {
        $this->assertStrictModeIsActive();

        $stored = DB::table('repair_requests')->orderBy('id')->pluck('repair_status', 'id')->all();

        foreach (self::legacyRepairRequestCases() as $case) {
            $this->assertSame(
                $case['repair_status'],
                $stored[$case['id']],
                "repair_requests.repair_status '{$case['repair_status']}' must round-trip intact."
            );
        }

        $this->assertSame(
            self::ALL_REPAIR_STATUSES,
            array_values(array_intersect(self::ALL_REPAIR_STATUSES, array_unique(array_values($stored)))),
            'The dataset must exercise all eight repair statuses, including the ones Task 63 '
            . 'flagged as unmappable (waiting_parts, failed, archived).'
        );
    }

    /**
     * The UNIQUE constraint that shapes the whole dataset, proven rather than
     * assumed: a second repair request for an existing damage report is
     * rejected by the engine.
     */
    public function test_a_damage_report_can_hold_only_one_repair_request(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('repair_requests')->insert([
            'repair_code'        => 'RR-REH-DUPLICATE',
            'damage_report_id'   => 2, // already owned by RR-REH-0001
            'repair_description' => 'Second repair request for the same damage report.',
            'repair_status'      => 'pending',
        ]);
    }

    // -----------------------------------------------------------------------
    // GATE D — relationships resolve
    // -----------------------------------------------------------------------

    /**
     * GATE D — every foreign key in the dataset points at a row that exists.
     *
     * The engine enforces this on INSERT, so a passing seed already implies it.
     * Asserting it anyway is what makes the guarantee survive a future edit
     * that adds a nullable column and forgets to populate it: a NULL FK
     * silently satisfies the engine but would break the rehearsal.
     */
    public function test_every_legacy_relationship_resolves(): void
    {
        $orphanedDamageReports = DB::table('damage_reports as dr')
            ->leftJoin('items as i', 'i.id', '=', 'dr.item_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'dr.reported_by')
            ->leftJoin('departments as d', 'd.department_id', '=', 'dr.department_id')
            ->whereNull('i.id')
            ->orWhereNull('u.user_id')
            ->orWhereNull('d.department_id')
            ->count();

        $this->assertSame(0, $orphanedDamageReports, 'Every damage report must resolve its item, reporter and department.');

        $orphanedRepairRequests = DB::table('repair_requests as rr')
            ->leftJoin('damage_reports as dr', 'dr.id', '=', 'rr.damage_report_id')
            ->whereNull('dr.id')
            ->count();

        $this->assertSame(0, $orphanedRepairRequests, 'Every repair request must resolve its damage report.');

        // Rooms are nullable by design — DR 10 has none, and that is the point
        // of DR 10. Every OTHER damage report must resolve one.
        $unresolvedRooms = DB::table('damage_reports as dr')
            ->leftJoin('rooms as r', 'r.id', '=', 'dr.room_id')
            ->whereNotNull('dr.room_id')
            ->whereNull('r.id')
            ->count();

        $this->assertSame(0, $unresolvedRooms, 'A non-null room_id must point at a real room.');

        $this->assertSame(
            1,
            DB::table('damage_reports')->whereNull('room_id')->count(),
            'Exactly one damage report must carry a NULL room_id — the Phase 16 location edge case.'
        );
    }

    /**
     * Some legacy rows are already linked to a unified maintenance report and
     * some are not. Both halves must be present, because the future migration
     * has to CREATE reports for the unlinked ones and REUSE them for the rest —
     * two different code paths.
     */
    public function test_dataset_contains_both_linked_and_unlinked_legacy_rows(): void
    {
        $linked   = DB::table('damage_reports')->whereNotNull('report_id')->count();
        $unlinked = DB::table('damage_reports')->whereNull('report_id')->count();

        $this->assertSame(4, $linked, 'Four damage reports must already carry a maintenance report link.');
        $this->assertSame(6, $unlinked, 'Six must not — those are the rows the migration has to create reports for.');

        $danglingLinks = DB::table('damage_reports as dr')
            ->leftJoin('maintenance_reports as mr', 'mr.report_id', '=', 'dr.report_id')
            ->whereNotNull('dr.report_id')
            ->whereNull('mr.report_id')
            ->count();

        $this->assertSame(0, $danglingLinks, 'A non-null report_id must resolve to a real maintenance report.');
    }

    // -----------------------------------------------------------------------
    // GATE F — assignment
    // -----------------------------------------------------------------------

    /**
     * GATE F — the three INDEPENDENT assignment fields are all represented,
     * pointing at DIFFERENT users on the same piece of work.
     *
     * This is the evidence Task 67 Phase 15 exists to produce. Damage report 9
     * is one logical job, and today it carries three separate answers to "who
     * is responsible":
     *
     *     maintenance_reports.assigned_to      -> technician B
     *     repair_requests.technician_user_id   -> technician A
     *     dispatches.release_assigned_to       -> technician A
     *
     * Nothing reconciles them. The values are deliberately not all equal, so
     * that a future migration which collapses these three columns into one
     * cannot pass this test by accident — it has to make a real decision about
     * which one wins, and about what happens to the other two.
     */
    public function test_the_three_independent_assignment_fields_are_all_represented(): void
    {
        $reportAssignee = DB::table('maintenance_reports')->where('report_id', 4)->value('assigned_to');
        $technician     = DB::table('repair_requests')->where('id', 8)->value('technician_user_id');
        $releaseHandler = DB::table('dispatches')->where('id', 2)->value('release_assigned_to');

        $this->assertSame(self::USER_TECHNICIAN_B, (int) $reportAssignee);
        $this->assertSame(self::USER_TECHNICIAN_A, (int) $technician);
        $this->assertSame(self::USER_TECHNICIAN_A, (int) $releaseHandler);

        $this->assertNotSame(
            (int) $reportAssignee,
            (int) $technician,
            'These two fields describe the same job and disagree. That disagreement is the '
            . 'finding — a migration that merges them silently would destroy one of the answers.'
        );

        // Unassigned work exists too: a merge must cope with NULL on both sides.
        $this->assertSame(
            2,
            DB::table('repair_requests')->whereNull('technician_user_id')->count(),
            'Two repair requests must be unassigned — the migration cannot assume a technician exists.'
        );

        $this->assertSame(
            1,
            DB::table('maintenance_reports')->whereNull('assigned_to')->count(),
            'One maintenance report must be unassigned.'
        );
    }

    // -----------------------------------------------------------------------
    // GATE G — replacement, with no inventory mutation
    // -----------------------------------------------------------------------

    /**
     * GATE G — the replacement scenario is fully represented on both sides of
     * the release boundary.
     *
     * Approval and release are different acts: approval is a decision, release
     * is what moves stock. The dataset holds one of each so the rehearsal can
     * tell them apart.
     */
    public function test_replacement_scenarios_are_represented(): void
    {
        $approved = DB::table('dispatches')->where('id', 1)->first();
        $released = DB::table('dispatches')->where('id', 2)->first();

        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->released_by, 'The approved dispatch must not have been released.');
        $this->assertSame('released', $released->status);

        $failedRepair = DB::table('repair_requests')->where('id', 7)->first();
        $this->assertSame('failed', $failedRepair->repair_status);
        $this->assertSame(self::REPLACEMENT_ITEM_ID, (int) $failedRepair->replacement_item_id);
        $this->assertSame(1, (int) $failedRepair->replacement_dispatch_id);
        $this->assertNotNull($failedRepair->failure_reason);

        $replacedDamage = DB::table('damage_reports')->where('id', 9)->first();
        $this->assertSame('replaced', $replacedDamage->status);
        $this->assertSame(self::REPLACEMENT_ITEM_ID, (int) $replacedDamage->replacement_item_id);
        $this->assertSame(1, (int) $replacedDamage->replacement_transaction_id);
        $this->assertNotNull($replacedDamage->replaced_at);
    }

    /**
     * GATE G — the fixture moved no stock.
     *
     * items.quantity is written by exactly one thing in this codebase,
     * InventoryTransactionObserver, and an observer only fires for Eloquent
     * writes. The builder uses DB::table() throughout, so the observer is
     * structurally unreachable — this test asserts that the structure held.
     *
     * The inventory_transactions row is a LEDGER ENTRY describing a deployment
     * in the fixture's fictional past, not a mutation performed now. Its
     * existence alongside an unchanged quantity is the proof: if seeding had
     * gone through a model, the two would have moved together.
     */
    public function test_seeding_the_fixture_performed_no_inventory_mutation(): void
    {
        $replacement = DB::table('items')->where('id', self::REPLACEMENT_ITEM_ID)->first();

        $this->assertSame(
            self::REPLACEMENT_ITEM_SEEDED_QUANTITY,
            (int) $replacement->quantity,
            'The replacement item must still hold its seeded quantity. Any change means an '
            . 'Eloquent write reached InventoryTransactionObserver and the fixture is no longer inert.'
        );

        $this->assertSame(
            self::REPLACEMENT_ITEM_SEEDED_RESERVED,
            (int) $replacement->reserved_quantity,
            'No reservation may have been created either.'
        );

        $this->assertSame(
            'available',
            $replacement->status,
            'The replacement item status must be untouched — the observer also writes this column.'
        );

        $this->assertSame(
            self::DAMAGED_ITEM_SEEDED_QUANTITY,
            (int) DB::table('items')->where('id', self::DAMAGED_ITEM_ID)->value('quantity'),
            'The damaged asset quantity must be untouched too.'
        );

        // Exactly one ledger row, and it is the declared artefact — nothing
        // generated a second one as a side effect.
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertSame(
            'deploy',
            DB::table('inventory_transactions')->where('id', 1)->value('transaction_type')
        );
    }

    // -----------------------------------------------------------------------
    // GATE H — history
    // -----------------------------------------------------------------------

    /**
     * GATE H — both legacy history tables carry representative events, and
     * every event resolves to its parent.
     */
    public function test_history_is_represented_and_resolves(): void
    {
        $this->assertSame(
            0,
            DB::table('damage_report_histories as h')
                ->leftJoin('damage_reports as dr', 'dr.id', '=', 'h.damage_report_id')
                ->whereNull('dr.id')
                ->count(),
            'Every damage report history row must resolve its parent.'
        );

        $this->assertSame(
            0,
            DB::table('repair_histories as h')
                ->leftJoin('repair_requests as rr', 'rr.id', '=', 'h.repair_request_id')
                ->whereNull('rr.id')
                ->count(),
            'Every repair history row must resolve its parent.'
        );

        // The event vocabulary the future unified history has to absorb.
        $this->assertSame(
            ['closed', 'created', 'repaired', 'replacement_fulfilled', 'status_changed'],
            $this->sortedDistinct('damage_report_histories', 'action_type'),
            'The damage history must cover creation, status change, repair, replacement and closure.'
        );

        $this->assertSame(
            ['assigned', 'completed', 'created', 'failed', 'replacement_fulfilled', 'status_changed'],
            $this->sortedDistinct('repair_histories', 'action_type'),
            'The repair history must cover assignment, progress, completion, failure and replacement.'
        );

        // A full lifecycle exists in order, so the rehearsal can test ordering
        // and not just presence.
        $this->assertSame(
            ['pending', 'under_review', 'repairing', 'replaced'],
            DB::table('damage_report_histories')
                ->where('damage_report_id', 9)
                ->orderBy('id')
                ->pluck('to_status')
                ->all(),
            'Damage report 9 must carry a complete, ordered lifecycle ending in replacement.'
        );
    }

    /**
     * The history tables type their statuses as varchar(30) while the parent
     * tables use ENUMs. That asymmetry is real, it is in the migrations, and it
     * means history rows are NOT constrained to the parent's vocabulary.
     *
     * The unified history table the next tasks design has to decide
     * deliberately whether to keep that looseness or tighten it — so the fact
     * is pinned here rather than left to be rediscovered.
     */
    public function test_history_status_columns_are_looser_than_their_parents(): void
    {
        $this->assertSame('varchar(30)', $this->columnType('damage_report_histories', 'to_status'));
        $this->assertSame('varchar(30)', $this->columnType('repair_histories', 'to_status'));

        $this->assertStringStartsWith('enum(', (string) $this->columnType('damage_reports', 'status'));
        $this->assertStringStartsWith('enum(', (string) $this->columnType('repair_requests', 'repair_status'));
    }

    // -----------------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------------

    /**
     * Every seeded notification points at a legacy entity that EXISTS.
     *
     * Task 64/65 found twelve genuinely orphaned damage_report notifications in
     * the live database. Those are a real finding and are untouched. The
     * fixture deliberately ships none of its own, so a rehearsal that
     * encounters an orphan knows it found one rather than seeded one.
     */
    public function test_seeded_notifications_reference_live_legacy_entities(): void
    {
        $damageOrphans = DB::table('notifications as n')
            ->leftJoin('damage_reports as dr', 'dr.id', '=', 'n.entity_id')
            ->where('n.entity_type', 'damage_report')
            ->whereNull('dr.id')
            ->count();

        $repairOrphans = DB::table('notifications as n')
            ->leftJoin('repair_requests as rr', 'rr.id', '=', 'n.entity_id')
            ->where('n.entity_type', 'repair_request')
            ->whereNull('rr.id')
            ->count();

        $this->assertSame(0, $damageOrphans, 'The fixture must not seed orphaned damage_report notifications.');
        $this->assertSame(0, $repairOrphans, 'The fixture must not seed orphaned repair_request notifications.');

        $this->assertSame(
            ['damage_report', 'repair_request'],
            $this->sortedDistinct('notifications', 'entity_type'),
            'Both legacy entity types must be represented.'
        );
    }

    // -----------------------------------------------------------------------
    // Phase 14 — status mapping evidence, executable
    // -----------------------------------------------------------------------

    /**
     * The lossy mapping, demonstrated on real rows rather than asserted on
     * paper.
     *
     * RepairService::syncDamageReportStatus() collapses eight repair statuses
     * into six damage statuses, and MaintenanceReportSyncService collapses
     * those into the maintenance report status model. This test walks the
     * dataset through the FIRST hop and shows the collapse actually happening:
     *
     *   pending, assigned, diagnosing, failed  -> under_review   (4 -> 1)
     *   repairing, waiting_parts               -> repairing      (2 -> 1)
     *
     * The consequence worth naming: a FAILED repair and an untouched PENDING
     * one both present as 'under_review'. Once migrated, nothing downstream can
     * distinguish "nobody has looked at this yet" from "we tried and it did not
     * work" — which are opposite operational situations.
     *
     * NOTHING IS CHANGED HERE. Phase 14 is evidence gathering; widening the
     * status model or introducing a work_stage column is explicitly out of
     * scope for this task.
     */
    public function test_repair_status_collapse_is_visible_in_the_dataset(): void
    {
        $pairs = DB::table('repair_requests as rr')
            ->join('damage_reports as dr', 'dr.id', '=', 'rr.damage_report_id')
            ->orderBy('rr.id')
            ->pluck('dr.status', 'rr.repair_status')
            ->all();

        // Every pairing in the dataset matches the real service's mapping.
        foreach (self::legacyRepairRequestCases() as $case) {
            $damageStatus = DB::table('damage_reports')->where('id', $case['damage_report_id'])->value('status');

            $this->assertSame(
                $case['damage_status'],
                $damageStatus,
                "Repair status '{$case['repair_status']}' must sit on a damage report in state "
                . "'{$case['damage_status']}', which is what RepairService::syncDamageReportStatus() "
                . 'produces. A mismatch means the fixture is not a state the application could reach.'
            );
        }

        $this->assertSame(
            'under_review',
            $pairs['failed'],
            "A FAILED repair leaves the damage report in 'under_review' — indistinguishable from a "
            . 'repair nobody has started. This is the headline information loss.'
        );

        $this->assertSame($pairs['pending'], $pairs['failed'], 'Confirming the collapse: two opposite situations, one status.');
        $this->assertSame($pairs['repairing'], $pairs['waiting_parts'], "'waiting_parts' is not separately representable either.");

        // Eight distinct repair states arrive as strictly fewer damage states.
        $this->assertGreaterThan(
            count(array_unique(array_values($pairs))),
            count(self::ALL_REPAIR_STATUSES),
            'The mapping must be provably lossy: more source states than destination states.'
        );
    }

    /**
     * Phase 16 — the room reference has nowhere to land.
     *
     * damage_reports.room_id is a real foreign key to rooms. maintenance_reports
     * has NO room_id at all — only a free-text `location` string. So migrating a
     * damage report to a unified report converts a structured, joinable
     * reference into prose, and the reverse lookup ("every open report in room
     * REH-102") stops being expressible in SQL.
     *
     * Task 63 flagged that some legacy replacement behaviour requires room_id.
     * Adding room_id to maintenance_reports is explicitly NOT part of this
     * task; this test states the gap so the next one cannot overlook it.
     */
    public function test_room_reference_has_no_structured_destination(): void
    {
        $this->assertNotNull(
            $this->columnType('damage_reports', 'room_id'),
            'damage_reports.room_id exists and is a foreign key.'
        );

        $this->assertNull(
            $this->columnType('maintenance_reports', 'room_id'),
            'maintenance_reports has no room_id. Any room association carried by a legacy row can '
            . 'only survive migration as free text in `location` — a structured reference becomes prose.'
        );

        $this->assertSame(
            'varchar(255)',
            $this->columnType('maintenance_reports', 'location'),
            'The only available destination is a free-text column.'
        );

        // Both shapes are present in the dataset: rooms that would need
        // flattening, and one row with no room at all.
        $this->assertSame(9, DB::table('damage_reports')->whereNotNull('room_id')->count());
        $this->assertSame(1, DB::table('damage_reports')->whereNull('room_id')->count());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Guards the value of every status assertion above. Under a non-strict
     * session MySQL coerces bad ENUM values to '' instead of rejecting them, so
     * a "successful" insert would prove nothing at all.
     */
    private function assertStrictModeIsActive(): void
    {
        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode,
            "The connection must be strict for these assertions to mean anything; "
            . "config/database.php sets 'strict' => true."
        );
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $row === null ? null : strtolower((string) $row->COLUMN_TYPE);
    }

    /** @return array<int, string> */
    private function sortedDistinct(string $table, string $column): array
    {
        $values = array_values(array_unique(
            array_map('strval', DB::table($table)->pluck($column)->all())
        ));

        sort($values);

        return $values;
    }
}
