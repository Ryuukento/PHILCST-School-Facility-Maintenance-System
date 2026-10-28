<?php

namespace App\Http\Middleware;

use App\Services\RoleNormalizerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SyncLegacyPhpSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $legacyUser = $this->readLegacyUserFromNativeSession();

        // Preserve each original authenticated store's credential evidence
        // before compatibility import can merge or overwrite its payload.
        $versions = [];
        foreach ([$request->session()->all(), $_SESSION ?? []] as $store) {
            $identity = !empty($store['auth_user']) ? $store['auth_user'] : ($store['user'] ?? []);
            if (is_array($identity) && !empty($identity)) $versions[] = $identity['auth_version'] ?? 0;
        }
        $request->attributes->set('sfms.auth_versions', $versions);

        // A cached permission difference is refreshable; an account-ID
        // difference must never silently switch the authenticated actor.
        if ($this->hasIdentityConflict($request)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $_SESSION = [];

            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Your session has expired. Please sign in again.'], 401)
                : redirect('/login');
        }

        Log::info('SyncLegacyPhpSession', [
            'url'           => $request->path(),
            'has_auth_user' => !empty($_SESSION['auth_user'] ?? []),
            'has_user'      => !empty($_SESSION['user']      ?? []),
            'user_id'       => ($legacyUser['user_id'] ?? null),
        ]);

        if (is_array($legacyUser) && !empty($legacyUser)) {
            $normalizedUser = $this->normalizeLegacyUser($legacyUser);

            foreach (['user', 'auth_user'] as $key) {
                $existing = $request->session()->get($key, []);
                if (!is_array($existing) || (int) ($existing['user_id'] ?? 0) !== (int) $normalizedUser['user_id']) $existing = [];
                $request->session()->put($key, array_replace(is_array($existing) ? $existing : [], $normalizedUser));
            }
            $request->session()->put('user_id', $normalizedUser['user_id'] ?? null);
            $request->session()->put('role', $normalizedUser['role'] ?? null);
            $request->session()->put('last_activity', time());
        }

        return $next($request);
    }

    private function readLegacyUserFromNativeSession(): array
    {
        if (PHP_SAPI === 'cli') {
            return (array)(!empty($_SESSION['auth_user']) ? $_SESSION['auth_user'] : ($_SESSION['user'] ?? []));
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        return (array)(!empty($_SESSION['auth_user']) ? $_SESSION['auth_user'] : ($_SESSION['user'] ?? []));
    }

    private function hasIdentityConflict(Request $request): bool
    {
        $ids = [];
        foreach ([$request->session()->all(), $_SESSION ?? []] as $store) {
            // auth_user is the primary identity; user and flat keys are
            // compatibility copies, and may lag an explicit session change.
            $identity = !empty($store['auth_user']) ? $store['auth_user'] : ($store['user'] ?? []);
            if (empty($identity)) continue;
            $id = is_array($identity) ? ($identity['user_id'] ?? null) : null;
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) return true;
            $ids[] = $id;
        }

        return count(array_unique($ids)) > 1;
    }

    private function normalizeLegacyUser(array $legacyUser): array
    {
        $legacyUser['role'] = RoleNormalizerService::normalize($legacyUser['role'] ?? '');

        return $legacyUser;
    }
}
