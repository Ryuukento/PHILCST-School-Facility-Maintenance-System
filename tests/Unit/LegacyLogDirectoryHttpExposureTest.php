<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TASK 60 — Legacy Backend Retirement Readiness & Final Reachability Audit.
 *
 * The legacy logger writes plain-text log files into a directory that sits
 * INSIDE the web root:
 *
 *     public/backend/utils/Logger.php:8
 *     private static $logDir = __DIR__ . '/../../logs';   // → public/logs/
 *
 * Nothing denied those files over HTTP. A request for
 * /public/logs/2026-04-07.log matches none of the rewrites in the root
 * .htaccess, and the Laravel front-controller fallback does not catch it
 * either, because that fallback is guarded by `RewriteCond
 * %{REQUEST_FILENAME} !-f` — the log file exists, so the condition fails and
 * Apache serves the file directly as a static asset. This is the same
 * mechanism that makes /public/backend/... reachable, established in TASK 59.
 *
 * `Options -Indexes` does not mitigate this. It only suppresses the directory
 * listing; the filenames are literal dates (2026-04-07.log), so enumeration is
 * trivial without a listing.
 *
 * Why this matters more than it looks: TASK 55 (FacilityService) and TASK 59
 * (the legacy notifications endpoint) both fixed raw-exception disclosure by
 * routing the full PDOException text into `Logger::error()` and returning only
 * a generic message to the client. Those fixes RELOCATED the sensitive detail
 * rather than eliminating it — and they relocated it here. The audit found the
 * live log files carrying exactly the payload those tasks removed from HTTP
 * responses: SQLSTATE codes, missing table/column names, and the database name
 * `school_facility_maintenance`. Worse, the TASK 59 disclosure required an
 * authenticated session (the endpoint 401s first), whereas reading a log file
 * requires no session at all. Leaving this open would make the post-fix state
 * strictly more exposed than the defect TASK 59 closed.
 *
 * The same exposure applies to Laravel's own `storage/logs/laravel.log`. That
 * path is currently shielded only by accident: the stale
 * `RewriteRule ^storage/(.*)$ public/storage/$1` sends it to a nonexistent
 * public/storage/, so it 404s. That shield is incidental, not deliberate, and
 * would vanish the moment anyone tidied up the stale rewrite — see
 * test_laravel_own_log_file_is_denied_over_http below.
 *
 * These assertions are source-level: Apache and MySQL were not running during
 * this audit, so no live HTTP request could be issued. Rather than assert on
 * prose, this test parses the deny rules out of the real .htaccess and
 * evaluates candidate request paths against them, so it tests rewrite
 * behaviour rather than the presence of a comment. This follows the
 * comment-stripping approach established in
 * LegacyNotificationsEndpointErrorDisclosureTest.
 *
 * See TASK_60_LEGACY_BACKEND_RETIREMENT_READINESS_REPORT.md.
 */
class LegacyLogDirectoryHttpExposureTest extends TestCase
{
    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The root .htaccess with comment and blank lines removed.
     *
     * Stripping matters: the fix below is documented with a comment that
     * necessarily quotes the rule it adds. Matching against raw text would let
     * that prose satisfy these assertions.
     *
     * @return string[]
     */
    private function htaccessLines(): array
    {
        $path = $this->projectRoot() . '/.htaccess';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, 'Unable to read the project root .htaccess.');

