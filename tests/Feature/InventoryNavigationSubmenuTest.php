<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK 6 / 6A — Inventory navigation submenu consolidation.
 * DUPLICATE-INVENTORY FIX — the structure below supersedes the original.
 *
 * The Dean asked that Inventory Reports, Purchase Receipts and Deployment
 * Tracking stop appearing as separate top-level sidebar items and sit inside
 * Inventory instead. The section originally repeated its own landing page as
 * the submenu's first child, so the word "Inventory" appeared twice. That
 * duplicate has now been removed and the parent row itself is the destination:
 *
 *     [ Inventory ] [v]          <- <a> navigates | <button> expands
 *         ├── Purchase Receipts
 *         ├── Dispatches
 *         ├── Deployment Tracking
 *         └── Inventory Reports
 *
 * ---------------------------------------------------------------------------
 * DELIBERATE REVERSAL — read before "restoring" anything here.
 *
 * This file previously carried test_inventory_parent_cannot_navigate_on_click,
 * which asserted the parent row was a <button> with NO href, on the reasoning
 * that a row which both navigates and expands would fire the wrong intent. That
 * reasoning was sound ONLY while the landing page was reachable as a child. With
 * the duplicate child removed the premise is gone: if the parent could not
 * navigate, the main Inventory page would have no sidebar entry at all.
 *
 * The requirement is now explicitly the opposite — the label navigates, the
 * caret expands, and the two must not trigger each other. The assertions below
 * encode that. This is a REQUIREMENT CHANGE, not a test bent to fit the code.
 * ---------------------------------------------------------------------------
 *
 * This is a NAVIGATION change only. The destinations remain separate modules
 * with their own pages, routes, controllers and APIs; nothing was merged,
 * renamed or deleted.
 *
 * These tests pin down:
 *   1. the parent row is split into a navigating link and a separate expander;
 *   2. all four children live inside the submenu, in the requested order, and
 *      the landing page is NOT among them;
 *   3. the standalone top-level entries are gone — asserted on rendered
 *      markup, since the explanatory comments left behind deliberately name
 *      the entries they replaced;
 *   4. every route and page behind them still exists, unchanged;
 *   5. role-based visibility is carried over verbatim — in particular that
 *      Deployment Tracking is still hidden from maintenance_staff;
 *   6. the submenu is theme-variable driven, so Light and Dark both work;
 *   7. unrelated sidebar sections were not disturbed;
 *   8. TASK 36's Deploy-to-Room retirement was not undone.
 *
 * Verified by source assertion because the sidebar is a PHP include rendered
 * into every page and no browser automation is available in this environment
 * (see the task report).
 */
class InventoryNavigationSubmenuTest extends TestCase
{
    private const SIDEBAR = 'public/frontend/includes/sidebar.php';

    /**
     * The final child order, data-page => visible label.
     *
     * 'inventory' is deliberately ABSENT: the section's landing page is the
     * parent row's own destination now, not a child. Re-adding it here would
     * reintroduce the duplicate this task removed.
     *
     * Inventory Reports is last; Deployment Tracking precedes it; Dispatches
     * sits between Purchase Receipts and Deployment Tracking.
     */
    private const SUBMENU_ORDER = [
        'purchase-receipts' => 'Purchase Receipts',
        'dispatches' => 'Dispatches',
        'deployment-tracking' => 'Deployment Tracking',
        'inventory-reports' => 'Inventory Reports',
    ];

    /** The pages the relocated Dispatches entry highlights on. */
    private const DISPATCH_PAGES = ['dispatches.php', 'dispatch-detail.php', 'dispatch-create.php'];

    private function sidebar(): string
    {
        $path = base_path(self::SIDEBAR);
        $this->assertFileExists($path, 'sidebar.php is missing.');

        $markup = file_get_contents($path);
        $this->assertNotFalse($markup, 'sidebar.php could not be read.');

        return $markup;
    }

    /**
     * The sidebar with PHP comment blocks stripped, so assertions about what is
     * RENDERED are not satisfied (or defeated) by the documentation left in the
     * file explaining what moved.
     */
    private function renderedSidebar(): string
    {
        // `(?:(?!\*/).)*` instead of `.*?` so the match can never backtrack
        // across a comment terminator and swallow the real markup in between.
        return (string) preg_replace(
            '#<\?php\s*/\*(?:(?!\*/).)*\*/\s*\?>#s',
            '',
            $this->sidebar()
        );
    }

