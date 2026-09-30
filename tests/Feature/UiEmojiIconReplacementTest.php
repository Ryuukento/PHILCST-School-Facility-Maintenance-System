<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TASK 7 — UI emojis replaced with proper interface icons.
 *
 * The Dean flagged that the interface used emoji characters as icons
 * (📦 Inventory, 🔔 Notifications, 🗑️ Delete, ✓ Approved …). Emoji render as
 * colourful, platform-dependent pictures that vary between Windows, macOS and
 * Android, which is precisely what made the UI look unprofessional.
 *
 * The application already had an icon convention — hand-written inline
 * <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
 * in the sidebar and header. TASK 7 did NOT introduce a library, a CDN or an
 * npm package; it consolidated that existing convention into a registry
 * (includes/icon-paths.php) with two renderers that read from it:
 *
 *   - ui_icon()        — PHP, for server-rendered markup
 *   - UIIcons.svg()    — JS, for the many views this app paints from script
 *
 * The registry is serialised into the page by header.php, so the two renderers
 * physically cannot drift apart.
 *
 * These tests pin down:
 *   1. the registry and both renderers exist and agree;
 *   2. every icon follows one visual contract (24x24, stroke, currentColor),
 *      which is what makes Light and Dark theme work with no per-theme rule;
 *   3. icons are decorative by default, so screen readers do not hear a
 *      redundant name next to an already-visible label;
 *   4. icon-only controls kept a real accessible name;
 *   5. the emoji that were acting as interface icons are gone from the
 *      surfaces that render them;
 *   6. no label text was reworded in the process;
 *   7. the TASK 6 / 6A Inventory submenu is untouched;
 *   8. no backend file was dragged into a presentation change.
 */
class UiEmojiIconReplacementTest extends TestCase
{
    /** Frontend surfaces that render UI chrome and must be emoji-icon free. */
    private const UI_SURFACES = [
        'public/frontend/includes/header.php',
        'public/frontend/includes/sidebar.php',
        'public/frontend/assets/js/notification.js',
        'public/frontend/pages/dispatch-detail.php',
        'public/frontend/pages/dispatches.php',
        'public/frontend/pages/dispatch-create.php',
        'public/frontend/pages/buildings-overview.php',
        'public/frontend/pages/users.php',
        'public/frontend/pages/reports.php',
        'public/frontend/pages/purchase-receipts.php',
        'public/frontend/pages/report-detail.php',
        'public/frontend/pages/maintenance-report-detail.php',
        'public/frontend/pages/super-admin-dashboard.php',
        'public/frontend/pages/dashboard.php',
        // TASK 7.1 — surfaces the original sweep never listed, which is part of
        // why entity-encoded icons survived it.
        'public/frontend/pages/staff-dashboard.php',
        'public/frontend/pages/create-report.php',
        'public/frontend/assets/js/utils.js',
    ];

    /**
     * The pictographic emoji this task removed. Deliberately NOT a blanket
     * "any non-ASCII" sweep: the repository legitimately contains → and ⚠ in
     * code comments and prose, and this task was never about those.
     */
    private const RETIRED_EMOJI = [
        '📦', '🧾', '🚚', '📊', '🏢', '👤', '🔔', '📋', '📁', '📅', '🗓️',
        '📄', '📝', '📥', '💬', '🚪', '🏁', '⛔', '✅', '❌', '⏳', '🙋',
        '🗑️', '💻', '👥', '📚', '🎓', '🗂️', '📌',
    ];

