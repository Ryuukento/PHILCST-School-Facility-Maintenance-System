<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * TASK 68 — the legacy -> unified migration, rehearsed.
 *
 * WHAT THIS IS
 * ------------
 * Task 63 planned the retirement of the Damage Report / Repair Request pair,
 * Task 64 specified the unified target, Tasks 65-66 made the schema
 * reproducible, and Task 67 pinned a deterministic synthetic dataset. This
 * trait is the next step and the last one before anything real is touched: it
 * runs MAP -> TRANSFORM -> VALIDATE over that dataset inside a scratch
 * database, and records what the migration WOULD do.
 *
 * It is a rehearsal. It never runs against the application database, it never
 * moves stock, and every row it writes is either in a scratch copy of
 * maintenance_reports or in a table whose name starts with `rehearsal_`.
 *
 * THE ONE DESIGN DECISION THAT MATTERS
 * ------------------------------------
 * Task 64 §9.3 specifies two NEW columns — `work_stage` and `resolution` —
 * without which the eight repair states cannot survive the collapse into the
 * six maintenance states. Those columns DO NOT EXIST yet, and Task 68 forbids
 * inventing them.
 *
 * So this rehearsal maps against the schema as it actually is today. That is
 * not a limitation to work around; it is the entire question being asked:
 *
 *     "If we ran the migration right now, against the real current schema,
 *      what would survive and what would be destroyed?"
 *
 * The answer is computed per record, not assumed. A record whose state needs a
 * column that does not exist is NOT transformed. It is stopped and marked
 * REQUIRES_PRODUCT_DECISION, because silently writing it would discard the
 * distinction that makes it that record. Task 68 Phase 6 requires exactly this:
 * "Do NOT silently collapse statuses that have different business meanings."
 *
 * That is why the rehearsal's expected outcome is a partial transform. A
 * rehearsal that transformed all nineteen records cleanly would not be a
 * success — it would mean the losses had been swallowed.
 *
 * WHY THE DECISION IS COMPUTED ON THE PAIR, NOT THE ROW
 * ----------------------------------------------------
 * A damage report and its repair request collapse into ONE unified report. So
 * the safety of that report is the safety of the pair. DR 8 looks harmless on
 * its own (`under_review` -> `assigned`), but its repair request is `failed`,
 * and `failed` is the precondition the replacement workflow gates on
 * (RepairService.php:386). Mapping the pair without carrying `failed` produces
 * a row that has quietly lost the right to be replaced. The damage report is
 * therefore blocked by its repair request's state.
 *
 * NO INVENTORY MUTATION — STRUCTURAL, NOT PROMISED
 * ------------------------------------------------
 * Same property Task 67 established: every write is DB::table(), never
 * Eloquent, so no model event fires and InventoryTransactionObserver — the
 * single writer of items.quantity / items.status — is unreachable. Replacement
 * chains are RECORDED into rehearsal_replacement_trace, never re-executed.
 */
trait RehearsesLegacyMigration
{
    // -----------------------------------------------------------------------
    // Vocabulary
    // -----------------------------------------------------------------------

    /** The pair's state fits the current schema with no loss of meaning. */
    public const DECISION_TRANSFORMED = 'TRANSFORMED';

    /** The pair needs a column that does not exist. Stopped deliberately. */
    public const DECISION_PRODUCT_DECISION = 'REQUIRES_PRODUCT_DECISION';

    public const ACTION_CREATED    = 'CREATED';
    public const ACTION_RECONCILED = 'RECONCILED_EXISTING';
    public const ACTION_MERGED     = 'MERGED_INTO_PARENT_REPORT';
    public const ACTION_NONE       = 'NONE';

    /**
     * New unified reports get id 100 + damage_report.id.
     *
     * Deterministic on purpose: the rehearsal asserts exact primary keys and
     * diffs one run against the next, neither of which is possible if the
     * target ids come from AUTO_INCREMENT.
     */
    public const TARGET_REPORT_ID_BASE = 100;

    // -----------------------------------------------------------------------
    // PHASE 5/9/10 — the field map, as reviewable data
    // -----------------------------------------------------------------------

