/**
 * SfmsPrint — the shared, branded printout used by the system's list reports
 * (All Reports, Dispatches). One letterhead (PHILCST logo + school name), one
 * title block, an optional summary strip, a data table and a sign-off block,
 * so every printed report looks the same.
 *
 * Laid out for coupon bond (Short 8.5x11 / Long 8.5x13): landscape, a fixed
 * column plan so the table always fits the page width, the column header
 * repeated on every page, rows never split across pages, and a
 * "Page X of Y" footer. Self-contained (inline styles) so it renders the
 * same in the blank print window from any page.
 *
 * Usage:
 *   SfmsPrint.open({
 *     title: 'Maintenance Reports Summary',
 *     subtitle: 'Month of August 2026',
 *     preparedBy: 'Ryan Mondido', preparedRole: 'Administrator',
 *     stats: [{ label: 'Total Reports', value: 9, tone: 'purple' }, ...],
 *     columns: [{ label: 'No.', width: '4%', className: 'c-no' }, ...],
 *     rowsHtml: '<tr>…</tr>…',          // cells already HTML-escaped
 *     recordLabel: 'Records'
 *   });
 * Optional (single-record printouts such as one Purchase Receipt):
 *     details: [{ label: 'OR Number', value: 'OR-001' }, ...]  // key/value grid above the table
 *     orientation: 'portrait'                                  // default 'landscape'
 *     targetWindow: win   // a window already opened inside the click handler,
 *                         // for callers that must fetch data first (pop-up blockers)
 * Cell helpers: SfmsPrint.pill(text, tone), SfmsPrint.escape(text).
 * Tones: purple, blue, violet, green, amber, red, gray.
 */
