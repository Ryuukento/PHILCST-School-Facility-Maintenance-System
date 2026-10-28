// Run with: node --test tests/Frontend/UserManagementPasswordResetSafety.test.cjs
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
        remove() { for (const match of this.innerHTML.matchAll(/id="([^"]+)"/g)) nodes.delete(match[1]); nodes.delete(this.id); }
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

test('Administrator recovery offers only Reset Password and requires the actor password', () => {
    const h = harness();
    const account = user({ role: 'super_admin' });
    h.select(account);
    const actions = [...h.context.renderUserCard(account).matchAll(/data-user-action="([^"]+)"/g)].map((m) => m[1]);
    assert.deepEqual(actions, ['reset-password']);
    h.click('reset-password');
    const html = h.nodes.get('reset-password-modal').innerHTML;
    assert.match(html, /Your Current Password/);
    assert.match(html, /your own Administrator password/);
    assert.match(html, /id="reset-actor-current-password"[^>]+type="password"[^>]+required/);
    assert.equal(h.nodes.get('reset-actor-current-password').value, '');
});

test('non-Administrator reset keeps the old experience without actor proof', async () => {
    const h = harness();
    h.select(user());
    h.click('reset-password');
    assert.equal(h.nodes.get('reset-actor-current-password'), undefined);
    h.nodes.get('reset-password-input').value = 'synthetic-temporary';
    await h.nodes.get('confirm-reset-password-btn').emit('click');
    const request = h.requests.find((r) => r.method === 'POST');
    assert.deepEqual(JSON.parse(request.body), { new_password: 'synthetic-temporary' });
    assert.equal(request.url, '/api/users/73/reset-password');
});

test('missing proof blocks Administrator recovery before submission', async () => {
    const h = harness();
    h.select(user({ role: 'super_admin' }));
    h.click('reset-password');
    h.nodes.get('reset-password-input').value = 'synthetic-temporary';
    await h.nodes.get('confirm-reset-password-btn').emit('click');
    assert.equal(h.requests.filter((r) => r.method === 'POST').length, 0);
});

test('proof travels only in the reset request body and is cleared after success', async () => {
    const h = harness();
    h.select(user({ role: 'super_admin', full_name: '<img src=x onerror=bad()> & "Admin"' }));
    h.click('reset-password');
    const proof = h.nodes.get('reset-actor-current-password');
    proof.value = 'actor-secret-"<&';
    h.nodes.get('reset-password-input').value = 'synthetic-temporary';
    assert.ok(!h.nodes.get('reset-password-modal').innerHTML.includes(proof.value));
    await h.nodes.get('confirm-reset-password-btn').emit('click');
    const request = h.requests.find((r) => r.method === 'POST');
    assert.deepEqual(JSON.parse(request.body), { new_password: 'synthetic-temporary', current_password: 'actor-secret-"<&' });
    assert.equal(request.url, '/api/users/73/reset-password');
    assert.equal(proof.value, '');
    assert.equal(h.nodes.has('reset-password-modal'), false);
});

test('cancel and changing target clear both proof and temporary password', async () => {
    const h = harness();
    h.select(user({ role: 'super_admin' }));
    h.click('reset-password');
    const first = h.nodes.get('reset-actor-current-password');
    const temporary = h.nodes.get('reset-password-input');
    first.value = 'secret';
    temporary.value = 'temporary';
    await h.nodes.get('cancel-reset-password-btn').emit('click');
    assert.equal(first.value, '');
    assert.equal(temporary.value, '');
    h.click('reset-password');
    const second = h.nodes.get('reset-actor-current-password');
    second.value = 'another-secret';
    h.select(user({ role: 'maintenance_staff' }));
    h.click('reset-password');
    assert.equal(second.value, '');
    assert.equal(h.nodes.get('reset-actor-current-password'), undefined);
});

test('failed verification keeps the modal open but clears proof without logging it', async () => {
    const h = harness();
    h.select(user({ role: 'super_admin' }));
    h.click('reset-password');
    const logs = [];
    h.context.console.error = (...args) => logs.push(args);
    h.context.fetch = async () => ({ json: async () => ({ success: false, errors: { current_password: ['Unable to verify password reset authorization.'] } }) });
    const proof = h.nodes.get('reset-actor-current-password');
    proof.value = 'failed-sensitive-proof';
    h.nodes.get('reset-password-input').value = 'synthetic-temporary';
    await h.nodes.get('confirm-reset-password-btn').emit('click');
    assert.equal(proof.value, '');
    assert.equal(h.nodes.has('reset-password-modal'), true);
    assert.equal(h.nodes.get('confirm-reset-password-btn').disabled, false);
    assert.deepEqual(logs, []);
    assert.ok(!h.nodes.get('alert-container').innerHTML.includes('failed-sensitive-proof'));
});

test('transport failure clears proof and emits only a generic error', async () => {
    const h = harness();
    h.select(user({ role: 'super_admin' }));
    h.click('reset-password');
    h.context.console.error = () => assert.fail('Credentials must not be logged.');
    h.context.fetch = async () => { throw new Error('must-not-render-this-proof'); };
    const proof = h.nodes.get('reset-actor-current-password');
    proof.value = 'must-not-render-this-proof';
    h.nodes.get('reset-password-input').value = 'synthetic-temporary';
    await h.nodes.get('confirm-reset-password-btn').emit('click');
    assert.equal(proof.value, '');
    assert.ok(!h.nodes.get('alert-container').innerHTML.includes('must-not-render-this-proof'));
});

test('in-flight Administrator recovery cannot submit twice', async () => {
    const h = harness();
    h.select(user({ role: 'super_admin' }));
    h.click('reset-password');
    let resolve;
    let count = 0;
    h.context.fetch = async (_url, options = {}) => {
        if (options.method === 'POST') {
            count++;
            return new Promise((done) => { resolve = () => done({ json: async () => ({ success: true }) }); });
        }
        return { json: async () => ({ success: true, data: { users: [] } }) };
    };
    h.nodes.get('reset-actor-current-password').value = 'actor-proof';
    h.nodes.get('reset-password-input').value = 'synthetic-temporary';
    const button = h.nodes.get('confirm-reset-password-btn');
    const pending = button.emit('click');
    await button.emit('click');
    assert.equal(count, 1);
    assert.equal(button.disabled, true);
    resolve();
    await pending;
    assert.equal(button.disabled, false);
});

test('reset source does not persist actor credentials or interpolate them into markup', () => {
    const reset = source.slice(source.indexOf('function clearResetPasswordModal'), source.indexOf('async function deactivateUser'));
    assert.doesNotMatch(reset, /localStorage|sessionStorage|console\.(?:log|error)|onclick\s*=/);
    assert.doesNotMatch(reset, /value="\$\{/);
    assert.match(reset, /currentPassword === undefined/);
});
