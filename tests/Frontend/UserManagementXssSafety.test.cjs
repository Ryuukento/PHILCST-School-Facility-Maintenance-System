// Run with: node --test tests/Frontend/UserManagementXssSafety.test.cjs
// Executes the page's JavaScript with DOM doubles and an offline fetch stub.
// These are source/rendering and interaction tests, not real-browser XSS tests.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/users.php'), 'utf8');
const scripts = [...page.matchAll(/<script>\s*([\s\S]*?)<\/script>/g)];
assert.equal(scripts.length, 1);
const source = scripts[0][1].replace('<?php echo json_encode($userRole); ?>', JSON.stringify('super_admin'));
assert.ok(!source.includes('<?php'));

function decodeText(value) {
    const entities = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'" };
    return value.replace(/&(?:amp|lt|gt|quot|#39);/g, (match) => entities[match]);
}

function harness(role = 'super_admin') {
    const nodes = new Map();
    const ready = [];
    const requests = [];
    class Element {
        constructor(id = '') {
            this.id = id;
            this.value = '';
            this.style = {};
            this.dataset = {};
            this.disabled = false;
            this.listeners = {};
            this.classList = { add() {}, remove() {}, toggle() {} };
        }
        set innerHTML(html) {
            this.html = html;
            // Only recognize form IDs/value attributes; do not interpret or execute HTML.
            for (const match of html.matchAll(/<[^>]+\bid="([^"]+)"[^>]*>/g)) {
                const element = new Element(match[1]);
                const value = match[0].match(/\bvalue="([^"]*)"/);
                if (value) element.value = decodeText(value[1]);
                nodes.set(element.id, element);
            }
        }
        get innerHTML() { return this.html || ''; }
        addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
        async emit(name, event = {}) {
            for (const callback of this.listeners[name] || []) await callback({ target: this, ...event });
        }
        appendChild(element) { nodes.set(element.id, element); }
        querySelector() { return null; }
        querySelectorAll() { return []; }
        setAttribute(name, value) { this[name] = value; }
        getAttribute(name) { return this[name]; }
        contains(element) { return element?.inside !== false; }
        focus() {}
        remove() { nodes.delete(this.id); }
    }
    for (const id of ['users-container', 'users-pagination', 'users-summary', 'search-input', 'add-new-user-btn', 'alert-container']) {
        nodes.set(id, new Element(id));
    }
    const document = {
        body: new Element('body'),
        activeElement: null,
        getElementById: (id) => nodes.get(id) || null,
        createElement: () => new Element(),
        querySelectorAll: () => [],
        querySelector: () => null,
        addEventListener: (name, callback) => { if (name === 'DOMContentLoaded') ready.push(callback); },
    };
    const context = vm.createContext({
        document,
        window: { API: {}, SFMS_PUBLIC_URL: (url) => url, location: { search: '' } },
        console: { error() {}, log() {} },
        URLSearchParams,
        setTimeout: () => 0,
        fetch: async (url, options = {}) => {
            requests.push({ url, ...options });
            return { ok: true, json: async () => ({ success: true, data: { users: [], departments: [{ department_id: 5, name: 'Maintenance' }] } }) };
        },
    });
    // Compile only trusted repository source. Payloads are assigned as values below.
    new vm.Script(source.replace('const currentUserRole = "super_admin";', `const currentUserRole = ${JSON.stringify(role)};`)
        + '\nglobalThis.testUsersState = usersState;').runInContext(context);
    function select(user) {
        context.testUsersState.allUsers = [{ ...user, user_id: 19, full_name: 'Other person' }, user];
        context.testUsersState.filteredUsers = [user];
    }
    function click(action, id = '73', overrides = {}) {
        const button = { dataset: { userAction: action, userId: id }, disabled: false, ...overrides };
        return context.handleUserAction({ target: { closest: () => button }, currentTarget: nodes.get('users-container') });
    }
    return { context, nodes, requests, ready, select, click };
}

function user(overrides = {}) {
    return { user_id: 73, full_name: 'Doe, Jane D.', email: 'jane@example.test', role: 'maintenance_staff',
        status: 'active', department_id: 5, designation: 'Technician', ...overrides };
}