    /**
     * The Inventory parent's expander element, from its opening tag through
     * `</button>`.
     *
     * Located by `<button` rather than by "the previous `<`", because the tag's
     * own attributes contain inline `<?php` echoes.
     */
    private function parentToggleElement(): string
    {
        $markup = $this->renderedSidebar();

        $anchor = strpos($markup, 'data-nav-parent="inventory"');
        $this->assertNotFalse($anchor, 'The Inventory expander was not found.');

        $start = strrpos(substr($markup, 0, $anchor), '<button');
        $this->assertNotFalse($start, 'The Inventory expander is not a <button> element.');

        $end = strpos($markup, '</button>', $start);
        $this->assertNotFalse($end, 'The Inventory expander is not closed.');

        return substr($markup, $start, ($end + strlen('</button>')) - $start);
    }

    /**
     * The Inventory parent's NAVIGATING half — the <a> that carries
     * data-page="inventory" — from its opening tag through `</a>`.
     *
     * Located by `<a ` rather than by "the previous `<`", for the same reason
     * as the expander: the tag's attributes contain inline `<?php` echoes.
     */
    private function parentLinkElement(): string
    {
        $markup = $this->renderedSidebar();

        $anchor = strpos($markup, 'data-page="inventory"');
        $this->assertNotFalse($anchor, 'The Inventory parent link was not found.');

        $start = strrpos(substr($markup, 0, $anchor), '<a ');
        $this->assertNotFalse($start, 'The Inventory parent link is not an <a> element.');

        $end = strpos($markup, '</a>', $start);
        $this->assertNotFalse($end, 'The Inventory parent link is not closed.');

        return substr($markup, $start, ($end + strlen('</a>')) - $start);
    }

    /**
     * The markup between `<ul class="nav-submenu"` and its closing `</ul>`.
     */
    private function submenuBlock(): string
    {
        $markup = $this->renderedSidebar();

        $start = strpos($markup, '<ul class="nav-submenu"');
        $this->assertNotFalse($start, 'The Inventory submenu list was not found.');

        $end = strpos($markup, '</ul>', $start);
        $this->assertNotFalse($end, 'The Inventory submenu list is not closed.');

        return substr($markup, $start, $end - $start);
    }

    // -----------------------------------------------------------------
    // 1. The parent
    // -----------------------------------------------------------------

    public function test_inventory_parent_exists_and_is_an_expander(): void
    {
        $markup = $this->renderedSidebar();

        $this->assertStringContainsString(
            'data-nav-parent="inventory"',
            $markup,
            'The Inventory parent must exist as an expandable nav item.'
        );
        $this->assertStringContainsString(
            'class="nav-item nav-parent',
            $markup,
            'The Inventory item must be marked as a submenu parent.'
        );
        $this->assertStringContainsString(
            'aria-controls="nav-submenu-inventory"',
            $markup,
            'The expander must point at the submenu it controls.'
        );
        $this->assertStringContainsString(
            'class="nav-caret"',
            $markup,
            'The parent needs a chevron indicating expanded/collapsed state.'
        );
    }

    /**
     * The row is TWO controls, not one. This is the structural guarantee that
     * makes "clicking the label navigates, clicking the arrow expands"
     * possible at all — a single element cannot do both without guessing.
     */
    public function test_the_parent_row_splits_navigation_from_expansion(): void
    {
        $markup = $this->renderedSidebar();

        $this->assertStringContainsString(
            'class="nav-parent-row"',
            $markup,
            'The label and the expander must share one row wrapper so the rail\'s '
            . 'rhythm is unchanged.'
        );

        $rowStart = strpos($markup, 'class="nav-parent-row"');
        $submenuStart = strpos($markup, '<ul class="nav-submenu"');
        $this->assertNotFalse($rowStart);
        $this->assertNotFalse($submenuStart);

        $row = substr($markup, $rowStart, $submenuStart - $rowStart);

        $this->assertSame(
            1,
            substr_count($row, '<a '),
            'The parent row must hold exactly one navigating link.'
        );
        $this->assertSame(
            1,
            substr_count($row, '<button'),
            'The parent row must hold exactly one expander.'
        );
    }

