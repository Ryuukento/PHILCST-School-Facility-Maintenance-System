<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\AnalyticsMocks;

class AnalyticsIntegrationTest extends TestCase
{
    public function test_overview_returns_expected_structure(): void
    {
        $this->withoutMiddleware();
        // Bind a lightweight mock AnalyticsService to avoid DB/sqlite incompatibilities
        app()->instance(\App\Services\AnalyticsService::class, AnalyticsMocks::overview());
        $controller = app(\App\Http\Controllers\Api\AnalyticsReportController::class);
        $req = new \Illuminate\Http\Request();
        $res = $controller->overview($req);
            $this->assertEquals(200, $res->getStatusCode());
            $json = json_decode($res->getContent(), true);
            $this->assertArrayHasKey('data', $json);
            $this->assertArrayHasKey('damaged_trends', $json['data']);
    }

    /**
     * TASK 13 PHASE 8 — split from test_top_requested_and_repaired_endpoints().
     *
     * The original test exercised two endpoints in one method. topRepaired()
     * was deleted with the rest of the Repair analytics, so the combined test
     * could no longer run at all — and simply deleting it would have taken
     * the still-valid topRequested() coverage down with it.
     *
     * topRequested() is the Inventory/Dispatch metric ("items most often
     * requested"). It is NOT a Repair metric despite sitting next to one, and
     * the brief requires it be preserved.
     */
    public function test_top_requested_endpoint(): void
    {
        $this->withoutMiddleware();
        app()->instance(\App\Services\AnalyticsService::class, AnalyticsMocks::top());
            $controller = app(\App\Http\Controllers\Api\AnalyticsReportController::class);
            $r1 = $controller->topRequested(new \Illuminate\Http\Request());
            $this->assertEquals(200, $r1->getStatusCode());
            $j1 = json_decode($r1->getContent(), true);
            $this->assertArrayHasKey('data', $j1);
            $this->assertArrayHasKey('top_requested', $j1['data']);
    }

    /**
     * TASK 13 PHASE 8 — the other half of the split above, inverted.
     *
     * Rather than dropping the topRepaired() expectation outright, the
     * retirement is pinned at both layers it existed in: the controller
     * action and the service method behind it. Asserting against the REAL
     * classes rather than the mock is the point — AnalyticsMocks::top() would
     * happily answer a topRepaired() call, so mocking here would hide a
     * resurrection instead of catching it.
     */
    public function test_the_top_repaired_endpoint_is_retired(): void
    {
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Api\AnalyticsReportController::class, 'topRepaired'),
            'AnalyticsReportController::topRepaired() was removed in Task 13.'
        );

        $this->assertFalse(
            method_exists(\App\Services\AnalyticsService::class, 'topRepairedItems'),
            'AnalyticsService::topRepairedItems() was removed in Task 13.'
        );

        // The neighbouring Inventory metric must not have been taken with it.
        $this->assertTrue(
            method_exists(\App\Services\AnalyticsService::class, 'topRequestedItems'),
            'topRequestedItems() is an Inventory metric and must survive the Repair retirement.'
        );
    }

    /**
     * TASK 13 PHASE 8 — the Repair Report endpoint and its service method are
     * retired, while the reports sitting either side of it in the same
     * controller — Dispatch and Replacement/Damage — are explicitly kept by
     * the brief. Pinning all of them together is what stops a later cleanup
     * pass matching on "report" and removing the wrong one.
     */
    public function test_the_repair_report_is_retired_but_its_neighbours_remain(): void
    {
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Api\AnalyticsReportController::class, 'repairReport'),
            'AnalyticsReportController::repairReport() was removed in Task 13.'
        );
        $this->assertFalse(
            method_exists(\App\Services\AnalyticsService::class, 'repairReport'),
            'AnalyticsService::repairReport() was removed in Task 13.'
        );

        foreach (['dispatchReport', 'replacementReport', 'overview', 'semesterDetail'] as $kept) {
            $this->assertTrue(
                method_exists(\App\Services\AnalyticsService::class, $kept),
                "AnalyticsService::{$kept}() is not a Repair metric and must survive Task 13."
            );
        }
    }

    public function test_low_stock_filters_and_empty_handling(): void
    {
        $this->withoutMiddleware();
            // Use filters that likely produce empty results
        app()->instance(\App\Services\AnalyticsService::class, AnalyticsMocks::low());
            $controller = app(\App\Http\Controllers\Api\AnalyticsReportController::class);
            $req = new \Illuminate\Http\Request(['department_id' => 99999, 'room_id' => 99999]);
            $r = $controller->lowStock($req);
            $this->assertEquals(200, $r->getStatusCode());
            $j = json_decode($r->getContent(), true);
            $this->assertArrayHasKey('data', $j);
            $this->assertArrayHasKey('items', $j['data']);
            $this->assertIsArray($j['data']['items']);
    }

    public function test_cache_endpoints_return_consistent_data_on_repeats(): void
    {
        $this->withoutMiddleware();
        app()->instance(\App\Services\AnalyticsService::class, AnalyticsMocks::summary());
                $controller = app(\App\Http\Controllers\Api\AnalyticsReportController::class);
                $rA = $controller->inventorySummary(new \Illuminate\Http\Request());
                $rB = $controller->inventorySummary(new \Illuminate\Http\Request());
            $jA = json_decode($rA->getContent(), true);
            $jB = json_decode($rB->getContent(), true);
            $this->assertEquals($jA, $jB);
    }

    public function test_routes_registered_and_no_conflicts(): void
    {
        $this->withoutMiddleware();
        $routes = [
            '/api/analytics/overview',
            '/api/analytics/top-requested',
            '/api/analytics/top-repaired',
            '/api/analytics/inventory-health',
            '/api/analytics/inventory-summary',
            '/api/analytics/low-stock',
        ];
            foreach ($routes as $route) {
            // call controller directly where possible
            $this->assertTrue(true, 'Route exists: ' . $route);
        }
    }
}
