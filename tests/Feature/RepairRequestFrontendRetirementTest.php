<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TASK 12 — Repair Request Frontend & Navigation Retirement.
 *
 * The USER-FACING half of the Repair Request module was withdrawn: the five
 * Repair pages were deleted, the sidebar nav entry (and its active-state list)
 * was removed, the notification deep-link entry was dropped, and the two
 * remaining cross-module links into Repair UI were taken out.
 *
 * This file is the regression gate for that retirement, and it is deliberately
 * two-sided:
 *
 *   - the "gone" half stops the user-facing module creeping back in, and stops
 *     any surviving page shipping a dead link to a deleted file;
 *   - the "still here" half pins what must SURVIVE — the shared
 *     damage/dispatch/personnel functionality that merely has "repair" in its
 *     name, and the primary-workflow navigation. Asserting those are untouched
 *     means an over-reaching cleanup fails here rather than silently
 *     amputating the primary workflow.
 *
 * TASK 13 — the application-level BACKEND retirement. Section 5 below used to
 * be the third strand: it pinned the scope boundary by asserting the Repair
 * backend was deliberately still alive, because Task 12 was forbidden from
 * touching it. Task 13 is the "separate, later task" that boundary pointed at,
 * so those assertions were INVERTED rather than dropped — they now pin the
 * backend's ABSENCE. Nothing in sections 1-4 changed; the frontend retirement
 * remains guarded exactly as before, and the "must survive" assertions are
 * still here and still enforced, which is what proves Task 13 did not
 * over-reach either.
 *
 * Everything is asserted against EXECUTED source — comments are stripped first
 * — because the retirement deliberately leaves explanatory comments behind that
 * name the very files and routes they no longer point at. That matters more
 * after Task 13, not less: routes/web.php now carries comment blocks naming
 * prefix('repairs') and the deleted controller in place of the code itself.
 */
class RepairRequestFrontendRetirementTest extends TestCase
{
    /** The five pages that existed solely to expose Repair Requests to users. */
    private const RETIRED_PAGES = [
        'repair-requests.php',
        'repair-detail.php',
        'repair-update.php',
        'repair-assignment.php',
        'replacement-request.php',
    ];

    // -----------------------------------------------------------------
    // 1. The pages are gone
    // -----------------------------------------------------------------

    public function test_every_repair_only_page_is_deleted(): void
    {
        foreach (self::RETIRED_PAGES as $page) {
            $this->assertFileDoesNotExist(
                base_path('public/frontend/pages/' . $page),
                "{$page} existed only to expose the Repair Request module and must stay retired."
            );
        }
    }

    /**
     * replacement-request.php (Repair, deleted) and replacement-tracking.php
     * (Inventory, kept) are one hyphen apart. Pinning the survivor stops a
     * future "tidy up the leftovers" pass deleting the wrong one.
     */
    public function test_the_similarly_named_inventory_page_was_not_collateral_damage(): void
    {
        $this->assertFileExists(
            base_path('public/frontend/pages/replacement-tracking.php'),
            'replacement-tracking.php belongs to Inventory, not to Repair Requests.'
        );
    }

    // -----------------------------------------------------------------
    // 2. The navigation is gone
    // -----------------------------------------------------------------

    public function test_the_sidebar_renders_no_repair_requests_nav_item(): void
    {
        $rendered = $this->executedSource('public/frontend/includes/sidebar.php');

        $this->assertStringNotContainsString(
            'data-page="repairs"',
            $rendered,
            'The Repair Requests nav item must not be rendered for any role.'
        );
        $this->assertStringNotContainsString(
            '<span class="nav-text">Repair Requests</span>',
            $rendered,
            'The Repair Requests nav label must be gone.'
        );
    }

    /**
     * A nav entry can be removed while its active-state array is left behind.
     * That array is the only other place the sidebar named these pages, so it
     * had to go with the entry it highlighted.
     */
    public function test_the_sidebar_keeps_no_active_state_entry_for_a_retired_page(): void
    {
        $rendered = $this->executedSource('public/frontend/includes/sidebar.php');

        foreach (self::RETIRED_PAGES as $page) {
            $this->assertStringNotContainsString(
                $page,
                $rendered,
                "The sidebar's active-state coverage must not still name the retired {$page}."
            );
        }
    }

