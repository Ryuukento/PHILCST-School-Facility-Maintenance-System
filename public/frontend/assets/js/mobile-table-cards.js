/**
 * Mobile table cards — on phones (see mobile-table-cards.css, <= 640px) the
 * system's wide data tables are shown as one card per row instead of a table
 * that has to be scrolled sideways.
 *
 * The CSS labels each value with its column name via td[data-label]. Most of
 * these tables are rendered by JavaScript and re-rendered on every filter /
 * page change, so this script copies each column header into data-label
 * automatically and keeps doing so as rows are replaced (MutationObserver) —
 * no table renderer has to change.
 *
 * Opt-in by selector: only the tables listed below are converted, so tables
 * that already have their own phone layout (e.g. All Reports) are untouched.
 */
(function () {
    'use strict';

    const TABLE_SELECTORS = [
        '.dispatches-table',
        '.deployment-tracking-table',
        '.dd-items-table',
        '.activity-log-page table.table',
        '.purchase-receipts-table',
        '.hd-table',
        '.inventory-page table.table',
        '[id^="an-"] table.table',
        '.ir-table',
        'table.m-cards'
    ].join(',');

    function labelTable(table) {
        const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
        if (!headers.length) return;

        table.classList.add('m-cards');
        table.querySelectorAll('tbody tr').forEach((row) => {
            let column = 0;
            Array.from(row.children).forEach((cell) => {
                const span = Math.max(1, Number(cell.colSpan) || 1);
                if (!cell.hasAttribute('data-label')) {
                    // A cell spanning several columns (empty-state rows,
                    // "No records found") gets no label and the full width.
                    cell.setAttribute('data-label', span > 1 ? '' : (headers[column] || ''));
                }
                column += span;
            });
        });
    }

    let scheduled = false;
    function scan() {
        scheduled = false;
        document.querySelectorAll(TABLE_SELECTORS).forEach(labelTable);
    }

    function schedule() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(scan);
    }

    function start() {
        scan();
        // Only childList changes: labelling adds attributes, which must not
        // retrigger the observer.
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
