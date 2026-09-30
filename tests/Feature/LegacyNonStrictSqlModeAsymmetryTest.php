<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\TestCase;

/**
 * TASK 66 PHASE 9 — the legacy/Laravel sql_mode asymmetry, pinned as evidence.
 *
 * THE ASYMMETRY
 * -------------
 * The two stacks in this project connect to the SAME database with DIFFERENT
 * strictness, and neither one says so out loud:
 *
 *   config/database.php (Laravel)
 *       -> 'strict' => true, so Laravel issues its own SET SESSION sql_mode
 *          including STRICT_TRANS_TABLES on every connection.
 *
 *   public/backend/config/database.php::getDBConnection() (legacy)
 *       -> sets ONLY ATTR_ERRMODE, ATTR_DEFAULT_FETCH_MODE and
 *          ATTR_EMULATE_PREPARES. No sql_mode, no init command. It therefore
 *          silently inherits whatever the server's global sql_mode happens to
 *          be — which on this project's server (MariaDB 10.4.32) is
 *          NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION, i.e. NOT strict.
 *
 * WHY THAT MATTERS
 * ----------------
 * Under a non-strict session MySQL/MariaDB does not reject an out-of-range ENUM
 * value — it stores the empty string '' and emits a warning nobody reads. So the
 * same bad write that would ABORT loudly through Laravel is ACCEPTED silently
 * through the legacy stack, and the row is left holding a value no branch in the
 * application will ever match.
 *
 * SCOPE — WHAT THIS TEST DOES *NOT* CLAIM
 * ---------------------------------------
 * Phase 9 traced every legacy write path that touches an ENUM-backed column.
 * The result is that this is NOT a currently exploitable defect:
 *
 *   - Only two legacy writers touch ENUM columns at all:
 *       models/Item.php::create()/update()  -> items.item_type, items.status
 *       models/User.php::create()           -> users.status
 *   - Both are reachable only via FacilityService / AuthenticationService,
 *     which are instantiated ONLY inside FacilityController::__construct() and
 *     AuthController::__construct() (public/backend/controllers/*.php:10-11).
 *   - Nothing anywhere in the repository instantiates either controller.
 *     `new FacilityController` / `new AuthController` have zero matches, and
 *     public/backend/api/ contains no files. bootstrap.php only require_once's
 *     the class definitions; it never constructs them.
 *   - The one legacy endpoint actually reachable over HTTP
 *     (models/notifications.php, via bootstrap.php) writes only the
 *     `notifications` table, which has NO enum columns.
 *   - The five remaining executable legacy scripts all require _dev_guard.php
 *     (CLI, or an authenticated super_admin session) and none of them write an
 *     ENUM column.
 *
 * So the risk is LATENT, not live: it is a migration hazard and a
 * configuration-documentation gap. This test exists so that the hazard stays
 * VISIBLE and cannot quietly become live — if someone later wires a legacy
 * controller up to a route, the behaviour proven below is what they inherit.
 *
 * The deliberate decision NOT to "fix" it by adding sql_mode to
 * getDBConnection() is recorded in the Task 66 report: doing so would change the
 * runtime behaviour of live legacy writes (turning silent coercions into thrown
 * exceptions) to protect code paths that are currently unreachable — a
 * behaviour change with no current beneficiary, which the task brief forbids.
 *
 * SAFETY
 * ------
 * Everything happens in a disposable scratch database (see
 * ConnectsToScratchMySqlDatabase). sql_mode is only ever set at SESSION scope on
 * a connection this test opened itself. No global variable is written, no server
 * configuration is touched, and the real application database is never opened.
 */
