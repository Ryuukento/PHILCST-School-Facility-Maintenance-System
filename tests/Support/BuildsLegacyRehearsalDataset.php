<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * TASK 67 — the deterministic legacy dataset the future Damage Report /
 * Repair Request -> Unified Maintenance Report migration will be rehearsed
 * against.
 *
 * WHAT THIS IS FOR
 * ----------------
 * Task 63 planned the retirement of the legacy Damage Report / Repair Request
 * pair; Task 64 specified the unified target; Task 65 and 66 made the schema
 * reproducible. The next step is a rehearsal: run MAP -> TRANSFORM -> VALIDATE
 * -> ROLLBACK over realistic legacy rows. That rehearsal must not invent its
 * own data while it runs, because data invented mid-migration cannot be
 * reviewed beforehand and cannot be compared between runs.
 *
 * So the dataset is pinned here, once, ahead of time.
 *
 * THREE PROPERTIES THIS BUILDER GUARANTEES
 * ----------------------------------------
 *  1. DETERMINISTIC. Every id, code, status, name and timestamp is a literal.
 *     There is no faker, no random(), no now(). Two runs produce byte-identical
 *     rows, so a rehearsal can assert on exact primary keys and a second run
 *     can be diffed against the first.
 *
 *  2. SYNTHETIC. Every name, code and address-like string is obviously fake and
 *     carries a REH- / Rehearsal marker. No production row is read, copied or
 *     referenced. There is no personal information of any kind.
 *
 *  3. NO INVENTORY MUTATION — and this one is structural, not a promise.
 *     Every write below goes through DB::table()->insert(), never through an
 *     Eloquent model. Eloquent is what fires model events, and model events are
 *     what reach InventoryTransactionObserver, the single writer of
 *     items.quantity / items.status. Bypassing Eloquent means the observer
 *     cannot run, so seeding cannot move stock even by accident. The
 *     inventory_transactions row seeded below is therefore a HISTORICAL
 *     ARTEFACT of a mutation that happened in the fictional past — it is not an
 *     executed mutation, and items.quantity is deliberately left at its seeded
 *     value to prove exactly that. See REPLACEMENT_ITEM_SEEDED_QUANTITY.
 *
 * WHY A TRAIT AND NOT A SEEDER
 * ----------------------------
 * A seeder is discoverable by `db:seed` and one careless invocation aims it at
 * whatever database is currently configured — which, outside a test, is the
 * real one. A trait has no such entry point: it can only run inside a test
 * class that has already pointed itself at a scratch database. It also matches
 * the idiom already in this directory (BuildsSharedTestSchema). Nothing in
 * app/, routes/, config/ or database/seeders/ references this file.
 *
 * COVERAGE, AND WHY THE SHAPE IS WHAT IT IS
 * -----------------------------------------
 * repair_requests.damage_report_id is UNIQUE — one repair request per damage
 * report, enforced by the schema. Covering all eight repair_status values
 * therefore REQUIRES at least eight damage reports; they cannot be folded into
 * fewer rows. That single constraint is what sets the size of this dataset:
 * ten damage reports (all six damage statuses) and nine repair requests (all
 * eight repair statuses), which is the minimum that is also representative.
 *
 * Every damage/repair status pairing below is internally consistent with the
 * REAL mapping in RepairService::syncDamageReportStatus(), so the rehearsal is
 * reading rows the running application could actually have produced:
 *
 *     pending, assigned, diagnosing, failed  -> under_review
 *     repairing, waiting_parts               -> repairing
 *     completed                              -> repaired
 *     archived                               -> replaced if already replaced,
 *                                               otherwise closed
 *
 * DR 9 and DR 10 exist specifically to cover both sides of that last branch.
 */
trait BuildsLegacyRehearsalDataset
{
    /**
     * The stock level of the replacement item, seeded and then never touched.
     *
     * A rehearsal asserts this value is still intact afterwards. If it ever
     * changes, something reached the inventory observer, which would mean the
     * fixture stopped being inert.
     */
    public const REPLACEMENT_ITEM_ID = 2;
    public const REPLACEMENT_ITEM_SEEDED_QUANTITY = 50;
    public const REPLACEMENT_ITEM_SEEDED_RESERVED = 0;

