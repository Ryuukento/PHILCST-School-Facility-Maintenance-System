// Run with: node --test tests/Frontend/UserNameHandling.test.cjs
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

test('Add suffix dropdown has exactly the approved choices and defaults to None', () => {
    const h = harness();
    h.context.openRegisterUserModal();
    const html = h.nodes.get('register-user-modal').innerHTML;
    const select = html.match(/<select id="register-suffix"[^>]*>([\s\S]*?)<\/select>/);
    assert.ok(select);
    const options = [...select[1].matchAll(/<option value="([^"]*)">([^<]+)<\/option>/g)];
    assert.deepEqual(options.map((option) => [option[1], option[2]]),
        [['', 'None'], ['Jr.', 'Jr.'], ['Sr.', 'Sr.'], ['II', 'II'], ['III', 'III'], ['IV', 'IV'], ['V', 'V']]);
    assert.equal(h.nodes.get('register-suffix').value, '');
    assert.ok(!html.includes('<input id="register-suffix"'));
});

for (const suffix of ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V']) {
    test(`Add submits complete persisted-name input including suffix ${suffix || 'None'}`, async () => {
        const h = harness();
        h.context.loadUsers = async () => {};
        h.context.highlightUserFromQuery = () => {};
        h.context.openRegisterUserModal();
        for (const [id, value] of Object.entries({ 'register-first-name': 'Juan', 'register-last-name': 'Dela Cruz',
            'register-middle-initial': 'd.', 'register-suffix': suffix, 'register-username': 'juan_cruz',
            'register-password': 'Synthetic!123', 'register-role': 'maintenance_staff', 'register-department': '5' })) {
            h.nodes.get(id).value = value;
        }
        await h.nodes.get('confirm-register-btn').emit('click');
        const requests = h.requests.filter((request) => request.method === 'POST');
        assert.equal(requests.length, 1);
        const payload = JSON.parse(requests[0].body);
        assert.equal(payload.full_name, 'Dela Cruz, Juan D.' + (suffix ? ' ' + suffix : ''));
        assert.equal(payload.suffix, suffix);
        assert.equal(payload.last_name, 'Dela Cruz');
        assert.ok(!payload.full_name.includes('..'));
        assert.ok(!payload.full_name.includes('  '));
    });
}

test('composition normalizes spacing and optional parts without inventing placeholders', () => {
    const h = harness();
    assert.equal(h.context.buildFullName('  Dela   Cruz ', ' Juan ', '', 'jr'), 'Dela Cruz, Juan Jr.');
    assert.equal(h.context.buildFullName('Santos', 'Pedro', '', 'III'), 'Santos, Pedro III');
    assert.equal(h.context.buildFullName('Reyes', 'Maria', '', ''), 'Reyes, Maria');
    assert.equal(h.context.buildFullName('Peña', 'José', 'n\u0303', 'sr'), 'Peña, José Ñ. Sr.');
});

for (const suffix of ['123', 'VI', 'Random', 'Jr123', 'Jr..', 'Jr.,']) {
    test(`invalid suffix ${suffix} blocks Add before any write request`, async () => {
        const h = harness();
        h.context.openRegisterUserModal();
        for (const [id, value] of Object.entries({ 'register-first-name': 'Juan', 'register-last-name': 'Cruz',
            'register-suffix': suffix, 'register-username': 'juan_cruz', 'register-password': 'Synthetic!123',
            'register-role': 'maintenance_staff', 'register-department': '5' })) h.nodes.get(id).value = value;
        await h.nodes.get('confirm-register-btn').emit('click');
        assert.ok(h.context.getSuffixValidationError(suffix));
        assert.equal(h.requests.filter((request) => request.method === 'POST').length, 0);
    });
}

test('persisted suffix appears in cards, search, edit and action confirmations', () => {
    const h = harness();
    const account = user({ full_name: 'Dela Cruz, Juan D. Jr.' });
    h.select(account);
    assert.ok(h.context.renderUserCard(account).includes('<h3>Dela Cruz, Juan D. Jr.</h3>'));
    h.context.testUsersState.searchTerm = 'jr.';
    h.context.applyUserFilters();
    assert.equal(h.context.testUsersState.filteredUsers.length, 1);
    assert.equal(h.context.testUsersState.filteredUsers[0].user_id, 73);
    h.context.openEditUserModal(73);
    assert.equal(h.nodes.get('edit-user-full-name').value, account.full_name);
    h.context.openApproveUserModal(73, account.full_name);
    assert.ok(h.nodes.get('approve-user-modal').innerHTML.includes(account.full_name));
    h.context.openRejectUserModal(73, account.full_name);
    assert.ok(h.nodes.get('reject-user-modal').innerHTML.includes(account.full_name));
});

