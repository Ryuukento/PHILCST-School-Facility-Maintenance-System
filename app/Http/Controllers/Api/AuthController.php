<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RoleNormalizerService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponder;

    // Login spam protection. MAX_LOGIN_ATTEMPTS failed sign-ins for the same
    // username from the same IP (counted within LOGIN_ATTEMPT_WINDOW_SECONDS)
    // lock that username+IP. Repeat lockouts within LOGIN_LOCKOUT_MEMORY_SECONDS
    // escalate through LOGIN_LOCKOUT_STEPS (1 min -> 5 min -> 15 min), so a
    // user who simply mistyped waits briefly while a script hammering the form
    // is slowed down sharply. A successful sign-in resets all of it.
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_ATTEMPT_WINDOW_SECONDS = 900;
    private const LOGIN_LOCKOUT_STEPS = [60, 300, 900];
    private const LOGIN_LOCKOUT_MEMORY_SECONDS = 3600;
    private const RESET_REQUEST_LOCKOUT_SECONDS = 60;

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier  = strtolower(trim($validated['username']));
        $throttleKey = $this->throttleKey($identifier, (string) $request->ip());

        // While locked, even the correct password is refused until the
        // countdown ends — otherwise the lock would not stop guessing.
        if (RateLimiter::tooManyAttempts($this->lockKey($throttleKey), 1)) {
            return $this->fail('Too many login attempts. Please try again later.', 429, [
                'max_attempts' => self::MAX_LOGIN_ATTEMPTS,
                'retry_after_seconds' => max(1, RateLimiter::availableIn($this->lockKey($throttleKey))),
            ]);
        }

        $user = User::query()->where('username', $identifier)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->failedLoginResponse($throttleKey, 'Invalid username or password', 401);
        }

        if (strtolower((string)$user->status) === 'pending') {
            return $this->failedLoginResponse(
                $throttleKey,
                'Your account is pending approval. Please wait for Administrator approval.',
                403
            );
        }

        if (strtolower((string)$user->status) !== 'active') {
            return $this->failedLoginResponse($throttleKey, 'Account is inactive', 403);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($this->lockKey($throttleKey));
        RateLimiter::clear($this->lockoutCountKey($throttleKey));

        // Regenerate the session ID on successful login to prevent session fixation.
        $request->session()->regenerate();

        $sessionUser = [
            'user_id'              => $user->user_id,
            'full_name'            => $user->full_name,
            'email'                => $user->email,
            'role'                 => $this->normalizeRoleAlias($user->role),
            'status'               => $user->status,
            'department_id'        => $user->department_id,
            'avatar'               => $user->avatar,
            // TASK 54 — without this, header.php's forced-setup redirect
            // (which reads $_SESSION['user']['force_profile_update']) never
            // actually engages: this key simply never existed in the session
            // user array, so an Administrator-created or password-reset
            // account could log in and navigate freely despite the flag
            // being 1 in the database.
            'force_profile_update' => (bool) $user->force_profile_update,
        ];

        $request->session()->put('auth_user', $sessionUser);

        // Also set 'user' for backward compatibility with old PHP code
        $request->session()->put('user', $sessionUser);

        // Legacy PHP APIs depend on top-level session keys.
        $request->session()->put('user_id', $user->user_id);
        $request->session()->put('role', $this->normalizeRoleAlias($user->role));
        $request->session()->put('last_activity', time());

        // Sync to native PHP $_SESSION so plain-PHP frontend pages can read the
        // user without going through Laravel's session store.
        // SyncLegacyPhpSession middleware has already called session_start() before
        // this controller runs, so $_SESSION is writable here.
        $_SESSION['auth_user']     = $sessionUser;
        $_SESSION['user']          = $sessionUser;
        $_SESSION['user_id']       = $user->user_id;
        $_SESSION['role']          = $this->normalizeRoleAlias($user->role);
        $_SESSION['last_activity'] = time();

        $this->activityLogService->log([
            'user_id' => $user->user_id,
            'user_role' => $this->normalizeRoleAlias($user->role),
            'action' => 'LOGIN',
            'module' => 'auth',
            'entity_type' => 'user',
            'entity_id' => $user->user_id,
            'details' => 'User logged in successfully.',
        ], $request);

        return $this->ok('Login successful', ['user' => $request->session()->get('auth_user')]);
    }

    public function register(Request $request)
    {
        // TASK 81 Part 1 — Username Standardization. Self-registration must
        // now also collect a username (the field login() authenticates
        // against), matching the same required/min:3/max:50/unique rule
        // UserController::store() already enforces for Administrator-created
        // accounts. Email stays required too — it is not the login
        // identifier anymore, but it is still a real, independent user
        // attribute (contact info; see class doc + forgotPasswordRequest()
        // below), so it is deliberately NOT removed here.
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255', "regex:/^(?=.*\\p{L})[\\p{L} .'-]+$/u"],
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'full_name.regex' => 'Name must contain only letters, spaces, apostrophes, and hyphens (no numbers).',
        ]);

        if ($validator->fails()) {
            return $this->fail('Validation error', 422, [
                'errors' => $validator->errors(),
            ]);
        }

        $validated = $validator->validated();

        $departmentId = Department::query()
            ->where('status', 'active')
            ->orderBy('department_id')
            ->value('department_id');

        $user = User::query()->create([
            'full_name' => $validated['full_name'],
            'username' => strtolower(trim($validated['username'])),
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
            'department_id' => $departmentId,
            'status' => 'pending',
        ]);

        $this->activityLogService->log([
            'user_id' => $user->user_id,
            'user_role' => 'user',
            'action' => 'REGISTER',
            'module' => 'auth',
            'entity_type' => 'user',
            'entity_id' => $user->user_id,
            'details' => 'Self-registered account (pending approval).',
        ], $request);

        return $this->ok('Registration submitted. Your account is pending Administrator approval.', [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status,
        ], 201);
    }

    public function forgotPasswordRequest(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $identifier = strtolower(trim($validated['username']));
        $throttleKey = 'forgot-password-request|' . $identifier . '|' . (string)$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            return $this->fail('Please wait before sending another reset request.', 429, [
                'retry_after_seconds' => RateLimiter::availableIn($throttleKey),
            ]);
        }

        RateLimiter::hit($throttleKey, self::RESET_REQUEST_LOCKOUT_SECONDS);

        $user = User::query()->where('username', $identifier)->first();

        if (!$user) {
            return $this->ok('If this account is registered, Administrator has been notified.', [
                'mode' => 'admin_notify',
            ]);
        }

        try {
            $notificationColumns = collect(DB::select('SHOW COLUMNS FROM notifications'))
                ->map(fn ($column) => strtolower((string)($column->Field ?? '')))
                ->all();
            $hasReportIdColumn = in_array('report_id', $notificationColumns, true);

            $superAdmins = DB::table('users')
                ->select('user_id', 'full_name', 'email')
                ->whereIn(DB::raw('LOWER(TRIM(role))'), ['super_admin', 'super admin'])
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhereRaw('LOWER(TRIM(status)) = ?', ['active']);
                })
                ->get();

            foreach ($superAdmins as $admin) {
                $payload = [
                    'user_id' => $admin->user_id,
                    'title' => 'Password Reset Request',
                    'message' => ($user->full_name ?: $user->email) . ' requested a password reset. Please update the password in User Management.',
                    'is_read' => 0,
                    'created_at' => now(),
                    // TASK 17 — Notification Deep Linking: this notification is
                    // about the requesting user's account, so the admin can
                    // deep-link straight to it in User Management.
                    'entity_type' => 'user',
                    'entity_id' => $user->user_id,
                ];

                if ($hasReportIdColumn) {
                    $payload['report_id'] = null;
                }

                DB::table('notifications')->insert($payload);
            }

            $this->activityLogService->log([
                'user_id' => $user->user_id,
                'user_role' => $this->normalizeRoleAlias((string) $user->role),
                'action' => 'PASSWORD_RESET_REQUEST',
                'module' => 'auth',
                'entity_type' => 'user',
                'entity_id' => $user->user_id,
                'details' => 'Requested Administrator password reset assistance.',
            ], $request);

            return $this->ok('Administrator has been notified to reset your password.');
        } catch (\Throwable $e) {
            \Log::warning('Password reset request notification exception', [
                'username' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return $this->fail('Unable to notify Administrator right now. Please try again later.', 500);
        }
    }

    public function logout(Request $request)
    {
        $authUser = $request->session()->get('auth_user') ?? $request->session()->get('user');

        if (is_array($authUser) && isset($authUser['user_id'])) {
            $this->activityLogService->log([
                'user_id' => (int) $authUser['user_id'],
                'user_role' => (string) ($authUser['role'] ?? ''),
                'action' => 'LOGOUT',
                'module' => 'auth',
                'entity_type' => 'user',
                'entity_id' => (int) $authUser['user_id'],
                'details' => 'User logged out.',
            ], $request);
        }

        // Clear native PHP $_SESSION so legacy frontend pages lose access too
        $_SESSION = [];

        $request->session()->forget('auth_user');
        $request->session()->forget('user');
        $request->session()->forget('user_id');
        $request->session()->forget('role');
        $request->session()->forget('last_activity');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok('Logout successful');
    }

    public function check(Request $request)
    {
        $user = $request->session()->get('auth_user') ?? $request->session()->get('user');
        return $this->ok('Session active', ['user' => $user]);
    }

    private function normalizeRoleAlias(?string $role): string
    {
        return RoleNormalizerService::normalizeWithStaffDefault($role);
    }

    private function throttleKey(string $email, string $ipAddress): string
    {
        return strtolower($email) . '|' . $ipAddress;
    }

    private function failedLoginResponse(string $throttleKey, string $message, int $status)
    {
        RateLimiter::hit($throttleKey, self::LOGIN_ATTEMPT_WINDOW_SECONDS);

        $attemptsUsed = RateLimiter::attempts($throttleKey);
        $attemptsRemaining = max(0, self::MAX_LOGIN_ATTEMPTS - $attemptsUsed);

        if ($attemptsUsed >= self::MAX_LOGIN_ATTEMPTS) {
            // Start a lock whose length depends on how many times this
            // username+IP has already been locked within the last hour.
            RateLimiter::clear($throttleKey);
            RateLimiter::hit($this->lockoutCountKey($throttleKey), self::LOGIN_LOCKOUT_MEMORY_SECONDS);
            $lockoutNumber = RateLimiter::attempts($this->lockoutCountKey($throttleKey));
            $lockoutSeconds = $this->lockoutSecondsFor($lockoutNumber);
            RateLimiter::hit($this->lockKey($throttleKey), $lockoutSeconds);

            [$username, $ipAddress] = array_pad(explode('|', $throttleKey, 2), 2, '');
            $this->activityLogService->log([
                'action'      => 'LOGIN_LOCKOUT',
                'module'      => 'auth',
                'entity_type' => 'user',
                'details'     => sprintf(
                    'Sign-in for "%s" from %s locked for %s after %d failed attempts (lockout #%d within an hour).',
                    $username,
                    $ipAddress !== '' ? $ipAddress : 'unknown IP',
                    $this->describeSeconds($lockoutSeconds),
                    self::MAX_LOGIN_ATTEMPTS,
                    $lockoutNumber
                ),
                'dedupe_window_seconds' => 0,
            ]);

            return $this->fail('Too many login attempts. Please try again later.', 429, [
                'max_attempts' => self::MAX_LOGIN_ATTEMPTS,
                'retry_after_seconds' => $lockoutSeconds,
                'lockout_seconds' => $lockoutSeconds,
            ]);
        }

        return $this->fail($message, $status, [
            'max_attempts' => self::MAX_LOGIN_ATTEMPTS,
            'attempts_remaining' => $attemptsRemaining,
            // So the sign-in form can warn how long the NEXT lock would be.
            'next_lockout_seconds' => $this->lockoutSecondsFor(
                RateLimiter::attempts($this->lockoutCountKey($throttleKey)) + 1
            ),
        ]);
    }

    private function lockKey(string $throttleKey): string
    {
        return 'login-lock:' . $throttleKey;
    }

    private function lockoutCountKey(string $throttleKey): string
    {
        return 'login-lockouts:' . $throttleKey;
    }

    /** 1st lockout -> 60 s, 2nd -> 300 s, 3rd and later -> 900 s. */
    private function lockoutSecondsFor(int $lockoutNumber): int
    {
        $steps = self::LOGIN_LOCKOUT_STEPS;
        return $steps[min(max($lockoutNumber, 1), count($steps)) - 1];
    }

    private function describeSeconds(int $seconds): string
    {
        return $seconds % 60 === 0
            ? ($seconds / 60) . ' minute' . ($seconds === 60 ? '' : 's')
            : $seconds . ' seconds';
    }
}
