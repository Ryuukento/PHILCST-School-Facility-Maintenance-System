<?php
/**
 * Application Settings
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    if (!@session_start()) {
        // 2026-09-30: transient Windows/antivirus file-lock on the session
        // save path (C:\xampp\tmp) can make session_start() fail; suppress
        // the raw warning and log it instead so users just see a clean
        // logged-out state (e.g. after auto-logout) rather than PHP noise.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

// Application settings
define('APP_NAME', 'School Facility Maintenance System');
define('APP_VERSION', '1.0.0');

function sfms_detect_app_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        ? 'https'
        : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $requestUri = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = '';

    // Laravel's built-in dev server serves the app from the domain root.
    if ($host !== '' && preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/i', $host) && preg_match('/:(8000|8001|8080)$/', $host)) {
        $basePath = '';
    } else {
        // 2026-09-27 — default to the domain root. The project folder name is
        // used only when the request is actually served from that subfolder
        // (local XAMPP: http://localhost/School_Facility_Maintenance_System/...).
        // It used to be the default for every non-dev-server host, so a
        // domain-root deployment (https://philcstmaintenance.com/) generated
        // links and redirects under /School_Facility_Maintenance_System/.
        $basePath = '';

        foreach ([$requestUri, $scriptName] as $candidate) {
            if (preg_match('#^(.*?/School_Facility_Maintenance_System)(?:/.*)?$#', (string)$candidate, $matches)) {
                $basePath = rtrim($matches[1], '/');
                break;
            }
        }
    }

    if ($host !== '') {
        return $scheme . '://' . $host . $basePath;
    }

    return 'http://localhost/School_Facility_Maintenance_System';
}

define('APP_URL', sfms_detect_app_url());

$appUrlPath = parse_url(APP_URL, PHP_URL_PATH);
$appUrlPath = is_string($appUrlPath) ? trim($appUrlPath) : '';
$appUrlPath = $appUrlPath === '/' ? '' : rtrim($appUrlPath, '/');
define('APP_BASE_PATH', $appUrlPath);

// Public-facing URLs should resolve from the project root URL.
define('APP_PUBLIC_PATH', APP_BASE_PATH);

// File upload settings
define('UPLOAD_DIR', __DIR__ . '/../../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);

// Pagination
define('ITEMS_PER_PAGE', 10);

// Security - Password hashing
define('BCRYPT_COST', 12);
define('PASSWORD_MIN_LENGTH', 8);

// Status constants
define('STATUS_ACTIVE', 'active');
define('STATUS_INACTIVE', 'inactive');
define('STATUS_SUSPENDED', 'suspended');
define('STATUS_PENDING', 'pending');

// Role constants
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_DEPARTMENT_ADMIN', 'department_admin');
define('ROLE_MAINTENANCE_ADMIN', 'maintenance_admin');
define('ROLE_MAINTENANCE_STAFF', 'maintenance_staff');
define('ROLE_USER', 'user');

// Priority constants
define('PRIORITY_LOW', 'low');
define('PRIORITY_MEDIUM', 'medium');
define('PRIORITY_HIGH', 'high');
define('PRIORITY_URGENT', 'urgent');
define('PRIORITY_CRITICAL', 'critical');

// Timezone
date_default_timezone_set('Asia/Manila');

// Session settings
define('SESSION_NAME', 'SFMS_SESSION');
define('SESSION_TIMEOUT', 600); // 10 minutes of inactivity
define('SESSION_REGENERATE_INTERVAL', 600); // 10 minutes

// Debug mode
//
// TASK 100.2.3 remediation (2026-08-17): this used to hardcode APP_DEBUG to
// `true` with no environment gate at all, independently of Laravel's own
// APP_DEBUG (already fixed in Task 100.2.2). Tracing every file that loads
// this settings.php (directly or via bootstrap.php) showed the debug flags
// below are NOT limited to the _dev_guard.php-gated one-off admin scripts —
// they are also active on public/frontend/pages/index.php (the login page,
// always reachable without authentication), the project-root index.php
// (every request to "/"), and the 37 authenticated frontend pages that
// include includes/header.php. None of those are protected by
// _dev_guard.php. With display_errors forced on unconditionally, any
// uncaught PHP warning/notice/fatal error on any of those pages would have
// printed raw error text — potentially including file paths and line
// numbers — directly into the HTTP response for any visitor.
//
// Fix: read APP_DEBUG from the same .env value Laravel and
// public/backend/config/database.php already use (via the same Dotenv
// loader established in Task 100.1), defaulting to false when absent, so
// nothing is displayed to an HTTP client unless a developer explicitly
// opts in via .env. This file is often loaded standalone, without
// database.php (e.g. by the login page and header.php), so it cannot rely
// on database.php's sfmsBackendEnv() helper already being defined — the
// loader below is self-contained and uses a distinctly-named helper
// (sfmsLegacyEnv, not sfmsBackendEnv) to avoid a "cannot redeclare"
// collision on the request path where bootstrap.php loads both files.
if (!function_exists('sfmsLegacyEnv')) {
    function sfmsLegacyEnv(string $key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}

$sfmsSettingsProjectRoot = dirname(__DIR__, 3);
$sfmsSettingsAutoload = $sfmsSettingsProjectRoot . '/vendor/autoload.php';

if (is_file($sfmsSettingsAutoload)) {
    require_once $sfmsSettingsAutoload;

    if (class_exists(\Dotenv\Dotenv::class) && is_file($sfmsSettingsProjectRoot . '/.env')) {
        // safeLoad(): never overwrites a real OS-level env var that is
        // already set; safe to call again even if database.php also calls
        // it later in the same request.
        \Dotenv\Dotenv::createImmutable($sfmsSettingsProjectRoot)->safeLoad();
    }
}

$sfmsLegacyDebugEnabled = filter_var(sfmsLegacyEnv('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);

define('APP_DEBUG', $sfmsLegacyDebugEnabled);

// Error reporting: keep reporting everything so real bugs are still fully
// captured server-side (log_errors is On, writing to PHP's configured
// error_log — confirmed independently of this change), but only print
// errors into the HTTP response when APP_DEBUG is explicitly enabled via
// .env. This preserves diagnostics without exposing them to HTTP clients.
error_reporting(E_ALL);
ini_set('display_errors', $sfmsLegacyDebugEnabled ? '1' : '0');

if (!function_exists('sfms_public_base_path')) {
    function sfms_public_base_path() {
        return APP_BASE_PATH;
    }
}

if (!function_exists('public_url')) {
    function public_url($path) {
        $basePath = sfms_public_base_path();
        return ($basePath !== '' ? $basePath : '') . '/' . ltrim((string)$path, '/');
    }
}
