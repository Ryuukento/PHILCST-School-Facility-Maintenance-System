// Runs the shared navigation script offline. Server rejection rules are covered by PHP regressions.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const script = read('public/frontend/assets/js/main.js');

function harness({ cached = 'present', blockedStorage = false, logoutFails = false, modal = true, serverName = 'Current Server Name' } = {}) {
    const nodes = new Map(), documentListeners = new Map(), toggles = [], storageWrites = [];
    let sessionReads = 0, sessionClears = 0, logoutCalls = 0;
    const get = id => {
        if (!nodes.has(id)) nodes.set(id, { textContent: '', value: '', style: {}, attrs: {}, listeners: new Map(),
            setAttribute(key, value) { this.attrs[key] = value; },
            addEventListener(type, callback) { if (!this.listeners.has(type)) this.listeners.set(type, []); this.listeners.get(type).push(callback); },
            async fire(type) { for (const callback of this.listeners.get(type) || []) await callback({ preventDefault() {} }); },
            classList: { add() {}, remove() {}, toggle() {} },
        });
        return nodes.get(id);
    };
    get('header-user-name').textContent = serverName;
    get('server-role-label').textContent = 'Server authorized role';
    const window = { location: { pathname: '/School_Facility_Maintenance_System/dashboard', href: '' },
        SFMS_PUBLIC_URL: url => '/School_Facility_Maintenance_System' + url };
    const api = { logout: async () => { logoutCalls++; if (logoutFails) throw new Error('Synthetic logout failure'); } };
    window.API = api;
    const context = vm.createContext({
        window, API: api, console,
        document: {
            head: { insertAdjacentHTML() {} },
            documentElement: { setAttribute() {}, style: { setProperty() {} } },
            getElementById: id => !modal && id.startsWith('global-logout') ? null : get(id),
            querySelector: selector => ['#headerProfileGreeting', '#headerProfileSubtext', '#headerDashboardKicker'].includes(selector) ? get(selector.slice(1)) : null,
            querySelectorAll: selector => selector === '[data-logout]' ? [get('logout')] : [],
            addEventListener(type, callback) { if (!documentListeners.has(type)) documentListeners.set(type, []); documentListeners.get(type).push(callback); },
        },
        localStorage: {
            getItem(key) { if (blockedStorage) throw new Error('Storage unavailable'); return key === 'sfms_settings_font_size' ? 'medium' : null; },
            setItem(key, value) { if (blockedStorage) throw new Error('Storage unavailable'); storageWrites.push({ key, value }); },
        },
        Session: {
            get() { sessionReads++; if (cached === 'malformed' || cached === 'unreadable') throw new Error('Unreadable cache');
                return cached === 'missing' ? null : { full_name: 'Stale Cached Name', role: 'super_admin', department_id: 999 }; },
            clear() { sessionClears++; if (blockedStorage) throw new Error('Storage unavailable'); },
        },
        UI: { toggleModal: (id, show) => toggles.push({ id, show }) }, setInterval() {},
        fetch() { throw new Error('Unexpected network request'); },
    });
    new vm.Script(script).runInContext(context);
    return { context, window, get, toggles, storageWrites,
        counters: () => ({ sessionReads, sessionClears, logoutCalls }),
        async init() { for (const callback of documentListeners.get('DOMContentLoaded') || []) await callback(); },
    };
}

for (const cached of ['present', 'missing', 'malformed', 'unreadable']) {
    test('server-rendered page initializes without login redirect for cache ' + cached, async () => {
        const h = harness({ cached }); await h.init();
        assert.equal(h.window.location.href, ''); assert.equal(h.counters().sessionReads, 0);
        assert.match(h.get('headerProfileGreeting').textContent, /Current Server Name/);
        assert.equal(h.get('header-user-name').textContent, 'Current Server Name');
        assert.equal(h.get('server-role-label').textContent, 'Server authorized role');
        assert.equal(h.get('logout').listeners.get('click').length, 1);
        assert.deepEqual(h.storageWrites, []);
    });
}

