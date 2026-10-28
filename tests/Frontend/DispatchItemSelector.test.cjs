// Offline page execution with DOM/fetch doubles. No browser, HTTP, or database access.
// Run: node --test tests/Frontend/DispatchItemSelector.test.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/dispatch-create.php'), 'utf8');
const source = page.match(/<script>\s*([\s\S]*?)<\/script>/)[1]
    .replace("<?php echo $_dcIsAdmin ? 'true' : 'false'; ?>", 'false');
const inventory = [
    { id: 71, name: 'Aircon Filter', quantity: 12, reserved_quantity: 2 },
    { id: 92, name: 'Cable & Switch', quantity: 8, reserved_quantity: 0 },
    { id: 103, name: 'Empty Cartridge', quantity: 2, reserved_quantity: 2 },
    { id: 118, name: 'Zero Stock', quantity: 0, reserved_quantity: 0 },
    { id: 150, name: '<img src=x onerror=alert(1)> "Tool" & O\'Neil', quantity: 4, reserved_quantity: 0 },
];

function harness(items = inventory, pages = null) {
    const nodes = new Map(), requests = [], documentEvents = {};
    let document;
    class Element {
        constructor(tag = 'div') {
            this.tagName = tag.toUpperCase(); this.children = []; this.events = {}; this.attrs = {};
            this.style = {}; this.className = ''; this.textContent = ''; this.hidden = false;
            this.disabled = false; this._value = ''; this.id = ''; this.parentNode = null;
        }
        get value() { return this._value; }
        set value(value) { this._value = String(value); }
        setAttribute(key, value) { this.attrs[key] = String(value); if (key === 'max') this.max = String(value); }
        removeAttribute(key) { delete this.attrs[key]; if (key === 'max') this.max = ''; }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        replaceChildren(...children) { this.children = []; children.forEach((child) => this.appendChild(child)); }
        remove() { this.parentNode.children = this.parentNode.children.filter((child) => child !== this); this.parentNode = null; }
        contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
        all() { return this.children.flatMap((child) => [child, ...child.all()]); }
        querySelectorAll(selector) { return this.all().filter((child) => child.className.split(' ').includes(selector.slice(1))); }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
        dispatchEvent(event) { for (const fn of this.events[event.type] || []) fn(event); }
        async emit(type, properties = {}) {
            const event = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...properties };
            if (type !== 'click' || !this.disabled) for (const fn of this.events[type] || []) await fn(event);
            return event;
        }
        focus() { if (document.activeElement !== this) { document.activeElement = this; this.dispatchEvent({ type: 'focus' }); } }
        scrollIntoView() {}
    }
    for (const id of ['dc-items-rows', 'dc-form', 'dc-form-error', 'dc-room-id', 'dc-submit-btn', 'dc-items-loading', 'dc-add-row']) {
        const node = new Element(); node.id = id; nodes.set(id, node);
    }
    const all = () => [...nodes.values()].flatMap((node) => [node, ...node.all()]);
    document = {
        activeElement: null, createElement: (tag) => new Element(tag),
        getElementById: (id) => all().find((node) => node.id === id) || null,
        querySelectorAll: (selector) => all().filter((node) => node.className.split(' ').includes(selector.slice(1))),
        addEventListener: (name, fn) => { (documentEvents[name] ||= []).push(fn); },
    };
    const context = vm.createContext({
        document, Event: class { constructor(type) { this.type = type; } },
        window: { location: { search: '', href: '' }, SFMS_PUBLIC_URL: (url) => url }, URLSearchParams,
        fetch: async (url, options = {}) => {
            requests.push({ url, ...options });
            const data = url.startsWith('/api/items')
                ? (pages ? pages[new URL(url, 'http://offline.test').searchParams.get('page') - 1] : { data: items, last_page: 1 })
                : { dispatch_id: 999 };
            return { ok: true, json: async () => ({ success: true, data }) };
        },
    });
    new vm.Script(source + '\nglobalThis.dcTest = { setItems(items) { dcItems = items; dcPrepareItemChoices(); }, roster() { dcSelectedPersonnel.add(7); }, departments() { dcSelectedDepartments.add(3); } };').runInContext(context);
    context.dcTest.setItems(items);
    const rows = () => document.querySelectorAll('.dc-item-row');
    const add = () => { context.addItemRow(); return rows().at(-1); };
    const search = async (row, value) => { const input = row.querySelector('.dc-item-search'); input.value = value; await input.emit('input'); };
    const options = (row) => row.querySelectorAll('.dc-item-option');
    const pick = async (row, name) => { row.querySelector('.dc-item-search').focus(); const option = options(row).find((node) => node.children[0].textContent === name); assert.ok(option, name); await option.emit('click'); };
    return { context, nodes, document, requests, rows, add, search, options, pick, documentEvents };
}