    /**
     * Every damage_reports column, classified against the CURRENT
     * maintenance_reports schema.
     *
     * 'result' is one of:
     *   PRESERVED        — carried across intact
     *   TRANSFORMED      — carried across but reshaped (see note)
     *   PARTIAL          — some of the meaning survives, some does not
     *   LOST             — no target column exists
     *   PRODUCT_DECISION — a target is conceivable but choosing one is a
     *                      product call, so this rehearsal refuses to guess
     *
     * @return array<string, array<string, string|null>>
     */
    public static function damageReportFieldMap(): array
    {
        return [
            'id'                         => ['target' => null,                'result' => 'PARTIAL',    'note' => 'Not a target column. Retained in the rehearsal ledger so the target row is always traceable back to its source.'],
            'damage_report_code'         => ['target' => null,                'result' => 'LOST',       'note' => 'No target column. Task 64 §5.4 says preserve historical codes on legacy rows and mint no new ones — which means the unified row cannot display it.'],
            'report_id'                  => ['target' => 'report_id',         'result' => 'PRESERVED',  'note' => 'The existing link. Non-null means the unified row already exists.'],
            'item_id'                    => ['target' => 'item_id',           'result' => 'PRESERVED',  'note' => 'Column already present.'],
            'room_id'                    => ['target' => null,                'result' => 'PARTIAL',    'note' => 'No room_id on maintenance_reports. Rendered into free-text location, which is not reversible and not FK-joinable. Task 64 §7.'],
            'department_id'              => ['target' => 'department_id',     'result' => 'PRESERVED',  'note' => 'Column already present.'],
            'source_dispatch_id'         => ['target' => 'source_dispatch_id', 'result' => 'PRESERVED', 'note' => 'Column already present.'],
            'damage_description'         => ['target' => 'description',       'result' => 'PRESERVED',  'note' => 'longtext target, text source.'],
            'severity_level'             => ['target' => 'priority',          'result' => 'PRESERVED',  'note' => 'Lossless: the 4 severity values are a strict subset of the 5 priority values, and DamageReportService.php:208-210 already performs this exact mapping in production.'],
            'reported_by'                => ['target' => 'created_by',        'result' => 'PRESERVED',  'note' => 'Same actor.'],
            'status'                     => ['target' => 'status',            'result' => 'TRANSFORMED', 'note' => 'Six damage states onto six report states. Lossy for repaired/replaced — see resolution.'],
            'image_path'                 => ['target' => null,                'result' => 'LOST',       'note' => 'completion_proof_image is NOT a substitute: it is proof work finished, not evidence of the damage. Task 64 §5.1.'],
            'repair_notes'               => ['target' => null,                'result' => 'LOST',       'note' => 'Task 64 §5.2 specifies repair_notes; the column does not exist yet.'],
            'replacement_item_id'        => ['target' => null,                'result' => 'PRODUCT_DECISION', 'note' => 'Task 64 D3 is explicitly open: reuse need_change_* or add a parallel replacement_* set. Reusing need_change_* would conflate a consumable request with a failed-repair substitution.'],
            'replacement_quantity'       => ['target' => null,                'result' => 'PRODUCT_DECISION', 'note' => 'Same decision D3.'],
            'replacement_transaction_id' => ['target' => null,                'result' => 'PRODUCT_DECISION', 'note' => 'Same decision D3.'],
            'replaced_by'                => ['target' => null,                'result' => 'PRODUCT_DECISION', 'note' => 'Same decision D3.'],
            'replaced_at'                => ['target' => null,                'result' => 'PRODUCT_DECISION', 'note' => 'Same decision D3.'],
            'closed_at'                  => ['target' => null,                'result' => 'LOST',       'note' => 'Task 64 §5.2 folds this into resolved_at, which does not exist yet.'],
            'created_at'                 => ['target' => 'created_at',        'result' => 'PRESERVED',  'note' => ''],
            'updated_at'                 => ['target' => 'updated_at',        'result' => 'PRESERVED',  'note' => ''],
        ];
    }

    /**
     * Every repair_requests column, classified the same way.
     *
     * @return array<string, array<string, string|null>>
     */
    public static function repairRequestFieldMap(): array
    {
        return [
            'id'                        => ['target' => null,             'result' => 'PARTIAL',    'note' => 'Retained in the ledger for traceability.'],
            'repair_code'               => ['target' => null,             'result' => 'LOST',       'note' => 'No target column.'],
            'damage_report_id'          => ['target' => null,             'result' => 'PRESERVED',  'note' => 'Expressed structurally: the repair merges into the damage report own unified row, so the relationship becomes identity.'],
            'report_id'                 => ['target' => 'report_id',      'result' => 'PRESERVED',  'note' => ''],
            'technician_user_id'        => ['target' => null,             'result' => 'LOST',       'note' => 'MUST NOT be written to assigned_to. Task 64 §11: three actors, three different authorization constraints, strictly ordered. Merging widens a security boundary.'],
            'repair_type'               => ['target' => null,             'result' => 'LOST',       'note' => 'Task 64 §5.2 specifies repair_type; not yet present.'],
            'repair_description'        => ['target' => null,             'result' => 'LOST',       'note' => 'Task 64 §5.2 folds this and notes into repair_plan; not yet present.'],
            'repair_cost'               => ['target' => null,             'result' => 'PRODUCT_DECISION', 'note' => 'Task 64 D7: nothing in the system reads this field. Confirm it is still a requirement before carrying it forward.'],
            'repair_status'             => ['target' => 'status',         'result' => 'TRANSFORMED', 'note' => 'Eight states onto six. This is the collapse that blocks records — see work_stage/resolution.'],
            'repair_date'               => ['target' => null,             'result' => 'LOST',       'note' => 'Task 64 §5.2 renames to repair_started_at; not yet present.'],
            'estimated_completion_date' => ['target' => null,             'result' => 'PRODUCT_DECISION', 'note' => 'due_date exists and is semantically close, but Task 64 never specified this mapping. Adopting it silently would invent a business rule, so it is left open.'],
            'completion_date'           => ['target' => 'completed_date', 'result' => 'PRESERVED',  'note' => 'Both are DATE. A genuine existing target.'],
            'notes'                     => ['target' => null,             'result' => 'LOST',       'note' => 'Folded into the unspecified repair_plan.'],
            'failure_reason'            => ['target' => null,             'result' => 'LOST',       'note' => 'The narrative explaining WHY a repair failed. Nothing in the current schema can hold it.'],
            'replacement_item_id'       => ['target' => null,             'result' => 'PRODUCT_DECISION', 'note' => 'Decision D3.'],
            'replacement_quantity'      => ['target' => null,             'result' => 'PRODUCT_DECISION', 'note' => 'Decision D3.'],
            'replacement_dispatch_id'   => ['target' => null,             'result' => 'PRODUCT_DECISION', 'note' => 'Decision D3. Traced in rehearsal_replacement_trace instead.'],
            'replacement_transaction_id' => ['target' => null,            'result' => 'PRODUCT_DECISION', 'note' => 'Decision D3.'],
            'created_by'                => ['target' => null,             'result' => 'LOST',       'note' => 'created_by on the target already holds the damage reporter. Two different people; one column.'],
            'updated_by'                => ['target' => null,             'result' => 'LOST',       'note' => 'Task 64 §5.4 declines to add this — no consumer.'],
            'archived_at'               => ['target' => null,             'result' => 'LOST',       'note' => 'Folded into the unspecified resolved_at.'],
            'created_at'                => ['target' => null,             'result' => 'LOST',       'note' => 'The unified row keeps the damage report timestamps; the repair own creation time has nowhere to go.'],
            'updated_at'                => ['target' => null,             'result' => 'LOST',       'note' => 'As above.'],
        ];
    }

