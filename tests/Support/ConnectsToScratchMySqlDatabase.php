<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * TASK 65 PHASE 3 — run a test against a REAL MySQL/MariaDB server.
 *
 * WHY THIS EXISTS
 * ---------------
 * The whole Feature suite runs on in-memory SQLite (see phpunit.xml and
 * ConfiguresIsolatedSqliteConnection). That is fine for the ~95% of the
 * codebase that uses the query builder portably, but AnalyticsService issues
 * raw SQL built from MySQL-only functions — DATE_FORMAT(), DATE_SUB(),
 * CURDATE(), MONTH(), YEAR(), CONCAT() — which SQLite cannot parse at all.
 * Those code paths therefore had ZERO executed coverage: nothing in the suite
 * could ever run them, so a syntax or semantic error in them would ship
 * undetected.
 *
 * This trait provides a DISPOSABLE, SELF-BUILT database on the same server so
 * that SQL can actually be executed.
 *
 * SAFETY MODEL — the important part
 * ---------------------------------
 * The obvious way to do this (point tests at the real database) is exactly
 * what must not happen. Four independent guards prevent it:
 *
 *  1. NAME ALLOWLIST. The scratch database name must match
 *     /^sfms_test_scratch_[a-z0-9_]+$/. This is asserted immediately before
 *     every CREATE and every DROP. The real database ('school_facility_
 *     maintenance') cannot match that pattern, so a DROP can never be aimed
 *     at it — even if this trait were called with a hand-edited name.
 *
 *  2. WE ONLY DROP WHAT WE CREATED. $created is set only after a successful
 *     CREATE DATABASE issued by this trait. tearDown drops nothing otherwise.
 *
 *  3. NO SCHEMA IS COPIED FROM, AND NO CONNECTION IS OPENED TO, THE REAL
 *     DATABASE. The caller declares the tables it needs. This test is
 *     hermetic: it does not read, write, lock, or even name application data.
 *
 *  4. CREDENTIALS ARE NOT HARDCODED. Host/port/user/password come from the
 *     existing config, i.e. from .env, the same way the application resolves
 *     them. Only the DATABASE NAME is overridden — with a name guard #1
 *     guarantees is disposable.
 *
 * GRACEFUL DEGRADATION
 * --------------------
 * mysqlUnavailableReason() returns a human-readable string when the server
 * cannot be reached or lacks CREATE DATABASE rights, so the caller can
 * markTestSkipped() instead of failing. A developer without MySQL running,
 * or a CI box with SQLite only, still gets a green suite — the coverage is
 * opportunistic by design, because the alternative (a hard failure) would
 * pressure someone into deleting the test.
 *
 * WHY NOT JUST RUN THE MIGRATIONS?
 * --------------------------------
 * HISTORICAL (Task 65): running them was attempted first and did not work.
 * `php artisan migrate` against an empty database failed at
 * 2026_08_15_000100_add_username_to_users_table with "Unknown column
 * 'employee_id' in 'users'" — the migration set assumed a column no migration
 * in the repository created. The schema was therefore NOT reproducible from
 * migrations, which is why the callers of this trait declare their tables
 * directly.
 *
 * RESOLVED (Task 66): the migration chain now completes against an empty
 * database and is verified to keep doing so by
 * tests/Feature/MigrationChainReproducibilityTest.php, which runs the real
 * chain inside a scratch database created by this very trait.
 *
 * The existing callers still declare their own tables, and that remains the
 * right choice for them: they are testing SQL behaviour, not schema
 * provenance, and hand-declaring keeps them fast and independent of migration
 * churn. Use migrations when the schema itself is what is under test.
 */
trait ConnectsToScratchMySqlDatabase
{
    /** Enforced on every CREATE and DROP. See guard #1 above. */
    private const SCRATCH_NAME_PATTERN = '/^sfms_test_scratch_[a-z0-9_]+$/';

    private ?string $scratchDatabase = null;

    private ?string $scratchConnection = null;

    private bool $scratchDatabaseWasCreatedByThisTest = false;

    /**
     * @return string|null null when MySQL IS usable; otherwise the reason to
     *                     hand to markTestSkipped().
     */
    protected function mysqlUnavailableReason(): ?string
    {
        if (!extension_loaded('pdo_mysql')) {
            return 'The pdo_mysql extension is not loaded.';
        }

        try {
            $pdo = $this->serverPdo();
        } catch (Throwable $e) {
            return 'Could not connect to the MySQL server: ' . $e->getMessage();
        }

        try {
            // Probe the one privilege this trait actually needs, using a name
            // that satisfies the allowlist so the probe itself is disposable.
            $probe = 'sfms_test_scratch_privilege_probe';
            $this->assertScratchNameIsDisposable($probe);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$probe}`");
            $pdo->exec("DROP DATABASE IF EXISTS `{$probe}`");
        } catch (Throwable $e) {
            return 'The configured MySQL user cannot CREATE/DROP databases: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * Create the scratch database, register it as a Laravel connection, and
     * make it the default so the code under test uses it with no awareness
     * that it is being tested.
     */
    protected function useScratchMySqlDatabase(string $suffix): void
    {
        $database = 'sfms_test_scratch_' . $suffix;
        $this->assertScratchNameIsDisposable($database);

        $pdo = $this->serverPdo();

        // A leftover from a previously crashed run is ours by name convention
        // (guard #1 proves the name is disposable), so reclaim it rather than
        // inheriting stale rows.
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->scratchDatabase = $database;
        $this->scratchDatabaseWasCreatedByThisTest = true;

        $connection = 'scratch_' . $suffix;
        $this->scratchConnection = $connection;

        $config = Config::get('database.connections.mysql');
        $config['database'] = $database;
        // phpunit.xml sets DB_URL to the empty string; a present-but-empty url
        // would otherwise be parsed instead of the discrete host/port fields.
        unset($config['url']);

        Config::set("database.connections.{$connection}", $config);
        Config::set('database.default', $connection);
        Config::set('session.driver', 'array');
        Config::set('cache.default', 'array');

        DB::purge($connection);
        DB::setDefaultConnection($connection);
        DB::reconnect($connection);
    }

    /**
     * Drop the scratch database. Safe to call unconditionally — it is a no-op
     * unless this test actually created one (guard #2).
     */
    protected function dropScratchMySqlDatabase(): void
    {
        if (!$this->scratchDatabaseWasCreatedByThisTest || $this->scratchDatabase === null) {
            return;
        }

        $database = $this->scratchDatabase;
        $this->assertScratchNameIsDisposable($database);

        if ($this->scratchConnection !== null) {
            DB::purge($this->scratchConnection);
        }

        try {
            $this->serverPdo()->exec("DROP DATABASE IF EXISTS `{$database}`");
        } catch (Throwable $e) {
            // Leaving a scratch database behind is untidy but harmless, and
            // the next run reclaims it. Never fail a test during cleanup.
        }

        $this->scratchDatabase = null;
        $this->scratchDatabaseWasCreatedByThisTest = false;
    }

    /**
     * A server-level connection with NO database selected — it is structurally
     * incapable of touching application tables.
     */
    private function serverPdo(): PDO
    {
        $config = Config::get('database.connections.mysql');

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '3306';

        return new PDO(
            "mysql:host={$host};port={$port}",
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Guard #1. The single chokepoint every CREATE and DROP passes through.
     */
    private function assertScratchNameIsDisposable(string $database): void
    {
        if (preg_match(self::SCRATCH_NAME_PATTERN, $database) !== 1) {
            throw new \RuntimeException(
                "Refusing to create or drop the database [{$database}]: scratch "
                . 'databases must match ' . self::SCRATCH_NAME_PATTERN . '. This guard '
                . 'exists so a DROP in the test suite can never be aimed at a real database.'
            );
        }
    }
}
