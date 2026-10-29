<?php
// This guard is also loaded directly by native entrypoints without Composer bootstrap.
require_once __DIR__ . '/../../../app/Support/ScannedDispatchDestination.php';

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
 * here exactly once, and every entry point (header.php, edit-report.php)
 * calls the same function. suppliers-manage.php used to be one of these
 * too; as of 2026-10-27 it includes header.php like any other page and no
 * longer calls these functions directly.
 */

if (!function_exists('sfms_native_authorization_entrypoint')) {
    function sfms_native_authorization_entrypoint(?string $filename = null, ?string $sapi = null): bool
    {
        if (($sapi ?? PHP_SAPI) === 'cli' || defined('LARAVEL_START')) return false;
        $filename = realpath($filename ?? ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        return $filename !== false && dirname($filename) === realpath(__DIR__ . '/../pages')
            && pathinfo($filename, PATHINFO_EXTENSION) === 'php';
    }
}

if (!function_exists('sfms_refresh_native_authorization')) {
    function sfms_refresh_native_authorization(?PDO $connection = null): bool
    {
        $identity = !empty($_SESSION['auth_user']) ? $_SESSION['auth_user'] : ($_SESSION['user'] ?? []);
        if (empty($identity)) return false;
        $userId = is_array($identity) ? ($identity['user_id'] ?? null) : null;
        $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            $_SESSION = [];
            unset($GLOBALS['sfms_native_authorization_account']);
            return false;
        }

        // PHP globals last only for this native request. Reapply this account
        // when the shared header calls again, without another database query.
        $account = $GLOBALS['sfms_native_authorization_account'] ?? null;
        if (!$account || (int) $account['user_id'] !== $userId) {
            try {
                if ($connection === null) {
                    require_once __DIR__ . '/../../backend/config/database.php';
                    $connection = getDBConnection();
                }
                $stmt = $connection->prepare('SELECT user_id, role, department_id, status, auth_version, force_profile_update FROM users WHERE user_id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $_SESSION = [];
                unset($GLOBALS['sfms_native_authorization_account']);
                throw $e;
            }
        }

        if (!$account || strtolower((string) $account['status']) !== 'active'
            || !\App\Http\Middleware\EnsureApiAuthenticated::versionMatches($identity['auth_version'] ?? 0, (int) $account['auth_version'])) {
            $_SESSION = [];
            unset($GLOBALS['sfms_native_authorization_account']);
            return false;
        }
        $GLOBALS['sfms_native_authorization_account'] = $account;
        $authorization = [
            'user_id' => (int) $account['user_id'],
            'role' => \App\Services\RoleNormalizerService::normalize($account['role']),
            'department_id' => $account['department_id'] !== null ? (int) $account['department_id'] : null,
            'status' => strtolower((string) $account['status']),
            'auth_version' => (int) $account['auth_version'],
            'force_profile_update' => (bool) $account['force_profile_update'],
        ];
        $fallback = !empty($_SESSION['auth_user']) ? $_SESSION['auth_user'] : $_SESSION['user'];
        foreach (['user', 'auth_user'] as $key) {
            $payload = $_SESSION[$key] ?? $fallback;
            if (!is_array($payload) || (int) ($payload['user_id'] ?? 0) !== $userId) $payload = $identity;
            $_SESSION[$key] = array_replace(is_array($payload) ? $payload : $fallback, $authorization);
        }
        foreach ($authorization as $key => $value) $_SESSION[$key] = $value;
        return true;
    }
}

if (!function_exists('sfms_native_setup_allowed')) {
    function sfms_native_setup_allowed(string $filename): bool
    {
        return in_array(basename($filename), ['account.php', 'session-ping.php'], true);
    }
}

if (!function_exists('sfms_enforce_forced_setup')) {
    function sfms_enforce_forced_setup(): void
    {
        if (!empty($_SESSION['auth_user']['force_profile_update'])
            && !sfms_native_setup_allowed($_SERVER['SCRIPT_FILENAME'] ?? '')) {
            header('Location: ' . public_url(\App\Support\ScannedDispatchDestination::carry('/account?setup=1', \App\Support\ScannedDispatchDestination::nativeReference())));
            exit;
        }
    }
}

if (!function_exists('sfms_reject_stale_session')) {
    function sfms_reject_stale_session(): void
    {
        if (empty($_SESSION['user']) && empty($_SESSION['auth_user'])) return;
        if (sfms_refresh_native_authorization()) return;
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        require_once __DIR__ . '/../../backend/config/settings.php';
        $target = function_exists('public_url')
            ? public_url('/login')
            : '/login';

        header('Location: ' . \App\Support\ScannedDispatchDestination::carry($target . '?session_expired=1', \App\Support\ScannedDispatchDestination::nativeReference()));
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
                ? public_url('/login')
                : '/login';

            header('Location: ' . \App\Support\ScannedDispatchDestination::carry($target . '?session_expired=1&reason=idle', \App\Support\ScannedDispatchDestination::nativeReference()));
            exit;
        }

        $_SESSION['last_activity'] = time();
    }
}