    /**
     * Behaviour 1 — "Clicking the Inventory label/icon navigates to the main
     * Inventory page." The label half is a real <a> pointing at the EXISTING
     * inventory destination; no new route was invented for it.
     */
    public function test_the_inventory_label_navigates_to_the_existing_inventory_page(): void
    {
        $element = $this->parentLinkElement();

        $this->assertStringStartsWith(
            '<a ',
            $element,
            'The Inventory label must be an anchor so it navigates on click.'
        );
        $this->assertStringContainsString(
            "public_url('/frontend/pages/' . \$inventoryLink)",
            $element,
            'The label must reuse the pre-existing $inventoryLink destination — '
            . 'this task must not introduce a new route.'
        );
        $this->assertStringContainsString(
            '<span class="nav-text">Inventory</span>',
            $element,
            'The label must still read "Inventory".'
        );
        $this->assertStringContainsString(
            'class="nav-icon"',
            $element,
            'The icon belongs to the navigating half, so clicking it navigates too.'
        );

        // .nav-link is what gives it the sidebar's hover/focus/.active treatment
        // and what sidebar.js's navLinks collection picks up — no new styling
        // and no new JS.
        $this->assertStringContainsString('nav-link', $element);

        $this->assertStringNotContainsString(
            'nav-parent-toggle',
            $element,
            'The label must not also be the expander, or clicking it would toggle '
            . 'the submenu instead of navigating.'
        );
    }

    /**
     * Behaviour 2 — "Clicking the dropdown arrow expands/collapses the
     * submenu." The expander is a separate, non-navigating <button>.
     */
    public function test_the_caret_expands_without_navigating(): void
    {
        $element = $this->parentToggleElement();

        $this->assertStringStartsWith(
            '<button',
            $element,
            'The expander must be a button so it cannot navigate.'
        );
        $this->assertStringNotContainsString(
            'href',
            $element,
            'The expander must not carry an href.'
        );
        $this->assertStringContainsString(
            'type="button"',
            $element,
            'An in-page control must not default to submit.'
        );
        $this->assertStringContainsString(
            'aria-expanded',
            $element,
            'The expanded/collapsed state must be exposed to assistive tech.'
        );
        $this->assertStringContainsString(
            'aria-label=',
            $element,
            'An icon-only button needs an accessible name.'
        );
        $this->assertStringContainsString(
            'class="nav-caret"',
            $element,
            'The arrow itself must live inside the expander, so the click target '
            . 'and the affordance are the same element.'
        );

        // It deliberately does NOT carry .nav-link: addActiveHighlight() strips
        // .active from every .nav-link and re-applies it to exactly one, so a
        // .nav-link expander would be a rival for the label's own highlight.
        $this->assertStringNotContainsString(
            'nav-link',
            $element,
            'The expander must not join the .nav-link collection sidebar.js '
            . 'rewrites .active across.'
        );
    }

    // -----------------------------------------------------------------
    // 2. The four children
    // -----------------------------------------------------------------

    public function test_all_four_modules_live_inside_the_inventory_submenu(): void
    {
        $submenu = $this->submenuBlock();

        foreach (self::SUBMENU_ORDER as $page => $label) {
            $this->assertStringContainsString(
                'data-page="' . $page . '"',
                $submenu,
                "The {$label} destination must be a child of Inventory."
            );
            $this->assertStringContainsString(
                '<span class="nav-text">' . $label . '</span>',
                $submenu,
                "The {$label} submenu item must keep its visible label."
            );
        }
    }

    /**
     * The order is part of the requirement, not incidental: Purchase Receipts,
     * Dispatches, Deployment Tracking, Inventory Reports.
     */
    public function test_submenu_items_appear_in_the_requested_order(): void
    {
        $submenu = $this->submenuBlock();

        preg_match_all('/data-page="([a-z-]+)"/', $submenu, $matches);

        $this->assertSame(
            array_keys(self::SUBMENU_ORDER),
            $matches[1],
            'The Inventory submenu must list exactly these four destinations, in this order.'
        );
    }

    /**
     * THE FIX ITSELF. The section's landing page must not be repeated inside
     * its own submenu — that duplicate is what this task removed.
     */
    public function test_the_landing_page_is_not_repeated_as_a_child(): void
    {
        $submenu = $this->submenuBlock();

        $this->assertStringNotContainsString(
            'data-page="inventory"',
            $submenu,
            'The Inventory landing page must not appear as a child of itself.'
        );
        $this->assertStringNotContainsString(
            '<span class="nav-text">Inventory</span>',
            $submenu,
            'A second "Inventory" label inside the submenu is the duplicate this '
            . 'task removed.'
        );
        $this->assertStringNotContainsString(
            "public_url('/frontend/pages/' . \$inventoryLink)",
            $submenu,
            'The landing-page link belongs on the parent row, not in the submenu.'
        );
    }

