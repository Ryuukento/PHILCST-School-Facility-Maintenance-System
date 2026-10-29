// Real page functions executed with offline DOM/API doubles. No browser or live requests.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/buildings-overview.php'), 'utf8');
const css = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/assets/css/buildings-redesign.css'), 'utf8');
const component = page.slice(page.indexOf('const deployedOverview ='), page.indexOf('// Drill-down floor cache:'));
const loader = page.slice(page.indexOf('async function loadDeployedItemsSummary()'), page.indexOf('function renderBuildingStats('));
const item = (overrides = {}) => ({ item_name: 'Chair', category: 'Furniture', quantity: 3, unit: 'pc',
    building_id: 1, building_name: 'Building A', floor_id: 10, floor_name: 'Floor 1', room_id: 100, room_name: 'Room A',
    source_type: 'dispatch', source_record_id: 1, item_id: 7, dispatch_id: 1, ...overrides });
function response(items) { return { ok: true, json: async () => ({ success: true, data: { items } }) }; }
function harness({ admin = true, items = [item()], fetcher = async () => response(items) } = {}) {
    const nodes = new Map(), requests = [];
    const document = { activeElement: null };
    function node(tag = 'div') {
        let ownText = '';
        const n = { tag, children: [], attrs: {}, style: {}, dataset: {}, value: '', disabled: false, hidden: false, listeners: {},
            appendChild(child) { this.children.push(child); child.parent = this; return child; },
            replaceChildren(...children) { this.children = []; ownText = ''; children.forEach(child => this.appendChild(child)); },
            setAttribute(key, value) { this.attrs[key] = String(value); },
            addEventListener(event, fn) { this.listeners[event] = fn; },
            focus() { document.activeElement = this; },
            closest() { let p = this; while (p) { if (p.hidden) return p; p = p.parent; } return null; },
            querySelector(selector) {
                for (const child of this.children) { if (child.tag === selector) return child; const found = child.querySelector(selector); if (found) return found; }
                return null;
            },
        };
        Object.defineProperty(n, 'textContent', { get: () => ownText + n.children.map(child => child.textContent).join(''), set: value => { ownText = String(value); n.children = []; } });
        Object.defineProperty(n, 'firstChild', { get: () => n.children[0] });
        Object.defineProperty(n, 'innerHTML', { set() { throw new Error('Unsafe HTML rendering'); } });
        return n;
    }
    const get = id => { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); };
    document.body = node('body'); document.body.style.overflow = '';
    document.createElement = node; document.getElementById = get;
    get('deployedOverviewModal').hidden = true;
    get('deployedOverviewModal').querySelectorAll = () => [get('deoClose'), get('deoSearch')];
    const context = vm.createContext({ document, canModifyBuildings: admin, buildingItemCounts: {},
        window: { SFMS_PUBLIC_URL: url => '/School_Facility_Maintenance_System' + url, addEventListener() {} },
        extractList: payload => payload.data.items, console: { error() {} },
        fetch: async (url, options) => { requests.push({ url, options }); return fetcher(); },
    });
    vm.runInContext(component + loader, context);
    context.initDeployedOverview();
    return { context, document, get, requests, state: () => vm.runInContext('deployedOverview', context),
        load: () => context.loadDeployedItemsSummary(), open: () => context.openDeployedOverview(),
        click: id => get(id).listeners.click(), change: (id, value) => { get(id).value = value; return get(id).listeners.change({ target: get(id) }); },
        search: value => { get('deoSearch').value = value; get('deoSearch').listeners.input({ target: get('deoSearch') }); } };
}
test('card, modal and filtered quantities use one complete response snapshot', async () => {
    const h = harness({ items: [item({ quantity: 5 }), item({ source_type: 'direct', source_record_id: 9, quantity: 7 })] });
    await h.load(); h.open();
    assert.equal(h.requests.length, 1); assert.equal(h.get('statDeployedItems').textContent, '12');
    assert.equal(h.get('deoTotal').textContent, '12'); assert.equal(h.get('deoFilteredTotal').textContent, '12');
    assert.equal(h.get('deoRows').children.length, 2);
    assert.equal(h.requests[0].url, '/School_Facility_Maintenance_System/api/buildings/deployed-items');
    assert.ok(h.get('deoRows').textContent.includes('Direct Room Item'));
});
test('rankings sum quantities, preserve separate units and use deterministic ties', () => {
    const h = harness();
    const data = [item({ item_name: ' PC ', quantity: 3 }), item({ item_name: 'pc', quantity: 2, unit: 'pcs' }),
        item({ item_name: 'PC', quantity: 6, unit: 'box' }), item({ item_name: 'PC', quantity: 4, unit: 'set' }),
        item({ item_name: 'TV', quantity: 5 }), item({ item_name: 'Fan', quantity: 1 })];
    const ranked = h.context.deoTopFive(data);
    assert.equal(ranked.length, 5); assert.equal(ranked[0].unit, 'box'); assert.equal(ranked[0].quantity, 6);
    assert.equal(ranked.find(r => r.unit === 'pc' && r.name.trim().toLowerCase() === 'pc').quantity, 5);
    assert.equal(ranked.find(r => r.unit === 'set').quantity, 4);
    assert.equal(ranked.map(r => r.key).join('|'), h.context.deoTopFive([...data].reverse()).map(r => r.key).join('|'));
});
test('cascade filters use returned IDs and reset dependent selections', async () => {
    const h = harness({ items: [item(), item({ building_id: 2, building_name: 'B', floor_id: 20, room_id: 200 })] });
    await h.load(); h.open(); h.change('deoBuilding', '1'); h.change('deoFloor', '10'); h.change('deoRoom', '100');
    assert.equal(h.get('deoFilteredTotal').textContent, '3');
    h.change('deoBuilding', '2');
    assert.equal(h.state().floor, ''); assert.equal(h.state().room, '');
    assert.equal(h.get('deoFloor').children.map(n => n.value).join(','), ',20');
    h.change('deoFloor', '20'); assert.equal(h.state().room, '');
});
test('case-insensitive search and empty state do not change the global total', async () => {
    const h = harness({ items: [item({ item_name: 'USB Cable', quantity: 4 }), item({ item_name: 'Chair', quantity: 2 })] });
    await h.load(); h.open(); h.search('uSb');
    assert.equal(h.get('deoFilteredTotal').textContent, '4'); assert.equal(h.get('deoTotal').textContent, '6');
    h.search('no match'); assert.equal(h.get('deoFilteredTotal').textContent, '0'); assert.match(h.get('deoRows').textContent, /No recorded deployments/);
    h.search(''); assert.equal(h.get('deoFilteredTotal').textContent, '6');
});
test('missing and inactive locations stay counted and missing locations are visible', async () => {
    const h = harness({ items: [item({ quantity: 2, is_active: false }), item({ source_record_id: 2, quantity: 5,
        building_id: null, building_name: null, floor_id: null, floor_name: null, room_id: null, room_name: null })] });
    await h.load(); h.open(); assert.equal(h.get('deoTotal').textContent, '7');
    assert.match(h.get('deoRows').textContent, /Unspecified/);
    h.change('deoBuilding', '__unspecified__'); assert.equal(h.get('deoFilteredTotal').textContent, '5');
});
test('zero and missing quantities never become a fabricated quantity of one', async () => {
    const h = harness({ items: [item({ quantity: 0 }), item({ source_record_id: 2, quantity: null })] });
    await h.load(); h.open(); assert.equal(h.get('deoTotal').textContent, '0');
});
test('pagination only slices table rendering; totals/rankings include every matching record', async () => {
    const h = harness({ items: Array.from({ length: 45 }, (_, i) => item({ source_record_id: i + 1, quantity: 2 })) });
    await h.load(); h.open(); assert.equal(h.get('deoRows').children.length, 20); assert.equal(h.get('deoTotal').textContent, '90');
    assert.match(h.get('deoPageInfo').textContent, /45 records.*Page 1 of 3/);
    h.click('deoNext'); assert.equal(h.state().page, 2); h.click('deoNext'); assert.equal(h.get('deoRows').children.length, 5);
    assert.equal(h.requests.length, 1); h.click('deoPrevious'); assert.equal(h.state().page, 2);
});
test('View Locations uses item ID, not matching names, and Back preserves filters/page', async () => {
    const rows = Array.from({ length: 25 }, (_, i) => item({ source_record_id: i + 1, item_id: i === 22 ? 99 : 7 }));
    rows[24] = item({ source_record_id: 25, item_id: 7, room_id: 200, room_name: 'Room B' });
    const h = harness({ items: rows }); await h.load(); h.open(); h.search('Chair'); h.click('deoNext');
    h.context.deoShowLocations('dispatch:23'); assert.equal(h.get('deoLocationRows').children.length, 1);
    assert.equal(h.get('deoTableSection').hidden, true); h.click('deoBack');
    assert.equal(h.state().search, 'Chair'); assert.equal(h.state().page, 2);
    h.context.deoShowLocations('dispatch:25'); assert.equal(h.get('deoLocationRows').children.length, 24);
});
test('error does not pretend to be zero; Retry reads the same endpoint and restores totals', async () => {
    let fail = true;
    const h = harness({ fetcher: async () => { if (fail) throw new Error('Offline'); return response([item()]); } });
    await h.load(); h.open(); assert.equal(h.get('statDeployedItems').textContent, 'Unavailable');
    assert.equal(h.get('deoRetry').hidden, false); assert.match(h.get('deoStatus').textContent, /Unable to load/);
    fail = false; await h.click('deoRetry'); assert.equal(h.get('deoTotal').textContent, '3'); assert.equal(h.get('statDeployedItems').textContent, '3');
});
test('loading state and stale-response guard prevent an older result overwriting newer totals', async () => {
    const resolvers = [];
    const h = harness({ fetcher: () => new Promise(resolve => resolvers.push(resolve)) });
    const first = h.load(); h.open(); assert.match(h.get('deoStatus').textContent, /Loading/);
    assert.equal(h.get('deoStatus').attrs['aria-busy'], 'true');
    const second = h.load(); resolvers[1](response([item({ quantity: 8 })])); await second;
    resolvers[0](response([item({ quantity: 99 })])); assert.equal((await first).stale, true);
    assert.equal(h.get('statDeployedItems').textContent, '8'); assert.equal(h.get('deoTotal').textContent, '8');
});
test('malformed API success is an error, not a misleading empty dataset', async () => {
    const h = harness({ fetcher: async () => ({ ok: true, json: async () => ({ success: true, data: {} }) }) });
    await h.load(); assert.equal(h.get('statDeployedItems').textContent, 'Unavailable');
});
test('non-Administrator card remains non-interactive and programmatic opening is denied', () => {
    const h = harness({ admin: false }); h.open();
    assert.equal(h.state().open, false); assert.equal(h.get('deployedOverviewCard').listeners.click, undefined);
    assert.match(page, /super_admin'[\s\S]*?role="button" tabindex="0"/);
});
test('Enter/Space open, Escape closes and focus returns without changing outer navigation', async () => {
    const h = harness(); await h.load();
    for (const key of ['Enter', ' ']) {
        let prevented = false;
        h.get('deployedOverviewCard').listeners.keydown({ key, preventDefault() { prevented = true; } });
        assert.equal(prevented, true); assert.equal(h.document.activeElement, h.get('deoClose'));
        assert.equal(h.get('deployedOverviewCard').attrs['aria-expanded'], 'true');
        h.get('deployedOverviewModal').listeners.keydown({ key: 'Escape', preventDefault() {} });
        assert.equal(h.get('deployedOverviewModal').hidden, true); assert.equal(h.document.activeElement, h.get('deployedOverviewCard'));
        assert.equal(h.document.body.style.overflow, '');
    }
    assert.ok(!component.includes('currentBuildingId =')); assert.ok(!component.includes('buildingsSearchTerm ='));
});
test('Tab and Shift+Tab stay within the dialog', async () => {
    const h = harness(); await h.load(); h.open(); let trapped = 0;
    h.get('deoSearch').focus(); h.get('deployedOverviewModal').listeners.keydown({ key: 'Tab', shiftKey: false, preventDefault() { trapped++; } });
    assert.equal(h.document.activeElement, h.get('deoClose'));
    h.get('deployedOverviewModal').listeners.keydown({ key: 'Tab', shiftKey: true, preventDefault() { trapped++; } });
    assert.equal(h.document.activeElement, h.get('deoSearch')); assert.equal(trapped, 2);
});
test('untrusted item/category/location text never uses innerHTML', async () => {
    const text = '<img src=x onerror=alert(1)>';
    const h = harness({ items: [item({ item_name: text, category: text, building_name: text, floor_name: text, room_name: text })] });
    await h.load(); h.open(); h.context.deoShowLocations('dispatch:1');
    assert.ok(h.get('deoRows').textContent.includes(text)); assert.ok(h.get('deoLocationRows').textContent.includes(text));
    assert.ok(!component.includes('innerHTML'));
});
test('compact ranking separates left-aligned names from right-aligned quantities without changing totals', async () => {
    const h = harness({ items: [item({ quantity: 12 })] }); await h.load(); h.open();
    const rank = h.get('deoRanking').children[0], label = rank.children[0];
    assert.equal(label.className, 'deo-rank-label');
    assert.equal(label.children[0].className, 'deo-rank-name');
    assert.equal(label.children[0].textContent, 'Chair');
    assert.equal(label.children[1].className, 'deo-rank-amount');
    assert.equal(label.children[1].children[0].textContent, '12');
    assert.equal(label.children[1].children[1].textContent, 'pc');
    assert.equal(h.get('deoTotal').textContent, '12');
    assert.match(css, /\.deo-bar-track\s*\{[^}]*height: 4px/);
    assert.match(css, /\.deo-ranking-panel\s*\{[^}]*max-width: 720px/);
    assert.match(css, /\.deo-rank-label\s*\{[^}]*justify-content: space-between/);
});
test('missing units have a truthful display label while ranking keys and quantities stay unchanged', async () => {
    const rows = [null, '', '   '].map((unit, i) => item({ unit, source_record_id: i + 1 }));
    const h = harness({ items: rows }); await h.load(); h.open();
    assert.equal(h.get('deoTotal').textContent, '9');
    for (const row of h.get('deoRows').children) assert.equal(row.children[3].textContent, 'Unit not recorded');
    const ranked = h.context.deoTopFive(rows);
    assert.equal(ranked.length, 1); assert.equal(ranked[0].unit, 'Unspecified'); assert.equal(ranked[0].quantity, 9);
    assert.match(h.get('deoRanking').textContent, /Unit not recorded/);
    h.context.deoShowLocations('dispatch:1');
    for (const location of h.get('deoLocationRows').children) assert.match(location.textContent, /Unit not recorded/);
});
test('table retains nine columns, sticky headers and non-clipped actions; explanations stay accessible', () => {
    const cols = page.match(/<colgroup>([\s\S]*?)<\/colgroup>/)[1];
    assert.equal([...cols.matchAll(/<col /g)].length, 9);
    assert.equal([...page.matchAll(/<th scope="col">/g)].length, 9);
    assert.match(css, /\.deo-table\s*\{[^}]*min-width: 1220px[^}]*table-layout: fixed/);
    assert.match(css, /\.deo-table th\s*\{[^}]*position: sticky[^}]*top: 0/);
    assert.match(css, /\.deo-table td:last-child \.btn\s*\{[^}]*white-space: nowrap/);
    assert.match(page, /<details class="deo-notes"><summary>About these quantities<\/summary>/);
    assert.ok(component.includes('input, select, summary, [tabindex="0"]'));
    assert.match(css, /\.deo-header\s*\{[^}]*flex-shrink: 0/);
});
test('modal is viewport-bounded/responsive; full page JavaScript compiles', () => {
    assert.match(css, /\.deo-dialog[^}]*max-height: 92dvh/);
    assert.match(css, /\.deo-scroll[^}]*overflow-y: auto/);
    assert.match(css, /\.deo-table-wrap[^}]*overflow-x: auto/);
    assert.match(css, /@media \(max-width: 420px\)/);
    assert.ok(page.includes('Includes items recorded directly in rooms and legacy room records.') || page.includes('includes items recorded directly in rooms and legacy room records.'));
    const source = page.replace(/<\?php[\s\S]*?\?>/g, 'null');
    for (const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) new vm.Script(match[1]);
});