    /** The damaged room asset every damage report points at. */
    public const DAMAGED_ITEM_ID = 1;
    public const DAMAGED_ITEM_SEEDED_QUANTITY = 1;

    public const USER_REPORTER = 1;
    public const USER_TECHNICIAN_A = 2;
    public const USER_TECHNICIAN_B = 3;
    public const USER_ADMIN = 4;

    /** Fixed clock. Nothing here calls now(). */
    private const T0 = '2026-01-05 08:00:00';
    private const T1 = '2026-01-06 09:30:00';
    private const T2 = '2026-01-07 10:15:00';
    private const T3 = '2026-01-08 11:45:00';
    private const T4 = '2026-01-09 14:00:00';

    /**
     * Every damage report in the dataset, as a reviewable table.
     *
     * The rehearsal iterates this rather than repeating literals, so the
     * dataset has exactly one definition and the assertions cannot drift from
     * the rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function legacyDamageReportCases(): array
    {
        return [
            // id, code, room, status, severity, linked maintenance report, note.
            ['id' => 1,  'code' => 'DR-REH-0001', 'room_id' => 1,    'status' => 'pending',      'severity' => 'low',      'report_id' => 1,    'has_repair_request' => false, 'case' => 'CASE 1 — basic damage, never triaged, no repair request, no replacement.'],
            ['id' => 2,  'code' => 'DR-REH-0002', 'room_id' => 1,    'status' => 'under_review', 'severity' => 'medium',   'report_id' => null, 'has_repair_request' => true,  'case' => 'CASE 2 — repair request raised but not yet assigned to anyone.'],
            ['id' => 3,  'code' => 'DR-REH-0003', 'room_id' => 1,    'status' => 'under_review', 'severity' => 'medium',   'report_id' => null, 'has_repair_request' => true,  'case' => 'CASE 2 — repair request assigned to a technician.'],
            ['id' => 4,  'code' => 'DR-REH-0004', 'room_id' => 2,    'status' => 'under_review', 'severity' => 'high',     'report_id' => null, 'has_repair_request' => true,  'case' => 'CASE 2 — technician diagnosing.'],
            ['id' => 5,  'code' => 'DR-REH-0005', 'room_id' => 1,    'status' => 'repairing',    'severity' => 'high',     'report_id' => 2,    'has_repair_request' => true,  'case' => 'CASE 2 — active repair in progress.'],
            ['id' => 6,  'code' => 'DR-REH-0006', 'room_id' => 2,    'status' => 'repairing',    'severity' => 'medium',   'report_id' => null, 'has_repair_request' => true,  'case' => 'CASE 2 — repair stalled waiting for parts.'],
            ['id' => 7,  'code' => 'DR-REH-0007', 'room_id' => 1,    'status' => 'repaired',     'severity' => 'low',      'report_id' => null, 'has_repair_request' => true,  'case' => 'CASE 4 — completed repair, no replacement needed.'],
            ['id' => 8,  'code' => 'DR-REH-0008', 'room_id' => 2,    'status' => 'under_review', 'severity' => 'critical', 'report_id' => 3,    'has_repair_request' => true,  'case' => 'CASE 3 — repair FAILED, replacement dispatch approved but not released.'],
            ['id' => 9,  'code' => 'DR-REH-0009', 'room_id' => 1,    'status' => 'replaced',     'severity' => 'critical', 'report_id' => 4,    'has_repair_request' => true,  'case' => 'CASE 5 — replacement completed and released.'],
            ['id' => 10, 'code' => 'DR-REH-0010', 'room_id' => null, 'status' => 'closed',       'severity' => 'low',      'report_id' => null, 'has_repair_request' => true,  'case' => 'CASE 4 — closed with NO room association (room_id NULL) — the Phase 16 location-mapping edge.'],
        ];
    }

    /**
     * Every repair request in the dataset.
     *
     * `damage_status` records the damage report status this repair status
     * legitimately produces via RepairService::syncDamageReportStatus(), so the
     * table doubles as the readable statement of that mapping.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function legacyRepairRequestCases(): array
    {
        return [
            // id, code, damage report, technician, repair status, resulting damage status.
            ['id' => 1, 'code' => 'RR-REH-0001', 'damage_report_id' => 2,  'technician_user_id' => null,                  'repair_status' => 'pending',       'damage_status' => 'under_review', 'report_id' => null, 'has_replacement' => false],
            ['id' => 2, 'code' => 'RR-REH-0002', 'damage_report_id' => 3,  'technician_user_id' => self::USER_TECHNICIAN_A, 'repair_status' => 'assigned',      'damage_status' => 'under_review', 'report_id' => null, 'has_replacement' => false],
            ['id' => 3, 'code' => 'RR-REH-0003', 'damage_report_id' => 4,  'technician_user_id' => self::USER_TECHNICIAN_A, 'repair_status' => 'diagnosing',    'damage_status' => 'under_review', 'report_id' => null, 'has_replacement' => false],
            ['id' => 4, 'code' => 'RR-REH-0004', 'damage_report_id' => 5,  'technician_user_id' => self::USER_TECHNICIAN_A, 'repair_status' => 'repairing',     'damage_status' => 'repairing',    'report_id' => 2,    'has_replacement' => false],
            ['id' => 5, 'code' => 'RR-REH-0005', 'damage_report_id' => 6,  'technician_user_id' => self::USER_TECHNICIAN_B, 'repair_status' => 'waiting_parts', 'damage_status' => 'repairing',    'report_id' => null, 'has_replacement' => false],
            ['id' => 6, 'code' => 'RR-REH-0006', 'damage_report_id' => 7,  'technician_user_id' => self::USER_TECHNICIAN_B, 'repair_status' => 'completed',     'damage_status' => 'repaired',     'report_id' => null, 'has_replacement' => false],
            ['id' => 7, 'code' => 'RR-REH-0007', 'damage_report_id' => 8,  'technician_user_id' => self::USER_TECHNICIAN_B, 'repair_status' => 'failed',        'damage_status' => 'under_review', 'report_id' => 3,    'has_replacement' => true],
            ['id' => 8, 'code' => 'RR-REH-0008', 'damage_report_id' => 9,  'technician_user_id' => self::USER_TECHNICIAN_A, 'repair_status' => 'archived',      'damage_status' => 'replaced',     'report_id' => 4,    'has_replacement' => true],
            ['id' => 9, 'code' => 'RR-REH-0009', 'damage_report_id' => 10, 'technician_user_id' => null,                  'repair_status' => 'archived',      'damage_status' => 'closed',       'report_id' => null, 'has_replacement' => false],
        ];
    }

    /**
     * Insert the whole dataset, in foreign-key-safe order.
     *
     * Callers MUST already be pointed at a scratch database
     * (ConnectsToScratchMySqlDatabase::useScratchMySqlDatabase) with the
     * migration chain applied.
     */
    protected function seedLegacyRehearsalDataset(): void
    {
        $this->seedRehearsalOrganisation();
        $this->seedRehearsalUsers();
        $this->seedRehearsalLocationsAndItems();
        $this->seedRehearsalMaintenanceReports();
        $this->seedRehearsalDamageReports();
        $this->seedRehearsalDispatches();
        $this->seedRehearsalInventoryTransactionArtefact();
        $this->seedRehearsalRepairRequests();
        $this->seedRehearsalReplacementLinkage();
        $this->seedRehearsalHistories();
        $this->seedRehearsalNotifications();
    }

