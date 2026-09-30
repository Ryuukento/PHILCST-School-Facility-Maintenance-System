<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK 100 — Create Report consolidated under the All Reports module.
 *
 * The Dean asked that Create Report stop being its own sidebar destination and
 * be reached from inside All Reports instead. This is a NAVIGATION change, not
 * a reimplementation: the "+ Create Report" action in the All Reports header
 * links to the existing create-report.php workflow, which is otherwise
 * untouched.
 *
 * What these tests pin down:
 *
 *  - The entry point exists in All Reports, is a real link to the existing
 *    page, and is wrapped in the pre-existing $canCreateReport role gate.
 *  - The Create Report form was NOT duplicated into reports.php.
 *  - The page, the named route and the API endpoint all remain functional,
 *    with their authorization untouched — in particular POST /api/reports is
 *    still restricted by EnsureRole, so hiding the button is presentation
 *    only and never the security boundary.
 *  - The other, unrelated entry points (the two dashboards' quick actions)
 *    still work, which is why the route had to stay.
 *  - TASK 99's Damage Report classification surface on this same page is
 *    unaffected.
 *
 * Backend role behaviour itself (super_admin cannot create; the two submitter
 * roles can) is already owned by ReportRbacPolicyTest and is deliberately not
 * duplicated here.
 */
class CreateReportNavigationConsolidationTest extends TestCase
{
    private const CREATE_REPORT_ROLES = ['maintenance_admin', 'maintenance_staff'];

    private function reportsPage(): string
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));
        $this->assertNotFalse($markup, 'reports.php could not be read.');

        return $markup;
    }

    public function test_all_reports_header_offers_the_create_report_action(): void
    {
        $markup = $this->reportsPage();

        $this->assertStringContainsString('id="create-report-btn"', $markup);
        // Label text was later renamed app-wide from "+ Create Report" to
        // "+ Report a Problem" to match the terminology used everywhere else
        // this workflow is referenced (create-report.php's own <title>/<h2>,
        // the sidebar quick action, damage-reports.php's empty-state copy).
        // The action itself — id, target, position — is unchanged.
        $this->assertStringContainsString('+ Report a Problem', $markup, 'The action needs a clear visible label.');

        // It must sit in the existing header action group, beside Export
        // Reports, rather than somewhere new.
        $headerStart = strpos($markup, 'reports-header-actions');
        $buttonStart = strpos($markup, 'id="create-report-btn"');
        $exportStart = strpos($markup, 'id="print-report-btn"');

        $this->assertNotFalse($headerStart);
        $this->assertNotFalse($buttonStart);
        $this->assertNotFalse($exportStart);

        $this->assertGreaterThan($headerStart, $buttonStart, 'The action must live inside the All Reports header action group.');
        $this->assertLessThan($exportStart, $buttonStart, 'Create Report precedes Export Reports, per the requested layout.');
    }

    /**
     * Phase 6 — a control that navigates should be a link, so it is keyboard
     * focusable and activatable natively rather than needing extra handlers.
     * It must also reuse the page's existing button classes so the responsive
     * and dark-theme rules in reports.inline1.css already apply, with no new
     * CSS introduced.
     */
    public function test_the_create_report_action_uses_link_semantics_and_the_existing_design_system(): void
    {
        $markup = $this->reportsPage();

        $anchor = $this->createReportAnchor($markup);

        $this->assertStringStartsWith('<a ', $anchor, 'A navigating control must be an anchor, not a <button>.');
        $this->assertStringContainsString('href=', $anchor);
        $this->assertStringContainsString('btn btn-primary', $anchor, 'Reuse the existing primary button style.');
        $this->assertStringContainsString('reports-header-btn', $anchor, 'Reuse the existing header button sizing/responsive class.');

        $this->assertStringNotContainsString('onclick', $anchor, 'Navigation must not depend on an inline JS handler.');
    }

    public function test_the_create_report_action_points_at_the_existing_create_report_page(): void
    {
        $anchor = $this->createReportAnchor($this->reportsPage());

        $this->assertStringContainsString('/frontend/pages/create-report.php', $anchor);
        $this->assertStringContainsString('public_url(', $anchor, 'Use the shared URL helper the rest of the app uses.');
        $this->assertStringContainsString('htmlspecialchars(', $anchor, 'Match the escaping convention used by every other nav href.');
    }

    /**
     * "Move Create Report into All Reports" meant moving the navigation, not
     * copying the implementation. create-report.php owns the form; reports.php
     * must not have grown a second copy of it.
     */
    public function test_the_create_report_form_was_not_duplicated_into_all_reports(): void
    {
        $markup = $this->reportsPage();

        $this->assertStringNotContainsString('id="report-form"', $markup, 'create-report.php\'s form must not be copied into All Reports.');
        $this->assertStringNotContainsString('id="alert-container"', $markup, 'create-report.php\'s form scaffolding must not be copied into All Reports.');
        $this->assertStringNotContainsString('API.createReport', $markup, 'All Reports must not submit new reports itself.');
    }

    public function test_the_create_report_page_still_exists_and_keeps_its_own_role_guard(): void
    {
        $path = base_path('public/frontend/pages/create-report.php');
        $this->assertFileExists($path, 'Removing the sidebar entry must not delete the page.');

        $markup = file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/in_array\(\s*\$_crRole,\s*\[\'maintenance_admin\',\s*\'maintenance_staff\'\],\s*true\s*\)/',
            $markup,
            'The page-level role guard must remain exactly as it was.'
        );
        $this->assertStringContainsString(
            'header(\'Location: /School_Facility_Maintenance_System/frontend/pages/reports.php\')',
            $markup,
            'Unauthorized direct access must still be redirected away.'
        );
    }

    public function test_the_create_report_routes_remain_registered(): void
    {
        $this->assertTrue(Route::has('reports.create'), 'The named /reports/create route must stay registered.');

        $storeRoute = collect(Route::getRoutes())->first(
            static fn ($route): bool => $route->uri() === 'api/reports' && in_array('POST', $route->methods(), true)
        );

        $this->assertNotNull($storeRoute, 'POST /api/reports must stay registered.');
    }

    /**
     * The security boundary. Hiding the button must never be the thing that
     * stops an unauthorized role from creating a report.
     */
    public function test_report_creation_remains_restricted_by_backend_middleware(): void
    {
        $storeRoute = collect(Route::getRoutes())->first(
            static fn ($route): bool => $route->uri() === 'api/reports' && in_array('POST', $route->methods(), true)
        );

        $middleware = $storeRoute->gatherMiddleware();

        $roleMiddleware = collect($middleware)->first(
            static fn ($m): bool => is_string($m) && str_contains($m, 'EnsureRole')
        );

        $this->assertNotNull($roleMiddleware, 'POST /api/reports must still carry EnsureRole.');

        foreach (self::CREATE_REPORT_ROLES as $role) {
            $this->assertStringContainsString($role, $roleMiddleware);
        }

        $this->assertStringNotContainsString(
            'super_admin',
            $roleMiddleware,
            'Moving the button must not have widened who can create reports.'
        );

        $this->assertContains(
            \App\Http\Middleware\EnsureApiAuthenticated::class,
            $middleware,
            'Authentication must still precede the role check.'
        );
    }

    /**
     * Phase 2 — the route had to stay functional partly because these two
     * dashboards link straight to it. They are unrelated to this task and were
     * deliberately left alone; this test makes that dependency explicit so the
     * page is not deleted later on the assumption that nothing points at it.
     */
    public function test_the_dashboard_quick_actions_still_reach_create_report(): void
    {
        foreach (['maintenance-dashboard.php', 'staff-dashboard.php'] as $page) {
            $markup = file_get_contents(base_path('public/frontend/pages/' . $page));

            $this->assertStringContainsString(
                'create-report.php',
                $markup,
                "{$page}'s quick action to Create Report must keep working."
            );
        }
    }

    public function test_all_reports_keeps_its_export_and_task_99_classification_controls(): void
    {
        $markup = $this->reportsPage();

        // Export Reports.
        $this->assertStringContainsString('id="print-report-btn"', $markup);
        $this->assertStringContainsString('id="print-filter-report-type"', $markup);

        // TASK 99 — Report Type filter and classification.
        $this->assertStringContainsString('id="filter-report-type"', $markup);
        $this->assertStringContainsString('filters.report_type = reportType', $markup);
        $this->assertStringContainsString('getReportTypeBadge', $markup);

        // The later "ALL REPORTS TABLE RESPONSIVENESS" task (see the TASK
        // comment above displayReports() in reports.php) removed Type as a
        // standalone table column to fit the table on normal laptop screens.
        // report_type / getReportTypeBadge() classification itself was not
        // removed — it is asserted above, and still drives the filter and the
        // View Report detail page — only this ONE table stopped painting a
        // dedicated Type column for it.
        $this->assertStringNotContainsString("html += '<th>Type</th>';", $markup);
    }

    /**
     * Extracts the "+ Create Report" anchor element from the page markup.
     */
    private function createReportAnchor(string $markup): string
    {
        $idPos = strpos($markup, 'id="create-report-btn"');
        $this->assertNotFalse($idPos, 'The Create Report action was not found.');

        $start = strrpos(substr($markup, 0, $idPos), '<a ');
        $this->assertNotFalse($start, 'The Create Report action is not an anchor element.');

        $end = strpos($markup, '</a>', $start);
        $this->assertNotFalse($end);

        return substr($markup, $start, $end - $start);
    }
}
