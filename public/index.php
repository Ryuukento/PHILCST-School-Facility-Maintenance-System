<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Last-resort JSON guard for API requests (2026-09-27, production login fix).
 *
 * Laravel's own exception handler turns errors into JSON — but only once
 * Laravel has booted. A fatal error BEFORE that (missing/incomplete vendor/,
 * an unreadable bootstrap file, a missing class during boot, memory
 * exhaustion) produced an empty response, which the sign-in page reported
 * as "Unexpected end of JSON input". This guard:
 *   - answers API/JSON callers with a JSON body and HTTP 500 instead of an
 *     empty response, and
 *   - writes the real error to PHP's error_log so it shows in the hosting
 *     error log (it is never sent to the browser).
 * It does nothing when Laravel handled the request normally.
 */
(static function (): void {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    $wantsJson = preg_match('#/api(/|$|\?)#', $uri) === 1
        || stripos($accept, 'application/json') !== false
        || stripos($contentType, 'application/json') !== false;

    if (!$wantsJson) {
        return;
    }

    $guard = static function () use ($uri): void {
        $error = error_get_last();
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if ($error === null || ($error['type'] & $fatalTypes) === 0) {
            return;
        }

        error_log(sprintf(
            '[SFMS] Fatal error before/while handling %s: %s in %s:%d',
            $uri,
            $error['message'],
            $error['file'],
            $error['line']
        ));

        if (headers_sent()) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'The server could not process this request. Please try again later or contact the Administrator.',
        ]);
    };

    // Registered from inside a shutdown function so it runs LAST — after
    // Laravel's own fatal-error renderer (registered later, during boot). If
    // Laravel already sent its JSON error response, headers_sent() is true
    // and this only logs; it writes a body only when nothing was sent.
    register_shutdown_function(static function () use ($guard): void {
        register_shutdown_function($guard);
    });
})();

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
// A missing vendor/ used to be a silent fatal (empty response); fail loudly
// in the server log and with a JSON body instead.
$autoload = __DIR__.'/../vendor/autoload.php';
if (!is_file($autoload)) {
    error_log('[SFMS] vendor/autoload.php not found at ' . $autoload . ' - run composer install / upload vendor/.');
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'The server is not fully set up. Please contact the Administrator.',
    ]);
    exit;
}
require $autoload;

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