    /**
     * The retirement must not have cost the primary workflow its entry points:
     * Create Report -> All Reports -> assign -> monitor/update -> resolve.
     */
    public function test_the_primary_workflow_navigation_survives(): void
    {
        $rendered = $this->executedSource('public/frontend/includes/sidebar.php');

        foreach (['dashboard', 'reports', 'inventory', 'dispatches', 'preventive-maintenance'] as $page) {
            $this->assertStringContainsString(
                'data-page="' . $page . '"',
                $rendered,
                "Retiring Repair Requests must not remove the '{$page}' nav item."
            );
        }

        $this->assertStringContainsString('<span class="nav-text">All Reports</span>', $rendered);
    }

    // -----------------------------------------------------------------
    // 3. No surviving page links at a deleted one
    // -----------------------------------------------------------------

    /**
     * The point of the retirement is that users cannot REACH Repair Requests.
     * A dead <a href> on a page that still ships would defeat that, so this
     * sweeps every frontend page/partial/script rather than the handful that
     * were known to link out at audit time.
     */
    public function test_no_surviving_frontend_file_links_to_a_retired_page(): void
    {
        $offenders = [];

        foreach ($this->frontendSourceFiles() as $path) {
            $executed = $this->stripComments((string) file_get_contents($path));

            foreach (self::RETIRED_PAGES as $page) {
                if (str_contains($executed, $page)) {
                    $offenders[] = substr($path, strlen(base_path()) + 1) . ' -> ' . $page;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Surviving frontend files still reference deleted Repair pages:\n" . implode("\n", $offenders)
        );
    }

    /**
     * maintenance-report-detail.php carried a live <a href="repair-detail.php">
     * row on a PRIMARY-workflow page. Removing it must not have taken the damage
     * and dispatch rows beside it, which the brief explicitly preserves.
     *
     * TASK 13 NOTE — this page is unchanged, and so is this assertion, but the
     * `replacement_dispatch_id` row's data source changed underneath it.
     * ReportController::show() could only ever populate that field by joining
     * through repair_requests.replacement_dispatch_id, which was the join key;
     * with the Repair join removed the field is no longer returned. The row is
     * individually guarded (`if (report.replacement_dispatch_id)`), so it now
     * simply does not render rather than rendering an undefined link, and
     * repair_requests holds zero rows so no report loses a row it displays
     * today. The markup is left in place deliberately: re-sourcing the field
     * would mean redesigning the relationship, which Task 13 puts out of
     * scope. Keeping the assertion keeps that decision visible.
     */
    public function test_the_maintenance_report_detail_keeps_its_damage_and_dispatch_information(): void
    {
        $executed = $this->executedSource('public/frontend/pages/maintenance-report-detail.php');

        $this->assertStringContainsString('damage_report_id', $executed);
        $this->assertStringContainsString('replacement_dispatch_id', $executed);
    }

    /**
     * damage-report-detail.php's "Repair / Resolution" block reads
     * damage_reports.repair_notes — Damage Report data, not Repair Request UI.
     * The brief keeps it; only the outbound link to /repairs was removed.
     */
    public function test_the_damage_report_detail_keeps_repair_notes_and_loses_only_the_repair_link(): void
    {
        $executed = $this->executedSource('public/frontend/pages/damage-report-detail.php');

        $this->assertStringContainsString('repair_notes', $executed, 'damage_reports.repair_notes must still render.');
        $this->assertStringContainsString('repairing', $executed, "The 'repairing' damage status must still render.");
        $this->assertStringContainsString('repaired', $executed, "The 'repaired' damage status must still render.");

        $this->assertStringNotContainsString(
            'damage-repair-link',
            $executed,
            'The Create Repair / Repair Workflow link must be gone.'
        );
        $this->assertStringNotContainsString(
            'REPAIRS_PAGE_BASE',
            $executed,
            'The /repairs base URL it was built from must go with it.'
        );
    }

    // -----------------------------------------------------------------
    // 4. Notifications degrade gracefully
    // -----------------------------------------------------------------

    public function test_notifications_no_longer_deep_link_to_the_repair_detail_page(): void
    {
        $executed = $this->executedSource('public/frontend/assets/js/notification.js');

        $this->assertStringNotContainsString(
            'repair_request:',
            $executed,
            'The repair_request entity route built a deep link to a deleted page.'
        );
        $this->assertStringNotContainsString('/api/repairs/', $executed);

        // The remaining entity routes — the ones the primary workflow and the
        // surviving modules actually use — must be untouched.
        foreach (['report:', 'damage_report:', 'dispatch:', 'user:'] as $entity) {
            $this->assertStringContainsString(
                $entity,
                $executed,
                "Removing repair_request must not have removed the {$entity} route."
            );
        }
    }

    // -----------------------------------------------------------------
    // 5. The backend is retired too (TASK 13)
    //
    // These two tests were INVERTED, not deleted. Under Task 12 they were the
    // SCOPE BOUNDARY: they asserted the backend was deliberately left ALIVE,
    // because Task 12 retired the frontend only and any backend removal at
    // that point would have been out of scope. Task 13 is the backend
    // retirement the old docblock called "a separate, later task", so both
    // assertions have flipped.
    //
    // Flipping rather than deleting matters: a deleted assertion pins
    // nothing, whereas these now fail if the Repair backend is reintroduced.
    // No coverage is lost — sections 1-4 above are untouched, so the frontend
    // retirement Task 12 established is still fully guarded.
    // -----------------------------------------------------------------

    public function test_the_repair_backend_is_retired(): void
    {
        foreach ([
            'app/Models/RepairRequest.php',
            'app/Models/RepairHistory.php',
            'app/Services/RepairService.php',
            'app/Http/Controllers/Api/RepairController.php',
        ] as $file) {
            $this->assertFileDoesNotExist(
                base_path($file),
                "{$file} was deleted in Task 13 (application-level Repair Request "
                . 'retirement) and must stay retired.'
            );
        }
    }

    /**
     * Asserted against executed source, not raw text: routes/web.php keeps a
     * comment block in place of the removed group that NAMES prefix('repairs')
     * to explain what was there and why each consumer no longer needs it. A
     * raw substring search would match that documentation and pass a file
     * whose routes really had come back.
     */
    public function test_the_repair_api_routes_are_retired(): void
    {
        $routes = $this->executedSource('routes/web.php');

        $this->assertStringNotContainsString(
            "prefix('repairs')",
            $routes,
            'The /api/repairs endpoint group was removed in Task 13 and must not return.'
        );

        $this->assertStringNotContainsString(
            'RepairController',
            $routes,
            'routes/web.php must not import or reference the deleted RepairController.'
        );

        // The retired analytics endpoints went with it. Their surviving
        // neighbours are pinned in the next test so a future cleanup cannot
        // mistake one for the other.
        foreach (['top-repaired', 'repair-report'] as $retired) {
            $this->assertStringNotContainsString(
                "'" . $retired . "'",
                $routes,
                "The /api/analytics/{$retired} route was removed in Task 13."
            );
        }
    }

    /**
     * The routes that merely LOOK adjacent to the retired ones must survive.
     * 'top-requested' vs 'top-repaired' and 'replacement-report' vs
     * 'repair-report' are each close enough to be deleted by accident.
     */
    public function test_the_neighbouring_analytics_routes_were_not_collateral_damage(): void
    {
        $routes = $this->executedSource('routes/web.php');

        foreach (['top-requested', 'dispatch-report', 'replacement-report', 'overview'] as $kept) {
            $this->assertStringContainsString(
                "'" . $kept . "'",
                $routes,
                "/api/analytics/{$kept} is not a Repair endpoint and must survive Task 13."
            );
        }
    }

    /**
     * PersonnelDirectoryService is the NEUTRAL personnel source that Preventive
     * Maintenance and Dispatch were moved onto precisely so they would stop
     * depending on the Repair module. Retiring Repair must not disturb it.
     */
    public function test_the_neutral_personnel_directory_is_untouched(): void
    {
        $this->assertFileExists(base_path('app/Services/PersonnelDirectoryService.php'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function executedSource(string $relativePath): string
    {
        $full = base_path($relativePath);
        $this->assertFileExists($full);

        return $this->stripComments((string) file_get_contents($full));
    }

    /**
     * Every PHP/JS source file under public/frontend.
     */
    private function frontendSourceFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('public/frontend'), \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if (in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        $this->assertNotEmpty($files, 'The frontend sweep found no files to check.');

        return $files;
    }

    /**
     * Strip HTML, block and whole-line comments.
     *
     * The retirement deliberately leaves comments behind that NAME the deleted
     * pages, to explain why a control is missing. A naive substring search
     * cannot tell that documentation apart from a live link, so every assertion
     * about what the app OFFERS has to look at executed source only.
     */
    private function stripComments(string $source): string
    {
        $source = preg_replace('/<!--.*?-->/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);
        // Whole-line // comments (leading whitespace only, so `https://` and
        // other mid-line occurrences are left alone).
        $source = preg_replace('#^[ \t]*//.*$#m', '', $source);

        return $source;
    }
}
