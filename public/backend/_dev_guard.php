<?php
/**
 * Access guard for one-off setup/seed/debug scripts under public/backend/.
 * These scripts perform destructive or data-exposing operations and were
 * previously reachable by anyone over HTTP with no authentication at all.
 *
 * Allowed:
 *  - PHP CLI execution (php public/backend/setup_inventory.php), for local dev use.
 *  - An HTTP request carrying an authenticated super_admin session.
 * Everything else receives 403 Forbidden before any script logic runs.
 */

if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }

    $sessionUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? null;
    $role = is_array($sessionUser) ? ($sessionUser['role'] ?? null) : null;

    if (!is_array($sessionUser) || $role !== 'super_admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Forbidden: this script requires CLI execution or an authenticated super_admin session.\n");
    }
}
