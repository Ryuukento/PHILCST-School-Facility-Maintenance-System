<?php

namespace Tests\Support;

use App\Services\AnalyticsService;

class AnalyticsMocks
{
    public static function overview(): AnalyticsService
    {
        return new class extends AnalyticsService {
            public function overview(array $filters = []): array
            {
                return ['damaged_trends' => [], 'repair_trends' => [], 'dispatch_trends' => [], 'stock_movements' => []];
            }
        };
    }

    public static function top(): AnalyticsService
    {
        return new class extends AnalyticsService {
            public function topRequestedItems(array $filters = []): array
            {
                return ['top_requested' => []];
            }

            public function topRepairedItems(array $filters = []): array
            {
                return ['top_repaired' => []];
            }
        };
    }

    public static function low(): AnalyticsService
    {
        return new class extends AnalyticsService {
            public function lowStock(array $filters = []): array
            {
                return ['items' => []];
            }
        };
    }

    public static function summary(): AnalyticsService
    {
        return new class extends AnalyticsService {
            public function inventorySummary(array $filters = []): array
            {
                return ['total_quantity' => 0];
            }
        };
    }
}