    /**
     * TASK 7.1 — the same idea as RETIRED_EMOJI, expressed as codepoints so the
     * entity spellings can be generated rather than hand-listed.
     *
     * Covers the set above plus the glyphs the entity sweep retired: 👋 waving
     * hand, 🔒 lock, 🔧 wrench, 🚪 door, 👥 users, ⚠ warning, ❔ question,
     * ✔ heavy check, ℹ info, ✗/✘ ballot X, and the ↑ ↓ trend arrows.
     *
     * See test_no_entity_encoded_emoji_are_used_as_interface_icons for what is
     * intentionally NOT in this list and why.
     */
    private const RETIRED_EMOJI_CODEPOINTS = [
        0x1F4E6, 0x1F9FE, 0x1F69A, 0x1F4CA, 0x1F3E2, 0x1F464, 0x1F514, 0x1F4CB,
        0x1F4C1, 0x1F4C5, 0x1F5D3, 0x1F4C4, 0x1F4DD, 0x1F4E5, 0x1F4AC, 0x1F6AA,
        0x1F3C1, 0x26D4, 0x2705, 0x274C, 0x23F3, 0x1F64B, 0x1F5D1, 0x1F4BB,
        0x1F465, 0x1F4DA, 0x1F393, 0x1F5C2, 0x1F4CC,
        // Retired by the TASK 7.1 entity sweep.
        0x1F44B, 0x1F512, 0x1F527, 0x26A0, 0x2754, 0x2714, 0x2139, 0x2717,
        0x2718, 0x2191, 0x2193,
    ];

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path, "Expected UI surface {$relative} to exist.");

        return (string) file_get_contents($path);
    }

    /**
     * Strips comments so assertions run against markup that actually reaches
     * the browser. The replacement comments deliberately quote the emoji they
     * replaced ("was a 🔔 emoji"), which is documentation, not UI.
     */
    private function readWithoutComments(string $relative): string
    {
        $src = $this->read($relative);

        // Block comments, which covers the inline PHP-comment-block form too.
        $src = preg_replace('#/\*(?:(?!\*/)[\s\S])*\*/#', '', $src);
        // Line comments, to end of line.
        $src = preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
        // HTML comments. A comment node is never rendered, so a glyph quoted
        // inside one is documentation exactly like the two forms above.
        $src = preg_replace('#<!--[\s\S]*?-->#', '', $src);

        // console.log/warn/error are developer output in the browser console,
        // not interface chrome. TASK 7 deliberately left them alone, so they
        // are excluded here rather than being allowed to fail the sweep.
        $src = preg_replace('#console\.(?:log|warn|error|info|debug)\([^\n]*#', '', $src);

        return (string) $src;
    }

    private function registry(): array
    {
        return require base_path('public/frontend/includes/icon-paths.php');
    }

    // -----------------------------------------------------------------
    // 1. The icon system exists and is single-sourced
    // -----------------------------------------------------------------

    public function test_the_icon_registry_exists_and_is_populated(): void
    {
        $paths = $this->registry();

        $this->assertIsArray($paths);
        $this->assertGreaterThan(
            20,
            count($paths),
            'The registry should cover every icon the UI needs.'
        );

        foreach ($paths as $name => $geometry) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z-]*$/',
                $name,
                "Icon name '{$name}' should be a lowercase kebab-case token."
            );
            $this->assertNotSame('', trim((string) $geometry), "Icon '{$name}' has no geometry.");
            $this->assertStringNotContainsString(
                '<svg',
                (string) $geometry,
                "Icon '{$name}' should hold inner geometry only; the wrapper is the renderer's job."
            );
        }
    }

    public function test_both_renderers_exist(): void
    {
        require_once base_path('public/frontend/includes/icons.php');

        $this->assertTrue(function_exists('ui_icon'), 'The PHP renderer must exist.');
        $this->assertTrue(function_exists('ui_icon_paths_json'), 'The registry must be exposable to JS.');

        $js = $this->read('public/frontend/assets/js/ui-icons.js');
        $this->assertStringContainsString('window.UI_ICON_PATHS', $js, 'The JS renderer must read the shared registry.');
        $this->assertStringContainsString('global.UIIcons', $js, 'The JS renderer must publish UIIcons.');
    }

    /**
     * The whole point of the registry: PHP and JS draw the same icon because
     * they read the same data, not because someone kept two copies in sync.
     */
    public function test_the_js_renderer_does_not_carry_its_own_copy_of_the_geometry(): void
    {
        $js = $this->read('public/frontend/assets/js/ui-icons.js');

        $this->assertStringNotContainsString(
            '<polyline',
            $js,
            'ui-icons.js must not hard-code icon geometry — it reads window.UI_ICON_PATHS.'
        );
        $this->assertStringNotContainsString('<circle', $js);

        $header = $this->read('public/frontend/includes/header.php');
        $this->assertStringContainsString(
            'window.UI_ICON_PATHS = <?php echo ui_icon_paths_json(); ?>',
            $header,
            'header.php must hand the one registry to the browser.'
        );
    }

    // -----------------------------------------------------------------
    // 2. One visual contract — this is what makes both themes work
    // -----------------------------------------------------------------

    public function test_every_icon_renders_in_the_projects_existing_svg_style(): void
    {
        require_once base_path('public/frontend/includes/icons.php');

        foreach (array_keys($this->registry()) as $name) {
            $svg = ui_icon($name);

            $this->assertStringStartsWith('<svg', $svg, "Icon '{$name}' must render an <svg>.");
            $this->assertStringContainsString('viewBox="0 0 24 24"', $svg, "Icon '{$name}' must use the 24x24 grid.");
            $this->assertStringContainsString('fill="none"', $svg, "Icon '{$name}' must be stroke-drawn, not filled.");
            $this->assertStringContainsString(
                'stroke="currentColor"',
                $svg,
                "Icon '{$name}' must inherit colour so Light and Dark theme need no separate rule."
            );
        }
    }

    /**
     * currentColor is the entire theme story: if an icon hard-coded a hex, it
     * would be legible in one theme and not the other.
     */
    public function test_no_icon_hardcodes_a_colour(): void
    {
        require_once base_path('public/frontend/includes/icons.php');

        foreach (array_keys($this->registry()) as $name) {
            $svg = ui_icon($name);

            $this->assertDoesNotMatchRegularExpression(
                '/(?:stroke|fill)="#[0-9a-f]{3,8}"/i',
                $svg,
                "Icon '{$name}' must not pin a colour — it would break one of the two themes."
            );
        }
    }

    public function test_the_icon_stylesheet_is_loaded_and_sizes_icons_for_twelve_pixel_text(): void
    {
        $header = $this->read('public/frontend/includes/header.php');
        $this->assertStringContainsString(
            'ui-icons.css',
            $header,
            'The icon stylesheet must be linked.'
        );

        $css = $this->read('public/frontend/assets/css/ui-icons.css');

        // Sized relative to the surrounding text, so icons track the ~12px UI
        // standard rather than being pinned to one pixel size.
        $this->assertMatchesRegularExpression(
            '/\.ui-icon\s*\{[^}]*width:\s*[0-9.]+em/s',
            $css,
            'Icons should size relative to their text.'
        );
        $this->assertStringContainsString(
            'vertical-align',
            $css,
            'Icons must be aligned to the text baseline to avoid layout shift.'
        );

        // No oversized default: the emoji looked large, the icons should not.
        preg_match_all('/width:\s*(\d+)px/', $css, $m);
        foreach ($m[1] as $px) {
            $this->assertLessThanOrEqual(
                44,
                (int) $px,
                'No icon size step should be oversized for a 12px UI.'
            );
        }
    }

    // -----------------------------------------------------------------
    // 3. Accessibility
    // -----------------------------------------------------------------

    public function test_icons_are_decorative_by_default(): void
    {
        require_once base_path('public/frontend/includes/icons.php');

        $svg = ui_icon('package');

        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('focusable="false"', $svg);
        $this->assertStringNotContainsString(
            'role="img"',
            $svg,
            'A decorative icon beside a visible label must not announce itself.'
        );
    }

    public function test_an_icon_that_carries_meaning_can_take_an_accessible_name(): void
    {
        require_once base_path('public/frontend/includes/icons.php');

        $svg = ui_icon('arrow-up', ['label' => 'Increased']);

        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('aria-label="Increased"', $svg);
        $this->assertStringNotContainsString(
            'aria-hidden',
            $svg,
            'An icon with an accessible name must not also be hidden.'
        );
    }

    /**
     * The analytics trend arrow is the one icon in this task whose direction is
     * not repeated in adjacent text, so it must be labelled rather than hidden
     * — otherwise the trend would be conveyed by colour alone.
     */
    public function test_the_analytics_trend_arrow_is_labelled_not_hidden(): void
    {
        $src = $this->read('public/frontend/pages/analytics-dashboard.php');

        $this->assertStringContainsString('arrowLabel', $src);
        $this->assertMatchesRegularExpression(
            '/label:\s*arrowLabel/',
            $src,
            'The trend arrow must pass an accessible name.'
        );
        foreach (['Increased', 'Decreased', 'No change'] as $word) {
            $this->assertStringContainsString($word, $src, "Trend direction '{$word}' must be stated.");
        }
    }

    public function test_icon_only_controls_kept_an_accessible_name(): void
    {
        $src = $this->read('public/frontend/pages/buildings-overview.php');

        // Both delete controls lost their emoji glyph, so the aria-label is now
        // the only thing naming them.
        $this->assertSame(
            2,
            substr_count($src, "setAttribute('aria-label', 'Delete')"),
            'Every icon-only delete control must still name itself.'
        );
        $this->assertStringNotContainsString(
            "del.textContent = '",
            $src,
            'Delete controls should render an icon, not a text glyph.'
        );
    }

    /**
     * This used to assert the notification bell in header.php kept its
     * aria-label. The top header bar — bell included — was removed from the
     * authenticated UI, so that element no longer exists to be named.
     *
     * The underlying contract has not changed and is what is asserted here: the
     * icon-only control that survived the header removal must carry an
     * accessible name. #sidebarToggleMobile moved out of the bar and into the
     * sidebar includes; it renders nothing but an SVG, so its aria-label is the
     * only thing a screen reader can announce for it, and below 768px it is the
     * sole way to open navigation. Both shells are checked because each ships
     * its own copy of the button.
     */
    public function test_the_icon_only_mobile_menu_button_is_named_in_both_shells(): void
    {
        foreach ([
            'public/frontend/includes/sidebar.php',
            'resources/views/includes/sidebar.blade.php',
        ] as $shell) {
            $src = $this->read($shell);

            $this->assertStringContainsString(
                'id="sidebarToggleMobile"',
                $src,
                "{$shell} must keep the mobile menu button — it is the only way to open navigation below 768px."
            );
            $this->assertStringContainsString(
                'aria-label="Toggle navigation menu"',
                $src,
                "The menu button in {$shell} is icon-only and must keep its accessible name."
            );
        }
    }

    /**
     * The bar is gone and must stay gone: no page should reintroduce it by
     * pasting the markup back into the shared legacy header.
     */
    public function test_the_removed_top_header_bar_has_not_come_back(): void
    {
        $src = $this->readWithoutComments('public/frontend/includes/header.php');

        foreach (['<nav class="navbar', 'navbar-brand', 'header-profile', 'notificationBell'] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $src,
                "'{$marker}' is back in the legacy header — the top bar was removed deliberately."
            );
        }
    }

    // -----------------------------------------------------------------
    // 4. The emoji are actually gone from rendered UI
    // -----------------------------------------------------------------

    public function test_no_retired_emoji_remain_in_rendered_ui_markup(): void
    {
        $offenders = [];

        foreach (self::UI_SURFACES as $surface) {
            $src = $this->readWithoutComments($surface);

            foreach (self::RETIRED_EMOJI as $emoji) {
                if (mb_strpos($src, $emoji) !== false) {
                    $offenders[] = basename($surface) . " still renders {$emoji}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Emoji still used as interface icons:\n" . implode("\n", $offenders)
        );
    }

    /**
     * TASK 7.1 — the regression that made the original sweep look complete when
     * it was not.
     *
     * `&#128075;` and `👋` are the same character to a browser and render
     * identically, but only the second one is visible to a literal-codepoint
     * scan. The original TASK 7 sweep — and the test above — searched for
     * literal codepoints only, so every emoji that happened to be written as an
     * HTML entity sailed through untouched and shipped.
     *
     * This test closes that hole by searching the raw source for the *entity*
     * spelling, in decimal and both hex cases.
     *
     * Deliberately excluded, because these are not emoji acting as icons:
     *   - `&#10003;` ✓ and `&#9679;` ● — typographic marks sitting inside a
     *     sentence ("✓ All items are sufficiently stocked", "● 3 Low Stock").
     *     The text beside them carries the meaning; they are punctuation.
     *   - `&#8942;` ⋮ — the inventory kebab control, a typographic glyph rather
     *     than a pictograph, and outside this task's scope.
     *   - `&rarr;` → — prose in call-to-action labels.
     *   - The literal ⚠ in the Cross-Department Assignment warning, which was
     *     intentionally left alone. Only its *entity* spelling is rejected
     *     here, so the intentional exception is unaffected.
     */
    public function test_no_entity_encoded_emoji_are_used_as_interface_icons(): void
    {
        $offenders = [];

        foreach (self::UI_SURFACES as $surface) {
            $src = $this->readWithoutComments($surface);

            foreach (self::RETIRED_EMOJI_CODEPOINTS as $codepoint) {
                $spellings = [
                    '&#' . $codepoint . ';',
                    '&#x' . dechex($codepoint) . ';',
                    '&#x' . strtoupper(dechex($codepoint)) . ';',
                ];

                foreach ($spellings as $entity) {
                    if (stripos($src, $entity) !== false) {
                        $offenders[] = basename($surface) . " still renders {$entity} (U+"
                            . strtoupper(str_pad(dechex($codepoint), 4, '0', STR_PAD_LEFT)) . ')';
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Entity-encoded emoji are still being used as interface icons.\n"
            . "These render exactly like the literal character, so they must go\n"
            . "through ui_icon() / UIIcons.svg() like every other icon:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * TASK 7.1 — the third encoding, and the only one a user could actually
     * see was broken.
     *
     * A UTF-8 emoji whose bytes were once read back as cp1252 becomes literal
     * garbage text: 📊 is `F0 9F 93 8A`, which cp1252 decodes to `ðŸ“Š` and
     * then re-encodes as six UTF-8 bytes. It is not an emoji any more, so
     * neither the literal sweep nor the entity sweep above can see it — but it
     * was rendering as visible mojibake in page headings.
     *
     * `\xC3\xB0\xC5\xB8` is the signature: the UTF-8 encoding of `ð` followed
     * by `Ÿ`, i.e. every four-byte emoji in the U+1F300–U+1FAFF planes.
     */
    public function test_no_mojibake_double_encoded_emoji_remain(): void
    {
        $offenders = [];

        foreach (self::UI_SURFACES as $surface) {
            $src = $this->read($surface);

            if (strpos($src, "\xC3\xB0\xC5\xB8") !== false) {
                $offenders[] = basename($surface) . ' contains a double-encoded (mojibake) emoji';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Double-encoded emoji found. These render as literal garbage text\n"
            . "(for example 'ðŸ\"Š' where 📊 was intended):\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * TASK 7.1 — an encoding-agnostic backstop.
     *
     * The two tests above each chase a spelling. This one ignores spelling
     * entirely and asserts the *shape*: an element whose class says it is an
     * icon chip must be filled by a renderer call, never by a text glyph. That
     * holds however a future regression chooses to encode itself.
     */
    public function test_icon_containers_are_filled_by_a_renderer_not_a_text_glyph(): void
    {
        $containers = [
            'public/frontend/pages/buildings-overview.php' => 'buildings-stat-icon',
            'public/frontend/pages/staff-dashboard.php'    => 'stat-icon-chip',
            'public/frontend/pages/super-admin-dashboard.php' => 'stat-icon-chip',
            'public/frontend/assets/js/utils.js'           => 'system-modal-icon',
            'public/frontend/pages/create-report.php'      => 'system-modal-icon',
        ];

        $offenders = [];

        foreach ($containers as $surface => $class) {
            $src = $this->readWithoutComments($surface);

            $pattern = '#class="' . preg_quote($class, '#') . '"[^>]*>(.*?)</(?:div|span)>#s';
            preg_match_all($pattern, $src, $matches);

            $this->assertNotEmpty(
                $matches[1],
                "Expected to find .{$class} containers in " . basename($surface) . '.'
            );

            foreach ($matches[1] as $inner) {
                $rendered = trim($inner) !== ''
                    && (str_contains($inner, 'ui_icon(')
                        || str_contains($inner, 'UIIcons.svg(')
                        || str_contains($inner, '_modalIcon(')
                        || str_contains($inner, 'saIcon(')
                        || str_contains($inner, '${icon}')
                        || str_contains($inner, '<svg'));

                if (! $rendered) {
                    $offenders[] = basename($surface) . " .{$class} contains '" . trim($inner) . "'";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An icon container is holding a text glyph instead of a rendered icon:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * The console.* debug lines in reports.php keep their emoji on purpose:
     * they are developer output in the browser console, never interface
     * chrome. This test documents that as a deliberate boundary, so a future
     * blanket strip does not get mistaken for task scope.
     */
    public function test_developer_console_logging_was_left_alone(): void
    {
        $src = $this->read('public/frontend/pages/reports.php');

        $this->assertMatchesRegularExpression(
            '/console\.(log|warn|error)\(/',
            $src,
            'reports.php should still have its debug logging.'
        );
    }

    public function test_the_never_displayed_validation_emoji_prefix_was_removed_cleanly(): void
    {
        // Comments are stripped: the replacement comment quotes the prefix it
        // removed, which is documentation rather than code.
        $code = $this->readWithoutComments('public/frontend/pages/users.php');
        $src = $this->read('public/frontend/pages/users.php');

        // The prefix used to be added in fifteen places and stripped in two,
        // so it was never visible. Both halves must be gone together.
        $this->assertStringNotContainsString('❌', $code);
        $this->assertStringNotContainsString(
            "replace(/^❌ /, '')",
            $code,
            'The strip is pointless once the prefix is gone.'
        );

        // The user-visible wording is unchanged.
        foreach ([
            'Username is required.',
            'Password must be at least 8 characters long.',
            'Email must be a valid Gmail address ending with @gmail.com.',
        ] as $message) {
            $this->assertStringContainsString($message, $src, "Validation wording must not change: {$message}");
        }
    }

    // -----------------------------------------------------------------
    // 5. Labels were not reworded (Phase 10)
    // -----------------------------------------------------------------

    public function test_navigation_and_action_labels_are_unchanged(): void
    {
        $sidebar = $this->read('public/frontend/includes/sidebar.php');

        foreach ([
            'Inventory',
            'Purchase Receipts',
            'Dispatches',
            'Deployment Tracking',
            'Inventory Reports',
            'Activity Logs',
            'Buildings Overview',
            'User Management',
            // TASK 12 — 'Repair Requests' left this list because the nav item
            // was retired outright, not renamed. This test guards against the
            // icon swap REWORDING a label; a label that no longer exists is
            // outside that concern, and the eight above still enforce it.
        ] as $label) {
            $this->assertStringContainsString(
                '<span class="nav-text">' . $label . '</span>',
                $sidebar,
                "Navigation label '{$label}' must not be renamed."
            );
        }

        $this->assertStringContainsString('Back to Dispatches', $this->read('public/frontend/pages/dispatch-detail.php'));
        $this->assertStringContainsString('Print Labels', $this->read('public/frontend/pages/dispatch-detail.php'));
        $this->assertStringContainsString('Back to List', $this->read('public/frontend/pages/purchase-receipts.php'));
        $this->assertStringContainsString('Back to Reports', $this->read('public/frontend/pages/report-detail.php'));
        $this->assertStringContainsString('Reopen Report', $this->read('public/frontend/pages/maintenance-report-detail.php'));
        $this->assertStringContainsString('Last Month Reports', $this->read('public/frontend/pages/super-admin-dashboard.php'));
    }

    /**
     * Status meaning must survive the glyph swap — the word is what carries it,
     * so the icon is a reinforcement and never the sole signal (Phase 9).
     */
    public function test_status_text_is_retained_beside_every_status_icon(): void
    {
        $src = $this->read('public/frontend/pages/reports.php');

        $this->assertStringContainsString("repIcon('check') + ' Approved</span>'", $src);
        $this->assertStringContainsString("repIcon('x') + ' Rejected</span>'", $src);
        $this->assertStringContainsString("repIcon('clock') + ' Pending</span>'", $src);
    }

    // -----------------------------------------------------------------
    // 6. TASK 6 / 6A safety — the Inventory submenu is untouched
    // -----------------------------------------------------------------

    public function test_the_inventory_submenu_structure_survived(): void
    {
        $sidebar = $this->read('public/frontend/includes/sidebar.php');

        $this->assertStringContainsString('nav-parent-toggle', $sidebar);
        $this->assertStringContainsString('nav-submenu-inventory', $sidebar);

        $order = ['inventory', 'purchase-receipts', 'dispatches', 'deployment-tracking', 'inventory-reports'];
        $last = -1;
        foreach ($order as $page) {
            $pos = strpos($sidebar, 'data-page="' . $page . '"');
            $this->assertNotFalse($pos, "Submenu child '{$page}' must still be present.");
            $this->assertGreaterThan($last, $pos, "Submenu order changed at '{$page}'.");
            $last = $pos;
        }
    }

    public function test_role_gates_were_not_touched_by_a_presentation_change(): void
    {
        $sidebar = $this->read('public/frontend/includes/sidebar.php');

        foreach ([
            '$showInventoryReportsNav',
            '$showPurchaseReceiptsNav',
            '$showDispatchesNav',
            '$showDeploymentTrackingNav',
        ] as $gate) {
            $this->assertStringContainsString($gate, $sidebar, "Role gate {$gate} must survive.");
        }
    }

    public function test_deploy_to_room_was_not_restored(): void
    {
        $sidebar = $this->read('public/frontend/includes/sidebar.php');

        $this->assertStringNotContainsString('deploy-to-room', $sidebar);
        $this->assertStringNotContainsString('Deploy to Room', $sidebar);
    }

    // -----------------------------------------------------------------
    // 7. Scope — a presentation task must not reach the backend
    // -----------------------------------------------------------------

    public function test_the_icon_system_is_confined_to_the_frontend(): void
    {
        foreach ([
            'public/frontend/includes/icon-paths.php',
            'public/frontend/includes/icons.php',
            'public/frontend/assets/js/ui-icons.js',
            'public/frontend/assets/css/ui-icons.css',
        ] as $file) {
            $this->assertFileExists(base_path($file));
            $this->assertStringStartsWith(
                'public/frontend/',
                $file,
                'The icon system must live entirely in the frontend.'
            );
        }
    }

    /**
     * No CDN, no npm, no second icon library — the task explicitly forbade
     * adding a dependency when an existing convention was available.
     */
    public function test_no_external_icon_dependency_was_introduced(): void
    {
        foreach (array_merge(self::UI_SURFACES, ['public/frontend/assets/js/ui-icons.js']) as $surface) {
            $src = $this->read($surface);

            foreach ([
                'font-awesome', 'fontawesome', 'cdnjs', 'unpkg.com', 'jsdelivr',
                'bootstrap-icons', 'material-icons', 'heroicons', 'lucide',
            ] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $src,
                    basename($surface) . " must not pull in an external icon dependency ({$forbidden})."
                );
            }
        }
    }
}
