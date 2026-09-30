<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\TestCase;

/**
 * Login spam protection (AuthController::login()).
 *
 * 5 failed sign-ins for the same username from the same IP lock that
 * username+IP. The lock escalates for repeat lockouts within an hour
 * (1 min -> 5 min -> 15 min), is enforced by the server (even the correct
 * password is refused while locked), resets on a successful sign-in, and each
 * lockout is written to the Activity Log.
 */
class LoginThrottleTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('login_throttle_testing');

        Schema::disableForeignKeyConstraints();
        foreach (['activity_logs', 'departments', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createActivityLogsTable();
        Schema::enableForeignKeyConstraints();

        $this->forceLocalTestUrl();

        $this->seedUser([
            'username' => 'mariah',
            'password' => Hash::make('correct-password'),
            'role' => 'maintenance_admin',
            'status' => 'active',
        ]);
        $this->seedUser([
            'username' => 'euclide',
            'password' => Hash::make('other-password'),
            'role' => 'maintenance_staff',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_the_first_four_failures_warn_how_many_attempts_are_left(): void
    {
        foreach ([4, 3, 2, 1] as $remaining) {
            $this->attempt('mariah', 'wrong')
                ->assertStatus(401)
                ->assertJsonPath('data.attempts_remaining', $remaining)
                ->assertJsonPath('data.max_attempts', 5)
                ->assertJsonPath('data.next_lockout_seconds', 60);
        }
    }

    public function test_the_fifth_failure_locks_sign_in_for_one_minute(): void
    {
        $this->failTimes('mariah', 4);

        $this->attempt('mariah', 'wrong')
            ->assertStatus(429)
            ->assertJsonPath('data.retry_after_seconds', 60)
            ->assertJsonPath('data.lockout_seconds', 60);
    }

    public function test_while_locked_even_the_correct_password_is_refused(): void
    {
        $this->failTimes('mariah', 5);

        $this->attempt('mariah', 'correct-password')->assertStatus(429);
    }

    public function test_after_the_countdown_the_user_can_sign_in_and_the_counters_reset(): void
    {
        $this->failTimes('mariah', 5);
        $this->travel(61)->seconds();

        $this->attempt('mariah', 'correct-password')->assertOk();

        // A fresh set of 5 attempts, and the next lock is back to 1 minute.
        $this->attempt('mariah', 'wrong')
            ->assertStatus(401)
            ->assertJsonPath('data.attempts_remaining', 4)
            ->assertJsonPath('data.next_lockout_seconds', 60);
    }

    public function test_repeat_lockouts_escalate_to_five_then_fifteen_minutes(): void
    {
        $this->failTimes('mariah', 5);            // 1st lock: 60 s
        $this->travel(61)->seconds();

        $this->failTimes('mariah', 4);
        $this->attempt('mariah', 'wrong')->assertStatus(429)->assertJsonPath('data.lockout_seconds', 300);
        $this->travel(301)->seconds();

        $this->failTimes('mariah', 4);
        $this->attempt('mariah', 'wrong')->assertStatus(429)->assertJsonPath('data.lockout_seconds', 900);
    }

    public function test_a_lock_only_affects_that_username(): void
    {
        $this->failTimes('mariah', 5);

        $this->attempt('euclide', 'other-password')->assertOk();
    }

    public function test_each_lockout_is_recorded_in_the_activity_log(): void
    {
        $this->failTimes('mariah', 5);

        $log = DB::table('activity_logs')->where('action', 'LOGIN_LOCKOUT')->first();
        $this->assertNotNull($log, 'A lockout must be visible to the Administrator in Activity Logs.');
        $this->assertStringContainsString('"mariah"', (string) $log->details);
        $this->assertStringContainsString('1 minute', (string) $log->details);
    }

    public function test_the_sign_in_page_starts_the_countdown_from_the_servers_answer(): void
    {
        // api.js's login() is the one the sign-in page actually uses (its own
        // copy is overridden by Object.assign), so the error it throws must
        // carry the HTTP status and data — otherwise the 429 countdown can
        // never start.
        $apiJs = file_get_contents(base_path('public/frontend/assets/js/api.js'));
        $loginStart = strpos($apiJs, 'async login(');
        $loginBody = substr($apiJs, $loginStart, strpos($apiJs, 'async logout(') - $loginStart);

        $this->assertStringContainsString('error.status = response.status;', $loginBody);
        $this->assertStringContainsString('error.data = data.data || {};', $loginBody);

        $page = file_get_contents(base_path('public/frontend/pages/index.php'));
        $this->assertStringContainsString('Number(error?.status) === 429', $page);
        $this->assertStringContainsString('startLoginLockTimer(retryAfterSeconds, lockoutSeconds, normalizedUsername);', $page);
        $this->assertStringContainsString('error?.data?.attempts_remaining', $page);
    }

    private function attempt(string $username, string $password)
    {
        return $this->postJson('/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    private function failTimes(string $username, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->attempt($username, 'wrong');
        }
    }
}
