// Offline execution of the real navigation statements; no browser/network/database.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const login = fs.readFileSync(path.join(root, 'public/frontend/pages/index.php'), 'utf8');
const account = fs.readFileSync(path.join(root, 'public/frontend/pages/account.php'), 'utf8');
const labels = fs.readFileSync(path.join(root, 'public/frontend/assets/js/dispatch-labels.js'), 'utf8');
function destinations(value) {
    const php = `require 'app/Support/ScannedDispatchDestination.php'; $id=json_decode($argv[1], true); echo json_encode([App\\Support\\ScannedDispatchDestination::destination($id), App\\Support\\ScannedDispatchDestination::carry('/login?password_changed=1', $id)]);`;
    return JSON.parse(execFileSync('C:/xampp/php/php.exe', ['-r', php, JSON.stringify(value)], { cwd: root, encoding: 'utf8' }));
}
function loginNavigation(destination) {
    const start = login.indexOf('setTimeout(() => {', login.indexOf('const response = await window.API.login'));
    const end = login.indexOf('}, 500);', start);
    assert.ok(start > 0 && end > start);
    return login.slice(start, end + '}, 500);'.length).replace(/<\?php[\s\S]*?\?>/g, JSON.stringify('/School_Facility_Maintenance_System' + destination));
}
for (const [reference, expected] of [[6, '/dispatches/6'], ['2147483647', '/dispatches/2147483647'],
    [null, '/dashboard'], ['', '/dashboard'], ['//evil.example', '/dashboard'], ['https://evil.example', '/dashboard'],
    [['6'], '/dashboard'], ['6/approve', '/dashboard'], ['%2f%2fevil.example', '/dashboard']]) {
    test(`login navigation for ${JSON.stringify(reference)}`, () => {
        const [destination] = destinations(reference);
        assert.equal(destination, expected);
        const window = { location: { href: '' } };
        vm.runInNewContext(loginNavigation(destination), { window, setTimeout: fn => fn() });
        assert.equal(window.location.href, '/School_Facility_Maintenance_System' + expected);
    });
}
test('forced setup reauthentication keeps the reference after all session data is gone', () => {
    const [, relogin] = destinations('6');
    const statement = account.split(/\r?\n/).find(line => line.includes('window.location.href = window.SFMS_PUBLIC_URL'));
    assert.ok(statement);
    const window = { SFMS_PUBLIC_URL: p => '/School_Facility_Maintenance_System' + p, location: {} };
    vm.runInNewContext(statement, { window, scannedDispatchReloginPath: relogin });
    assert.equal(window.location.href, '/School_Facility_Maintenance_System/login?password_changed=1&next_dispatch=6');
    assert.equal(destinations('6')[0], '/dispatches/6');
});
test('navigation does not read localStorage.user and a blocked user cache is nonfatal', () => {
    const statement = login.split(/\r?\n/).find(line => line.includes("try { window.Session.set('user'"));
    assert.ok(statement);
    const window = { Session: { set() { throw new Error('Storage unavailable'); } }, location: {} };
    vm.runInNewContext(statement + loginNavigation('/dispatches/6'), {
        window, response: { data: { user: {} } }, setTimeout: fn => fn(),
    });
    assert.equal(window.location.href, '/School_Facility_Maintenance_System/dispatches/6');
});
test('printed QR payload remains the existing dispatch URL', () => {
    assert.ok(labels.includes('qrSvg(publicUrl(`/dispatches/${dispatch.id}`))'));
    assert.ok(!labels.includes('next_dispatch'));
});
test('explicit logout has no carried reference or persistent redirect state', () => {
    const logout = fs.readFileSync(path.join(root, 'public/frontend/pages/logout.php'), 'utf8');
    assert.ok(logout.includes("public_url('/login')"));
    assert.ok(!logout.includes('next_dispatch'));
    assert.ok(!loginNavigation('/dispatches/6').includes('localStorage'));
});
test('both page scripts remain syntactically valid with PHP output substituted', () => {
    for (const source of [login, account]) {
        const withoutPhp = source.replace(/<\?php[\s\S]*?\?>/g, 'null');
        for (const match of withoutPhp.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
            new vm.Script(match[1]);
        }
    }
});