class LegacyNonStrictSqlModeAsymmetryTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;

    /** Not a member of the probe ENUM below — that is the point. */
    private const OUT_OF_RANGE_VALUE = 'rejected';

    private const PROBE_ENUM_MEMBERS = ['pending', 'approved', 'deducted', 'failed'];

    protected function setUp(): void
    {
        parent::setUp();

        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped('Legacy sql_mode asymmetry proof skipped. ' . $reason);
        }

        $this->useScratchMySqlDatabase('legacy_sql_mode');

        DB::statement(
            'CREATE TABLE sql_mode_probe (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                state ENUM(\'' . implode("','", self::PROBE_ENUM_MEMBERS) . '\') NULL
            ) ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        $this->dropScratchMySqlDatabase();

        parent::tearDown();
    }

    /**
     * Laravel pins strictness itself rather than trusting the server.
     */
    public function test_laravel_connection_pins_strict_mode(): void
    {
        $this->assertTrue(
            (bool) Config::get('database.connections.mysql.strict'),
            "config/database.php must keep 'strict' => true — every other assertion "
            . 'about Laravel-side safety depends on it.'
        );

        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            $this->sessionSqlMode(DB::connection()->getPdo()),
            'Laravel sets its own session sql_mode, so it is strict regardless of the server global.'
        );
    }

    /**
     * A connection built the way getDBConnection() builds one takes whatever the
     * server hands it. This is the asymmetry itself, and it holds regardless of
     * how any particular server happens to be configured.
     */
    public function test_legacy_style_connection_inherits_the_server_global(): void
    {
        $global = (string) DB::selectOne('SELECT @@GLOBAL.sql_mode AS mode')->mode;

        $this->assertSame(
            $global,
            $this->sessionSqlMode($this->legacyStylePdo()),
            'public/backend/config/database.php sets no sql_mode and no init command, so its '
            . 'session mode is exactly the server global — unlike Laravel, which overrides it. '
            . 'Two stacks, one database, two different strictness levels.'
        );

        $this->assertNotSame(
            $this->sessionSqlMode(DB::connection()->getPdo()),
            $this->sessionSqlMode($this->legacyStylePdo()),
            'On a server whose global mode is not strict, the legacy stack and Laravel disagree '
            . 'about what constitutes a valid write to the same table.'
        );
    }

    /**
     * The consequence: a non-strict session accepts an out-of-range ENUM value
     * and silently rewrites it to ''.
     */
    public function test_non_strict_session_silently_coerces_an_out_of_range_enum(): void
    {
        $this->assertNotContains(
            self::OUT_OF_RANGE_VALUE,
            self::PROBE_ENUM_MEMBERS,
            'Precondition: the probe value must not be a member of the probe ENUM.'
        );

        $pdo = $this->legacyStylePdo();
        // SESSION scope only, on a connection this test opened, against a
        // disposable database. No global and no server configuration is touched.
        $pdo->exec("SET SESSION sql_mode = ''");

        $statement = $pdo->prepare('INSERT INTO sql_mode_probe (state) VALUES (?)');
        $statement->execute([self::OUT_OF_RANGE_VALUE]);

        $this->assertSame(
            '',
            DB::table('sql_mode_probe')->orderByDesc('id')->value('state'),
            'A non-strict session does not reject the value — it stores the empty string. '
            . 'No exception is raised, so calling code has no way to notice, and the row now '
            . 'holds a state no application branch will ever match.'
        );
    }

    /**
     * The identical write through a strict session — the one Laravel always
     * uses — fails loudly instead.
     */
    public function test_strict_session_rejects_the_same_write(): void
    {
        $pdo = $this->legacyStylePdo();
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");

        $statement = $pdo->prepare('INSERT INTO sql_mode_probe (state) VALUES (?)');

        $this->expectException(\PDOException::class);
        $statement->execute([self::OUT_OF_RANGE_VALUE]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Mirrors public/backend/config/database.php::getDBConnection() — the same
     * three PDO attributes and, crucially, no sql_mode and no init command.
     * Pointed at the scratch database, never at DB_DATABASE.
     */
    private function legacyStylePdo(): PDO
    {
        $config = Config::get('database.connections.mysql');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            DB::connection()->getDatabaseName()
        );

        return new PDO($dsn, $config['username'] ?? 'root', $config['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    private function sessionSqlMode(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT @@SESSION.sql_mode AS mode')->fetch()['mode'];
    }
}
