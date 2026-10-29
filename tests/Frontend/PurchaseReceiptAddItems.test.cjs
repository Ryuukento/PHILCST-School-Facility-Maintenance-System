// Executes the page's row and submission code with offline DOM/API doubles.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/purchase-receipts.php'), 'utf8').replace(/\r\n/g, '\n');
function cut(start, end) {
    const i = page.indexOf(start), j = page.indexOf(end, i);
    assert.ok(i >= 0 && j > i, start);
    return page.slice(i, j);
}
const rowCode = cut('function prRowElements()', '// Reorder Previous Receipt:');
const suggestion = cut('function prApplySuggestion(', 'function prMoveSuggestActive(');
const submitCode = cut("document.getElementById('add-items-form').addEventListener('submit'", '// Post Receipt button');
function node() {
    const classes = new Set();
    return { value: '', textContent: '', dataset: {}, attrs: {}, listeners: {}, disabled: false,
        classList: { toggle: (c, on) => on ? classes.add(c) : classes.delete(c), contains: c => classes.has(c), add: c => classes.add(c) },
        setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k]; },
        addEventListener(k, fn) { this.listeners[k] = fn; },
        focus() { this.focused = true; }, select() {}, scrollIntoView() { this.scrolled = true; },
        querySelector() { return {}; },
    };
}
function harness() {
    let rows = [], requests = [], closed = 0, refreshed = 0, toasts = [];
    const nodes = new Map();
    const get = id => { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); };
    get('pr-bulk-rows').appendChild = row => { rows.push(row); };
    const escape = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const decode = s => s.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    let response = { response: { ok: true }, data: { success: true, data: { count: 1, ids: [11] } } };
    let hold;
    const context = vm.createContext({
        document: { getElementById: get, querySelectorAll: () => rows, createElement() {
            const row = node(), fields = new Map();
            for (const sel of ['.pr-item-input', '.pr-item-name-error', '.pr-category-select', '.pr-qty-input', '.pr-unit-select', '.pr-row-note', '.pr-row-remove']) fields.set(sel, node());
            row.querySelector = sel => fields.get(sel);
            fields.get('.pr-item-input').closest = () => row;
            Object.defineProperty(row, 'innerHTML', { set(html) {
                row.html = html;
                fields.get('.pr-item-input').value = decode(html.match(/class="form-control pr-item-input"[\s\S]*?value="([^"]*)"/)[1]);
                fields.get('.pr-qty-input').value = html.match(/class="form-control pr-qty-input"[\s\S]*?value="([^"]*)"/)[1];
                fields.get('.pr-unit-select').value = 'pc';
            } });
            row.remove = () => { rows = rows.filter(r => r !== row); };
            return row;
        } },
        prRowSeq: 0, PR_UNITS: ['pc', 'box', 'set'], PURCHASE_API: '/api/purchase-receipts', PR_RECEIPT_ID: 6,
        prEscapeHtml: escape, prCategoryOptions: () => '', prUnitOptions: () => '',
        prHideSuggest() {}, prSetBulkError: message => { get('add-items-error').textContent = message; },
        prCloseAddItems: () => closed++, UI: { toast: (...args) => toasts.push(args) },
        prCloseModal: () => closed++,
        loadReceiptItems: async () => refreshed++,
        prFetch: async (url, options) => { requests.push({ url, ...options }); if (hold) await hold; return response; },
    });
    vm.runInContext(rowCode + suggestion + submitCode, context);
    return { context, get, rows: () => rows, requests, add: (data = {}) => context.prAddRow(data, false),
        submit: () => get('add-items-form').listeners.submit({ preventDefault() {} }),
        state: () => ({ closed, refreshed, toasts }), fail: r => { response = r; },
        hold: promise => { hold = promise; } };
}
const valid = ['Electric Fan', 'Office Chair', 'Keyboard', 'Monitor', 'RJ45 Connector', 'USB-C Cable',
    '3.5mm Audio Cable', '1.5 HP Air Conditioner', 'TV', 'PC', 'Air Conditioner (Window Type)',
    'Cable, Audio/USB', 'Écran électrique', '電気扇', 'Cafe\u0301 Monitor', 'A'.repeat(100)];
