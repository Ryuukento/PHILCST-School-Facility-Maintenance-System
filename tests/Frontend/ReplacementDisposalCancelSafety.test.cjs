// Offline DOM/fetch doubles only. No browser, database, or real disposal requests.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/replacement-tracking.php'), 'utf8');
const source = page.slice(page.indexOf('const replacementDisposalsInProgress'), page.indexOf('function applyReplacementTrackingFilters()'));

function harness(allowed = true) {
    const requests = [], alerts = [], nodes = new Map();
    let refreshes = 0, gate = null, fail = false;
    const document = { activeElement: null, body: { style: { overflow: 'auto' } } };
    function node(id) {
        const listeners = new Map(), attributes = new Map();
        return { id, hidden: true, value: '', disabled: false, textContent: 'Mark as Disposed', isConnected: true,
            addEventListener(type, callback) { if (!listeners.has(type)) listeners.set(type, new Set()); listeners.get(type).add(callback); },
            removeEventListener(type, callback) { listeners.get(type)?.delete(callback); },
            fire(type, event = {}) { return Promise.all([...(listeners.get(type) || [])].map(callback => callback({ target: this, preventDefault() {}, stopPropagation() {}, ...event }))); },
            setAttribute(name, value) { attributes.set(name, value); },
            getAttribute(name) { return name === 'data-report-id' ? '99' : attributes.get(name); },
            focus() { document.activeElement = this; },
            listenerCount() { return [...listeners.values()].reduce((sum, set) => sum + set.size, 0); },
        };
    }
    const get = id => { if (!nodes.has(id)) nodes.set(id, node(id)); return nodes.get(id); };
    const events = node('document');
    Object.assign(document, { getElementById: get, querySelectorAll: () => [get('trigger')],
        addEventListener: events.addEventListener, removeEventListener: events.removeEventListener });
    const context = vm.createContext({ document, CAN_CONFIRM_DISPOSAL: allowed,
        REPLACEMENT_TRACKING_API_BASE: '/project/api/replacement-tracking',
        fetch: async (url, options) => {
            requests.push({ url, ...options }); if (gate) await gate;
            return { ok: !fail, json: async () => ({ success: !fail, message: 'Synthetic disposal error' }) };
        }, loadReplacementTracking: async () => { refreshes++; },
        console: { error() {} }, alert: message => alerts.push(message),
    });
    new vm.Script(source).runInContext(context); context.bindReplacementDisposeButtons();
    return { get, document, events, context, requests, alerts,
        refreshes: () => refreshes, fail: value => { fail = value; },
        hold() { let resolve; gate = new Promise(r => { resolve = r; }); return () => { gate = null; resolve(); }; },
        open: () => get('trigger').fire('click'),
    };
}

for (const action of ['Cancel', 'Escape', 'close button', 'backdrop']) {
    test(action + ' preserves cancellation and sends zero requests', async () => {
        const h = harness(), pending = h.open();
        assert.equal(h.get('replacement-disposal-modal').hidden, false);
        h.get('replacement-disposal-note').value = 'Unsubmitted note';
        if (action === 'Escape') await h.events.fire('keydown', { key: 'Escape' });
        else await h.get(action === 'Cancel' ? 'replacement-disposal-cancel' : action === 'close button' ? 'replacement-disposal-close' : 'replacement-disposal-modal').fire('click');
        await pending;
        assert.equal(h.requests.length, 0); assert.equal(h.refreshes(), 0);
        assert.equal(h.get('replacement-disposal-modal').hidden, true);
        assert.equal(h.get('replacement-disposal-modal').getAttribute('aria-hidden'), 'true');
        assert.equal(h.get('trigger').disabled, false); assert.equal(h.document.activeElement.id, 'trigger');
        assert.equal(h.document.body.style.overflow, 'auto'); assert.equal(h.events.listenerCount(), 0);
    });
}

