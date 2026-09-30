<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Covers the user-management security gaps documented in
 * SYSTEM_FLOW_REVIEW.md §6: GET /api/users had no role guard at all, and
 * the Approve User modal offered a role ("Admin"/super_admin) that the
 * backend validator rejects. Verifies the fix reuses the existing
 * EnsureRole middleware (super_admin-only, per BUSINESS_RULES.md §1
 * "Manage users") rather than introducing a new permission system.
 */
class UserManagementAuthorizationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('user_management_authorization_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    /**
     * TASK 54 — TEST-ISOLATION DEFECT.
     *
     * This file logs in for real (see the _method=PATCH profile test below),
     * and AuthController::login() writes the raw PHP $_SESSION superglobal
     * for legacy plain-PHP page compatibility. $_SESSION is process-global
     * and, unlike Laravel's session store, is NOT reset between test methods
     * or classes. SyncLegacyPhpSession then reads that leftover $_SESSION on
     * every subsequent request and does session()->put('auth_user', ...),
     * OVERWRITING whatever role a later test established via withSession().
     *
     * Because the account this file logs in as is a super_admin, the leak
     * silently promotes every test that runs afterward in the same process.
     * That is actively dangerous for an RBAC suite: a later test asserting a
     * restricted role receives 200 would still pass — under the leaked
     * super_admin identity rather than the role it claims to exercise —
     * making its coverage illusory.
     *
     * AuthUsernameLoginTest (the only other file that logs in) already
     * documents this exact hazard and clears $_SESSION in tearDown; this
     * file performs the same login but was missing the same cleanup. This
     * adds it, mirroring that established pattern rather than inventing a
     * new isolation mechanism.
     */
    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    public function test_super_admin_can_list_users(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $this->seedUser(['role' => 'maintenance_staff', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/users');

        $response->assertOk();
    }

    public function test_maintenance_admin_cannot_list_users(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->getJson('/api/users');

        $response->assertForbidden();
    }

    public function test_maintenance_staff_cannot_list_users(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/users');

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_cannot_list_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertUnauthorized();
    }

    public function test_super_admin_can_approve_a_pending_user(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $pendingId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$pendingId}/approve", ['role' => 'maintenance_admin', 'department_id' => $this->seedDepartment('Electrical')]);

        $response->assertOk();
        $this->assertSame('active', DB::table('users')->where('user_id', $pendingId)->value('status'));
    }

    public function test_approval_requires_choosing_a_department(): void
    {
        // 2026-09-27 — self-registration cannot know the department, and
        // report/dispatch permissions are department-based, so the
        // Administrator must choose it when approving.
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $pendingId = $this->seedUser(['role' => 'user', 'status' => 'pending']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$pendingId}/approve", ['role' => 'maintenance_staff'])
            ->assertStatus(422);

        $this->assertSame('pending', DB::table('users')->where('user_id', $pendingId)->value('status'));
    }

    public function test_approval_saves_the_chosen_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $computerId = $this->seedDepartment('Computer');
        $plumbingId = $this->seedDepartment('Plumbing');
        $pendingId = $this->seedUser(['role' => 'user', 'status' => 'pending', 'department_id' => $computerId]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$pendingId}/approve", ['role' => 'maintenance_staff', 'department_id' => $plumbingId])
            ->assertOk();

        $row = DB::table('users')->where('user_id', $pendingId)->first();
        $this->assertSame('active', $row->status);
        $this->assertSame('maintenance_staff', $row->role);
        $this->assertSame($plumbingId, (int) $row->department_id);
    }

    public function test_maintenance_admin_cannot_approve_a_pending_user(): void
    {
        $requesterId = $this->seedUser(['role' => 'maintenance_admin']);
        $pendingId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($requesterId, 'maintenance_admin')
            ->patchJson("/api/users/{$pendingId}/approve", ['role' => 'maintenance_staff']);

        $response->assertForbidden();
    }

    public function test_maintenance_staff_cannot_delete_a_pending_user(): void
    {
        $requesterId = $this->seedUser(['role' => 'maintenance_staff']);
        $pendingId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($requesterId, 'maintenance_staff')
            ->deleteJson("/api/users/{$pendingId}/reject");

        $response->assertForbidden();
        $this->assertNotNull(DB::table('users')->where('user_id', $pendingId)->first());
    }

    public function test_maintenance_admin_cannot_edit_a_privileged_user(): void
    {
        $requesterId = $this->seedUser(['role' => 'maintenance_admin']);
        $superAdminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($requesterId, 'maintenance_admin')
            ->patchJson("/api/users/{$superAdminId}/deactivate");

        $response->assertForbidden();
    }

    /**
     * The Approve User modal must only ever submit roles the backend
     * validator accepts (maintenance_admin / maintenance_staff). This locks
     * in the backend contract so the frontend fix (removing the "Admin"
     * option) cannot silently drift out of sync again.
     */
    public function test_approve_endpoint_rejects_a_super_admin_role_assignment(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $pendingId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$pendingId}/approve", ['role' => 'super_admin']);

        $response->assertStatus(422);
        $this->assertSame('pending', DB::table('users')->where('user_id', $pendingId)->value('status'));
    }

    public function test_frontend_approve_modal_only_offers_backend_accepted_roles(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/users.php'));
        $this->assertNotFalse($markup);

        $modalStart = strpos($markup, 'id="approve-user-role"');
        $this->assertNotFalse($modalStart, 'Approve User role select not found.');

        $modalEnd = strpos($markup, '</select>', $modalStart);
        $modalMarkup = substr($markup, $modalStart, $modalEnd - $modalStart);

        $this->assertStringContainsString('maintenance_staff', $modalMarkup);
        $this->assertStringContainsString('maintenance_admin', $modalMarkup);
        $this->assertStringNotContainsString('super_admin', $modalMarkup, 'Approve modal must not offer a role the backend rejects.');
    }

    // -----------------------------------------------------------------------
    // TASK 37 — Add New User: the Department field depends on the Role
    // -----------------------------------------------------------------------

    /**
     * TASK 37.
     *
     * store()'s department_id rule was an unconditional 'nullable', so the
     * only thing that ever decided whether a department was supplied was the
     * register modal — which always showed the field and never required it.
     * A Head or Maintenance Staff account could therefore be created with no
     * department at all.
     *
     * That is not cosmetic. Both roles are department-scoped: report
     * visibility, assignment and the users-page role label all resolve
     * through the account's department. users.php::getRoleLabel() renders a
     * departmentless maintenance_admin as a bare "Head", and every
     * department-scoped query simply fails to match it — so the account looks
     * valid and works nowhere.
     *
     * An Administrator is the deliberate exception: the role is system-wide,
     * and the live Administrator account carries department_id = NULL.
     */
    public function test_administrator_can_be_created_without_a_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username' => 'newadmin',
                'role'     => 'super_admin',
            ]));

        $response->assertCreated();
        $this->assertNull(
            DB::table('users')->where('username', 'newadmin')->value('department_id'),
            'An Administrator created without a department must be stored with department_id NULL.'
        );
    }

    public function test_head_maintenance_cannot_be_created_without_a_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username' => 'headnodept',
                'role'     => 'maintenance_admin',
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('department_id');
        $this->assertDatabaseMissingUser('headnodept');
    }

    public function test_maintenance_staff_cannot_be_created_without_a_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username' => 'staffnodept',
                'role'     => 'maintenance_staff',
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('department_id');
        $this->assertDatabaseMissingUser('staffnodept');
    }

    /**
     * An explicit null is the shape the register modal actually sends when
     * nothing is picked (`department_id: ... || null`), so it must be rejected
     * exactly like an absent key rather than satisfying `nullable`.
     */
    public function test_an_explicit_null_department_is_rejected_for_a_department_scoped_role(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username'      => 'headnulldept',
                'role'          => 'maintenance_admin',
                'department_id' => null,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('department_id');
        $this->assertDatabaseMissingUser('headnulldept');
    }

    public function test_head_maintenance_can_be_created_with_a_valid_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $departmentId = $this->seedDepartment('Electrical');

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username'      => 'headwithdept',
                'role'          => 'maintenance_admin',
                'department_id' => $departmentId,
            ]));

        $response->assertCreated();
        $this->assertSame(
            $departmentId,
            (int) DB::table('users')->where('username', 'headwithdept')->value('department_id')
        );
    }

    public function test_maintenance_staff_can_be_created_with_a_valid_department(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $departmentId = $this->seedDepartment('Computer');

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username'      => 'staffwithdept',
                'role'          => 'maintenance_staff',
                'department_id' => $departmentId,
            ]));

        $response->assertCreated();
        $this->assertSame(
            $departmentId,
            (int) DB::table('users')->where('username', 'staffwithdept')->value('department_id')
        );
    }

    /**
     * The requirement must survive the role aliases.
     *
     * store()'s Rule::in() whitelist is built from
     * RoleNormalizerService::rawValuesFor(), which expands
     * 'maintenance_admin' to include the legacy alias 'admin_maintenance'
     * (and 'maintenance_staff' to 'eelab_staff'/'maintenance_personnel'). So
     * those alias strings are *accepted* roles. A department check that
     * string-compared the raw input against 'maintenance_admin' would classify
     * them as roles with no department obligation and wave a departmentless
     * Head straight through — the exact hole the fix exists to close. Locks in
     * that the check normalizes first.
     *
     * @dataProvider departmentScopedRoleAliasProvider
     */
    public function test_role_aliases_cannot_bypass_the_department_requirement(string $aliasRole, string $username): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username' => $username,
                'role'     => $aliasRole,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('department_id');
        $this->assertDatabaseMissingUser($username);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function departmentScopedRoleAliasProvider(): array
    {
        return [
            'admin_maintenance => maintenance_admin'     => ['admin_maintenance', 'aliashead'],
            'eelab_staff => maintenance_staff'           => ['eelab_staff', 'aliasstaffa'],
            'maintenance_personnel => maintenance_staff' => ['maintenance_personnel', 'aliasstaffb'],
        ];
    }

    /**
     * A department id that does not exist must still be rejected for a
     * department-scoped role — requiredIf() must not displace the existing
     * exists: rule.
     */
    public function test_a_nonexistent_department_is_rejected(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username'      => 'headbaddept',
                'role'          => 'maintenance_admin',
                'department_id' => 99999,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('department_id');
        $this->assertDatabaseMissingUser('headbaddept');
    }

    /**
     * Creating a user must not disturb anyone who already exists. The live
     * database holds a super_admin with department_id NULL alongside a
     * maintenance_admin and a maintenance_staff that both carry a department;
     * none of them may be rewritten as a side effect of an insert.
     */
    public function test_creating_a_user_leaves_existing_accounts_untouched(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $departmentId = $this->seedDepartment('Plumbing');
        $existingHeadId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $departmentId]);
        $existingStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $departmentId]);

        $before = DB::table('users')->orderBy('user_id')->get()->toArray();

        $this->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'username'      => 'brandnewstaff',
                'role'          => 'maintenance_staff',
                'department_id' => $departmentId,
            ]))
            ->assertCreated();

        $after = DB::table('users')
            ->whereIn('user_id', [$adminId, $existingHeadId, $existingStaffId])
            ->orderBy('user_id')
            ->get()
            ->toArray();

        $this->assertEquals($before, $after, 'Pre-existing accounts must be byte-identical after another user is created.');
    }

    /**
     * The backend is the enforcing copy, but the modal has to agree with it or
     * Administrators get a field they cannot fill and an error they cannot
     * clear. These three lock the markup/JS contract the live UI depends on:
     * a toggleable wrapper, one role-driven sync, and a payload derived from
     * the role rather than from whatever is left in the hidden select.
     */
    public function test_register_modal_department_field_is_wrapped_in_a_toggleable_group(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/users.php'));
        $this->assertNotFalse($markup);

        $this->assertStringContainsString(
            'id="register-department-group"',
            $markup,
            'The Department field needs its own wrapper id so the role sync can hide the label with the select.'
        );
        $this->assertStringContainsString(
            'id="register-department-error"',
            $markup,
            'Department needs the same -error container every other validated field in this modal uses.'
        );
    }

    public function test_register_modal_hides_and_requires_department_by_role(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/users.php'));
        $this->assertNotFalse($markup);

        $this->assertStringContainsString(
            "const DEPARTMENT_REQUIRED_ROLES = ['maintenance_admin', 'maintenance_staff'];",
            $markup,
            'The modal must mirror UserController::DEPARTMENT_REQUIRED_ROLES exactly — super_admin must not appear.'
        );

        $syncStart = strpos($markup, 'function syncRoleDependentFields()');
        $this->assertNotFalse($syncStart, 'Role-dependent field sync not found.');

        $sync = substr($markup, $syncStart, 1400);

        $this->assertStringContainsString('roleRequiresDepartment(role)', $sync);
        $this->assertStringContainsString("departmentGroup.style.display = needsDepartment ? 'block' : 'none'", $sync);
        $this->assertStringContainsString('departmentSelect.required = needsDepartment', $sync);
        $this->assertStringContainsString(
            "departmentSelect.value = ''",
            $sync,
            'Switching to Administrator must clear the selection so no stale department is submitted.'
        );
        $this->assertStringContainsString(
            "setFieldValidationState(departmentSelect, '')",
            $sync,
            'Switching to Administrator must clear the stale "required" error, or a now-valid form stays blocked.'
        );

        $this->assertStringContainsString(
            "roleSelect?.addEventListener('change', syncRoleDependentFields)",
            $markup,
            'The sync must run on every role change, not just on modal open.'
        );
    }

    public function test_register_modal_never_submits_a_department_for_a_role_that_has_none(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/users.php'));
        $this->assertNotFalse($markup);

        $this->assertStringContainsString(
            'department_id: needsDepartment ? (selectedDepartmentId || null) : null',
            $markup,
            'The payload must be derived from the role so a stale department cannot reach the API by any path.'
        );
    }

    private function seedDepartment(string $name): int
    {
        return (int) DB::table('departments')->insertGetId([
            'name'       => $name,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'department_id');
    }

    /**
     * A complete, valid create-user body. Every Task 37 test overrides only
     * the role/department pair, so a failure can only ever be about the rule
     * under test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registerUserPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Dela Cruz, Juan',
            'username'  => 'newuser',
            'password'  => 'SecurePass123!',
            'role'      => 'maintenance_staff',
        ], $overrides);
    }

    private function assertDatabaseMissingUser(string $username): void
    {
        $this->assertFalse(
            DB::table('users')->where('username', $username)->exists(),
            "A rejected create-user request must not have persisted '{$username}'."
        );
    }

    /**
     * TASK 21 rejects a session pointing at a *deleted* user_id, but a
     * deactivated user's row still exists — only its `status` changes to
     * 'inactive'. AuthController::login() already refuses to authenticate
     * anyone whose status isn't 'active' (see login()'s pending/inactive
     * checks), so the same invariant must hold for a session that was
     * established before the deactivation happened, not just at login time.
     */
    public function test_deactivated_users_existing_session_is_rejected(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        $client = $this->actingAsSessionUser($userId, 'maintenance_staff');

        DB::table('users')->where('user_id', $userId)->update(['status' => 'inactive']);

        $response = $client->getJson('/api/auth/check');

        $response->assertUnauthorized();
    }

    /**
     * TASK 17 wired AuthController::forgotPasswordRequest() to notify every
     * active super_admin with a 'Password Reset Request' notification linked
     * via entity_type='user' / entity_id=<requesting user>, and users.php's
     * renderUserCard() reads targetUser.has_pending_password_reset_request to
     * show a "Reset Password" quick action — but UserController::index()
     * never selected that field, so the button could never appear no matter
     * how many reset requests came in.
     */
    public function test_index_flags_a_user_with_a_pending_password_reset_request(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $requesterId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $otherId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        DB::table('notifications')->insert([
            'user_id' => $adminId,
            'title' => 'Password Reset Request',
            'message' => 'Test User requested a password reset.',
            'entity_type' => 'user',
            'entity_id' => $requesterId,
            'is_read' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/users');

        $response->assertOk();

        $users = collect($response->json('data.users'));
        $requester = $users->firstWhere('user_id', $requesterId);
        $other = $users->firstWhere('user_id', $otherId);

        $this->assertNotNull($requester);
        $this->assertNotNull($other);
        $this->assertTrue((bool) $requester['has_pending_password_reset_request']);
        $this->assertFalse((bool) $other['has_pending_password_reset_request']);
    }

    /**
     * approve() whitelists role via `in:maintenance_admin,maintenance_staff`,
     * but store() only required `string`, so a typo'd or malicious role
     * string could be persisted with no server-side check — silently
     * creating a user who fails every role check in the app. store()'s modal
     * offers exactly super_admin/maintenance_admin/maintenance_staff, so the
     * whitelist mirrors what the UI already sends.
     */
    public function test_store_rejects_an_unrecognized_role_string(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', [
                'full_name' => 'New Person',
                'username' => 'newperson',
                'password' => 'password123',
                'role' => 'not_a_real_role',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('users')->where('username', 'newperson')->count());
    }

    /**
     * reject() already restricts itself to status==='pending' ("Only pending
     * users can be rejected"). approve() is reject()'s twin in the same
     * pending-user workflow but only checked status !== 'active', so it
     * could silently reactivate + reassign the role of an already-inactive
     * user. The UI (renderUserCard()) only ever calls approve() for
     * status==='pending' cards -- Set Active/activate() is the dedicated,
     * role-preserving path for reactivating an inactive account -- so a
     * crafted request was the only way to reach this gap.
     */
    public function test_approve_endpoint_only_operates_on_pending_users(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $inactiveId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'inactive']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$inactiveId}/approve", ['role' => 'maintenance_admin', 'department_id' => $this->seedDepartment('Electrical')]);

        $response->assertStatus(400);
        $this->assertSame('inactive', DB::table('users')->where('user_id', $inactiveId)->value('status'));
        $this->assertSame('maintenance_staff', DB::table('users')->where('user_id', $inactiveId)->value('role'));
    }

    /**
     * deactivate()/activate()/reject() all guard against operating on a
     * super_admin account ("Administrator accounts cannot be changed
     * here"), but approve() was missing the same guard -- defense-in-depth
     * that the other three status-mutating actions in this controller
     * already rely on, in case a super_admin row is ever found with a
     * non-active status.
     */
    public function test_approve_endpoint_rejects_a_super_admin_target(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $targetSuperAdminId = $this->seedUser(['role' => 'super_admin', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetSuperAdminId}/approve", ['role' => 'maintenance_admin', 'department_id' => $this->seedDepartment('Electrical')]);

        $response->assertStatus(400);
        $this->assertSame('super_admin', DB::table('users')->where('user_id', $targetSuperAdminId)->value('role'));
        $this->assertSame('pending', DB::table('users')->where('user_id', $targetSuperAdminId)->value('status'));
    }

    /**
     * forgotPasswordRequest() creates an unread 'Password Reset Request'
     * notification that index() surfaces as has_pending_password_reset_request
     * to drive the "Reset Password" quick action. Once an Administrator
     * actually performs the reset via resetPassword(), that notification
     * must be resolved -- otherwise the request is fulfilled but the quick
     * action (and the underlying flag) stays stuck on forever.
     */
    public function test_reset_password_clears_pending_password_reset_notification(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $targetId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        DB::table('notifications')->insert([
            'user_id' => $adminId,
            'title' => 'Password Reset Request',
            'message' => 'Test User requested a password reset.',
            'entity_type' => 'user',
            'entity_id' => $targetId,
            'is_read' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson("/api/users/{$targetId}/reset-password", ['new_password' => 'newpassword123']);

        $response->assertOk();

        $this->assertSame(
            0,
            DB::table('notifications')
                ->where('entity_type', 'user')
                ->where('entity_id', $targetId)
                ->where('title', 'Password Reset Request')
                ->where('is_read', 0)
                ->count()
        );
    }

    /**
     * UserController::updateProfile() used to trim() the new_password input
     * before hashing it, while AuthController::login() (and every other
     * password-set path: register(), store(), resetPassword(),
     * forgotPasswordReset()) hashes/compares the raw untrimmed value. A user
     * who set a new password containing a leading/trailing space via the
     * Account Settings page would have it silently stored trimmed, then
     * fail to log back in when retyping the same (untrimmed) password.
     * Regression guard: change the password through the real endpoint with
     * a trailing space, then confirm logging in with that exact
     * (untrimmed) string succeeds end-to-end.
     */
    public function test_updating_profile_password_with_whitespace_still_logs_in(): void
    {
        $userId = $this->seedUser([
            'username' => 'profilepwdcheck',
            'role' => 'super_admin',
            'password' => Hash::make('OldPass123!'),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->patchJson('/api/users/profile', [
                'full_name' => 'Test User',
                'current_password' => 'OldPass123!',
                'new_password' => 'NewPass123! ',
            ]);

        $response->assertOk();

        $login = $this->postJson('/api/auth/login', [
            'username' => 'profilepwdcheck',
            'password' => 'NewPass123! ',
        ]);

        $login->assertOk();
        $login->assertJsonPath('success', true);
    }

    /**
     * TASK 41B: Route::patch('profile', ...) requires a multipart/form-data
     * body (the endpoint also accepts a profile_picture file upload). On
     * PHP < 8.4, PHP's SAPI (and Symfony's Request::createFromGlobals()
     * fallback for non-urlencoded content types) only auto-parses
     * multipart/form-data bodies into $_POST/$_FILES for genuine HTTP POST
     * requests -- never for PUT/PATCH/DELETE, even with an identical body.
     * The old frontend sent a real PATCH with a multipart body, so on the
     * live Apache+PHP stack the entire body silently arrived empty:
     * full_name/username/email fell back to their existing stored values
     * and the `if ($newPassword !== '')` gate around the password-hashing
     * block was never entered -- yet the endpoint still returned HTTP 200
     * with "Profile updated successfully", so nothing appeared wrong until
     * the user tried to log back in with the password they'd just "saved".
     *
     * (Laravel's test client -- patchJson()/patch(), including the one
     * above -- builds its Request by injecting parameters directly into
     * Symfony's ParameterBag rather than through the real PHP SAPI/
     * php://input pipeline, so it cannot reproduce this class of bug
     * regardless of method or content-type. Reproducing it required a raw
     * HTTP request against the real running stack, done out-of-band per
     * Task 41B's investigation; that empirical result is documented in
     * TASK_41B_ACCOUNT_SETTINGS_PASSWORD_LOGIN_MISMATCH_ANALYSIS_REPORT.md
     * and is not repeated here.)
     *
     * The fix (Task 41B Phase 2) changes the shared API client
     * (public/frontend/assets/js/api.js, API.updateProfile -- the function
     * account.php's Save Changes handler actually calls via the bare
     * `API` global; account.php also defines its own local
     * `window.API = window.API || {...}` object, but that binding is
     * dead code, unconditionally overwritten by api.js's own
     * `window.API = API` and never referenced anywhere by a `window.API.`
     * prefix, so it was intentionally left unmodified) to submit a
     * genuine POST with a `_method=PATCH` field appended to the FormData,
     * using Laravel's standard method-spoofing convention
     * (Request::enableHttpMethodParameterOverride(), enabled by default)
     * so PHP correctly parses the multipart body while the router still
     * matches Route::patch('profile', ...) with no backend/route changes.
     * This test locks in that contract end-to-end: a POST to
     * /api/users/profile carrying _method=PATCH (i.e. exactly what the
     * fixed api.js now sends) is routed to updateProfile(), persists the
     * new password, and that new password logs in immediately afterward.
     */
    public function test_updating_profile_via_post_with_method_override_allows_immediate_login_with_new_password(): void
    {
        $userId = $this->seedUser([
            'username' => 'profilepostoverride',
            'role' => 'super_admin',
            'password' => Hash::make('OldPass123!'),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->post('/api/users/profile', [
                '_method' => 'PATCH',
                'full_name' => 'Test User',
                'current_password' => 'OldPass123!',
                'new_password' => 'NewPass456!',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $login = $this->postJson('/api/auth/login', [
            'username' => 'profilepostoverride',
            'password' => 'NewPass456!',
        ]);

        $login->assertOk();
        $login->assertJsonPath('success', true);
    }

    /**
     * TASK 54 — AuthController::login() built its $sessionUser array without
     * force_profile_update at all, so header.php's forced-setup redirect
     * (which reads $_SESSION['user']['force_profile_update']) could never
     * actually engage on a freshly logged-in Administrator-created or
     * password-reset account, even though the column was correctly set to 1
     * in the database. Locks in that the login response now carries it.
     */
    public function test_login_response_reports_a_pending_forced_profile_update(): void
    {
        $this->seedUser([
            'username' => 'freshhire',
            'password' => Hash::make('TempPass123!'),
            'force_profile_update' => 1,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'username' => 'freshhire',
            'password' => 'TempPass123!',
        ]);

        $login->assertOk();
        $login->assertJsonPath('data.user.force_profile_update', true);
    }

    public function test_login_response_reports_no_forced_profile_update_for_a_normal_account(): void
    {
        $this->seedUser([
            'username' => 'settledstaff',
            'password' => Hash::make('Secret123!'),
            'force_profile_update' => 0,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'username' => 'settledstaff',
            'password' => 'Secret123!',
        ]);

        $login->assertOk();
        $login->assertJsonPath('data.user.force_profile_update', false);
    }

    /**
     * TASK 55 — completing the forced password change must actually clear
     * the flag in the database, not just unblock the current session's
     * popup: the very next login (which is what the new auto-logout-then-
     * relogin flow requires) has to come back with force_profile_update
     * false, or the user would be dropped right back into the same lockout
     * they just escaped.
     */
    public function test_completing_forced_password_change_clears_the_flag_for_the_next_login(): void
    {
        $userId = $this->seedUser([
            'username' => 'newhirefinishessetup',
            'password' => Hash::make('TempPass123!'),
            'force_profile_update' => 1,
            'role' => 'super_admin',
        ]);

        $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->patchJson('/api/users/profile', [
                'full_name' => 'New Hire',
                'current_password' => 'TempPass123!',
                'new_password' => 'PermanentPass123!',
            ])
            ->assertOk();

        $login = $this->postJson('/api/auth/login', [
            'username' => 'newhirefinishessetup',
            'password' => 'PermanentPass123!',
        ]);

        $login->assertOk();
        $login->assertJsonPath('data.user.force_profile_update', false);
    }

    /**
     * super_admin accounts are permanently excluded from GET /api/users
     * (index()'s whereRaw('LOWER(u.role) <> ?', ['super_admin'])) and
     * setRoleFilter()'s own allowlist ('all'/'maintenance_staff'/
     * 'maintenance_admin') doesn't include 'super_admin' either -- so the
     * "Administrator" role-filter button could never surface a matching
     * user and silently fell back to the "All" filter when clicked, while
     * visually failing to show itself as active. Locks in that the dead
     * control has been removed rather than left to confuse Administrators.
     */
    public function test_role_filters_do_not_offer_a_super_admin_option(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/users.php'));
        $this->assertNotFalse($markup);

        $this->assertStringNotContainsString('data-role-filter="super_admin"', $markup);
    }

    /**
     * The light-theme override for .users-role-filter sets background:#ffffff
     * for every filter pill (including the active one), and the .is-active
     * override only set color/border-color -- leaving the active pill's
     * white text invisible on a white background. Locks in that the
     * light-theme .is-active rule also sets a non-white background.
     */
    public function test_light_theme_active_role_filter_pill_sets_a_visible_background(): void
    {
        $css = file_get_contents(base_path('public/frontend/assets/css/users.inline.css'));
        $this->assertNotFalse($css);

        $ruleStart = strpos($css, "data-theme-resolved='light'] .users-role-filter.is-active");
        $this->assertNotFalse($ruleStart, 'Light-theme active role filter rule not found.');

        $ruleEnd = strpos($css, '}', $ruleStart);
        $rule = substr($css, $ruleStart, $ruleEnd - $ruleStart);

        $this->assertStringContainsString(
            'background:',
            $rule,
            'Light-theme .is-active role filter must set a background so its white text is not invisible.'
        );
    }

    /**
     * TASK 54 — PRIVILEGE-ESCALATION PROBE (step 1 of 2).
     *
     * AuthController::register() creates self-signup accounts with
     * role='user', status='pending'. approve() is the workflow's role-
     * assigning gate: it validates `role` into maintenance_admin|
     * maintenance_staff and refuses anything not status==='pending'
     * ("Only pending users can be approved"), so it can never leave an
     * account active while still carrying the placeholder 'user' role.
     *
     * activate() is documented (in approve()'s own comment) as "the
     * dedicated, role-preserving reactivation path" — i.e. its contract is
     * to un-deactivate an ALREADY-APPROVED user, preserving the role
     * approve() previously assigned. But it only checked status==='active',
     * never status==='pending', so it also accepted a never-approved
     * signup and flipped it to active while preserving its role — yielding
     * role='user' + status='active', a combination the approval workflow
     * is specifically designed to make unreachable.
     *
     * This test pins the guard that closes that path. It mirrors the
     * wording and shape of reject()'s and approve()'s existing pending
     * checks rather than adding a new authorization concept.
     */
    public function test_activate_rejects_a_never_approved_pending_signup(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        // Exactly what AuthController::register() writes for a self-signup.
        $signupId = $this->seedUser(['role' => 'user', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$signupId}/activate");

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $row = DB::table('users')->where('user_id', $signupId)->first();
        $this->assertSame('pending', $row->status, 'A pending signup must stay pending until approved.');
        $this->assertSame('user', $row->role, 'activate() must not confer an effective role on an unapproved account.');
    }

    /**
     * activate()'s legitimate purpose — reactivating a user that approve()
     * already assigned a real role to — must keep working unchanged. This
     * is the regression guard on the fix above: it fails if the new pending
     * check is widened into something that also blocks 'inactive'.
     */
    public function test_activate_still_reactivates_a_previously_approved_inactive_user(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $inactiveId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'inactive']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$inactiveId}/activate")
            ->assertOk()
            ->assertJsonPath('success', true);

        $row = DB::table('users')->where('user_id', $inactiveId)->first();
        $this->assertSame('active', $row->status);
        $this->assertSame('maintenance_staff', $row->role, 'Reactivation must preserve the approved role.');
    }

    /**
     * TASK 54 — PRIVILEGE-ESCALATION PROBE (step 2 of 2): why step 1 matters.
     *
     * EnsureApiAuthenticated gates purely on status==='active'; it never
     * inspects role. EnsureRole is what checks role, but it is applied
     * per-route and ~30 read routes deliberately carry no EnsureRole
     * (any authenticated user may view them — see BuildingsRbacTest's
     * "View (GET) routes ... are unchanged" note). So an account holding
     * the placeholder role='user' with status='active' is not blocked
     * anywhere: it authenticates, and every un-role-gated route serves it.
     *
     * This test documents that reachability directly, using
     * GET /api/departments (routes/web.php — no EnsureRole). It is
     * asserting the CURRENT, INTENTIONAL design of the auth middleware,
     * NOT a defect in it: making EnsureApiAuthenticated role-aware would
     * be exactly the "redesign RBAC" this audit must not do. It exists to
     * pin down the consequence that makes the activate() guard above
     * security-relevant rather than cosmetic — if someone later removes
     * that guard, this test explains what they have re-opened.
     */
    public function test_an_active_account_is_admitted_by_the_auth_middleware_regardless_of_role(): void
    {
        // Only reachable at all if activate() (or a manual DB edit) produced
        // this state; the approval workflow itself cannot.
        $placeholderRoleId = $this->seedUser(['role' => 'user', 'status' => 'active']);

        $this
            ->actingAsSessionUser($placeholderRoleId, 'user')
            ->getJson('/api/departments')
            ->assertOk();

        // ...while a role-gated route still refuses it — proving EnsureRole,
        // not EnsureApiAuthenticated, is the role boundary.
        $this
            ->actingAsSessionUser($placeholderRoleId, 'user')
            ->getJson('/api/users')
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Edit User (UserController::update()) — the fix for "what if a Staff
    // gets promoted to Head?" (approve() only assigns role once, while
    // status==='pending'; there was previously no way to change role/
    // department/designation for an already-active or inactive account).
    // -----------------------------------------------------------------------

    public function test_super_admin_can_edit_a_users_role_department_and_designation(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $oldDept = $this->seedDepartment('Computer');
        $newDept = $this->seedDepartment('Electrical');
        $targetId = $this->seedUser([
            'role' => 'maintenance_staff',
            'status' => 'active',
            'department_id' => $oldDept,
            'full_name' => 'Old Name',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetId}", [
                'full_name' => 'New Name',
                'role' => 'maintenance_admin',
                'department_id' => $newDept,
                'designation' => 'Head Electrical',
            ]);

        $response->assertOk();

        $row = DB::table('users')->where('user_id', $targetId)->first();
        $this->assertSame('New Name', $row->full_name);
        $this->assertSame('maintenance_admin', $row->role);
        $this->assertSame($newDept, (int) $row->department_id);
        $this->assertSame('Head Electrical', $row->designation);
    }

    public function test_edit_requires_a_department_for_a_department_scoped_role(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $targetId = $this->seedUser([
            'role' => 'maintenance_staff',
            'status' => 'active',
            'department_id' => $this->seedDepartment('Computer'),
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetId}", [
                'full_name' => 'Some Name',
                'role' => 'maintenance_admin',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('department_id');
        $this->assertSame('maintenance_staff', DB::table('users')->where('user_id', $targetId)->value('role'));
    }

    public function test_edit_endpoint_rejects_editing_your_own_account(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$adminId}", [
                'full_name' => 'Self Edit Attempt',
                'role' => 'maintenance_staff',
                'department_id' => $this->seedDepartment('Computer'),
            ]);

        $response->assertStatus(400);
    }

    public function test_edit_endpoint_rejects_a_super_admin_target(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $targetSuperAdminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetSuperAdminId}", [
                'full_name' => 'Renamed Admin',
                'role' => 'maintenance_staff',
                'department_id' => $this->seedDepartment('Computer'),
            ]);

        $response->assertStatus(400);
        $this->assertSame('super_admin', DB::table('users')->where('user_id', $targetSuperAdminId)->value('role'));
    }

    public function test_edit_endpoint_rejects_a_pending_user(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $pendingId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'pending']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$pendingId}", [
                'full_name' => 'Should Not Apply',
                'role' => 'maintenance_admin',
                'department_id' => $this->seedDepartment('Computer'),
            ]);

        $response->assertStatus(400);
        $this->assertSame('pending', DB::table('users')->where('user_id', $pendingId)->value('status'));
    }

    public function test_edit_endpoint_rejects_a_super_admin_role_assignment(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $targetId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetId}", [
                'full_name' => 'Trying To Escalate',
                'role' => 'super_admin',
            ]);

        $response->assertStatus(422);
        $this->assertSame('maintenance_staff', DB::table('users')->where('user_id', $targetId)->value('role'));
    }

    public function test_maintenance_admin_cannot_edit_a_user(): void
    {
        $requesterId = $this->seedUser(['role' => 'maintenance_admin']);
        $targetId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);

        $response = $this
            ->actingAsSessionUser($requesterId, 'maintenance_admin')
            ->patchJson("/api/users/{$targetId}", [
                'full_name' => 'Should Not Apply',
                'role' => 'maintenance_admin',
                'department_id' => $this->seedDepartment('Computer'),
            ]);

        $response->assertForbidden();
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();

        // UserController's index()/store() reference force_profile_update,
        // which exists on the real (legacy-originated) users table but isn't
        // part of BuildsSharedTestSchema's minimal users table, since no
        // other Feature test needs it. (username is now part of
        // createUsersTable() itself — TASK 81 Part 1.)
        //
        // `designation` used to be added here too, for the same reason. It is
        // now supplied by createUsersTable() in BuildsSharedTestSchema — the
        // real schema has carried it since migration
        // 2026_09_08_000100_add_out_of_band_user_and_report_columns, and the
        // shared blueprint had simply drifted from it. Adding it a second
        // time here now fails with "duplicate column name: designation".
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_profile_update')->default(0);
        });

        Schema::enableForeignKeyConstraints();
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    /**
     * TASK 53 — the Add New User modal had no protection against creating a
     * second account with the same Last Name + First Name as an existing
     * one; the live system had accumulated three near-identical "Manzo,
     * Kristine ..." accounts this way. UserController::store()/update() now
     * reject a Last+First match via duplicateNameExists(), matched on the
     * "Lastname, Firstname" prefix of full_name (the format
     * users.php::buildFullName() always produces) so a differing middle
     * initial or trailing name (e.g. "Kristine" vs "Kristine Joy") still
     * collides, but a genuinely different first name does not.
     */
    public function test_store_rejects_a_duplicate_last_and_first_name(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Manzo, Kristine D.']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'full_name' => 'Manzo, Kristine Joy D.',
                'username'  => 'kristinejoy',
                'role'      => 'super_admin',
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissingUser('kristinejoy');
    }

    public function test_store_allows_a_different_first_name_with_the_same_last_name(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Manzo, Kristine D.']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'full_name' => 'Manzo, Andres',
                'username'  => 'andresmanzo',
                'role'      => 'super_admin',
            ]));

        $response->assertCreated();
        $this->assertTrue(DB::table('users')->where('username', 'andresmanzo')->exists());
    }

    public function test_store_rejects_a_duplicate_name_even_when_the_existing_account_is_inactive(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        // deactivate()/activate() only flip the status column — the row (and
        // its full_name) is never deleted, so an inactive duplicate must
        // still be caught.
        $this->seedUser([
            'role'      => 'maintenance_staff',
            'status'    => 'inactive',
            'full_name' => 'Manzo, Kristine D.',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/users', $this->registerUserPayload([
                'full_name' => 'Manzo, Kristine D.',
                'username'  => 'kristinedupe',
                'role'      => 'super_admin',
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissingUser('kristinedupe');
    }

    public function test_update_rejects_renaming_into_an_existing_duplicate_name(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $departmentId = $this->seedDepartment('Electrical');
        $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Manzo, Kristine D.']);
        $targetId = $this->seedUser([
            'role'          => 'maintenance_staff',
            'department_id' => $departmentId,
            'full_name'     => 'Cruz, Juan',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetId}", [
                'full_name'     => 'Manzo, Kristine D.',
                'role'          => 'maintenance_staff',
                'department_id' => $departmentId,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            'Cruz, Juan',
            DB::table('users')->where('user_id', $targetId)->value('full_name'),
            'A rejected rename must not have persisted.'
        );
    }

    public function test_update_allows_keeping_the_accounts_own_unchanged_name(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $departmentId = $this->seedDepartment('Electrical');
        $targetId = $this->seedUser([
            'role'          => 'maintenance_staff',
            'department_id' => $departmentId,
            'full_name'     => 'Cruz, Juan',
        ]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->patchJson("/api/users/{$targetId}", [
                'full_name'     => 'Cruz, Juan',
                'role'          => 'maintenance_staff',
                'department_id' => $departmentId,
                'designation'   => 'Senior Technician',
            ]);

        $response->assertOk();
    }
}
