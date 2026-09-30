<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\TestCase;

/**
 * TASK 65 PHASE 5 — the need_change_status drift, pinned as executable evidence.
 * TASK 66 — the drift is now RESOLVED. See "STATUS" below.
 *
 * WHAT THE DRIFT WAS
 * ------------------
 * Three sources disagreed about the type of maintenance_reports.need_change_status:
 *
 *   database/migrations/2026_04_07_000700_add_need_change_fields_to_
 *   maintenance_reports_table.php line 24
 *       -> enum('pending','approved','deducted','failed'), nullable
 *
 *   The LIVE database (verified via information_schema)
 *       -> varchar(50), nullable
 *
 *   The APPLICATION
 *       -> ReportController::update() line 697 writes 'rejected',
 *          a fifth value absent from the migration's enum.
 *
 * The live column is varchar, so 'rejected' stored fine and there was no live
 * defect — which is why Task 65 recorded the drift rather than changing schema.
 *
 * WHY IT WAS STILL DANGEROUS
 * --------------------------
 * The migration was a false description of a schema that already existed
 * everywhere (recorded as applied, batch 3). The columns were in fact created
 * by a raw ALTER TABLE script (migrate_need_change.php, since deleted — see
 * FINAL_RELEASE_QA.md), which is why the types differed; the migration's own
 * `if (!Schema::hasColumn(...))` guards then made it a no-op.
 *
 * So whenever the schema was rebuilt from migrations, the column WOULD be
 * created as the enum, and two things broke at once:
 *
 *   1. Under Laravel's strict sql_mode, writing 'rejected' throws — the
 *      reject action starts failing outright.
 *   2. Worse, NeedChangeService::approve() line 75 guards on
 *      `need_change_status === 'rejected'` to stop a rejected request from
 *      ever deducting inventory. If that value can never be stored, the
 *      guard can never match, and a rejected Need Change becomes approvable
 *      — a silent inventory-integrity failure, not a loud one.
 *
 * STATUS AFTER TASK 66
 * --------------------
 * 2026_09_08_000200_align_need_change_columns_with_application_behavior.php
 * converts the column to VARCHAR(50) — converging the migration on the live
 * database rather than retyping a production column — so a freshly migrated
 * database now stores all five values, 'rejected' included. That outcome is
 * asserted against the REAL migrated schema by
 * tests/Feature/MigrationChainReproducibilityTest.php.
 *
 * THIS TEST IS DELIBERATELY KEPT. It does not test the migration; it tests the
 * two type choices in isolation, and so remains the standing proof of WHY
 * varchar was chosen over widening the enum. If someone later proposes
 * converting the column back to an ENUM, the first test below is the argument
 * against it, still executable.
 *
 * Nothing in the application or the database is modified by this test — it
 * builds each candidate definition in a disposable scratch database.
 */
class NeedChangeStatusSchemaDriftTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;

    /**
     * Exactly as declared by the migration at line 24.
     */
    private const MIGRATION_DECLARED_ENUM = ['pending', 'approved', 'deducted', 'failed'];

    /**
     * Written by ReportController::update() line 697 and read back as a
     * business-rule guard by NeedChangeService::approve() line 75.
     */
    private const VALUE_THE_APPLICATION_WRITES = 'rejected';

    protected function setUp(): void
    {
        parent::setUp();

        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped('need_change_status drift proof skipped. ' . $reason);
        }

        $this->useScratchMySqlDatabase('need_change_drift');
    }

    protected function tearDown(): void
    {
        $this->dropScratchMySqlDatabase();

        parent::tearDown();
    }

    /**
     * The migration's enum cannot hold the value the application writes.
     */
    public function test_migration_declared_enum_cannot_store_the_rejected_status(): void
    {
        $this->assertNotContains(
            self::VALUE_THE_APPLICATION_WRITES,
            self::MIGRATION_DECLARED_ENUM,
            'Precondition: the migration enum is missing the value the application writes.'
        );

        $members = implode("','", self::MIGRATION_DECLARED_ENUM);

        DB::statement("
            CREATE TABLE need_change_enum_probe (
                report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                need_change_status ENUM('{$members}') NULL
            ) ENGINE=InnoDB
        ");

        $threw = false;
        try {
            DB::table('need_change_enum_probe')->insert([
                'need_change_status' => self::VALUE_THE_APPLICATION_WRITES,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            "If maintenance_reports.need_change_status were built from the migration, "
            . "ReportController's reject_need_change branch would throw on every rejection."
        );

        // The four declared values are unaffected — this is specifically about
        // the missing fifth, not a broken enum.
        foreach (self::MIGRATION_DECLARED_ENUM as $valid) {
            DB::table('need_change_enum_probe')->insert(['need_change_status' => $valid]);
        }

        $this->assertSame(
            count(self::MIGRATION_DECLARED_ENUM),
            DB::table('need_change_enum_probe')->count(),
            'All four declared values must store normally.'
        );
    }

    /**
     * The live column's actual type (varchar(50)) is what keeps the system
     * working today. Proving the permissive type accepts 'rejected' is what
     * establishes that the current absence of a defect is a property of the
     * DATABASE, not of the code — the code is writing a value its own
     * migration forbids, and only the drift is saving it.
     */
    public function test_live_column_type_is_what_currently_prevents_the_defect(): void
    {
        DB::statement('
            CREATE TABLE need_change_varchar_probe (
                report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                need_change_status VARCHAR(50) NULL
            ) ENGINE=InnoDB
        ');

        DB::table('need_change_varchar_probe')->insert([
            'need_change_status' => self::VALUE_THE_APPLICATION_WRITES,
        ]);

        $this->assertSame(
            self::VALUE_THE_APPLICATION_WRITES,
            DB::table('need_change_varchar_probe')->value('need_change_status'),
            'varchar(50) — the live type — round-trips the value intact and unmodified.'
        );
    }
}
