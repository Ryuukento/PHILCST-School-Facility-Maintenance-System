<?php
require_once __DIR__ . '/public/backend/config/settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
    if (!@session_start()) {
        // 2026-09-30: transient Windows/antivirus file-lock on the session
        // save path (C:\xampp\tmp) can make session_start() fail; suppress
        // the raw warning and log it instead so users just see a clean
        // logged-out state (e.g. after auto-logout) rather than PHP noise.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

$requestUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? '/'));
$requestPath = (string)parse_url($requestUri, PHP_URL_PATH);
$basePath = defined('APP_BASE_PATH') ? (string)APP_BASE_PATH : '';

if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
}

$requestPath = '/' . ltrim((string)$requestPath, '/');

if ($requestPath !== '/' && $requestPath !== '') {
    require __DIR__ . '/public/index.php';
    exit;
}

// Clean URLs — "/" sends a signed-in user to /dashboard (which shows the
// dashboard for their role, see public/frontend/pages/role-dashboard.php)
// and everyone else to /login.
if (!empty($_SESSION['user'])) {
    header('Location: ' . public_url('/dashboard'), true, 302);
} else {
    header('Location: ' . public_url('/login'), true, 302);
}
exit;