    // -----------------------------------------------------------------------
    // PHASE 6 — the status maps, transcribed from the live services
    // -----------------------------------------------------------------------

    /**
     * damage_reports.status -> maintenance_reports.status.
     *
     * Transcribed verbatim from MaintenanceReportSyncService::DAMAGE_STATUS_MAP.
     * This is the map production uses today, not a proposal.
     */
    public static function damageStatusToReportStatus(string $damageStatus): ?string
    {
        return [
            'pending'      => 'submitted',
            'under_review' => 'assigned',
            'repairing'    => 'in_progress',
            'repaired'     => 'completed',
            'replaced'     => 'completed',
            'closed'       => 'closed',
        ][$damageStatus] ?? null;
    }

    /**
     * repair_status -> damage_reports.status.
     *
     * Transcribed verbatim from RepairService::syncDamageReportStatus(). The
     * `archived` branch is genuinely ambiguous in the source — it resolves
     * against the damage report's CURRENT status — so it takes that as an
     * argument rather than pretending the repair status alone decides.
     */
    public static function repairStatusToDamageStatus(string $repairStatus, string $currentDamageStatus): string
    {
        return match ($repairStatus) {
            'pending', 'assigned', 'diagnosing', 'failed' => 'under_review',
            'repairing', 'waiting_parts' => 'repairing',
            'completed' => 'repaired',
            'archived'  => $currentDamageStatus === 'replaced' ? 'replaced' : 'closed',
            default     => $currentDamageStatus,
        };
    }

    /**
     * The work_stage Task 64 §10 would need to write. Non-null == blocked,
     * because the column does not exist.
     */
    public static function requiredWorkStage(?string $repairStatus): ?string
    {
        return match ($repairStatus) {
            'diagnosing'    => 'diagnosing',
            'waiting_parts' => 'waiting_parts',
            'failed'        => 'failed',
            default         => null,
        };
    }

    /**
     * The resolution Task 64 §10 would need to write. Non-null == blocked.
     *
     * Derived from the DAMAGE status rather than the repair status, matching
     * Task 64 §10 statement 1. That choice matters: it is what stops a damage
     * report closed without any repair (DR 10) from being handed a spurious
     * `repaired` resolution just because its repair request was archived.
     */
    public static function requiredResolution(string $damageStatus): ?string
    {
        return match ($damageStatus) {
            'repaired' => 'repaired',
            'replaced' => 'replaced',
            default    => null,
        };
    }

    // -----------------------------------------------------------------------
    // PHASE 3 — rehearsal-only target structures
    // -----------------------------------------------------------------------

