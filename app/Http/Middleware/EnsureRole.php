<?php

namespace App\Http\Middleware;

use App\Services\RoleNormalizerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $authUser = $request->session()->get('auth_user', $request->session()->get('user', []));
        $role = RoleNormalizerService::normalize($authUser['role'] ?? '');

        if ($role === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array($role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
