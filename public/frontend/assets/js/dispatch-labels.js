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

    // Asset tracking — the per-unit Asset Codes registered when the dispatch
    // was released with "Track as Assets". GET /api/deployed-assets returns
    // them per line in registration order (Asset 1, Asset 2, ...), which is
    // the order the labels below number "Unit 1 of N". Only released
    // dispatches can have them. Any failure just prints labels without codes.
    async function attachAssetCodes(dispatch) {
        dispatch.__assetCodesByLine = {};
        if (String(dispatch.status || '').toLowerCase() !== 'released') return dispatch;

        try {
            const url = publicUrl(`/api/deployed-assets?dispatch_id=${encodeURIComponent(dispatch.id)}&status=all&per_page=500`);
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json();
            const assets = response.ok && payload.success && Array.isArray(payload.data?.items) ? payload.data.items : [];
            assets.forEach((asset) => {
                const lineId = String(asset.dispatch_item_id ?? '');
                if (!lineId) return;
                (dispatch.__assetCodesByLine[lineId] = dispatch.__assetCodesByLine[lineId] || []).push(asset.asset_code);
            });
        } catch (_) {
            // Labels still print; they just carry no Asset Code line.
        }
        return dispatch;
    }

    function buildLabels(dispatch) {
        const items = Array.isArray(dispatch.items) ? dispatch.items : [];
        const assetCodesByLine = dispatch.__assetCodesByLine || {};
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
            const lineAssetCodes = assetCodesByLine[String(line.id)] || [];

            for (let n = 1; n <= qty; n++) {
                // This unit's own Asset Code, if it was tracked. (The old
                // `item.asset_code` line was removed: that column belongs to
                // the warehouse row, which stands for many units, so it never
                // identified the unit a sticker is stuck on.)
                const assetCode = lineAssetCodes[n - 1] || '';
                labels.push(`
                    <div class="label">
                        <div class="qr">${qr}</div>
                        <div class="info">
                            <div class="brand"><img src="${escapeHtml(logo)}" alt="">PhilCST Property</div>
                            <div class="code">${escapeHtml(code)}</div>
                            <div class="unit">Unit ${n} of ${qty}</div>
                            ${assetCode ? `<div class="asset">Asset: <span>${escapeHtml(assetCode)}</span></div>` : ''}
                            <div class="item">${escapeHtml(item.name || 'Unknown item')}</div>
                            ${detail ? `<div class="meta">${escapeHtml(detail)}</div>` : ''}
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
        /* Never truncated: a cut-off code would make the sticker useless, so a
           long official code wraps onto the next line instead. */
        .asset { font-size: 6pt; font-weight: 700; margin: -.2mm 0 .6mm; word-break: break-all; }
        .asset span { font-family: "Consolas", "Courier New", monospace; font-size: 7.5pt; }
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

        /* Label print tracking — shown on screen after the print dialog
           closes; never printed (it lives inside .toolbar). */
        .mark-bar { flex-basis: 100%; margin-top: 8px; padding: 10px 12px; border-radius: 8px;
                    background: #f5f3ff; border: 1px solid #ddd6fe; color: #3b0764;
                    align-items: center; gap: 10px; flex-wrap: wrap; }
        .mark-bar span { flex: 1 1 260px; }
        .mark-bar .secondary { background: #fff; color: #4c1d95; border: 1px solid #c4b5fd; }
        .mark-bar .error { color: #b91c1c; }
        .toolbar { flex-wrap: wrap; }
    `;

    // Label print tracking — records that the stickers for these dispatches
    // were printed, so the Dispatches page's "Not yet printed" filter (and
    // Print All Labels with it) skips them next time.
    async function markPrinted(dispatchIds) {
        const response = await fetch(publicUrl('/api/dispatches/labels-printed'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ dispatch_ids: dispatchIds }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to mark the labels as printed.');
        }
        return payload.data || { marked_count: 0 };
    }

    // A browser cannot tell whether paper actually came out of the printer
    // ("afterprint" fires even when the dialog is cancelled), so after the
    // dialog closes the preview window asks, and only "Yes" marks anything.
    function wireMarkPrinted(win, dispatchIds, options) {
        const bar = win.document.getElementById('mark-bar');
        if (!bar || !dispatchIds.length) return;

        let settled = false;
        win.addEventListener('afterprint', () => {
            if (!settled) bar.style.display = 'flex';
        });

        bar.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-mark]');
            if (!button || settled) return;

            if (button.dataset.mark === 'no') {
                settled = true;
                bar.innerHTML = '<span>Not marked. These dispatches stay under "Not yet printed" so you can print them again.</span>';
                return;
            }

            bar.querySelectorAll('button').forEach((b) => { b.disabled = true; });
            try {
                const result = await markPrinted(dispatchIds);
                settled = true;
                const n = Number(result.marked_count) || 0;
                bar.innerHTML = `<span>Done — ${n} dispatch${n === 1 ? '' : 'es'} marked as printed. You can close this window.</span>`;
                if (options && typeof options.onMarked === 'function') options.onMarked(result);
            } catch (error) {
                bar.querySelectorAll('button').forEach((b) => { b.disabled = false; });
                const message = bar.querySelector('.error') || bar.appendChild(win.document.createElement('span'));
                message.className = 'error';
                message.textContent = error.message || 'Unable to mark the labels as printed.';
            }
        });
    }

    // Accepts one dispatch or an array of them. Labels from several dispatches
    // run on continuously, so a bulk print fills every sticker on each sheet.
    // options.trackPrinting — ask after printing whether to mark these
    // dispatches as printed; options.onMarked(result) — called once marked.
    function render(dispatches, win, options) {
        const list = Array.isArray(dispatches) ? dispatches : [dispatches];
        const trackPrinting = Boolean(options && options.trackPrinting);
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
    ${trackPrinting ? `<div class="mark-bar" id="mark-bar" style="display:none;">
        <span>Did all labels print correctly? Marking them as printed hides ${list.length === 1 ? 'this dispatch' : `these ${list.length} dispatches`} from the "Not yet printed" filter.</span>
        <button type="button" data-mark="yes">Yes, mark as printed</button>
        <button type="button" data-mark="no" class="secondary">Not yet</button>
    </div>` : ''}
</div>
${sheets}
</body>
</html>`);
        win.document.close();
        win.focus();

        if (trackPrinting) {
            wireMarkPrinted(win, list.map((d) => Number(d.id)).filter((id) => id > 0), options);
        }

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

    async function print(dispatch, options) {
        if (!dispatch) return;
        if (!isPrintable(dispatch.status)) {
            notify('Labels can only be printed for approved or released dispatches.', 'warning');
            return;
        }
        const win = openWindow();
        if (!win) return;
        await attachAssetCodes(dispatch);
        if (!win.closed) render(dispatch, win, options);
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
    async function printMany(ids, apiBase, options) {
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
            for (let i = 0; i < printable.length; i += batchSize) {
                await Promise.all(printable.slice(i, i + batchSize).map(attachAssetCodes));
                if (win.closed) return;
            }
            render(printable, win, options);
        } catch (error) {
            win.close();
            notify(error.message || 'Unable to print labels.', 'danger');
        }
    }

    // The window is opened synchronously (inside the click) so pop-up blockers
    // allow it, then filled once the dispatch has been fetched.
    async function printById(id, apiBase, options) {
        const win = openWindow();
        if (!win) return;
        try {
            const dispatch = await fetchDispatch(id, apiBase);
            if (!isPrintable(dispatch.status)) {
                win.close();
                notify('Labels can only be printed for approved or released dispatches.', 'warning');
                return;
            }
            await attachAssetCodes(dispatch);
            if (!win.closed) render(dispatch, win, options);
        } catch (error) {
            win.close();
            notify(error.message || 'Unable to print labels.', 'danger');
        }
    }

    global.DispatchLabels = { print, printById, printMany, isPrintable };
})(window);