        $lines = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $lines[] = $trimmed;
        }

        return $lines;
    }

    /**
     * Extracts every rule in the root .htaccess that DENIES a request outright,
     * i.e. a `RewriteRule <pattern> - [...F...]`, together with any
     * `RewriteCond %{REQUEST_URI} ...` lines that gate it.
     *
     * @return array<int, array{conds: string[], pattern: string, nocase: bool}>
     */
    private function denyRules(): array
    {
        $rules = [];
        $pendingConds = [];

        foreach ($this->htaccessLines() as $line) {
            if (preg_match('/^RewriteCond\s+%\{REQUEST_URI\}\s+(\S+)/i', $line, $m) === 1) {
                $pendingConds[] = $m[1];
                continue;
            }

            if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[(.*)\])?/i', $line, $m) === 1) {
                $pattern      = $m[1];
                $substitution = $m[2];
                $flags        = $m[3] ?? '';

                // A RewriteRule always consumes the conditions stacked above it,
                // whether or not it turns out to be a deny rule.
                $conds = $pendingConds;
                $pendingConds = [];

                $isDeny = $substitution === '-'
                    && preg_match('/(^|,)\s*F(orbidden)?\s*(,|$)/i', $flags) === 1;

                if ($isDeny) {
                    $rules[] = [
                        'conds'   => $conds,
                        'pattern' => $pattern,
                        'nocase'  => preg_match('/(^|,)\s*NC\s*(,|$)/i', $flags) === 1,
                    ];
                }
                continue;
            }

            // Any other directive (RewriteEngine, Options, ...) does not carry
            // conditions forward.
            if (preg_match('/^Rewrite/i', $line) === 1) {
                $pendingConds = [];
            }
        }

        return $rules;
    }

    /**
     * Would a request for $relativePath be refused by the root .htaccess?
     *
     * $relativePath is expressed relative to the project root, which is exactly
     * what a RewriteRule pattern in a root .htaccess is matched against.
     * %{REQUEST_URI} additionally carries a leading slash and, on a
     * subdirectory install, the install prefix — every REQUEST_URI condition
     * examined here is anchored with `(^|/)`, so the prefix does not change the
     * outcome.
     */
    private function isDenied(string $relativePath): bool
    {
        $requestUri = '/' . ltrim($relativePath, '/');

        foreach ($this->denyRules() as $rule) {
            $flags = $rule['nocase'] ? 'i' : '';

            if (preg_match('#' . $rule['pattern'] . '#' . $flags, $relativePath) !== 1) {
                continue;
            }

            $allCondsMatch = true;
            foreach ($rule['conds'] as $cond) {
                if (preg_match('#' . $cond . '#i', $requestUri) !== 1) {
                    $allCondsMatch = false;
                    break;
                }
            }

            if ($allCondsMatch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the log directory straight out of Logger.php, so this test keeps
     * describing the real logger rather than a hardcoded guess. Returns the
     * path relative to the project root, or null if the logger writes outside
     * the project entirely.
     */
    private function loggerDirectoryRelativeToRoot(): ?string
    {
        $loggerPath = $this->projectRoot() . '/public/backend/utils/Logger.php';
        $raw = file_get_contents($loggerPath);
        $this->assertNotFalse($raw, 'Unable to read the legacy Logger.');

        $matched = preg_match(
            '/\$logDir\s*=\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/',
            $raw,
            $m
        );
        $this->assertSame(
            1,
            $matched,
            'Could not determine the legacy Logger output directory from Logger.php. '
            . 'If the assignment changed shape, update this test so it keeps tracking the '
            . 'real directory instead of silently passing.'
        );

        $absolute = $this->projectRoot() . '/public/backend/utils' . $m[1];
        $normalised = [];
        foreach (explode('/', str_replace('\\', '/', $absolute)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalised);
                continue;
            }
            $normalised[] = $segment;
        }

        $rootSegments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $this->projectRoot())),
            static fn ($s) => $s !== ''
        ));

        if (array_slice($normalised, 0, count($rootSegments)) !== $rootSegments) {
            return null;
        }

        return implode('/', array_slice($normalised, count($rootSegments)));
    }

    /**
     * Guards the guard. Every assertion below is of the form "isDenied(...) is
     * true"; a parser that silently matched nothing would report exactly the
     * same failures for entirely the wrong reason, and would then flip to green
     * on a fix it never actually verified. This pins the matcher against the
     * deny rules TASK 100.2.1 already added, whose behaviour is settled — if
     * these stop being recognised, the parser is broken, not the .htaccess.
     */
    public function test_the_deny_rule_matcher_recognises_the_existing_protections(): void
    {
        $this->assertTrue(
            $this->isDenied('.git/config'),
            'The TASK 100.2.1 rule `RewriteCond %{REQUEST_URI} (^|/)\.git(/|$)` must be parsed '
            . 'as a deny rule; if it is not, every other assertion in this file is vacuous.'
        );
        $this->assertTrue(
            $this->isDenied('database/database.sqlite'),
            'The TASK 100.2.1 rule `RewriteRule ^database/ - [F,L]` must be parsed as a deny rule.'
        );
        $this->assertTrue(
            $this->isDenied('db_backups/dump.sql'),
            'The TASK 100.2.1 rule `RewriteRule ^db_backups/ - [F,L]` must be parsed as a deny rule.'
        );
    }

    public function test_the_legacy_logger_writes_inside_the_document_root(): void
    {
        $relative = $this->loggerDirectoryRelativeToRoot();

        if ($relative === null || !str_starts_with($relative, 'public/')) {
            $this->markTestSkipped(
                'The legacy Logger no longer writes inside public/, so the HTTP deny rule '
                . 'this test guards is no longer the mechanism protecting the logs. Relocating '
                . 'the log directory outside the web root is the stronger remediation; if that '
                . 'has been done, this suite should be revisited rather than left passing by '
                . 'accident.'
            );
        }

        $this->assertSame(
            'public/logs',
            $relative,
            'Logger.php resolves to a directory inside the public web root, which is why the '
            . 'deny rule asserted below is required.'
        );
    }

    public function test_legacy_log_files_are_denied_over_http(): void
    {
        $relative = $this->loggerDirectoryRelativeToRoot();

        if ($relative === null || !str_starts_with($relative, 'public/')) {
            $this->markTestSkipped('Logger no longer writes inside the web root.');
        }

        $this->assertTrue(
            $this->isDenied($relative . '/2026-04-07.log'),
            'Legacy log files are served directly by Apache: they live inside the web root, and '
            . 'the Laravel front-controller fallback is gated on `!-f`, so an existing log file '
            . 'never reaches it. TASK 55 and TASK 59 both fixed raw-exception disclosure by '
            . 'routing the full PDOException text into Logger::error() — that text (SQLSTATE '
            . 'codes, table and column names, the database name) is now sitting in these files, '
            . 'downloadable with no session at all. The root .htaccess must refuse them.'
        );
    }

    public function test_the_bare_logs_url_is_denied_as_well(): void
    {
        $this->assertTrue(
            $this->isDenied('logs/2026-04-07.log'),
            'The deny rule must not be pinned to the single path public/logs/. Log directories '
            . 'are created on demand by Logger::init(), and the root .htaccess already rewrites '
            . 'sibling prefixes (frontend/, backend/, storage/) into public/, so a bare /logs/ '
            . 'URL must be refused too rather than depending on which rewrites happen to exist.'
        );
    }

    public function test_laravel_own_log_file_is_denied_over_http(): void
    {
        $this->assertTrue(
            $this->isDenied('storage/logs/laravel.log'),
            'storage/logs/laravel.log is currently shielded only by accident: the stale rewrite '
            . '`^storage/(.*)$ public/storage/$1` points at a public/storage/ directory that '
            . 'does not exist, so the request 404s. That is incidental, not deliberate, and it '
            . 'disappears the moment the stale rewrite is cleaned up. The log file must be '
            . 'denied on its own terms.'
        );
    }

    public function test_the_deny_rule_does_not_block_legitimate_application_urls(): void
    {
        // A deny rule broad enough to catch every log directory must not also
        // swallow real routes. `activity-logs` is a live prefix in
        // routes/web.php (activity-logs.index / activity-logs.show), and the
        // legacy pages below are the surfaces TASK 59 confirmed are reachable.
        $mustRemainReachable = [
            'index.php',
            'public/index.php',
            'frontend/pages/activity-log.php',
            'frontend/pages/activity-log-detail.php',
            'public/frontend/pages/dashboard.php',
            'public/backend/models/notifications.php',
            'api/activity-logs/5',
            'activity-logs',
        ];

        foreach ($mustRemainReachable as $path) {
            $this->assertFalse(
                $this->isDenied($path),
                "The log deny rule is over-broad: it also refuses '{$path}', which is a live "
                . 'application URL. Narrow the pattern so it only matches a whole path segment '
                . 'named "logs".'
            );
        }
    }
}