(function (global) {
    const SCHOOL_NAME = 'Philippine College of Science and Technology';
    const SCHOOL_ADDRESS = 'Calasiao, Pangasinan';
    const SYSTEM_NAME = 'School Facility Maintenance System';

    function escape(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const TONES = ['purple', 'blue', 'violet', 'green', 'amber', 'red', 'gray'];
    const toneOf = (tone) => (TONES.includes(tone) ? tone : 'gray');

    function pill(text, tone) {
        return `<span class="pill tone-${toneOf(tone)}">${escape(text)}</span>`;
    }

    function logoUrl() {
        const path = typeof global.SFMS_PUBLIC_URL === 'function'
            ? global.SFMS_PUBLIC_URL('/frontend/assets/images/logo-seal.svg')
            : '/School_Facility_Maintenance_System/frontend/assets/images/logo-seal.svg';
        // Absolute: the print window is about:blank, where a relative path
        // would not resolve reliably.
        return new URL(path, global.location.href).href;
    }

    const STYLES = `
        @page {
            size: __ORIENTATION__;
            margin: 12mm 11mm 14mm;
            @bottom-left  { content: "PHILCST · ${SYSTEM_NAME}"; font: 8pt Arial, sans-serif; color: #64748b; }
            @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 8pt Arial, sans-serif; color: #64748b; }
        }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        html, body { margin: 0; padding: 0; }
        body { font-family: Arial, "Segoe UI", Helvetica, sans-serif; color: #1f2937; font-size: 9.5pt; background: #fff; }
        .sheet { width: 100%; }

        /* Letterhead */
        .letterhead { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 3px solid #6b21a8; }
        .logo { width: 74px; height: 74px; flex-shrink: 0; object-fit: cover; object-position: center; }
        .school { flex: 1; min-width: 0; }
        .school-name { margin: 0; font-size: 13.5pt; font-weight: 800; color: #4c1d95; letter-spacing: 0.02em; text-transform: uppercase; }
        .school-sub { margin: 2px 0 0; font-size: 9.5pt; color: #475569; }
        .school-system { margin: 3px 0 0; font-size: 9pt; font-weight: 700; color: #6b21a8; letter-spacing: 0.06em; text-transform: uppercase; }
        .doc-meta { text-align: right; font-size: 8.5pt; color: #475569; line-height: 1.5; white-space: nowrap; }
        .doc-meta strong { color: #1f2937; }
        .accent { height: 2px; background: #c4b5fd; margin-top: 2px; }

        /* Title block */
        .title-block { text-align: center; margin: 10px 0 8px; }
        .title-block h1 { margin: 0; font-size: 15pt; letter-spacing: 0.08em; text-transform: uppercase; color: #111827; }
        .title-block p { margin: 3px 0 0; font-size: 10pt; color: #4b5563; }

        /* Summary strip */
        .stats { display: grid; gap: 8px; margin: 0 0 10px; }
        .stat { border: 1px solid #e5e7eb; border-left: 4px solid #6b21a8; border-radius: 6px; padding: 6px 10px; background: #faf5ff; }
        .stat-value { display: block; font-size: 14pt; font-weight: 800; color: #111827; line-height: 1.1; }
        .stat-label { display: block; font-size: 7.5pt; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat.tone-blue   { border-left-color: #3b82f6; background: #eff6ff; }
        .stat.tone-violet { border-left-color: #8b5cf6; background: #f5f3ff; }
        .stat.tone-green  { border-left-color: #10b981; background: #ecfdf5; }
        .stat.tone-amber  { border-left-color: #f59e0b; background: #fffbeb; }
        .stat.tone-red    { border-left-color: #dc2626; background: #fef2f2; }
        .stat.tone-gray   { border-left-color: #64748b; background: #f8fafc; }

        /* Record details (optional key/value grid) */
        .details { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; margin: 0 0 12px; }
        .detail { display: flex; border-bottom: 1px solid #e5e7eb; font-size: 9.5pt; }
        .detail:nth-child(odd) { border-right: 1px solid #e5e7eb; }
        .detail.wide { grid-column: 1 / -1; border-right: 0; }
        .detail-label { flex: 0 0 34%; padding: 6px 8px; background: #faf5ff; color: #4c1d95; font-weight: 700; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.03em; }
        .detail-value { flex: 1; padding: 6px 8px; color: #111827; overflow-wrap: anywhere; }
        .detail.wide .detail-label { flex-basis: 17%; }

        /* Table — fixed plan (table-layout: fixed + <colgroup>) so every
           column always fits the page width. */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        th { background: #4c1d95; color: #fff; font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; text-align: left; padding: 7px 6px; border: 1px solid #4c1d95; }
        td { padding: 6px; border: 1px solid #e5e7eb; vertical-align: top; font-size: 8.8pt; line-height: 1.3; overflow-wrap: anywhere; }
        tbody tr:nth-child(even) td { background: #f8f7fc; }
        td.c-no { text-align: center; color: #6b7280; }
        td.c-key { font-weight: 700; color: #4c1d95; }
        td.c-strong { font-weight: 600; color: #111827; }
        td.c-nowrap { white-space: nowrap; }
        .sub { display: block; margin-top: 2px; font-size: 7.8pt; color: #6b7280; font-weight: 400; }
        .muted { color: #9ca3af; }

        .text-critical { color: #b91c1c; font-weight: 700; } .text-high { color: #b45309; font-weight: 700; }
        .text-medium { color: #1d4ed8; font-weight: 700; }   .text-low { color: #475569; font-weight: 700; }

        .pill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 7.8pt; font-weight: 700; border: 1px solid #cbd5e1; background: #f1f5f9; color: #334155; white-space: nowrap; }
        .pill.tone-purple { background: #faf5ff; border-color: #e9d5ff; color: #6b21a8; }
        .pill.tone-blue   { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
        .pill.tone-violet { background: #f5f3ff; border-color: #ddd6fe; color: #6d28d9; }
        .pill.tone-green  { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
        .pill.tone-amber  { background: #fef3c7; border-color: #fde68a; color: #92400e; }
        .pill.tone-red    { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

        /* Sign-off */
        .signoff { display: flex; justify-content: space-between; gap: 40px; margin-top: 18px; page-break-inside: avoid; break-inside: avoid; }
        .sign { width: 34%; text-align: center; font-size: 9pt; }
        .sign-label { text-align: left; color: #6b7280; margin-bottom: 20px; }
        .sign-line { border-top: 1px solid #111827; padding-top: 4px; font-weight: 700; color: #111827; min-height: 18px; }
        .sign-role { color: #6b7280; font-size: 8.5pt; }
        .end-note { margin-top: 8px; text-align: center; font-size: 8pt; color: #9ca3af; }

        @media screen {
            body { background: #e5e7eb; }
            .sheet { max-width: 1100px; margin: 20px auto; background: #fff; padding: 28px 32px; box-shadow: 0 4px 18px rgba(0,0,0,0.12); }
        }
    `;

    /** Builds the complete printable HTML document (no window involved). */
    function buildDocument(options) {
        const o = options || {};
        const columns = Array.isArray(o.columns) ? o.columns : [];
        const stats = Array.isArray(o.stats) ? o.stats : [];
        const generatedAt = new Date().toLocaleString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit'
        });
        const preparedBy = escape(o.preparedBy || 'System User');
        const docTitle = `PHILCST ${o.title || 'Report'}${o.subtitle ? ` - ${o.subtitle}` : ''}`;

        const statsHtml = stats.length
            ? `<section class="stats" style="grid-template-columns: repeat(${stats.length}, 1fr);">${stats.map((s) => `
                <div class="stat tone-${toneOf(s.tone)}"><span class="stat-value">${escape(s.value)}</span><span class="stat-label">${escape(s.label)}</span></div>
            `).join('')}</section>`
            : '';

        const details = Array.isArray(o.details) ? o.details : [];
        const detailsHtml = details.length
            ? `<section class="details">${details.map((d) => `
                <div class="detail${d.wide ? ' wide' : ''}"><span class="detail-label">${escape(d.label)}</span><span class="detail-value">${escape(d.value ?? '—')}</span></div>
            `).join('')}</section>`
            : '';
        const orientation = o.orientation === 'portrait' ? 'portrait' : 'landscape';

        return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>${escape(docTitle)}</title>
    <style>${STYLES.replace('__ORIENTATION__', orientation)}</style>
</head>
<body>
    <div class="sheet">
        <header class="letterhead">
            <img id="sfms-print-logo" class="logo" src="${escape(logoUrl())}" alt="PHILCST logo">
            <div class="school">
                <p class="school-name">${escape(SCHOOL_NAME)}</p>
                <p class="school-sub">${escape(SCHOOL_ADDRESS)}</p>
                <p class="school-system">${escape(SYSTEM_NAME)}</p>
            </div>
            <div class="doc-meta">
                <div><strong>Generated:</strong> ${escape(generatedAt)}</div>
                <div><strong>Prepared by:</strong> ${preparedBy}</div>
                <div><strong>${escape(o.recordLabel || 'Records')}:</strong> ${escape(o.recordCount ?? '')}</div>
            </div>
        </header>
        <div class="accent"></div>

        <div class="title-block">
            <h1>${escape(o.title || 'Report')}</h1>
            ${o.subtitle ? `<p>${escape(o.subtitle)}</p>` : ''}
        </div>

        ${statsHtml}

        ${detailsHtml}

        <table>
            <colgroup>${columns.map((c) => `<col style="width: ${escape(c.width || 'auto')};">`).join('')}</colgroup>
            <thead><tr>${columns.map((c) => `<th>${escape(c.label)}</th>`).join('')}</tr></thead>
            <tbody>${o.rowsHtml || ''}</tbody>
        </table>

        <section class="signoff">
            <div class="sign">
                <div class="sign-label">Prepared by:</div>
                <div class="sign-line">${preparedBy}</div>
                <div class="sign-role">${escape(o.preparedRole || '')}</div>
            </div>
            <div class="sign">
                <div class="sign-label">Noted by:</div>
                <div class="sign-line"></div>
                <div class="sign-role">Signature over printed name</div>
            </div>
        </section>
        <p class="end-note">— End of report —</p>
    </div>
</body>
</html>`;
    }

    /**
     * Opens the print window and prints once the logo has loaded (printing
     * straight after document.write() can capture the page before the image
     * arrives, leaving an empty logo box on paper). Returns false when the
     * browser blocked the pop-up.
     */
    function open(options) {
        const target = options && options.targetWindow;
        const printWindow = target && !target.closed ? target : global.open('', '_blank', 'width=1200,height=900');
        if (!printWindow) {
            return false;
        }

        printWindow.document.open();
        printWindow.document.write(buildDocument(options));
        printWindow.document.close();
        printWindow.focus();

        let printed = false;
        const doPrint = () => {
            if (printed) return;
            printed = true;
            printWindow.print();
        };
        const logo = printWindow.document.getElementById('sfms-print-logo');
        if (!logo || logo.complete) {
            doPrint();
        } else {
            logo.addEventListener('load', doPrint);
            logo.addEventListener('error', doPrint);
            setTimeout(doPrint, 3000);
        }
        return true;
    }

    global.SfmsPrint = { open, buildDocument, pill, escape };
})(window);
