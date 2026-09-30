<?php
/**
 * SFMS production deployment check — TEMPORARY, READ-ONLY.
 *
 * Upload to public_html/ (the folder that contains vendor/, bootstrap/,
 * storage/ and .env), open:
 *     https://philcstmaintenance.com/sfms-deploy-check.php?token=a3580c3de96921f018a9004b34ab5022
 * copy the output, then DELETE this file from the server.
 *
 * It changes nothing: no writes except a tiny probe file in storage/ and
 * bootstrap/cache (created and removed immediately), no passwords printed.
 */

const SFMS_CHECK_TOKEN = 'a3580c3de96921f018a9004b34ab5022';
// Shown by every mode, so you can confirm the uploaded copy is this version.
const SFMS_CHECK_VERSION = 'sfms-deploy-check v3 (2026-09-27, mode=http + included files + fingerprints)';

if (!hash_equals(SFMS_CHECK_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$probeFile = $root . '/storage/framework/sfms-http-probe.txt';

/*
 * mode=http — replay a real API request through the SAME entry point the
 * web server uses (root index.php -> settings.php -> public/index.php),
 * and record what happened (fatal error, headers, output) to a file.
 * The response of this request itself may come back empty — that is the
 * bug being investigated. Then open mode=show to read the record.
 */
if (($_GET['mode'] ?? '') === 'http') {
    $path = (string) ($_GET['path'] ?? '/api/auth/check');
    if (!preg_match('#^/api/[a-z0-9/_-]+$#i', $path)) {
        $path = '/api/auth/check';
    }
    @unlink($probeFile);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['QUERY_STRING'] = '';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    $_GET = [];

    register_shutdown_function(static function () use ($probeFile, $path): void {
        $output = '';
        while (ob_get_level() > 0) {
            $output = ob_get_clean() . $output;
        }
        $error = error_get_last();

        // Where execution actually got to: the last files PHP loaded.
        $root = dirname($probeFile, 3);
        $included = array_map(static fn ($f) => str_replace('\\', '/', substr($f, strlen($root))), get_included_files());
        $reachedLaravel = in_array('/bootstrap/app.php', $included, true);

        // Fingerprints of the entry files as they exist on THIS server
        // (sha256 prefix, computed on the raw file and with CRLF -> LF), to
        // compare with the repository copies.
        $fingerprints = [];
        foreach (['index.php', '.htaccess', 'public/index.php', 'public/.htaccess', 'public/backend/config/settings.php', 'bootstrap/app.php', 'routes/web.php'] as $f) {
            $p = $root . '/' . $f;
            if (!is_file($p)) {
                $fingerprints[] = $f . '=missing';
                continue;
            }
            $raw = (string) file_get_contents($p);
            $fingerprints[] = sprintf('%s=%s/%s (%d bytes)', $f, substr(hash('sha256', $raw), 0, 10), substr(hash('sha256', str_replace("\r\n", "\n", $raw)), 0, 10), strlen($raw));
        }

        $report = [
            'version' => SFMS_CHECK_VERSION,
            'time' => date('c'),
            'path' => $path,
            'php' => PHP_VERSION . ' / ' . PHP_SAPI,
            'last_error' => $error ? sprintf('[type %d] %s in %s:%d', $error['type'], $error['message'], $error['file'], $error['line']) : '(none)',
            'http_status' => (string) http_response_code(),
            'headers' => preg_replace('/(Set-Cookie:\s*[^=]+=)[^;|]*/i', '$1<redacted>', implode(' | ', headers_list())),
            'output_length' => strlen($output),
            'output_start' => substr($output, 0, 500),
            'reached_laravel' => $reachedLaravel ? 'yes (bootstrap/app.php was loaded)' : 'NO (bootstrap/app.php never loaded)',
            'included_count' => count($included),
            'first_includes' => implode(' , ', array_slice($included, 0, 8)),
            'last_includes' => implode(' , ', array_slice($included, -12)),
            'fingerprints' => implode(' | ', $fingerprints),
        ];
        $text = '';
        foreach ($report as $k => $v) {
            $text .= str_pad($k, 14) . ': ' . $v . PHP_EOL;
        }
        @file_put_contents($probeFile, $text);
        echo PHP_EOL . '--- probe recorded; open mode=show ---' . PHP_EOL;
    });

    error_clear_last();
    ob_start();
    require $root . '/index.php';
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

if (($_GET['mode'] ?? '') === 'show') {
    echo SFMS_CHECK_VERSION . PHP_EOL . PHP_EOL;
    echo is_file($probeFile) ? file_get_contents($probeFile) : 'No probe recorded yet — open mode=http first.' . PHP_EOL;
    @unlink($probeFile);
    exit;
}

ini_set('display_errors', '1');
error_reporting(E_ALL);
$line = static function (string $label, $value): void {
    echo str_pad($label, 34) . ': ' . (is_bool($value) ? ($value ? 'yes' : 'NO') : $value) . PHP_EOL;
};
$section = static function (string $title): void {
    echo PHP_EOL . '== ' . $title . ' ' . str_repeat('=', max(3, 60 - strlen($title))) . PHP_EOL;
};

// Report any fatal that happens while this script runs (e.g. during boot).
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo PHP_EOL . '!! FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . PHP_EOL;
    }
});

