/**
 * Dispatch Code labels — printable A4 sticker sheet, one label per deployed
 * unit, so every item placed in a room carries its Dispatch Code.
 *
 * Layout matches the common 21-up A4 sticker sheet (Avery L7160 and
 * compatibles): 3 columns x 7 rows, 63.5mm x 38.1mm labels.
 *
 * Each label has a QR code pointing at the dispatch's detail page, so scanning
 * the sticker with a phone opens the record (the page still requires login).
 *
 * Depends on vendor/qrcode-generator.js (global `qrcode`).
 *
 * Usage:
 *   DispatchLabels.print(dispatch)        — dispatch payload already loaded
 *   DispatchLabels.printById(id, apiBase) — fetches GET {apiBase}/{id} first
 *   DispatchLabels.printMany(ids, apiBase) — many dispatches on one sticker run
 */
(function (global) {
    'use strict';

    // Only dispatches whose items are (or are about to be) physically deployed
    // get labels — a pending or cancelled dispatch never reached a room.
    const PRINTABLE_STATUSES = ['approved', 'released'];

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function publicUrl(path) {
        const rel = typeof global.SFMS_PUBLIC_URL === 'function' ? global.SFMS_PUBLIC_URL(path) : path;
        return new URL(rel, global.location.href).href;
    }

    function notify(message, type) {
        if (global.Components && typeof global.Components.alert === 'function') {
            global.Components.alert(message, type || 'warning');
        } else {
            global.alert(message);
        }
    }

    function isPrintable(status) {
        return PRINTABLE_STATUSES.includes(String(status || '').toLowerCase());
    }

    function formatDate(value) {
        if (!value) return '';
        const d = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString();
    }

    // Builds the QR as a crisp vector <svg> (one path for all dark modules) so
    // it stays sharp at any print resolution.
    function qrSvg(text) {
        if (typeof global.qrcode !== 'function') return '';
        const qr = global.qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        const count = qr.getModuleCount();
        const quiet = 2;
        const size = count + quiet * 2;
        let d = '';
        for (let r = 0; r < count; r++) {
            for (let c = 0; c < count; c++) {
                if (qr.isDark(r, c)) d += `M${c + quiet} ${r + quiet}h1v1h-1z`;
            }
        }
        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges">`
            + `<rect width="${size}" height="${size}" fill="#fff"/><path d="${d}" fill="#000"/></svg>`;
    }

    function buildLabels(dispatch) {
        const items = Array.isArray(dispatch.items) ? dispatch.items : [];
        const code = dispatch.dispatch_code || '';
        const qr = qrSvg(publicUrl(`/dispatches/${dispatch.id}`));
        const room = dispatch.room_name || dispatch.room?.name || '';
        const dept = dispatch.department_name || dispatch.department?.name || '';
        const date = formatDate(dispatch.created_at);
        const logo = publicUrl('/frontend/assets/images/logo-seal.svg');

        const labels = [];
        items.forEach((line) => {
            const item = line.item || {};
            const qty = Math.max(0, parseInt(line.quantity, 10) || 0);
            const detail = [item.brand, item.model].filter(Boolean).join(' ');

            for (let n = 1; n <= qty; n++) {
                labels.push(`
                    <div class="label">
                        <div class="qr">${qr}</div>
                        <div class="info">
                            <div class="brand"><img src="${escapeHtml(logo)}" alt="">PhilCST Property</div>
                            <div class="code">${escapeHtml(code)}</div>
                            <div class="unit">Unit ${n} of ${qty}</div>
                            <div class="item">${escapeHtml(item.name || 'Unknown item')}</div>
                            ${detail ? `<div class="meta">${escapeHtml(detail)}</div>` : ''}
                            ${item.asset_code ? `<div class="meta">Asset: ${escapeHtml(item.asset_code)}</div>` : ''}
                            <div class="meta">${escapeHtml([room, dept].filter(Boolean).join(' · ') || '—')}</div>
                            ${date ? `<div class="meta">Dispatched: ${escapeHtml(date)}</div>` : ''}
                        </div>
                    </div>`);
            }
        });
        return labels;
    }

    const LABEL_CSS = `
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000;
               -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* 21-up A4: 3 x 63.5mm + 2 x 2.5mm gap = 195.5mm, centred on 210mm
           (7.25mm side margins); 7 x 38.1mm = 266.7mm, 15.15mm top margin. */
        .sheet { width: 210mm; height: 297mm; padding: 15.15mm 7.25mm 0;
                 display: grid; grid-template-columns: repeat(3, 63.5mm);
                 grid-auto-rows: 38.1mm; column-gap: 2.5mm; row-gap: 0;
                 page-break-after: always; break-after: page; overflow: hidden; }
        .sheet:last-child { page-break-after: auto; break-after: auto; }

        .label { width: 63.5mm; height: 38.1mm; padding: 2.5mm 3mm;
                 display: flex; gap: 2.5mm; align-items: center; overflow: hidden; }
        .qr { flex: 0 0 22mm; width: 22mm; height: 22mm; }
        .qr svg { width: 100%; height: 100%; display: block; }
        .info { flex: 1; min-width: 0; line-height: 1.2; }
        .brand { display: flex; align-items: center; gap: 1mm; font-size: 6pt;
                 font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: #4c1d95; }
        .brand img { width: 3.5mm; height: 3.5mm; object-fit: contain; }
        .code { font-family: "Consolas", "Courier New", monospace; font-size: 8.5pt;
                font-weight: 700; margin-top: .8mm; word-break: break-all; }
        .unit { font-size: 6.5pt; font-weight: 700; margin-bottom: .6mm; }
        .item { font-size: 7.5pt; font-weight: 700; overflow: hidden;
                display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .meta { font-size: 6pt; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* On screen only: show the sheet like paper with faint label outlines
           so the user can check the layout before printing. */
        @media screen {
            body { background: #e5e7eb; padding: 12px 0; }
            .toolbar { width: 210mm; margin: 0 auto 10px; font-size: 13px; color: #374151;
                       display: flex; justify-content: space-between; align-items: center; }
            .toolbar button { font: inherit; padding: 6px 14px; border: 0; border-radius: 6px;
                              background: #6d28d9; color: #fff; cursor: pointer; }
            .sheet { margin: 0 auto 12px; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
            .label { outline: 1px dashed #c4b5fd; outline-offset: -1px; }
        }
        @media print { .toolbar { display: none; } }
    `;

    // Accepts one dispatch or an array of them. Labels from several dispatches
    // run on continuously, so a bulk print fills every sticker on each sheet.
    function render(dispatches, win) {
        const list = Array.isArray(dispatches) ? dispatches : [dispatches];
        const labels = list.flatMap(buildLabels);
        if (!labels.length) {
            win.close();
            notify(list.length > 1 ? 'The selected dispatches have no items to label.' : 'This dispatch has no items to label.', 'warning');
            return;
        }

        const perSheet = 21;
        let sheets = '';
        for (let i = 0; i < labels.length; i += perSheet) {
            sheets += `<div class="sheet">${labels.slice(i, i + perSheet).join('')}</div>`;
        }
        const pages = Math.ceil(labels.length / perSheet);
        const code = escapeHtml(list.length === 1
            ? (list[0].dispatch_code || '')
            : `${list.length} dispatches`);

        win.document.open();
        win.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dispatch Labels ${code}</title>
<style>${LABEL_CSS}</style>
</head>
<body>
<div class="toolbar">
    <span><strong>${code}</strong> — ${labels.length} label${labels.length === 1 ? '' : 's'} on ${pages} A4 sheet${pages === 1 ? '' : 's'} (21 per sheet). Set printer scale to 100% / "Actual size".</span>
    <button type="button" onclick="window.print()">Print</button>
</div>
${sheets}
</body>
</html>`);
        win.document.close();
        win.focus();

        // Give the logo a moment to load so it is not missing from the print.
        let printed = false;
        const doPrint = () => { if (!printed) { printed = true; win.print(); } };
        const img = win.document.querySelector('.brand img');
        if (img && !img.complete) {
            img.addEventListener('load', doPrint);
            img.addEventListener('error', doPrint);
            setTimeout(doPrint, 1500);
        } else {
            setTimeout(doPrint, 150);
        }
    }

    function openWindow() {
        const win = global.open('', '_blank', 'width=1000,height=900');
        if (!win) {
            notify('Unable to open print preview. Please allow pop-ups for this site.', 'warning');
            return null;
        }
        win.document.write('<p style="font-family:Arial,sans-serif;padding:24px;">Preparing labels…</p>');
        return win;
    }

    function print(dispatch) {
        if (!dispatch) return;
        if (!isPrintable(dispatch.status)) {
            notify('Labels can only be printed for approved or released dispatches.', 'warning');
            return;
        }
        const win = openWindow();
        if (win) render(dispatch, win);
    }

    async function fetchDispatch(id, apiBase) {
        const response = await fetch(`${apiBase}/${id}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json();
        const dispatch = payload?.data?.dispatch;
        if (!response.ok || !payload.success || !dispatch) {
            throw new Error(payload?.message || 'Failed to load dispatch');
        }
        return dispatch;
    }

    // Bulk print: labels for many dispatches on one continuous sticker run.
    // Pending/cancelled ones are skipped. Details are fetched a few at a time
    // because the list endpoint carries no item lines. `ids` may be an array or
    // an async function returning one — the window opens first, inside the
    // click, so pop-up blockers allow it.
    async function printMany(ids, apiBase) {
        const win = openWindow();
        if (!win) return;
        try {
            const list = typeof ids === 'function' ? await ids() : ids;
            const unique = [...new Set((list || []).map(String))];
            if (!unique.length) {
                win.close();
                notify('There are no approved or released dispatches to print labels for.', 'warning');
                return;
            }
            const dispatches = [];
            const batchSize = 5;
            for (let i = 0; i < unique.length; i += batchSize) {
                const batch = await Promise.all(unique.slice(i, i + batchSize).map((id) => fetchDispatch(id, apiBase)));
                dispatches.push(...batch);
                if (win.closed) return;
                win.document.body.innerHTML = `<p style="font-family:Arial,sans-serif;padding:24px;">Preparing labels… ${dispatches.length} of ${unique.length} dispatches</p>`;
            }
            const printable = dispatches
                .filter((d) => isPrintable(d.status))
                .sort((a, b) => String(a.dispatch_code || '').localeCompare(String(b.dispatch_code || '')));
            if (!printable.length) {
                win.close();
                notify('There are no approved or released dispatches to print labels for.', 'warning');
                return;
            }
            render(printable, win);
        } catch (error) {
            win.close();
            notify(error.message || 'Unable to print labels.', 'danger');
        }
    }

    // The window is opened synchronously (inside the click) so pop-up blockers
    // allow it, then filled once the dispatch has been fetched.
    async function printById(id, apiBase) {
        const win = openWindow();
        if (!win) return;
        try {
            const dispatch = await fetchDispatch(id, apiBase);
            if (!isPrintable(dispatch.status)) {
                win.close();
                notify('Labels can only be printed for approved or released dispatches.', 'warning');
                return;
            }
            render(dispatch, win);
        } catch (error) {
            win.close();
            notify(error.message || 'Unable to print labels.', 'danger');
        }
    }

    global.DispatchLabels = { print, printById, printMany, isPrintable };
})(window);
