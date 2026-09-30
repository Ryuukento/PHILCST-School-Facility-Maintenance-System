<?php

namespace Tests\Feature;

use App\Services\AnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\TestCase;

/**
 * TASK 65 PHASE 3 — executed coverage for AnalyticsService's MySQL-only SQL.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * The Feature suite runs on in-memory SQLite. AnalyticsService builds raw SQL
 * out of functions SQLite does not implement, so these methods could never be
 * executed by any existing test:
 *
 *   overview()                     DATE_FORMAT, DATE_SUB, CURDATE   (x4 queries)
 *   monthlyInventoryComparison()   DATE_FORMAT, DATE_SUB, CURDATE   (via timeSeries)
 *   semesterYearComparison()       MONTH, YEAR, CONCAT, CASE, DATE_SUB, CURDATE
 *   semesterDetail()               UNION ALL derived table, DATE(), aggregate ORDER BY
 *
 * "Zero executed coverage" is not a stylistic complaint: a typo inside any of
 * those strings is invisible until a user loads the Reports page in
 * production. Task 64 recorded this as blocker B5. These tests execute every
 * one of those statements against a real server and assert on the values they
 * compute, so both syntax AND semantics are pinned.
 *
 * HOW IT STAYS SAFE
 * -----------------
 * Each test runs against a disposable database this test creates and drops
 * itself (see ConnectsToScratchMySqlDatabase for the four guards). No
 * connection is ever opened to the application database, no application data
 * is read or written, and no credentials are hardcoded — only the database
 * NAME is overridden, with a name that provably cannot be a real one.
 *
 * If MySQL is not reachable, or the configured user cannot create databases,
 * every test here SKIPS with an explanatory message. The coverage is
 * opportunistic on purpose: a hard failure on a SQLite-only machine would
 * just get the file deleted, and partial coverage beats none.
 *
 * Note the server here is MariaDB 10.4 (XAMPP). Every construct exercised
 * below is common to MySQL and MariaDB, but the report records the
 * distinction rather than claiming MySQL was tested.
 *
 * The final test in this file is not about analytics — it documents the
 * strict-mode divergence between Laravel's connection and the legacy
 * public/backend PDO connection. It lives here because this is the only
 * place in the suite where real MySQL is available to prove it.
 *
 * NOTHING IN AnalyticsService WAS CHANGED to make these pass — the brief
 * explicitly forbids rewriting the service to suit the tests.
 *
 * TASK 13 PHASE 8 (Repair retirement) — the two latent defects previously
 * recorded here lived in repairReport(), which no longer exists; that note is
 * retired along with the method. The assertions below were updated in the
 * opposite direction from a normal deletion: the repair_requests INSERTs are
 * kept and the expectations inverted, because Task 13 retires Repair at the
 * APPLICATION level only. The table still exists and may still hold rows, so
 * proving the metric is gone requires a populated table — on an empty one a
 * dropped metric and a zero-valued one are indistinguishable.
 *
 * These tests SKIP when MySQL is unreachable, which is how the stale
 * expectations survived the earlier focused runs unnoticed.
 */
class AnalyticsMySqlExecutionTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;

    private AnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped(
                'MySQL-backed analytics coverage skipped. ' . $reason
            );
        }

        $this->useScratchMySqlDatabase('analytics');
        $this->createScratchSchema();

        Cache::flush();

        $this->analytics = new AnalyticsService();
    }

    protected function tearDown(): void
    {
        $this->dropScratchMySqlDatabase();

        parent::tearDown();
    }

    /**
     * overview() runs four raw DATE_FORMAT/DATE_SUB/CURDATE queries. This
     * asserts both that they parse and that the 12-month window and the
     * month bucketing actually work — a query that returned everything, or
     * bucketed wrongly, would still "run".
     */
    public function test_overview_buckets_by_month_and_honours_the_twelve_month_window(): void
    {
        $recent = $this->monthsAgo(1);
        $older = $this->monthsAgo(3);
        $outOfWindow = $this->monthsAgo(18);

        // Two rows in the SAME month proves the GROUP BY aggregates rather
        // than merely listing rows.
        $this->insertDamageReport(['created_at' => $recent]);
        $this->insertDamageReport(['created_at' => $recent]);
        $this->insertDamageReport(['created_at' => $older]);
        $this->insertDamageReport(['created_at' => $outOfWindow]);

        $this->insertRepairRequest(['created_at' => $recent]);
        $this->insertDispatch(['created_at' => $recent]);
        $this->insertInventoryTransaction(['quantity' => 7, 'created_at' => $recent]);
        $this->insertInventoryTransaction(['quantity' => 5, 'created_at' => $recent]);

        $result = $this->analytics->overview();

        $damaged = $this->keyByYm($result['damaged_trends']);

        // ORDER BY ym is ASCENDING, so the older month comes first. Asserting
        // the exact key order (rather than just membership) pins the ordering
        // the Reports charts rely on to draw a left-to-right timeline.
        $this->assertSame(
            [$older->format('Y-m'), $recent->format('Y-m')],
            array_keys($damaged),
            'damaged_trends should contain exactly the two in-window months, oldest first.'
        );
        $this->assertSame(2, (int) $damaged[$recent->format('Y-m')]->cnt);
        $this->assertSame(1, (int) $damaged[$older->format('Y-m')]->cnt);
        $this->assertArrayNotHasKey(
            $outOfWindow->format('Y-m'),
            $damaged,
            'A row older than 12 months must be excluded by DATE_SUB(CURDATE(), INTERVAL 12 MONTH).'
        );

        // The remaining queries are structurally identical; assert they run
        // and aggregate rather than re-testing the windowing three times.
        $this->assertSame(1, (int) $this->keyByYm($result['dispatch_trends'])[$recent->format('Y-m')]->cnt);

        // TASK 13 PHASE 8 (Repair retirement) — the repair_requests 12-month
        // trend query was removed from overview(), so 'repair_trends' must no
        // longer appear in the payload at all.
        //
        // The repair row inserted above is deliberately LEFT IN PLACE. Task 13
        // is an application-level retirement: repair_requests still exists and
        // may still hold rows. Asserting absence while a matching row sits in
        // the table is what proves the metric was actually dropped, rather
        // than merely happening to return empty on a clean database.
        $this->assertArrayNotHasKey(
            'repair_trends',
            $result,
            'overview() must not report a Repair Request metric after Task 13, even though repair_requests still holds a row in this window.'
        );

        // stock_movements SUMs rather than COUNTs — a different code path.
        $this->assertSame(
            12,
            (int) $this->keyByYm($result['stock_movements'])[$recent->format('Y-m')]->qty,
            'stock_movements must SUM(quantity) (7 + 5), not count rows.'
        );
    }

    /**
     * semesterYearComparison() is the only place that uses MONTH(), YEAR() and
     * CONCAT() inside a CASE expression. The semester split is calendar-based
     * here (Jan-Jun / Jul-Dec) and is deliberately asserted as-is: this test
     * pins CURRENT behaviour, it does not endorse it. (semesterDetail() below
     * is the configured-date-range version the Dashboard actually uses.)
     */
    public function test_semester_year_comparison_splits_on_calendar_halves(): void
    {
        $firstHalf = Carbon::create($this->lastYear(), 3, 15, 9, 0, 0);
        $secondHalf = Carbon::create($this->lastYear(), 9, 15, 9, 0, 0);

        $this->insertInventoryTransaction(['quantity' => 4, 'created_at' => $firstHalf]);
        $this->insertInventoryTransaction(['quantity' => 6, 'created_at' => $firstHalf]);
        $this->insertInventoryTransaction(['quantity' => 3, 'created_at' => $secondHalf]);

        $rows = $this->analytics->semesterYearComparison()['semester_trends'];

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[$row->period] = (int) $row->qty;
        }

        $this->assertSame(10, $byPeriod[$this->lastYear() . '-S1'] ?? null, 'March belongs to S1 and must SUM to 4 + 6.');
        $this->assertSame(3, $byPeriod[$this->lastYear() . '-S2'] ?? null, 'September belongs to S2.');
    }

    /**
     * departmentUsageComparison() joined `items.department_id` — a column that
     * exists in no migration — so the endpoint returned HTTP 500
     * (SQLSTATE[42S22]) on EVERY call and the "Inventory Movement by
     * Department" panel always rendered "Failed to load department usage
     * data.". No test executed this method, which is why it survived.
     *
     * This pins three things: the query parses against a real server, movement
     * is attributed through dispatches.department_id, and an empty result set
     * returns an empty array rather than throwing.
     */
    public function test_department_usage_attributes_movement_through_dispatches(): void
    {
        $computer = $this->insertDepartment('Computer');

        $dispatchId = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'DSP-DEPT-USAGE',
            'department_id' => $computer,
            'status' => 'released',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Attributable: moved under a dispatch belonging to Computer.
        $this->insertInventoryTransaction([
            'dispatch_id' => $dispatchId,
            'quantity' => 7,
        ]);

        // Not attributable: no dispatch, so it has no owning department.
        $this->insertInventoryTransaction([
            'dispatch_id' => null,
            'quantity' => 5,
        ]);

        $rows = collect($this->analytics->departmentUsageComparison()['department_usage'])
            ->keyBy('department_name');

        $this->assertSame(7, (int) $rows['Computer']->total_moved);
        $this->assertSame(5, (int) $rows['No Department']->total_moved);

        // An empty window must return [] — not a 500, and not a fabricated row.
        Cache::flush();
        $empty = $this->analytics->departmentUsageComparison([
            'date_from' => '1990-01-01',
            'date_to' => '1990-12-31',
        ]);

        $this->assertSame([], collect($empty['department_usage'])->all());
    }

    /**
     * timeSeries() interpolates its table/column names into the SQL string and
     * is reached only through monthlyInventoryComparison(). The 1-month and
     * 12-month windows must actually differ, otherwise the $months parameter
     * is not being applied.
     */
    public function test_monthly_inventory_comparison_applies_distinct_windows(): void
    {
        $thisMonth = Carbon::now()->startOfMonth()->addDays(2);
        $sixMonthsAgo = $this->monthsAgo(6);

        $this->insertInventoryTransaction(['quantity' => 9, 'created_at' => $thisMonth]);
        $this->insertInventoryTransaction(['quantity' => 100, 'created_at' => $sixMonthsAgo]);

        $result = $this->analytics->monthlyInventoryComparison();

        $thisMonthTotal = array_sum(array_map(fn ($r) => (int) $r['val'], $result['this_month']));
        $last12Total = array_sum(array_map(fn ($r) => (int) $r['val'], $result['last_12_months']));

        $this->assertSame(9, $thisMonthTotal, 'The 1-month window must exclude the 6-month-old transaction.');
        $this->assertSame(109, $last12Total, 'The 12-month window must include both transactions.');
    }

    /**
     * semesterDetail() runs the most complex statement in the service: a
     * four-branch UNION ALL derived table, a LEFT JOIN onto departments, a
     * GROUP BY, and an ORDER BY over a sum of CASE expressions — with eight
     * bound parameters. Any error in the branch ordering would silently
     * mis-attribute counts to the wrong semester or the wrong source.
     */
    public function test_semester_detail_attributes_counts_to_the_right_semester_and_department(): void
    {
        $year = Carbon::now()->year;

        $this->configureSemesters(
            firstStart: "{$year}-01-01",
            firstEnd: "{$year}-06-30",
            secondStart: "{$year}-07-01",
            secondEnd: "{$year}-12-31"
        );

        $engineering = $this->insertDepartment('Engineering');
        $facilities = $this->insertDepartment('Facilities');

        $inFirstSemester = Carbon::create($year, 2, 10, 9, 0, 0);
        $inSecondSemester = Carbon::create($year, 8, 10, 9, 0, 0);

        // Engineering: 2 damage in S1, 1 dispatch in S2  (total 3)
        $this->insertDamageReport(['department_id' => $engineering, 'created_at' => $inFirstSemester]);
        $this->insertDamageReport(['department_id' => $engineering, 'created_at' => $inFirstSemester]);
        $this->insertDispatch(['department_id' => $engineering, 'created_at' => $inSecondSemester]);

        // Facilities: 1 damage in S2  (total 1) — must sort BELOW Engineering.
        $this->insertDamageReport(['department_id' => $facilities, 'created_at' => $inSecondSemester]);

        $this->insertMaintenanceReport(['created_at' => $inFirstSemester]);
        $this->insertRepairRequest(['created_at' => $inSecondSemester]);

        $result = $this->analytics->semesterDetail();

        $this->assertTrue($result['configured'], 'All four semester dates were configured.');

        $s1 = $result['semesters']['s1'];
        $s2 = $result['semesters']['s2'];

        $this->assertSame(2, $s1['damage_reports'], 'S1 holds only the two Engineering damage reports.');
        $this->assertSame(1, $s1['maintenance_reports']);
        $this->assertSame(0, $s1['dispatches']);

        $this->assertSame(1, $s2['damage_reports'], 'S2 holds the single Facilities damage report.');
        $this->assertSame(1, $s2['dispatches']);
        $this->assertSame(0, $s2['maintenance_reports']);

        // TASK 13 PHASE 8 (Repair retirement) — semesterDetail() is a SHARED
        // method and survives; only its 4th metric was dropped. The repair row
        // inserted above falls inside S2 and is left in place on purpose, so
        // this asserts the metric is gone rather than merely unpopulated.
        foreach (['s1' => $s1, 's2' => $s2] as $name => $semester) {
            $this->assertArrayNotHasKey(
                'repairs',
                $semester,
                "semesterDetail() {$name} must not carry a Repair Request count after Task 13."
            );
        }

        // The three metrics the brief requires be KEPT must all still be there.
        foreach (['maintenance_reports', 'dispatches', 'damage_reports'] as $kept) {
            $this->assertArrayHasKey($kept, $s1, "semesterDetail() must still report {$kept}.");
            $this->assertArrayHasKey($kept, $s2, "semesterDetail() must still report {$kept}.");
        }

        $breakdown = $result['department_breakdown'];
        $byDepartment = [];
        foreach ($breakdown as $row) {
            $byDepartment[$row['department_name']] = $row;
        }

        $this->assertArrayHasKey('Engineering', $byDepartment);
        $this->assertArrayHasKey('Facilities', $byDepartment);

        $this->assertSame(2, (int) $byDepartment['Engineering']['s1_damage']);
        $this->assertSame(0, (int) $byDepartment['Engineering']['s2_damage']);
        $this->assertSame(0, (int) $byDepartment['Engineering']['s1_dispatches']);
        $this->assertSame(1, (int) $byDepartment['Engineering']['s2_dispatches']);

        $this->assertSame(0, (int) $byDepartment['Facilities']['s1_damage']);
        $this->assertSame(1, (int) $byDepartment['Facilities']['s2_damage']);

        // The ORDER BY is over a sum of four CASE expressions; Engineering (3)
        // must outrank Facilities (1).
        $this->assertSame(
            'Engineering',
            $breakdown[0]['department_name'],
            'department_breakdown must be ordered by total activity descending.'
        );
    }

    /**
     * semesterDetail() must not fabricate dates when the Administrator has
     * not finished configuring the schedule. This path returns BEFORE the
     * UNION ALL query, so it also proves the early return is reachable.
     */
    public function test_semester_detail_reports_unconfigured_rather_than_guessing(): void
    {
        $this->configureSemesters(
            firstStart: null,
            firstEnd: null,
            secondStart: null,
            secondEnd: null
        );

        $result = $this->analytics->semesterDetail();

        $this->assertFalse($result['configured']);
        $this->assertNull($result['semesters']['s1']);
        $this->assertNull($result['semesters']['s2']);
        $this->assertSame([], $result['department_breakdown']);
    }

    /**
     * TASK 65 PHASE 4 COROLLARY — the two write paths do NOT agree, and this
     * is the single most important thing discovered in Task 65.
     *
     * This is a hybrid application: Laravel owns /api/*, while the legacy
     * PHP pages under public/backend/ open their own PDO connection
     * (public/backend/config/database.php::getDBConnection()). Those two
     * connections have DIFFERENT ENUM safety guarantees, because:
     *
     *   - config/database.php sets 'strict' => true, so Laravel issues
     *     SET SESSION sql_mode='...STRICT_TRANS_TABLES...' on connect.
     *     An out-of-range ENUM value is REJECTED with an exception.
     *
     *   - getDBConnection() sets no sql_mode at all, so it inherits the
     *     server global — which on this server is
     *     NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION, i.e. NOT
     *     strict. The same value is SILENTLY COERCED to the empty string and
     *     the INSERT reports success.
     *
     * Consequences that matter for the migration:
     *
     *   1. "MySQL will reject bad status values for us" is true only for
     *      Laravel-issued writes. Any backfill or cleanup run through a plain
     *      PDO script — the obvious way someone would write a migration
     *      script — gets silent data corruption instead of a loud failure.
     *   2. It explains how empty-string ENUM values can exist at all, and
     *      narrows their provenance to the non-Laravel path.
     *
     * Both halves are asserted so that flipping 'strict' => false, or the
     * legacy connection gaining a strict sql_mode, fails here loudly.
     */
    public function test_laravel_and_legacy_connections_disagree_on_bad_enum_values(): void
    {
        DB::statement("
            CREATE TABLE enum_truncation_probe (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                report_category ENUM('general','repair_replacement') NOT NULL DEFAULT 'general'
            ) ENGINE=InnoDB
        ");

        // ---- Path 1: Laravel's connection (strict) --------------------------
        $laravelMode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;

        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            $laravelMode,
            "Laravel's mysql connection must keep 'strict' => true; it is the only thing "
            . 'protecting API writes from silent ENUM coercion.'
        );

        // 'asset' is one of the exact values Phase 4 removed from
        // ReportUnifiedReadSurfaceTest.
        $rejected = false;
        try {
            DB::table('enum_truncation_probe')->insert(['report_category' => 'asset']);
        } catch (\Illuminate\Database\QueryException $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected, "Under strict mode the invalid ENUM value 'asset' must raise.");
        $this->assertSame(0, DB::table('enum_truncation_probe')->count(), 'The rejected row must not exist.');

        // ---- Path 2: the legacy backend's connection (server default) -------
        $globalMode = (string) DB::selectOne('SELECT @@GLOBAL.sql_mode AS mode')->mode;

        if (str_contains($globalMode, 'STRICT_TRANS_TABLES') || str_contains($globalMode, 'STRICT_ALL_TABLES')) {
            $this->markTestSkipped(
                'The server global sql_mode is strict, so the legacy PDO connection inherits '
                . 'strictness and the divergence documented here does not exist on this server. '
                . 'global sql_mode = ' . $globalMode
            );
        }

        // getDBConnection() sets no sql_mode, so its session mode IS the
        // global one. Adopting the global mode on this session reproduces the
        // legacy connection's behaviour exactly, without opening a second
        // connection or leaving the scratch database.
        DB::statement('SET SESSION sql_mode = @@GLOBAL.sql_mode');

        DB::table('enum_truncation_probe')->insert(['report_category' => 'asset']);

        $this->assertSame(
            '',
            DB::table('enum_truncation_probe')->value('report_category'),
            "On the legacy connection's sql_mode, 'asset' is stored as the empty string and "
            . 'the INSERT reports success. This is silent data corruption, not an error.'
        );
    }

    // ---------------------------------------------------------------------
    // Scratch schema + fixtures
    //
    // Declared here rather than copied from the live database (which this
    // test never connects to) or built by running the migrations (which do
    // not replay from empty — see ConnectsToScratchMySqlDatabase). Only the
    // columns AnalyticsService's SQL actually touches are present; the ENUM
    // definitions mirror the live ones so seeded values are realistic.
    // ---------------------------------------------------------------------

    private function createScratchSchema(): void
    {
        DB::statement("
            CREATE TABLE departments (
                department_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE maintenance_reports (
                report_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                department_id INT UNSIGNED NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE damage_reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                department_id INT UNSIGNED NULL,
                room_id BIGINT UNSIGNED NULL,
                item_id BIGINT UNSIGNED NULL,
                status ENUM('pending','under_review','repairing','repaired','replaced','closed') NOT NULL DEFAULT 'pending',
                replacement_item_id BIGINT UNSIGNED NULL,
                replacement_transaction_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE repair_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                repair_code VARCHAR(50) NULL,
                damage_report_id BIGINT UNSIGNED NULL,
                repair_status ENUM('pending','assigned','diagnosing','repairing','waiting_parts','completed','failed','archived') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE dispatches (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dispatch_code VARCHAR(50) NULL,
                department_id INT UNSIGNED NULL,
                status ENUM('pending','approved','released','cancelled') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE inventory_transactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NULL,
                dispatch_id BIGINT UNSIGNED NULL,
                quantity INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE school_settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                school_year VARCHAR(50) NULL,
                current_semester VARCHAR(50) NULL,
                first_sem_start DATE NULL,
                first_sem_end DATE NULL,
                second_sem_start DATE NULL,
                second_sem_end DATE NULL,
                semester_started_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");
    }

    private function configureSemesters(
        ?string $firstStart,
        ?string $firstEnd,
        ?string $secondStart,
        ?string $secondEnd
    ): void {
        DB::table('school_settings')->insert([
            'school_year' => '2026-2027',
            // Left null so SchoolSetting::syncAutomatic() derives it, exactly
            // as it does in the application.
            'current_semester' => null,
            'first_sem_start' => $firstStart,
            'first_sem_end' => $firstEnd,
            'second_sem_start' => $secondStart,
            'second_sem_end' => $secondEnd,
            'semester_started_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function insertDepartment(string $name): int
    {
        return (int) DB::table('departments')->insertGetId(['name' => $name], 'department_id');
    }

    private function insertMaintenanceReport(array $attributes = []): void
    {
        DB::table('maintenance_reports')->insert(array_merge([
            'department_id' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    private function insertDamageReport(array $attributes = []): void
    {
        DB::table('damage_reports')->insert(array_merge([
            'department_id' => null,
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    private function insertRepairRequest(array $attributes = []): void
    {
        DB::table('repair_requests')->insert(array_merge([
            'repair_code' => 'RR-' . uniqid(),
            'repair_status' => 'pending',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    private function insertDispatch(array $attributes = []): void
    {
        DB::table('dispatches')->insert(array_merge([
            'dispatch_code' => 'DSP-' . uniqid(),
            'department_id' => null,
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    private function insertInventoryTransaction(array $attributes = []): void
    {
        DB::table('inventory_transactions')->insert(array_merge([
            'item_id' => 1,
            'quantity' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    /**
     * The 11th of the month N months back. Anchored to startOfMonth so the
     * test cannot break when run on the 29th-31st, and far enough from the
     * 12-month cutoff that it is never boundary-ambiguous.
     */
    private function monthsAgo(int $months): Carbon
    {
        return Carbon::now()->startOfMonth()->subMonthsNoOverflow($months)->addDays(10)->setTime(9, 0);
    }

    private function lastYear(): int
    {
        return Carbon::now()->subYear()->year;
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<string,object>
     */
    private function keyByYm(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->ym] = $row;
        }

        return $keyed;
    }
}
