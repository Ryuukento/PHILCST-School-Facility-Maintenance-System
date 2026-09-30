<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * "All Reports" date-scope behaviour.
 *
 * THE PRODUCT RULE THIS FILE PINS
 * -------------------------------
 * A plain visit to All Reports scopes to the CURRENT MONTH — first of the
 * month through today. "All Reports" names the report collection (the module);
 * it is not an instruction to drop the date filter.
 *
 * HISTORY — READ THIS BEFORE CHANGING THE ASSERTIONS
 * --------------------------------------------------
 * This file was originally written for ISS-02, which asserted the OPPOSITE:
 * that a plain load leaves both date inputs empty (all-time). That change was
 * reported as a regression — opening All Reports showed an "All dates" chip
 * and listed months-old April/May reports instead of the current month — and
 * it was reverted. The assertions below were re-pointed to the restored
 * behaviour at that time. They were not weakened: the file still pins the
 * default scope just as tightly, it simply pins the correct one.
 *
 * The four scopes are deliberately distinct and each has coverage here:
 *   - plain load        → current month
 *   - ?last_month=1     → previous month
 *   - ?date_scope=today → today
 *   - explicit range    → that range
 *
 * THE SCOPE INDICATOR IS NOW GONE TOO — AND WHY THAT IS SEPARATE
 * -------------------------------------------------------------
 * The ISS-02 scope indicator (#reports-scope-notice) survived the revert for a
 * while: it named the active range on screen, which stopped an empty
 * current-month result from being misread as an empty system. It has since
 * been removed as redundant display text, and
 * test_the_date_range_summary_is_gone_but_the_date_filter_is_not() below was
 * re-pointed to assert its absence.
 *
 * Read that as a DISPLAY decision, never as a scope decision. The default
 * scope above is unchanged and the tests pinning it are unchanged. If an empty
 * current month is ever mistaken for an empty system again, the answer is the
 * date inputs — visible, populated and editable — not widening the default.
 *
 * This file also now covers the other two UI-only removals made at the same
 * time: the Lifecycle column and the position-based column width rules that
 * had to be re-indexed when it went.
 */
class ReportsDefaultDateScopeTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /** The exact dates this system holds, per the ISS-02 report. */
    private const REAL_REPORT_DATES = ['2026-04-27', '2026-04-29', '2026-05-11'];

    private string $pageSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('reports_default_date_scope_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        $this->pageSource = (string) file_get_contents(
            base_path('public/frontend/pages/reports.php')
        );
    }

    /**
     * Only the tables /api/reports touches. Mirrors the helper in
     * ReportsApiNeedChangeTest, which builds the same surface for the same
     * endpoint.
     */
    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();

        Schema::enableForeignKeyConstraints();
    }

    /**
     * The date scope is a FRONTEND concern: with no date parameters the API
     * returns every report, including ones months older than "now". This is
     * why the current-month default must be applied by the page, and why a
     * server-side default must never be added — if someone adds one, the
     * historical reports become unreachable and this test fails.
     */
    public function test_an_unfiltered_request_returns_every_report_regardless_of_age(): void
    {
        $adminId = $this->seedAdminWithReports();

        $response = $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->getJson('/api/reports?per_page=200');

        $response->assertOk();

        $rows = collect($response->json('data.reports'));
        $this->assertSame(3, $rows->count(), 'Unfiltered "All Reports" must return all three reports.');
        $this->assertSame(3, (int) $response->json('data.total'));

        $returned = $rows->map(fn ($r) => substr((string) $r['created_at'], 0, 10))->sort()->values()->all();
        $this->assertSame(self::REAL_REPORT_DATES, $returned);
    }

    /**
     * §8 — explicit date filtering must keep working exactly as before. These
     * are the three ranges named in the task brief.
     */
    public function test_explicit_date_ranges_still_filter_correctly(): void
    {
        $adminId = $this->seedAdminWithReports();

        $count = function (string $from, string $to) use ($adminId): int {
            $res = $this
                ->actingAsSessionUser($adminId, 'maintenance_admin')
                ->getJson("/api/reports?per_page=200&date_from={$from}&date_to={$to}");
            $res->assertOk();

            return count($res->json('data.reports'));
        };

        $this->assertSame(3, $count('2026-04-01', '2026-05-31'), 'April–May must contain all three reports.');
        $this->assertSame(0, $count('2026-07-01', '2026-07-31'), 'July must be empty.');
        $this->assertSame(0, $count('2026-09-01', '2026-09-30'), 'September must be empty.');

        // Boundaries are inclusive — a report dated exactly on the bound counts.
        $this->assertSame(1, $count('2026-05-11', '2026-05-11'));
        $this->assertSame(2, $count('2026-04-27', '2026-04-29'));
    }

    /**
     * THE REGRESSION GUARD. A plain load must pre-fill the current month in
     * BOTH render paths — the JS init and the no-JS PHP fallback — so the
     * "All dates" default can never come back through either one.
     */
    public function test_a_plain_load_scopes_to_the_current_month(): void
    {
        // JS: the default (no URL parameter) branch must seed the range from
        // getCurrentMonthDateRange(), not blank the inputs.
        $this->assertMatchesRegularExpression(
            '/\}\s*else\s*\{(?:(?!\}).)*?const range = getCurrentMonthDateRange\(\);\s*'
                . 'document\.getElementById\(\'filter-date-from\'\)\.value = formatLocalDate\(range\.start\);\s*'
                . 'document\.getElementById\(\'filter-date-to\'\)\.value = formatLocalDate\(range\.end\);/s',
            $this->pageSource,
            'The default (no URL parameter) load branch must pre-fill the current-month range.'
        );

        // The exact ISS-02 regression: blanking the inputs in that branch.
        $this->assertDoesNotMatchRegularExpression(
            '/\}\s*else\s*\{(?:(?!\}).)*?filter-date-from\'\)\.value\s*=\s*\'\';/s',
            $this->pageSource,
            'The default load branch must NOT blank the date inputs — that is the "All dates" regression.'
        );

        // The no-JS PHP fallback must agree with the JS path.
        $this->assertStringContainsString(
            "\$dateFrom = date('Y-m-01');",
            $this->pageSource,
            'The PHP fallback must default to a current-month lower bound.'
        );
        $this->assertStringContainsString(
            "\$dateTo = date('Y-m-d');",
            $this->pageSource,
            'The PHP fallback must default to today as the upper bound.'
        );
    }

    /**
     * THE DATE-SCOPE SUMMARY LINE IS GONE — AND THE FILTER IS NOT.
     *
     * WHY THIS TEST REVERSED
     * ----------------------
     * This method previously asserted the OPPOSITE: that reports.php wires an
     * on-screen scope indicator (#reports-scope-notice, renderReportsScopeNotice(),
     * fetchAllTimeReportCount(), "Reports are limited to this date range.").
     * That indicator was then removed by request as redundant display text —
     * the two date inputs sit directly above where it rendered and already
     * show the active range.
     *
     * It is re-pointed, not weakened, and not deleted. It asserted one thing
     * before (the summary exists); it asserts two things now — that every
     * piece of the summary is gone, AND that the date FILTER it used to
     * describe is still fully wired. The second half is the important half:
     * the risk in deleting display text is deleting the behaviour along with
     * it, so this test now pins exactly that boundary.
     */
    public function test_the_date_range_summary_is_gone_but_the_date_filter_is_not(): void
    {
        // (1) Every trace of the rendered summary must be absent.
        //
        // Checked against the page with its HTML and block comments stripped.
        // reports.php documents this removal in prose, and that prose quotes
        // the removed wording verbatim so the next reader knows exactly what
        // went — which would otherwise make this test fail on its own
        // documentation. Stripping comments asserts what the page RENDERS,
        // which is the actual requirement.
        $rendered = $this->sourceWithoutComments();

        foreach ([
            '<p id="reports-scope-notice"',
            'function renderReportsScopeNotice()',
            'renderReportsScopeNotice();',
            'async function fetchAllTimeReportCount()',
            'Reports are limited to this date range.',
            'No reports fall in this date range.',
            'Showing every report in the system.',
            'reports-scope-chip',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $rendered,
                "reports.php must no longer render the date-range summary — found [{$needle}]."
            );
        }

        // (2) The controls that actually scope the list must survive. If a
        // future cleanup takes the inputs out along with the text, this fails.
        foreach ([
            'id="filter-date-from"',
            'id="filter-date-to"',
            'id="clear-date-filters"',
        ] as $control) {
            $this->assertStringContainsString(
                $control,
                $this->pageSource,
                "The date filter control [{$control}] must remain on the page."
            );
        }

        // (3) And the range must still reach the API. Removing display text
        // must never quietly stop date_from/date_to being sent.
        $this->assertStringContainsString('date_from', $this->pageSource);
        $this->assertStringContainsString('date_to', $this->pageSource);
    }

    /**
     * The Lifecycle column was removed from the All Reports table — UI ONLY.
     *
     * The two halves of this test are the whole point of the change: the
     * COLUMN goes, the DATA stays. `damage_report_status` is still selected by
     * ReportController::index() and still returned on every report (pinned
     * separately by ReportUnifiedReadSurfaceTest); this page simply stopped
     * painting a column for it. Nothing about damage_reports, its history, or
     * the Damage Report module was touched.
     */
    public function test_the_lifecycle_column_is_removed_from_the_all_reports_table(): void
    {
        $this->assertStringNotContainsString(
            "html += '<th>Lifecycle</th>';",
            $this->pageSource,
            'The All Reports table must no longer render a Lifecycle header.'
        );
        $this->assertStringNotContainsString(
            'getLifecycleBadge(report)',
            $this->pageSource,
            'The All Reports table must no longer render a Lifecycle cell per row.'
        );
        $this->assertStringNotContainsString(
            'function getLifecycleBadge(',
            $this->pageSource,
            'The Lifecycle-only badge helper must not be left behind as dead code.'
        );

        // The remaining columns, in order. Pinned as a sequence so a future
        // edit cannot drop or reorder one silently — the column order is
        // load-bearing for the position-based width rules in
        // reports.inline1.css.
        //
        // Later superseded the twelve-column list this test originally
        // pinned: the "ALL REPORTS TABLE RESPONSIVENESS" task (see the TASK
        // comment above displayReports() in reports.php) merged ID into the
        // Report cell (stacked above the title) and dropped Type and Created
        // By as standalone columns — none of that data was removed from the
        // API/DB, only which columns this one table paints. Nine columns
        // remain.
        $expected = [
            'Report', 'Department', 'Priority', 'Status', 'Resolution',
            'Assigned To', 'Location', 'Date', 'Actions',
        ];
        preg_match_all("/html \+= '<th>([^<]+)<\/th>';/", $this->pageSource, $m);
        $this->assertSame(
            $expected,
            $m[1],
            'The All Reports table must render exactly these columns, in this order.'
        );

        // The backend behind the removed column is untouched: the controller
        // still joins damage_reports and still selects its status. This is a
        // source assertion rather than a live API call on purpose — the
        // response-shape contract is already owned by
        // ReportUnifiedReadSurfaceTest (which seeds the damage_reports table
        // this class deliberately does not build), and duplicating it here
        // would be a second, weaker copy of somebody else's test.
        $controller = (string) file_get_contents(
            base_path('app/Http/Controllers/Api/ReportController.php')
        );
        $this->assertStringContainsString(
            'damage_report_status',
            $controller,
            'Removing the Lifecycle COLUMN must not remove the lifecycle FIELD from the API.'
        );
    }

    /**
     * §4 — the width rules in reports.inline1.css address columns BY POSITION,
     * so removing a column silently re-points them. This pins the re-index.
     *
     * Originally: nowrap on td 1, 3, 4, 7, 8 = ID, Department, Priority,
     * Lifecycle, Assigned To; then re-indexed to 1, 3, 4, 7 = ID, Department,
     * Priority, Assigned To once Lifecycle was removed.
     *
     * Later superseded by the "ALL REPORTS TABLE RESPONSIVENESS" task (see
     * the TASK comment above displayReports() in reports.php and the
     * matching comment in reports.inline1.css): ID merged into the Report
     * cell and Type/Created By were dropped as standalone columns, taking
     * the table from 12 to 9 columns. The nowrap rule was re-indexed again to
     * 3, 4, 5, 8 = Priority, Status, Resolution, Date — the short, fixed-
     * vocabulary badge/date columns. Report, Department, Assigned To and
     * Location are left to wrap normally.
     */
    public function test_the_column_width_rules_were_reindexed_for_the_removed_column(): void
    {
        $css = (string) file_get_contents(
            base_path('public/frontend/assets/css/reports.inline1.css')
        );

        foreach ([3, 4, 5, 8] as $n) {
            $this->assertStringContainsString(
                ".reports-page-container .reports-table td:nth-child({$n})",
                $css,
                "Column {$n} of the reports table must keep its nowrap rule."
            );
        }
        $this->assertStringNotContainsString(
            '.reports-page-container .reports-table td:nth-child(7)',
            $css,
            'Column 7 is Location in the current 9-column layout and must not carry a stale nowrap rule.'
        );

        // The no-JS fallback table never had a Lifecycle column, so its own
        // rules must be unchanged — including slot 8 (Actions), which is what
        // keeps the View/Edit buttons on one line.
        foreach ([1, 3, 4, 7, 8] as $n) {
            $this->assertStringContainsString(
                ".reports-page-container .reports-fallback-table td:nth-child({$n})",
                $css,
                "The fallback table must keep its original nowrap rule on column {$n}."
            );
        }
    }

    /**
     * Explicit, caller-supplied scopes are NOT the bug and must survive.
     * super-admin-dashboard.php still links to reports.php?last_month=1.
     */
    public function test_explicit_url_scopes_are_preserved(): void
    {
        $this->assertStringContainsString("urlParams.get('last_month') === '1'", $this->pageSource);
        $this->assertStringContainsString('getLastMonthDateRange()', $this->pageSource);
        $this->assertStringContainsString("dateScopeParam === 'today'", $this->pageSource);
        $this->assertStringContainsString(
            "\$isLastMonth = isset(\$_GET['last_month']) && \$_GET['last_month'] === '1';",
            $this->pageSource,
            'The PHP fallback must still honour ?last_month=1.'
        );
    }

    /**
     * "Clear Date" resets to the current month — the SAME scope a plain load
     * uses, so the two agree. This was once filed as ISS-03 (a suspected bug)
     * back when the default was all-time and the two disagreed; with the
     * current-month default restored it is simply correct behaviour, and this
     * assertion now guards it rather than merely recording it as untouched.
     */
    public function test_clear_date_resets_to_the_current_month(): void
    {
        $this->assertMatchesRegularExpression(
            "/clear-date-filters'\)\.addEventListener\('click',\s*\(\)\s*=>\s*\{\s*const range = getCurrentMonthDateRange\(\);/",
            $this->pageSource,
            '"Clear Date" must re-apply the current month, not clear to "All dates".'
        );
    }

    /**
     * reports.php with its HTML comments and C-style block comments removed.
     *
     * Absence assertions about what the page DISPLAYS have to ignore prose
     * that merely discusses the removed feature, otherwise a file becomes
     * unable to document its own history. Only comment forms that cannot
     * appear inside the page's string literals are stripped — `//` comments
     * are deliberately left alone, since stripping those would also eat the
     * `//` in every URL in the file and could hide a real regression.
     */
    private function sourceWithoutComments(): string
    {
        $stripped = preg_replace('/<!--.*?-->/s', '', $this->pageSource);
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

        return (string) $stripped;
    }

    private function seedAdminWithReports(): int
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        foreach (self::REAL_REPORT_DATES as $i => $date) {
            DB::table('maintenance_reports')->insert([
                'title' => 'Report ' . ($i + 1),
                'description' => 'Seeded for the ISS-02 date-scope regression test.',
                'location' => 'Room 101',
                'priority' => 'medium',
                'status' => 'submitted',
                'created_by' => $adminId,
                'assigned_to' => null,
                'department_id' => null,
                'created_at' => $date . ' 09:00:00',
                'updated_at' => null,
            ]);
        }

        return $adminId;
    }
}
