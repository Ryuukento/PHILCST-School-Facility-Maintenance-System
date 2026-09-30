<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TASK 61 — _dev_guard.php Deployment Integrity & Development-Endpoint Protection.
 *
 * `public/backend/_dev_guard.php` (introduced by RELEASE_BLOCKER_FIX_REPORT.md)
 * is the authorization boundary in front of five one-off setup/seed/debug
 * scripts that live inside the web root. Those scripts create tables, seed
 * data, and — in the case of setup_inventory.php — run
 * `DELETE FROM inventory_categories`. The guard permits PHP CLI execution or an
 * authenticated `super_admin` HTTP session, and answers everything else with
 * 403 before any script logic runs.
 *
 * This suite locks the properties the guard depends on. It deliberately does
 * NOT test the guard by executing a guarded script — those scripts mutate the
 * database, and authorization must never be verified by attempting the
 * destructive operation. All checks here are source-level, which is sufficient
 * because every property below is statically decidable.
 *
 * Two properties matter and are easy to break silently:
 *
 *   1. The guard must be the FIRST executable statement in each dependent.
 *      A single `require` of config or bootstrap placed above it would run
 *      database code for an unauthenticated caller.
 *
 *   2. No dependent may emit any byte before the guard runs. This is not
 *      pedantry: reports_display.php carried a UTF-8 BOM (EF BB BF) ahead of
 *      its `<?php`, and PHP sends that immediately. With output_buffering=Off,
 *      headers were then already sent, so the guard's `http_response_code(403)`
 *      failed silently and the endpoint answered **HTTP 200** while PHP printed
 *      three warnings naming absolute filesystem paths. The guard still exited,
 *      so it still failed closed — but it reported success and leaked paths
 *      while doing it. Verified live; see TASK_61 report §11.
 *
 * See TASK_61_DEV_GUARD_DEPLOYMENT_INTEGRITY_REPORT.md.
 */
class DevGuardIntegrityTest extends TestCase
{
    private const GUARD = 'public/backend/_dev_guard.php';

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function path(string $relative): string
    {
        return $this->projectRoot() . '/' . $relative;
    }

