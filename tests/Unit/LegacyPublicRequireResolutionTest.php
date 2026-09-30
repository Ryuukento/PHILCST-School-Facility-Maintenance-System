<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TASK 60 — Legacy Backend Retirement Readiness & Final Reachability Audit.
 *
 * TASK 58 retired a public shim whose single statement was a `require_once` of
 * a file that had been deleted, so every request to its URL produced a PHP
 * fatal error that disclosed the absolute filesystem path and the include_path.
 * TASK 59 then verified that no `require` inside public/backend/ pointed at a
 * missing target.
 *
 * That verification stopped at public/backend/. This suite extends the same
 * check to the whole legacy public surface — public/frontend/ included — and in
 * doing so caught a second live instance of the identical defect class:
 *
 *     public/frontend/index.php:10
 *     require_once __DIR__ . '/../../backend/config/settings.php';
 *
 * public/frontend/index.php sits at public/frontend/, so `../../` resolves to
 * the PROJECT ROOT and the statement asked for <root>/backend/config/... . No
 * root-level backend/ directory exists — that is precisely why the root
 * .htaccess carries `RewriteRule ^backend/(.*)$ public/backend/$1`. The correct
 * target is one level up, at public/backend/.
 *
 * The mistake is a depth error, not a systemic one: every OTHER legacy file
 * spelling `__DIR__ . '/../../backend/...'` lives one directory deeper
 * (public/frontend/pages/, public/frontend/includes/), where `../../` lands on
 * public/ and is therefore correct. Only this file was moved or copied up a
 * level without its relative path being adjusted, in commit 6bbd38a — the same
 * restructure that deleted public/backend/api/.
 *
 * Why it mattered: public/frontend/index.php is the DirectoryIndex target for
 * the URL /frontend/, so it is publicly reachable even though no code links to
 * it by name. It is not dead — it is the frontend landing page, whose job is to
 * bounce a visitor to the dashboard matching their role, or to the login page.
 * Every request to /frontend/ hit the fatal instead, emitting:
 *
 *     Fatal error: Uncaught Error: Failed opening required
 *     'C:\xampp\...\public\frontend/../../backend/config/settings.php'
 *     (include_path='C:\xampp\php\PEAR')
 *
 * The TASK 55 `display_errors=0` hardening cannot suppress that, because the
 * hardening lives in settings.php — the very file that fails to load. This is
 * the same reason the TASK 58 shim leaked its path.
 *
 * These assertions are static: they resolve literal `__DIR__`-relative require
 * targets and check them against the filesystem. That is deliberate — the
 * legacy files boot a real MySQL connection when included, so they cannot be
 * exercised in-process, and a missing include target is fully decidable
 * statically anyway.
 *
 * See TASK_60_LEGACY_BACKEND_RETIREMENT_READINESS_REPORT.md.
 */
class LegacyPublicRequireResolutionTest extends TestCase
{
    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every .php file under the legacy public surface.
     *
     * @return string[]
     */
    private function legacyPhpFiles(): array
    {
        $files = [];

        foreach (['public/backend', 'public/frontend'] as $dir) {
            $base = $this->projectRoot() . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Extracts require/include statements of the literal form
     * `require_once __DIR__ . '/relative/path.php'` from one file.
     *
     * Only this literal form is analysed. Statements built from variables are
     * skipped rather than guessed at — an unresolvable-by-inspection include is
     * a separate concern, and reporting it here would produce false alarms.
     *
     * @return array<int, array{line: int, raw: string, resolved: string}>
     */
    private function literalRequires(string $path): array
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Unable to read {$path}.");

        $found = [];
        $pattern = '/(require_once|require|include_once|include)\s*\(?\s*__DIR__\s*\.\s*'
            . '([\'"])([^\'"]+)\2/i';

        foreach (preg_split('/\R/', $raw) as $index => $line) {
            $trimmed = ltrim($line);
            // Skip commented-out statements: a require inside a comment is
            // documentation, not behaviour.
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match($pattern, $line, $m) === 1) {
                $found[] = [
                    'line'     => $index + 1,
                    'raw'      => $m[3],
                    'resolved' => dirname($path) . $m[3],
                ];
            }
        }

        return $found;
    }

    public function test_every_literal_require_in_the_legacy_public_surface_resolves(): void
    {
        $broken = [];
        $checked = 0;

        foreach ($this->legacyPhpFiles() as $file) {
            foreach ($this->literalRequires($file) as $require) {
                $checked++;

                if (file_exists($require['resolved'])) {
                    continue;
                }

                $relativeFile = str_replace(
                    str_replace('\\', '/', $this->projectRoot()) . '/',
                    '',
                    str_replace('\\', '/', $file)
                );

                $broken[] = sprintf(
                    '%s:%d  require %s  →  %s (MISSING)',
                    $relativeFile,
                    $require['line'],
                    $require['raw'],
                    $require['resolved']
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'No literal __DIR__-relative requires were found at all, which means this scan is '
            . 'not actually inspecting the legacy surface. Fix the scan rather than accepting '
            . 'a vacuous pass.'
        );

        $this->assertSame(
            [],
            $broken,
            "A legacy public PHP file requires a target that does not exist. Every request that "
            . "reaches such a file dies in a PHP fatal error which prints the absolute filesystem "
            . "path and the include_path — the exact disclosure TASK 58 removed by retiring the "
            . "inventory-workflow-api shim. Note that display_errors=0 (TASK 55) does NOT contain "
            . "this when the missing target IS config/settings.php, because the hardening lives "
            . "in the file that failed to load.\n\nUnresolved:\n" . implode("\n", $broken)
        );
    }

    public function test_the_frontend_landing_page_boots(): void
    {
        // /frontend/ resolves to this file via DirectoryIndex, so it is publicly
        // reachable even though nothing links to it by name. Its whole job is
        // the role-based bounce below, and all of that depends on settings.php
        // loading — public_url() is defined there (settings.php:173).
        $path = $this->projectRoot() . '/public/frontend/index.php';
        $requires = $this->literalRequires($path);

        $this->assertNotEmpty(
            $requires,
            'public/frontend/index.php no longer requires anything; if it was rewritten, '
            . 'confirm the role-based redirect still works before relaxing this test.'
        );

        foreach ($requires as $require) {
            $this->assertFileExists(
                $require['resolved'],
                "public/frontend/index.php is the DirectoryIndex target for /frontend/. Its "
                . "require of '{$require['raw']}' does not resolve, so the landing page fatals "
                . "instead of redirecting. Remember this file sits at public/frontend/, one "
                . "level shallower than pages/ and includes/ — so it needs '/../backend/...', "
                . "not '/../../backend/...'."
            );
        }
    }
}
