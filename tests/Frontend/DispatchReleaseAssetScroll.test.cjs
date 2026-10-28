// Offline DOM doubles and CSS/source checks, not real-browser layout verification.
// Run: node --test tests/Frontend/DispatchReleaseAssetScroll.test.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/dispatch-detail.php'), 'utf8');
const cut = (start, end) => {
    const i = page.indexOf(start), j = page.indexOf(end, i);
    assert.ok(i >= 0 && j > i, start);
    return page.slice(i, j);
};
const release = cut('const DD_ASSET_CODE_PATTERN', '// Tracked Assets card');
const primitives = cut('function ddOpenModal(', '// ---------------------------------------------------------------------------\n// Approve');
const closing = cut('function ddCloseReleaseModal()', '// ---------------------------------------------------------------------------');
const wiring = cut('function ddBind(', "document.addEventListener('DOMContentLoaded'");
const escaping = cut('function ddEscapeHtml(', 'function ddNotify(');

function harness(quantities) {
    const nodes = new Map(), requests = [];
    let lines = [], focused = null, reloads = 0, settle;
    class Element {
        constructor() {
            this.style = {}; this.value = ''; this.textContent = ''; this.disabled = false;
            this.hidden = false; this.events = {}; this.dataset = {}; this.children = [];
            this.classList = { toggle() {}, add() {}, remove() {} };
        }
        setAttribute() {}
        removeAttribute() {}
        addEventListener(type, fn) { this.events[type] = fn; }
        focus() { focused = this; }
        closest() { return this.line; }
        querySelector(selector) { return selector === '.dd-asset-units' ? this.units : this.querySelectorAll(selector)[0] || null; }
        querySelectorAll(selector) { return selector === '.dd-asset-input' ? (this.inputs || []) : []; }
        set innerHTML(html) {
            this.html = html;
            if (this === nodes.get('dd-release-assets')) {
                lines = [...html.matchAll(/class="dd-asset-line" data-line-id="(\d+)" data-qty="(\d+)"/g)].map((match) => {
                    const line = new Element(); line.dataset = { lineId: match[1], qty: match[2] };
                    line.units = new Element(); line.checkbox = new Element(); line.checkbox.line = line;
                    line.querySelectorAll = (selector) => selector === '.dd-asset-input' ? line.units.inputs || [] : [];
                    return line;
                });
            } else {
                this.inputs = [...html.matchAll(/<input type="text" id="([^"]+)" class="form-control dd-asset-input"/g)]
                    .map((match) => { const input = new Element(); input.id = match[1]; return input; });
            }
        }
        get innerHTML() { return this.html || ''; }
        async click() { if (!this.disabled) return this.events.click?.({ target: this }); }
    }
    for (const id of ['dd-release-assets', 'dd-release-modal', 'dd-release-modal-close', 'dd-release-modal-dismiss', 'dd-release-confirm-btn', 'dd-release-error', 'dd-release-remarks']) nodes.set(id, new Element());
    nodes.get('dd-release-confirm-btn').textContent = 'Confirm Release';
    const container = nodes.get('dd-release-assets');
    container.querySelectorAll = (selector) => selector === '.dd-asset-track' ? lines.map((line) => line.checkbox) : [];
    const document = {
        getElementById: (id) => nodes.get(id),
        querySelectorAll: (selector) => selector.endsWith('.dd-asset-line') ? lines : lines.filter((line) => line.checkbox.checked).map((line) => line.checkbox),
    };
    const context = vm.createContext({
        document, DSP_ID: 6,
        window: { SFMS_PUBLIC_URL: (url) => url, location: { reload() { reloads++; } } },
        fetch: async (url, options) => {
            requests.push({ url, ...options });
            await new Promise((resolve) => { settle = resolve; });
            return { ok: true, json: async () => ({ success: true }) };
        },
        ddNotify() {},
    });
    new vm.Script(escaping + primitives + closing + release + wiring).runInContext(context);
    context.ddRenderReleaseAssets({ items: quantities.map((quantity, i) => ({ id: i + 10, quantity, item: { name: 'Item ' + i } })) });
    for (const line of lines) { line.checkbox.checked = true; context.ddToggleAssetLine(line.checkbox); }
    context.ddWireModal('dd-release-modal', context.ddCloseReleaseModal, 'dd-release-confirm-btn', context.doRelease);
    return { context, nodes, requests, get lines() { return lines; }, get focused() { return focused; }, get reloads() { return reloads; }, settle: () => settle() };
}

