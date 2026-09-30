<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * LIGHT IS THE ONLY THEME.
 *
 * WHAT THIS REPLACES
 * ------------------
 * This file supersedes DefaultLightThemeTest, which pinned the previous
 * requirement: "light is the DEFAULT, explicitly not the only mode". That test
 * asserted, among other things, that the toggle button, the dark branch of
 * every helper and the saved-preference read path all still existed. Those
 * assertions are now exactly backwards, which is why the old file was deleted
 * rather than extended — keeping both would have pinned a contradiction.
 *
 * WHAT IS PINNED NOW
 * ------------------
 * The theme is asked three separate times, in three files, because two of them
 * must run before main.js has even been downloaded:
 *
 *   1. public/frontend/includes/header.php   - inline boot script, legacy pages
 *   2. resources/views/layouts/app.blade.php - inline boot script, Laravel shell
 *   3. public/frontend/assets/js/main.js     - ThemeManager, runtime
 *
 * They cannot share code, so they can drift. When they disagree the symptom is
 * not a crash: the page paints one theme and then visibly flips to the other,
 * which reads as a flicker bug rather than as a theme bug and is easy to
 * misdiagnose. Each is pinned to the same unconditional answer.
 *
 * Two absence guarantees matter as much as the presence ones:
 *
 *   - No switching code survives anywhere. A single surviving localStorage read
 *     would re-apply a stale 'dark' preference after the boot script had already
 *     painted light, reintroducing the flip.
 *   - The font-size accessibility feature is NOT theme code and must survive.
 *     It shares the boot script and the same storage namespace, so it is the
 *     most likely thing to be deleted by accident during theme removal.
 *
 * Asserted against source rather than a rendered page because two of the three
 * files are inline <head> scripts that run before any test harness could observe
 * them, and because the Blade shell is not currently reachable by any route.
 */
class LightThemeOnlyTest extends TestCase
{
    private const HEADER = 'public/frontend/includes/header.php';
    private const FOOTER = 'public/frontend/includes/footer.php';
    private const MAIN_JS = 'public/frontend/assets/js/main.js';
    private const APP_LAYOUT = 'resources/views/layouts/app.blade.php';
    private const BLADE_HEADER = 'resources/views/includes/header.blade.php';
    private const STYLES = 'public/frontend/assets/css/styles.css';
    private const SUPER_ADMIN = 'public/frontend/pages/super-admin-dashboard.php';

