<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DashboardController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * TASK 53 — DASHBOARD & ANALYTICS AUDIT.
 *
 * DashboardController::maintenanceStats()/maintenanceCharts()/superAdminCharts()
 * cannot be exercised end-to-end through this project's existing
 * Feature-test harness (an isolated in-memory SQLite connection — see
 * tests/Support/ConfiguresIsolatedSqliteConnection.php), because all three
 * methods embed raw MySQL-only SQL (TIMESTAMPDIFF, DATE_FORMAT, DATE_SUB,
 * bare MONTH()/YEAR() calls, ORDER BY FIELD(...)) that SQLite does not
 * support — the query throws before a response is ever produced. This is
 * the same limitation AnalyticsRbacTest already documents and works around
 * by only exercising SQLite-compatible endpoints; it predates Task 53 and
 * is not something this task's scope extends to rearchitecting.
 *
 * Both of Task 53's confirmed DashboardController defects live entirely in
 * pure, DB-free PHP inside those methods, so they were extracted into two
 * small private static helpers — monthDateBounds() and colorForLabel() —
 * specifically so they could be regression-tested directly (via Reflection,
 * since they're private) without needing a working DB connection at all.
 * This proves the fix at the exact site of the bug, independent of the
 * surrounding raw-SQL/DB-driver concerns.
 */
class DashboardControllerHelpersTest extends TestCase
{
    private function callMonthDateBounds(int $year, int $month): array
    {
        $method = new ReflectionMethod(DashboardController::class, 'monthDateBounds');
        $method->setAccessible(true);

        return $method->invoke(null, $year, $month);
    }

    private function callColorForLabel(string $label, array $colorMap, bool $normalizeSpaces): string
    {
        $method = new ReflectionMethod(DashboardController::class, 'colorForLabel');
        $method->setAccessible(true);

        return $method->invoke(null, $label, $colorMap, $normalizeSpaces);
    }

    /**
     * Pulls the REAL color map out of the controller (rather than a copy
     * declared in this test file), so removing a key from production code
     * fails these tests instead of silently passing against a stale local
     * duplicate.
     */
    private function realColorMap(string $mapMethod): array
    {
        $method = new ReflectionMethod(DashboardController::class, $mapMethod);
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    /**
     * DEFECT #1 (fixed) — before the fix, $dateTo was a bare 'Y-m-d' string
     * (e.g. '2026-02-28'). Under both MySQL's implicit DATETIME cast and
     * SQLite's lexicographic string comparison, any created_at timestamp
     * later than exactly midnight on the last day of the month sorted
     * GREATER than that bare date string, so `created_at <= $dateTo` wrongly
     * excluded it. This test proves the fix directly on the string values
     * monthDateBounds() now returns, using plain string/date comparison —
     * exactly the comparison MySQL/SQLite perform against these bounds.
     */
    public function test_month_date_bounds_include_the_entire_last_day_of_the_month(): void
    {
        // February 2026 is NOT a leap year (2026 / 4 has a remainder), so
        // the last day is the 28th — the exact scenario the audit found.
        [$dateFrom, $dateTo] = $this->callMonthDateBounds(2026, 2);

        $this->assertSame('2026-02-01', $dateFrom);

        $lastMomentOfMonth = '2026-02-28 23:59:59';
        $justAfterMidnightOnLastDay = '2026-02-28 08:15:00';
        $lateEveningOnLastDay = '2026-02-28 23:58:59';

        // Before the fix, $dateTo === '2026-02-28' and each of these three
        // DATETIME values (all string-greater-than a bare date) would have
        // failed this <= comparison, reproducing the audit's reported bug.
        $this->assertLessThanOrEqual($dateTo, $justAfterMidnightOnLastDay);
        $this->assertLessThanOrEqual($dateTo, $lateEveningOnLastDay);
        $this->assertLessThanOrEqual($dateTo, $lastMomentOfMonth);

        // And it must NOT swallow the first moment of the next month.
        $firstMomentOfNextMonth = '2026-03-01 00:00:00';
        $this->assertGreaterThan($dateTo, $firstMomentOfNextMonth);
    }

    public function test_month_date_bounds_handles_leap_february(): void
    {
        // 2028 is a leap year — last day of February is the 29th.
        [, $dateTo] = $this->callMonthDateBounds(2028, 2);

        $this->assertSame('2028-02-29 23:59:59', $dateTo);
        $this->assertLessThanOrEqual($dateTo, '2028-02-29 20:00:00');
    }

    public function test_month_date_bounds_handles_a_31_day_month(): void
    {
        [$dateFrom, $dateTo] = $this->callMonthDateBounds(2026, 1);

        $this->assertSame('2026-01-01', $dateFrom);
        $this->assertSame('2026-01-31 23:59:59', $dateTo);
    }

    /**
     * DEFECT #6 (fixed) — a REGRESSION introduced by Defect #1's own fix.
     *
     * maintenanceStats()'s overdue KPI tests `due_date < ?`. `due_date` is a
     * DATE column (2026_03_27_000400_create_maintenance_reports_table), and
     * the `<` is strict, so the intended meaning is "due strictly before the
     * end of the selected period". Once $dateTo gained its '23:59:59' suffix,
     * passing $dateTo into that slot silently relaxed the strict `<` into an
     * effective `<=` against the last calendar day — every report due ON the
     * last day of the month (due today, not yet late) would have been wrongly
     * counted as overdue.
     *
     * The fix returns a third, time-less bound used for exactly this one
     * comparison. This test pins the two values apart, so collapsing them
     * back into one (the natural "cleanup" someone would attempt on seeing
     * two near-identical dates) fails here instead of silently inflating the
     * overdue count on the last day of every month.
     */
    public function test_due_date_cutoff_is_time_less_and_excludes_reports_due_on_the_last_day(): void
    {
        [, $dateTo, $dueDateCutoff] = $this->callMonthDateBounds(2026, 2);

        // Same calendar day, deliberately different values.
        $this->assertSame('2026-02-28 23:59:59', $dateTo);
        $this->assertSame('2026-02-28', $dueDateCutoff);
        $this->assertNotSame($dateTo, $dueDateCutoff, 'The overdue cutoff must not reuse the DATETIME upper bound.');

        // A report due ON the last day is NOT yet overdue under `due_date < ?`.
        $dueOnLastDay = '2026-02-28';
        $this->assertFalse(
            $dueOnLastDay < $dueDateCutoff,
            'A report due on the last day of the month must not count as overdue.'
        );
        // ...but it WOULD have, incorrectly, against the '23:59:59' bound.
        $this->assertTrue($dueOnLastDay < $dateTo, 'Guards the exact regression this bound prevents.');

        // A report due the day before genuinely is overdue.
        $this->assertTrue('2026-02-27' < $dueDateCutoff);
    }

    /**
     * DEFECT #2 (fixed) — 'critical' was missing from superAdminCharts()'s
     * $priorityColors map, so a 'Critical'-priority bar fell back to the
     * generic gray ('#6b7280') instead of a distinct color, unlike Task 46's
     * frontend fix for the same value in UI.getPriorityBadge().
     */
    public function test_critical_priority_label_resolves_to_a_distinct_non_fallback_color(): void
    {
        $priorityColors = $this->realColorMap('priorityColorMap');

        $this->assertArrayHasKey('critical', $priorityColors);

        // 'Critical' is exactly the ucfirst()'d label superAdminCharts()
        // builds from a 'critical' priority row.
        $color = $this->callColorForLabel('Critical', $priorityColors, false);

        $this->assertNotSame('#6b7280', $color, 'Critical must not fall back to the generic gray color.');

        // ...and it must be distinct from every other priority's color, not
        // just non-gray — a duplicate would make the bar indistinguishable
        // from 'urgent' in the chart.
        $this->assertSame(
            count($priorityColors),
            count(array_unique($priorityColors)),
            'Every priority must map to a visually distinct color.'
        );
    }

    /**
     * DEFECT #3 (fixed) — 'closed' was missing from superAdminCharts()'s
     * $statusColors map, so a 'Closed'-status segment fell back to the same
     * generic gray as an unrecognized status, even though 'closed' is one
     * of the 6 canonical maintenance_reports.status values.
     *
     * IT-expert status-color unification (2026-09-30): 'closed' was later
     * *deliberately* assigned the same hex ('#6b7280') that the fallback
     * branch also happens to use, as part of a 6-status palette where Closed
     * is intentionally neutral gray ("archived/final") to distinguish it
     * from Completed's green. So this no longer asserts "not gray" (that
     * would now fail on a legitimate, intentional value) — instead it pins
     * the exact expected color, which still fails loudly if 'closed' is ever
     * accidentally dropped from the map (assertArrayHasKey below) or its
     * color silently changed.
     */
    public function test_closed_status_label_resolves_to_its_dedicated_gray_color(): void
    {
        $statusColors = $this->realColorMap('statusColorMap');

        $this->assertArrayHasKey('closed', $statusColors);

        $color = $this->callColorForLabel('Closed', $statusColors, true);

        $this->assertSame('#6b7280', $color, "Closed is intentionally neutral gray in the unified status palette.");
        $this->assertSame(
            count($statusColors),
            count(array_unique($statusColors)),
            'Every status must map to a visually distinct color.'
        );
    }

    /**
     * Guards the root cause behind both Defect #2 and Defect #3: these maps
     * drifted out of sync with the DB enums as new status/priority values
     * were added by later tasks. This pins every canonical value from the
     * migrations so the next added value fails here instead of silently
     * rendering gray on the Super Admin dashboard.
     */
    public function test_color_maps_cover_every_canonical_status_and_priority_value(): void
    {
        $statusColors = $this->realColorMap('statusColorMap');
        $priorityColors = $this->realColorMap('priorityColorMap');

        // maintenance_reports.status — 5 from 2026_03_27_000400_create_maintenance_reports_table
        // plus 'assigned' from 2026_07_28_000800_add_assigned_status_to_maintenance_reports_table.
        foreach (['submitted', 'assigned', 'in_progress', 'completed', 'closed', 'cancelled'] as $status) {
            $this->assertArrayHasKey($status, $statusColors, "Status '{$status}' has no chart color.");
        }

        // maintenance_reports.priority enum — 2026_03_27_000400_create_maintenance_reports_table.
        foreach (['low', 'medium', 'high', 'urgent', 'critical'] as $priority) {
            $this->assertArrayHasKey($priority, $priorityColors, "Priority '{$priority}' has no chart color.");
        }
    }

    public function test_unrecognized_label_still_falls_back_to_gray(): void
    {
        $color = $this->callColorForLabel('SomeFutureStatus', ['submitted' => '#3b82f6'], true);

        $this->assertSame('#6b7280', $color);
    }
}
