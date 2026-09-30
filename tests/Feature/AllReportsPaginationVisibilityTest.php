<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ALL REPORTS PAGINATION VISIBILITY.
 *
 * The All Reports page always rendered its pagination/status bar as soon as
 * there was at least one report, so a single report produced a "Showing 1-1 of
 * 1 reports" summary plus a lone, dead page button. The requirement is that the
 * ENTIRE pagination section disappears whenever there is nothing to paginate.
 *
 * The condition was originally "all rows fit on one page" (totalPages <= 1). It
 * was later changed on request to a fixed report-count threshold: the bar
 * appears once the list has PAGINATION_MIN_REPORTS (10) or more reports and is
 * hidden below that, whatever page size is selected. That also removes a trap
 * in the old rule — choosing a page size large enough to fit every report hid
 * the rows-per-page control itself, leaving no way to switch back. The default
 * page size moved from 20 to 10 at the same time, so 11+ reports actually span
 * more than one page.
 *
 * NOTE ON METHOD: All Reports pagination is implemented entirely in the
 * client-side script embedded in reports.php — there is no controller, route or
 * query involved, and no JS runtime is available to this suite. So rather than
 * hand-copying the rule into PHP and testing the copy, these tests PARSE the
 * real page size and the real page-count formula out of reports.php and pin the
 * real guard expression. The threshold cases below are then computed from those
 * parsed values: if someone changes the default page size, the page-count
 * formula or the guard, these tests fail rather than silently drifting.
 */
