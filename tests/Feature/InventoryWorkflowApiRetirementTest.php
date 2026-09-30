<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * TASK 58 — Inventory Workflow API shim retirement guard.
 *
 * `public/inventory-workflow-api.php` was a one-line shim whose only
 * executable statement was
 *
 *     require_once __DIR__ . '/backend/api/inventory-workflow-api.php';
 *
 * Its target was deleted in commit 6bbd38a along with the whole of
 * `public/backend/api/`, but `.htaccess` kept rewriting the public URL
 * `/inventory-workflow-api.php` onto the shim. The endpoint therefore
 * answered unauthenticated requests with a PHP fatal error that disclosed the
 * absolute filesystem path and include_path (display_errors=1) instead of a
 * 404. No repository code has ever called it.
 *
 * Both the shim and the rewrite were removed under Task 58. These tests lock
 * that in, and — just as importantly — lock in that the DELETED TARGET IS NOT
 * RECREATED. That file auto-minted a `maintenance_admin` identity for any
 * anonymous caller and persisted it into $_SESSION, which every legacy page
 * reads; it also returned raw exception messages to the client. Its `deploy`
 * action and syncRoomAssetFromInventory() helper ARE the Deploy-to-Room
 * feature retired under TASK 36 PHASE 7.
 *
 * See TASK_58_INVENTORY_WORKFLOW_API_SHIM_AUDIT_REPORT.md.
 */
class InventoryWorkflowApiRetirementTest extends TestCase
{
    public function test_inventory_workflow_api_shim_does_not_exist(): void
    {
        $this->assertFileDoesNotExist(
            base_path('public/inventory-workflow-api.php'),
            'The inventory-workflow-api shim was retired under Task 58. Its require_once target '
            . 'was deleted in commit 6bbd38a, so restoring the shim would once again turn a public, '
            . 'unauthenticated URL into a path-disclosing PHP fatal error.'
        );
    }

    public function test_deleted_legacy_workflow_api_target_is_not_recreated(): void
    {
        $this->assertFileDoesNotExist(
            base_path('public/backend/api/inventory-workflow-api.php'),
            'The legacy backend/api workflow endpoint must not be recreated: it auto-minted a '
            . 'persisted maintenance_admin $_SESSION identity for any anonymous caller (a full '
            . 'authentication bypass) and echoed raw exception messages to the client. Its eleven '
            . 'actions are superseded by the Dispatch workflow and InventoryStockController, and '
            . 'its deploy action is the retired Deploy-to-Room feature (TASK 36 PHASE 7).'
        );
    }

    public function test_htaccess_does_not_route_the_retired_inventory_workflow_api_url(): void
    {
        $htaccess = file_get_contents(base_path('.htaccess'));
        $this->assertNotFalse($htaccess);

        // Ignore comment lines so the Task 58 explanatory block — which names
        // the removed rule verbatim — does not trip this guard.
        $directives = implode("\n", array_filter(
            preg_split('/\R/', $htaccess),
            static fn ($line) => !preg_match('/^\s*#/', $line)
        ));

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*RewriteRule\s+\S*inventory-workflow-api/mi',
            $directives,
            '.htaccess must not rewrite /inventory-workflow-api.php. The rule pointed at a shim '
            . 'whose require_once target no longer exists, publishing an unauthenticated '
            . 'information-disclosure surface instead of returning a 404.'
        );
    }

    public function test_no_application_code_calls_the_retired_inventory_workflow_endpoint(): void
    {
        $roots = [
            base_path('app'),
            base_path('routes'),
            base_path('public/frontend'),
        ];

        $offenders = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if (!in_array(strtolower($file->getExtension()), ['php', 'js', 'html'], true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if ($contents !== false && str_contains($contents, 'inventory-workflow-api')) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'No application code may call the retired inventory-workflow-api endpoint. Use the '
            . 'Dispatch routes (reserve/release) or the /api/inventory-stock endpoints '
            . '(transactions, summary, adjust) instead. Offending files: '
            . implode(', ', $offenders)
        );
    }
}