const sensitiveNames = [
    `O'Neil, Jane "JJ" & Family`,
    '<img src=x onerror="globalThis.xssExecuted=true">',
    '"><svg onload="globalThis.xssExecuted=true">',
    "&#39;);globalThis.xssExecuted=true;//",
    '</script><script>globalThis.xssExecuted=true</script>',
    'A < B > C & D \\ E\nF',
];

for (const name of sensitiveNames) {
    test(`sensitive name remains escaped data: ${JSON.stringify(name)}`, () => {
        const h = harness();
        const account = user({ full_name: name, email: name, has_pending_password_reset_request: true });
        h.select(account);
        const html = h.context.renderUserCard(account);
        const title = html.match(/<h3>([\s\S]*?)<\/h3>/)[1];
        assert.equal(decodeText(title), name);
        assert.ok(!title.includes('<'));
        const buttons = [...html.matchAll(/<button\b[^>]*>/g)].map((match) => match[0]);
        assert.ok(buttons.length > 0);
        for (const button of buttons) {
            assert.doesNotMatch(button, /\bon\w+\s*=/i);
            assert.match(button, /data-user-id="73"/);
            assert.ok(!button.includes(h.context.escapeHtml(name)));
        }
        assert.equal(h.context.xssExecuted, undefined);
    });
}

test('every action retrieves the selected account without evaluating its name or email', () => {
    const h = harness();
    const account = user({ full_name: sensitiveNames[2], email: sensitiveNames[3] });
    h.select(account);
    const routes = { edit: 'openEditUserModal', approve: 'openApproveUserModal', reject: 'openRejectUserModal',
        activate: 'openActivateUserModal', deactivate: 'openInactiveUserModal', 'reset-password': 'openResetPasswordModal' };
    for (const [action, method] of Object.entries(routes)) {
        let args;
        h.context[method] = (...values) => { args = values; };
        h.click(action);
        assert.deepEqual(args, action === 'edit' ? [73] : action === 'reset-password'
            ? [73, account.full_name, account.email] : [73, account.full_name]);
    }
    assert.equal(h.requests.length, 0);
    assert.equal(h.context.xssExecuted, undefined);
});

test('invalid, missing, disabled and unknown actions fail closed', () => {
    const h = harness();
    h.select(user());
    let calls = 0;
    h.context.openInactiveUserModal = () => { calls++; };
    for (const id of ['0', '-1', 'NaN', 'Infinity', '73;globalThis.xssExecuted=true', '9007199254740992', '999']) h.click('deactivate', id);
    h.click('deactivate', '73', { disabled: true });
    h.click('deactivate', '73', { inside: false });
    h.click('globalThis.xssExecuted=true');
    h.context.handleUserAction({ target: { closest: () => null }, currentTarget: h.nodes.get('users-container') });
    assert.equal(calls, 0);
    assert.equal(h.context.xssExecuted, undefined);
});

test('delegation rejects a non-Administrator context', () => {
    const h = harness('maintenance_staff');
    h.select(user());
    let calls = 0;
    h.context.openInactiveUserModal = () => { calls++; };
    h.click('deactivate');
    assert.equal(calls, 0);
});

test('existing status-dependent action visibility is preserved', () => {
    const h = harness();
    const actions = (overrides) => [...h.context.renderUserCard(user(overrides)).matchAll(/data-user-action="([^"]+)"/g)].map((m) => m[1]);
    assert.deepEqual(actions({ status: 'pending' }), ['approve', 'reject']);
    assert.deepEqual(actions({ status: 'inactive' }), ['edit', 'activate']);
    assert.deepEqual(actions({ status: 'active' }), ['edit', 'deactivate']);
    assert.deepEqual(actions({ has_pending_password_reset_request: true }), ['edit', 'reset-password', 'deactivate']);
});