class AllReportsPaginationVisibilityTest extends TestCase
{
    private function reportsPage(): string
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));
        $this->assertNotFalse($markup, 'reports.php could not be read.');

        return $markup;
    }

    private function reportsStylesheet(): string
    {
        $css = file_get_contents(base_path('public/frontend/assets/css/reports.inline1.css'));
        $this->assertNotFalse($css, 'reports.inline1.css could not be read.');

        return $css;
    }

    private function renderPaginationSource(): string
    {
        $markup = $this->reportsPage();

        $start = strpos($markup, 'function renderPagination(');
        $this->assertNotFalse($start, 'renderPagination() has disappeared from reports.php.');

        $end = strpos($markup, "\n}", $start);
        $this->assertNotFalse($end, 'Could not find the end of renderPagination().');

        return substr($markup, $start, $end - $start);
    }

    /**
     * The default page size, read from the page itself rather than assumed.
     */
    private function defaultRowsPerPage(): int
    {
        $this->assertSame(
            1,
            preg_match('/let\s+rowsPerPage\s*=\s*(\d+)\s*;/', $this->reportsPage(), $matches),
            'reports.php must declare exactly one default rowsPerPage.'
        );

        return (int) $matches[1];
    }

    /**
     * The visibility threshold, read from the page itself rather than assumed.
     */
    private function minimumReportsForPagination(): int
    {
        $this->assertSame(
            1,
            preg_match('/const\s+PAGINATION_MIN_REPORTS\s*=\s*(\d+)\s*;/', $this->reportsPage(), $matches),
            'reports.php must declare exactly one PAGINATION_MIN_REPORTS threshold.'
        );

        return (int) $matches[1];
    }

    /**
     * Transcription of the guard in renderPagination():
     * test_pagination_is_hidden_by_a_single_parent_condition() pins the real
     * source against this, so the two cannot drift apart unnoticed.
     */
    private function paginationIsVisible(int $totalReports): bool
    {
        return $totalReports >= $this->minimumReportsForPagination();
    }

    // ---------------------------------------------------------------------
    // The guard itself
    // ---------------------------------------------------------------------

    /**
     * One parent condition, guarding the whole section — not a per-element
     * hide, and not a second copy of the paging maths.
     */
    public function test_pagination_is_hidden_by_a_single_parent_condition(): void
    {
        $source = $this->renderPaginationSource();

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*totalReports\s*<\s*PAGINATION_MIN_REPORTS\s*\)\s*\{\s*container\.innerHTML\s*=\s*\'\'\s*;\s*return\s*;\s*\}/',
            $source,
            'renderPagination() must bail out through one guard that empties the whole container.'
        );

        $this->assertSame(
            1,
            preg_match_all('/container\.innerHTML\s*=\s*\'\'/', $source),
            'There should be exactly one place that clears the pagination container.'
        );
    }

    /**
     * The old behaviour — hide only at zero — must be gone, otherwise 1..N
     * reports would still paint a summary and a dead page button.
     */
    public function test_the_old_hide_only_when_empty_guard_is_gone(): void
    {
        $this->assertStringNotContainsString(
            'totalReports === 0',
            $this->renderPaginationSource(),
            'The zero-only guard was the bug; it must not survive alongside the new one.'
        );
    }

    /**
     * The guard reuses state renderReportsView() already computed, so the page
     * count is worked out once.
     */
    public function test_the_guard_reuses_the_existing_page_state(): void
    {
        $markup = $this->reportsPage();

        $this->assertStringContainsString(
            'renderPagination(totalReports, totalPages, startIndex);',
            $markup,
            'renderReportsView() must keep handing renderPagination() the counts it already calculated.'
        );

        $this->assertStringContainsString(
            'function renderPagination(totalReports, totalPages, startIndex)',
            $markup,
            'renderPagination()\'s signature must be unchanged.'
        );

        // No second Math.ceil / page-count calculation inside renderPagination().
        $this->assertStringNotContainsString(
            'Math.ceil',
            $this->renderPaginationSource(),
            'renderPagination() must not recompute the page count; that is renderReportsView()\'s job.'
        );
    }

    public function test_page_count_formula_is_unchanged(): void
    {
        $this->assertStringContainsString(
            'const totalPages = Math.max(1, Math.ceil(totalReports / rowsPerPage));',
            $this->reportsPage(),
            'The page count is still worked out once, in renderReportsView().'
        );
    }

    // ---------------------------------------------------------------------
    // Threshold behaviour
    // ---------------------------------------------------------------------

    /**
     * The requirement: pagination shows from 10 reports up, and not below.
     */
    public function test_the_threshold_is_ten_reports(): void
    {
        $this->assertSame(10, $this->minimumReportsForPagination());

        foreach ([0, 1, 5, 9] as $totalReports) {
            $this->assertFalse(
                $this->paginationIsVisible($totalReports),
                "With {$totalReports} reports the pagination must be hidden."
            );
        }

        foreach ([10, 11, 20, 150] as $totalReports) {
            $this->assertTrue(
                $this->paginationIsVisible($totalReports),
                "With {$totalReports} reports the pagination must be visible."
            );
        }
    }

    /**
     * The threshold must not depend on the selected page size, otherwise
     * choosing a large page size would hide the rows-per-page control that
     * is needed to switch back.
     */
    public function test_the_guard_does_not_depend_on_page_size(): void
    {
        $source = $this->renderPaginationSource();

        $start = strpos($source, 'if (totalReports < PAGINATION_MIN_REPORTS)');
        $this->assertNotFalse($start);
        $guardLine = substr($source, $start, strpos($source, "\n", $start) - $start);

        $this->assertStringNotContainsString('rowsPerPage', $guardLine);
        $this->assertStringNotContainsString('totalPages', $guardLine);
    }

    /**
     * The default page size matches the threshold, so a list just past it
     * really does span a second page.
     */
    public function test_the_shipped_default_page_size_is_ten(): void
    {
        $this->assertSame(10, $this->defaultRowsPerPage());
    }

    // ---------------------------------------------------------------------
    // Pagination still works when it IS shown
    // ---------------------------------------------------------------------

    /**
     * The component was not redesigned: summary, first/prev/next/last, the
     * numbered buttons and the rows-per-page control all still render.
     */
    public function test_the_pagination_markup_is_unchanged_when_it_renders(): void
    {
        $source = $this->renderPaginationSource();

        $this->assertStringContainsString('reports-pagination-summary', $source);
        $this->assertStringContainsString('of <strong>${totalReports}</strong> reports', $source);
        $this->assertStringContainsString('reports-pagination-controls', $source);
        $this->assertStringContainsString('pagination-prev', $source);
        $this->assertStringContainsString('pagination-next', $source);
        $this->assertStringContainsString('data-page="${page}"', $source);
        $this->assertStringContainsString('id="rows-per-page-select"', $source);

        foreach ([5, 7, 10, 20] as $option) {
            $this->assertStringContainsString(
                "<option value=\"{$option}\"",
                $source,
                "The rows-per-page option {$option} must survive."
            );
        }
    }

    /**
     * Page buttons are still generated one per page, so navigating between
     * pages keeps working for result sets that are large enough to show the
     * bar at all.
     */
    public function test_every_page_still_gets_a_button(): void
    {
        $this->assertStringContainsString(
            'for (let page = 1; page <= totalPages; page += 1)',
            $this->renderPaginationSource(),
            'The page-button loop must be untouched.'
        );
    }

    /**
     * Emptying the container is only safe because the click/change handlers are
     * delegated to the container itself rather than bound to the buttons. If
     * that ever changed, hiding the bar would permanently kill pagination.
     */
    public function test_pagination_handlers_are_delegated_to_the_container(): void
    {
        $markup = $this->reportsPage();

        $this->assertStringContainsString(
            "document.getElementById('pagination-container').addEventListener('click'",
            $markup
        );
        $this->assertStringContainsString(
            "document.getElementById('pagination-container').addEventListener('change'",
            $markup
        );

        // The container element is always in the document; only its contents
        // come and go.
        $this->assertStringContainsString('<nav id="pagination-container"', $markup);
    }

    // ---------------------------------------------------------------------
    // The emptied container must not leave a visible bar
    // ---------------------------------------------------------------------

    public function test_an_empty_pagination_container_is_not_painted(): void
    {
        $css = $this->reportsStylesheet();

        $this->assertMatchesRegularExpression(
            '/\.reports-pagination:empty\s*\{[^}]*display:\s*none/',
            $css,
            '.reports-pagination carries its own padding, border and background, so the empty container must be display:none.'
        );

        // The styling that made the empty bar visible is still there for the
        // populated case — the fix is additive, not a gutting of the component.
        $this->assertMatchesRegularExpression(
            '/\.reports-pagination\s*\{[^}]*display:\s*flex/',
            $css
        );
    }

    public function test_the_stylesheet_cache_token_was_bumped(): void
    {
        $markup = $this->reportsPage();

        $this->assertStringNotContainsString(
            'reports.inline1.css?v=20260806-2',
            $markup,
            'The stale token would serve a cached stylesheet without the :empty rule.'
        );

        $this->assertMatchesRegularExpression(
            '/reports\.inline1\.css\?v=[0-9a-z-]+/',
            $markup,
            'The stylesheet must still be cache-busted.'
        );
    }

    // ---------------------------------------------------------------------
    // Nothing else on the page moved
    // ---------------------------------------------------------------------

    /**
     * The surrounding All Reports functionality the brief put off-limits —
     * filtering, the report-type classification filter, the month/week filter
     * and export — is untouched.
     */
    public function test_the_rest_of_the_all_reports_page_is_intact(): void
    {
        $markup = $this->reportsPage();

        $this->assertStringContainsString('function applyStatusGroupFilter', $markup);
        $this->assertStringContainsString('function getReportsBySelectedWeek', $markup);
        $this->assertStringContainsString('function renderWeekPagination', $markup);
        $this->assertStringContainsString('id="print-report-btn"', $markup);

        // renderReportsView() still slices and renders the page of rows the
        // same way; only the pagination bar's visibility changed.
        $this->assertStringContainsString('const pagedReports = filteredReports.slice(startIndex, endIndex);', $markup);
        $this->assertStringContainsString('displayReports(pagedReports);', $markup);
    }

    /**
     * The week/month pagination above the table is a different control with its
     * own renderer; the fix must not have leaked into it.
     */
    public function test_the_week_filter_control_was_not_touched(): void
    {
        $markup = $this->reportsPage();

        $weekStart = strpos($markup, 'function renderWeekPagination(');
        $this->assertNotFalse($weekStart);

        $weekEnd = strpos($markup, "\n}", $weekStart);
        $weekSource = substr($markup, $weekStart, $weekEnd - $weekStart);

        $this->assertStringNotContainsString(
            'totalPages <= 1',
            $weekSource,
            'The new guard belongs to renderPagination() only.'
        );
    }
}