    /**
     * The user-visible symptom, asserted end to end: exactly one thing in the
     * whole sidebar says "Inventory" on its own.
     */
    public function test_exactly_one_inventory_label_renders_in_the_sidebar(): void
    {
        $this->assertSame(
            1,
            substr_count($this->renderedSidebar(), '<span class="nav-text">Inventory</span>'),
            'Only one "Inventory" label may be visible in the sidebar. '
            . '("Inventory Reports" is a different label and is counted separately.)'
        );
    }

    public function test_inventory_reports_is_the_final_submenu_item(): void
    {
        $submenu = $this->submenuBlock();

        preg_match_all('/data-page="([a-z-]+)"/', $submenu, $matches);

        $this->assertSame(
            'inventory-reports',
            end($matches[1]),
            'Inventory Reports must be the last item in the Inventory submenu.'
        );
    }

    public function test_deployment_tracking_sits_between_dispatches_and_inventory_reports(): void
    {
        $submenu = $this->submenuBlock();

        $dispatches = strpos($submenu, 'data-page="dispatches"');
        $deployment = strpos($submenu, 'data-page="deployment-tracking"');
        $reports = strpos($submenu, 'data-page="inventory-reports"');

        $this->assertNotFalse($dispatches);
        $this->assertNotFalse($deployment);
        $this->assertNotFalse($reports);

        $this->assertGreaterThan($dispatches, $deployment, 'Deployment Tracking follows Dispatches.');
        $this->assertLessThan($reports, $deployment, 'Deployment Tracking precedes Inventory Reports.');
    }

    public function test_submenu_items_keep_their_original_destination_urls(): void
    {
        $submenu = $this->submenuBlock();

        foreach ([
            "public_url('/purchase-receipts')",
            "public_url('/dispatches')",
            "public_url('/deployment-tracking')",
            "public_url('/inventory-reports')",
        ] as $href) {
            $this->assertStringContainsString(
                $href,
                $submenu,
                "Consolidating navigation must not change the {$href} destination."
            );
        }

        // Same escaping / URL-helper convention as every other nav entry.
        $this->assertStringContainsString('htmlspecialchars(public_url(', $submenu);
    }

    public function test_submenu_items_reuse_the_shared_nav_link_classes(): void
    {
        $submenu = $this->submenuBlock();

        $this->assertSame(
            4,
            substr_count($submenu, 'class="nav-link nav-sublink'),
            'Every child must reuse .nav-link so the sidebar hover/active/theme '
            . 'rules already apply to it.'
        );
        $this->assertSame(
            4,
            substr_count($submenu, 'class="nav-icon"'),
            'Each child keeps its own icon.'
        );
    }

    // -----------------------------------------------------------------
    // 3. The old top-level entries are gone
    // -----------------------------------------------------------------

    public function test_the_relocated_modules_no_longer_render_as_top_level_items(): void
    {
        $markup = $this->renderedSidebar();
        $submenu = $this->submenuBlock();

        // TASK 6A adds 'dispatches' to the list TASK 6 established.
        foreach (['purchase-receipts', 'dispatches', 'inventory-reports', 'deployment-tracking'] as $page) {
            $this->assertSame(
                1,
                substr_count($markup, 'data-page="' . $page . '"'),
                "'{$page}' must appear exactly once — inside the Inventory submenu, "
                . 'not also as a standalone top-level entry.'
            );
            $this->assertStringContainsString('data-page="' . $page . '"', $submenu);
        }

        // "Inventory" used to appear twice — parent label AND first child.
        // Now there is one of each: one label, one destination, and they are
        // the same element.
        $this->assertSame(
            1,
            substr_count($markup, 'data-page="inventory"'),
            'Inventory must have exactly one navigable destination.'
        );
    }

    // -----------------------------------------------------------------
    // 4. Nothing behind the navigation changed
    // -----------------------------------------------------------------

    public function test_every_underlying_route_remains_registered(): void
    {
        foreach ([
            'inventory.index',
            'inventory.reports',
            'purchase-receipts.page',
            'deployment.tracking',
        ] as $routeName) {
            $this->assertTrue(
                Route::has($routeName),
                "Route '{$routeName}' must remain registered — this task changes navigation only."
            );
        }
    }