for (const name of valid) test(`accepts ${name.slice(0, 30)}`, () => {
    const h = harness(); h.add({ item_name: name });
    assert.equal(h.context.prEvaluateRows(true).errors.length, 0);
});
for (const name of ['', '   ', 'A', '12345', '@@@###', 'Chair!!!', 'Electric@Fan', 'Cable & Plug', 'Item_Name', '---', 'A'.repeat(101), 'Fan🙂', 'Fan½', 'Fa\0n']) {
    test(`rejects ${JSON.stringify(name.slice(0, 20))} inline without losing input`, async () => {
        const h = harness(), row = h.add({ item_name: name });
        await h.submit();
        assert.equal(h.requests.length, 0);
        assert.equal(row.querySelector('.pr-item-input').value, name);
        assert.equal(row.querySelector('.pr-item-input').getAttribute('aria-invalid'), 'true');
        assert.ok(row.querySelector('.pr-item-name-error').textContent);
        assert.equal(row.querySelector('.pr-item-input').focused, true);
        assert.equal(row.scrolled, true);
    });
}
test('normalizes spaces and preserves acronyms; duplicates retain existing combination behavior', () => {
    const h = harness(); h.add({ item_name: ' USB-C\t  Cable\n' }); h.add({ item_name: 'USB-C Cable' });
    const result = h.context.prEvaluateRows(true);
    assert.equal(result.ready[0].data.item_name, 'USB-C Cable');
    assert.equal(result.errors.length, 0);
    assert.match(h.rows()[1].querySelector('.pr-row-note').textContent, /combined/);
});
test('twenty independent rows retain values, accurate counts and per-field error associations', () => {
    const h = harness();
    for (let i = 0; i < 20; i++) h.add({ item_name: `Chair ${i}` });
    assert.equal(h.get('pr-bulk-count').textContent, '20 rows');
    const bad = h.rows()[9]; bad.querySelector('.pr-item-input').value = 'Chair!!!';
    h.context.prEvaluateRows(true);
    assert.ok(bad.querySelector('.pr-item-name-error').textContent);
    assert.equal(h.rows()[8].querySelector('.pr-item-name-error').textContent, '');
    h.context.prRemoveRow(h.rows()[2]);
    assert.equal(h.get('pr-bulk-count').textContent, '19 rows');
    assert.equal(bad.querySelector('.pr-item-input').value, 'Chair!!!');
    assert.match(bad.html, /aria-describedby="pr-item-name-error-10"/);
});
test('autocomplete keeps real IDs, category/unit selection and unchanged legacy names', () => {
    const h = harness(), row = h.add({ item_name: 'Fan!!!' });
    const input = row.querySelector('.pr-item-input');
    h.context.prApplySuggestion(input, { id: 77, name: 'Fan!!!', category_id: 2, unit_type: 'set' });
    assert.equal(h.context.prEvaluateRows(true).errors.length, 0);
    assert.equal(h.context.prReadRow(row).item_id, 77);
    assert.equal(row.querySelector('.pr-unit-select').value, 'set');
    input.value = 'Modified!!!'; delete input.dataset.itemId; delete input.dataset.selectedItemName;
    assert.equal(h.context.prEvaluateRows(true).errors.length, 1);
});
test('save makes one existing bulk request, prevents double submission, closes and reloads', async () => {
    const h = harness(); h.add({ item_name: ' USB-C  Cable ' });
    let release; h.hold(new Promise(resolve => { release = resolve; }));
    const first = h.submit(); await h.submit();
    assert.equal(h.requests.length, 1); assert.equal(h.get('submitAddItemsBtn').disabled, true);
    assert.equal(JSON.parse(h.requests[0].body).items[0].item_name, 'USB-C Cable');
    assert.equal(h.requests[0].url, '/api/purchase-receipts/6/items/bulk');
    release(); await first;
    assert.equal(h.state().closed, 1); assert.equal(h.state().refreshed, 1); assert.equal(h.state().toasts.length, 1);
});
test('server field errors remain inline after finally; input and modal stay intact', async () => {
    const h = harness(), row = h.add({ item_name: 'Office Chair' });
    h.fail({ response: { ok: false }, data: { success: false, data: { errors: [{ index: 0, field: 'item_name', message: 'Server name error' }] } } });
    await h.submit();
    assert.equal(row.querySelector('.pr-item-name-error').textContent, 'Server name error');
    assert.equal(row.querySelector('.pr-item-input').value, 'Office Chair');
    assert.equal(h.state().closed, 0); assert.equal(h.state().toasts.length, 0);
    row.querySelector('.pr-item-input').value = 'New Office Chair'; h.context.prRefreshRowState();
    assert.equal(row.querySelector('.pr-item-name-error').textContent, '');
});
test('bounded modal CSS keeps header/footer and Add Another Item outside the row scroller', () => {
    assert.match(cut('.pr-bulk-modal {', '.pr-bulk-header {'), /max-height: 92dvh/);
    assert.match(cut('.pr-bulk-form {', '/* Column template'), /overflow: hidden/);
    assert.match(cut('.pr-bulk-rows {', '.pr-bulk-row {'), /overflow-y: auto/);
    assert.match(cut('.pr-bulk-rows {', '.pr-bulk-row {'), /max-height: 52dvh/);
    assert.match(cut('.pr-bulk-row {\n    flex-shrink', '.pr-bulk-row.pr-row-invalid'), /flex-shrink: 0/);
    assert.ok(page.indexOf('id="prAddAnotherRow"') > page.indexOf('id="pr-bulk-rows" class="pr-bulk-rows"></div>'));
    assert.match(cut('.pr-bulk-footer {', '.pr-bulk-count'), /flex-shrink: 0/);
    assert.match(page, /@media/);
});
test('Cancel, close and Escape preserve existing modal dismissal; full scripts compile', () => {
    assert.ok(page.includes("document.getElementById('cancelAddItemsModal').addEventListener('click', prCloseAddItems)"));
    assert.ok(page.includes("case 'add-items-modal': prCloseAddItems(); break;"));
    const cancel = cut('prCloseAddItems = function () {', "document.getElementById('prAddAnotherRow')");
    assert.ok(!cancel.includes('prFetch'));
    const h = harness(); h.add({ item_name: 'Chair' });
    vm.runInContext(cancel, h.context);
    h.get('cancelAddItemsModal').listeners.click();
    assert.equal(h.requests.length, 0); assert.equal(h.state().closed, 1);
    const source = page.replace(/<\?php[\s\S]*?\?>/g, 'null');
    for (const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) new vm.Script(match[1]);
});
