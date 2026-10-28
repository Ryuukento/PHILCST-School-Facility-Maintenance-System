// Offline execution of trusted page handlers with DOM/fetch doubles, not browser execution.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = (name) => fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/' + name + '.php'), 'utf8');
const supplier = read('suppliers-manage');
const account = read('account');

function between(source, start, end) {
    const from = source.indexOf(start);
    assert.ok(from >= 0, start);
    const to = source.indexOf(end, from);
    assert.ok(to > from, end);
    return source.slice(from, to);
}

function harness(kind) {
    const nodes = new Map();
    const requests = [];
    const alerts = [];
    const node = (id) => {
        if (!nodes.has(id)) nodes.set(id, {
            value: '', style: {}, disabled: false, listeners: {}, textContent: '',
            addEventListener(name, callback) { this.listeners[name] = callback; },
            // A DOM double; native browser constraint validation is not exercised here.
            checkValidity() { return this.validity !== false; },
            reset() { for (const item of nodes.values()) item.value = ''; },
            focus() {},
        });
        return nodes.get(id);
    };
    const context = vm.createContext({
        document: { getElementById: node },
        Components: { alert: (message) => alerts.push(message), toast() {} },
        window: { SFMS_PUBLIC_URL: (url) => url },
        console: { error() {} },
        fetch: async (url, options) => {
            requests.push({ url, ...options });
            return { ok: true, json: async () => ({ success: true, message: 'OK' }) };
        },
        apiPrefix: '/api/suppliers', originalSupplierEmail: '',
        setSupplierStatus() {}, fetchSuppliers() {},
        sendOtpBtn: node('send-otp-btn'), newEmailInput: node('new-email'),
        emailForm: node('email-form'), otpForm: node('otp-form'),
        otpEmailDisplay: node('otp-email-display'), otpCodeInput: node('otp-code'),
        hideEmailStatus() {}, showEmailStatus: (message) => alerts.push(message), startOtpTimer() {},
    });
    const source = kind === 'supplier'
        ? between(supplier, 'function supplierEmailAllowed(', '// Reorder Previous Receipt:')
            + between(supplier, 'function resetForm()', "document.getElementById('clear').addEventListener")
        : between(account, '// Send OTP', '// Verify OTP');
    new vm.Script(source).runInContext(context);
    return {
        nodes, node, requests, alerts, context,
        submit: async () => kind === 'supplier'
            ? node('form').listeners.submit({ preventDefault() {} })
            : node('send-otp-btn').listeners.click(),
    };
}

for (const kind of ['supplier', 'account']) {
    for (const email of ['person@gmail.com', 'person@yahoo.com', 'person@GMAIL.COM', 'person@YaHoO.CoM']) {
        test(kind + ' accepts exact allowed domain: ' + email, async () => {
            const h = harness(kind);
            h.node(kind === 'supplier' ? 'contact_email' : 'new-email').value = email;
            await h.submit();
            assert.equal(h.requests.length, 1);
            const payload = JSON.parse(h.requests[0].body);
            assert.equal(payload[kind === 'supplier' ? 'contact_email' : 'email'], email);
            assert.equal(h.requests[0].method, 'POST');
            if (kind === 'account') {
                assert.equal(h.node('otp-form').style.display, 'block');
                assert.equal(h.node('otp-email-display').textContent, email);
                assert.equal(h.node('send-otp-btn').disabled, false);
            }
        });
    }
    for (const email of ['a@outlook.com', 'a@hotmail.com', 'a@gmail.com.ph', 'a@yahoo.co', 'a@gmail.com.evil.com', 'a@sub.gmail.com', 'invalid', 'a@@gmail.com', 'a b@gmail.com', 'a'.repeat(246) + '@gmail.com']) {
        test(kind + ' blocks unsupported/invalid input before fetch: ' + email.slice(0, 35), async () => {
            const h = harness(kind);
            h.node(kind === 'supplier' ? 'contact_email' : 'new-email').value = email;
            await h.submit();
            assert.equal(h.requests.length, 0);
            assert.match(h.alerts[0], /gmail\.com or yahoo\.com/);
        });
    }
    test(kind + ' retains native syntax validation', async () => {
        const h = harness(kind);
        const input = h.node(kind === 'supplier' ? 'contact_email' : 'new-email');
        input.value = 'a@gmail.com'; input.validity = false;
        await h.submit();
        assert.equal(h.requests.length, 0);
    });
    test(kind + ' retains 255-character maximum', async () => {
        const h = harness(kind);
        h.node(kind === 'supplier' ? 'contact_email' : 'new-email').value = 'a'.repeat(245) + '@gmail.com';
        await h.submit();
        assert.equal(h.requests.length, 1);
    });
}

test('supplier email remains optional', async () => {
    const h = harness('supplier');
    await h.submit();
    assert.equal(h.requests.length, 1);
    assert.equal(JSON.parse(h.requests[0].body).contact_email, '');
});

test('unchanged legacy supplier email permits unrelated edit; changed unsupported email is blocked', async () => {
    const h = harness('supplier');
    h.node('supplier_id').value = '73';
    h.node('contact_email').value = 'legacy@outlook.com';
    h.context.originalSupplierEmail = 'legacy@outlook.com';
    await h.submit();
    assert.equal(h.requests[0].url, '/api/suppliers/73');
    assert.equal(h.requests[0].method, 'PATCH');
    assert.equal(JSON.parse(h.requests[0].body).contact_email, 'legacy@outlook.com');
    // Successful submission resets the form and the trusted original-email snapshot.
    assert.equal(h.context.originalSupplierEmail, '');
    h.node('supplier_id').value = '73';
    h.node('contact_email').value = 'different@outlook.com';
    await h.submit();
    assert.equal(h.requests.length, 1);
    assert.match(h.alerts[0], /gmail\.com or yahoo\.com/);
});

test('edit loads the original email from the server response', () => {
    assert.match(supplier, /originalSupplierEmail = s\.contact_email \|\| '';/);
});

test('account empty email is rejected before fetch', async () => {
    const h = harness('account');
    await h.submit();
    assert.equal(h.requests.length, 0);
    assert.match(h.alerts[0], /Please enter an email address/);
});

test('both complete inline scripts compile after replacing server-rendered constants', () => {
    for (const page of [supplier, account]) {
        const scripts = [...page.replace(/<!--[\s\S]*?-->/g, '').matchAll(/<script>\s*([\s\S]*?)<\/script>/g)];
        assert.ok(scripts.length > 0);
        for (const script of scripts) {
            const source = script[1]
                .replace(/<\?php echo json_encode\(public_url\('[^']+'\)\); \?>/g, '"/offline"')
                .replace(/<\?php echo \$forceProfileUpdate \? 'true' : 'false'; \?>/g, 'false');
            assert.ok(!source.includes('<?php'));
            new vm.Script(source);
        }
    }
    assert.match(account, /id="new-email"[^>]*maxlength="255"/);
    assert.match(supplier, /id="contact_email"[^>]*maxlength="255"/);
});