    public function test_every_underlying_page_still_exists(): void
    {
        foreach ([
            'inventory.php',
            'purchase-receipts.php',
            'inventory-reports.php',
            'deployment-tracking.php',
        ] as $page) {
            $this->assertFileExists(
                base_path('public/frontend/pages/' . $page),
                "Consolidating navigation must not delete {$page}."
            );
        }
    }

    // -----------------------------------------------------------------
    // 5. Active state
    // -----------------------------------------------------------------

    public function test_each_submenu_item_highlights_on_its_own_page(): void
    {
        $submenu = $this->submenuBlock();

        foreach ([
            "\$current_page === 'purchase-receipts.php'",
            // Dispatches is the one child with a multi-page active list.
            'in_array($current_page, $dispatchesPages, true)',
            "\$current_page === 'deployment-tracking.php'",
            "\$current_page === 'inventory-reports.php'",
        ] as $condition) {
            $this->assertStringContainsString(
                $condition,
                $submenu,
                "The submenu item guarded by `{$condition}` lost its active-state rule."
            );
        }
    }

    /**
     * The landing page's own highlight moved WITH it: it used to sit on the
     * removed child, so without this the main Inventory page would be the one
     * destination in the section that never highlights.
     */
    public function test_the_parent_highlights_on_the_main_inventory_page(): void
    {
        $markup = $this->sidebar();

        $this->assertStringContainsString(
            '$inventoryIsCurrent = ($current_page === basename($inventoryLink))',
            $markup,
            'The parent must know when the main Inventory page is the current page.'
        );

        $this->assertStringContainsString(
            'nav-parent-current',
            $this->sidebar(),
            'The parent still needs its own current-section class for when a CHILD '
            . 'page is open.'
        );

        // Mutually exclusive by construction: .active when the landing page is
        // open, .nav-parent-current when a child is. Both at once would
        // double-highlight the row.
        $this->assertStringContainsString(
            "\$inventoryIsCurrent ? ' active' : (\$inventorySectionOpen ? ' nav-parent-current' : '')",
            $markup,
            'The parent must be either .active or .nav-parent-current, never both.'
        );
    }

    public function test_the_parent_opens_for_any_visible_child_page(): void
    {
        $markup = $this->sidebar();

        $this->assertStringContainsString(
            '$inventorySectionOpen = in_array($current_page, $inventorySectionPages, true)',
            $markup,
            'The parent must derive its open state from the child pages.'
        );

        foreach (['purchase-receipts.php', 'deployment-tracking.php', 'inventory-reports.php'] as $page) {
            $this->assertStringContainsString(
                "\$inventorySectionPages[] = '{$page}'",
                $markup,
                "Being on {$page} must open the Inventory section."
            );
        }

        // TASK 6A — Dispatches contributes three pages, merged in as a set.
        $this->assertStringContainsString(
            '$inventorySectionPages = array_merge($inventorySectionPages, $dispatchesPages)',
            $markup,
            'All three dispatch pages must open the Inventory section.'
        );

        $this->assertStringContainsString(
            'nav-parent-open',
            $markup,
            'The open state needs a class the CSS can key off.'
        );
    }

    /**
     * sidebar.js's addActiveHighlight() strips .active from EVERY .nav-link and
     * re-applies it to exactly one. When a CHILD page is open that one is the
     * child — so the parent must mark itself with a distinct class, or its
     * highlight would be wiped on load.
     *
     * The expander is not a .nav-link at all, so it is outside that mechanism
     * entirely and must carry neither class.
     */
    public function test_the_parent_does_not_compete_with_the_child_for_the_active_class(): void
    {
        $toggle = $this->parentToggleElement();

        $this->assertStringNotContainsString(
            'active',
            $toggle,
            'The expander must not touch the .active class that sidebar.js owns.'
        );
        $this->assertStringNotContainsString(
            'nav-parent-current',
            $toggle,
            'The current-section marker belongs on the label, which is the thing '
            . 'being highlighted.'
        );

        $this->assertStringContainsString(
            'nav-parent-current',
            $this->parentLinkElement(),
            'The label needs its own current-section class for when a child page '
            . 'is open.'
        );
    }

    // -----------------------------------------------------------------
    // 6. RBAC is carried over, not re-derived
    // -----------------------------------------------------------------