    // -----------------------------------------------------------------------
    // Supporting rows — the minimum the legacy foreign keys demand
    // -----------------------------------------------------------------------

    private function seedRehearsalOrganisation(): void
    {
        DB::table('departments')->insert([
            'department_id' => 1,
            'name'          => 'Rehearsal Department',
            'status'        => 'active',
            'created_at'    => self::T0,
            'updated_at'    => self::T0,
        ]);
    }

    private function seedRehearsalUsers(): void
    {
        $common = [
            'password'      => 'not-a-real-hash',
            'department_id' => 1,
            'status'        => 'active',
            'created_at'    => self::T0,
            'updated_at'    => self::T0,
        ];

        DB::table('users')->insert([
            $common + ['user_id' => self::USER_REPORTER,     'full_name' => 'Rehearsal Reporter',      'username' => 'reh_reporter', 'email' => 'reh_reporter@example.invalid', 'role' => 'user'],
            $common + ['user_id' => self::USER_TECHNICIAN_A, 'full_name' => 'Rehearsal Technician A',  'username' => 'reh_tech_a',   'email' => 'reh_tech_a@example.invalid',   'role' => 'maintenance_staff'],
            $common + ['user_id' => self::USER_TECHNICIAN_B, 'full_name' => 'Rehearsal Technician B',  'username' => 'reh_tech_b',   'email' => 'reh_tech_b@example.invalid',   'role' => 'maintenance_staff'],
            $common + ['user_id' => self::USER_ADMIN,        'full_name' => 'Rehearsal Admin',         'username' => 'reh_admin',    'email' => 'reh_admin@example.invalid',    'role' => 'maintenance_admin'],
        ]);
    }

