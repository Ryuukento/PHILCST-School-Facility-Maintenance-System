// Offline renderer checks and a pure PHP helper with synthetic temp files only.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const account = fs.readFileSync(path.join(root, 'public/frontend/pages/account.php'), 'utf8');
const users = fs.readFileSync(path.join(root, 'public/frontend/pages/users.php'), 'utf8');
const sidebar = fs.readFileSync(path.join(root, 'public/frontend/includes/sidebar.php'), 'utf8');
const base = '/School_Facility_Maintenance_System';
function extract(text, start, end) { return text.slice(text.indexOf(start), text.indexOf(end, text.indexOf(start))); }
function context() {
    const nodes = new Map();
    const get = id => {
        if (!nodes.has(id)) nodes.set(id, { style: {}, textContent: '', src: '', removed: [],
            removeAttribute(name) { this.removed.push(name); if (name === 'src') this.src = ''; },
            setAttribute() {},
        });
        return nodes.get(id);
    };
    const c = vm.createContext({ window: { SFMS_PUBLIC_URL: url => base + url },
        document: { getElementById: get, querySelector: () => null } });
    new vm.Script(extract(account, 'function normalizeAccountAvatarUrl(', "document.getElementById('profile_picture')")
        + extract(users, 'function normalizeAvatarUrl(', 'function getRoleLabel(')
        + extract(users, 'function buildAvatarMarkup(', 'function normalizeAvatarUrl(')
        + 'function escapeHtml(value) { return String(value).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\x27/g,"&#39;"); }').runInContext(c);
    return { c, get };
}

for (const input of ['frontend/assets/uploads/avatars/test.png', '/frontend/assets/uploads/avatars/test.png',
    base + '/frontend/assets/uploads/avatars/test.png', '/public/frontend/assets/uploads/avatars/test.png']) {
    test('normalizes local avatar without doubling the base: ' + input, () => {
        const { c } = context(); const expected = base + '/frontend/assets/uploads/avatars/test.png';
        assert.equal(c.normalizeAccountAvatarUrl(input), expected); assert.equal(c.normalizeAvatarUrl(input), expected);
    });
}
for (const input of ['https://example.test/avatar.png?size=56', '//example.test/avatar.png', '/other/images/picture.png', 'other/images/picture.png']) {
    test('preserves external or unrelated asset path: ' + input, () => {
        const { c } = context(); assert.equal(c.normalizeAccountAvatarUrl(input), input); assert.equal(c.normalizeAvatarUrl(input), input);
    });
}

test('blank avatars and Unicode names produce safe initials', () => {
    const { c } = context();
    assert.equal(c.normalizeAvatarUrl(null), ''); assert.equal(c.getInitials('Ñora Peña'), 'ÑP');
    assert.equal(c.getInitials(''), 'U');
    const markup = c.buildAvatarMarkup({ full_name: 'Ñora Peña', avatar: null });
    assert.match(markup, /ÑP/); assert.doesNotMatch(markup, /<img/);
    assert.equal(c.normalizeAvatarUrl('javascript:alert(1)'), '');
});

test('valid image, load failure, absent avatar and later upload reuse persistent image/fallback nodes', () => {
    const { c, get } = context();
    c.renderSettingsAvatar({ full_name: 'Synthetic User', avatar: '/frontend/assets/uploads/avatars/test.png' });
    const image = get('settings-avatar-preview'), fallback = get('settings-avatar-preview-fallback');
    assert.equal(image.src, base + '/frontend/assets/uploads/avatars/test.png');
    assert.equal(image.style.display, 'block'); assert.equal(fallback.style.display, 'none');
    image.onerror(); assert.equal(image.style.display, 'none'); assert.equal(fallback.style.display, 'flex'); assert.equal(fallback.textContent, 'SU');
    c.renderSettingsAvatar({ full_name: 'Synthetic User', avatar: null });
    assert.ok(image.removed.includes('src')); assert.equal(image.src, ''); assert.equal(fallback.style.display, 'flex');
    c.renderSettingsAvatar({ full_name: 'Synthetic User', avatar: 'blob:offline-new-upload' });
    assert.equal(image.src, 'blob:offline-new-upload'); assert.equal(image.style.display, 'block'); assert.equal(fallback.style.display, 'none');
    c.renderAccountAvatar('header-user-avatar', 'header-user-avatar-fallback', { full_name: 'Synthetic User', avatar: 'https://example.test/avatar.png' });
    get('header-user-avatar').onerror(); assert.equal(get('header-user-avatar-fallback').textContent, 'SU');
});