    public function test_role_gates_are_preserved_exactly(): void
    {
        $markup = $this->sidebar();

        $this->assertMatchesRegularExpression(
            "/\\\$showInventoryReportsNav\s*=\s*in_array\(\(\\\$user\['role'\] \?\? ''\), \['super_admin', 'maintenance_admin', 'maintenance_staff'\], true\)/",
            $markup,
            'Inventory Reports must keep its original three-role gate.'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$showPurchaseReceiptsNav\s*=\s*in_array\(\(\\\$user\['role'\] \?\? ''\), \['super_admin', 'maintenance_admin', 'maintenance_staff'\], true\)/",
            $markup,
            'Purchase Receipts must keep its original three-role gate.'
        );
    }

    /**
     * The one gate that is NOT the same as the others, and therefore the one a
     * consolidation is most likely to widen by accident.
     */
    public function test_deployment_tracking_remains_hidden_from_maintenance_staff(): void
    {
        $markup = $this->sidebar();

        $this->assertMatchesRegularExpression(
            "/\\\$showDeploymentTrackingNav\s*=\s*in_array\(\(\\\$user\['role'\] \?\? ''\), \['super_admin', 'maintenance_admin'\], true\)/",
            $markup,
            'Deployment Tracking must stay restricted to super_admin and maintenance_admin.'
        );

        $gatePos = strpos($markup, '$showDeploymentTrackingNav = in_array');
        $this->assertNotFalse($gatePos);

        $gateLineEnd = strpos($markup, "\n", $gatePos);
        $gateLine = substr($markup, $gatePos, $gateLineEnd - $gatePos);

        $this->assertStringNotContainsString(
            'maintenance_staff',
            $gateLine,
            'Moving Deployment Tracking into the submenu must not grant it to maintenance_staff.'
        );

        // And the child itself is actually wrapped in that gate.
        $submenu = $this->submenuBlock();
        $flagPos = strpos($submenu, '$showDeploymentTrackingNav');
        $linkPos = strpos($submenu, 'data-page="deployment-tracking"');

        $this->assertNotFalse($flagPos, 'The Deployment Tracking child must be gated.');
        $this->assertLessThan(
            $linkPos,
            $flagPos,
            'The gate must wrap the Deployment Tracking link, not follow it.'
        );
    }

    public function test_the_parent_section_keeps_the_pre_existing_inventory_gate(): void
    {
        $markup = $this->sidebar();

        $this->assertStringContainsString(
            "\$showInventoryNav = in_array((\$user['role'] ?? ''), ['super_admin', 'maintenance_admin', 'maintenance_staff'], true)",
            $markup,
            'The Inventory section gate itself must be untouched.'
        );
    }

    // -----------------------------------------------------------------
    // 7. Theme + responsive
    // -----------------------------------------------------------------

    public function test_submenu_stylesheet_is_loaded_by_the_sidebar(): void
    {
        $this->assertStringContainsString(
            'sidebar-submenu.css',
            $this->sidebar(),
            'The submenu stylesheet must be linked by the sidebar include.'
        );

        $this->assertFileExists(
            base_path('public/frontend/assets/css/sidebar-submenu.css'),
            'The submenu stylesheet is missing.'
        );
    }

    public function test_submenu_styling_is_theme_variable_driven(): void
    {
        $css = (string) file_get_contents(
            base_path('public/frontend/assets/css/sidebar-submenu.css')
        );

        foreach ([
            '--sidebar-divider',
            '--sidebar-hover',
            '--sidebar-active-text',
            '--sidebar-text-muted',
        ] as $variable) {
            $this->assertStringContainsString(
                $variable,
                $css,
                "The submenu must reuse the existing {$variable} sidebar theme variable."
            );
        }

        foreach (['light', 'dark'] as $theme) {
            $this->assertStringContainsString(
                ":root[data-theme-resolved='{$theme}']",
                $css,
                "The submenu must declare {$theme}-theme handling."
            );
        }
    }

