<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConnectsToScratchMySqlDatabase;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * ISS-01 — a Dashboard holding historical reports must never present its
 * report summary as an unexplained empty system.
 *
 * THE BUG
 * -------
 * The Administrator Dashboard showed "0" on all five report KPI cards and
 * "0 total" in the Reports-by-Status donut, while the Maintenance Activity
 * Timeline immediately below listed three real maintenance reports. Nothing
 * on the page reconciled the two, so the screen read as "this system has no
 * reports" or "the dashboard is broken".
 *
 * Every number was individually correct. Three different, deliberate scopes
 * were in play at once:
 *
 *   KPI cards  semester-scoped  DashboardController::stats(), which filters
 *                               `created_at >= semester_started_at`
 *                               (TASK 16 / TASK 25.2)
 *   Charts     month-scoped     fetchMonthlyChartStats() in dashboard.php
 *                               (TASK 5)
 *   Timeline   all-time         renderActivityTimeline() (TASK 31.10)
 *
 * The live data had First Semester starting 2026-07-01 and all three reports
 * dated April–May 2026, so the semester-scoped counts were legitimately 0.
 *
 * WHAT THE FIX DOES — AND DELIBERATELY DOES NOT DO
 * ------------------------------------------------
 * The scopes are NOT changed. The business meaning of every metric is
 * unchanged, and no zero was replaced with a database total. The fix makes
 * the scope that produced the zero explicit on screen.
 *
 * The backend change is one additive, read-only response key,
 * `semester_started_at`: the exact lower bound stats() just used. The
 * Dashboard prints that date verbatim instead of re-deriving it from
 * first_sem_start / second_sem_start, which could disagree with the date the
 * query actually ran against.
 *
 * WHAT THESE TESTS PIN
 * --------------------
 *  1. The semester scope still excludes older reports (the fix did not
 *     quietly widen the query to make the zero go away). This is the
 *     assertion that fails if someone "fixes" ISS-01 by deleting the scope.
 *  2. `semester_started_at` is returned and equals the real boundary, so the
 *     Dashboard can state it. Without it the notice cannot name a date.
 *  3. It is null exactly when no semester is running, matching the four KPI
 *     fields that are already null in that state.
 *  4. The frontend is actually wired to the data — the notice container
 *     exists and initDashboard() calls the renderer. Without this, the
 *     backend contract could stay green while the contradiction returned to
 *     the screen.
 *
 * WHY MySQL AND NOT THE DEFAULT SQLite CONNECTION
 * -----------------------------------------------
 * stats() computes "Reports today" with `CURDATE()`, which SQLite does not
 * implement — the query throws before a response exists, so this endpoint
 * has never been reachable from the in-memory SQLite Feature harness. These
 * tests therefore use the same disposable scratch-database trait
 * AnalyticsMySqlExecutionTest uses (see ConnectsToScratchMySqlDatabase for
 * its four safety guards: an allowlisted name pattern that the real database
 * cannot match, dropping only what this test created, no connection to and
 * no schema copied from the real database, and credentials taken from config
 * rather than hardcoded). They SKIP with an explanation when MySQL is
 * unreachable. The frontend-wiring test below needs no database and always
 * runs.
 *
 * SCOPE NOTE: this file covers ISS-01 only. ISS-05 (the Dashboard has no
 * on-page month control and inherits its month from localStorage) is
 * untouched and remains open.
 */
class DashboardScopeTruthfulnessTest extends TestCase
{
    use ConnectsToScratchMySqlDatabase;
    use InteractsWithLegacySession;

    /** The live configuration this defect was reported against. */
    private const SCHOOL_YEAR      = '2026-2027';
    private const FIRST_SEM_START  = '2026-07-01';
    private const FIRST_SEM_END    = '2027-02-28';
    private const SECOND_SEM_START = '2027-03-01';
    private const SECOND_SEM_END   = '2027-07-31';