    private function seedRehearsalLocationsAndItems(): void
    {
        DB::table('buildings')->insert([
            'id' => 1, 'name' => 'Rehearsal Hall', 'created_at' => self::T0, 'updated_at' => self::T0,
        ]);

        DB::table('floors')->insert([
            'id' => 1, 'building_id' => 1, 'name' => 'Rehearsal Ground Floor', 'created_at' => self::T0, 'updated_at' => self::T0,
        ]);

        DB::table('rooms')->insert([
            ['id' => 1, 'building_id' => 1, 'floor_id' => 1, 'name' => 'REH-101', 'created_at' => self::T0, 'updated_at' => self::T0],
            ['id' => 2, 'building_id' => 1, 'floor_id' => 1, 'name' => 'REH-102', 'created_at' => self::T0, 'updated_at' => self::T0],
        ]);

        // Both rows must declare the SAME columns: a multi-row insert builds one
        // statement from the first row's keys, so a key present on only one row
        // is silently dropped from the column list and the value counts diverge.
        DB::table('items')->insert([
            [
                'id'                => self::DAMAGED_ITEM_ID,
                'room_id'           => 1,
                'item_type'         => 'room_asset',
                'name'              => 'Rehearsal Damaged Projector',
                'status'            => 'damaged',
                'quantity'          => self::DAMAGED_ITEM_SEEDED_QUANTITY,
                'reserved_quantity' => 0,
                'created_at'        => self::T0,
                'updated_at'        => self::T0,
            ],
            [
                'id'                => self::REPLACEMENT_ITEM_ID,
                'room_id'           => null,
                'item_type'         => 'inventory_stock',
                'name'              => 'Rehearsal Replacement Projector',
                'status'            => 'available',
                'quantity'          => self::REPLACEMENT_ITEM_SEEDED_QUANTITY,
                'reserved_quantity' => self::REPLACEMENT_ITEM_SEEDED_RESERVED,
                'created_at'        => self::T0,
                'updated_at'        => self::T0,
            ],
        ]);
    }

    /**
     * Four unified reports, deliberately fewer than the ten damage reports.
     *
     * The legacy tables are only PARTLY linked to maintenance_reports today —
     * report_id is nullable on both — so a rehearsal that saw every legacy row
     * already linked would be rehearsing the easy half of the problem. Six of
     * the ten damage reports below carry report_id = NULL on purpose: those are
     * the rows the future migration has to CREATE a unified report for.
     */
    private function seedRehearsalMaintenanceReports(): void
    {
        $common = [
            'description'     => 'Synthetic rehearsal report. Not production data.',
            'report_category' => 'repair_replacement',
            'priority'        => 'medium',
            'created_by'      => self::USER_REPORTER,
            'department_id'   => 1,
            'created_at'      => self::T0,
            'updated_at'      => self::T0,
        ];

        DB::table('maintenance_reports')->insert([
            $common + ['report_id' => 1, 'title' => 'Rehearsal MR 1 — untriaged damage',   'location' => 'Rehearsal Hall / REH-101', 'status' => 'submitted',   'assigned_to' => null],
            $common + ['report_id' => 2, 'title' => 'Rehearsal MR 2 — repair in progress', 'location' => 'Rehearsal Hall / REH-101', 'status' => 'in_progress', 'assigned_to' => self::USER_TECHNICIAN_B],
            $common + ['report_id' => 3, 'title' => 'Rehearsal MR 3 — failed repair',      'location' => 'Rehearsal Hall / REH-102', 'status' => 'assigned',    'assigned_to' => self::USER_ADMIN],
            $common + ['report_id' => 4, 'title' => 'Rehearsal MR 4 — replaced asset',     'location' => 'Rehearsal Hall / REH-101', 'status' => 'completed',   'assigned_to' => self::USER_TECHNICIAN_B],
        ]);
    }

