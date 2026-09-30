<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TASK 59 — Legacy Public Backend Shim Audit.
 *
 * `public/backend/models/notifications.php` is a procedural JSON endpoint that
 * is publicly reachable (via the `.htaccess` rule `^backend/(.*)$ →
 * public/backend/$1`). Its single `catch (Exception $e)` block returned
 * `$e->getMessage()` verbatim to the HTTP client.
 *
 * That catch served two very different populations:
 *
 *   1. Deliberate validation exceptions authored in this file — 'Notification
 *      ID is required', 'Invalid action'. These are developer-written literals
 *      and are legitimately user-facing; they must keep reaching the client.
 *   2. PDOException. The legacy PDO handle is built with
 *      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
 *      (public/backend/config/database.php:67), and PDOException extends
 *      Exception — so a database failure was caught here and its message,
 *      which can carry the SQLSTATE code, the literal SQL, and column or
 *      constraint names, was echoed straight into the response body.
 *
 * This is the same defect TASK 55 fixed in FacilityService::createBuilding()
 * (public/backend/services/FacilityService.php:66-78), where the full message
 * is now captured server-side via Logger::error() and only a generic string is
 * returned. Task 55 audited the legacy *services*; it did not reach this
 * procedural endpoint, so the endpoint was left as the last legacy surface
 * still disclosing raw exception text.
 *
 * Note that the TASK 55 `display_errors` hardening in config/settings.php does
 * NOT mitigate this: the disclosure is an explicit `echo json_encode(...)` in
 * application code, not a PHP-emitted error, so `display_errors=0` never
 * applies to it.
 *
 * These assertions are source-level because the endpoint is procedural and
 * boots the legacy stack (bootstrap.php opens a real MySQL connection) on
 * include, so it cannot be exercised in-process. This mirrors the established
 * source-assertion pattern in NavigationConsistencyTest and
 * InventoryWorkflowApiRetirementTest.
 *
 * See TASK_59_LEGACY_PUBLIC_BACKEND_SHIM_AUDIT_REPORT.md.
 */
class LegacyNotificationsEndpointErrorDisclosureTest extends TestCase
{
    /**
     * The endpoint's source with all comments stripped, via PHP's own tokenizer.
     *
     * Stripping matters: the fix is documented with a comment that necessarily
     * quotes `catch (Exception)` and `$e->getMessage()` in prose. Matching
     * against raw text would let that prose satisfy — or, worse, misalign —
     * these assertions. Analysing tokenised code means the tests describe what
     * the endpoint actually does, not what its comments claim.
     */
    private function endpointSource(): string
    {
        $path = __DIR__ . '/../../public/backend/models/notifications.php';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, 'Unable to read the legacy notifications endpoint.');

        $source = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $source .= $token[1];
                continue;
            }
            $source .= $token;
        }

        return $source;
    }

    /**
     * Returns the body of the `catch (PDOException ...)` block: everything from
     * that catch up to the next `catch (` or the end of file.
     */
    private function pdoCatchBlock(string $source): string
    {
        $start = strpos($source, 'catch (PDOException');
        $this->assertNotFalse(
            $start,
            'The legacy notifications endpoint must catch PDOException separately from the '
            . 'deliberate validation exceptions it throws, so database failures can be logged '
            . 'server-side and answered with a generic message instead of raw SQL detail.'
        );

        $next = strpos($source, 'catch (', $start + 1);

        return $next === false
            ? substr($source, $start)
            : substr($source, $start, $next - $start);
    }

    public function test_database_errors_are_not_echoed_to_the_client(): void
    {
        $block = $this->pdoCatchBlock($this->endpointSource());

        $this->assertStringNotContainsString(
            '$e->getMessage()',
            preg_replace('/Logger::error\s*\(.*?\);/s', '', $block),
            'A PDOException message can contain the SQLSTATE code, the literal SQL, and '
            . 'column/constraint names. It must never be echoed to the HTTP client — log it via '
            . 'Logger::error() and return a generic message, exactly as TASK 55 did in '
            . 'FacilityService::createBuilding().'
        );
    }

    public function test_database_errors_are_still_logged_server_side(): void
    {
        $block = $this->pdoCatchBlock($this->endpointSource());

        $this->assertStringContainsString(
            'Logger::error',
            $block,
            'Suppressing the client-facing message must not lose the diagnostic. The full '
            . 'exception has to remain available server-side via Logger::error(), matching the '
            . 'TASK 55 FacilityService pattern.'
        );
    }

    public function test_pdo_exception_is_caught_before_the_generic_exception_handler(): void
    {
        $source = $this->endpointSource();

        $pdoCatch     = strpos($source, 'catch (PDOException');
        $genericCatch = strpos($source, 'catch (Exception');

        $this->assertNotFalse($pdoCatch);
        $this->assertNotFalse($genericCatch);

        $this->assertLessThan(
            $genericCatch,
            $pdoCatch,
            'PDOException extends Exception, so a `catch (Exception)` placed first would swallow '
            . 'every database error and the PDOException handler would be unreachable dead code, '
            . 'silently reinstating the disclosure.'
        );
    }

    public function test_deliberate_validation_messages_still_reach_the_client(): void
    {
        $source = $this->endpointSource();

        // The endpoint throws these literals on purpose; they are developer-authored,
        // carry no internal detail, and are the endpoint's only user-facing feedback.
        $this->assertStringContainsString("throw new Exception('Notification ID is required')", $source);
        $this->assertStringContainsString("throw new Exception('Invalid action')", $source);

        $genericCatch = strpos($source, 'catch (Exception');
        $this->assertNotFalse($genericCatch);

        $this->assertStringContainsString(
            '$e->getMessage()',
            substr($source, $genericCatch),
            'The generic Exception handler must keep returning $e->getMessage() so the '
            . 'deliberate validation messages above are still delivered. Narrowing the database '
            . 'disclosure must not silently degrade validation feedback.'
        );
    }
}