    /**
     * Create the scratch-only tables the rehearsal writes into.
     *
     * Every name is prefixed `rehearsal_` so that no reader, and no future
     * grep, can mistake one of these for a production table. They exist only
     * inside a database whose name starts with sfms_test_scratch_, and they are
     * destroyed with it.
     *
     * DDL is issued OUTSIDE any transaction on purpose: MySQL/MariaDB commits
     * implicitly on DDL, so creating these inside the rollback rehearsal's
     * transaction would silently end that transaction and invalidate the very
     * thing Phase 16 is trying to prove.
     */
    protected function createRehearsalTargetStructures(): void
    {
        // The audit trail of the migration itself: one row per legacy source
        // record, always. The UNIQUE key is what makes Gate F (no unexpected
        // duplicates) an enforced property rather than a hopeful assertion.
        DB::statement('
            CREATE TABLE rehearsal_mapping_ledger (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source_table        VARCHAR(40)  NOT NULL,
                source_id           BIGINT UNSIGNED NOT NULL,
                source_code         VARCHAR(50)  NULL,
                source_status       VARCHAR(30)  NOT NULL,
                decision            VARCHAR(40)  NOT NULL,
                target_action       VARCHAR(40)  NOT NULL,
                target_report_id    INT UNSIGNED NULL,
                target_status       VARCHAR(30)  NULL,
                required_work_stage VARCHAR(30)  NULL,
                required_resolution VARCHAR(30)  NULL,
                lost_fields         TEXT         NULL,
                reason              TEXT         NOT NULL,
                UNIQUE KEY rehearsal_ledger_source_unique (source_table, source_id)
            ) ENGINE=InnoDB
        ');

        // The unified history shape from Task 64 §8. Rehearsal-only: Task 68
        // Phase 11 forbids creating the production table or migrating real
        // history, and permits exactly this for verification.
        DB::statement('
            CREATE TABLE rehearsal_target_report_histories (
                id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                report_id          INT UNSIGNED NOT NULL,
                source_table       VARCHAR(40)  NOT NULL,
                source_id          BIGINT UNSIGNED NOT NULL,
                action_type        VARCHAR(50)  NOT NULL,
                from_status        VARCHAR(30)  NULL,
                to_status          VARCHAR(30)  NULL,
                from_work_stage    VARCHAR(30)  NULL,
                to_work_stage      VARCHAR(30)  NULL,
                technician_user_id INT UNSIGNED NULL,
                notes              TEXT         NULL,
                changed_by         INT UNSIGNED NULL,
                created_at         TIMESTAMP    NULL,
                UNIQUE KEY rehearsal_history_source_unique (source_table, source_id),
                KEY rehearsal_history_report_time (report_id, created_at)
            ) ENGINE=InnoDB
        ');

        // Phase 12: the replacement chain, RECORDED rather than re-executed.
        DB::statement('
            CREATE TABLE rehearsal_replacement_trace (
                id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                damage_report_id         BIGINT UNSIGNED NOT NULL,
                repair_request_id        BIGINT UNSIGNED NULL,
                dispatch_id              BIGINT UNSIGNED NULL,
                dispatch_status          VARCHAR(30)  NULL,
                inventory_transaction_id INT UNSIGNED NULL,
                replacement_item_id      INT UNSIGNED NULL,
                destination_room_id      INT UNSIGNED NULL,
                target_report_id         INT UNSIGNED NULL,
                decision                 VARCHAR(40)  NOT NULL,
                note                     TEXT         NOT NULL,
                UNIQUE KEY rehearsal_replacement_source_unique (damage_report_id)
            ) ENGINE=InnoDB
        ');
    }

    // -----------------------------------------------------------------------
    // PHASE 4 — MAP. Reads only. Writes nothing.
    // -----------------------------------------------------------------------

    /**
     * Produce the complete mapping decision table.
     *
     * This is a PURE READ. Task 68 Phase 4 says "DO NOT transform yet", and the
     * separation is enforced by construction: this method issues SELECTs only,
     * so the map can be reviewed, asserted on, and diffed before a single
     * target row exists.
     *
     * Returns one entry per legacy source record — every damage report and
     * every repair request — keyed "table#id". Gates C and D are the assertion
     * that this covers all of them.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildRehearsalMap(): array
    {
        $damageReports  = DB::table('damage_reports')->orderBy('id')->get();
        $repairRequests = DB::table('repair_requests')->orderBy('id')->get();

        // damage_report_id is UNIQUE on repair_requests (Task 67), so keying by
        // it is safe and gives at most one repair per damage report.
        $repairByDamageReport = $repairRequests->keyBy('damage_report_id');

        $map = [];

        foreach ($damageReports as $damageReport) {
            $repair = $repairByDamageReport->get($damageReport->id);

            $targetStatus = self::damageStatusToReportStatus($damageReport->status);
            $workStage    = self::requiredWorkStage($repair->repair_status ?? null);
            $resolution   = self::requiredResolution($damageReport->status);

            // The whole decision, in one line: if carrying this pair faithfully
            // needs a column the schema does not have, do not carry it.
            $blocked = $workStage !== null || $resolution !== null;

            $decision = $blocked ? self::DECISION_PRODUCT_DECISION : self::DECISION_TRANSFORMED;

            if ($blocked) {
                $targetAction   = self::ACTION_NONE;
                $targetReportId = null;
                $reason         = $this->describeBlock($workStage, $resolution, $repair->repair_status ?? null, $damageReport->status);
            } elseif ($damageReport->report_id !== null) {
                $targetAction   = self::ACTION_RECONCILED;
                $targetReportId = (int) $damageReport->report_id;
                $reason         = 'A unified report already exists (report_id ' . $targetReportId
                    . '). The migration reconciles it rather than creating a duplicate.';
            } else {
                $targetAction   = self::ACTION_CREATED;
                $targetReportId = self::TARGET_REPORT_ID_BASE + (int) $damageReport->id;
                $reason         = 'No unified report exists yet, so the migration creates one.';
            }

            $map['damage_reports#' . $damageReport->id] = [
                'source_table'        => 'damage_reports',
                'source_id'           => (int) $damageReport->id,
                'source_code'         => $damageReport->damage_report_code,
                'source_status'       => $damageReport->status,
                'decision'            => $decision,
                'target_action'       => $targetAction,
                'target_report_id'    => $targetReportId,
                'target_status'       => $blocked ? null : $targetStatus,
                'required_work_stage' => $workStage,
                'required_resolution' => $resolution,
                'lost_fields'         => $this->lostFieldsFor($damageReport, self::damageReportFieldMap()),
                'reason'              => $reason,
            ];
        }

        foreach ($repairRequests as $repair) {
            $parentKey = 'damage_reports#' . $repair->damage_report_id;
            $parent    = $map[$parentKey] ?? null;

            // A repair request has no independent target: it merges into its
            // damage report's unified row. So its fate is its parent's fate,
            // which is also why blocking is computed on the pair.
            $parentTransformed = $parent !== null && $parent['decision'] === self::DECISION_TRANSFORMED;

            $map['repair_requests#' . $repair->id] = [
                'source_table'        => 'repair_requests',
                'source_id'           => (int) $repair->id,
                'source_code'         => $repair->repair_code,
                'source_status'       => $repair->repair_status,
                'decision'            => $parentTransformed ? self::DECISION_TRANSFORMED : self::DECISION_PRODUCT_DECISION,
                'target_action'       => $parentTransformed ? self::ACTION_MERGED : self::ACTION_NONE,
                'target_report_id'    => $parentTransformed ? $parent['target_report_id'] : null,
                'target_status'       => $parentTransformed ? $parent['target_status'] : null,
                'required_work_stage' => $parent['required_work_stage'] ?? null,
                'required_resolution' => $parent['required_resolution'] ?? null,
                'lost_fields'         => $this->lostFieldsFor($repair, self::repairRequestFieldMap()),
                'reason'              => $parentTransformed
                    ? 'Merges into the unified report created for damage report ' . $repair->damage_report_id . '.'
                    : 'Blocked with its damage report ' . $repair->damage_report_id . ': ' . ($parent['reason'] ?? 'parent missing'),
            ];
        }

        ksort($map);

        return $map;
    }

    /**
     * Spell out precisely which distinction would be destroyed, and what it
     * would cost. A ledger that said only "blocked" would be useless to the
     * person who has to make the decision.
     */
    private function describeBlock(?string $workStage, ?string $resolution, ?string $repairStatus, string $damageStatus): string
    {
        $parts = [];

        if ($workStage !== null) {
            $parts[] = "repair_status '{$repairStatus}' needs work_stage '{$workStage}', which has no column; "
                . "the pair would collapse to a status that cannot be told apart from an ordinary one"
                . ($workStage === 'failed'
                    ? ", and 'failed' is the precondition RepairService.php:386 gates replacement on, so the row would silently lose the right to be replaced"
                    : '');
        }

        if ($resolution !== null) {
            $parts[] = "damage status '{$damageStatus}' needs resolution '{$resolution}', which has no column; "
                . "'repaired' and 'replaced' both render as 'completed', so the outcome of the work becomes unrecoverable from the unified row";
        }

        return 'REQUIRES PRODUCT DECISION (Task 64 D1). ' . implode(' Also: ', $parts);
    }

    /**
     * List the populated columns on this row that have no faithful target.
     *
     * Driven by the field map and the row's actual contents rather than a hand
     * written list, so a NULL column is never reported as a loss — losing
     * nothing is not a loss — and adding a column to the fixture cannot quietly
     * escape the accounting.
     *
     * @param  array<string, array<string, string|null>>  $fieldMap
     */
    private function lostFieldsFor(object $row, array $fieldMap): string
    {
        $lost = [];

        foreach ($fieldMap as $column => $spec) {
            if (!in_array($spec['result'], ['LOST', 'PARTIAL', 'PRODUCT_DECISION'], true)) {
                continue;
            }

            if (($row->{$column} ?? null) === null) {
                continue;
            }

            $lost[] = $column;
        }

        sort($lost);

        return implode(',', $lost);
    }

    // -----------------------------------------------------------------------
    // PHASE 13 — TRANSFORM
    // -----------------------------------------------------------------------

    /**
     * Apply the map: write the ledger for every source record, create unified
     * reports for the transformable ones, carry their history, and trace the
     * replacement chains.
     *
     * @param  array<string, array<string, mixed>>  $map
     */
    protected function applyRehearsalTransform(array $map): void
    {
        $this->writeRehearsalLedger($map);
        $this->createRehearsalTargetReports($map);
        $this->carryRehearsalHistory($map);
        $this->traceRehearsalReplacements($map);
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     */
    private function writeRehearsalLedger(array $map): void
    {
        $rows = [];

        foreach ($map as $entry) {
            $rows[] = [
                'source_table'        => $entry['source_table'],
                'source_id'           => $entry['source_id'],
                'source_code'         => $entry['source_code'],
                'source_status'       => $entry['source_status'],
                'decision'            => $entry['decision'],
                'target_action'       => $entry['target_action'],
                'target_report_id'    => $entry['target_report_id'],
                'target_status'       => $entry['target_status'],
                'required_work_stage' => $entry['required_work_stage'],
                'required_resolution' => $entry['required_resolution'],
                'lost_fields'         => $entry['lost_fields'],
                'reason'              => $entry['reason'],
            ];
        }

        DB::table('rehearsal_mapping_ledger')->insert($rows);
    }

    /**
     * Create a unified report for every damage report marked CREATED.
     *
     * RECONCILED rows are deliberately NOT rewritten. Task 64 §10 states the
     * single most important safety property of the backfill: it only fills
     * NULLs, it never overwrites a populated lifecycle value. An existing
     * report's status has been maintained by MaintenanceReportSyncService since
     * Sprint 5 and is already correct; rewriting it to satisfy a mapping table
     * would regress good data. The rehearsal instead VERIFIES agreement — see
     * reconciliationMismatches().
     *
     * @param  array<string, array<string, mixed>>  $map
     */
    private function createRehearsalTargetReports(array $map): void
    {
        $rows = [];

        foreach ($map as $entry) {
            if ($entry['source_table'] !== 'damage_reports' || $entry['target_action'] !== self::ACTION_CREATED) {
                continue;
            }

            $damageReport = DB::table('damage_reports')->where('id', $entry['source_id'])->first();

            $rows[] = [
                'report_id'          => $entry['target_report_id'],
                'title'              => 'Damage Report: ' . $damageReport->damage_report_code,
                'description'        => $damageReport->damage_description,
                'report_category'    => 'repair_replacement',
                'item_id'            => $damageReport->item_id,
                'source_dispatch_id' => $damageReport->source_dispatch_id,
                'location'           => $this->locationTextFor($damageReport->room_id),
                'priority'           => $damageReport->severity_level,
                'status'             => $entry['target_status'],
                'created_by'         => $damageReport->reported_by,
                // Never technician_user_id. Task 64 §11 — different actor,
                // strictly wider authorization constraint.
                'assigned_to'        => null,
                'department_id'      => $damageReport->department_id,
                'completed_date'     => $this->completionDateFor($entry['source_id']),
                'created_at'         => $damageReport->created_at,
                'updated_at'         => $damageReport->updated_at,
            ];
        }

        if ($rows !== []) {
            DB::table('maintenance_reports')->insert($rows);
        }
    }

    /**
     * PHASE 8 — the structured room reference, flattened.
     *
     * This is the lossy step made explicit. A room_id is an integer FK into a
     * table of rooms; what comes back is a string. It cannot be joined, cannot
     * be filtered by AnalyticsService::damagedItems(), and cannot be turned
     * back into an id without parsing text. A NULL room stays NULL rather than
     * becoming the string 'Unknown', because inventing a location is worse than
     * admitting there isn't one.
     */
    private function locationTextFor(?int $roomId): ?string
    {
        if ($roomId === null) {
            return null;
        }

        $room = DB::table('rooms')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('rooms.id', $roomId)
            ->select('rooms.name as room_name', 'buildings.name as building_name')
            ->first();

        if ($room === null) {
            return null;
        }

        return $room->building_name . ' / ' . $room->room_name;
    }

    /**
     * completion_date is one of the few genuine field targets, and it lives on
     * the repair request rather than the damage report.
     */
    private function completionDateFor(int $damageReportId): ?string
    {
        return DB::table('repair_requests')
            ->where('damage_report_id', $damageReportId)
            ->value('completion_date');
    }

    /**
     * PHASE 11 — carry the audit trail for reports that were transformed.
     *
     * History for a blocked record is NOT carried. That is the honest
     * consequence of blocking the record: you cannot migrate the story of a row
     * you refused to migrate.
     *
     * @param  array<string, array<string, mixed>>  $map
     */
    private function carryRehearsalHistory(array $map): void
    {
        $rows = [];

        foreach ($map as $entry) {
            if ($entry['decision'] !== self::DECISION_TRANSFORMED || $entry['target_report_id'] === null) {
                continue;
            }

            if ($entry['source_table'] === 'damage_reports') {
                $events = DB::table('damage_report_histories')
                    ->where('damage_report_id', $entry['source_id'])
                    ->orderBy('id')
                    ->get();

                foreach ($events as $event) {
                    $rows[] = [
                        'report_id'          => $entry['target_report_id'],
                        'source_table'       => 'damage_report_histories',
                        'source_id'          => (int) $event->id,
                        'action_type'        => $event->action_type,
                        // Statuses are rewritten into the target vocabulary;
                        // leaving them in damage vocabulary would make the
                        // unified trail unreadable next to native events.
                        'from_status'        => $event->from_status === null ? null : self::damageStatusToReportStatus($event->from_status),
                        'to_status'          => $event->to_status === null ? null : self::damageStatusToReportStatus($event->to_status),
                        'from_work_stage'    => null,
                        'to_work_stage'      => null,
                        'technician_user_id' => null,
                        'notes'              => $event->notes,
                        'changed_by'         => $event->changed_by,
                        'created_at'         => $event->created_at,
                    ];
                }
            }

            if ($entry['source_table'] === 'repair_requests') {
                $events = DB::table('repair_histories')
                    ->where('repair_request_id', $entry['source_id'])
                    ->orderBy('id')
                    ->get();

                foreach ($events as $event) {
                    $damageStatus = DB::table('repair_requests')
                        ->join('damage_reports', 'damage_reports.id', '=', 'repair_requests.damage_report_id')
                        ->where('repair_requests.id', $entry['source_id'])
                        ->value('damage_reports.status');

                    $rows[] = [
                        'report_id'          => $entry['target_report_id'],
                        'source_table'       => 'repair_histories',
                        'source_id'          => (int) $event->id,
                        'action_type'        => $event->action_type,
                        'from_status'        => $this->repairHistoryStatusToTarget($event->from_status, $damageStatus),
                        'to_status'          => $this->repairHistoryStatusToTarget($event->to_status, $damageStatus),
                        'from_work_stage'    => self::requiredWorkStage($event->from_status),
                        'to_work_stage'      => self::requiredWorkStage($event->to_status),
                        'technician_user_id' => $event->technician_user_id,
                        'notes'              => $event->notes,
                        'changed_by'         => $event->changed_by,
                        'created_at'         => $event->created_at,
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('rehearsal_target_report_histories')->insert($rows);
        }
    }

    /**
     * Two hops, applied to a history row's status: repair -> damage -> report.
     */
    private function repairHistoryStatusToTarget(?string $repairStatus, ?string $damageStatus): ?string
    {
        if ($repairStatus === null) {
            return null;
        }

        return self::damageStatusToReportStatus(
            self::repairStatusToDamageStatus($repairStatus, $damageStatus ?? 'pending')
        );
    }

    /**
     * PHASE 12 — record every replacement chain that exists in the dataset.
     *
     * Nothing here executes. There is no call to fulfillReplacement(), no
     * dispatch release, no InventoryTransaction creation, and no write to
     * items at all. The chain
     *
     *     damage report -> repair request -> dispatch -> inventory transaction
     *
     * is READ and written down, so that a future migration can be checked
     * against a statement of what the links were before it ran.
     *
     * @param  array<string, array<string, mixed>>  $map
     */
    private function traceRehearsalReplacements(array $map): void
    {
        $rows = [];

        $repairs = DB::table('repair_requests')
            ->whereNotNull('replacement_item_id')
            ->orderBy('id')
            ->get();

        foreach ($repairs as $repair) {
            $dispatch = $repair->replacement_dispatch_id === null
                ? null
                : DB::table('dispatches')->where('id', $repair->replacement_dispatch_id)->first();

            $entry = $map['damage_reports#' . $repair->damage_report_id] ?? null;

            $rows[] = [
                'damage_report_id'         => (int) $repair->damage_report_id,
                'repair_request_id'        => (int) $repair->id,
                'dispatch_id'              => $repair->replacement_dispatch_id,
                'dispatch_status'          => $dispatch->status ?? null,
                'inventory_transaction_id' => $repair->replacement_transaction_id,
                'replacement_item_id'      => $repair->replacement_item_id,
                'destination_room_id'      => $dispatch->room_id ?? null,
                'target_report_id'         => $entry['target_report_id'] ?? null,
                'decision'                 => $entry['decision'] ?? self::DECISION_PRODUCT_DECISION,
                'note'                     => 'Chain recorded, not executed. No dispatch was released, no '
                    . 'InventoryTransaction was created, and items.quantity was not read or written.',
            ];
        }

        if ($rows !== []) {
            DB::table('rehearsal_replacement_trace')->insert($rows);
        }
    }

    // -----------------------------------------------------------------------
    // PHASE 14/15 — VALIDATE
    // -----------------------------------------------------------------------

    /**
     * Source vs mapped vs transformed vs unmapped vs failed.
     *
     * @return array<string, int>
     */
    protected function rehearsalConservation(): array
    {
        $sourceDamage = DB::table('damage_reports')->count();
        $sourceRepair = DB::table('repair_requests')->count();
        $ledger       = DB::table('rehearsal_mapping_ledger');

        return [
            'source_records'      => $sourceDamage + $sourceRepair,
            'source_damage'       => $sourceDamage,
            'source_repair'       => $sourceRepair,
            'mapped_records'      => (clone $ledger)->count(),
            'transformed'         => (clone $ledger)->where('decision', self::DECISION_TRANSFORMED)->count(),
            'product_decision'    => (clone $ledger)->where('decision', self::DECISION_PRODUCT_DECISION)->count(),
            'target_created'      => (clone $ledger)->where('target_action', self::ACTION_CREATED)->count(),
            'target_reconciled'   => (clone $ledger)->where('target_action', self::ACTION_RECONCILED)->count(),
            'target_merged'       => (clone $ledger)->where('target_action', self::ACTION_MERGED)->count(),
            // A source record with no ledger row at all. This is the number
            // that must be zero: it is the definition of a SILENT loss, as
            // opposed to a loss that was recorded and refused.
            'unmapped'            => ($sourceDamage + $sourceRepair) - (clone $ledger)->count(),
            'failed'              => (clone $ledger)->whereNotIn('decision', [self::DECISION_TRANSFORMED, self::DECISION_PRODUCT_DECISION])->count(),
        ];
    }

    /**
     * Every damage report whose already-existing unified report disagrees with
     * the status the mapping would derive.
     *
     * A non-empty result would mean the live sync and the migration's own map
     * do not agree about the same row — which would have to be resolved before
     * any real migration, since one of the two is wrong.
     *
     * @return array<int, string>
     */
    protected function reconciliationMismatches(): array
    {
        $mismatches = [];

        $rows = DB::table('rehearsal_mapping_ledger')
            ->where('target_action', self::ACTION_RECONCILED)
            ->orderBy('source_id')
            ->get();

        foreach ($rows as $row) {
            $actual = DB::table('maintenance_reports')
                ->where('report_id', $row->target_report_id)
                ->value('status');

            if ($actual !== $row->target_status) {
                $mismatches[] = "damage_reports#{$row->source_id} -> report {$row->target_report_id}: "
                    . "existing status '{$actual}' but the map derives '{$row->target_status}'";
            }
        }

        return $mismatches;
    }

    /**
     * PHASE 11 — history events that share a parent AND a second.
     *
     * Timestamps in both legacy trails are second-granularity, so a merge that
     * orders purely by created_at has no defined order for these. Returning
     * them makes the hazard measurable instead of theoretical.
     *
     * @return array<int, string>
     */
    protected function sameSecondHistoryCollisions(): array
    {
        $collisions = [];

        foreach ([
            'damage_report_histories' => 'damage_report_id',
            'repair_histories'        => 'repair_request_id',
        ] as $table => $parent) {
            $rows = DB::table($table)
                ->select($parent, 'created_at', DB::raw('COUNT(*) as total'))
                ->groupBy($parent, 'created_at')
                ->having('total', '>', 1)
                ->orderBy($parent)
                ->get();

            foreach ($rows as $row) {
                $collisions[] = "{$table}: {$row->total} events for {$parent}={$row->{$parent}} share created_at {$row->created_at}";
            }
        }

        return $collisions;
    }

    /**
     * A canonical, order-stable fingerprint of everything the rehearsal
     * produced. Two runs are equivalent if and only if these match.
     *
     * Deliberately covers the ledger, the created target reports AND the
     * carried history, so that a difference anywhere in the output is caught
     * rather than only a difference in the summary counts.
     */
    protected function rehearsalDigest(): string
    {
        $ledger = DB::table('rehearsal_mapping_ledger')
            ->orderBy('source_table')->orderBy('source_id')
            ->get([
                'source_table', 'source_id', 'source_code', 'source_status', 'decision',
                'target_action', 'target_report_id', 'target_status',
                'required_work_stage', 'required_resolution', 'lost_fields',
            ])->toArray();

        $reports = DB::table('maintenance_reports')
            ->where('report_id', '>=', self::TARGET_REPORT_ID_BASE)
            ->orderBy('report_id')
            ->get([
                'report_id', 'title', 'description', 'report_category', 'item_id',
                'location', 'priority', 'status', 'created_by', 'assigned_to',
                'department_id', 'completed_date', 'created_at', 'updated_at',
            ])->toArray();

        $histories = DB::table('rehearsal_target_report_histories')
            ->orderBy('source_table')->orderBy('source_id')
            ->get([
                'report_id', 'source_table', 'source_id', 'action_type',
                'from_status', 'to_status', 'from_work_stage', 'to_work_stage',
                'technician_user_id', 'changed_by', 'created_at',
            ])->toArray();

        $trace = DB::table('rehearsal_replacement_trace')
            ->orderBy('damage_report_id')
            ->get([
                'damage_report_id', 'repair_request_id', 'dispatch_id', 'dispatch_status',
                'inventory_transaction_id', 'replacement_item_id', 'destination_room_id',
                'target_report_id', 'decision',
            ])->toArray();

        return hash('sha256', json_encode([
            'ledger'    => $ledger,
            'reports'   => $reports,
            'histories' => $histories,
            'trace'     => $trace,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A fingerprint of the LEGACY tables, used to prove the transform did not
     * disturb its own inputs and that a rollback restored them exactly.
     */
    protected function legacySourceDigest(): string
    {
        $payload = [];

        foreach ([
            'damage_reports'          => 'id',
            'repair_requests'         => 'id',
            'damage_report_histories' => 'id',
            'repair_histories'        => 'id',
            'dispatches'              => 'id',
            'dispatch_items'          => 'id',
            'inventory_transactions'  => 'id',
            'items'                   => 'id',
            'notifications'           => 'id',
        ] as $table => $key) {
            $payload[$table] = DB::table($table)->orderBy($key)->get()->toArray();
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
