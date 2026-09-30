<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\TestCase;

/**
 * TASK 66 — the guard that stops the migration chain becoming unreproducible again.
 *
 * WHY THIS EXISTS
 * ---------------
 * Task 65 found that `php artisan migrate` against an empty database could not
 * rebuild this project's schema: it aborted at
 * 2026_08_15_000100_add_username_to_users_table on a `->after('employee_id')`
 * hint naming a column no migration creates. Because `username` is the sole
 * login identifier (AuthController::login()), a fresh install had no working
 * authentication at all.
 *
 * The reason that went unnoticed for so long is structural, and it is the real
 * thing this test defends against: the Feature suite builds its schema by hand
 * (tests/Support/BuildsSharedTestSchema.php) rather than from migrations. So
 * the migrations had NO executed coverage whatsoever — every test could stay
 * green while the chain rotted. Nothing in the repository would have noticed.
 *
 * This test closes that hole by running the REAL chain and asserting on the
 * schema it actually produces.
 *
 * SAFETY
 * ------
 * Everything happens inside a disposable scratch database created by
 * ConnectsToScratchMySqlDatabase, whose name must match
 * /^sfms_test_scratch_[a-z0-9_]+$/ — a pattern the real application database
 * cannot satisfy — asserted immediately before every CREATE and every DROP.
 * The real database is never opened, read, named, or migrated. The scratch
 * name is fixed and derived from this class, NOT from any developer's local
 * database name, so the test is portable.
 *
 * It skips (rather than fails) when MySQL is unreachable, so a developer on
 * SQLite-only still gets a green suite. The alternative — a hard failure —
 * would pressure someone into deleting the test, which is exactly how the
 * coverage gap above was created in the first place.
 */
class MigrationChainReproducibilityTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;

    /**
     * Every value maintenance_reports.need_change_status legitimately holds.
     *
     * 'rejected' is the important one: ReportController::update() writes it and
     * NeedChangeService::approve() reads it back as the guard that stops a
     * rejected Need Change from ever deducting inventory. It was absent from
     * the migration's original ENUM, so on a freshly migrated database that
     * guard could never match — a silent inventory-integrity failure.
     */
    private const NEED_CHANGE_STATUSES = ['pending', 'approved', 'deducted', 'failed', 'rejected'];

    protected function setUp(): void
    {
        parent::setUp();

        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped('Migration reproducibility check skipped. ' . $reason);
        }

        $this->useScratchMySqlDatabase('migration_chain');

        // The whole point: build the schema from the repository's migration
        // history, not from a hand-written helper.
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        $this->dropScratchMySqlDatabase();

        parent::tearDown();
    }

    /**
     * GATE A — a fresh database can complete the entire migration chain.
     */
    public function test_migration_chain_completes_against_an_empty_database(): void
    {
        $this->assertSame(
            'sfms_test_scratch_migration_chain',
            DB::connection()->getDatabaseName(),
            'Sanity: the chain must have run against the scratch database, never the real one.'
        );

        $applied = DB::table('migrations')->count();
        $onDisk  = count(glob(database_path('migrations') . DIRECTORY_SEPARATOR . '*.php'));

        $this->assertSame(
            $onDisk,
            $applied,
            'Every migration file on disk must have been applied. A shortfall means the '
            . 'chain aborted partway — which is exactly the Task 65 blocker this test guards.'
        );
    }

    /**
     * GATE B — the ledger is coherent: no pending rows, and no ledger entry
     * pointing at a migration file that no longer exists.
     */
    public function test_migration_ledger_is_coherent(): void
    {
        $ledger = DB::table('migrations')->pluck('migration')->all();

        $files = array_map(
            static fn (string $path): string => basename($path, '.php'),
            glob(database_path('migrations') . DIRECTORY_SEPARATOR . '*.php')
        );

        $this->assertSame(
            [],
            array_values(array_diff($files, $ledger)),
            'These migration files exist but were never applied — the chain is incomplete.'
        );

        $this->assertSame(
            [],
            array_values(array_diff($ledger, $files)),
            'These ledger rows name migrations with no file on disk. On a freshly built '
            . 'database that should be impossible, and would mean the ledger is lying.'
        );

        $this->assertSame(
            [1],
            array_values(array_unique(DB::table('migrations')->pluck('batch')->all())),
            'A from-empty build should record exactly one batch.'
        );
    }

    /**
     * GATE C — the columns the application actually reads and writes exist.
     *
     * Each of these was missing from the migration set and present only in the
     * live database, having been added out-of-band. Absent them, a fresh
     * install breaks in a specific, named way.
     */
    public function test_columns_the_application_depends_on_are_created(): void
    {
        $required = [
            // Sole login identifier — AuthController::login().
            'users.username',
            // Validated and inserted by UserController::store().
            'users.designation',
            // Written by UserController::store()/resetPassword(); read to decide
            // whether to force first-login profile setup.
            'users.force_profile_update',
            // MaintenanceReport::$fillable; written by ReportController on
            // completion-proof upload.
            'maintenance_reports.completion_proof_image',
        ];

        foreach ($required as $qualified) {
            [$table, $column] = explode('.', $qualified);

            $this->assertTrue(
                \Schema::hasColumn($table, $column),
                "{$qualified} is written or read by application code but the migration "
                . 'chain does not create it. A fresh install would fail at runtime.'
            );
        }
    }

    /**
     * GATE D + E — need_change_status can hold every value the application
     * uses, INCLUDING 'rejected', under the same strict sql_mode Laravel's own
     * connection sets. This is the assertion that would have failed before
     * Task 66.
     */
    public function test_need_change_status_stores_every_value_the_application_writes(): void
    {
        $this->assertSame(
            'varchar(50)',
            $this->columnType('maintenance_reports', 'need_change_status'),
            'need_change_status must be varchar(50) — the representation the live database '
            . 'uses and the one that accommodates every state without another ALTER.'
        );

        $this->assertStrictModeIsActive();

        $this->seedMinimalReportPrerequisites();

        foreach (self::NEED_CHANGE_STATUSES as $index => $status) {
            $reportId = $index + 1;

            DB::table('maintenance_reports')->insert([
                'report_id'            => $reportId,
                'title'                => 'Need Change probe',
                'description'          => 'Probe row',
                'status'               => 'submitted',
                'created_by'           => 1,
                'need_change_status'   => $status,
                'need_change_quantity' => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            $this->assertSame(
                $status,
                DB::table('maintenance_reports')->where('report_id', $reportId)->value('need_change_status'),
                "need_change_status '{$status}' must round-trip intact. A silent coercion to '' "
                . "here is worse than an error: NeedChangeService::approve()'s 'rejected' guard "
                . 'would stop matching and a rejected request would become approvable.'
            );
        }
    }

    /**
     * need_change_quantity must accept NULL: ReportController validates it as
     * nullable and persists the validated value, so clearing the Edit Report
     * "Need Change" toggle writes NULL.
     */
    public function test_need_change_quantity_accepts_null(): void
    {
        $this->assertStrictModeIsActive();
        $this->seedMinimalReportPrerequisites();

        DB::table('maintenance_reports')->insert([
            'report_id'            => 1,
            'title'                => 'Cleared need change',
            'description'          => 'Probe row',
            'status'               => 'submitted',
            'created_by'           => 1,
            'need_change_item_id'  => null,
            'need_change_quantity' => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->assertNull(
            DB::table('maintenance_reports')->where('report_id', 1)->value('need_change_quantity'),
            'Turning the Need Change toggle off persists NULL; a NOT NULL column rejects that.'
        );
    }

    /**
     * users.email must be nullable: UserController::store() explicitly persists
     * null when an administrator leaves the field blank (username, not email,
     * has been the login identifier since Task 81 Part 1).
     */
    public function test_users_email_is_nullable(): void
    {
        $this->assertStrictModeIsActive();

        DB::table('users')->insert([
            'user_id'    => 1,
            'full_name'  => 'No Email Administrator',
            'username'   => 'no_email_admin',
            'email'      => null,
            'password'   => 'hashed',
            'role'       => 'super_admin',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(
            DB::table('users')->where('user_id', 1)->value('email'),
            'An administrator-created user with no email address must be storable.'
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Guards the value of every other assertion here. Under a non-strict
     * session MySQL coerces bad values instead of rejecting them, so a
     * "successful" insert would prove nothing at all.
     */
    private function assertStrictModeIsActive(): void
    {
        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode,
            "The connection must be in strict mode for these assertions to mean anything; "
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

    /**
     * The minimum rows maintenance_reports' foreign keys require.
     */
    private function seedMinimalReportPrerequisites(): void
    {
        DB::table('departments')->insert([
            'department_id' => 1,
            'name'          => 'Probe Department',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('users')->insert([
            'user_id'       => 1,
            'full_name'     => 'Probe Reporter',
            'username'      => 'probe_reporter',
            'email'         => 'probe@example.test',
            'password'      => 'hashed',
            'role'          => 'super_admin',
            'status'        => 'active',
            'department_id' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}
