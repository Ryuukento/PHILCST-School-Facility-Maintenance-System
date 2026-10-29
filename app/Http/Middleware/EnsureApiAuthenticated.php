<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\RoleNormalizerService;
use App\Support\ScannedDispatchDestination;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        // Capture only the existing dispatch-detail GET, never an API/action request.
        $scannedDispatch = $request->isMethod('GET') && $request->route()?->getName() === 'dispatches.show'
            ? ScannedDispatchDestination::id($request->route('id')) : null;
        // Check for either 'auth_user' (new) or 'user' (old PHP compat)
        if (!$request->session()->has('auth_user') && !$request->session()->has('user')) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Please log in',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Web requests should redirect to the login page.
            return redirect(ScannedDispatchDestination::carry('/login', $scannedDispatch));
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
        $sessionUser = $request->session()->get('auth_user');
        if (empty($sessionUser)) $sessionUser = $request->session()->get('user');
        $userId = is_array($sessionUser) ? ($sessionUser['user_id'] ?? null) : null;

        // Replace the status-only lookup: the account, not either cached
        // session payload, owns current authorization. Never read request input.
        $account = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            ? User::query()->select(['user_id', 'role', 'department_id', 'status', 'auth_version', 'force_profile_update'])->find($userId)
            : null;

        $versions = $request->attributes->get('sfms.auth_versions', [$sessionUser['auth_version'] ?? 0]);
        $versionsValid = $account && count($versions) > 0;
        foreach ($versions as $version) {
            if (!$account || !self::versionMatches($version, (int) $account->auth_version)) $versionsValid = false;
        }
        if (!$account || strtolower((string) $account->status) !== 'active' || !$versionsValid) {
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

            return redirect(ScannedDispatchDestination::carry('/frontend/pages/index.php?session_expired=1', $scannedDispatch));
        }

        $authorization = [
            'user_id' => (int) $account->user_id,
            'role' => RoleNormalizerService::normalize($account->role),
            'department_id' => $account->department_id !== null ? (int) $account->department_id : null,
            'status' => strtolower((string) $account->status),
            'auth_version' => (int) $account->auth_version,
            'force_profile_update' => (bool) $account->force_profile_update,
        ];
        // A CLI/Laravel-only request may have no writable native session.
        // Do not manufacture one; the bridge starts it for real HTTP requests.
        $syncNative = session_status() === PHP_SESSION_ACTIVE
            || !empty($_SESSION['auth_user']) || !empty($_SESSION['user']) || isset($_SESSION['user_id']);
        foreach (['user', 'auth_user'] as $key) {
            $payload = $request->session()->get($key, $sessionUser);
            if (!is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $account->user_id) $payload = $sessionUser;
            $request->session()->put($key, array_replace(is_array($payload) ? $payload : [], $authorization));
            if ($syncNative) {
                $nativePayload = $_SESSION[$key] ?? $sessionUser;
                if (!is_array($nativePayload) || (int) ($nativePayload['user_id'] ?? 0) !== (int) $account->user_id) $nativePayload = $sessionUser;
                $_SESSION[$key] = array_replace(is_array($nativePayload) ? $nativePayload : [], $authorization);
            }
        }
        foreach ($authorization as $key => $value) {
            $request->session()->put($key, $value);
            if ($syncNative) $_SESSION[$key] = $value;
        }

        if ($account->force_profile_update && !$this->allowsForcedSetup($request)) {
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Please complete your required password change.',
                    'code' => 'PASSWORD_CHANGE_REQUIRED', 'redirect' => '/account?setup=1'], 403)
                : redirect(ScannedDispatchDestination::carry('/account?setup=1', $scannedDispatch));
        }

        return $next($request);
    }

    public static function versionMatches(mixed $version, int $current): bool
    {
        $version = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $version !== false && $version === $current;
    }

    private function allowsForcedSetup(Request $request): bool
    {
        $action = $request->route()?->getActionName();
        $allowed = [
            'App\\Http\\Controllers\\Api\\UserController@updateProfile' => 'PATCH',
            'App\\Http\\Controllers\\Api\\AuthController@check' => 'GET',
            'App\\Http\\Controllers\\Api\\AuthController@logout' => 'POST',
            'App\\Http\\Controllers\\Api\\EmailVerificationController@initiate' => 'POST',
            'App\\Http\\Controllers\\Api\\EmailVerificationController@verifyOtp' => 'POST',
            'App\\Http\\Controllers\\Api\\EmailVerificationController@resendOtp' => 'POST',
            'App\\Http\\Controllers\\Api\\EmailVerificationController@getStatus' => 'GET',
        ];
        return (isset($allowed[$action]) && $request->method() === $allowed[$action])
            || ($request->route()?->getName() === 'logout' && $request->isMethod('GET'));
    }
}
