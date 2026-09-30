<?php
/**
 * TASK 21 — Stale Session After User Deletion (shared check).
 *
 * A browser session can keep pointing at a user_id whose row has since
 * been deleted (e.g. by an Administrator) while the tab stays open.
 * Continuing to trust that session is what let a deleted user's session
 * reach as far as a database insert and leak a raw SQLSTATE foreign-key
 * error instead of failing gracefully.
 *
 * header.php (the shared layout most legacy pages include) already runs
 * this check. A small number of pages render their own standalone HTML
 * document instead of including header.php — because including it would
 * inject a second <head>/<body>/navbar/sidebar into their page — so they
 * cannot reuse header.php itself. This file lets them reuse the *check*
 * without duplicating it: the existence check and teardown logic live
 * here exactly once, and every entry point (header.php, edit-report.php,
 * suppliers-manage.php) calls the same function.
 */

if (!function_exists('sfms_reject_stale_session')) {
    function sfms_reject_stale_session(): void
    {
        $sessionUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? null;
        $userId = is_array($sessionUser) ? ($sessionUser['user_id'] ?? null) : null;

        if ($userId === null) {
            return;
        }

        require_once __DIR__ . '/../../backend/config/database.php';

        $pdo = getDBConnection();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([(int) $userId]);

        if ($stmt->fetch()) {
            return;
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        require_once __DIR__ . '/../../backend/config/settings.php';
        $target = function_exists('public_url')
            ? public_url('/frontend/pages/index.php')
            : '/frontend/pages/index.php';

        header('Location: ' . $target . '?session_expired=1');
        exit;
    }
}

/**
 * Idle-timeout enforcement (10-minute inactivity auto-logout).
 *
 * Legacy pages under public/frontend/pages/*.php each start their own
 * native PHP session and never went through SessionMiddleware::checkTimeout()
 * (that class is only wired into the backend/API path), so a signed-in
 * session here never expired from inactivity — it lived until php.ini's
 * gc_maxlifetime or browser close. This mirrors that same check for every
 * legacy page, using the same SESSION_TIMEOUT constant so both paths agree.
 */
if (!function_exists('sfms_enforce_idle_timeout')) {
    function sfms_enforce_idle_timeout(): void
    {
        $sessionUser = $_SESSION['user'] ?? $_SESSION['auth_user'] ?? null;
        if ($sessionUser === null) {
            return;
        }

        $lastActivity = $_SESSION['last_activity'] ?? null;
        if ($lastActivity === null) {
            $_SESSION['last_activity'] = time();
            return;
        }

        if ((time() - (int) $lastActivity) > SESSION_TIMEOUT) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            require_once __DIR__ . '/../../backend/config/settings.php';
            $target = function_exists('public_url')
                ? public_url('/frontend/pages/index.php')
                : '/frontend/pages/index.php';

            header('Location: ' . $target . '?session_expired=1&reason=idle');
            exit;
        }

        $_SESSION['last_activity'] = time();
    }
}