    // -----------------------------------------------------------------------
    // The legacy rows themselves
    // -----------------------------------------------------------------------

    private function seedRehearsalDamageReports(): void
    {
        $rows = [];

        foreach (self::legacyDamageReportCases() as $case) {
            $rows[] = [
                'id'                 => $case['id'],
                'damage_report_code' => $case['code'],
                'report_id'          => $case['report_id'],
                'item_id'            => self::DAMAGED_ITEM_ID,
                'room_id'            => $case['room_id'],
                'department_id'      => 1,
                'damage_description' => 'Synthetic rehearsal damage. ' . $case['case'],
                'severity_level'     => $case['severity'],
                'reported_by'        => self::USER_REPORTER,
                'status'             => $case['status'],
                'repair_notes'       => $case['status'] === 'pending' ? null : 'Rehearsal repair notes.',
                'closed_at'          => $case['status'] === 'closed' ? self::T4 : null,
                'created_at'         => self::T0,
                'updated_at'         => self::T1,
            ];
        }

        DB::table('damage_reports')->insert($rows);
    }

    /**
     * Two dispatches, chosen to sit on OPPOSITE sides of the release boundary.
     *
     * Dispatch 1 is approved but NOT released — approval is a decision, release
     * is the act that moves stock. Dispatch 2 is released, and is the fictional
     * past event the inventory_transactions artefact belongs to. Neither is
     * created through DispatchService, so no release logic runs here at all.
     */
    private function seedRehearsalDispatches(): void
    {
        // Same-columns-per-row rule as items above.
        DB::table('dispatches')->insert([
            [
                'id'                  => 1,
                'dispatch_code'       => 'DSP-REH-0001',
                'department_id'       => 1,
                'requested_by'        => self::USER_ADMIN,
                'approved_by'         => self::USER_ADMIN,
                'approved_at'         => self::T2,
                'released_by'         => null,
                'release_assigned_to' => null,
                'release_assigned_by' => null,
                'release_assigned_at' => null,
                'receiver_user_id'    => null,
                'room_id'             => 2,
                'damage_report_id'    => 8,
                'report_id'           => 3,
                'status'              => 'approved',
                'notes'               => 'Rehearsal replacement approved, not yet released.',
                'created_at'          => self::T2,
                'updated_at'          => self::T2,
            ],
            [
                'id'                  => 2,
                'dispatch_code'       => 'DSP-REH-0002',
                'department_id'       => 1,
                'requested_by'        => self::USER_ADMIN,
                'approved_by'         => self::USER_ADMIN,
                'approved_at'         => self::T3,
                'released_by'         => self::USER_ADMIN,
                'release_assigned_to' => self::USER_TECHNICIAN_A,
                'release_assigned_by' => self::USER_ADMIN,
                'release_assigned_at' => self::T3,
                'receiver_user_id'    => self::USER_REPORTER,
                'room_id'             => 1,
                'damage_report_id'    => 9,
                'report_id'           => 4,
                'status'              => 'released',
                'notes'               => 'Rehearsal replacement released.',
                'created_at'          => self::T3,
                'updated_at'          => self::T4,
            ],
        ]);

        DB::table('dispatch_items')->insert([
            ['id' => 1, 'dispatch_id' => 1, 'item_id' => self::REPLACEMENT_ITEM_ID, 'quantity' => 1, 'created_at' => self::T2, 'updated_at' => self::T2],
            ['id' => 2, 'dispatch_id' => 2, 'item_id' => self::REPLACEMENT_ITEM_ID, 'quantity' => 1, 'created_at' => self::T3, 'updated_at' => self::T3],
        ]);
    }