for (const note of ['', '   ', '  Turned over to Property Custodian  ', '<img src=x onerror="executed=true"> & "note"']) {
    test('explicit confirmation sends exactly one intended POST for note ' + JSON.stringify(note), async () => {
        const h = harness(), pending = h.open(); h.get('replacement-disposal-note').value = note;
        await h.get('replacement-disposal-confirm').fire('click'); await pending;
        assert.equal(h.requests.length, 1); const request = h.requests[0];
        assert.equal(request.url, '/project/api/replacement-tracking/99/dispose');
        assert.equal(request.method, 'POST'); assert.equal(request.credentials, 'include');
        assert.equal(request.headers['Content-Type'], 'application/json');
        assert.deepEqual(JSON.parse(request.body), { notes: note.trim() || null });
        assert.equal(h.refreshes(), 1); assert.equal(h.get('replacement-disposal-modal').hidden, true);
        assert.equal(h.get('trigger').disabled, true); assert.equal(h.context.executed, undefined);
    });
}

test('double opening, double confirmation and repeat clicks during delivery cannot duplicate POST', async () => {
    const h = harness(), release = h.hold(), pending = h.open();
    await h.open(); assert.equal(h.requests.length, 0);
    const confirm = h.get('replacement-disposal-confirm');
    await Promise.all([confirm.fire('click'), confirm.fire('click')]);
    await h.open(); assert.equal(h.requests.length, 1);
    release(); await pending; assert.equal(h.refreshes(), 1);
    await confirm.fire('click'); assert.equal(h.requests.length, 1);
});

test('dismissed modal can reopen cleanly without accumulating handlers or stale notes', async () => {
    const h = harness(); let pending = h.open(); h.get('replacement-disposal-note').value = 'Cancelled';
    await h.get('replacement-disposal-close').fire('click'); await pending;
    pending = h.open(); assert.equal(h.get('replacement-disposal-note').value, '');
    await h.get('replacement-disposal-confirm').fire('click'); await pending;
    assert.equal(h.requests.length, 1); assert.deepEqual(JSON.parse(h.requests[0].body), { notes: null });
});

test('dialog content clicks do not confirm or dismiss, and keyboard focus stays inside', async () => {
    const h = harness(), pending = h.open();
    assert.equal(h.document.activeElement.id, 'replacement-disposal-note');
    await h.get('replacement-disposal-modal').fire('click', { target: h.get('replacement-disposal-note') });
    assert.equal(h.get('replacement-disposal-modal').hidden, false); assert.equal(h.requests.length, 0);
    h.get('replacement-disposal-confirm').focus(); await h.events.fire('keydown', { key: 'Tab' });
    assert.equal(h.document.activeElement.id, 'replacement-disposal-close');
    await h.events.fire('keydown', { key: 'Tab', shiftKey: true }); assert.equal(h.document.activeElement.id, 'replacement-disposal-confirm');
    await h.events.fire('keydown', { key: 'Escape' }); await pending;
});

test('backend rejection is reported without automatic retry and controls recover', async () => {
    const h = harness(); h.fail(true); const pending = h.open();
    await h.get('replacement-disposal-confirm').fire('click'); await pending;
    assert.equal(h.requests.length, 1); assert.equal(h.refreshes(), 0);
    assert.deepEqual(h.alerts, ['Synthetic disposal error']); assert.equal(h.get('trigger').disabled, false);
    const retry = h.open(); await h.get('replacement-disposal-cancel').fire('click'); await retry;
    assert.equal(h.requests.length, 1);
});

test('existing client permission guard denies confirmation without opening modal or sending request', async () => {
    const h = harness(false); await h.open();
    assert.equal(h.requests.length, 0); assert.equal(h.get('replacement-disposal-modal').hidden, true);
});

test('accessible modal markup, bounded layout and full page JavaScript compile', () => {
    assert.match(page, /id="replacement-disposal-modal" hidden role="dialog" aria-modal="true"/);
    assert.match(page, /aria-labelledby="replacement-disposal-title" aria-describedby="replacement-disposal-message"/);
    assert.match(page, /id="replacement-disposal-note"[^>]*maxlength="255"/);
    assert.match(page, /max-height: 90vh; overflow-y: auto/);
    assert.doesNotMatch(source, /window\.prompt|systemPrompt/);
    new vm.Script(page.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, 'false'));
});
