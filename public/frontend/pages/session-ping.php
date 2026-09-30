<?php
/**
 * Idle-timeout keep-alive endpoint.
 *
 * Called by idle-timeout.js while the user is actively using a page (not on
 * every mouse move — throttled client-side) so the native $_SESSION used by
 * public/frontend/pages/*.php gets its last_activity refreshed. Without this,
 * a user actively typing a long report on a single page (no new page load)
 * would still get force-logged-out by sfms_enforce_idle_timeout() on their
 * next navigation, because that check only runs when a page is loaded.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
    if (!@session_start()) {
        // 2026-09-30: transient Windows/antivirus file-lock on the session
        // save path (C:\xampp\tmp) can make session_start() fail; suppress
        // the raw warning and log it instead so users just see a clean
        // logged-out state (e.g. after auto-logout) rather than PHP noise.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

header('Content-Type: application/json; charset=UTF-8');

$sessionUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? null;
if ($sessionUser === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../backend/config/settings.php';

$lastActivity = $_SESSION['last_activity'] ?? time();
if ((time() - (int) $lastActivity) > SESSION_TIMEOUT) {
    $_SESSION = [];
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

$_SESSION['last_activity'] = time();
echo json_encode(['success' => true]);