test('fully blocked browser storage does not interrupt initialization, display or logout fallback', async () => {
    const h = harness({ cached: 'unreadable', blockedStorage: true }); await h.init();
    assert.equal(h.window.location.href, ''); assert.equal(h.counters().sessionReads, 0);
    assert.doesNotThrow(() => h.window.AccessibilityManager.applyFontSize('large'));
    await h.get('logout').fire('click'); await h.get('global-logout-confirm').fire('click');
    assert.equal(h.counters().logoutCalls, 1); assert.equal(h.counters().sessionClears, 1);
    assert.equal(h.window.location.href, '/School_Facility_Maintenance_System/logout');
});

test('logout Cancel makes no request; confirm preserves API logout and server-side logout redirect', async () => {
    const h = harness(); await h.init(); await h.get('logout').fire('click');
    assert.deepEqual(h.toggles.at(-1), { id: 'global-logout-modal', show: true });
    await h.get('global-logout-cancel').fire('click'); assert.equal(h.counters().logoutCalls, 0); assert.equal(h.window.location.href, '');
    await h.get('logout').fire('click'); await h.get('global-logout-confirm').fire('click');
    assert.equal(h.counters().logoutCalls, 1); assert.equal(h.counters().sessionClears, 1);
    assert.equal(h.window.location.href, '/School_Facility_Maintenance_System/logout');
});

test('failed API logout still reaches server logout when storage is blocked', async () => {
    const h = harness({ blockedStorage: true, logoutFails: true, modal: false }); await h.init();
    await h.get('logout').fire('click'); await new Promise(setImmediate);
    assert.equal(h.counters().logoutCalls, 1);
    assert.equal(h.window.location.href, '/School_Facility_Maintenance_System/logout');
});

test('cached administrator identity cannot overwrite current server display or grant controls', async () => {
    const h = harness({ serverName: 'Transferred Maintenance User' }); await h.init();
    assert.match(h.get('headerProfileGreeting').textContent, /Transferred Maintenance User/);
    assert.equal(h.get('server-role-label').textContent, 'Server authorized role');
    assert.equal(h.counters().sessionReads, 0); assert.deepEqual(h.storageWrites, []);
});

test('missing display data remains harmless and never becomes a login decision', async () => {
    const h = harness({ serverName: '' }); await h.init();
    assert.match(h.get('headerProfileGreeting').textContent, /User/); assert.equal(h.window.location.href, '');
});

test('missing cache cannot add the dashboard-to-login edge of a redirect loop', async () => {
    const h = harness({ cached: 'missing' }); await h.init();
    h.context.initializeNavigation(); assert.equal(h.window.location.href, '');
    const login = read('public/frontend/pages/index.php');
    assert.match(login, /isset\(\$_SESSION\['auth_user'\]\) \|\| isset\(\$_SESSION\['user'\]\)/);
    assert.match(login, /header\('Location: ' \. public_url\('\/dashboard'\)\)/);
});

test('existing server guards and ping/idle mechanisms remain in place; no new tokens or authentication endpoint', () => {
    const header = read('public/frontend/includes/header.php');
    assert.match(header, /sfms_reject_stale_session\(\)/); assert.match(header, /sfms_enforce_idle_timeout\(\)/);
    assert.match(header, /public_url\('\/account\?setup=1'\)/);
    const ping = read('public/frontend/pages/session-ping.php');
    assert.match(ping, /sfms_refresh_native_authorization\(\)/); assert.match(ping, /http_response_code\(401\)/);
    assert.doesNotMatch(script, /Session\.get\('user'\)|\/api\/auth\/check|auth_version|session_token/);
    assert.match(read('public/frontend/includes/footer.php'), /main\.js\?v=20261029-session-navigation/);
});