echo SFMS_CHECK_VERSION . PHP_EOL;

$section('PHP');
$line('PHP version', PHP_VERSION);
$line('SAPI', PHP_SAPI);
$line('memory_limit', ini_get('memory_limit'));
$line('disable_functions', ini_get('disable_functions') ?: '(none)');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'bcmath', 'curl'] as $ext) {
    $line('ext ' . $ext, extension_loaded($ext));
}

$section('Files');
$line('project root', $root);
foreach (['vendor/autoload.php', 'vendor/composer/installed.json', 'bootstrap/app.php', 'public/index.php', 'routes/web.php', '.env', 'index.php', '.htaccess'] as $f) {
    $line($f, is_file("$root/$f"));
}

$section('Writable folders');
foreach (['storage', 'storage/logs', 'storage/framework', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/cache/data', 'bootstrap/cache'] as $d) {
    $path = "$root/$d";
    $ok = is_dir($path) && is_writable($path);
    if ($ok) {
        $probe = $path . '/.sfms_probe_' . bin2hex(random_bytes(3));
        $ok = @file_put_contents($probe, 'x') !== false;
        @unlink($probe);
    }
    $line($d, is_dir($path) ? ($ok ? 'exists, writable' : 'exists, NOT writable') : 'MISSING');
}

$section('bootstrap/cache contents');
foreach (glob("$root/bootstrap/cache/*.php") ?: [] as $f) {
    $content = (string) @file_get_contents($f);
    $windowsPaths = preg_match('#[A-Z]:\\\\\\\\|[A-Z]:/(Users|xampp)#', $content) === 1;
    $line(basename($f), filesize($f) . ' bytes' . ($windowsPaths ? '  <-- CONTAINS WINDOWS PATHS (built on your PC)' : ''));
}

$section('.env (values hidden)');
$env = [];
if (is_file("$root/.env")) {
    foreach (file("$root/.env", FILE_IGNORE_NEW_LINES) as $raw) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $raw, $m)) {
            $env[$m[1]] = trim($m[2], " \t\"'");
        }
    }
}
foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'SESSION_DRIVER', 'CACHE_STORE', 'LOG_CHANNEL'] as $k) {
    $line($k, array_key_exists($k, $env) ? ($env[$k] === '' ? '(empty)' : $env[$k]) : '(not set)');
}
$line('APP_KEY set', !empty($env['APP_KEY']));
$line('DB_USERNAME set', !empty($env['DB_USERNAME']));
$line('DB_PASSWORD set', array_key_exists('DB_PASSWORD', $env) && $env['DB_PASSWORD'] !== '');

$section('Database connection');
if (($env['DB_CONNECTION'] ?? '') === 'mysql' && extension_loaded('pdo_mysql')) {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? ''),
            $env['DB_USERNAME'] ?? '',
            $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $line('connect', 'OK');
        foreach (['users', 'sessions', 'maintenance_reports', 'migrations'] as $t) {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
            $line('table ' . $t, $exists);
        }
    } catch (Throwable $e) {
        $line('connect', 'FAILED: ' . $e->getMessage());
    }
} else {
    $line('connect', 'skipped (DB_CONNECTION is not mysql or pdo_mysql missing)');
}

$section('Laravel boot + GET /api/auth/check');
if (!is_file("$root/vendor/autoload.php")) {
    echo 'vendor/autoload.php is missing - Laravel cannot start.' . PHP_EOL;
} else {
    try {
        require "$root/vendor/autoload.php";
        $line('autoload', 'OK');
        foreach ([\Illuminate\Foundation\Application::class, \Symfony\Component\HttpFoundation\Request::class, \Dotenv\Dotenv::class, \Monolog\Logger::class, \Carbon\Carbon::class] as $class) {
            $line($class, class_exists($class));
        }
        $app = require "$root/bootstrap/app.php";
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $request = \Illuminate\Http\Request::create('/api/auth/check', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $kernel->handle($request);
        $line('response status', $response->getStatusCode());
        $line('response body', substr((string) $response->getContent(), 0, 300));
        $line('expected', '401 with a JSON body = Laravel works');
    } catch (Throwable $e) {
        echo '!! EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        echo '   at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
        $prev = $e->getPrevious();
        if ($prev) {
            echo '   caused by ' . get_class($prev) . ': ' . $prev->getMessage() . PHP_EOL;
        }
    }
}

$section('Last Laravel log errors');
$log = "$root/storage/logs/laravel.log";
if (is_file($log)) {
    $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -400);
    $errors = array_values(array_filter($lines, static fn ($l) => str_contains($l, '.ERROR:') || str_contains($l, '.CRITICAL:')));
    foreach (array_slice($errors, -8) as $l) {
        echo substr($l, 0, 400) . PHP_EOL;
    }
    if (!$errors) {
        echo '(no ERROR lines in the last 400 log lines)' . PHP_EOL;
    }
} else {
    echo '(storage/logs/laravel.log not found)' . PHP_EOL;
}

echo PHP_EOL . 'Done. DELETE this file from the server now.' . PHP_EOL;
