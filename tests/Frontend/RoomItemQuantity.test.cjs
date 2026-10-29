// Executes trusted room-card renderers with DOM doubles; no HTTP or database use.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const page = fs.readFileSync(path.join(root, 'public/frontend/pages/buildings-overview.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/frontend/assets/css/buildings-redesign.css'), 'utf8');
const source = page.slice(page.indexOf('function createItemQuantityRow('), page.indexOf('function createEntityCard('));

function harness() {
    const deleted = [];
    const node = tag => ({ tag, className: '', textContent: '', dataset: {}, children: [], attrs: {},
        appendChild(child) { this.children.push(child); return child; },
        setAttribute(name, value) { this.attrs[name] = value; },
        classList: { add() {} },
    });
    const context = vm.createContext({ document: { createElement: node }, window: { UIIcons: { svg: () => '<svg></svg>' } },
        getItemIcon: () => 'package', deleteEntity: (...args) => deleted.push(args) });
    new vm.Script(source).runInContext(context);
    return { context, deleted };
}
const all = card => [card, ...card.children.flatMap(all)];
const find = (card, className) => all(card).find(n => n.className.split(' ').includes(className));
const badge = card => find(card, 'item-quantity-badge').textContent;

for (const [quantity, expected] of [[0, '0 pcs'], [1, '1 pc'], [10, '10 pcs'], ['0', '0 pcs'], ['1', '1 pc'], ['25', '25 pcs']]) {
    for (const renderer of ['createItemCard', 'createDeployedItemCard']) test(renderer + ' displays actual quantity ' + JSON.stringify(quantity), () => {
        const { context } = harness();
        const card = context[renderer]({ id: 9, name: 'Chair', item_name: 'Chair', quantity, asset_code: 'EXISTING-09' });
        assert.equal(badge(card), expected);
        const row = find(card, 'item-quantity-row'); assert.equal(row.children[0].textContent, 'Quantity');
        assert.ok(all(card).some(n => n.textContent === 'EXISTING-09'));
        assert.equal(find(card, 'item-asset-card-name').textContent, 'Chair');
        assert.ok(find(card, 'item-asset-card-icon'));
        assert.equal(all(card).filter(n => n.className === 'item-quantity-row').length, 1);
    });
}

for (const quantity of [undefined, null, '', ' ', 'invalid', -1, 1.5, false, [], {}, Infinity, NaN, '1e2', '9007199254740992']) {
    test('invalid or missing quantity is visibly unavailable: ' + String(quantity), () => {
        const { context } = harness();
        assert.equal(badge(context.createItemCard({ id: 2, name: 'Chair', quantity })), 'Not available');
        assert.equal(badge(context.createDeployedItemCard({ item_name: 'Chair', quantity })), 'Not available');
    });
}

test('each same-name record retains its own ID and quantity and re-render uses refreshed API values', () => {
    const { context } = harness();
    const first = { id: 1, name: 'Chair', quantity: 2 }, second = { id: 2, name: 'Chair', quantity: 9 };
    const a = context.createItemCard(first), b = context.createItemCard(second);
    assert.equal(a.dataset.id, 1); assert.equal(b.dataset.id, 2);
    assert.equal(badge(a), '2 pcs'); assert.equal(badge(b), '9 pcs');
    first.quantity = 0; assert.equal(badge(context.createItemCard(first)), '0 pcs');
});

test('existing delete action retains its item identity and propagation behavior; dispatch cards remain read-only', () => {
    const { context, deleted } = harness(); const card = context.createItemCard({ id: 42, name: 'Chair', quantity: 10 });
    const button = find(card, 'card-delete'); let stopped = false;
    assert.equal(button.tag, 'button'); assert.equal(button.attrs['aria-label'], 'Delete');
    button.onclick({ stopPropagation() { stopped = true; } });
    assert.equal(stopped, true); assert.deepEqual(deleted, [['item', 42]]);
    assert.equal(find(context.createDeployedItemCard({ quantity: 10 }), 'card-delete'), undefined);
});

test('existing asset-code placeholder and dispatch details remain available', () => {
    const { context } = harness(); const card = context.createItemCard({ id: 1, quantity: 0, asset_code: null });
    assert.ok(all(card).some(n => n.textContent === 'Asset Code'));
    assert.ok(all(card).some(n => n.textContent === 'Not specified'));
    const dispatched = context.createDeployedItemCard({ quantity: 2, asset_code: 'SFMS-EXAMPLE', dispatch_code: 'DSP-EXAMPLE', date_dispatched: '2026-10-29' });
    for (const value of ['SFMS-EXAMPLE', 'DSP-EXAMPLE', 'Date Deployed']) assert.ok(all(dispatched).some(n => n.textContent === value));
});

test('existing API units are displayed as text and malicious quantity never becomes markup', () => {
    const { context } = harness();
    assert.equal(badge(context.createItemCard({ quantity: 3, unit_type: 'sets' })), '3 sets');
    assert.equal(badge(context.createDeployedItemCard({ quantity: 4, unit: 'units' })), '4 units');
    const hostile = '<img src=x onerror="executed=true">';
    assert.equal(badge(context.createItemCard({ quantity: hostile })), 'Not available');
    assert.equal(badge(context.createItemCard({ quantity: 2, unit_type: hostile })), '2 ' + hostile);
    assert.equal(context.executed, undefined);
    assert.doesNotMatch(source.slice(0, source.indexOf('function createDeployedItemCard(')), /innerHTML/);
});

test('quantity is the final content row, with responsive violet badge styling and valid page JavaScript', () => {
    const { context } = harness();
    for (const renderer of ['createItemCard', 'createDeployedItemCard']) {
        const card = context[renderer]({ quantity: 10 });
        const content = card.children.filter(n => n.tag !== 'button');
        assert.equal(content.at(-1).className, 'item-quantity-row');
    }
    assert.match(css, /\.item-quantity-row\s*\{[^}]*justify-content: space-between;[^}]*flex-wrap: wrap;/s);
    assert.match(css, /\.item-quantity-badge\s*\{[^}]*margin-left: auto;[^}]*max-width: 100%;[^}]*background: #f3e8ff;[^}]*color: #6d28d9;/s);
    assert.match(css, /\.item-quantity-badge\s*\{[^}]*overflow-wrap: anywhere;/s);
    new vm.Script(page.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, 'false'));
});
