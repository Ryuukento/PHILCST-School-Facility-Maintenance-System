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

$role = $_SESSION['user']['role'] ?? '';

if (!empty($_SESSION['user'])) {
    if ($role === 'maintenance_admin') {
        header('Location: ' . public_url('/frontend/pages/maintenance-dashboard.php'), true, 302);
    } elseif ($role === 'maintenance_staff') {
        header('Location: ' . public_url('/frontend/pages/staff-dashboard.php'), true, 302);
    } else {
        header('Location: ' . public_url('/frontend/pages/dashboard.php'), true, 302);
    }
} else {
    header('Location: ' . public_url('/frontend/pages/index.php'), true, 302);
}
exit;
