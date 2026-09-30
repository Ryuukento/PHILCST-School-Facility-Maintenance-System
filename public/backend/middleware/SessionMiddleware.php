<?php
/**
 * Session Middleware
 * Validates user session and handles session management
 */

class SessionMiddleware {
    
    public static function initialize() {
        // Only start session if not already started
        // Don't try to set session_name if session is already active
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => ($_SERVER['HTTPS'] ?? 'off') === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }
    
    public static function isAuthenticated() {
        if (!empty($_SESSION['user_id'])) {
            return true;
        }

        $fallbackUserId = $_SESSION['user']['user_id']
            ?? $_SESSION['auth_user']['user_id']
            ?? null;

        if (!empty($fallbackUserId)) {
            $_SESSION['user_id'] = $fallbackUserId;
            return true;
        }

        return false;
    }
    
    public static function checkTimeout() {
        if (self::isAuthenticated()) {
            $lastActivity = $_SESSION['last_activity'] ?? null;

            // If last_activity was never set, initialize it now — don't expire
            if ($lastActivity === null) {
                $_SESSION['last_activity'] = time();
                return true;
            }

            $timeSinceActivity = time() - $lastActivity;

            if ($timeSinceActivity > SESSION_TIMEOUT) {
                self::destroy();
                return false;
            }

            // Update last_activity on every request
            $_SESSION['last_activity'] = time();

            // Regenerate session periodically
            if ($timeSinceActivity > SESSION_REGENERATE_INTERVAL) {
                session_regenerate_id(true);
            }
        }
        return true;
    }
    
    public static function destroy() {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, 
                $params["path"], $params["domain"], 
                $params["secure"], $params["httponly"]);
        }
        session_destroy();
    }
    
    public static function regenerate() {
        session_regenerate_id(true);
    }
}
