<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check for either 'auth_user' (new) or 'user' (old PHP compat)
        if (!$request->session()->has('auth_user') && !$request->session()->has('user')) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Please log in',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Web requests should redirect to the login page.
            return redirect('/login');
        }

        // TASK 21 — Stale Session After User Deletion: a session can keep
        // pointing at a user_id whose row has since been deleted (e.g. by an
        // Administrator) while the browser stays authenticated. Continuing to
        // trust that session let requests reach as far as a DB insert and
        // leak a raw SQLSTATE foreign-key error instead of failing
        // gracefully. Checked once here — the single place already gating
        // every authenticated route — rather than re-checked per controller.
        //
        // Also rejects a session whose user still exists but is no longer
        // 'active' (e.g. deactivated by an Administrator after the session
        // was established). AuthController::login() already refuses to
        // authenticate a pending/inactive user; a session predating the
        // deactivation must not be able to keep bypassing that same check.
        $sessionUser = $request->session()->get('auth_user') ?? $request->session()->get('user');
        $userId = is_array($sessionUser) ? ($sessionUser['user_id'] ?? null) : null;

        if ($userId === null || strtolower((string) User::query()->whereKey($userId)->value('status')) !== 'active') {
            $request->session()->forget('auth_user');
            $request->session()->forget('user');
            $request->session()->forget('user_id');
            $request->session()->forget('role');
            $request->session()->forget('last_activity');
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $_SESSION = [];

            $message = 'Your session has expired or your account no longer exists. Please sign in again.';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect('/frontend/pages/index.php?session_expired=1');
        }

        return $next($request);
    }
}
