<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session-based authentication helpers for Feature tests that exercise
 * routes guarded by EnsureApiAuthenticated / EnsureRole, which read the
 * 'auth_user' session key (set by the legacy PHP login flow / SyncLegacyPhpSession).
 */
trait InteractsWithLegacySession
{
    /**
     * Authenticate the test client as the given user via the session-based
     * 'auth_user' key that EnsureApiAuthenticated / EnsureRole read.
     *
     * TASK 9 — mirrors AuthController::login(), which includes the user's
     * department_id in the session 'auth_user' array. Looked up from the
     * users table (schema-guarded, since not every test's users table has a
     * department_id column) so ReportAuthorizationService::canModifyReport()
     * sees the same department_id a real login would populate, without every
     * call site having to pass it explicitly.
     */
    protected function actingAsSessionUser(int $userId, string $role): self
    {
        return $this->withSession([
            'auth_user' => $this->legacyAuthUserPayload($userId, $role),
        ]);
    }

    /**
     * Same as actingAsSessionUser(), but also sets the flat 'user_id'/'role'
     * session keys that some controllers (e.g. ItemController) read directly
     * for activity logging, in addition to the nested 'auth_user' array.
     *
     * TASK 13 — this used to build 'auth_user' inline with only user_id/role,
     * so a caller that used the flat-key variant silently got a session with
     * NO department_id. That is not what AuthController::login() produces, and
     * it makes department-scoped authorization (DispatchAuthorizationService,
     * ReportAuthorizationService) untestable through this helper — every such
     * check would see null and compare null === null. Both helpers now build
     * the same payload so the only difference between them is the extra flat
     * keys, which is what the names imply.
     */
    protected function actingAsSessionUserWithFlatKeys(int $userId, string $role): self
    {
        return $this->withSession([
            'auth_user' => $this->legacyAuthUserPayload($userId, $role),
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    /**
     * The 'auth_user' array a real login writes. department_id is looked up
     * from the users table rather than passed in, so call sites cannot drift
     * from the row they seeded; the lookup is schema-guarded because not every
     * test's users table has that column.
     */
    private function legacyAuthUserPayload(int $userId, string $role): array
    {
        $authUser = ['user_id' => $userId, 'role' => $role];

        if (Schema::hasColumn('users', 'department_id')) {
            $authUser['department_id'] = DB::table('users')->where('user_id', $userId)->value('department_id');
        }

        return $authUser;
    }
}
