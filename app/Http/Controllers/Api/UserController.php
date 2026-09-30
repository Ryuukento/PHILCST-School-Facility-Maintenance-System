<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RoleNormalizerService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponder;

    /**
     * TASK 37 — roles whose accounts are meaningless without a department.
     *
     * Both of these are department-scoped roles: report visibility, assignment
     * and the users-page role label are all resolved through the account's
     * department. A Head with no department renders as a bare "Head" in
     * users.php::getRoleLabel() and matches no department-scoped query, so
     * creating one produces an account that looks valid and works nowhere.
     * super_admin is deliberately absent — an Administrator is system-wide and
     * has no department by design (the live Administrator account carries
     * department_id = NULL).
     *
     * Stored in canonical form. The submitted role is normalized through
     * RoleNormalizerService before being compared against this list, so the
     * legacy aliases that Rule::in() accepts below ('admin_maintenance',
     * 'eelab_staff', 'maintenance_personnel') cannot be used to slip past the
     * requirement.
     */
    private const DEPARTMENT_REQUIRED_ROLES = ['maintenance_admin', 'maintenance_staff'];

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'     => ['required', 'string', 'max:255'],
            'username'      => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'password'      => ['required', 'string', 'min:8'],
            // The create-user modal only ever offers these three roles;
            // without a whitelist a typo'd/malicious role string could be
            // persisted and silently fail every role check in the app.
            'role'          => ['required', 'string', Rule::in(RoleNormalizerService::rawValuesFor([
                'super_admin', 'maintenance_admin', 'maintenance_staff',
            ]))],
            // TASK 37 — department is conditional on the role, enforced here
            // rather than only in the modal: hiding the field in JavaScript
            // says nothing about what a direct POST to this endpoint can do.
            'department_id' => [
                Rule::requiredIf(fn (): bool => $this->roleRequiresDepartment($request->input('role'))),
                'nullable',
                'integer',
                'exists:departments,department_id',
            ],
            'designation'   => ['nullable', 'string', 'max:255'],
        ], [
            // Without this the API returns "The department id field is
            // required", which is what the register modal renders verbatim.
            'department_id.required' => 'Department is required for this role.',
        ]);

        // Duplicate-account guard: the Add New User modal already produced
        // three near-identical "Manzo, Kristine ... " accounts (a typo'd
        // re-entry) because nothing stopped it. Block a new account whenever
        // its Last Name + First Name already matches an existing one —
        // across every status, since "Set Inactive" never deletes the row
        // (see deactivate()), so an inactive near-duplicate would otherwise
        // still slip past a check scoped to active users only.
        if ($this->duplicateNameExists($validated['full_name'])) {
            return $this->fail('An account with this first and last name already exists.', 422);
        }

        $userId = DB::table('users')->insertGetId([
            'full_name'            => $validated['full_name'],
            'username'             => strtolower($validated['username']),
            'password'             => Hash::make($validated['password']),
            'role'                 => $validated['role'],
            'status'               => 'active',
            'department_id'        => $validated['department_id'] ?? null,
            'designation'          => $validated['designation'] ?? null,
            'force_profile_update' => 1,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return $this->ok('User created successfully', ['user_id' => $userId], 201);
    }

    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        $user->update([
            'password'             => Hash::make($validated['new_password']),
            'force_profile_update' => 1,
        ]);

        // Resolve any pending "Password Reset Request" notification(s) for
        // this user now that an Administrator has actually reset the
        // password — otherwise index()'s has_pending_password_reset_request
        // flag (and the "Reset Password" quick action it drives) stays
        // stuck on forever, since nothing else ever marks it read.
        DB::table('notifications')
            ->where('entity_type', 'user')
            ->where('entity_id', $user->user_id)
            ->where('title', 'Password Reset Request')
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return $this->ok('Password reset successfully');
    }

    public function index()
    {
        $users = DB::table('users as u')
            ->select([
                'u.user_id', 'u.full_name', 'u.username', 'u.email', 'u.role',
                'u.status', 'u.avatar', 'u.created_at', 'u.designation',
                'u.department_id', 'd.name as department_name',
            ])
            // TASK 17 wired forgotPasswordRequest() to notify Administrators
            // via a 'Password Reset Request' notification linked with
            // entity_type='user' / entity_id=<requesting user>, and
            // users.php's renderUserCard() already reads this flag to show a
            // "Reset Password" quick action — but this query never selected
            // it, so that button could never appear.
            ->selectRaw(
                'EXISTS (
                    SELECT 1 FROM notifications n
                    WHERE n.entity_type = ?
                      AND n.entity_id = u.user_id
                      AND n.title = ?
                      AND n.is_read = 0
                ) as has_pending_password_reset_request',
                ['user', 'Password Reset Request']
            )
            ->leftJoin('departments as d', 'd.department_id', '=', 'u.department_id')
            ->whereRaw('LOWER(u.role) <> ?', ['super_admin'])
            ->orderByDesc('u.created_at')
            ->get();

        return $this->ok('Users retrieved', ['users' => $users]);
    }

    public function deactivate(Request $request, User $user)
    {
        $authUser = $request->session()->get('auth_user', []);
        if ((int)($authUser['user_id'] ?? 0) === (int)$user->user_id) {
            return $this->fail('Cannot set your own account to inactive', 400);
        }

        if (strtolower((string)$user->role) === 'super_admin') {
            return $this->fail('Administrator accounts cannot be changed here', 400);
        }

        if (strtolower((string)$user->status) === 'inactive') {
            return $this->ok('User is already inactive');
        }

        $user->update(['status' => 'inactive']);
        $this->logStatusChange($request, 'INACTIVATE_USER', (int)$user->user_id, 'Set user #' . $user->user_id . ' to inactive');

        return $this->ok('User set to inactive successfully');
    }

    public function activate(Request $request, User $user)
    {
        if (strtolower((string)$user->role) === 'super_admin') {
            return $this->fail('Administrator accounts cannot be changed here', 400);
        }

        if (strtolower((string)$user->status) === 'active') {
            return $this->ok('User is already active');
        }

        // TASK 54 — activate() is the role-PRESERVING reactivation path (see
        // approve()'s comment below), so it must only ever run on a user
        // approve() has already assigned a real role to. A never-approved
        // self-signup is role='user'/status='pending' (AuthController::
        // register()); preserving that role while flipping status to
        // 'active' would produce role='user' + status='active' — a state the
        // approval workflow is built to make unreachable, and one that
        // EnsureApiAuthenticated (which gates on status only, never role)
        // admits to every route that carries no EnsureRole. Pending users
        // must go through approve(), which is where a role gets assigned.
        // Mirrors reject()'s and approve()'s existing pending checks.
        if (strtolower((string)$user->status) === 'pending') {
            return $this->fail('Pending users must be approved, not activated', 400);
        }

        $user->update(['status' => 'active']);
        $this->logStatusChange($request, 'ACTIVATE_USER', (int)$user->user_id, 'Set user #' . $user->user_id . ' to active');

        return $this->ok('User set to active successfully');
    }

    /**
     * Edit an already-approved account's Full Name, Role, Department and
     * Designation.
     *
     * Why this exists: approve() is the ONLY place a role was ever assigned,
     * and it only runs once, while status is still 'pending'. There was no
     * way to reflect a real-world promotion (Maintenance Staff -> Head
     * Maintenance) or a department transfer for an account that is already
     * active/inactive — an Administrator had to reject and re-register the
     * person from scratch, losing their account history. This is the
     * general-purpose edit path for that.
     *
     * Deliberately excluded from this endpoint (unlike store()):
     *  - username — left read-only in the UI. Changing it here would be easy
     *    to type past validation but the field is referenced by convention
     *    across audit trails/notifications as a stable handle; a rename
     *    feature is a separate, larger decision than this task covers.
     *  - password — already has its own dedicated resetPassword() action and
     *    UI button; duplicating it here would just be two ways to do one
     *    thing.
     *  - role=super_admin — index() never lists super_admin accounts in the
     *    first place (see its whereRaw() exclusion), so the edit modal never
     *    offers this option; enforced here too in case of a direct API call.
     */
    public function update(Request $request, User $user)
    {
        $authUser = $request->session()->get('auth_user', []);
        if ((int)($authUser['user_id'] ?? 0) === (int)$user->user_id) {
            return $this->fail('Cannot edit your own account here', 400);
        }

        if (strtolower((string)$user->role) === 'super_admin') {
            return $this->fail('Administrator accounts cannot be changed here', 400);
        }

        // Mirrors activate()'s reasoning: a 'pending' account has never been
        // through approve() and so has no real role assigned yet. Editing it
        // here would let an Administrator skip the approval decision
        // entirely. Pending accounts must go through Approve/Reject first.
        if (strtolower((string)$user->status) === 'pending') {
            return $this->fail('Pending users must be approved before they can be edited', 400);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'role'      => ['required', 'string', Rule::in(RoleNormalizerService::rawValuesFor([
                'maintenance_admin', 'maintenance_staff',
            ]))],
            'department_id' => [
                Rule::requiredIf(fn (): bool => $this->roleRequiresDepartment($request->input('role'))),
                'nullable',
                'integer',
                'exists:departments,department_id',
            ],
            'designation' => ['nullable', 'string', 'max:255'],
        ], [
            'department_id.required' => 'Department is required for this role.',
        ]);

        // Same duplicate-name guard as store() — renaming into an existing
        // Last Name + First Name combo would recreate the same "Manzo,
        // Kristine ..." collision, just via Edit instead of Add New User.
        if ($this->duplicateNameExists($validated['full_name'], (int)$user->user_id)) {
            return $this->fail('An account with this first and last name already exists.', 422);
        }

        $previousRole = (string)$user->role;

        $user->update([
            'full_name'     => $validated['full_name'],
            'role'          => $validated['role'],
            'department_id' => $validated['department_id'] ?? null,
            'designation'   => $validated['designation'] ?? null,
        ]);

        $roleChanged = RoleNormalizerService::normalize($previousRole) !== RoleNormalizerService::normalize($validated['role']);

        $this->logStatusChange(
            $request,
            'UPDATE_USER',
            (int)$user->user_id,
            $roleChanged
                ? 'Edited user #' . $user->user_id . ' — role changed from ' . $previousRole . ' to ' . $validated['role']
                : 'Edited user #' . $user->user_id . ' — profile details updated'
        );

        return $this->ok('User updated successfully', [
            'user_id' => (int)$user->user_id,
            'role'    => $validated['role'],
        ]);
    }

    public function approve(Request $request, User $user)
    {
        // 2026-09-27 — the Administrator now also picks the department when
        // approving. Self-registration cannot know it and used to drop every
        // new account into the first active department, while report and
        // dispatch permissions are department-based. Same rule as store():
        // both maintenance roles require an active department.
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:maintenance_admin,maintenance_staff'],
            'department_id' => [
                Rule::requiredIf(fn (): bool => $this->roleRequiresDepartment($request->input('role'))),
                'nullable',
                'integer',
                Rule::exists('departments', 'department_id')->where('status', 'active'),
            ],
        ], [
            'department_id.required' => 'Please choose the department this user belongs to.',
            'department_id.exists' => 'Please choose an active department.',
        ]);

        // Mirrors the same guard deactivate()/activate()/reject() already
        // enforce ("Administrator accounts cannot be changed here") — this
        // was the one status-mutating action in this controller missing it.
        if (strtolower((string)$user->role) === 'super_admin') {
            return $this->fail('Administrator accounts cannot be changed here', 400);
        }

        // approve() is reject()'s twin in the pending-user workflow, which
        // already restricts itself to status==='pending' ("Only pending
        // users can be rejected"). approve() only checked status !== 'active',
        // so it could silently reactivate + reassign the role of an
        // already-inactive user — a path the UI never takes (renderUserCard()
        // only offers Approve for status==='pending' cards; Set Active/
        // activate() is the dedicated, role-preserving reactivation path).
        if (strtolower((string)$user->status) !== 'pending') {
            return $this->fail('Only pending users can be approved', 400);
        }

        $user->update([
            'role' => $validated['role'],
            'department_id' => $validated['department_id'] ?? $user->department_id,
            'status' => 'active',
        ]);

        $this->logStatusChange(
            $request,
            'APPROVE_USER',
            (int)$user->user_id,
            'Approved user #' . $user->user_id . ' and assigned role ' . $validated['role']
                . (isset($validated['department_id']) ? ' (department #' . $validated['department_id'] . ')' : '')
        );

        return $this->ok('User approved successfully', [
            'user_id' => (int)$user->user_id,
            'role' => $validated['role'],
            'status' => 'active',
        ]);
    }

    public function reject(Request $request, User $user)
    {
        $authUser = $request->session()->get('auth_user', []);
        if ((int)($authUser['user_id'] ?? 0) === (int)$user->user_id) {
            return $this->fail('Cannot reject your own account', 400);
        }

        if (strtolower((string)$user->role) === 'super_admin') {
            return $this->fail('Administrator accounts cannot be rejected here', 400);
        }

        if (strtolower((string)$user->status) !== 'pending') {
            return $this->fail('Only pending users can be rejected', 400);
        }

        $targetUserId = (int)$user->user_id;

        DB::transaction(function () use ($targetUserId): void {
            User::query()->where('user_id', $targetUserId)->delete();
        });

        $this->logStatusChange(
            $request,
            'REJECT_USER',
            $targetUserId,
            'Rejected pending user #' . $targetUserId . ' and deleted account'
        );

        return $this->ok('Pending user rejected and account deleted successfully');
    }

    public function updateProfile(Request $request)
    {
        $authUser = $request->session()->get('auth_user') ?? $request->session()->get('user', []);
        $userId   = (int)(is_array($authUser) ? ($authUser['user_id'] ?? 0) : 0);
        if ($userId <= 0) {
            return $this->fail('Unauthorized', 401);
        }

        $currentUser = DB::table('users')->where('user_id', $userId)->first();
        if (!$currentUser) {
            return $this->fail('User not found', 404);
        }

        $fullName        = trim((string)$request->input('full_name', $currentUser->full_name ?? ''));
        $username        = strtolower(trim((string)$request->input('username', '')));
        $currentPassword = (string)$request->input('current_password', '');
        // Deliberately NOT trimmed: every other password-set path in this
        // codebase (register(), UserController::store(), resetPassword())
        // hashes the raw input, and AuthController::
        // login() compares the raw input via Hash::check() without trimming.
        // Trimming only here silently stored a different string than what
        // the user actually typed, so a new password containing a leading/
        // trailing space would hash correctly on save but never match again
        // at login. Keeping this consistent with the rest of the app fixes
        // that mismatch.
        $newPassword     = (string)$request->input('new_password', '');
        $isForcedSetup   = !empty($currentUser->force_profile_update);

        if ($fullName === '') {
            return $this->fail('Full name is required', 422);
        }

        if ($username !== '' && !preg_match('/^[a-z0-9_]{3,50}$/', $username)) {
            return $this->fail('Username must be 3-50 characters: lowercase letters, numbers, underscores only', 422);
        }

        if ($username !== '' && $username !== ($currentUser->username ?? '')) {
            $taken = DB::table('users')
                ->where('username', $username)
                ->where('user_id', '!=', $userId)
                ->exists();
            if ($taken) {
                return $this->fail('Username is already taken', 422);
            }
        }

        if ($isForcedSetup && $newPassword === '') {
            return $this->fail('For first-time account setup, setting a new password is required', 422);
        }

        $passwordHash = null;
        if ($newPassword !== '') {
            if ($currentPassword === '') {
                return $this->fail('Current password is required to change your password', 422);
            }
            if (!Hash::check($currentPassword, $currentUser->password ?? '')) {
                return $this->fail('Current password is incorrect', 422);
            }
            if (strlen($newPassword) < 8) {
                return $this->fail('New password must be at least 8 characters', 422);
            }
            $passwordHash = Hash::make($newPassword);
        }

        $avatarPath = $currentUser->avatar ?? null;
        if ($request->hasFile('profile_picture')) {
            $file         = $request->file('profile_picture');
            $allowedMimes = [
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                'image/png'  => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            ];
            $mime = $file->getMimeType();
            if (!isset($allowedMimes[$mime])) {
                return $this->fail('Only JPG, PNG, WEBP, or GIF images are allowed', 422);
            }
            if ($file->getSize() > 3 * 1024 * 1024) {
                return $this->fail('Profile picture must be 3MB or less', 422);
            }
            $uploadDir = public_path('frontend/assets/uploads/avatars');
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $ext      = $allowedMimes[$mime];
            $fileName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $file->move($uploadDir, $fileName);

            if (!empty($avatarPath) && str_contains((string)$avatarPath, '/frontend/assets/uploads/avatars/')) {
                $oldPath = public_path(str_replace('/School_Facility_Maintenance_System/', '', (string)$avatarPath));
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $avatarPath = '/School_Facility_Maintenance_System/frontend/assets/uploads/avatars/' . $fileName;
        }

        $updates = [
            'full_name'            => $fullName,
            'username'             => $username !== '' ? $username : ($currentUser->username ?? null),
            'avatar'               => $avatarPath,
            'force_profile_update' => 0,
            'updated_at'           => now(),
        ];
        if ($passwordHash) {
            $updates['password'] = $passwordHash;
        }

        DB::table('users')->where('user_id', $userId)->update($updates);

        $updatedAuthUser = array_merge((array)$authUser, [
            'full_name' => $fullName,
            'avatar'    => $avatarPath,
        ]);
        $request->session()->put('auth_user', $updatedAuthUser);
        $request->session()->put('user', $updatedAuthUser);

        return $this->ok('Profile updated successfully', [
            'user' => [
                'user_id'              => $userId,
                'full_name'            => $fullName,
                'avatar'               => $avatarPath,
                'force_profile_update' => 0,
            ],
        ]);
    }

    /**
     * TASK 37 — does this submitted role oblige the account to carry a
     * department?
     *
     * Normalizes through the project's existing RoleNormalizerService rather
     * than string-comparing the raw input. That matters: Rule::in() above
     * accepts every alias rawValuesFor() expands to, so a POST carrying
     * role='admin_maintenance' is a legitimate Head. Comparing the raw string
     * against 'maintenance_admin' would treat it as a role with no department
     * obligation and let a department-less Head through the one check meant to
     * stop it.
     */
    private function roleRequiresDepartment(mixed $role): bool
    {
        if (!is_string($role)) {
            return false;
        }

        return in_array(
            RoleNormalizerService::normalize($role),
            self::DEPARTMENT_REQUIRED_ROLES,
            true
        );
    }

    /**
     * True when some OTHER user already has the same Last Name + First Name
     * as $fullName. `full_name` is stored as one free-text column (no
     * separate first_name/last_name in the schema) built by the frontend's
     * buildFullName() as "Lastname, Firstname MI." — so both sides of the
     * comparison are derived the same way: split once on the first comma for
     * the last name, then take only the first word of whatever follows for
     * the first name (dropping middle initial/suffix, matching the "Last +
     * First only" duplicate policy so "Manzo, Kristine D." collides with
     * "Manzo, Kristine Joy D.").
     *
     * Checked against every status (active/inactive/suspended/pending), not
     * just active — deactivate()/activate() only flip the status column and
     * never delete the row, so an inactivated near-duplicate would otherwise
     * still slip through undetected.
     */
    private function duplicateNameExists(string $fullName, ?int $excludeUserId = null): bool
    {
        $key = $this->nameKey($fullName);
        if ($key === '') {
            return false;
        }

        return User::query()
            ->when($excludeUserId !== null, fn ($query) => $query->where('user_id', '!=', $excludeUserId))
            ->pluck('full_name')
            ->contains(fn (?string $existingFullName): bool => $this->nameKey((string)$existingFullName) === $key);
    }

    private function nameKey(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return '';
        }

        [$lastName, $rest] = array_pad(explode(',', $fullName, 2), 2, '');
        $lastName = mb_strtolower(trim($lastName));

        $rest = trim($rest);
        $firstName = $rest === '' ? '' : mb_strtolower((string)strtok($rest, " \t\n\r\0\x0B"));

        if ($lastName === '' || $firstName === '') {
            return '';
        }

        return $lastName . '|' . $firstName;
    }

    private function logStatusChange(Request $request, string $action, int $targetUserId, string $details): void
    {
        $authUser = $request->session()->get('auth_user', []);

        $this->activityLogService->log([
            'user_id' => (int)($authUser['user_id'] ?? 0),
            'user_role' => (string)($authUser['role'] ?? $request->session()->get('role', '')),
            'action' => $action,
            'module' => 'user_management',
            'entity_type' => 'user',
            'entity_id' => $targetUserId,
            'details' => $details,
        ], $request);
    }
}
