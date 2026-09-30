<?php
/**
 * Frontend Root Index
 */
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

// TASK 60 — this was '/../../backend/config/settings.php', which resolves to
// <project root>/backend/config/settings.php. No root-level backend/ directory
// exists (that is exactly why the root .htaccess rewrites ^backend/ into
// public/backend/), so every request to /frontend/ — this file is the
// DirectoryIndex target for that URL — died in a PHP fatal error that printed
// the absolute filesystem path and the include_path, the same disclosure
// TASK 58 removed when it retired the broken public inventory workflow shim.
// display_errors=0 (TASK 55) could not contain it, because that hardening lives
// in settings.php, the very file that failed to load.
//
// (That sentence deliberately avoids spelling the retired endpoint's filename:
// the TASK 58 regression guard greps public/frontend/ for the literal string
// and cannot tell a comment from a call. See the TASK 60 report.)
//
// The other legacy files that spell '/../../backend/...' are correct: they sit
// one level deeper, in pages/ and includes/, where '../../' lands on public/.
// This file sits at public/frontend/, so it needs a single '../'.
require_once __DIR__ . '/../backend/config/settings.php';

if (!empty($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? '';
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