test('unchanged legacy name remains editable with duplicate records in loaded state', async () => {
    const h = harness();
    const name = 'Legacy 123 <name> VI';
    const account = user({ full_name: name });
    h.select(account);
    h.context.testUsersState.allUsers.push(user({ user_id: 91, full_name: name }));
    h.context.loadUsers = async () => {};
    h.context.openEditUserModal(73);
    h.nodes.get('edit-user-role').value = 'maintenance_staff';
    h.nodes.get('edit-user-department').value = '5';
    h.nodes.get('edit-user-designation').value = 'Updated designation';
    await h.nodes.get('confirm-edit-user-btn').emit('click');
    const request = h.requests.find((item) => item.method === 'PATCH');
    assert.ok(request);
    assert.equal(request.url, '/api/users/73');
    assert.equal(JSON.parse(request.body).full_name, name);
    assert.ok(h.context.getFullNameValidationError(name + ' changed', 'Name', name));
});

function pageFunction(file, name) {
    const contents = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/' + file), 'utf8');
    const match = contents.match(new RegExp('^function ' + name + '\\([^]*?^}', 'm'));
    assert.ok(match, name);
    return match[0];
}

function otherValidators() {
    const context = vm.createContext({});
    new vm.Script(pageFunction('index.php', 'normalizeSignupName') + '\n'
        + pageFunction('index.php', 'isValidFullName') + '\n'
        + pageFunction('account.php', 'normalizeAccountNameWhitespace') + '\n'
        + pageFunction('account.php', 'accountNameValidationError')).runInContext(context);
    return context;
}

for (const name of ['Peña, Ñora Jr.', 'Pen\u0303a, Jose\u0301', 'O’Neil-Santos, María Luisa III',
    "O'Neil, Anne-Marie", 'Juan Dela Cruz', 'Dela Cruz, Juan Jr.']) {
    test(`Add/Edit/Signup/Profile support ${name}`, () => {
        const h = harness();
        const validators = otherValidators();
        assert.equal(h.context.getFullNameValidationError(name, 'Name'), null);
        assert.equal(validators.isValidFullName(name), true);
        assert.equal(validators.accountNameValidationError(name, 'Old Name'), null);
        assert.equal(h.context.getRequiredNameValidationError('O’Neil-Santos', 'Last name'), null);
        assert.equal(h.context.getMiddleInitialValidationError('Ñ'), null);
    });
}

test('all frontend paths enforce the 255-character boundary without truncating', () => {
    const h = harness();
    const validators = otherValidators();
    const name = 'Ñ'.repeat(255);
    for (const [value, valid] of [[name, true], [name + 'Ñ', false]]) {
        assert.equal(h.context.getFullNameValidationError(value, 'Name') === null, valid);
        assert.equal(validators.isValidFullName(value), valid);
        assert.equal(validators.accountNameValidationError(value, 'Old Name') === null, valid);
    }
    assert.equal(h.context.normalizeCompleteName(name), name);
    const tooLongAfterNormalization = 'A'.repeat(252) + ' Jr';
    assert.ok(h.context.getFullNameValidationError(tooLongAfterNormalization, 'Name'));
    assert.equal(validators.isValidFullName(tooLongAfterNormalization), false);
    assert.ok(validators.accountNameValidationError(tooLongAfterNormalization, 'Old Name'));
    assert.equal(validators.accountNameValidationError('Legacy 123 <name> VI', 'Legacy 123 <name> VI'), null);
});

test('suffix rendering preserves UM-01 escaping and numeric-ID event delegation', () => {
    const h = harness();
    const name = '<img src=x onerror="globalThis.nameExecuted=true"> Jr.';
    const account = user({ full_name: name });
    h.select(account);
    const card = h.context.renderUserCard(account);
    assert.ok(!card.includes(name));
    assert.ok(card.includes(h.context.escapeHtml(name)));
    h.context.openEditUserModal(73);
    assert.equal(h.nodes.get('edit-user-full-name').value, name);
    assert.equal(h.context.nameExecuted, undefined);
    for (const button of card.matchAll(/<button\b[^>]*>/g)) {
        assert.ok(!button[0].includes('onclick='));
        assert.match(button[0], /data-user-id="73"/);
    }
});