    /**
     * Phase 6 — the collapsed icon rail must not silently drop four
     * destinations, and nothing may fly outside the sidebar.
     */
    public function test_collapsed_and_mobile_sidebars_keep_the_submenu_reachable(): void
    {
        $css = (string) file_get_contents(
            base_path('public/frontend/assets/css/sidebar-submenu.css')
        );

        $this->assertStringContainsString(
            '.sidebar.collapsed .nav-submenu',
            $css,
            'The collapsed sidebar needs explicit submenu handling.'
        );
        $this->assertStringContainsString(
            '@media (max-width: 768px)',
            $css,
            'The submenu needs mobile handling.'
        );
        $this->assertStringContainsString(
            '.sidebar.mobile-open .nav-sublink',
            $css,
            'The mobile drawer needs submenu item handling.'
        );

        $this->assertStringNotContainsString(
            'position: absolute;\n    left: 100%',
            $css,
            'The submenu must not fly out beyond the sidebar.'
        );

        // On the icon rail there is no room for a second control. The expander
        // is hidden outright rather than merely losing its caret — a 34px empty
        // box beside the icon would push the icon off-centre — and the children
        // stay rendered as icon rows, so nothing becomes unreachable.
        $this->assertStringContainsString(
            '.sidebar.collapsed .nav-parent-toggle',
            $css,
            'The collapsed rail needs explicit handling for the expander.'
        );
    }

    /**
     * The split row must not cost the rail any width. The label half absorbs
     * the row and truncates; the caret half is fixed. Without min-width:0 the
     * label refuses to shrink and the row overflows the sidebar horizontally.
     */
    public function test_the_split_parent_row_cannot_overflow_the_rail(): void
    {
        $css = (string) file_get_contents(
            base_path('public/frontend/assets/css/sidebar-submenu.css')
        );

        $this->assertStringContainsString(
            '.sidebar .nav-parent-row',
            $css,
            'The split row needs its own layout rule.'
        );
        $this->assertStringContainsString(
            '.sidebar .nav-parent-row .nav-parent-link',
            $css,
            'The label half needs an explicit flex rule.'
        );
        $this->assertStringContainsString(
            'min-width: 0;',
            $css,
            'Without min-width:0 a flex item will not shrink below its content, '
            . 'so a long label would push the caret out of the rail.'
        );
        $this->assertStringContainsString(
            'text-overflow: ellipsis;',
            $css,
            'The label must truncate rather than overflow.'
        );

        // Both halves are keyboard-reachable, so both need a visible focus ring.
        $this->assertStringContainsString('.sidebar .nav-parent-toggle:focus-visible', $css);
        $this->assertStringContainsString('.sidebar .nav-parent-link:focus-visible', $css);
    }

    public function test_submenu_text_uses_the_twelve_pixel_ui_size(): void
    {
        $css = (string) file_get_contents(
            base_path('public/frontend/assets/css/sidebar-submenu.css')
        );

        $this->assertStringContainsString(
            'font-size: 12px;',
            $css,
            'Submenu labels should follow the 12px UI text standard.'
        );

        preg_match_all('/font-size:\s*([0-9.]+)px/i', $css, $matches);
        foreach ($matches[1] as $size) {
            $this->assertGreaterThanOrEqual(
                12.0,
                (float) $size,
                "Found a {$size}px font-size in the submenu stylesheet; 12px is the floor."
            );
        }
    }

    // -----------------------------------------------------------------
    // 8. Nothing else moved
    // -----------------------------------------------------------------

    public function test_unrelated_sidebar_sections_are_untouched(): void
    {
        $markup = $this->renderedSidebar();
        $submenu = $this->submenuBlock();

        // TASK 12 — 'repairs' left this list because the Repair Requests nav
        // item was retired in that task. Its absence is not collateral damage
        // from the Inventory submenu work this test guards, which is what the
        // list exists to detect; the eight remaining pages still cover that.
        foreach ([
            'dashboard',
            'reports',
            'buildings-overview',
            'preventive-maintenance',
            'analytics',
            'users',
            'activity-logs',
            'account',
        ] as $page) {
            $this->assertStringContainsString(
                'data-page="' . $page . '"',
                $markup,
                "The unrelated '{$page}' nav item must still exist."
            );
            $this->assertStringNotContainsString(
                'data-page="' . $page . '"',
                $submenu,
                "'{$page}' is unrelated to Inventory and must not have been pulled into the submenu."
            );
        }

        // Only ONE submenu exists; this task did not turn the whole sidebar
        // into a tree.
        $this->assertSame(
            1,
            substr_count($markup, '<ul class="nav-submenu"'),
            'Exactly one submenu (Inventory) should exist.'
        );
    }

    // -----------------------------------------------------------------
    // TASK 6A — Dispatches relocated into the submenu
    // -----------------------------------------------------------------

    public function test_the_submenu_has_exactly_four_children(): void
    {
        $submenu = $this->submenuBlock();

        $this->assertSame(
            4,
            substr_count($submenu, '<li class="nav-item nav-subitem">'),
            'The Inventory submenu must contain exactly four destinations: '
            . 'Purchase Receipts, Dispatches, Deployment Tracking, Inventory Reports.'
        );
    }

