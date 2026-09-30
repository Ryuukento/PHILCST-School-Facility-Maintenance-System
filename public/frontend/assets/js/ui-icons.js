/* ============================================================================
   TASK 7 — client-side icon renderer
   ----------------------------------------------------------------------------
   Much of this application paints its UI from JavaScript (dispatch-detail,
   buildings-overview, reports, notifications...), so the emoji glyphs being
   retired live in template literals, not in PHP. This module gives that code
   the same icons the server renders.

   It does NOT carry its own copy of the geometry. header.php serialises
   includes/icon-paths.php into window.UI_ICON_PATHS, and this file reads it,
   so PHP and JS can never disagree about what an icon looks like.

   Style contract matches includes/icons.php exactly: 24x24 box, stroke-based,
   stroke="currentColor" so Light/Dark theme is inherited from text colour.
   ========================================================================= */
(function (global) {
    'use strict';

    var PATHS = global.UI_ICON_PATHS || {};

    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Build an inline <svg> string.
     *
     * @param {string} name  key from the shared registry
     * @param {object} [opts]
     *        opts.className    extra classes, appended to .ui-icon
     *        opts.size         pixel box, default 16
     *        opts.label        accessible name; omit to stay decorative
     *        opts.strokeWidth  default 2
     * @returns {string} markup, or '' when the name is unknown
     */
    function svg(name, opts) {
        opts = opts || {};

        var inner = PATHS[name];
        if (!inner) {
            // Unknown icon renders as nothing. Never fall back to an emoji —
            // that is precisely the pattern TASK 7 removes.
            return '';
        }

        var cls = ('ui-icon ' + (opts.className || '')).trim();
        var size = opts.size || 16;
        var strokeWidth = opts.strokeWidth || 2;

        var a11y = opts.label
            ? ' role="img" aria-label="' + escapeAttr(opts.label) + '"'
            : ' aria-hidden="true" focusable="false"';

        return '<svg class="' + escapeAttr(cls) + '"'
            + ' width="' + size + '" height="' + size + '"'
            + ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            + ' stroke-width="' + strokeWidth + '"'
            + ' stroke-linecap="round" stroke-linejoin="round"'
            + a11y + '>' + inner + '</svg>';
    }

    /** True when a name exists in the registry — lets callers branch safely. */
    function has(name) {
        return Object.prototype.hasOwnProperty.call(PATHS, name);
    }

    global.UIIcons = { svg: svg, has: has, paths: PATHS };
}(window));