for (const [action, modalId] of [['approve', 'approve-user-modal'], ['reject', 'reject-user-modal'],
    ['activate', 'activate-user-modal'], ['deactivate', 'inactive-user-modal'], ['reset-password', 'reset-password-modal'], ['edit', 'edit-user-modal']]) {
    test(`${action} modal keeps sensitive names as escaped text or attribute data`, async () => {
        const h = harness();
        const account = user({ full_name: sensitiveNames[2], email: sensitiveNames[1] });
        h.select(account);
        h.click(action);
        await Promise.resolve();
        const html = h.nodes.get(modalId).innerHTML;
        assert.ok(html.includes(h.context.escapeHtml(account.full_name)));
        assert.ok(!html.includes(account.full_name));
        assert.ok(!html.includes('<svg onload='));
        assert.ok(!h.requests.some((request) => request.method && request.method !== 'GET'));
        assert.equal(h.context.xssExecuted, undefined);
    });
}

for (const [action, confirm, method, endpoint] of [
    ['approve', 'confirm-approve-btn', 'PATCH', '/api/users/73/approve'],
    ['reject', 'confirm-reject-btn', 'DELETE', '/api/users/73/reject'],
    ['activate', 'confirm-activate-btn', 'PATCH', '/api/users/73/activate'],
    ['deactivate', 'confirm-inactive-btn', 'PATCH', '/api/users/73/deactivate'],
    ['reset-password', 'confirm-reset-password-btn', 'POST', '/api/users/73/reset-password'],
    ['edit', 'confirm-edit-user-btn', 'PATCH', '/api/users/73'],
]) {
    test(`${action} confirms the correct endpoint and selected user using offline fetch`, async () => {
        const h = harness();
        h.select(user());
        h.click(action);
        await Promise.resolve();
        if (action === 'approve') {
            h.nodes.get('approve-user-role').value = 'maintenance_staff';
            h.nodes.get('approve-user-department').value = '5';
        }
        if (action === 'edit') {
            h.nodes.get('edit-user-full-name').value = 'Doe, Jane';
            h.nodes.get('edit-user-role').value = 'maintenance_staff';
            h.nodes.get('edit-user-department').value = '5';
        }
        if (action === 'reset-password') h.nodes.get('reset-password-input').value = 'synthetic-password';
        await h.nodes.get(confirm).emit('click');
        assert.equal(h.requests.filter((r) => r.method === method && r.url === endpoint).length, 1);
        assert.ok(!h.requests.some((r) => r.method !== 'GET' && r.url.includes('/19')));
    });
}

test('Add remains listener-bound and submits the existing creation endpoint', async () => {
    const h = harness();
    h.context.loadUsers = async () => {};
    h.context.highlightUserFromQuery = () => {};
    h.ready[0]();
    assert.equal(h.nodes.get('users-container').listeners.click.length, 1);
    await h.nodes.get('add-new-user-btn').emit('click');
    for (const [id, value] of Object.entries({ 'register-first-name': 'Jane', 'register-last-name': 'Doe',
        'register-middle-initial': 'D', 'register-username': 'jane_doe', 'register-password': 'synthetic-password',
        'register-role': 'maintenance_staff', 'register-department': '5' })) h.nodes.get(id).value = value;
    await h.nodes.get('confirm-register-btn').emit('click');
    const request = h.requests.find((r) => r.method === 'POST' && r.url === '/api/users');
    assert.ok(request);
    assert.equal(JSON.parse(request.body).full_name, 'Doe, Jane D.');
});

test('related error and role rendering preserve HTML escaping', async () => {
    const h = harness();
    h.context.fetch = async () => { throw new Error('<img src=x onerror="globalThis.xssExecuted=true">'); };
    await h.context.loadUsers();
    assert.match(h.nodes.get('users-container').innerHTML, /&lt;img/);
    assert.doesNotMatch(h.nodes.get('users-container').innerHTML, /<img/);
    const html = h.context.renderUserCard(user({ role: '"><svg onload="globalThis.xssExecuted=true">', full_name: '< A' }));
    assert.ok(!html.includes('<svg onload='));
    assert.match(html, /users-mini-avatar">&lt;A<\/div>/);
    assert.equal(h.context.xssExecuted, undefined);
});

test('page source contains no inline click handlers or dynamic code execution', () => {
    assert.doesNotMatch(source, /\bonclick\s*=/i);
    assert.doesNotMatch(source, /escapeJsString/);
    assert.doesNotMatch(source, /\beval\s*\(|\b(?:new\s+)?Function\s*\(/);
});