test('search filters by item name case-insensitively while empty search shows stock and out-of-stock options', async () => {
    const h = harness(), row = h.add();
    await h.search(row, 'aIrCoN');
    assert.deepEqual(h.options(row).map((node) => node.children[0].textContent), ['Aircon Filter']);
    await h.search(row, '');
    assert.equal(h.options(row).length, inventory.length);
    const empty = h.options(row).find((node) => node.children[0].textContent === 'Empty Cartridge');
    assert.equal(empty.disabled, true); assert.equal(empty.attrs['aria-disabled'], 'true');
    assert.equal(empty.children[1].textContent, 'Out of stock — Available: 0');
    const stocked = h.options(row).find((node) => node.children[0].textContent === 'Aircon Filter');
    assert.equal(stocked.children[1].textContent, 'Available: 10');
    await empty.emit('click'); assert.equal(row.querySelector('.dc-item-select').value, '');
});

test('no matches has clear text and Enter never submits the form', async () => {
    const h = harness(), row = h.add(); await h.search(row, 'not found');
    assert.equal(row.querySelector('.dc-item-empty').textContent, 'No matching items found');
    const event = await row.querySelector('.dc-item-search').emit('keydown', { key: 'Enter' });
    assert.equal(event.defaultPrevented, true); assert.equal(h.requests.length, 0);
});

test('selection stores original ID, displays name/available stock and preserves quantity', async () => {
    const h = harness(), row = h.add(); row.querySelector('.dc-qty-input').value = '3';
    await h.pick(row, 'Aircon Filter');
    assert.equal(row.querySelector('.dc-item-select').value, '71');
    assert.equal(row.querySelector('.dc-item-search').value, 'Aircon Filter');
    assert.equal(row.querySelector('.dc-qty-input').value, '3');
    assert.equal(row.querySelector('.dc-qty-input').max, '10');
    assert.equal(row.querySelector('.dc-qty-available').textContent, 'Available: 10');
    assert.equal(row.querySelector('.dc-item-results').hidden, true);
});

test('changing selection clears stale ID, retains quantity and clamps it to new availability', async () => {
    const h = harness(), row = h.add(); await h.pick(row, 'Aircon Filter');
    row.querySelector('.dc-qty-input').value = '9'; await h.search(row, 'Cable');
    assert.equal(row.querySelector('.dc-item-select').value, '');
    assert.equal(row.querySelector('.dc-qty-input').value, '9');
    assert.equal(row.querySelector('.dc-qty-input').max, '');
    await h.options(row)[0].emit('click');
    assert.equal(row.querySelector('.dc-item-select').value, '92');
    assert.equal(row.querySelector('.dc-qty-input').value, '8');
});

test('multiple rows have independent selection and removing one preserves the other and last-row protection', async () => {
    const h = harness(), first = h.add(), second = h.add();
    await h.pick(first, 'Aircon Filter'); await h.pick(second, 'Cable & Switch');
    second.querySelector('.dc-qty-input').value = '4';
    assert.notEqual(first.querySelector('.dc-item-results').id, second.querySelector('.dc-item-results').id);
    await first.children.at(-1).emit('click'); assert.equal(h.rows().length, 1);
    assert.equal(second.querySelector('.dc-item-select').value, '92');
    assert.equal(second.querySelector('.dc-qty-input').value, '4');
    await second.children.at(-1).emit('click'); assert.equal(h.rows().length, 1);
});

test('Arrow keys navigate enabled results, Enter selects and Escape closes', async () => {
    const h = harness(), row = h.add(); await h.search(row, '');
    const input = row.querySelector('.dc-item-search');
    await input.emit('keydown', { key: 'ArrowDown' });
    const first = input.attrs['aria-activedescendant']; assert.ok(first);
    await input.emit('keydown', { key: 'ArrowDown' }); assert.notEqual(input.attrs['aria-activedescendant'], first);
    await input.emit('keydown', { key: 'ArrowUp' }); assert.equal(input.attrs['aria-activedescendant'], first);
    const active = h.document.getElementById(first); assert.equal(active.disabled, false);
    const selectedName = active.children[0].textContent;
    const event = await input.emit('keydown', { key: 'Enter' }); assert.equal(event.defaultPrevented, true);
    assert.equal(input.value, selectedName); assert.notEqual(row.querySelector('.dc-item-select').value, '');
    await input.emit('click'); await input.emit('keydown', { key: 'Escape' });
    assert.equal(input.attrs['aria-expanded'], 'false');
});

test('keyboard cannot select out-of-stock-only results', async () => {
    const h = harness(), row = h.add(); await h.search(row, 'Empty');
    const input = row.querySelector('.dc-item-search');
    await input.emit('keydown', { key: 'ArrowDown' }); await input.emit('keydown', { key: 'Enter' });
    assert.equal(row.querySelector('.dc-item-select').value, '');
});

