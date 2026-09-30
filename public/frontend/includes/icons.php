<?php
/**
 * ============================================================================
 * TASK 7 — server-side icon renderer
 * ----------------------------------------------------------------------------
 * Renders an entry from icon-paths.php as an inline <svg>, in the same 24x24
 * stroke style the sidebar and header already use. No library, no CDN, no
 * build step — this only removes the hand-copying.
 *
 * Accessibility (TASK 7 Phase 5): icons default to decorative
 * (aria-hidden="true" focusable="false") because in nearly every call site the
 * visible label beside the icon already supplies the accessible name, and a
 * second announcement would be redundant. Passing a $label opts into
 * role="img" + <title> for the rare icon that carries meaning on its own.
 * ========================================================================= */

if (!function_exists('ui_icon')) {

    /**
     * @param string $name  key from icon-paths.php
     * @param array  $opts  class       extra CSS classes (appended to .ui-icon)
     *                      size        pixel box; default 16
     *                      label       accessible name; omit to stay decorative
     *                      stroke_width  default 2
     * @return string inline <svg> markup, or '' for an unknown name
     */
    function ui_icon(string $name, array $opts = []): string
    {
        static $paths = null;
        if ($paths === null) {
            $paths = require __DIR__ . '/icon-paths.php';
        }

        if (!isset($paths[$name])) {
            // Unknown icon: render nothing rather than a broken glyph. Never
            // fall back to an emoji — that is the pattern this task removes.
            return '';
        }

        $class  = trim('ui-icon ' . (string) ($opts['class'] ?? ''));
        $size   = (int) ($opts['size'] ?? 16);
        $stroke = $opts['stroke_width'] ?? 2;
        $label  = $opts['label'] ?? null;

        $a11y = $label !== null
            ? ' role="img" aria-label="' . htmlspecialchars((string) $label, ENT_QUOTES) . '"'
            : ' aria-hidden="true" focusable="false"';

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '"'
            . ' width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="' . htmlspecialchars((string) $stroke, ENT_QUOTES) . '"'
            . ' stroke-linecap="round" stroke-linejoin="round"'
            . $a11y . '>' . $paths[$name] . '</svg>';
    }

    /**
     * The registry as JSON, for handing to ui-icons.js so the JS renderer
     * draws from the identical geometry. One source of truth for both sides.
     */
    function ui_icon_paths_json(): string
    {
        $paths = require __DIR__ . '/icon-paths.php';

        return json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