    private function read(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path, $relativePath . ' is missing.');

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, $relativePath . ' could not be read.');

        return (string) $contents;
    }

    /**
     * The same source with its explanatory comments removed.
     *
     * Every one of these files now carries a comment explaining, in words, what
     * the dark theme used to do and why its remnants are deliberately ignored.
     * Absence assertions have to run against the code rather than the prose, or
     * the documentation of the removal would be mistaken for the thing it
     * documents.
     *
     * Block comments and Blade comments are stripped; `//` lines are stripped
     * only when the whole line is a comment, so a real statement with a trailing
     * comment is never silently discarded. Stripping preserves relative order,
     * so positions taken from the stripped text are still meaningful.
     */
    private function codeOnly(string $source): string
    {
        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);
        $stripped = preg_replace('/^\s*<!--.*?-->\s*$/ms', '', (string) $stripped);
        $stripped = preg_replace('#^\s*//.*$#m', '', (string) $stripped);

        return (string) $stripped;
    }

    /** The legacy-page boot script asserts light unconditionally. */
    public function test_the_legacy_boot_script_asserts_light_unconditionally(): void
    {
        $code = $this->codeOnly($this->read(self::HEADER));

        foreach (['data-theme-mode', 'data-theme-resolved', 'data-theme'] as $attribute)
        {
            $this->assertStringContainsString(
                "root.setAttribute('{$attribute}', 'light');",
                $code,
                "header.php must write {$attribute}='light' unconditionally."
            );
        }

        $this->assertStringContainsString(
            "root.style.colorScheme = 'light';",
            $code,
            'header.php must pin colorScheme so form controls and scrollbars render light too.'
        );
    }

    /**
     * The legacy boot script no longer reads, resolves or branches on a theme.
     *
     * A saved 'dark' preference may still be sitting in localStorage from before
     * this change. It must be IGNORED, not consulted — that is precisely what
     * makes light unconditional for the users who had chosen dark.
     */
    public function test_the_legacy_boot_script_has_no_theme_switching_logic(): void
    {
        $code = $this->codeOnly($this->read(self::HEADER));

        foreach (['sfmsThemeMode', 'sfms_settings_theme', 'sfms_theme_mode'] as $key)
        {
            $this->assertStringNotContainsString(
                $key,
                $code,
                "header.php still reads the '{$key}' theme preference; a stale dark value would come back."
            );
        }

        $this->assertStringNotContainsString(
            'prefers-color-scheme',
            $code,
            'header.php still consults the OS colour scheme, so an OS set to dark would still influence the page.'
        );
        $this->assertStringNotContainsString(
            "'dark'",
            $code,
            'A dark branch survives in the header boot script.'
        );
        $this->assertStringNotContainsString(
            'data-theme-toggle',
            $code,
            'The theme toggle button is back in the legacy header.'
        );
    }

    /**
     * The boot script must stay above the stylesheets.
     *
     * This is the anti-flicker guarantee. The script paints the theme attributes
     * synchronously; if it ever slides below the <link> tags, or gains defer, the
     * first frame renders unthemed and the flash comes back.
     */
    public function test_the_legacy_boot_script_runs_before_any_stylesheet(): void
    {
        $code = $this->codeOnly($this->read(self::HEADER));

        $bootAt = strpos($code, "root.setAttribute('data-theme-resolved', 'light');");
        $firstStylesheetAt = strpos($code, '<link rel="stylesheet"');

        $this->assertNotFalse($bootAt, 'No theme boot script found in header.php.');
        $this->assertNotFalse($firstStylesheetAt, 'No stylesheet link found in header.php.');
        $this->assertLessThan(
            $firstStylesheetAt,
            $bootAt,
            'The theme boot script must run before the first stylesheet or the page will flash.'
        );
    }

    /**
     * The font-size preference is a separate accessibility feature.
     *
     * It lives in the same boot script and the same storage namespace as the
     * theme code that was removed, which makes it the single most likely thing
     * to be deleted by accident. It is not theme code and must survive.
     */
    public function test_the_font_size_accessibility_feature_survived(): void
    {
        $header = $this->codeOnly($this->read(self::HEADER));
        $mainJs = $this->codeOnly($this->read(self::MAIN_JS));

        $this->assertStringContainsString(
            "localStorage.getItem('sfms_settings_font_size')",
            $header,
            'The header boot script stopped reading the saved font size.'
        );
        $this->assertStringContainsString(
            "root.setAttribute('data-font-size-mode', safeFontSizeMode);",
            $header,
            'The header boot script stopped applying the saved font size.'
        );
        $this->assertStringContainsString(
            "storageKey: 'sfms_settings_font_size',",
            $mainJs,
            'AccessibilityManager stopped reading the font-size preference key.'
        );
    }

    /** ThemeManager agrees with the boot scripts and does nothing else. */
    public function test_theme_manager_only_reasserts_light(): void
    {
        $code = $this->codeOnly($this->read(self::MAIN_JS));

        $this->assertStringContainsString(
            "mode: 'light',",
            $code,
            'ThemeManager must declare light as its one mode.'
        );
        $this->assertStringContainsString(
            "root.setAttribute('data-theme-resolved', 'light');",
            $code,
            'ThemeManager.applyMode() must assert light.'
        );

        foreach (['getSavedMode', 'resolveMode', 'toggleMode', 'updateToggleButtons', 'bindToggleButtons'] as $removed)
        {
            $this->assertStringNotContainsString(
                $removed,
                $code,
                "ThemeManager.{$removed}() is back; the theme is switchable again."
            );
        }

        $this->assertStringNotContainsString(
            'matchMedia',
            $code,
            'main.js subscribes to OS theme changes again.'
        );
        $this->assertStringNotContainsString(
            "'dark'",
            $code,
            'A dark branch survives in main.js.'
        );
    }

    /**
     * ThemeManager stays a global.
     *
     * It was reduced to a shim rather than deleted because main.js loads on every
     * legacy page and window.ThemeManager is a global that other code may still
     * call. Deleting it outright would turn a no-op into a TypeError.
     */
    public function test_theme_manager_is_still_exposed_as_a_global(): void
    {
        $code = $this->codeOnly($this->read(self::MAIN_JS));

        $this->assertStringContainsString(
            'window.ThemeManager = window.ThemeManager || ThemeManager;',
            $code,
            'window.ThemeManager was removed; any straggling caller now throws instead of no-opping.'
        );
        $this->assertStringContainsString(
            'ThemeManager.init();',
            $code,
            'ThemeManager.init() is no longer called on DOMContentLoaded.'
        );
    }

    /** The Laravel shell asks the same question and gets the same answer. */
    public function test_the_blade_shell_asserts_light_unconditionally(): void
    {
        $code = $this->codeOnly($this->read(self::APP_LAYOUT));

        foreach (['data-theme-mode', 'data-theme-resolved', 'data-theme'] as $attribute)
        {
            $this->assertStringContainsString(
                "document.documentElement.setAttribute('{$attribute}', 'light');",
                $code,
                "The Blade boot script must write {$attribute}='light' unconditionally."
            );
        }

        foreach (['normalizeMode', 'getStoredMode', 'setTheme', 'data-theme-toggle'] as $removed)
        {
            $this->assertStringNotContainsString(
                $removed,
                $code,
                "'{$removed}' survives in the Blade shell; it will disagree with the other two initializers."
            );
        }

        $this->assertStringNotContainsString(
            "'dark'",
            $code,
            'A dark branch survives in the Blade shell.'
        );
    }

    /** The Blade boot script has the same anti-flicker ordering guarantee. */
    public function test_the_blade_boot_script_runs_before_any_stylesheet(): void
    {
        $code = $this->codeOnly($this->read(self::APP_LAYOUT));

        $bootAt = strpos($code, "document.documentElement.setAttribute('data-theme-resolved', 'light');");
        $firstStylesheetAt = strpos($code, '<link rel="stylesheet"');

        $this->assertNotFalse($bootAt, 'No theme boot script found in the Blade layout.');
        $this->assertNotFalse($firstStylesheetAt, 'No stylesheet link found in the Blade layout.');
        $this->assertLessThan(
            $firstStylesheetAt,
            $bootAt,
            'The Blade theme boot script must run before the first stylesheet or the page will flash.'
        );
    }

    /**
     * No toggle is left behind in either shell's header.
     *
     * The requirement was explicit that a non-functional button must not remain,
     * so the node is deleted rather than hidden — a display:none button is still
     * in the accessibility tree.
     *
     * The Blade half of this is now vacuous by construction: the top header bar
     * was removed from the authenticated UI and header.blade.php was deleted
     * with it, so there is no Blade header for a toggle to come back into. The
     * assertion is kept but guarded on the file existing, so that if a Blade
     * header is ever reintroduced it is immediately back under this contract
     * rather than silently unchecked. The legacy shell still has a header.php
     * (it holds <head> and the sidebar include), so it is checked unconditionally.
     */
    public function test_no_theme_toggle_button_remains_in_either_header(): void
    {
        $markers = ['data-theme-toggle', 'themeToggle', 'theme-toggle-button', 'theme-toggle-wrapper'];

        $legacyHeader = $this->codeOnly($this->read(self::HEADER));

        foreach ($markers as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $legacyHeader,
                "'{$marker}' is back in the legacy header."
            );
        }

        if (!file_exists(base_path(self::BLADE_HEADER))) {
            $this->assertTrue(true, 'No Blade header exists, so no toggle can remain in one.');

            return;
        }

        $bladeHeader = $this->codeOnly($this->read(self::BLADE_HEADER));

        foreach ($markers as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $bladeHeader,
                "'{$marker}' is back in the Blade header."
            );
        }
    }

    /**
     * The chart palette no longer resolves a theme.
     *
     * super-admin-dashboard.php held the last prefers-color-scheme read in the
     * application — it picked a chart colour set from the resolved theme.
     */
    public function test_the_chart_palette_no_longer_resolves_a_theme(): void
    {
        $code = $this->codeOnly($this->read(self::SUPER_ADMIN));

        $this->assertStringContainsString(
            "return 'light';",
            $code,
            'getResolvedTheme() must return light outright.'
        );
        $this->assertStringNotContainsString(
            'prefers-color-scheme',
            $code,
            'The last prefers-color-scheme read is back; OS dark mode would recolour the charts.'
        );
    }

    /**
     * A stale cached main.js would silently undo the change, so the cache-buster
     * must have moved off every version that shipped switching code.
     *
     * A browser still holding an old copy would read the saved preference and
     * re-apply dark after the boot script had already painted light.
     */
    public function test_the_main_js_cache_buster_was_bumped(): void
    {
        $footer = $this->read(self::FOOTER);

        $this->assertMatchesRegularExpression(
            '/main\.js\?v=\d{8}/',
            $footer,
            'main.js must still be loaded with a dated cache-buster.'
        );

        foreach (['main.js?v=20260521', 'main.js?v=20260916'] as $staleToken)
        {
            $this->assertStringNotContainsString(
                $staleToken,
                $footer,
                "main.js still carries {$staleToken}; browsers will keep a ThemeManager that can switch themes."
            );
        }
    }

    /**
     * The light palette itself must survive.
     *
     * Every colour token the application renders with is defined in this one
     * block. It is the thing all of the above is in service of, and deleting the
     * theme system without noticing it would leave the app unstyled rather than
     * light.
     */
    public function test_the_light_token_block_survives(): void
    {
        $styles = $this->read(self::STYLES);

        $this->assertStringContainsString(
            ":root[data-theme-resolved='light'] {",
            $styles,
            'The light theme token block was deleted from styles.css.'
        );
    }
}