for (const quantity of [1, 3, 100]) {
    test(quantity + ' tracked units render editable inputs and preserve values/references after scrolling and focusing', () => {
        const h = harness([quantity]);
        const inputs = h.lines[0].units.inputs;
        assert.equal(inputs.length, quantity);
        inputs.forEach((input, i) => { input.value = 'EXISTING-' + (i + 1); });
        const container = h.nodes.get('dd-release-assets');
        container.scrollTop = 1200;
        inputs.at(-1).focus();
        container.scrollTop = 0;
        assert.equal(h.lines[0].units.inputs, inputs);
        inputs.forEach((input, i) => assert.equal(input.value, 'EXISTING-' + (i + 1)));
        const collected = h.context.ddCollectReleaseAssets();
        assert.equal(collected.error, undefined);
        assert.equal(collected.assets['10'].length, quantity);
        assert.equal(collected.assets['10'][quantity - 1], 'EXISTING-' + quantity);
    });
}

test('multiple tracked items and blank fields retain the existing per-line payload contract', () => {
    const h = harness([3, 100]);
    h.lines[0].units.inputs[0].value = ' abc-001 ';
    h.lines[1].units.inputs[99].value = 'LAST-100';
    const collected = JSON.parse(JSON.stringify(h.context.ddCollectReleaseAssets()));
    assert.equal(collected.assets['10'][0], 'ABC-001');
    assert.deepEqual(collected.assets['10'].slice(1), ['', '']);
    assert.equal(collected.assets['11'].length, 100);
    assert.equal(collected.assets['11'][99], 'LAST-100');
});

test('Confirm uses the unchanged release API once while the button is disabled; remarks are preserved', async () => {
    const h = harness([100]);
    h.lines[0].units.inputs[99].value = 'LAST-100';
    h.nodes.get('dd-release-remarks').value = ' Front desk handover ';
    const button = h.nodes.get('dd-release-confirm-btn');
    const pending = button.click(); await button.click();
    assert.equal(button.disabled, true); assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/api/dispatches/6/release');
    assert.equal(h.requests[0].method, 'POST');
    const body = JSON.parse(h.requests[0].body);
    assert.equal(body.release_remarks, 'Front desk handover');
    assert.equal(body.assets['10'].length, 100); assert.equal(body.assets['10'][0], '');
    assert.equal(body.assets['10'][99], 'LAST-100'); assert.equal(body.released_by, undefined);
    h.settle(); await pending; assert.equal(h.reloads, 1);
});

test('Cancel and close send no release request', async () => {
    const h = harness([100]); h.context.ddOpenModal('dd-release-modal');
    await h.nodes.get('dd-release-modal-dismiss').click();
    assert.equal(h.nodes.get('dd-release-modal').style.display, 'none');
    assert.equal(h.requests.length, 0); assert.equal(h.reloads, 0);
});

test('asset-code validation and intentional untracking behavior remain unchanged', () => {
    const h = harness([3]);
    h.lines[0].units.inputs[0].value = 'DUP-001'; h.lines[0].units.inputs[1].value = 'dup-001';
    assert.match(h.context.ddCollectReleaseAssets().error, /entered more than once/);
    h.lines[0].checkbox.checked = false; h.context.ddToggleAssetLine(h.lines[0].checkbox);
    assert.equal(h.lines[0].units.hidden, true); assert.equal(h.lines[0].units.inputs.length, 0);
    assert.deepEqual(JSON.parse(JSON.stringify(h.context.ddCollectReleaseAssets())), { assets: {} });
});

test('modal shell is viewport-bounded with non-scrolling header/footer and independent scroll region', () => {
    const shell = cut('.dispatch-detail-page .dd-release-modal-content {', '.dispatch-detail-page .dd-asset-intro');
    assert.match(shell, /max-height: 90vh/); assert.match(shell, /max-height: 90dvh/);
    assert.match(shell, /display: flex/); assert.match(shell, /flex-direction: column/); assert.match(shell, /overflow: hidden/);
    assert.match(shell, /\.modal-header,[\s\S]*?\.modal-footer \{\s*flex: 0 0 auto/);
    const scroll = cut('.dispatch-detail-page .dd-asset-lines {', '.dispatch-detail-page .dd-asset-line {');
    assert.match(scroll, /max-height: min\(42vh, 360px\)/); assert.match(scroll, /overflow-y: auto/);
    assert.match(scroll, /:focus-visible/); assert.match(scroll, /scroll-padding: 6px/);
    assert.match(page, /id="dd-release-assets"[^>]*role="region"[^>]*tabindex="0"><\/div>\s*<label for="dd-release-remarks"/);
    assert.match(page, /minmax\(min\(220px, 100%\), 1fr\)/);
});

test('entire inline page script compiles after substituting server-rendered constants', () => {
    const script = page.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, '0');
    new vm.Script(script);
});
