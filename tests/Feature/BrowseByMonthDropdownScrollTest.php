<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * BROWSE BY MONTH — the dropdown must be able to show every month it renders.
 *
 * THE BUG
 * -------
 * #month-picker-dropdown is absolutely positioned inside .card-header. The shared
 * `.card` rule in styles.css carries `overflow: hidden` so the card can clip its
 * children to its 14px radius — and that clipped the open dropdown too. Measured
 * on the live page before the fix: the card ended at y=507 while the open dropdown
 * ended at y=556, so the final month sat entirely below the card's edge and the one
 * above it was sliced in half.
 *
 * WHY IT LOOKED INTERMITTENT
 * --------------------------
 * The card's height is driven by the report table. A long table pushes the card
 * past the bottom of the dropdown and the bug vanishes; a short table (or a month
 * with few reports) brings it back. That is why it reads as "sometimes the months
 * are cut off" rather than as a layout rule that is simply wrong.
 *
 * THE TWO GUARANTEES PINNED HERE
 * ------------------------------
 * 1. The card no longer clips the dropdown  (an escape hatch).
 * 2. The month list is independently bounded and scrollable  (a belt).
 *
 * Both matter. (1) alone would leave the list free to grow past the bottom of the
 * VIEWPORT in a December-length list on a short screen. (2) alone would keep the
 * list short but still let the card guillotine it. Either one regressing on its own
 * reintroduces a clipped month, so both are asserted.
 *
 * WHAT IS DELIBERATELY *NOT* CHANGED
 * ----------------------------------
 * Which months are offered. reports.php caps the list at the current month, so in
 * September the list is January–September and October–December are not rendered at
 * all. That cap is existing month-selection behaviour and is explicitly out of scope
 * for a scrolling fix, so the guard is pinned below as a REGRESSION GUARD — this
 * test fails if a future "make all months accessible" change quietly removes it.
 *
 * Asserted against source: the dropdown is styled by a stylesheet and positioned by
 * the browser, so there is no server-rendered state for a request test to inspect,
 * and the suite has no JS runtime. This mirrors AllReportsPaginationVisibilityTest.
 */
class BrowseByMonthDropdownScrollTest extends TestCase
{
    private const PAGE = 'public/frontend/pages/reports.php';
    private const CSS = 'public/frontend/assets/css/reports.inline1.css';