    /**
     * A ledger row describing a deployment that happened in the fixture's
     * fictional past.
     *
     * This is the one place the dataset comes close to inventory, so it is
     * worth being exact about what it is NOT: it is not a mutation. It is a row
     * in a log table, inserted with DB::table(), which no observer watches and
     * no service reads on write. items.quantity is left at
     * REPLACEMENT_ITEM_SEEDED_QUANTITY precisely so a rehearsal can assert the
     * ledger and the stock level are INDEPENDENT here — proving the fixture
     * described history rather than performing it.
     */
    private function seedRehearsalInventoryTransactionArtefact(): void
    {
        DB::table('inventory_transactions')->insert([
            'id'               => 1,
            'item_id'          => self::REPLACEMENT_ITEM_ID,
            'report_id'        => 4,
            'dispatch_id'      => 2,
            'room_id'          => 1,
            'transaction_type' => 'deploy',
            'quantity'         => 1,
            'reference_note'   => 'Rehearsal artefact only. No stock was moved to create this row.',
            'performed_by'     => self::USER_ADMIN,
            'created_at'       => self::T4,
            'updated_at'       => self::T4,
        ]);
    }

    private function seedRehearsalRepairRequests(): void
    {
        $rows = [];

        foreach (self::legacyRepairRequestCases() as $case) {
            $isTerminal = in_array($case['repair_status'], ['completed', 'archived'], true);

            $rows[] = [
                'id'                        => $case['id'],
                'repair_code'               => $case['code'],
                'damage_report_id'          => $case['damage_report_id'],
                'report_id'                 => $case['report_id'],
                'technician_user_id'        => $case['technician_user_id'],
                'repair_type'               => 'corrective',
                'repair_description'        => 'Synthetic rehearsal repair request.',
                'repair_cost'               => '0.00',
                'repair_status'             => $case['repair_status'],
                'repair_date'               => $case['technician_user_id'] === null ? null : '2026-01-06',
                'estimated_completion_date' => $case['technician_user_id'] === null ? null : '2026-01-12',
                'completion_date'           => $isTerminal ? '2026-01-09' : null,
                'notes'                     => 'Rehearsal notes.',
                'failure_reason'            => $case['repair_status'] === 'failed' ? 'Rehearsal failure: component unavailable.' : null,
                'created_by'                => self::USER_ADMIN,
                'updated_by'                => self::USER_ADMIN,
                'archived_at'               => $case['repair_status'] === 'archived' ? self::T4 : null,
                'created_at'                => self::T1,
                'updated_at'                => self::T2,
            ];
        }

        DB::table('repair_requests')->insert($rows);
    }

    /**
     * The replacement columns, applied after both sides exist.
     *
     * They are set with UPDATE rather than in the INSERT above because
     * repair_requests.replacement_dispatch_id points at dispatches, and
     * dispatches.repair_request_id points back at repair_requests — a genuine
     * cycle in the live schema. Something has to be written second; splitting
     * it out makes that ordering explicit rather than accidental.
     */
    private function seedRehearsalReplacementLinkage(): void
    {
        // Repair request 7 — FAILED, replacement approved but not released.
        DB::table('repair_requests')->where('id', 7)->update([
            'replacement_item_id'     => self::REPLACEMENT_ITEM_ID,
            'replacement_quantity'    => 1,
            'replacement_dispatch_id' => 1,
        ]);

        // Repair request 8 — ARCHIVED after the replacement was released.
        DB::table('repair_requests')->where('id', 8)->update([
            'replacement_item_id'        => self::REPLACEMENT_ITEM_ID,
            'replacement_quantity'       => 1,
            'replacement_dispatch_id'    => 2,
            'replacement_transaction_id' => 1,
        ]);

        // Damage report 9 carries the completed replacement outcome.
        DB::table('damage_reports')->where('id', 9)->update([
            'replacement_item_id'        => self::REPLACEMENT_ITEM_ID,
            'replacement_quantity'       => 1,
            'replacement_transaction_id' => 1,
            'replaced_by'                => self::USER_ADMIN,
            'replaced_at'                => self::T4,
            'source_dispatch_id'         => 2,
        ]);

        DB::table('dispatches')->where('id', 1)->update(['repair_request_id' => 7]);
        DB::table('dispatches')->where('id', 2)->update(['repair_request_id' => 8]);
    }

    // -----------------------------------------------------------------------
    // History — the audit trail the future migration has to carry across
    // -----------------------------------------------------------------------

