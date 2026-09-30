<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\TestCase;

/**
 * TASK 81 Part 1 — Username Standardization.
 *
 * Locks in that AuthController::login() authenticates by username only
 * (not email), that email is preserved on the user record as a separate
 * attribute but is rejected as a login identifier, that
 * AuthController::register() now requires a unique username alongside
 * email, and that existing status/role gating (pending, inactive) still
 * applies under the username-based flow.
 */
class AuthUsernameLoginTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('auth_username_login_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        RateLimiter::clear('ryanmondido|127.0.0.1');
        RateLimiter::clear('ryaondido27@gmail.com|127.0.0.1');
        RateLimiter::clear('wrongusername|127.0.0.1');
    }

    /**
     * AuthController::login() writes to the raw PHP $_SESSION superglobal
     * (for legacy plain-PHP page compatibility), which — unlike Laravel's
     * session store — is not reset between test methods/classes in the same
     * process. SyncLegacyPhpSession then reads that leftover $_SESSION on
     * every later request and overwrites whatever session other Feature
     * tests set up via withSession(), so it must be cleared here or this
     * file would poison unrelated tests that run afterward.
     */
    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    public function test_valid_username_and_password_logs_in_successfully(): void
    {
        $userId = $this->seedUser([
            'username' => 'ryanmondido',
            'email' => 'ryaondido27@gmail.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'super_admin',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'ryanmondido',
            'password' => 'Secret123!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user.user_id', $userId);
        $response->assertJsonPath('data.user.role', 'super_admin');
    }

    public function test_invalid_username_fails_to_authenticate(): void
    {
        $this->seedUser([
            'username' => 'ryanmondido',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'wrongusername',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Invalid username or password');
    }

    public function test_email_used_as_username_does_not_authenticate(): void
    {
        $this->seedUser([
            'username' => 'ryanmondido',
            'email' => 'ryaondido27@gmail.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'ryaondido27@gmail.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Invalid username or password');
    }

    public function test_pending_user_cannot_authenticate(): void
    {
        $this->seedUser([
            'username' => 'pendinguser',
            'password' => Hash::make('Secret123!'),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'pendinguser',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Your account is pending approval. Please wait for Administrator approval.');
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $this->seedUser([
            'username' => 'inactiveuser',
            'password' => Hash::make('Secret123!'),
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'inactiveuser',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Account is inactive');
    }

    public function test_wrong_password_fails_to_authenticate(): void
    {
        $this->seedUser([
            'username' => 'ryanmondido',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'ryanmondido',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    public function test_login_matches_username_case_insensitively(): void
    {
        $this->seedUser([
            'username' => 'ryanmondido',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'RyanMondido',
            'password' => 'Secret123!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_register_requires_a_unique_username(): void
    {
        $this->seedUser(['username' => 'takenname']);

        $response = $this->postJson('/api/auth/register', [
            'full_name' => 'New Person',
            'username' => 'takenname',
            'email' => 'newperson@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.username.0', 'The username has already been taken.');
    }

    public function test_register_rejects_a_missing_username(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'full_name' => 'New Person',
            'email' => 'newperson@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.username.0', 'The username field is required.');
    }

    public function test_register_creates_a_pending_user_with_username_and_email_and_new_username_can_log_in(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'full_name' => 'New Person',
            'username' => 'newperson',
            'email' => 'newperson@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.username', 'newperson');
        $response->assertJsonPath('data.email', 'newperson@example.com');
        $response->assertJsonPath('data.status', 'pending');

        // A freshly self-registered user is 'pending' until approved, so
        // login must still be rejected even though the username now exists.
        $this->postJson('/api/auth/login', [
            'username' => 'newperson',
            'password' => 'Secret123!',
        ])->assertStatus(403);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createActivityLogsTable();

        Schema::enableForeignKeyConstraints();
    }
}
