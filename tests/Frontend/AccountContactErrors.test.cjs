// Offline DOM/fetch doubles execute the page's complete script; no browser or network.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const page = fs.readFileSync(path.resolve(__dirname, '../../public/frontend/pages/account.php'), 'utf8');
const script = page.match(/<script>\s*([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, 'false');
const phoneTaken = 'This phone number is already registered.';
const emailTaken = 'This email address is already registered.';

function harness() {
    const nodes = new Map(), requests = [], timers = [], focus = [], scrolled = [];
    const get = id => {
        if (!nodes.has(id)) {
            const attributes = new Map(), classes = new Set();
            nodes.set(id, { value: '', textContent: '', style: {}, hidden: false, disabled: false, readOnly: false, listeners: {},
                addEventListener(type, fn) { this.listeners[type] = fn; },
                setAttribute(name, value) { attributes.set(name, value); },
                removeAttribute(name) { attributes.delete(name); },
                getAttribute(name) { return attributes.get(name); },
                classList: { add: (...values) => values.forEach(value => classes.add(value)), remove: (...values) => values.forEach(value => classes.delete(value)), contains: value => classes.has(value) },
                focus() { focus.push(id); }, scrollIntoView(options) { scrolled.push({ id, options }); },
                querySelector() { return get('submit'); }, checkValidity() { return this.validity !== false; },
            });
        }
        return nodes.get(id);
    };
    get('full_name').value = 'Synthetic Contact User'; get('username').value = 'contact_user';
    get('phone').value = '9171234567'; get('verified-email-display').textContent = 'Not set';
    get('email-form').style.display = 'flex'; get('otp-form').style.display = 'none';
    for (const id of ['phone-error', 'email-error']) get(id).hidden = true;
    let next = { ok: true, data: { success: true, data: { user: {} } } };
    const context = vm.createContext({
        document: { getElementById: get, querySelector: () => null, querySelectorAll: () => [] },
        window: { SFMS_PUBLIC_URL: url => '/project' + url, addEventListener() {} },
        Session: { get: () => ({}), set() {} }, localStorage: { getItem: () => null, setItem() {} },
        console: { error() {} }, clearTimeout() {}, clearInterval() {},
        setTimeout: fn => { timers.push(fn); return timers.length; }, setInterval: () => 1,
        requestAnimationFrame: fn => fn(), URL: { createObjectURL: () => 'offline-avatar' },
        fetch: async (url, options) => {
            requests.push({ url, ...options });
            if (next.error) throw next.error;
            return { ok: next.ok, json: async () => next.data };
        },
        FormData: class {
            constructor() { this.values = new Map(['full_name', 'username', 'phone', 'current_password', 'new_password', 'profile_picture'].map(id => [id, get(id).value])); }
            set(key, value) { this.values.set(key, value); } append(key, value) { this.values.set(key, value); }
            get(key) { return this.values.get(key); }
        },
    });
    new vm.Script(script, { filename: 'account-inline.js' }).runInContext(context);
    return { get, context, requests, timers, focus, scrolled,
        reply(data, ok = false) { next = { data, ok }; }, fail(error) { next = { error }; },
        save: () => get('account-settings-form').listeners.submit({ preventDefault() {} }),
        click: id => get(id).listeners.click(), input: id => get(id).listeners.input(),
    };
}

test('plain duplicate phone error persists inline with accessible red state and focused input, without duplicate toast', async () => {
    const h = harness(); h.reply({ success: false, message: phoneTaken }); await h.save();
    assert.equal(h.get('phone-error').textContent, phoneTaken); assert.equal(h.get('phone-error').hidden, false);
    assert.equal(h.get('phone').getAttribute('aria-invalid'), 'true');
    assert.equal(h.get('phone-control').classList.contains('has-contact-error'), true);
    assert.equal(h.focus.at(-1), 'phone'); assert.equal(h.scrolled.at(-1).id, 'phone');
    assert.equal(h.get('settings-toast').textContent, ''); assert.equal(h.get('submit').disabled, false);
    h.timers.forEach(fn => fn()); assert.equal(h.get('phone-error').textContent, phoneTaken);
});

for (const nested of [false, true]) test('structured contact validation accepts ' + (nested ? 'data.errors' : 'errors') + ' and focuses first field in page order', async () => {
    const h = harness(); const errors = { email: [emailTaken], phone: [phoneTaken] };
    h.reply({ success: false, message: 'Validation error', ...(nested ? { data: { errors } } : { errors }) }); await h.save();
    assert.equal(h.get('phone-error').textContent, phoneTaken); assert.equal(h.get('email-error').textContent, emailTaken);
    assert.equal(h.focus.at(-1), 'phone'); assert.equal(h.get('settings-toast').textContent, '');
});

test('input changes clear only their corresponding error and preserve phone digit normalization', async () => {
    const h = harness(); h.reply({ errors: { phone: [phoneTaken], email: [emailTaken] } }); await h.save();
    h.get('phone').value = '917abc123456789'; h.input('phone');
    assert.equal(h.get('phone').value, '9171234567'); assert.equal(h.get('phone-error').hidden, true);
    assert.equal(h.get('phone').getAttribute('aria-invalid'), 'false'); assert.equal(h.get('email-error').hidden, false);
    assert.equal(h.get('phone-control').classList.contains('has-contact-error'), false);
    h.input('new-email'); assert.equal(h.get('email-error').hidden, true);
});

test('profile success clears phone error only, retains success toast and original multipart payload', async () => {
    const h = harness(); h.reply({ errors: { phone: [phoneTaken], email: [emailTaken] } }); await h.save();
    h.get('profile_picture').value = 'synthetic-upload'; h.reply({ success: true, data: { user: {} } }, true); await h.save();
    const request = h.requests.at(-1);
    assert.equal(request.url, '/project/api/users/profile'); assert.equal(request.method, 'POST'); assert.equal(request.credentials, 'include');
    assert.equal(request.body.get('_method'), 'PATCH'); assert.equal(request.body.get('phone'), '09171234567');
    assert.equal(request.body.get('profile_picture'), 'synthetic-upload');
    assert.equal(h.get('phone-error').hidden, true); assert.equal(h.get('email-error').hidden, false);
    assert.equal(h.get('settings-toast').textContent, 'Account settings saved successfully!');
});

for (const action of ['send-otp-btn', 'verify-otp-btn', 'resend-otp-btn']) test(action + ' duplicate email is inline, leaves OTP state intact and has no redundant notification', async () => {
    const h = harness(); h.get('new-email').value = 'synthetic@gmail.com'; h.get('otp-code').value = '123456';
    if (action !== 'send-otp-btn') { h.get('email-form').style.display = 'none'; h.get('otp-form').style.display = 'block'; }
    h.reply({ success: false, message: emailTaken }); await h.click(action);
    assert.equal(h.requests.length, 1); assert.equal(h.get('email-error').textContent, emailTaken);
    assert.equal(h.get('new-email').getAttribute('aria-invalid'), 'true'); assert.equal(h.focus.at(-1), 'new-email');
    assert.equal(h.get('email-form').style.display, 'flex'); assert.equal(h.get('email-status').style.display, 'none');
    assert.equal(h.get('settings-toast').textContent, ''); assert.equal(h.get(action).disabled, false);
    assert.equal(h.get('otp-code').value, '123456');
    if (action !== 'send-otp-btn') {
        assert.equal(h.get('otp-form').style.display, 'block'); assert.equal(h.get('new-email').readOnly, true);
        assert.equal(h.get('send-otp-btn').hidden, true);
        await h.click('cancel-otp-btn'); assert.equal(h.requests.length, 1); assert.equal(h.get('new-email').readOnly, false);
        assert.equal(h.get('send-otp-btn').hidden, false); assert.equal(h.get('email-error').hidden, true);
    }
});

test('structured OTP email error renders as text, including HTML-sensitive input', async () => {
    const h = harness(); const text = '<img src=x onerror="globalThis.executed=true"> & "quoted"';
    h.get('new-email').value = 'synthetic@gmail.com'; h.reply({ message: 'Validation error', errors: { email: [text] } });
    await h.click('send-otp-btn'); assert.equal(h.get('email-error').textContent, text); assert.equal(h.context.executed, undefined);
    assert.doesNotMatch(script.slice(script.indexOf('function showAccountContactErrors'), script.indexOf('async function updateAccountProfile')), /innerHTML/);
});

test('email verification success clears only email error and preserves verified status and success notification', async () => {
    const h = harness(); h.get('new-email').value = 'synthetic@yahoo.com'; h.get('otp-code').value = '123456';
    h.reply({ message: emailTaken }); await h.click('verify-otp-btn');
    h.context.showAccountContactErrors({ errors: { phone: [phoneTaken] } });
    h.reply({ success: true }, true); await h.click('verify-otp-btn');
    assert.equal(h.get('email-error').hidden, true); assert.equal(h.get('phone-error').hidden, false);
    assert.equal(h.get('verified-email-display').textContent, 'synthetic@yahoo.com');
    assert.equal(h.get('settings-toast').textContent, 'Email verified successfully!'); assert.equal(h.get('new-email').readOnly, false);
    assert.equal(h.get('otp-form').style.display, 'none');
});

test('general save, password, network and OTP errors keep existing feedback', async () => {
    const h = harness(); h.reply({ success: false, message: 'Current password is incorrect' }); await h.save();
    assert.equal(h.get('settings-toast').textContent, 'Current password is incorrect'); assert.equal(h.get('phone-error').hidden, true);
    h.fail(new Error('Offline network failure')); await h.save(); assert.equal(h.get('settings-toast').textContent, 'Offline network failure');
    h.get('new-email').value = 'synthetic@gmail.com'; h.get('otp-code').value = '123456';
    h.reply({ message: 'Incorrect OTP. 2 attempts remaining.' }); await h.click('verify-otp-btn');
    assert.equal(h.get('email-status').textContent, 'Incorrect OTP. 2 attempts remaining.'); assert.equal(h.get('email-error').hidden, true);
});

test('mixed validation retains unrelated password feedback without repeating the contact error in a toast', async () => {
    const h = harness(); h.reply({ message: 'Validation error', errors: { phone: [phoneTaken], new_password: ['Password must contain at least eight characters.'] } });
    await h.save(); assert.equal(h.get('phone-error').textContent, phoneTaken);
    assert.equal(h.get('settings-toast').textContent, 'Password must contain at least eight characters.');
    assert.equal(h.focus.at(-1), 'new_password');
});

test('client-side save validation focuses and scrolls the invalid field without making a request', async () => {
    const h = harness(); h.get('phone').value = '123'; await h.save();
    assert.equal(h.requests.length, 0); assert.equal(h.focus.at(-1), 'phone'); assert.equal(h.scrolled.at(-1).id, 'phone');
    h.get('full_name').value = ''; await h.save(); assert.equal(h.focus.at(-1), 'full_name');
});

for (const action of ['send-otp-btn', 'resend-otp-btn']) test(action + ' success clears email error only and retains the OTP workflow', async () => {
    const h = harness(); h.get('new-email').value = 'synthetic@gmail.com';
    h.context.showAccountContactErrors({ errors: { phone: [phoneTaken], email: [emailTaken] } });
    h.reply({ success: true }, true); await h.click(action);
    assert.equal(h.get('email-error').hidden, true); assert.equal(h.get('phone-error').hidden, false);
    assert.equal(h.get(action).disabled, false); assert.equal(h.requests.length, 1);
    if (action === 'send-otp-btn') assert.equal(h.get('otp-form').style.display, 'block');
    else assert.equal(h.get('settings-toast').textContent, 'New OTP sent');
});

test('markup links inline errors, retains helper text and bounds mobile wrapping; full JavaScript compiles', () => {
    const markup = page.replace(/<\?php[\s\S]*?\?>/g, '');
    assert.match(markup, /id="phone"[^>]*aria-invalid="false"[^>]*aria-describedby="phone-help phone-error"/);
    assert.match(markup, /id="new-email"[^>]*aria-invalid="false"[^>]*aria-describedby="email-error"/);
    for (const id of ['phone-error', 'email-error']) assert.match(page, new RegExp('id="' + id + '"[^>]*role="alert"[^>]*aria-live="polite"[^>]*hidden'));
    assert.match(page, /For SMS alerts\. Enter a 10-digit Philippine mobile number\./);
    assert.match(page, /overflow-wrap: anywhere/); assert.match(page, /#email-form \{ flex-wrap: wrap/);
    new vm.Script(script);
});
