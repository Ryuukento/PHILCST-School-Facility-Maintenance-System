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

        Log::info('SyncLegacyPhpSession', [
            'url'           => $request->path(),
            'has_auth_user' => !empty($_SESSION['auth_user'] ?? []),
            'has_user'      => !empty($_SESSION['user']      ?? []),
            'php_session_id'=> session_id(),
            'user_id'       => ($legacyUser['user_id'] ?? null),
        ]);

        if (is_array($legacyUser) && !empty($legacyUser)) {
            $normalizedUser = $this->normalizeLegacyUser($legacyUser);

            $request->session()->put('user', $normalizedUser);
            $request->session()->put('auth_user', $normalizedUser);
            $request->session()->put('user_id', $normalizedUser['user_id'] ?? null);
            $request->session()->put('role', $normalizedUser['role'] ?? null);
            $request->session()->put('last_activity', time());
        }

        return $next($request);
    }

    private function readLegacyUserFromNativeSession(): array
    {
        if (PHP_SAPI === 'cli') {
            return (array)($_SESSION['auth_user'] ?? $_SESSION['user'] ?? []);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        return (array)($_SESSION['auth_user'] ?? $_SESSION['user'] ?? []);
    }

    private function normalizeLegacyUser(array $legacyUser): array
    {
        $legacyUser['role'] = RoleNormalizerService::normalize($legacyUser['role'] ?? '');

        return $legacyUser;
    }
}
