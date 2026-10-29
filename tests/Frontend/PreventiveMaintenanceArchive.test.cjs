// Offline execution of page functions with DOM/fetch doubles; no browser or live API.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/preventive-maintenance.php'), 'utf8');
function cut(start, end) {
    const i = page.indexOf(start), j = page.indexOf(end, i);
    assert.ok(i >= 0 && j > i, start);
    return page.slice(i, j);
}
const functions = cut('function pmEscapeHtml(', 'function pmNotify(')
    + cut('function pmRenderPlansList()', 'function pmRenderOverview()')
    + cut('function pmScheduleActionsCell(', '// Manual legend codes')
    + cut('async function pmToggleActive(', '// ---------------------------------------------------------------------\n// Print view')
    + cut('function pmHistoryRowMarkup(', 'function pmResultBadge(').replace(/<\?php[\s\S]*?\?>/g, '/offline');
const active = { id: 1, title: 'Active plan', is_active: true, frequency_label: 'Monthly' };
const archived = { id: 2, title: 'Archived plan', is_active: false, frequency_label: 'Monthly' };
function harness({ admin = true, confirm = true, total = 23, rows = [archived], getFails = false } = {}) {
    const nodes = new Map(), requests = [], confirmations = [], errors = [], refreshes = [];
    const get = (id) => {
        if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', hidden: false, disabled: false,
            attrs: {}, classList: { toggle() {} }, setAttribute(key, value) { this.attrs[key] = value; } });
        return nodes.get(id);
    };
    const button = { disabled: false };
    let resolvePost, rejectPost;
    const context = vm.createContext({
        document: { getElementById: get, querySelectorAll: () => [button] },
        PM_API_BASE: '/api/preventive-maintenance', PM_CAN_ARCHIVE: admin, PM_CAN_ASSIGN: true,
        pmCanManageTask: () => true, pmCanCompleteTask: () => true,
        pmFormatDate: (value) => String(value), pmMutedDash: () => '—', pmStatusBadge: () => 'Status',
        pmResultBadge: () => 'Result', pmHistoryRow: null,
        pmGetLoadingMarkup: (label) => 'LOADING: ' + label,
        pmLoadScheduleGrid: async () => refreshes.push('active'), pmLoadSummary: async () => refreshes.push('summary'), pmLoadChecklist() {},
        pmNotify: (error) => errors.push(error),
        Components: {
            toast() {}, confirm: async (...args) => { confirmations.push(args); return confirm; },
            fetchJson: async (url, options) => {
                requests.push({ url, ...options });
                if (options.method === 'POST') return new Promise((resolve, reject) => { resolvePost = resolve; rejectPost = reject; });
                if (getFails) throw new Error('Unable to load <unsafe>');
                const current = Number(new URL(url, 'http://offline.test').searchParams.get('page'));
                return { response: { ok: true }, data: { success: true, data: { data: rows, current_page: current, last_page: 2, total } } };
            },
        },
    });
    const state = "let pmPlansView = 'active', pmArchivedRows = [], pmArchivedPage = 1, pmArchivedLastPage = 1, pmArchivedRequest = 0, pmArchivedLoading = false, pmArchivedError = ''; const pmActivationPending = new Set(); let pmScheduleRows = [], pmActiveTab = 'overview';";
    new vm.Script(state + functions + '\nglobalThis.inspectState = () => ({ pmPlansView, pmScheduleRows, pmArchivedRows }); globalThis.seedActive = (rows) => { pmScheduleRows = rows; };').runInContext(context);
    context.seedActive([active]);
    return { context, get, requests, confirmations, errors, refreshes, button,
        settle: (success = true) => resolvePost({ response: { ok: success }, data: { success, message: success ? 'Restored' : 'Restore denied' } }),
        reject: () => rejectPost(new Error('Network unavailable')) };
}

test('Active Plans defaults to active rows and excludes archived rows', () => {
    const h = harness(); h.context.seedActive([active, archived]); h.context.pmRenderPlansList();
    assert.match(h.get('pm-overview-plans-container').innerHTML, /Active plan/);
    assert.doesNotMatch(h.get('pm-overview-plans-container').innerHTML, /Archived plan/);
});

test('archived list uses server total and pagination metadata with separate state', async () => {
    const h = harness(); h.context.pmSwitchPlansView('archived'); await h.context.pmLoadArchivedPlans(2);
    assert.equal(h.get('pm-plans-archived').textContent, 'Archived Plans (23)');
    assert.equal(h.get('pm-archived-page').textContent, 'Page 2 of 2');
    assert.equal(h.get('pm-archived-next').disabled, true); assert.equal(h.get('pm-archived-prev').disabled, false);
    assert.match(h.requests.at(-1).url, /is_active=0&per_page=20&page=2/);
    assert.equal(h.context.inspectState().pmScheduleRows[0].id, 1);
    assert.equal(h.context.inspectState().pmArchivedRows[0].id, 2);
    assert.match(h.get('pm-overview-plans-container').innerHTML, /Archived plan/);
    h.context.pmSwitchPlansView('active');
    assert.equal(h.get('pm-archived-pagination').hidden, true);
    assert.match(h.get('pm-overview-plans-container').innerHTML, /Active plan/);
});