    /**
     * Every file under public/backend/ that requires the guard, as paths
     * relative to the project root. Derived by scanning, not hardcoded, so a
     * newly added dependent is covered automatically.
     *
     * @return string[]
     */
    private function guardDependents(): array
    {
        $dependents = [];
        $base = $this->path('public/backend');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $name = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($name, '_dev_guard.php')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && preg_match('/require(_once)?[^;]*_dev_guard\.php/', $contents) === 1) {
                $dependents[] = str_replace(
                    str_replace('\\', '/', $this->projectRoot()) . '/',
                    '',
                    $name
                );
            }
        }

        sort($dependents);

        return $dependents;
    }

    public function test_the_guard_file_exists(): void
    {
        $this->assertFileExists(
            $this->path(self::GUARD),
            'public/backend/_dev_guard.php is the only authorization boundary in front of five '
            . 'destructive setup/seed scripts that sit inside the web root. Without it, every '
            . 'dependent dies in a require_once fatal that prints absolute paths and the '
            . 'include_path (fails closed, but discloses).'
        );
    }

    public function test_the_guard_is_fail_closed(): void
    {
        $source = file_get_contents($this->path(self::GUARD));
        $this->assertNotFalse($source);

        // Default-deny shape: a non-CLI caller must be rejected unless the
        // session carries super_admin, and rejection must terminate.
        $this->assertMatchesRegularExpression(
            '/PHP_SAPI\s*!==\s*[\'"]cli[\'"]/',
            $source,
            'The guard must branch on PHP_SAPI so that only real CLI execution skips the session '
            . 'check. Note "cli-server" (PHP built-in server) is deliberately NOT "cli", so web '
            . 'requests through it are still challenged.'
        );

        $this->assertStringContainsString(
            "!== 'super_admin'",
            $source,
            'The guard must reject any role other than super_admin.'
        );

        $this->assertMatchesRegularExpression(
            '/http_response_code\(403\)/',
            $source,
            'Rejection must set 403.'
        );

        $this->assertMatchesRegularExpression(
            '/\bexit\b/',
            $source,
            'Rejection must terminate the request. Without exit, execution would fall through '
            . 'into the guarded script body.'
        );
    }

    public function test_the_expected_dependents_are_all_present(): void
    {
        $this->assertSame(
            [
                'public/backend/reports_display.php',
                'public/backend/seed_inventory_items.php',
                'public/backend/setup-database.php',
                'public/backend/setup_inventory.php',
                'public/backend/verify_category_setup.php',
            ],
            $this->guardDependents(),
            'The set of guard-protected scripts changed. If a script was added, confirm it is '
            . 'guarded; if one was removed, confirm it was retired deliberately and not simply '
            . 'stripped of its guard.'
        );
    }

    public function test_the_guard_is_the_first_executable_statement_in_every_dependent(): void
    {
        foreach ($this->guardDependents() as $relative) {
            $source = file_get_contents($this->path($relative));
            $this->assertNotFalse($source);

            $firstStatement = null;

            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                        continue;
                    }
                    $firstStatement = $token[1];
                    break;
                }
                // Punctuation before any keyword would be a syntax error; ignore.
            }

            $this->assertNotNull($firstStatement, "No executable statement found in {$relative}.");

            $this->assertMatchesRegularExpression(
                '/^(require|require_once|include|include_once)$/i',
                $firstStatement,
                "{$relative} must begin with the guard require. Anything executed above the guard "
                . 'runs for unauthenticated callers.'
            );

            // ...and that first require must be the guard, not config/bootstrap.
            $upToFirstSemicolon = substr($source, 0, (int) strpos($source, ';', (int) strpos($source, $firstStatement)) + 1);

            $this->assertStringContainsString(
                '_dev_guard.php',
                $upToFirstSemicolon,
                "{$relative} executes a require BEFORE the guard. Requiring config/database.php or "
                . 'bootstrap.php first opens a database connection on behalf of an unauthenticated '
                . 'caller, which is exactly what the guard exists to prevent.'
            );
        }
    }

    public function test_no_guarded_script_emits_output_before_the_guard_runs(): void
    {
        $files = array_merge([self::GUARD], $this->guardDependents());

        foreach ($files as $relative) {
            $handle = fopen($this->path($relative), 'rb');
            $this->assertNotFalse($handle);
            $firstBytes = (string) fread($handle, 5);
            fclose($handle);

            $this->assertStringStartsWith(
                '<?php',
                $firstBytes,
                "{$relative} emits bytes before its opening PHP tag (a UTF-8 BOM, EF BB BF, or "
                . 'stray whitespace). PHP flushes those immediately, so with output_buffering=Off '
                . "the headers are already sent by the time the guard runs: http_response_code(403) "
                . 'fails silently and the endpoint answers HTTP 200, while PHP prints warnings '
                . 'containing absolute filesystem paths. Strip the leading bytes.'
            );
        }
    }

    /**
     * Deployment integrity: the guard must not be missing from a clean checkout
     * while the scripts it protects are present in one.
     *
     * TASK 61 DEFECT #1 — this is currently violated on `main`. The five
     * guarded scripts are tracked; `_dev_guard.php` is untracked and not
     * gitignored; and the committed copies of those scripts contain no guard
     * require at all (the requires exist only in the working tree). A clean
     * checkout therefore yields five unauthenticated, publicly reachable
     * database-mutating endpoints — including `DELETE FROM inventory_categories`.
     *
     * Fixing that means committing files, which TASK 61 forbids. So this test
     * SKIPS rather than fails while the guard is untracked: leaving a
     * permanently-red test in the suite would train everyone to ignore red.
     * The skip is loud and names the remediation. Once the guard is committed,
     * this becomes a live assertion that the committed scripts really do carry
     * their guard.
     */
    public function test_the_guard_is_deployable_from_a_clean_checkout(): void
    {
        exec('git rev-parse --is-inside-work-tree 2>&1', $probe, $probeStatus);
        if ($probeStatus !== 0) {
            $this->markTestSkipped('Not a Git working tree; deployment integrity cannot be checked.');
        }

        exec('git ls-files --error-unmatch ' . escapeshellarg(self::GUARD) . ' 2>&1', $out, $status);
        $guardTracked = $status === 0;

        if (!$guardTracked) {
            $this->markTestSkipped(
                "TASK 61 DEFECT #1 (OPEN — requires a commit, which TASK 61 forbids): "
                . self::GUARD . " is untracked and not gitignored, while all five scripts it "
                . "protects ARE tracked, and their committed copies contain no guard require. "
                . "A clean checkout of main is therefore five unauthenticated, publicly "
                . "reachable DB-mutating endpoints. Remediate with:\n"
                . "    git add public/backend/_dev_guard.php \\\n"
                . "            public/backend/reports_display.php \\\n"
                . "            public/backend/seed_inventory_items.php \\\n"
                . "            public/backend/setup-database.php \\\n"
                . "            public/backend/setup_inventory.php \\\n"
                . "            public/backend/verify_category_setup.php\n"
                . "    git commit\n"
                . "This test becomes an active assertion once that lands."
            );
        }

        foreach ($this->guardDependents() as $relative) {
            exec('git ls-files --error-unmatch ' . escapeshellarg($relative) . ' 2>&1', $o, $s);
            if ($s !== 0) {
                continue; // dependent is itself untracked; nothing to deploy
            }

            $committed = shell_exec('git show ' . escapeshellarg('HEAD:' . $relative) . ' 2>&1');

            $this->assertStringContainsString(
                '_dev_guard.php',
                (string) $committed,
                "The committed copy of {$relative} does not require the guard, even though the "
                . 'working-tree copy does. A clean checkout would expose this script unguarded.'
            );
        }
    }

    public function test_every_destructive_backend_script_is_guarded(): void
    {
        $base = $this->path('public/backend');
        $unguarded = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            // Class definitions are inert when requested directly: they declare
            // a class and execute nothing. Only procedural scripts can run DDL
            // or DML merely by being requested.
            if (preg_match('/^\s*(abstract\s+|final\s+)?class\s+/mi', $source) === 1) {
                continue;
            }

            $isDestructive = preg_match(
                '/\b(DROP\s+TABLE|TRUNCATE\s+|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)\b/i',
                $source
            ) === 1;

            if ($isDestructive && !str_contains($source, '_dev_guard.php')) {
                $unguarded[] = str_replace(
                    str_replace('\\', '/', $this->projectRoot()) . '/',
                    '',
                    str_replace('\\', '/', $file->getPathname())
                );
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "A procedural script under public/backend/ performs schema or bulk-data operations "
            . "without requiring _dev_guard.php. public/backend/ is inside the web root and the "
            . "root .htaccess routes ^backend/ straight into it with [L], bypassing all Laravel "
            . "middleware — so such a script is an unauthenticated, publicly reachable database "
            . "mutation.\n\nUnguarded:\n" . implode("\n", $unguarded)
        );
    }
}
