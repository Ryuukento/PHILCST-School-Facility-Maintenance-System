<?php
/**
 * Auth Middleware
 * Checks if user is authenticated
 */

class AuthMiddleware {
    
    public static function protect() {
        if (!SessionMiddleware::isAuthenticated()) {
            Response::error('Unauthorized: Please log in', [], Response::HTTP_UNAUTHORIZED);
        }
        
        if (!SessionMiddleware::checkTimeout()) {
            Response::error('Session expired', [], Response::HTTP_UNAUTHORIZED);
        }
    }
    
    public static function verifyCSRFToken($token) {
        if (!isset($_SESSION['session_token']) || $_SESSION['session_token'] !== $token) {
            Response::error('CSRF token mismatch', [], Response::HTTP_FORBIDDEN);
        }
    }
}