test('rendering does not turn sensitive names or URLs into executable HTML', () => {
    const { c, get } = context(); const name = '<img src=x onerror=alert(1)> User';
    c.renderSettingsAvatar({ full_name: name, avatar: 'https://example.test/"onerror="bad' });
    assert.equal(get('settings-avatar-preview').src, 'https://example.test/"onerror="bad');
    assert.equal(get('settings-avatar-preview-fallback').textContent, '<S');
    assert.doesNotMatch(extract(account, 'function renderAccountAvatar(', "document.getElementById('profile_picture')"), /innerHTML|outerHTML/);
    const markup = c.buildAvatarMarkup({ full_name: name, avatar: 'https://example.test/"onerror="bad' });
    assert.ok(markup.includes('&quot;')); assert.ok(!markup.includes('alt="' + name));
});

test('User Management image load failure shows existing initials in both avatar variants', () => {
    const { c } = context();
    for (const fn of ['buildAvatarMarkup', 'buildCardMediaMarkup']) {
        const markup = c[fn]({ full_name: 'Synthetic User', avatar: '/frontend/assets/uploads/avatars/missing.png' });
        const handler = markup.match(/onerror="([^"]+)"/)[1];
        const image = { style: {}, nextElementSibling: { style: {} } };
        new vm.Script('(function(){' + handler + '}).call(image)').runInNewContext({ image });
        assert.equal(image.style.display, 'none'); assert.equal(image.nextElementSibling.style.display, 'flex');
        assert.ok(markup.includes('SU'));
    }
});

test('PHP helper suppresses missing local requests, preserves valid uploads/external URLs and rejects traversal', () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'sfms-avatar-offline-'));
    try {
        const uploadDir = path.join(temp, 'frontend/assets/uploads/avatars'); fs.mkdirSync(uploadDir, { recursive: true });
        fs.writeFileSync(path.join(uploadDir, 'valid.png'), Buffer.from('iVBORw0KGgo=', 'base64'));
        const php = "function public_url($p){return '/School_Facility_Maintenance_System'.$p;} require "
            + JSON.stringify(path.join(root, 'public/frontend/includes/avatar.php').replaceAll('\\', '/')) + ';'
            + '$root=' + JSON.stringify(temp.replaceAll('\\', '/')) + ';'
            + '$out=[]; foreach([null,"/frontend/assets/uploads/avatars/valid.png","frontend/assets/uploads/avatars/valid.png","/School_Facility_Maintenance_System/frontend/assets/uploads/avatars/valid.png","/frontend/assets/uploads/avatars/missing.png","https://example.test/avatar.png","/other/picture.png","/frontend/assets/uploads/avatars/%2e%2e%2foutside.png"] as $value){$out[]=sfms_avatar_url($value,$root);} $out[]=sfms_avatar_initials("Ñora Peña"); echo json_encode($out);';
        const result = spawnSync('C:/xampp/php/php.exe', ['-d', 'allow_url_fopen=0', '-r', php], { encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(JSON.parse(result.stdout), ['', base + '/frontend/assets/uploads/avatars/valid.png', base + '/frontend/assets/uploads/avatars/valid.png',
            base + '/frontend/assets/uploads/avatars/valid.png', '', 'https://example.test/avatar.png', '/other/picture.png', '', 'ÑP']);
    } finally {
        assert.equal(path.dirname(path.resolve(temp)), path.resolve(os.tmpdir()));
        assert.match(path.basename(temp), /^sfms-avatar-offline-/);
        fs.rmSync(temp, { recursive: true, force: true });
    }
});

test('server renderers use file-checked URLs, omit src for missing avatars and always include escaped initials', () => {
    for (const [text, variable] of [[sidebar, 'sidebarAvatarUrl'], [account, 'settingsAvatarUrl']]) {
        assert.ok(text.includes(variable + ' = sfms_avatar_url('));
        assert.ok(text.includes('if ($' + variable + " !== ''): ?>src="));
        assert.ok(text.includes('htmlspecialchars(sfms_avatar_initials('));
        assert.ok(text.includes("this.style.display='none';this.nextElementSibling.style.display='flex';"));
    }
    assert.doesNotMatch(sidebar, /data-user-avatar id="header-user-avatar-fallback"/);
    for (const text of [account, users]) new vm.Script(text.replace(/<!--[\s\S]*?-->/g, '').replace(/<\?php[\s\S]*?\?>/g, 'false').match(/<script>\s*([\s\S]*?)<\/script>/)[1]);
});
