// Offline execution of the page's actual approval/render/reconciliation code.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/maintenance-report-detail.php'), 'utf8');
function cut(start, end) {
    const i = page.indexOf(start), j = page.indexOf(end, i);
    assert.ok(i >= 0 && j > i, start); return page.slice(i, j);
}
const render = cut('function needChangePresentation(', '// Load report details');
const handlers = cut("document.getElementById('approve-need-change-btn')?.addEventListener", "document.getElementById('reject-need-change-btn')?.addEventListener");
const pending = () => ({ report_id: 42, need_change_item_id: 7, need_change_item_name: 'Aircon', need_change_status: 'pending', need_change_deducted_at: null });
const completed = () => ({ ...pending(), need_change_status: 'deducted', need_change_deducted_at: '2026-10-29 18:23:49' });
const reply = (status, data) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
function harness({ report = pending(), admin = true, confirm = async () => true,
    patch = async () => reply(200, { success: true }), read = async () => reply(200, { success: true, data: { report: completed() } }) } = {}) {
    const nodes = new Map(), requests = [], toasts = [];
    const get = id => {
        if (!nodes.has(id)) {
            const classes = new Set();
            nodes.set(id, { innerHTML: '', textContent: '', style: {}, attrs: {}, hidden: false, disabled: false, listeners: {},
                classList: { toggle: (name, yes) => yes ? classes.add(name) : classes.delete(name) },
                setAttribute(k, v) { this.attrs[k] = v; }, addEventListener(k, fn) { this.listeners[k] = fn; },
            });
        }
        return nodes.get(id);
    };
    let confirmations = 0;
    const context = vm.createContext({
        document: { getElementById: get }, isSuperAdminUser: admin, currentReport: report, reportId: 42,
        needChangeApprovalBusy: false, needChangeStateUncertain: false, needChangeReleaseAcknowledged: false,
        isArchiveLocked: r => Boolean(r.archive?.is_locked), applyArchiveUI() {},
        showSystemConfirm: async () => { confirmations++; return confirm(); },
        UI: { escapeHtml: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
            toast: (...args) => toasts.push(args) },
        window: { SFMS_PUBLIC_URL: url => '/School_Facility_Maintenance_System' + url },
        fetch: async (url, options) => {
            const method = options.method || 'GET'; requests.push({ url, method, options });
            return method === 'PATCH' ? patch() : read();
        },
    });
    vm.runInContext(render + handlers, context);
    context.updateNeedChangeApprovalUI(report);
    return { context, get, requests, toasts, confirmations: () => confirmations,
        approve: () => get('approve-need-change-btn').listeners.click(),
        check: () => get('check-need-change-status-btn').listeners.click() };
}
test('successful approval shows exact toast and authoritative completed summary', async () => {
    const h = harness(); await h.approve();
    assert.equal(h.requests.map(r => r.method).join(','), 'PATCH,GET');
    assert.deepEqual(h.toasts[0], ['Replacement approved and released successfully.', 'success']);
    assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Approved & Released');
    assert.match(h.get('need-change-approval-meta').innerHTML, /Aircon/);
    assert.match(h.get('need-change-approval-meta').innerHTML, /Stock Deduction:<\/strong> Completed/);
    assert.match(h.get('need-change-approval-meta').innerHTML, /2026-10-29 18:23:49/);
    assert.equal(h.get('need-change-approval-actions').style.display, 'none');
    assert.equal(h.get('report-need-change-status').textContent, 'Approved & Released');
    assert.match(h.get('need-change-approval-description').textContent, /Already Processed/);
    assert.equal(h.requests[1].options.cache, 'no-store');
    assert.equal(JSON.parse(h.requests[0].options.body).approve_need_change, true);
});
test('completed state is derived on reload and cannot issue another approval', async () => {
    const h = harness({ report: completed() }); await h.approve();
    assert.equal(h.requests.length, 0); assert.equal(h.confirmations(), 0);
    assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Approved & Released');
    assert.equal(h.get('approve-need-change-btn').disabled, true);
    assert.ok(!h.get('need-change-approval-meta').innerHTML.includes('DEDUCTED'));
});
test('deduction timestamp confirms release even with a legacy status value', () => {
    const h = harness({ report: { ...completed(), need_change_status: null } });
    assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Approved & Released');
});
test('legacy approved without deduction evidence does not invent a deduction or date', async () => {
    const h = harness({ report: { ...pending(), need_change_status: 'approved' } }); await h.approve();
    assert.equal(h.requests.length, 0);
    assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Approved');
    assert.match(h.get('need-change-approval-meta').innerHTML, /Not confirmed/);
    assert.ok(!h.get('need-change-approval-meta').innerHTML.includes('Deducted At'));
});
test('rejected requests show an informational processed state with no approval request', async () => {
    const h = harness({ report: { ...pending(), need_change_status: 'rejected' } }); await h.approve();
    assert.equal(h.requests.length, 0);
    assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Request Rejected');
});
for (const role of ['maintenance_admin', 'maintenance_staff', 'user']) {
    test(`${role} retains no approval controls or API path through the handler`, async () => {
        const h = harness({ admin: false }); await h.approve();
        assert.equal(h.get('need-change-approval-card').style.display, 'none'); assert.equal(h.requests.length, 0);
    });
}
test('archive protection and missing request remain non-actionable', async () => {
    const h = harness({ report: { ...pending(), archive: { is_locked: true } } }); await h.approve();
    assert.equal(h.requests.length, 0); assert.equal(h.get('approve-need-change-btn').disabled, true);
    const empty = harness({ report: { report_id: 42 } });
    assert.equal(empty.get('need-change-approval-actions').style.display, 'none');
});
test('a second click during confirmation cannot open a second modal or submit', async () => {
    let release; const confirmation = new Promise(resolve => { release = resolve; });
    const h = harness({ confirm: () => confirmation });
    const first = h.approve(); await h.approve();
    assert.equal(h.confirmations(), 1); assert.equal(h.requests.length, 0);
    release(false); await first; assert.equal(h.requests.length, 0);
    assert.equal(h.get('approve-need-change-btn').disabled, false);
});
test('processing has a spinner, disabled actions and a request-level duplicate guard', async () => {
    let release; const network = new Promise(resolve => { release = resolve; });
    const h = harness({ patch: () => network }); const first = h.approve();
    await Promise.resolve(); await Promise.resolve(); await h.approve();
    assert.equal(h.requests.length, 1);
    assert.match(h.get('approve-need-change-btn').innerHTML, /need-change-spinner.*Processing/s);
    assert.equal(h.get('approve-need-change-btn').attrs['aria-busy'], 'true');
    assert.equal(h.get('reject-need-change-btn').disabled, true);
    release(reply(200, { success: true })); await first;
    assert.equal(h.requests.filter(r => r.method === 'PATCH').length, 1);
});
test('API validation failure is escaped and retry waits for a successful current-state read', async () => {
    const h = harness({ patch: async () => reply(422, { success: false, message: 'Insufficient stock <img>' }),
        read: async () => reply(200, { success: true, data: { report: pending() } }) });
    await h.approve();
    assert.match(h.get('need-change-approval-alert').innerHTML, /alert-danger.*Insufficient stock &lt;img&gt;/);
    assert.equal(h.get('approve-need-change-btn').disabled, false); assert.equal(h.toasts.length, 0);
    assert.equal(h.requests.map(r => r.method).join(','), 'PATCH,GET');
});
test('lost approval response is reconciled as successful when the current record is deducted', async () => {
    const h = harness({ patch: async () => { throw new Error('Disconnected'); } }); await h.approve();
    assert.equal(h.toasts.length, 1); assert.equal(h.requests.filter(r => r.method === 'PATCH').length, 1);
    assert.equal(h.get('need-change-approval-actions').style.display, 'none');
});
test('ambiguous response followed by pending read gives neutral feedback without automatic retry', async () => {
    const h = harness({ patch: async () => { throw new Error('Disconnected'); },
        read: async () => reply(200, { success: true, data: { report: pending() } }) });
    await h.approve();
    assert.match(h.get('need-change-approval-alert').innerHTML, /could not be confirmed/);
    assert.equal(h.toasts.length, 0); assert.equal(h.requests.length, 2);
    assert.equal(h.get('approve-need-change-btn').disabled, false);
});
test('failed reconciliation pauses actions; Check Current Status only performs GET', async () => {
    let connected = false;
    const h = harness({ patch: async () => { throw new Error('Disconnected'); }, read: async () => {
        if (!connected) throw new Error('Offline');
        return reply(200, { success: true, data: { report: completed() } });
    } });
    await h.approve(); await h.approve();
    assert.equal(h.requests.length, 2); assert.equal(h.get('approve-need-change-btn').disabled, true);
    assert.equal(h.get('check-need-change-status-btn').hidden, false);
    assert.match(h.get('need-change-approval-alert').innerHTML, /Unable to confirm whether approval completed/);
    connected = true; await h.check();
    assert.equal(h.requests.map(r => r.method).join(','), 'PATCH,GET,GET');
    assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Approved & Released');
});
test('confirmed success with contradictory pending state keeps actions paused', async () => {
    const h = harness({ read: async () => reply(200, { success: true, data: { report: pending() } }) });
    await h.approve(); await h.check(); await h.approve();
    assert.equal(h.requests.map(r => r.method).join(','), 'PATCH,GET,GET');
    assert.equal(h.get('approve-need-change-btn').disabled, true); assert.equal(h.toasts.length, 0);
});
test('server error is treated as ambiguous and malformed JSON still reconciles safely', async () => {
    for (const patch of [async () => reply(500, { success: false }), async () => ({ ok: true, json: async () => { throw new Error('Bad JSON'); } })]) {
        const h = harness({ patch }); await h.approve();
        assert.equal(h.get('need-change-approval-title').textContent, 'Replacement Approved & Released');
        assert.equal(h.requests.filter(r => r.method === 'PATCH').length, 1);
    }
});
test('wrong-record response is rejected and no failure/success is invented', async () => {
    const h = harness({ patch: async () => { throw new Error('Unknown'); },
        read: async () => reply(200, { success: true, data: { report: { ...completed(), report_id: 999 } } }) });
    await h.approve(); assert.equal(h.context.currentReport.report_id, 42);
    assert.equal(h.get('approve-need-change-btn').disabled, true); assert.equal(h.toasts.length, 0);
});
test('item names and timestamps are escaped; complete page JavaScript compiles', () => {
    const h = harness({ report: { ...completed(), need_change_item_name: '<img onerror=bad>', need_change_deducted_at: '<script>' } });
    assert.ok(!h.get('need-change-approval-meta').innerHTML.includes('<img'));
    assert.match(h.get('need-change-approval-meta').innerHTML, /&lt;script&gt;/);
    const source = page.replace(/<\?php[\s\S]*?\?>/g, 'null');
    for (const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) new vm.Script(match[1]);
});
