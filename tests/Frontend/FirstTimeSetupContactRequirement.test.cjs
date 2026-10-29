// Offline execution of trusted Account Settings handlers with DOM/API doubles.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/account.php'), 'utf8');
function cut(start, end) {
    const i = page.indexOf(start), j = page.indexOf(end, i);
    assert.ok(i >= 0 && j > i, start); return page.slice(i, j);
}
const contactState = cut('const PHONE_PATTERN', 'const originalAccountFullName');
const submit = cut("document.getElementById('account-settings-form').addEventListener", "if (<?php echo $forceProfileUpdate");
const verify = cut("verifyOtpBtn.addEventListener('click'", '// Resend OTP');

function harness({ forced = true, verified = '', phone = '' } = {}) {
    const nodes = new Map(), saves = [], messages = [], timers = [], requests = [];
    const get = (id) => {
        if (!nodes.has(id)) nodes.set(id, { value: '', textContent: '', style: {}, disabled: false, listeners: {},
            addEventListener(type, fn) { this.listeners[type] = fn; },
            classList: { add() {}, remove() {}, contains: () => false }, focus() {},
            setAttribute() {},
            querySelector: () => get('submit'),
        });
        return nodes.get(id);
    };
    get('full_name').value = 'Synthetic Setup User'; get('current_password').value = 'Temporary!123';
    get('new_password').value = 'Changed!456'; get('phone').value = phone;
    get('verified-email-display').textContent = verified || 'Not set';
    const context = vm.createContext({
        document: { getElementById: get, querySelector: () => null },
        forceProfileSetup: forced, accountNameValidationError: () => null, originalAccountFullName: 'Synthetic Setup User',
        showSettingsToast: (...args) => messages.push(args), renderHeaderAvatar() {}, renderSettingsAvatar() {},
        window: { SFMS_PUBLIC_URL: (url) => url }, setTimeout: (fn) => timers.push(fn), clearInterval() {}, console: { error() {} },
        FormData: class {
            constructor() { this.values = new Map(['full_name', 'username', 'current_password', 'new_password', 'phone'].map((id) => [id, get(id).value])); }
            set(key, value) { this.values.set(key, value); }
            append(key, value) { this.values.set(key, value); }
            get(key) { return this.values.get(key); }
        },
        verifyOtpBtn: get('verify-otp-btn'), sendOtpBtn: get('send-otp-btn'), newEmailInput: get('new-email'), otpCodeInput: get('otp-code'),
        verifiedEmailDisplay: get('verified-email-display'), emailForm: get('email-form'), otpForm: get('otp-form'),
        otpTimer: 0, hideEmailStatus() {}, showEmailStatus: (...args) => messages.push(args),
        fetch: async (url, options) => {
            if (url === '/api/users/profile') {
                saves.push(options.body);
                return { ok: true, json: async () => ({ success: true, data: { user: {}, requires_reauthentication: forced } }) };
            }
            requests.push({ url, ...options }); return { ok: true, json: async () => ({ success: true }) };
        },
    });
    new vm.Script(contactState + submit + verify).runInContext(context);
    return { context, get, saves, messages, timers, requests,
        save: () => get('account-settings-form').listeners.submit({ preventDefault() {} }) };
}

for (const [label, options] of [
    ['phone only', { phone: '9171234567' }], ['Gmail only', { verified: 'verified@gmail.com' }],
    ['Yahoo only', { verified: 'verified@yahoo.com' }], ['legacy verified email only', { verified: 'verified@school.example' }],
    ['both', { phone: '9171234567', verified: 'verified@gmail.com' }],
]) test(label + ' allows mandatory password-change save', async () => {
    const h = harness(options); await h.save();
    assert.equal(h.saves.length, 1);
    assert.equal(h.saves[0].get('phone'), options.phone ? '0' + options.phone : '');
    assert.equal(h.saves[0].get('new_password'), 'Changed!456'); assert.equal(h.timers.length, 1);
});

test('neither contact, typed email and a forged display badge cannot qualify', async () => {
    const h = harness(); h.get('new-email').value = 'typed@gmail.com';
    h.get('verified-email-display').textContent = 'forged@gmail.com';
    await h.save(); assert.equal(h.saves.length, 0);
    assert.match(h.messages.at(-1)[0], /at least one contact method/);
});

test('pending verification only becomes qualifying after the successful server verification response', async () => {
    const h = harness(); h.get('new-email').value = 'verified@gmail.com'; h.get('otp-code').value = '123456';
    await h.save(); assert.equal(h.saves.length, 0);
    await h.get('verify-otp-btn').listeners.click(); await h.save();
    assert.equal(h.requests.length, 1); assert.equal(h.saves.length, 1);
    assert.equal(h.get('verified-email-display').textContent, 'verified@gmail.com');
});

for (const verified of ['', 'verified@gmail.com']) test('nonempty invalid phone is rejected with verified email ' + Boolean(verified), async () => {
    const h = harness({ verified, phone: '8123456789' }); await h.save();
    assert.equal(h.saves.length, 0); assert.match(h.messages.at(-1)[0], /Phone number must be 10 digits/);
});

for (const field of ['current_password', 'new_password']) test('missing ' + field + ' still blocks forced setup', async () => {
    const h = harness({ phone: '9171234567' }); h.get(field).value = ''; await h.save();
    assert.equal(h.saves.length, 0); assert.match(h.messages.at(-1)[0], /current and new password are required/);
});

test('unchanged temporary password remains rejected', async () => {
    const h = harness({ phone: '9171234567' }); h.get('new_password').value = h.get('current_password').value;
    await h.save(); assert.equal(h.saves.length, 0); assert.match(h.messages.at(-1)[0], /different from your temporary password/);
});

test('ordinary profile editing retains optional contacts and optional password', async () => {
    const h = harness({ forced: false }); h.get('current_password').value = ''; h.get('new_password').value = '';
    await h.save(); assert.equal(h.saves.length, 1); assert.equal(h.timers.length, 0);
    assert.equal(h.messages.at(-1)[0], 'Account settings saved successfully!');
});

test('popup, labels, helper text and Gmail/Yahoo policy remain consistent; complete script compiles', () => {
    assert.match(page, /Change your temporary password and provide at least one contact method: Phone Number or Verified Email\./);
    assert.equal((page.match(/\(Phone or Verified Email required\)/g) || []).length, 2);
    assert.match(page, />For SMS alerts\. Enter a 10-digit Philippine mobile number\.<\/small>/);
    assert.match(page, /gmail\\\.com\|yahoo\\\.com/);
    new vm.Script(page.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, 'false'));
});