    /**
     * Representative history for the three damage reports whose stories are
     * complete enough to be worth replaying: an untriaged one, a repaired one,
     * a replaced one, and a closed one.
     *
     * Both legacy history tables store statuses as varchar(30), NOT as the
     * enums the parent tables use — so history rows can already hold status
     * strings the parent column would reject. That is recorded here rather than
     * asserted away, because it is exactly the kind of thing a unified history
     * table has to decide what to do about.
     */
    private function seedRehearsalHistories(): void
    {
        DB::table('damage_report_histories')->insert([
            ['id' => 1,  'damage_report_id' => 1,  'action_type' => 'created',              'from_status' => null,           'to_status' => 'pending',      'notes' => 'Rehearsal: damage reported.',            'changed_by' => self::USER_REPORTER,     'created_at' => self::T0],
            ['id' => 2,  'damage_report_id' => 7,  'action_type' => 'created',              'from_status' => null,           'to_status' => 'pending',      'notes' => 'Rehearsal: damage reported.',            'changed_by' => self::USER_REPORTER,     'created_at' => self::T0],
            ['id' => 3,  'damage_report_id' => 7,  'action_type' => 'status_changed',       'from_status' => 'pending',      'to_status' => 'under_review', 'notes' => 'Rehearsal: repair request raised.',      'changed_by' => self::USER_ADMIN,        'created_at' => self::T1],
            ['id' => 4,  'damage_report_id' => 7,  'action_type' => 'status_changed',       'from_status' => 'under_review', 'to_status' => 'repairing',    'notes' => 'Rehearsal: repair started.',             'changed_by' => self::USER_TECHNICIAN_B, 'created_at' => self::T2],
            ['id' => 5,  'damage_report_id' => 7,  'action_type' => 'repaired',             'from_status' => 'repairing',    'to_status' => 'repaired',     'notes' => 'Rehearsal: repair completed.',           'changed_by' => self::USER_TECHNICIAN_B, 'created_at' => self::T3],
            ['id' => 6,  'damage_report_id' => 9,  'action_type' => 'created',              'from_status' => null,           'to_status' => 'pending',      'notes' => 'Rehearsal: damage reported.',            'changed_by' => self::USER_REPORTER,     'created_at' => self::T0],
            ['id' => 7,  'damage_report_id' => 9,  'action_type' => 'status_changed',       'from_status' => 'pending',      'to_status' => 'under_review', 'notes' => 'Rehearsal: repair request raised.',      'changed_by' => self::USER_ADMIN,        'created_at' => self::T1],
            ['id' => 8,  'damage_report_id' => 9,  'action_type' => 'status_changed',       'from_status' => 'under_review', 'to_status' => 'repairing',    'notes' => 'Rehearsal: repair attempted.',           'changed_by' => self::USER_TECHNICIAN_A, 'created_at' => self::T2],
            ['id' => 9,  'damage_report_id' => 9,  'action_type' => 'replacement_fulfilled', 'from_status' => 'repairing',   'to_status' => 'replaced',     'notes' => 'Rehearsal: replacement released.',       'changed_by' => self::USER_ADMIN,        'created_at' => self::T4],
            ['id' => 10, 'damage_report_id' => 10, 'action_type' => 'created',              'from_status' => null,           'to_status' => 'pending',      'notes' => 'Rehearsal: damage reported.',            'changed_by' => self::USER_REPORTER,     'created_at' => self::T0],
            ['id' => 11, 'damage_report_id' => 10, 'action_type' => 'closed',               'from_status' => 'pending',      'to_status' => 'closed',       'notes' => 'Rehearsal: closed without repair.',      'changed_by' => self::USER_ADMIN,        'created_at' => self::T4],
        ]);

        DB::table('repair_histories')->insert([
            ['id' => 1, 'repair_request_id' => 6, 'action_type' => 'created',              'from_status' => null,         'to_status' => 'pending',    'technician_user_id' => null,                    'notes' => 'Rehearsal: repair request raised.',  'changed_by' => self::USER_ADMIN,        'created_at' => self::T1],
            ['id' => 2, 'repair_request_id' => 6, 'action_type' => 'assigned',             'from_status' => 'pending',    'to_status' => 'assigned',   'technician_user_id' => self::USER_TECHNICIAN_B, 'notes' => 'Rehearsal: technician assigned.',    'changed_by' => self::USER_ADMIN,        'created_at' => self::T1],
            ['id' => 3, 'repair_request_id' => 6, 'action_type' => 'status_changed',       'from_status' => 'assigned',   'to_status' => 'repairing',  'technician_user_id' => self::USER_TECHNICIAN_B, 'notes' => 'Rehearsal: repair started.',         'changed_by' => self::USER_TECHNICIAN_B, 'created_at' => self::T2],
            ['id' => 4, 'repair_request_id' => 6, 'action_type' => 'completed',            'from_status' => 'repairing',  'to_status' => 'completed',  'technician_user_id' => self::USER_TECHNICIAN_B, 'notes' => 'Rehearsal: repair completed.',       'changed_by' => self::USER_TECHNICIAN_B, 'created_at' => self::T3],
            ['id' => 5, 'repair_request_id' => 7, 'action_type' => 'created',              'from_status' => null,         'to_status' => 'pending',    'technician_user_id' => null,                    'notes' => 'Rehearsal: repair request raised.',  'changed_by' => self::USER_ADMIN,        'created_at' => self::T1],
            ['id' => 6, 'repair_request_id' => 7, 'action_type' => 'failed',               'from_status' => 'diagnosing', 'to_status' => 'failed',     'technician_user_id' => self::USER_TECHNICIAN_B, 'notes' => 'Rehearsal: repair failed, replacement requested.', 'changed_by' => self::USER_TECHNICIAN_B, 'created_at' => self::T2],
            ['id' => 7, 'repair_request_id' => 8, 'action_type' => 'created',              'from_status' => null,         'to_status' => 'pending',    'technician_user_id' => null,                    'notes' => 'Rehearsal: repair request raised.',  'changed_by' => self::USER_ADMIN,        'created_at' => self::T1],
            ['id' => 8, 'repair_request_id' => 8, 'action_type' => 'failed',               'from_status' => 'repairing',  'to_status' => 'failed',     'technician_user_id' => self::USER_TECHNICIAN_A, 'notes' => 'Rehearsal: repair failed.',          'changed_by' => self::USER_TECHNICIAN_A, 'created_at' => self::T2],
            ['id' => 9, 'repair_request_id' => 8, 'action_type' => 'replacement_fulfilled', 'from_status' => 'failed',    'to_status' => 'archived',   'technician_user_id' => self::USER_TECHNICIAN_A, 'notes' => 'Rehearsal: replacement fulfilled, request archived.', 'changed_by' => self::USER_ADMIN, 'created_at' => self::T4],
        ]);
    }