    private function read(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path, $relativePath . ' is missing.');

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, $relativePath . ' could not be read.');

        return (string) $contents;
    }

    /**
     * The stylesheet with its explanatory comments removed.
     *
     * The new rules ship with a comment block that quotes `overflow: hidden` while
     * explaining what went wrong. Absence assertions have to run against the code
     * rather than the prose, or the documentation of the fix reads as the bug.
     */
    private function cssCodeOnly(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::CSS));
    }

    /**
     * reports.php with its comments removed.
     *
     * Same hazard as the stylesheet, and worse here: reports.php carries two long
     * comments that QUOTE the removed date-range summary text while explaining why
     * it was removed. An absence assertion run against the raw file would read that
     * explanation as the thing it is explaining and fail.
     */
    private function pageCodeOnly(): string
    {
        $stripped = preg_replace('/<!--.*?-->/s', '', $this->read(self::PAGE));
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

        return (string) $stripped;
    }

    /** The month buttons live inside a dedicated scroll container. */
    public function test_the_month_options_are_wrapped_in_a_scroll_container(): void
    {
        $markup = $this->read(self::PAGE);

        $this->assertStringContainsString(
            '<div class="month-picker-list">',
            $markup,
            'The .month-picker-list wrapper is gone; the month buttons have nothing to scroll inside.'
        );

        $listStart = strpos($markup, '<div class="month-picker-list">');
        $buttonAt = strpos($markup, 'class="month-picker-item"');

        $this->assertNotFalse($buttonAt, 'The month option buttons have disappeared.');
        $this->assertGreaterThan(
            $listStart,
            $buttonAt,
            'The month buttons must be INSIDE .month-picker-list or the wrapper cannot scroll them.'
        );
    }

    /** The list is bounded, scrollable, and sized against the viewport rather than a desktop guess. */
    public function test_the_month_list_is_bounded_and_scrolls_on_its_own(): void
    {
        $css = $this->cssCodeOnly();

        $this->assertMatchesRegularExpression(
            '/#month-picker-dropdown\s+\.month-picker-list\s*\{[^}]*overflow-y:\s*auto/s',
            $css,
            'The month list no longer scrolls; a long list will be cut off instead.'
        );

        $this->assertMatchesRegularExpression(
            '/#month-picker-dropdown\s+\.month-picker-list\s*\{[^}]*max-height:[^;]+/s',
            $css,
            'Without a max-height the list has nothing to scroll against and grows unbounded.'
        );

        // The height must react to the viewport. A bare pixel value would look fine
        // on a 900px desktop and push the dropdown off the bottom of a 480px screen,
        // which is the exact failure this task was asked not to introduce.
        $this->assertMatchesRegularExpression(
            '/#month-picker-dropdown\s+\.month-picker-list\s*\{[^}]*max-height:[^;]*vh/s',
            $css,
            'max-height must be viewport-relative; a hardcoded desktop height breaks small screens.'
        );
    }

    /** The card's radius clip no longer guillotines the open dropdown. */
    public function test_the_reports_card_no_longer_clips_the_dropdown(): void
    {
        $css = $this->cssCodeOnly();

        $this->assertMatchesRegularExpression(
            '/\.reports-page-container\s+\.card\s*\{[^}]*overflow:\s*visible/s',
            $css,
            'The reports card is clipping its overflow again, which cuts off the lower months.'
        );

        // Scoped to this page on purpose. styles.css is shared by every card in the
        // application, so the shared `.card` rule must be left exactly as it was —
        // releasing the clip there would change every card in the system.
        $this->assertMatchesRegularExpression(
            '/^\.card\s*\{[^}]*overflow:\s*hidden/m',
            $this->read('public/frontend/assets/css/styles.css'),
            'The shared .card rule was modified; the fix must stay scoped to the reports page.'
        );
    }

    /** A scrollbar the user can actually see, matching the existing sidebar convention. */
    public function test_the_scrollbar_is_visible_and_themed(): void
    {
        $css = $this->cssCodeOnly();

        $this->assertMatchesRegularExpression(
            '/#month-picker-dropdown\s+\.month-picker-list::-webkit-scrollbar\s*\{[^}]*width:\s*6px/s',
            $css,
            'The scrollbar track width is gone; the list gives no hint that it scrolls.'
        );

        $this->assertMatchesRegularExpression(
            '/#month-picker-dropdown\s+\.month-picker-list::-webkit-scrollbar-thumb\s*\{[^}]*background:\s*rgba\(168,\s*85,\s*247/s',
            $css,
            'The scrollbar thumb lost its brand colour.'
        );

        // Firefox has no ::-webkit-scrollbar; without these it renders a default
        // full-width scrollbar that does not match the theme.
        $this->assertMatchesRegularExpression(
            '/#month-picker-dropdown\s+\.month-picker-list\s*\{[^}]*scrollbar-width:\s*thin/s',
            $css,
            'scrollbar-width is missing, so Firefox falls back to an unthemed scrollbar.'
        );

        $this->assertStringNotContainsString(
            'scrollbar-width: none',
            $css,
            'Hiding the scrollbar entirely stops the user from realising more months exist.'
        );
    }

    /**
     * A stale stylesheet would leave the new wrapper unstyled.
     *
     * The markup change and the CSS change are not independent: browsers holding the
     * previous token would get the .month-picker-list wrapper with no rule matching
     * it, so it would have no max-height and the dropdown would stay clipped.
     */
    public function test_the_stylesheet_cache_token_was_bumped(): void
    {
        $markup = $this->read(self::PAGE);

        $this->assertStringNotContainsString(
            'reports.inline1.css?v=20260916"',
            $markup,
            'The token that shipped before the dropdown fix is still in use; the wrapper would load unstyled.'
        );

        $this->assertMatchesRegularExpression(
            '/reports\.inline1\.css\?v=[0-9a-z-]+/',
            $markup,
            'The stylesheet must still be cache-busted.'
        );
    }

    /** Keyboard users reach the months through real buttons, not divs. */
    public function test_the_month_options_are_still_focusable_controls(): void
    {
        $markup = $this->read(self::PAGE);

        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="month-picker-item"/',
            $markup,
            'Month options must stay native <button>s or they drop out of the tab order.'
        );

        $this->assertStringNotContainsString(
            'tabindex="-1"',
            substr($markup, (int) strpos($markup, 'month-picker-list'), 1200),
            'A negative tabindex would hide the months from keyboard users.'
        );
    }

    // -----------------------------------------------------------------
    // REGRESSION GUARDS — behaviour this UI fix must not have touched.
    // -----------------------------------------------------------------

    /** The month list is still capped at the current month. */
    public function test_future_months_are_still_not_offered(): void
    {
        $markup = $this->read(self::PAGE);

        $this->assertStringContainsString(
            'if ($mIdx + 1 > $currentMonth) continue;',
            $markup,
            'The current-month cap was removed. That changes which months can be filtered, '
            . 'which is month-selection behaviour, not dropdown scrolling.'
        );
    }

    /** Selecting a month still drives the existing date filter, untouched. */
    public function test_selecting_a_month_still_sets_the_existing_date_filters(): void
    {
        $markup = $this->read(self::PAGE);

        foreach ([
            "document.getElementById('filter-date-from').value = dateFrom;",
            "document.getElementById('filter-date-to').value = dateTo;",
            'filterReports();',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $markup,
                'The month-selection handler was modified; this task was UI-only.'
            );
        }
    }

    /** The two deliberately removed pieces of All Reports UI stay removed. */
    public function test_the_removed_all_reports_ui_was_not_reintroduced(): void
    {
        $markup = $this->pageCodeOnly();

        $this->assertStringNotContainsString(
            'Reports are limited to this date range',
            $markup,
            'The date-range summary text was intentionally removed and must not come back.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<th[^>]*>\s*Lifecycle\s*<\/th>/i',
            $markup,
            'The Lifecycle column was intentionally removed from All Reports.'
        );
    }
}