test('item names with HTML-sensitive characters are plain text, never HTML markup', async () => {
    const h = harness(), row = h.add(); await h.search(row, '<img');
    assert.equal(h.options(row)[0].children[0].textContent, inventory[4].name);
    await h.options(row)[0].emit('click'); assert.equal(row.querySelector('.dc-item-search').value, inventory[4].name);
    assert.equal(row.querySelector('.dc-item-select').value, '150');
    const pickerSource = source.slice(source.indexOf('function dcBuildItemPicker('), source.indexOf('function addItemRow('));
    assert.ok(!pickerSource.includes('innerHTML'));
});

test('opening another row or clicking outside closes only the dropdown without changing selections', async () => {
    const h = harness(), first = h.add(), second = h.add(); await h.pick(first, 'Aircon Filter');
    await first.querySelector('.dc-item-search').emit('click'); second.querySelector('.dc-item-search').focus();
    assert.equal(first.querySelector('.dc-item-results').hidden, true);
    for (const fn of h.documentEvents.pointerdown) fn({ target: h.nodes.get('dc-form') });
    assert.equal(second.querySelector('.dc-item-results').hidden, true);
    assert.equal(first.querySelector('.dc-item-select').value, '71');
});

test('large inventory renders 100 results initially, supports more, and searches all cached records', async () => {
    const many = Array.from({ length: 1000 }, (_, i) => ({ id: i + 1, name: 'Item ' + String(i).padStart(4, '0'), quantity: 3 }));
    const h = harness(many), row = h.add(); await h.search(row, ''); assert.equal(h.options(row).length, 100);
    await row.querySelector('.dc-item-more').emit('click'); assert.equal(h.options(row).length, 200);
    await h.search(row, 'Item 0999'); assert.equal(h.options(row).length, 1);
    await h.options(row)[0].emit('click'); assert.equal(row.querySelector('.dc-item-select').value, '1000');
});

test('existing API pagination includes items beyond page one', async () => {
    const h = harness([], [{ data: inventory.slice(0, 2), last_page: 2 }, { data: inventory.slice(2), last_page: 2 }]);
    await h.context.loadItems(); assert.equal(h.requests.length, 2); assert.match(h.requests[1].url, /page=2$/);
    const row = h.add(); await h.search(row, ''); assert.equal(h.options(row).length, inventory.length);
});

test('original dispatch payload contains selected item IDs and quantities unchanged', async () => {
    const h = harness(), first = h.add(), second = h.add();
    await h.pick(first, 'Aircon Filter'); await h.pick(second, 'Cable & Switch');
    first.querySelector('.dc-qty-input').value = '3'; second.querySelector('.dc-qty-input').value = '2';
    h.nodes.get('dc-room-id').value = '6'; h.context.dcTest.roster(); h.context.dcTest.departments();
    await h.nodes.get('dc-form').emit('submit'); assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/api/dispatches'); assert.equal(h.requests[0].method, 'POST');
    assert.deepEqual(JSON.parse(h.requests[0].body), { department_ids: [3], room_id: 6, personnel_ids: [7], items: [{ item_id: 71, quantity: 3 }, { item_id: 92, quantity: 2 }] });
});

test('existing duplicate-item rejection remains before submission', async () => {
    const h = harness(), first = h.add(), second = h.add();
    await h.pick(first, 'Aircon Filter'); await h.pick(second, 'Aircon Filter');
    h.nodes.get('dc-room-id').value = '6'; h.context.dcTest.roster();
    await h.nodes.get('dc-form').emit('submit'); assert.equal(h.requests.length, 0);
    assert.match(h.nodes.get('dc-form-error').textContent, /Each item can only appear once/);
});

test('required item/quantity and over-stock checks still prevent submission', async () => {
    const h = harness(), row = h.add(); h.nodes.get('dc-room-id').value = '6'; h.context.dcTest.roster();
    await h.nodes.get('dc-form').emit('submit'); assert.match(h.nodes.get('dc-form-error').textContent, /At least one item/);
    await h.pick(row, 'Aircon Filter'); row.querySelector('.dc-qty-input').value = '0';
    await h.nodes.get('dc-form').emit('submit'); assert.match(h.nodes.get('dc-form-error').textContent, /At least one item/);
    row.querySelector('.dc-qty-input').value = '11'; await h.nodes.get('dc-form').emit('submit');
    assert.match(h.nodes.get('dc-form-error').textContent, /Only 10 Aircon Filter available/); assert.equal(h.requests.length, 0);
});

test('selector styles bound scrolling, wrap names and preserve the existing mobile grid', () => {
    assert.match(page, /max-height: 240px; overflow-y: auto/);
    assert.match(page, /overflow-wrap: anywhere/);
    assert.match(page, /\.dc-item-picker \{ position: relative; min-width: 0; \}/);
    new vm.Script(source); // Complete inline script syntax, not just extracted picker functions.
});