    /**
     * A handful of notifications pointing at legacy entities.
     *
     * Every one references a legacy row that EXISTS. Task 64/65 found twelve
     * real orphaned damage_report notifications in the live database; those are
     * untouched and are deliberately not reproduced here, because a fixture
     * that shipped its own orphans would make the rehearsal unable to tell a
     * seeded orphan from a real finding.
     */
    private function seedRehearsalNotifications(): void
    {
        DB::table('notifications')->insert([
            ['id' => 1, 'user_id' => self::USER_TECHNICIAN_A, 'title' => 'Rehearsal: repair assigned',       'message' => 'A rehearsal repair request was assigned to you.', 'entity_type' => 'repair_request', 'entity_id' => 2, 'is_read' => 0, 'created_at' => self::T1, 'updated_at' => self::T1],
            ['id' => 2, 'user_id' => self::USER_ADMIN,        'title' => 'Rehearsal: repair failed',         'message' => 'A rehearsal repair failed and needs a replacement.', 'entity_type' => 'repair_request', 'entity_id' => 7, 'is_read' => 0, 'created_at' => self::T2, 'updated_at' => self::T2],
            ['id' => 3, 'user_id' => self::USER_REPORTER,     'title' => 'Rehearsal: asset replaced',        'message' => 'Your rehearsal damage report was resolved by replacement.', 'entity_type' => 'damage_report', 'entity_id' => 9, 'is_read' => 1, 'created_at' => self::T4, 'updated_at' => self::T4],
        ]);
    }
}
