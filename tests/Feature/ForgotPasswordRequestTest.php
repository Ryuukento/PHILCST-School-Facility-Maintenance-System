<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\TestCase;

/**
 * FORGOT PASSWORD "SUBMIT REQUEST" BUG — regression coverage.
 *
 * THE BUG
 * -------
 * On the login page, entering a valid username and clicking "SUBMIT REQUEST"
 * always showed "Could not submit request. Please try again or contact your
 * Administrator directly." — and NO HTTP request was ever sent (laravel.log
 * contained zero `api/auth/forgot_password_request` entries, even though
 * SyncLegacyPhpSession logs every single request that reaches Laravel).
 *
 * The cause was entirely client side. public/frontend/pages/index.php used:
 *
 *     window.API = window.API || { ...login, register, forgotPasswordRequest }
 *
 * and it loads public/frontend/assets/js/api.js FIRST. TASK 98.2 appended
 * `window.API = API;` to the end of api.js (so main.js's performLogout() could
 * reach API.logout()). From that moment on, `window.API` was already truthy by
 * the time index.php ran, the `||` short-circuited, and the page's entire
 * object literal was silently discarded.
 *
 * api.js defines login() but NOT register() and NOT forgotPasswordRequest() —
 * those two exist only in index.php. So login kept working by coincidence
 * while "Submit Request" threw
 * `TypeError: window.API.forgotPasswordRequest is not a function`, which the
 * submit handler's catch turned into the generic message above. Registration
 * was silently broken by the same defect.
 *
 * THE FIX
 * -------
 * The page literal was renamed to AUTH_PAGE_API and merged rather than
 * replaced:
 *
 *     window.API = Object.assign(AUTH_PAGE_API, window.API || {});
 *
 * Existing api.js members win, so nothing that already worked changes; only
 * the genuinely missing methods are filled in.
 *
 * WHAT THESE TESTS PIN
 * --------------------
 * test_login_page_does_not_short_circuit... and test_login_page_merges...
 * pin the fix itself. But the DURABLE invariant — the one that would have
 * caught TASK 98.2 the day it landed — is
 * test_every_window_api_method_the_login_page_calls_is_actually_defined():
 * it re-derives the method list from both source files on every run, so ANY
 * future change that leaves the login page calling a method nobody defines
 * fails here instead of in a user's browser.
 *
 * The HTTP tests below cover the endpoint's non-MySQL paths. The happy path
 * cannot run here because the controller issues `SHOW COLUMNS FROM
 * notifications`, which SQLite cannot parse — see
 * ForgotPasswordRequestMySqlExecutionTest for executed coverage of that.
 */
class ForgotPasswordRequestTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;

    private const LOGIN_PAGE = __DIR__ . '/../../public/frontend/pages/index.php';
    private const API_JS = __DIR__ . '/../../public/frontend/assets/js/api.js';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('forgot_password_request_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        // The endpoint throttles on 'forgot-password-request|<username>|<ip>'
        // with a 60s lockout after a SINGLE attempt, and RateLimiter state is
        // process-global, so it must be cleared or the second test method to
        // use a given username would get a 429 it did not ask for.
        $this->clearForgotPasswordThrottle('euclidebonifacio');
        $this->clearForgotPasswordThrottle('nosuchpersonatall');
        $this->clearForgotPasswordThrottle('repeatrequester');
    }

    /**
     * AuthController::login() writes the raw $_SESSION superglobal for legacy
     * page compatibility and PHP does not reset it between test methods in the
     * same process. This endpoint does not log anyone in, but clearing is
     * cheap insurance that this file can never poison a later suite.
     */
    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The exact bug.
    // ---------------------------------------------------------------

    public function test_login_page_does_not_short_circuit_the_shared_api_object(): void
    {
        $page = $this->loginPageSource();

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*window\.API\s*=\s*window\.API\s*\|\|/m',
            $page,
            'public/frontend/pages/index.php must not re-introduce the '
            . '`window.API = window.API || { ... }` short-circuit. api.js runs '
            . 'first and already sets window.API, so the `||` throws away this '
            . "page's object literal — including forgotPasswordRequest() and "
            . 'register(), which api.js does not define. That is exactly what '
            . 'broke "SUBMIT REQUEST".'
        );
    }

    public function test_login_page_merges_its_own_methods_into_the_shared_api_object(): void
    {
        $page = $this->loginPageSource();

        $this->assertStringContainsString(
            'window.API = Object.assign(AUTH_PAGE_API, window.API || {});',
            $page,
            'The login page must MERGE its auth-only methods into window.API. '
            . 'Object.assign(target, source) lets the source (api.js) win on '
            . 'every key it defines, so login() and the shared helpers keep '
            . 'their existing behaviour while register() and '
            . 'forgotPasswordRequest() are filled in.'
        );
    }

    /**
     * The invariant that generalises the bug: the page may only call methods
     * that something actually defines. Both sides are re-derived from source
     * on every run, so this keeps working as either file changes.
     */
    public function test_every_window_api_method_the_login_page_calls_is_actually_defined(): void
    {
        $called = $this->methodsCalledOnWindowApiByLoginPage();

        $this->assertContains(
            'forgotPasswordRequest',
            $called,
            'Sanity check on this test itself: the login page is supposed to '
            . 'call window.API.forgotPasswordRequest(). If this fails, the '
            . 'call-site regex below has gone stale and the rest of this test '
            . 'is no longer proving anything.'
        );

        $defined = array_unique(array_merge(
            $this->objectLiteralMethodNames($this->apiJsSource(), 'const API = {'),
            $this->objectLiteralMethodNames($this->loginPageSource(), 'const AUTH_PAGE_API = {')
        ));

        $missing = array_values(array_diff($called, $defined));

        $this->assertSame(
            [],
            $missing,
            'The login page calls window.API method(s) that neither api.js nor '
            . 'its own AUTH_PAGE_API literal defines: ' . implode(', ', $missing)
            . '. At runtime that is a TypeError swallowed by a catch block, so '
            . 'the user only ever sees a generic "please try again" message. '
            . 'Either define the method or stop calling it.'
        );
    }

    /**
     * Guards the other half of the same defect: api.js must keep publishing
     * window.API (TASK 98.2, for main.js's performLogout), and the login page
     * must keep tolerating that. Documented here so nobody "fixes" the bug by
     * deleting the api.js line instead — that would break logout everywhere.
     */
    public function test_api_js_still_publishes_the_shared_window_api_object(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\s*window\.API\s*=\s*API;/m',
            $this->apiJsSource(),
            'api.js must keep exporting `window.API = API;` — main.js '
            . 'performLogout() depends on it. The login page is the side that '
            . 'has to merge, which is what the Object.assign() fix does.'
        );
    }

    /**
     * Registration was collateral damage from the identical short-circuit and
     * is fixed by the identical merge; pinned so a partial revert is caught.
     */
    public function test_login_page_register_method_survives_the_merge(): void
    {
        $this->assertContains(
            'register',
            $this->objectLiteralMethodNames($this->loginPageSource(), 'const AUTH_PAGE_API = {'),
            'register() exists only on the login page — api.js does not define '
            . 'it — so it must remain inside AUTH_PAGE_API to reach window.API.'
        );
    }

    // ---------------------------------------------------------------
    // Endpoint behaviour that does not need MySQL.
    // ---------------------------------------------------------------

    public function test_username_is_required(): void
    {
        $response = $this->postJson('/api/auth/forgot_password_request', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('username');
    }

    public function test_username_shorter_than_the_minimum_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/forgot_password_request', [
            'username' => 'ab',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('username');
    }

    /**
     * Account-enumeration guard: an unknown username must return the SAME
     * shape of success response a known one does, so an attacker cannot use
     * this endpoint to discover which usernames exist.
     */
    public function test_unknown_username_returns_a_non_committal_success(): void
    {
        $response = $this->postJson('/api/auth/forgot_password_request', [
            'username' => 'nosuchpersonatall',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'message',
            'If this account is registered, Administrator has been notified.'
        );
    }

    /**
     * A repeat request inside the lockout window is throttled. This is a
     * security control (it rate-limits admin-notification spam), so it is
     * pinned rather than relaxed.
     */
    public function test_a_repeated_request_for_the_same_username_is_throttled(): void
    {
        $this->seedUser([
            'username' => 'repeatrequester',
            'email' => 'repeatrequester@example.com',
            'role' => 'maintenance_staff',
            'status' => 'active',
        ]);

        $first = $this->postJson('/api/auth/forgot_password_request', [
            'username' => 'repeatrequester',
        ]);

        // The first call reaches the notification block, which runs MySQL-only
        // SQL and therefore cannot succeed on SQLite. What matters here is
        // that it was NOT throttled — the throttle is what the second call
        // asserts.
        $this->assertNotSame(429, $first->getStatusCode(), 'The first request must not be throttled.');

        $second = $this->postJson('/api/auth/forgot_password_request', [
            'username' => 'repeatrequester',
        ]);

        $second->assertStatus(429);
        $second->assertJsonPath('success', false);
        $this->assertIsInt(
            $second->json('data.retry_after_seconds'),
            'The throttled response must tell the caller how long to wait.'
        );
    }

    /**
     * The throttle key is lower-cased and trimmed, so casing cannot be used to
     * sidestep the limit.
     */
    public function test_changing_the_username_casing_does_not_bypass_the_throttle(): void
    {
        $this->postJson('/api/auth/forgot_password_request', [
            'username' => 'repeatrequester',
        ]);

        $response = $this->postJson('/api/auth/forgot_password_request', [
            'username' => '  RepeatRequester  ',
        ]);

        $response->assertStatus(429);
    }

    /**
     * The endpoint is deliberately public — a locked-out user cannot log in to
     * ask for help — so it must not sit behind the API auth middleware.
     */
    public function test_the_endpoint_is_reachable_without_authentication(): void
    {
        $response = $this->postJson('/api/auth/forgot_password_request', [
            'username' => 'nosuchpersonatall',
        ]);

        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
        $response->assertOk();
    }

    /**
     * 2026-09-27 — the self-service "reset code" endpoint was never usable
     * (nothing issued a code) and was removed. Resetting a password is done
     * by the Administrator after this request.
     */
    public function test_the_unused_reset_code_endpoint_is_gone(): void
    {
        $status = $this->postJson('/api/auth/forgot_password_reset', [
            'email' => 'someone@example.com',
            'reset_code' => '123456',
            'password' => 'NewPassword123',
        ])->status();

        $this->assertContains($status, [404, 405]);
    }

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

    private function loginPageSource(): string
    {
        return $this->readSource(self::LOGIN_PAGE);
    }

    private function apiJsSource(): string
    {
        return $this->readSource(self::API_JS);
    }

    private function readSource(string $path): string
    {
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertNotSame('', $contents, "Refusing to assert against an empty {$path}.");

        return $contents;
    }

    /**
     * Every `window.API.something(` call site in the login page. The trailing
     * `(` is required so prose mentions of a method name inside comments are
     * not counted as call sites.
     *
     * @return list<string>
     */
    private function methodsCalledOnWindowApiByLoginPage(): array
    {
        preg_match_all(
            '/window\.API\.([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
            $this->loginPageSource(),
            $matches
        );

        return array_values(array_unique($matches[1]));
    }

    /**
     * Shorthand method names declared at the top level of a `const X = {`
     * object literal. Both files indent those members with exactly four
     * spaces, which is what keeps nested helpers out of the result.
     *
     * @return list<string>
     */
    private function objectLiteralMethodNames(string $source, string $openingDeclaration): array
    {
        $start = strpos($source, $openingDeclaration);
        $this->assertNotFalse(
            $start,
            "Could not locate `{$openingDeclaration}` — this test's source "
            . 'parsing has gone stale and must be updated alongside the file.'
        );

        $body = substr($source, $start);
        $end = strpos($body, "\n};");
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }

        preg_match_all(
            '/^    (?:async\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/m',
            $body,
            $matches
        );

        return array_values(array_unique($matches[1]));
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

    private function clearForgotPasswordThrottle(string $username): void
    {
        foreach (['127.0.0.1', ''] as $ip) {
            RateLimiter::clear('forgot-password-request|' . $username . '|' . $ip);
        }
    }
}
