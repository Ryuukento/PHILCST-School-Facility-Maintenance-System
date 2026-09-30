<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Covers the navigation audit in SYSTEM_FLOW_REVIEW.md §8: the sidebar's
 * "Create Report" link was computed by role and then immediately hardcoded
 * to false, hiding it for every user; the "All Reports" link did not
 * highlight as active from its own detail page; and components.php carried
 * a dead, unreferenced duplicate sidebar/navbar definition pointing at a
 * nonexistent analytics.php route. These tests lock in the fixes so the
 * regressions cannot silently return.
 */
class NavigationConsistencyTest extends TestCase
{
    /**
     * TASK 100 — the Create Report ENTRY POINT moved from the sidebar into the
     * All Reports module at the Dean's request. The three tests below were
     * originally written against sidebar.php's $showCreateReport flag; they now
     * assert the identical properties of reports.php's $canCreateReport, which
     * is where the entry point lives.
     *
     * The expectation changed only because the business requirement explicitly
     * changed. Nothing is relaxed: the same allow-list, the same exclusion of
     * super_admin, and the same "never hardcoded off" regression are still
     * pinned — and they now guard the control that is actually rendered rather
     * than a nav item that no longer exists.
     *
     * These remain presentation guards. The authoritative boundaries are
     * EnsureRole:maintenance_admin,maintenance_staff on POST /api/reports and
     * create-report.php's own redirect guard, neither of which this task
     * touched.
     */
    public function test_create_report_action_is_not_hardcoded_to_false(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));
        $this->assertNotFalse($markup);

        $this->assertDoesNotMatchRegularExpression(
            '/\$canCreateReport\s*=\s*false\s*;/',
            $markup,
            'The All Reports Create Report action must not be hardcoded to false.'
        );
    }

    public function test_create_report_action_is_visible_to_every_role_that_can_submit_reports(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));
        $this->assertNotFalse($markup);

        $this->assertMatchesRegularExpression(
            '/\$canCreateReport\s*=\s*in_array\(\s*\$currentRole,\s*\[\'maintenance_admin\',\s*\'maintenance_staff\'\],\s*true\s*\)\s*;/',
            $markup,
            'The Create Report action must be shown only to report submitters: maintenance_admin (Head Maintenance) and maintenance_staff. super_admin (Administrator) reviews/assigns/monitors reports but does not submit them (BUSINESS_RULES.md §1, "Permissions (by role)" table).'
        );

        $this->assertMatchesRegularExpression(
            '/if\s*\(\$canCreateReport\).{0,1200}?id="create-report-btn"/s',
            $markup,
            'The rendered "+ Create Report" action must sit inside the $canCreateReport gate, not outside it.'
        );
    }

    public function test_create_report_action_is_hidden_from_super_admin(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));
        $this->assertNotFalse($markup);

        $this->assertDoesNotMatchRegularExpression(
            '/\$canCreateReport\s*=\s*in_array\([^;]*\'super_admin\'[^;]*\)\s*;/',
            $markup,
            'super_admin (Administrator) reviews/assigns/monitors reports but must not be able to create them; it must not appear in the $canCreateReport allow-list.'
        );
    }

    /**
     * TASK 100 — the sidebar must no longer offer Create Report as its own
     * destination. Asserted on the nav-item markers rather than on the string
     * "create-report.php", because the explanatory comment left in sidebar.php
     * deliberately names the page it no longer links to.
     */
    public function test_sidebar_has_no_standalone_create_report_navigation_item(): void
    {
        $markup = file_get_contents(base_path('public/frontend/includes/sidebar.php'));
        $this->assertNotFalse($markup);

        $this->assertStringNotContainsString('data-page="create-report"', $markup, 'The sidebar must not render a Create Report nav item.');
        $this->assertStringNotContainsString('<span class="nav-text">Create Report</span>', $markup, 'The Create Report nav label must be gone.');
        $this->assertStringNotContainsString('$showCreateReport', $markup, 'The sidebar-only Create Report gate must go with the nav item it guarded.');

        // The consolidation must not have cost any other destination.
        // TASK 12 — "Repair Requests" left this list because its user-facing
        // module was retired deliberately in that task, not because the Create
        // Report consolidation took it. The four remaining entries still guard
        // this test's real concern.
        foreach (['All Reports', 'Inventory', 'Dispatches', 'Deployment Tracking'] as $navItem) {
            $this->assertStringContainsString(
                '<span class="nav-text">' . $navItem . '</span>',
                $markup,
                "Removing Create Report must not remove the {$navItem} nav item."
            );
        }
    }

    public function test_all_reports_link_highlights_active_from_its_own_detail_page(): void
    {
        $markup = file_get_contents(base_path('public/frontend/includes/sidebar.php'));
        $this->assertNotFalse($markup);

        $navStart = strpos($markup, 'data-page="reports"');
        $this->assertNotFalse($navStart, 'All Reports nav link not found.');

        $liStart = strrpos(substr($markup, 0, $navStart), '<li');
        $anchorMarkup = substr($markup, $liStart, $navStart - $liStart);

        $this->assertStringContainsString('maintenance-report-detail.php', $anchorMarkup, 'All Reports link must stay active when viewing a report detail page.');
    }

    public function test_components_file_no_longer_defines_dead_duplicate_sidebar(): void
    {
        $markup = file_get_contents(base_path('public/frontend/components/components.php'));
        $this->assertNotFalse($markup);

        $this->assertStringNotContainsString('function renderSidebar', $markup, 'Dead duplicate renderSidebar() (pointing at a nonexistent analytics.php route) must stay removed.');
        $this->assertStringNotContainsString('function renderNavbar', $markup, 'Dead duplicate renderNavbar() must stay removed.');
    }

    public function test_dashboard_no_buildings_notice_does_not_link_to_a_nonexistent_setup_page(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/dashboard.php'));
        $this->assertNotFalse($markup);

        $this->assertStringNotContainsString('href="/School_Facility_Maintenance_System/backend/setup.html"', $markup, 'Dead link to nonexistent backend/setup.html must not be used as a navigation target.');
    }
}