    public function test_dispatches_keeps_its_original_url_icon_and_active_pages(): void
    {
        $submenu = $this->submenuBlock();
        $markup = $this->sidebar();

        $this->assertStringContainsString(
            "public_url('/dispatches')",
            $submenu,
            'Dispatches must keep its existing destination URL.'
        );

        // The original arrow icon, carried over verbatim.
        $this->assertStringContainsString('<path d="M5 12H19"></path>', $submenu);
        $this->assertStringContainsString('<path d="M12 5L19 12L12 19"></path>', $submenu);

        // The original three-page active-state list, carried over verbatim.
        $this->assertStringContainsString(
            "\$dispatchesPages = ['dispatches.php', 'dispatch-detail.php', 'dispatch-create.php']",
            $markup,
            'Dispatches must still highlight on its list, detail and create pages.'
        );
    }

    public function test_dispatches_keeps_its_original_role_gate(): void
    {
        $markup = $this->sidebar();

        $this->assertMatchesRegularExpression(
            "/\\\$showDispatchesNav\s*=\s*in_array\(\(\\\$user\['role'\] \?\? ''\), \['super_admin', 'maintenance_admin', 'maintenance_staff'\], true\)/",
            $markup,
            'Dispatches must keep its original three-role gate — moving a nav item '
            . 'must neither broaden nor narrow access.'
        );

        // And the child is actually wrapped in that gate.
        $submenu = $this->submenuBlock();
        $flagPos = strpos($submenu, '$showDispatchesNav');
        $linkPos = strpos($submenu, 'data-page="dispatches"');

        $this->assertNotFalse($flagPos, 'The Dispatches child must be gated.');
        $this->assertLessThan($linkPos, $flagPos, 'The gate must wrap the Dispatches link.');
    }

    public function test_exactly_one_dispatches_navigation_destination_exists(): void
    {
        $markup = $this->renderedSidebar();

        $this->assertSame(
            1,
            substr_count($markup, 'data-page="dispatches"'),
            'There must be exactly one Dispatches nav destination.'
        );
        $this->assertSame(
            1,
            substr_count($markup, "public_url('/dispatches')"),
            'There must be exactly one Dispatches link.'
        );
        $this->assertSame(
            1,
            substr_count($markup, '<span class="nav-text">Dispatches</span>'),
            'There must be exactly one Dispatches label.'
        );

        $this->assertStringContainsString(
            'data-page="dispatches"',
            $this->submenuBlock(),
            'The single Dispatches destination must be the one inside Inventory.'
        );
    }

    /**
     * The dispatch workflow (create → approve → release → inventory deduction →
     * deployment tracking) lives entirely behind these files and routes. Moving
     * a sidebar entry must not have disturbed any of it.
     */
    public function test_the_dispatch_backend_is_untouched_by_the_navigation_move(): void
    {
        foreach (['dispatches.index', 'dispatches.create', 'dispatches.show'] as $routeName) {
            $this->assertTrue(
                Route::has($routeName),
                "Route '{$routeName}' must remain registered."
            );
        }

        foreach (self::DISPATCH_PAGES as $page) {
            $this->assertFileExists(
                base_path('public/frontend/pages/' . $page),
                "Moving the nav entry must not delete {$page}."
            );
        }

        foreach ([
            'app/Http/Controllers/Api/DispatchController.php',
            'app/Services/DispatchService.php',
            'app/Services/DispatchAuthorizationService.php',
        ] as $backendFile) {
            $this->assertFileExists(
                base_path($backendFile),
                "{$backendFile} must still exist — this task is navigation only."
            );
        }
    }

    // -----------------------------------------------------------------
    // 9. TASK 36 safety — Deploy-to-Room stays retired
    // -----------------------------------------------------------------

    public function test_deploy_to_room_was_not_restored(): void
    {
        $markup = $this->sidebar();

        foreach ([
            'deploy-to-room',
            'deployToRoom',
            'inventory/deploy',
            'deploy.php',
        ] as $retired) {
            $this->assertStringNotContainsString(
                $retired,
                $markup,
                "TASK 36 retired '{$retired}'; this navigation change must not bring it back."
            );
        }

        // The submenu points at the surviving Deployment Tracking workflow.
        $this->assertStringContainsString(
            "public_url('/deployment-tracking')",
            $this->submenuBlock()
        );
    }
}