    private int $adminId = 0;

    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Guards against the $_SESSION leakage documented in
        // UserManagementAuthorizationTest — a leftover super_admin from an
        // earlier test in the same process would otherwise mask the role
        // this file establishes.
        $_SESSION = [];

        // APP_URL in this environment includes the XAMPP htdocs subdirectory,
        // which collides with the legacy subdirectory-redirect catch-all in
        // routes/web.php when the test client builds URLs via url() — every
        // request comes back 302 instead of reaching the controller. Same
        // two lines as ConfiguresIsolatedSqliteConnection::forceLocalTestUrl();
        // inlined rather than pulling in a SQLite trait this MySQL-backed
        // test does not otherwise use. Affects test requests only.
        Config::set('app.url', 'http://localhost');
        app('url')->forceRootUrl('http://localhost');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        if ($this->databaseReady) {
            $this->dropScratchMySqlDatabase();
        }

        parent::tearDown();
    }

    /**
     * Opt-in per test, so the pure-frontend test below never needs MySQL.
     */
    private function bootScratchDatabase(): void
    {
        if ($reason = $this->mysqlUnavailableReason()) {
            $this->markTestSkipped('Dashboard scope coverage skipped. ' . $reason);
        }

        $this->useScratchMySqlDatabase('dashboard_scope');
        $this->createScratchSchema();
        $this->databaseReady = true;

        $this->adminId = $this->seedAdmin();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Backend contract
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reproduces the exact reported scenario: three real reports, all dated
     * before the running semester began.
     *
     * The four semester-scoped KPIs must STILL be 0 — that scope is
     * intentional and the fix does not touch it. What must also be true is
     * that the response carries the boundary that produced the 0, so the
     * Dashboard can explain it rather than rendering a bare zero next to a
     * timeline full of reports.
     */
    public function test_reports_predating_the_semester_yield_zero_kpis_but_a_stated_boundary(): void
    {
        $this->bootScratchDatabase();

        // The three real reports from the defect report.
        $this->seedReport('completed', '2026-04-27 22:07:30');
        $this->seedReport('submitted', '2026-04-29 16:49:51');
        $this->seedReport('submitted', '2026-05-11 21:07:52');

        // Today falls inside First Semester, so a semester IS running.
        $this->seedSchoolSettings('First Semester', self::FIRST_SEM_START);

        $data = $this->fetchStats();

        // (1) The scope is unchanged. If a future change "fixes" the zero by
        //     widening the query to all-time, this fails — which is the
        //     point: the metric's meaning must not drift.
        $this->assertSame(0, $data['total_reports'], 'Semester scoping must still exclude pre-semester reports.');
        $this->assertSame(0, $data['pending']);
        $this->assertSame(0, $data['in_progress']);
        $this->assertSame(0, $data['completed']);

        // (2) A semester really is running — so the "No Active Semester"
        //     branch in the UI does NOT apply and the zeros would otherwise
        //     be displayed completely unexplained.
        $this->assertTrue($data['semester_active']);
        $this->assertSame('First Semester', $data['current_semester']);

        // (3) The boundary the query actually used is reported, so the
        //     Dashboard can name the date instead of guessing one.
        $this->assertArrayHasKey('semester_started_at', $data);
        $this->assertSame(
            self::FIRST_SEM_START,
            $data['semester_started_at'],
            'The Dashboard needs the exact date the KPI query filtered on in order to explain a 0.'
        );

        // (4) The reports are genuinely still there. This is the contradiction
        //     itself, asserted rather than assumed: a system reporting "0" on
        //     the same screen that lists three reports.
        $this->assertSame(3, (int) DB::table('maintenance_reports')->count());
    }

    /**
     * The mirror case — reports INSIDE the semester. Proves the semester
     * filter is a real filter and not a no-op, so test (1) above is
     * meaningful rather than vacuously true.
     */
    public function test_reports_inside_the_semester_are_counted_normally(): void
    {
        $this->bootScratchDatabase();

        $this->seedReport('submitted',   '2026-07-02 09:00:00');
        $this->seedReport('in_progress', '2026-08-14 11:30:00');
        $this->seedReport('completed',   '2026-09-01 15:45:00');
        // ...and one that predates it, which must still be excluded.
        $this->seedReport('completed',   '2026-05-11 21:07:52');

        $this->seedSchoolSettings('First Semester', self::FIRST_SEM_START);

        $data = $this->fetchStats();

        $this->assertSame(3, $data['total_reports'], 'In-semester reports must be counted.');
        $this->assertSame(1, $data['pending']);
        $this->assertSame(1, $data['in_progress']);
        $this->assertSame(1, $data['completed']);
        $this->assertSame(self::FIRST_SEM_START, $data['semester_started_at']);
        $this->assertSame(4, (int) DB::table('maintenance_reports')->count());
    }

    /**
     * When no semester is running, the four KPI fields are already null and
     * the UI renders "No Active Semester" (TASK 25.2). The new field must
     * follow the same rule — a date there would invite the Dashboard to
     * explain a zero that is not actually being shown.
     */
    public function test_no_active_semester_reports_a_null_boundary_alongside_null_kpis(): void
    {
        $this->bootScratchDatabase();

        $this->seedReport('submitted', '2026-05-11 21:07:52');

        // A schedule entirely in the future — today is before it, so
        // SchoolSetting::syncAutomatic() resolves current_semester to null.
        $this->seedSchoolSettings(null, null, [
            'first_sem_start'  => '2099-07-01',
            'first_sem_end'    => '2100-02-28',
            'second_sem_start' => '2100-03-01',
            'second_sem_end'   => '2100-07-31',
        ]);

        $data = $this->fetchStats();

        $this->assertFalse($data['semester_active']);
        $this->assertNull($data['total_reports']);
        $this->assertNull($data['pending']);
        $this->assertNull($data['in_progress']);
        $this->assertNull($data['completed']);
        $this->assertNull(
            $data['semester_started_at'],
            'With no running semester there is no boundary to explain, so none may be advertised.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Frontend wiring (no database required)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The contradiction lives on the rendered page, so a green backend
     * contract alone does not prove it is gone. This pins the links in the
     * chain: the renderer exists, initDashboard() calls it, and it is given
     * the field the backend returns.
     *
     * Deliberately a structural check, not a snapshot of the wording — the
     * copy may be reworded freely, but the wiring may not silently vanish.
     *
     * UPDATED BY THE DASHBOARD CLEANUP TASK (§2)
     * ------------------------------------------
     * ISS-01 originally shipped TWO on-screen signals. The full-width "Why
     * these counters read 0" banner was then removed by explicit instruction
     * ("Remove the entire Dashboard notice... Do NOT replace it with another
     * large explanatory banner"), because it was the biggest single
     * contributor to the page's length.
     *
     * This test was therefore re-pointed rather than relaxed: the assertion
     * that the banner EXISTS became an assertion that it STAYS GONE, so the
     * removal cannot be silently undone, and every other link in the chain is
     * still pinned exactly as before. The three backend tests above are
     * untouched, which is what proves §2's "this is ONLY a visual removal" —
     * the semester scope, its boundary date, and the null-semester contract
     * all still hold.
     *
     * What still carries the scope on screen, and is asserted below: the
     * month-scope caption under the Reports by Status donut. The KPI cards'
     * own "this semester" chips carry the semester half and are static markup
     * in the KPI block.
     */
    public function test_dashboard_page_renders_and_wires_the_scope_reconciliation_notice(): void
    {
        $dashboard = file_get_contents(base_path('public/frontend/pages/dashboard.php'));

        $this->assertIsString($dashboard);

        // §2 — the banner must not come back. Checked against the id and the
        // headline copy, so neither a re-added container nor a re-worded
        // rebuild of the same banner slips through.
        $this->assertStringNotContainsString(
            'id="dashboard-scope-notice"',
            $dashboard,
            'The "Why these counters read 0" banner was removed by the Dashboard cleanup task and must stay removed.'
        );
        $this->assertStringNotContainsString(
            'Why these counters read 0',
            $dashboard,
            'The removed banner\'s headline must not be reintroduced anywhere on the Dashboard.'
        );

        $this->assertStringContainsString(
            'id="status-chart-empty-note"',
            $dashboard,
            'The donut\'s "0 total" needs its month-scope explanation element.'
        );
        $this->assertStringContainsString(
            'function renderDashboardScopeNotice(',
            $dashboard,
            'The renderer that reconciles the zeros against the timeline is missing.'
        );
        $this->assertStringContainsString(
            'renderDashboardScopeNotice(stats, effectiveChartStats, monthLabel)',
            $dashboard,
            'initDashboard() must invoke the renderer, or the notice never appears.'
        );
        $this->assertStringContainsString(
            'semester_started_at:        apiStats ? (apiStats.semester_started_at || null) : null,',
            $dashboard,
            'The boundary date must survive the stats merge or the notice cannot name it.'
        );

        // The all-time report rows the notice counts against must still be
        // handed to the timeline — that array is what makes the zeros a
        // visible contradiction in the first place.
        $this->assertStringContainsString(
            'renderActivityTimeline(stats._reports || [])',
            $dashboard,
            'The timeline must keep its all-time source; ISS-01 is about explaining it, not removing it.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function fetchStats(): array
    {
        $response = $this
            ->actingAsSessionUser($this->adminId, 'super_admin')
            ->getJson('/api/dashboard/stats');

        $response->assertOk();

        return $response->json('data');
    }

    private function seedAdmin(): int
    {
        return (int) DB::table('users')->insertGetId([
            'name'       => 'Audit Administrator',
            'email'      => 'iss01-admin@example.test',
            'role'       => 'super_admin',
            'status'     => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function seedReport(string $status, string $createdAt): void
    {
        DB::table('maintenance_reports')->insert([
            'title'      => 'Seeded report ' . $createdAt,
            'status'     => $status,
            'priority'   => 'medium',
            'created_by' => $this->adminId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function seedSchoolSettings(?string $semester, ?string $startedAt, array $overrides = []): void
    {
        DB::table('school_settings')->insert(array_merge([
            'school_year'         => self::SCHOOL_YEAR,
            'current_semester'    => $semester,
            'first_sem_start'     => self::FIRST_SEM_START,
            'first_sem_end'       => self::FIRST_SEM_END,
            'second_sem_start'    => self::SECOND_SEM_START,
            'second_sem_end'      => self::SECOND_SEM_END,
            'semester_started_at' => $startedAt === null ? null : $startedAt . ' 00:00:00',
            'created_at'          => '2026-01-01 00:00:00',
            'updated_at'          => '2026-01-01 00:00:00',
        ], $overrides));
    }

    /**
     * Only the columns stats() and its collaborators actually read. Declared
     * here rather than migrated so the test stays fast and independent of
     * migration churn — the same choice AnalyticsMySqlExecutionTest makes.
     */
    private function createScratchSchema(): void
    {
        DB::statement("
            CREATE TABLE users (
                user_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL,
                email VARCHAR(255) NULL,
                role VARCHAR(50) NULL,
                status VARCHAR(50) NULL,
                department_id INT UNSIGNED NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        DB::statement("
            CREATE TABLE maintenance_reports (
                report_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NULL,
                status VARCHAR(50) NULL,
                priority VARCHAR(50) NULL,
                department_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                assigned_to INT UNSIGNED NULL,
                due_date DATE NULL,
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

        // stats() also counts low-stock inventory. Not part of ISS-01, but the
        // endpoint queries it, so the table has to exist for a response to be
        // produced at all.
        DB::statement("
            CREATE TABLE items (
                item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_type VARCHAR(50) NULL,
                status VARCHAR(50) NULL
            ) ENGINE=InnoDB
        ");
    }
}