test('archived loading, empty, and error states are explicit and escaped', async () => {
    const h = harness({ rows: [], total: 0 }); h.context.pmSwitchPlansView('archived');
    assert.match(h.get('pm-overview-plans-container').innerHTML, /LOADING/);
    await h.context.pmLoadArchivedPlans();
    assert.match(h.get('pm-overview-plans-container').innerHTML, /No archived maintenance plans/);
    assert.equal(h.get('pm-plans-archived').textContent, 'Archived Plans (0)');
    const failed = harness({ getFails: true }); failed.context.pmSwitchPlansView('archived'); await failed.context.pmLoadArchivedPlans();
    failed.context.pmRenderPlansList(); // An active-grid refresh must not overwrite the archived error.
    assert.match(failed.get('pm-overview-plans-container').innerHTML, /&lt;unsafe&gt;/);
    assert.equal(failed.get('pm-plans-archived').textContent, 'Archived Plans (unavailable)');
});

test('archived rows expose only View and Administrator Unarchive', () => {
    const h = harness(); const actions = h.context.pmScheduleActionsCell(archived);
    assert.match(actions, /data-action="view"/); assert.match(actions, /data-action="activate"/);
    assert.doesNotMatch(actions, /data-action="(?:edit|archive|assign|complete)"/);
    const nonAdmin = harness({ admin: false });
    assert.doesNotMatch(nonAdmin.context.pmScheduleActionsCell(archived), /data-action="activate"/);
});

test('Unarchive confirmation has requested content and safely escaped plan name; Cancel sends nothing', async () => {
    const h = harness({ confirm: false });
    await h.context.pmToggleActive({ ...archived, title: '<img src=x onerror=alert(1)> "Plan" & test' }, true);
    assert.equal(h.requests.length, 0); assert.equal(h.button.disabled, false);
    const [message, yes, no] = h.confirmations[0];
    assert.match(message, /Unarchive Maintenance Plan\?/); assert.match(message, /Are you sure you want to restore/);
    assert.match(message, /&lt;img/); assert.match(message, /&quot;Plan&quot; &amp; test/);
    assert.equal(yes, 'Yes, Unarchive'); assert.equal(no, 'Cancel');
});

test('double-click makes one activate request and success refreshes all required datasets', async () => {
    const h = harness(); const pending = h.context.pmToggleActive(archived, true);
    await h.context.pmToggleActive(archived, true);
    assert.equal(h.confirmations.length, 1); assert.equal(h.requests.length, 1); assert.equal(h.button.disabled, true);
    assert.equal(h.requests[0].url, '/api/preventive-maintenance/2/activate'); assert.equal(h.requests[0].method, 'POST');
    h.settle(); await pending;
    assert.deepEqual(h.refreshes.sort(), ['active', 'summary']);
    assert.equal(h.requests.filter((request) => request.method === 'POST').length, 1);
    assert.ok(h.requests.some((request) => request.url.includes('is_active=0'))); assert.equal(h.button.disabled, false);
});

for (const network of [false, true]) test((network ? 'network' : 'API') + ' failure keeps visible plan and allows retry', async () => {
    const h = harness(); h.context.pmSwitchPlansView('archived'); await h.context.pmLoadArchivedPlans();
    const before = h.get('pm-overview-plans-container').innerHTML;
    const pending = h.context.pmToggleActive(archived, true); await new Promise(setImmediate);
    if (network) h.reject(); else h.settle(false);
    await pending;
    assert.equal(h.get('pm-overview-plans-container').innerHTML, before);
    assert.equal(h.refreshes.length, 0); assert.equal(h.errors.length, 1); assert.equal(h.button.disabled, false);
});

test('non-Administrator cannot invoke restore through the frontend helper', async () => {
    const h = harness({ admin: false }); await h.context.pmToggleActive(archived, true);
    assert.equal(h.requests.length, 0); assert.equal(h.confirmations.length, 0);
});

test('special-character archived names render as text and history retains existing report links', async () => {
    const h = harness({ rows: [{ ...archived, title: '<script>alert(1)</script> & "Plan"' }] });
    h.context.pmSwitchPlansView('archived'); await h.context.pmLoadArchivedPlans();
    const html = h.get('pm-overview-plans-container').innerHTML;
    assert.match(html, /&lt;script&gt;/); assert.doesNotMatch(html, /<script>/);
    assert.match(page, /if \(h\.maintenance_report_id\)/);
    assert.match(page, /else if \(pmHistoryRow\?\.is_active && pmCanCompleteTask\(pmHistoryRow\)\)/);
});

test('whole inline JavaScript compiles; shared toolbar and schedule filters remain available', () => {
    const script = page.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, '0');
    new vm.Script(script);
    assert.match(page, /<option value="0">Archived<\/option>/);
    assert.match(page, /role="tablist" aria-label="Maintenance plans"/);
});

test('history is read-only for archived plans but active checklist follow-up and linked reports remain available', () => {
    const h = harness(); const history = { id: 33, condition_result: 'needs_repair' };
    h.context.pmHistoryRow = archived;
    assert.doesNotMatch(h.context.pmHistoryRowMarkup(history), /data-action="create-repair-report"/);
    h.context.pmHistoryRow = active;
    assert.match(h.context.pmHistoryRowMarkup(history), /data-action="create-repair-report"/);
    assert.match(h.context.pmHistoryRowMarkup({ ...history, maintenance_report_id: 77 }), /Repair report #77/);
    assert.match(page, /items\.forEach\(\(item\) => \{ item\.is_active = true; \}\)/);
});
